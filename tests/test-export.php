<?php
/**
 * WireCore_OAC_Export and WireCore_OAC_Retention.
 */
class Test_WireCore_OAC_Export extends WireCore_OAC_TestCase
{
	public function test_csv_cells_are_quoted_and_formulas_neutralized()
	{
		$this->assertSame(
			"1,\"a,b\",\"say \"\"hi\"\"\",'=SUM(A1),'-5,'@x\r\n",
			WireCore_OAC_Export::row([
				1,
				'a,b',
				'say "hi"',
				'=SUM(A1)',
				'-5',
				'@x',
			])
		);
	}

	public function test_csv_contains_the_orders()
	{
		$order = $this->order('completed', '-2 years', [
			'billing_email' => 'grace@example.org',
		]);
		WireCore_OAC_Archiver::archive_order($order);

		$csv = WireCore_OAC_Export::csv([$order->get_id()]);
		$lines = explode("\r\n", trim($csv));

		$this->assertCount(2, $lines);
		$this->assertStringStartsWith('Order ID,', $lines[0]);
		$this->assertStringContainsString('grace@example.org', $lines[1]);
		$this->assertStringContainsString(',completed,', $lines[1]);
	}

	public function test_scheduled_export_mails_all_archived_orders()
	{
		$archived = $this->order();
		WireCore_OAC_Archiver::archive_order($archived);
		$this->order();
		$this->settings([
			'export_enabled' => true,
			'export_email' => 'shop@example.org',
		]);
		reset_phpmailer_instance();

		$this->assertTrue(WireCore_OAC_Export::send());

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame(
			'shop@example.org',
			$mailer->get_recipient('to')->address
		);
		$attachments = $mailer->getAttachments();
		$this->assertCount(1, $attachments);
		$this->assertStringEndsWith('.csv', $attachments[0][2]);
		$this->assertStringContainsString(
			'1 archived order',
			$mailer->get_sent()->body
		);
	}

	public function test_export_does_nothing_when_disabled()
	{
		$this->assertFalse(WireCore_OAC_Export::send());
	}

	public function test_retention_deletes_old_archived_orders_after_the_backup()
	{
		$old = $this->order('completed', '-9 years');
		$young = $this->order('completed', '-3 years');
		$not_archived = $this->order('completed', '-9 years');
		WireCore_OAC_Archiver::archive_order($old);
		WireCore_OAC_Archiver::archive_order($young);
		$this->settings(['retention_enabled' => true, 'retention_years' => 7]);
		reset_phpmailer_instance();

		$this->assertSame(1, WireCore_OAC_Retention::run());

		$this->assertFalse(wc_get_order($old->get_id()));
		$this->assertSame('archived', $this->status($young));
		$this->assertSame('completed', $this->status($not_archived));
		$this->assertCount(
			1,
			tests_retrieve_phpmailer_instance()->getAttachments()
		);
	}

	public function test_retention_keeps_orders_when_the_backup_fails()
	{
		$old = $this->order('completed', '-9 years');
		WireCore_OAC_Archiver::archive_order($old);
		$this->settings(['retention_enabled' => true]);
		add_filter('pre_wp_mail', '__return_false');

		$this->assertSame(0, WireCore_OAC_Retention::run());
		$this->assertSame('archived', $this->status($old));
	}

	public function test_retention_does_nothing_when_disabled()
	{
		$old = $this->order('completed', '-9 years');
		WireCore_OAC_Archiver::archive_order($old);

		$this->assertSame(0, WireCore_OAC_Retention::run());
		$this->assertSame('archived', $this->status($old));
	}
}
