<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Archives and restores WooCommerce orders.
 */
class WireCore_OAC_Cli
{
	/**
	 * Archives one batch of orders with the current settings.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Continue with further batches until all orders are checked.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wirecore-archive run
	 *     wp wirecore-archive run --all
	 */
	public function run($args, $assoc_args)
	{
		$all = !empty($assoc_args['all']);
		$archived = 0;
		$skipped = 0;
		$offset = 0;
		do {
			$result = WireCore_OAC_Archiver::run_batch($offset);
			$archived += $result['archived'];
			$skipped += $result['skipped'];
			$offset = $result['next'];
		} while ($all && !$result['dry_run'] && $offset !== null);
		WireCore_OAC_Archiver::log_run($archived, $skipped, $result['dry_run']);

		WP_CLI::success(
			sprintf(
				$result['dry_run']
					? 'Dry run: %d orders would be archived, %d skipped by rules.'
					: 'Archived: %d, skipped by rules: %d.',
				$archived,
				$skipped
			)
		);
	}

	/**
	 * Restores an archived order.
	 *
	 * ## OPTIONS
	 *
	 * <order-id>
	 * : ID of the order.
	 *
	 * [--status=<status>]
	 * : Status to restore to, without "wc-". Default: the status before archiving.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wirecore-archive restore 42
	 *     wp wirecore-archive restore 42 --status=processing
	 */
	public function restore($args, $assoc_args)
	{
		$order = wc_get_order((int) $args[0]);
		if (!$order) {
			WP_CLI::error(sprintf('Order #%d not found.', (int) $args[0]));
		}
		if (
			!WireCore_OAC_Archiver::restore_order(
				$order,
				isset($assoc_args['status'])
					? (string) $assoc_args['status']
					: ''
			)
		) {
			WP_CLI::error(
				sprintf(
					'Order #%d is not archived (status: %s).',
					$order->get_id(),
					$order->get_status()
				)
			);
		}
		WP_CLI::success(
			sprintf(
				'Order #%d restored to "%s".',
				$order->get_id(),
				wc_get_order($order->get_id())->get_status()
			)
		);
	}

	/**
	 * Shows the settings and the archive statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default: table.
	 */
	public function status($args, $assoc_args)
	{
		$settings = WireCore_OAC_Settings::get();
		$last_run = get_option(WireCore_OAC_Archiver::LAST_RUN);
		$rows = [
			'Version' => WIRECORE_OAC_VERSION,
			'Automatic archiving' => $settings['enabled'] ? 'on' : 'off',
			'Dry run' => $settings['dry_run'] ? 'on' : 'off',
			'Archive orders older than' =>
				$settings['cutoff_months'] . ' months',
			'Statuses' => implode(', ', $settings['statuses']),
			'Batch size' => $settings['batch_size'],
			'Archived orders' => WireCore_OAC_Archiver::count(),
			'Last run' => is_array($last_run)
				? sprintf(
					'%s UTC (archived: %d, skipped: %d)',
					gmdate('Y-m-d H:i:s', $last_run['time']),
					$last_run['archived'],
					$last_run['skipped']
				)
				: 'never',
			'Scheduled export' => $settings['export_enabled']
				? $settings['export_frequency']
				: 'off',
			'Retention' => $settings['retention_enabled']
				? 'delete after ' . $settings['retention_years'] . ' years'
				: 'off',
			'Webhook' => $settings['webhook_enabled'] ? 'on' : 'off',
		];
		$items = [];
		foreach ($rows as $key => $value) {
			$items[] = ['setting' => $key, 'value' => $value];
		}
		WP_CLI\Utils\format_items(
			isset($assoc_args['format']) ? $assoc_args['format'] : 'table',
			$items,
			['setting', 'value']
		);
	}

	/**
	 * Sends the export of all archived orders by email now.
	 *
	 * Requires the scheduled export to be enabled; uses its email address.
	 */
	public function export()
	{
		if (!WireCore_OAC_Settings::get()['export_enabled']) {
			WP_CLI::error('The scheduled export is disabled.');
		}
		if (!WireCore_OAC_Export::send()) {
			WP_CLI::error('The email could not be sent.');
		}
		WP_CLI::success('Export sent.');
	}
}
