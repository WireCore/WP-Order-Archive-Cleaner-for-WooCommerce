<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * The "Archived" order status, archiving and restoring orders and the order
 * screen integration (bulk actions, order actions).
 */
class WireCore_OAC_Archiver
{
	// Status slug without "wc-"
	const STATUS = 'archived';
	const META_ARCHIVED_AT = '_wirecore_oac_archived_at';
	const META_PREVIOUS_STATUS = '_wirecore_oac_previous_status';
	const LAST_RUN = 'wirecore_oac_last_run';
	const SCREENS = ['woocommerce_page_wc-orders', 'edit-shop_order'];

	public static function init()
	{
		add_action('init', [self::class, 'register_status']);
		add_filter('wc_order_statuses', [self::class, 'order_statuses']);
		// Archived orders count like paid orders, e.g. for the total spent of
		// a customer
		add_filter('woocommerce_order_is_paid_statuses', [
			self::class,
			'paid_statuses',
		]);

		foreach (self::SCREENS as $screen) {
			add_filter('bulk_actions-' . $screen, [
				self::class,
				'bulk_actions',
			]);
			add_filter(
				'handle_bulk_actions-' . $screen,
				[self::class, 'handle_bulk_actions'],
				10,
				3
			);
		}
		add_action('admin_notices', [self::class, 'bulk_notice']);

		add_filter(
			'woocommerce_order_actions',
			[self::class, 'order_actions'],
			10,
			2
		);
		add_action('woocommerce_order_action_wirecore_oac_archive', function (
			$order
		) {
			self::archive_order(
				$order,
				__(
					'Archived manually.',
					'circle3-order-archive-cleaner-for-woocommerce'
				)
			);
		});
		add_action('woocommerce_order_action_wirecore_oac_restore', [
			self::class,
			'restore_order',
		]);
	}

	public static function register_status()
	{
		register_post_status('wc-' . self::STATUS, [
			'label' => _x(
				'Archived',
				'Order status',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
			'public' => false,
			'exclude_from_search' => true,
			// Hidden from "All", shown as its own view
			'show_in_admin_all_list' => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count' => _n_noop(
				'Archived <span class="count">(%s)</span>',
				'Archived <span class="count">(%s)</span>',
				'circle3-order-archive-cleaner-for-woocommerce'
			),
		]);
	}

	public static function order_statuses(array $statuses)
	{
		$statuses['wc-' . self::STATUS] = _x(
			'Archived',
			'Order status',
			'circle3-order-archive-cleaner-for-woocommerce'
		);
		return $statuses;
	}

	public static function paid_statuses(array $statuses)
	{
		$statuses[] = self::STATUS;
		return $statuses;
	}

	/**
	 * Checks one batch of orders older than the cutoff and archives those not
	 * excluded by a rule. Skipped orders keep their status and stay in the
	 * query result, archived ones drop out – the next batch therefore starts
	 * at $offset plus the skipped orders ("next", null when all were checked).
	 *
	 * @return array{archived: int, skipped: int, dry_run: bool, next: int|null}
	 */
	public static function run_batch($offset = 0)
	{
		$settings = WireCore_OAC_Settings::get();
		$result = [
			// In dry-run mode: orders that would be archived
			'archived' => 0,
			'skipped' => 0,
			'dry_run' => $settings['dry_run'],
			'next' => null,
		];

		$ids = $settings['statuses']
			? wc_get_orders([
				'status' => $settings['statuses'],
				'type' => 'shop_order',
				'date_created' =>
					'<' .
					gmdate(
						'Y-m-d',
						strtotime('-' . $settings['cutoff_months'] . ' months')
					),
				'orderby' => 'ID',
				'order' => 'ASC',
				'limit' => $settings['batch_size'],
				'offset' => (int) $offset,
				'return' => 'ids',
			])
			: [];

		foreach ($ids as $id) {
			$order = wc_get_order($id);
			if (!$order || self::excluded($order, $settings)) {
				$result['skipped']++;
			} elseif (
				$settings['dry_run'] ||
				self::archive_order(
					$order,
					__(
						'Archived automatically by WireCore Order Archive Cleaner.',
						'circle3-order-archive-cleaner-for-woocommerce'
					)
				)
			) {
				$result['archived']++;
			}
		}

		if (count($ids) === $settings['batch_size']) {
			$result['next'] =
				(int) $offset +
				($settings['dry_run'] ? count($ids) : $result['skipped']);
		}

		/**
		 * After every batch, also in dry-run mode.
		 *
		 * @param array $result archived, skipped, dry_run, next
		 */
		do_action('wirecore_oac_after_archive_batch', $result);

		return $result;
	}

	/**
	 * Result of a complete run (all batches) for the overview.
	 */
	public static function log_run($archived, $skipped, $dry_run)
	{
		update_option(
			self::LAST_RUN,
			[
				'time' => time(),
				'archived' => (int) $archived,
				'skipped' => (int) $skipped,
				'dry_run' => (bool) $dry_run,
			],
			false
		);
	}

	/**
	 * Archive rules of the settings plus the filter
	 * wirecore_oac_should_skip_order.
	 */
	public static function excluded(WC_Order $order, array $settings)
	{
		$excluded =
			($settings['exclude_refunded'] && $order->get_refunds()) ||
			($settings['exclude_above_total'] > 0 &&
				(float) $order->get_total() > $settings['exclude_above_total']);

		if (
			!$excluded &&
			$settings['exclude_roles'] &&
			$order->get_customer_id()
		) {
			$user = get_userdata($order->get_customer_id());
			$excluded =
				$user &&
				array_intersect(
					(array) $user->roles,
					$settings['exclude_roles']
				);
		}

		if (!$excluded && $settings['exclude_categories']) {
			foreach ($order->get_items() as $item) {
				// The parent product for variations
				if (
					$item instanceof WC_Order_Item_Product &&
					has_term(
						$settings['exclude_categories'],
						'product_cat',
						$item->get_product_id()
					)
				) {
					$excluded = true;
					break;
				}
			}
		}

		/**
		 * Whether an order is never archived automatically.
		 *
		 * @param bool     $excluded excluded by the rules of the settings
		 * @param WC_Order $order
		 */
		return (bool) apply_filters(
			'wirecore_oac_should_skip_order',
			(bool) $excluded,
			$order
		);
	}

	/**
	 * @param WC_Order|int $order
	 */
	public static function archive_order($order, $note = '')
	{
		$order = wc_get_order($order);
		if (!$order || $order->get_status() === self::STATUS) {
			return false;
		}
		$order->update_meta_data(self::META_ARCHIVED_AT, time());
		$order->update_meta_data(
			self::META_PREVIOUS_STATUS,
			$order->get_status()
		);
		$order->update_status(self::STATUS, $note);
		return true;
	}

	/**
	 * Restores an archived order to the status it had before, or to $status.
	 *
	 * @param WC_Order|int $order
	 */
	public static function restore_order($order, $status = '')
	{
		$order = wc_get_order($order);
		if (!$order || $order->get_status() !== self::STATUS) {
			return false;
		}
		if ($status === '') {
			$status = (string) $order->get_meta(self::META_PREVIOUS_STATUS);
		}
		$status = sanitize_key($status);
		if (substr($status, 0, 3) === 'wc-') {
			$status = substr($status, 3);
		}
		if (
			!array_key_exists(
				$status,
				WireCore_OAC_Settings::archivable_statuses()
			)
		) {
			$status = 'completed';
		}
		$order->delete_meta_data(self::META_ARCHIVED_AT);
		$order->delete_meta_data(self::META_PREVIOUS_STATUS);
		$order->update_status(
			$status,
			__(
				'Restored from the archive.',
				'circle3-order-archive-cleaner-for-woocommerce'
			)
		);
		return true;
	}

	public static function count()
	{
		return (int) wc_orders_count(self::STATUS);
	}

	public static function bulk_actions(array $actions)
	{
		$actions['wirecore_oac_archive'] = __(
			'Archive',
			'circle3-order-archive-cleaner-for-woocommerce'
		);
		$actions['wirecore_oac_restore'] = __(
			'Restore from archive',
			'circle3-order-archive-cleaner-for-woocommerce'
		);
		return $actions;
	}

	/**
	 * WooCommerce has checked the nonce and the capability to edit orders
	 * before this filter runs.
	 */
	public static function handle_bulk_actions($redirect, $action, array $ids)
	{
		if (
			!in_array(
				$action,
				['wirecore_oac_archive', 'wirecore_oac_restore'],
				true
			)
		) {
			return $redirect;
		}

		$processed = 0;
		foreach ($ids as $id) {
			$done =
				$action === 'wirecore_oac_archive'
					? self::archive_order(
						(int) $id,
						__(
							'Archived manually.',
							'circle3-order-archive-cleaner-for-woocommerce'
						)
					)
					: self::restore_order((int) $id);
			if ($done) {
				$processed++;
			}
		}

		return add_query_arg(
			[
				'wirecore_oac_bulk' =>
					$action === 'wirecore_oac_archive'
						? 'archived'
						: 'restored',
				'wirecore_oac_processed' => $processed,
			],
			$redirect
		);
	}

	public static function bulk_notice()
	{
		$screen = get_current_screen();
		// Display only, after the redirect of the bulk action
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (
			!$screen ||
			!in_array($screen->id, self::SCREENS, true) ||
			!isset($_GET['wirecore_oac_bulk'], $_GET['wirecore_oac_processed'])
		) {
			return;
		}
		$bulk = sanitize_key(wp_unslash($_GET['wirecore_oac_bulk']));
		$processed = absint($_GET['wirecore_oac_processed']);
		// phpcs:enable

		$text =
			$bulk === 'archived'
				? sprintf(
					/* translators: %d: number of orders */
					_n(
						'%d order archived.',
						'%d orders archived.',
						$processed,
						'circle3-order-archive-cleaner-for-woocommerce'
					),
					$processed
				)
				: sprintf(
					/* translators: %d: number of orders */
					_n(
						'%d order restored.',
						'%d orders restored.',
						$processed,
						'circle3-order-archive-cleaner-for-woocommerce'
					),
					$processed
				);
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html($text)
		);
	}

	/**
	 * "Order actions" box on the edit screen of an order.
	 */
	public static function order_actions(array $actions, $order = null)
	{
		if (!($order instanceof WC_Order)) {
			return $actions;
		}
		if ($order->get_status() === self::STATUS) {
			$actions['wirecore_oac_restore'] = __(
				'Restore from archive',
				'circle3-order-archive-cleaner-for-woocommerce'
			);
		} else {
			$actions['wirecore_oac_archive'] = __(
				'Archive order',
				'circle3-order-archive-cleaner-for-woocommerce'
			);
		}
		return $actions;
	}
}
