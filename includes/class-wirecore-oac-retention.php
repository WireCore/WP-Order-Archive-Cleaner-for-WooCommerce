<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Permanently deletes archived orders older than the retention period,
 * optionally after sending them as CSV.
 */
class WireCore_OAC_Retention
{
	const LIMIT = 200;

	/**
	 * @return int number of deleted orders
	 */
	public static function run()
	{
		$settings = WireCore_OAC_Settings::get();
		if (!$settings['retention_enabled']) {
			return 0;
		}
		$ids = wc_get_orders([
			'status' => WireCore_OAC_Archiver::STATUS,
			'type' => 'shop_order',
			'date_created' =>
				'<' .
				gmdate(
					'Y-m-d',
					strtotime('-' . $settings['retention_years'] . ' years')
				),
			'orderby' => 'ID',
			'order' => 'ASC',
			'limit' => self::LIMIT,
			'return' => 'ids',
		]);
		if (!$ids) {
			return 0;
		}

		$logger = wc_get_logger();
		$context = ['source' => 'wirecore-oac'];
		if (
			$settings['retention_export'] &&
			!WireCore_OAC_Export::mail(
				get_option('admin_email'),
				$ids,
				sprintf(
					/* translators: %s: site name */
					__(
						'[%s] Archived orders before deletion',
						'circle3-order-archive-cleaner-for-woocommerce'
					),
					wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
				),
				sprintf(
					/* translators: 1: number of orders, 2: number of years */
					_n(
						'The retention policy permanently deletes %1$d archived order older than %2$d years. Attached is its export.',
						'The retention policy permanently deletes %1$d archived orders older than %2$d years. Attached is their export.',
						count($ids),
						'circle3-order-archive-cleaner-for-woocommerce'
					),
					count($ids),
					$settings['retention_years']
				)
			)
		) {
			// Never delete orders whose backup was not sent
			$logger->error(
				'Retention run aborted: the export email could not be sent.',
				$context
			);
			return 0;
		}

		$deleted = 0;
		foreach ($ids as $id) {
			$order = wc_get_order($id);
			if ($order && $order->delete(true)) {
				$deleted++;
			}
		}
		$logger->info(
			sprintf(
				'Retention run deleted %d archived orders older than %d years.',
				$deleted,
				$settings['retention_years']
			),
			$context
		);

		// More are waiting – continue unless nothing could be deleted
		if (count($ids) === self::LIMIT && $deleted > 0) {
			as_enqueue_async_action(
				WireCore_OAC_Scheduler::RETENTION,
				[],
				WireCore_OAC_Scheduler::GROUP
			);
		}
		return $deleted;
	}
}
