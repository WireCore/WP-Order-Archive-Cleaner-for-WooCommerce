<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Widget on the WordPress dashboard with the archive statistics.
 */
class WireCore_OAC_Dashboard
{
	public static function init()
	{
		add_action('wp_dashboard_setup', [self::class, 'register']);
	}

	public static function register()
	{
		if (!current_user_can('manage_woocommerce')) {
			return;
		}
		wp_add_dashboard_widget(
			'wirecore_oac_dashboard',
			__(
				'Order archive',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
			[self::class, 'render']
		);
	}

	public static function render()
	{
		$last_run = get_option(WireCore_OAC_Archiver::LAST_RUN);
		$stats = [
			__(
				'Archived orders',
				'circle3-order-archive-cleaner-for-woocommerce'
			) => number_format_i18n(WireCore_OAC_Archiver::count()),
			__(
				'Archived this month',
				'circle3-order-archive-cleaner-for-woocommerce'
			) => number_format_i18n(
				self::archived_since(strtotime(gmdate('Y-m-01')))
			),
			__(
				'Last run',
				'circle3-order-archive-cleaner-for-woocommerce'
			) => is_array($last_run)
				? sprintf(
					/* translators: %s: time span, e.g. "2 hours" */
					__(
						'%s ago',
						'circle3-order-archive-cleaner-for-woocommerce'
					),
					human_time_diff($last_run['time'])
				)
				: __('Never', 'circle3-order-archive-cleaner-for-woocommerce'),
		];
		echo '<ul>';
		foreach ($stats as $label => $value) {
			printf(
				'<li>%s: <strong>%s</strong></li>',
				esc_html($label),
				esc_html($value)
			);
		}
		echo '</ul>';
		printf(
			'<p><a href="%s">%s</a> | <a href="%s">%s</a></p>',
			esc_url(WireCore_OAC_Admin::orders_url()),
			esc_html__(
				'View archived orders',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
			esc_url(WireCore_OAC_Admin::page_url()),
			esc_html__(
				'Settings',
				'circle3-order-archive-cleaner-for-woocommerce'
			)
		);
	}

	public static function archived_since($timestamp)
	{
		return (int) wc_get_orders([
			'status' => WireCore_OAC_Archiver::STATUS,
			'type' => 'shop_order',
			// Only the total is needed
			'paginate' => true,
			'limit' => 1,
			'return' => 'ids',
			// Only orders archived by the plugin carry the meta field
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => [
				[
					'key' => WireCore_OAC_Archiver::META_ARCHIVED_AT,
					'value' => (int) $timestamp,
					'compare' => '>=',
					'type' => 'NUMERIC',
				],
			],
		])->total;
	}
}
