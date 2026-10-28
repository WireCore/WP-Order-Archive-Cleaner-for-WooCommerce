<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Background jobs via Action Scheduler: the daily archive run (continued in
 * batches until all orders are checked), the scheduled export and the
 * retention run.
 */
class WireCore_OAC_Scheduler
{
	const GROUP = 'wirecore-oac';
	const DAILY = 'wirecore_oac_daily';
	const BATCH = 'wirecore_oac_batch';
	const EXPORT = 'wirecore_oac_export';
	const RETENTION = 'wirecore_oac_retention';

	public static function init()
	{
		add_action(self::DAILY, [self::class, 'daily']);
		add_action(self::BATCH, [self::class, 'batch'], 10, 3);
		add_action(self::EXPORT, ['WireCore_OAC_Export', 'send']);
		add_action(self::RETENTION, ['WireCore_OAC_Retention', 'run']);
		// Restores missing jobs, e.g. after Action Scheduler was cleaned up
		add_action('admin_init', [self::class, 'schedule']);
	}

	/**
	 * Recurring jobs according to the settings: missing ones are scheduled,
	 * disabled ones removed.
	 */
	public static function schedule()
	{
		if (!function_exists('as_has_scheduled_action')) {
			return;
		}
		$settings = WireCore_OAC_Settings::get();
		$jobs = [
			self::DAILY => [$settings['enabled'], DAY_IN_SECONDS],
			self::EXPORT => [
				$settings['export_enabled'],
				$settings['export_frequency'] === 'weekly'
					? WEEK_IN_SECONDS
					: MONTH_IN_SECONDS,
			],
			self::RETENTION => [
				$settings['retention_enabled'],
				MONTH_IN_SECONDS,
			],
		];
		foreach ($jobs as $hook => list($enabled, $interval)) {
			if (!$enabled) {
				as_unschedule_all_actions($hook, [], self::GROUP);
			} elseif (!as_has_scheduled_action($hook, null, self::GROUP)) {
				as_schedule_recurring_action(
					strtotime('tomorrow'),
					$interval,
					$hook,
					[],
					self::GROUP
				);
			}
		}
	}

	/**
	 * After saving the settings, so that a changed export frequency applies.
	 */
	public static function reschedule()
	{
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::EXPORT, [], self::GROUP);
		}
		self::schedule();
	}

	public static function unschedule()
	{
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions('', [], self::GROUP);
		}
	}

	public static function daily()
	{
		if (WireCore_OAC_Settings::get()['enabled']) {
			self::batch(0, 0, 0);
		}
	}

	/**
	 * One batch; queues the next one until all orders are checked, then
	 * sends the summary. Dry runs check one batch only.
	 */
	public static function batch($offset, $archived, $skipped)
	{
		$result = WireCore_OAC_Archiver::run_batch($offset);
		$archived += $result['archived'];
		$skipped += $result['skipped'];

		if (!$result['dry_run'] && $result['next'] !== null) {
			as_enqueue_async_action(
				self::BATCH,
				[$result['next'], $archived, $skipped],
				self::GROUP
			);
			return;
		}
		WireCore_OAC_Archiver::log_run($archived, $skipped, $result['dry_run']);
		if ($archived > 0 && !$result['dry_run']) {
			self::notify($archived, $skipped);
		}
	}

	private static function notify($archived, $skipped)
	{
		if (!WireCore_OAC_Settings::get()['notify']) {
			return;
		}
		wp_mail(
			get_option('admin_email'),
			sprintf(
				/* translators: %s: site name */
				__(
					'[%s] Orders archived',
					'circle3-order-archive-cleaner-for-woocommerce'
				),
				wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
			),
			sprintf(
				/* translators: 1: number of archived orders, 2: number of skipped orders, 3: URL of the archived orders */
				__(
					"The automatic run of WireCore Order Archive Cleaner is complete.\n\nArchived: %1\$d\nSkipped by rules: %2\$d\n\nArchived orders: %3\$s",
					'circle3-order-archive-cleaner-for-woocommerce'
				),
				$archived,
				$skipped,
				WireCore_OAC_Admin::orders_url()
			)
		);
	}
}
