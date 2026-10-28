<?php
/**
 * WireCore_OAC_Archiver: archiving, rules, batches and restoring.
 */
class Test_WireCore_OAC_Archiver extends WireCore_OAC_TestCase
{
	public function test_runs_on_the_requested_order_storage()
	{
		$this->assertSame(
			(bool) getenv('WIRECORE_OAC_HPOS'),
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		);
	}

	public function test_archives_old_orders_of_the_selected_statuses_only()
	{
		$old = $this->order('completed');
		$cancelled = $this->order('cancelled');
		$processing = $this->order('processing');
		$recent = $this->order('completed', '-1 month');

		$result = WireCore_OAC_Archiver::run_batch();

		$this->assertSame(2, $result['archived']);
		$this->assertSame('archived', $this->status($old));
		$this->assertSame('archived', $this->status($cancelled));
		$this->assertSame('processing', $this->status($processing));
		$this->assertSame('completed', $this->status($recent));
	}

	public function test_archiving_stores_the_previous_status_and_restoring_uses_it()
	{
		$order = $this->order('cancelled');

		$this->assertTrue(
			WireCore_OAC_Archiver::archive_order($order->get_id())
		);
		$archived = wc_get_order($order->get_id());
		$this->assertSame(
			'cancelled',
			$archived->get_meta(WireCore_OAC_Archiver::META_PREVIOUS_STATUS)
		);
		$this->assertFalse(WireCore_OAC_Archiver::archive_order($archived));

		$this->assertTrue(WireCore_OAC_Archiver::restore_order($archived));
		$restored = wc_get_order($order->get_id());
		$this->assertSame('cancelled', $restored->get_status());
		$this->assertSame(
			'',
			$restored->get_meta(WireCore_OAC_Archiver::META_ARCHIVED_AT)
		);
		$this->assertFalse(WireCore_OAC_Archiver::restore_order($restored));
	}

	public function test_restore_accepts_a_status_and_falls_back_to_completed()
	{
		$order = $this->order();
		WireCore_OAC_Archiver::archive_order($order);
		WireCore_OAC_Archiver::restore_order($order->get_id(), 'wc-processing');
		$this->assertSame('processing', $this->status($order));

		WireCore_OAC_Archiver::archive_order($order->get_id());
		WireCore_OAC_Archiver::restore_order(
			$order->get_id(),
			'no-such-status'
		);
		$this->assertSame('completed', $this->status($order));
	}

	public function test_rules_exclude_refunds_totals_roles_and_categories()
	{
		$refunded = $this->order();
		wc_create_refund(['order_id' => $refunded->get_id(), 'amount' => 5]);
		$expensive = $this->order('completed', '-2 years', ['total' => 500]);
		$editor = self::factory()->user->create(['role' => 'editor']);
		$by_editor = $this->order('completed', '-2 years', [
			'customer_id' => $editor,
		]);
		$category = self::factory()->term->create([
			'taxonomy' => 'product_cat',
		]);
		$product = new WC_Product_Simple();
		$product->set_category_ids([$category]);
		$product->save();
		$with_category = $this->order();
		$with_category->add_product($product);
		$with_category->save();
		$plain = $this->order();

		$this->settings([
			'exclude_above_total' => 100,
			'exclude_roles' => ['editor'],
			'exclude_categories' => [$category],
		]);
		$result = WireCore_OAC_Archiver::run_batch();

		$this->assertSame(1, $result['archived']);
		$this->assertSame(4, $result['skipped']);
		$this->assertSame('archived', $this->status($plain));
		foreach (
			[$refunded, $expensive, $by_editor, $with_category]
			as $order
		) {
			$this->assertSame('completed', $this->status($order));
		}
	}

	public function test_filter_can_skip_orders()
	{
		$order = $this->order();
		add_filter('wirecore_oac_should_skip_order', '__return_true');

		$result = WireCore_OAC_Archiver::run_batch();

		$this->assertSame(1, $result['skipped']);
		$this->assertSame('completed', $this->status($order));
	}

	public function test_dry_run_counts_without_changing_orders()
	{
		$order = $this->order();
		$this->settings(['dry_run' => true]);

		$result = WireCore_OAC_Archiver::run_batch();

		$this->assertTrue($result['dry_run']);
		$this->assertSame(1, $result['archived']);
		$this->assertSame('completed', $this->status($order));
	}

	/**
	 * Skipped orders stay in the query result – a full batch of them must not
	 * block the orders behind it.
	 */
	public function test_next_batch_starts_after_skipped_orders()
	{
		$skipped = [$this->order(), $this->order()];
		foreach ($skipped as $order) {
			wc_create_refund(['order_id' => $order->get_id(), 'amount' => 1]);
		}
		$later = [$this->order(), $this->order(), $this->order()];
		$this->settings(['batch_size' => 2]);

		$first = WireCore_OAC_Archiver::run_batch();
		$this->assertSame(0, $first['archived']);
		$this->assertSame(2, $first['next']);

		$second = WireCore_OAC_Archiver::run_batch($first['next']);
		$this->assertSame(2, $second['archived']);
		$this->assertSame(2, $second['next']);

		$third = WireCore_OAC_Archiver::run_batch($second['next']);
		$this->assertSame(1, $third['archived']);
		$this->assertNull($third['next']);

		foreach ($later as $order) {
			$this->assertSame('archived', $this->status($order));
		}
	}

	public function test_archived_orders_count_as_paid_and_have_their_own_status()
	{
		$this->assertContains('archived', wc_get_is_paid_statuses());
		$this->assertArrayHasKey('wc-archived', wc_get_order_statuses());

		$order = $this->order();
		WireCore_OAC_Archiver::archive_order($order);
		$this->assertSame(1, WireCore_OAC_Archiver::count());
	}

	public function test_bulk_actions_archive_and_restore()
	{
		$orders = [$this->order('processing', 'now'), $this->order()];
		$ids = [$orders[0]->get_id(), $orders[1]->get_id()];

		$url = WireCore_OAC_Archiver::handle_bulk_actions(
			'https://example.org/',
			'wirecore_oac_archive',
			$ids
		);
		$this->assertStringContainsString('wirecore_oac_processed=2', $url);
		$this->assertSame('archived', $this->status($orders[0]));

		WireCore_OAC_Archiver::handle_bulk_actions(
			'https://example.org/',
			'wirecore_oac_restore',
			$ids
		);
		$this->assertSame('processing', $this->status($orders[0]));
		$this->assertSame(
			'https://example.org/',
			WireCore_OAC_Archiver::handle_bulk_actions(
				'https://example.org/',
				'trash',
				$ids
			)
		);
	}
}
