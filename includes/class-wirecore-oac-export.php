<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * CSV export of archived orders by email: on a schedule and before the
 * retention run deletes orders.
 */
class WireCore_OAC_Export
{
	const PAGE_SIZE = 100;

	/**
	 * Scheduled export of all archived orders.
	 */
	public static function send()
	{
		$settings = WireCore_OAC_Settings::get();
		if (!$settings['export_enabled']) {
			return false;
		}
		$ids = [];
		$page = 1;
		do {
			$batch = wc_get_orders([
				'status' => WireCore_OAC_Archiver::STATUS,
				'type' => 'shop_order',
				'orderby' => 'ID',
				'order' => 'ASC',
				'limit' => self::PAGE_SIZE,
				'page' => $page++,
				'return' => 'ids',
			]);
			$ids = array_merge($ids, $batch);
		} while (count($batch) === self::PAGE_SIZE);

		return self::mail(
			$settings['export_email'] ?: get_option('admin_email'),
			$ids,
			sprintf(
				/* translators: %s: site name */
				__(
					'[%s] Archived orders export',
					'circle3-order-archive-cleaner-for-woocommerce'
				),
				wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
			),
			sprintf(
				/* translators: %d: number of orders */
				_n(
					'Attached is the export of %d archived order.',
					'Attached is the export of %d archived orders.',
					count($ids),
					'circle3-order-archive-cleaner-for-woocommerce'
				),
				count($ids)
			)
		);
	}

	/**
	 * Sends the orders as CSV attachment.
	 *
	 * @param int[] $ids
	 */
	public static function mail($to, array $ids, $subject, $message)
	{
		$file = wp_tempnam('wirecore-oac-export');
		// A temporary file for the attachment, WP_Filesystem is not needed for it
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents($file, self::csv($ids));
		$sent = wp_mail($to, $subject, $message, '', [
			// The key is the file name of the attachment (WordPress 6.2+)
			'archived-orders-' . gmdate('Y-m-d') . '.csv' => $file,
		]);
		wp_delete_file($file);
		return $sent;
	}

	/**
	 * @param int[] $ids
	 */
	public static function csv(array $ids)
	{
		$csv = self::row([
			'Order ID',
			'Order number',
			'Date created',
			'Status before archiving',
			'Customer',
			'Email',
			'Total',
			'Currency',
			'Items',
		]);
		foreach ($ids as $id) {
			$order = wc_get_order($id);
			if (!$order) {
				continue;
			}
			$items = [];
			foreach ($order->get_items() as $item) {
				$items[] = $item->get_name() . ' x' . $item->get_quantity();
			}
			$created = $order->get_date_created();
			$csv .= self::row([
				$order->get_id(),
				$order->get_order_number(),
				$created ? $created->date('Y-m-d H:i:s') : '',
				$order->get_meta(WireCore_OAC_Archiver::META_PREVIOUS_STATUS),
				$order->get_formatted_billing_full_name(),
				$order->get_billing_email(),
				$order->get_total(),
				$order->get_currency(),
				implode('; ', $items),
			]);
		}
		return $csv;
	}

	/**
	 * RFC 4180 row. Cells starting with = + - @ are prefixed with ' so
	 * spreadsheets do not run them as formulas.
	 */
	public static function row(array $cells)
	{
		foreach ($cells as &$cell) {
			$cell = (string) $cell;
			if ($cell !== '' && strpos('=+-@', $cell[0]) !== false) {
				$cell = "'" . $cell;
			}
			if (strpbrk($cell, ",\"\r\n") !== false) {
				$cell = '"' . str_replace('"', '""', $cell) . '"';
			}
		}
		return implode(',', $cells) . "\r\n";
	}
}
