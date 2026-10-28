<?php
/**
 * WireCore_OAC_Scheduler and WireCore_OAC_Webhook.
 */
class Test_WireCore_OAC_Scheduler extends WireCore_OAC_TestCase
{
	public function set_up()
	{
		parent::set_up();
		WireCore_OAC_Scheduler::unschedule();
	}

	private function scheduled($hook)
	{
		return as_has_scheduled_action(
			$hook,
			null,
			WireCore_OAC_Scheduler::GROUP
		);
	}

	public function test_jobs_follow_the_settings()
	{
		WireCore_OAC_Scheduler::schedule();
		$this->assertTrue($this->scheduled(WireCore_OAC_Scheduler::DAILY));
		$this->assertFalse($this->scheduled(WireCore_OAC_Scheduler::EXPORT));
		$this->assertFalse($this->scheduled(WireCore_OAC_Scheduler::RETENTION));

		$this->settings([
			'enabled' => false,
			'export_enabled' => true,
			'retention_enabled' => true,
		]);
		WireCore_OAC_Scheduler::reschedule();
		$this->assertFalse($this->scheduled(WireCore_OAC_Scheduler::DAILY));
		$this->assertTrue($this->scheduled(WireCore_OAC_Scheduler::EXPORT));
		$this->assertTrue($this->scheduled(WireCore_OAC_Scheduler::RETENTION));

		WireCore_OAC_Scheduler::unschedule();
		$this->assertFalse($this->scheduled(WireCore_OAC_Scheduler::EXPORT));
	}

	public function test_full_batch_queues_the_next_one_with_the_totals()
	{
		$this->order();
		$this->order();
		$this->settings(['batch_size' => 2]);

		WireCore_OAC_Scheduler::daily();

		$this->assertTrue(
			as_has_scheduled_action(
				WireCore_OAC_Scheduler::BATCH,
				[0, 2, 0],
				WireCore_OAC_Scheduler::GROUP
			)
		);
	}

	public function test_last_batch_logs_the_totals_of_the_run()
	{
		$this->order();
		$this->order();
		$this->settings(['batch_size' => 1]);

		WireCore_OAC_Scheduler::batch(0, 0, 0);
		$this->assertFalse(get_option(WireCore_OAC_Archiver::LAST_RUN));
		WireCore_OAC_Scheduler::batch(0, 1, 0);
		WireCore_OAC_Scheduler::batch(0, 2, 0);

		$last_run = get_option(WireCore_OAC_Archiver::LAST_RUN);
		$this->assertSame(2, $last_run['archived']);
		$this->assertFalse($last_run['dry_run']);
	}

	public function test_summary_email_after_the_last_batch_when_enabled()
	{
		$this->order();
		$this->settings(['notify' => true]);
		reset_phpmailer_instance();

		WireCore_OAC_Scheduler::daily();

		$this->assertFalse($this->scheduled(WireCore_OAC_Scheduler::BATCH));
		$this->assertStringContainsString(
			'Archived: 1',
			tests_retrieve_phpmailer_instance()->get_sent()->body
		);
	}

	public function test_webhook_sends_signed_payload()
	{
		$this->settings([
			'webhook_enabled' => true,
			'webhook_url' => 'https://example.org/hook',
			'webhook_secret' => 'secret',
		]);
		$requests = [];
		add_filter(
			'pre_http_request',
			function ($response, $args, $url) use (&$requests) {
				$requests[] = [$url, $args];
				return ['response' => ['code' => 200], 'body' => ''];
			},
			10,
			3
		);
		$order = $this->order('cancelled');

		WireCore_OAC_Archiver::archive_order($order);

		$this->assertCount(1, $requests);
		list($url, $args) = $requests[0];
		$payload = json_decode($args['body'], true);
		$this->assertSame('https://example.org/hook', $url);
		$this->assertSame('order.archived', $payload['event']);
		$this->assertSame($order->get_id(), $payload['order_id']);
		$this->assertSame('cancelled', $payload['previous_status']);
		$this->assertSame(
			'sha256=' . hash_hmac('sha256', $args['body'], 'secret'),
			$args['headers'][WireCore_OAC_Webhook::SIGNATURE_HEADER]
		);
	}

	public function test_webhook_url_checks()
	{
		$this->assertTrue(
			WireCore_OAC_Webhook::allowed_url('https://example.org/hook')
		);
		$this->assertFalse(
			WireCore_OAC_Webhook::allowed_url('http://example.org/hook')
		);
		$this->assertFalse(
			WireCore_OAC_Webhook::allowed_url('https://127.0.0.1/hook')
		);
		$this->assertFalse(WireCore_OAC_Webhook::allowed_url(''));
	}
}
