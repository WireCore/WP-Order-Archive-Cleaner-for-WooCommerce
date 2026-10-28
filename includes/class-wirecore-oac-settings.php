<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Settings in one option. Everything that is stored (form, WP-CLI) goes
 * through sanitize().
 */
class WireCore_OAC_Settings
{
	const OPTION = 'wirecore_oac_settings';
	const FLAGS = [
		'enabled',
		'dry_run',
		'exclude_refunded',
		'notify',
		'export_enabled',
		'retention_enabled',
		'retention_export',
		'webhook_enabled',
	];
	const FREQUENCIES = ['weekly', 'monthly'];
	const MAX_BATCH_SIZE = 200;

	private static $cache = null;

	public static function defaults()
	{
		return [
			// Archiving
			'enabled' => true,
			'cutoff_months' => 12,
			// Order status slugs without "wc-"
			'statuses' => ['completed', 'cancelled'],
			'batch_size' => 50,
			'dry_run' => false,
			// Email a summary to the admin after each automatic run
			'notify' => false,
			// Rules – orders matching one of them are never archived
			'exclude_refunded' => true,
			// 0 = no limit
			'exclude_above_total' => 0.0,
			'exclude_roles' => [],
			// product_cat term IDs
			'exclude_categories' => [],
			// Scheduled export of all archived orders
			'export_enabled' => false,
			'export_frequency' => 'monthly',
			// empty = admin email
			'export_email' => '',
			// Retention: permanently delete archived orders
			'retention_enabled' => false,
			'retention_years' => 7,
			'retention_export' => true,
			// Webhook on every archived order
			'webhook_enabled' => false,
			'webhook_url' => '',
			'webhook_secret' => '',
		];
	}

	public static function get()
	{
		if (self::$cache === null) {
			$stored = get_option(self::OPTION);
			self::$cache = array_merge(
				self::defaults(),
				is_array($stored) ? $stored : []
			);
		}
		return self::$cache;
	}

	public static function save(array $settings)
	{
		self::$cache = null;
		update_option(self::OPTION, self::sanitize($settings), false);
	}

	/**
	 * Order statuses that can be archived: all WooCommerce statuses except
	 * "Archived" itself, slug without "wc-" => label.
	 */
	public static function archivable_statuses()
	{
		$statuses = [];
		foreach (wc_get_order_statuses() as $status => $label) {
			$status =
				substr($status, 0, 3) === 'wc-' ? substr($status, 3) : $status;
			if ($status !== WireCore_OAC_Archiver::STATUS) {
				$statuses[$status] = $label;
			}
		}
		return $statuses;
	}

	public static function sanitize(array $input)
	{
		$defaults = self::defaults();
		$settings = [];

		foreach (self::FLAGS as $flag) {
			$settings[$flag] = isset($input[$flag])
				? (bool) $input[$flag]
				: $defaults[$flag];
		}

		$settings['cutoff_months'] = self::range(
			$input,
			'cutoff_months',
			1,
			120
		);
		$settings['batch_size'] = self::range(
			$input,
			'batch_size',
			1,
			self::MAX_BATCH_SIZE
		);
		$settings['retention_years'] = self::range(
			$input,
			'retention_years',
			1,
			20
		);

		$settings['statuses'] = isset($input['statuses'])
			? array_values(
				array_intersect(
					array_map('strval', (array) $input['statuses']),
					array_keys(self::archivable_statuses())
				)
			)
			: $defaults['statuses'];

		$total = isset($input['exclude_above_total'])
			? (float) $input['exclude_above_total']
			: 0.0;
		$settings['exclude_above_total'] = max(0.0, round($total, 2));

		$settings['exclude_roles'] = isset($input['exclude_roles'])
			? array_values(
				array_intersect(
					array_map('strval', (array) $input['exclude_roles']),
					array_keys(wp_roles()->roles)
				)
			)
			: [];

		$settings['exclude_categories'] = [];
		$categories = isset($input['exclude_categories'])
			? array_unique(
				array_filter(
					array_map('absint', (array) $input['exclude_categories'])
				)
			)
			: [];
		foreach ($categories as $category) {
			if (term_exists($category, 'product_cat')) {
				$settings['exclude_categories'][] = $category;
			}
		}

		$frequency = isset($input['export_frequency'])
			? (string) $input['export_frequency']
			: '';
		$settings['export_frequency'] = in_array(
			$frequency,
			self::FREQUENCIES,
			true
		)
			? $frequency
			: $defaults['export_frequency'];

		$settings['export_email'] = isset($input['export_email'])
			? sanitize_email((string) $input['export_email'])
			: '';

		$url = isset($input['webhook_url'])
			? esc_url_raw(trim((string) $input['webhook_url']), ['https'])
			: '';
		$settings['webhook_url'] = $url;
		$settings['webhook_secret'] = isset($input['webhook_secret'])
			? sanitize_text_field((string) $input['webhook_secret'])
			: '';

		return $settings;
	}

	private static function range(array $input, $key, $min, $max)
	{
		if (!isset($input[$key]) || !is_numeric($input[$key])) {
			return self::defaults()[$key];
		}
		return min($max, max($min, (int) $input[$key]));
	}
}
