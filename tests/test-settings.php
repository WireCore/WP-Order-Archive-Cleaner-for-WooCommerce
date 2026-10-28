<?php
/**
 * WireCore_OAC_Settings: defaults and sanitizing.
 */
class Test_WireCore_OAC_Settings extends WireCore_OAC_TestCase
{
	public function test_defaults_apply_without_stored_option()
	{
		$settings = WireCore_OAC_Settings::get();

		$this->assertTrue($settings['enabled']);
		$this->assertSame(12, $settings['cutoff_months']);
		$this->assertSame(['completed', 'cancelled'], $settings['statuses']);
		$this->assertSame(50, $settings['batch_size']);
		$this->assertFalse($settings['retention_enabled']);
		$this->assertFalse($settings['webhook_enabled']);
	}

	public function test_numbers_are_clamped_to_their_range()
	{
		$settings = WireCore_OAC_Settings::sanitize([
			'cutoff_months' => 0,
			'batch_size' => 5000,
			'retention_years' => 'abc',
			'exclude_above_total' => '-3',
		]);

		$this->assertSame(1, $settings['cutoff_months']);
		$this->assertSame(
			WireCore_OAC_Settings::MAX_BATCH_SIZE,
			$settings['batch_size']
		);
		$this->assertSame(7, $settings['retention_years']);
		$this->assertSame(0.0, $settings['exclude_above_total']);
	}

	public function test_lists_keep_known_values_only()
	{
		$category = self::factory()->term->create([
			'taxonomy' => 'product_cat',
		]);
		$settings = WireCore_OAC_Settings::sanitize([
			'statuses' => ['completed', 'archived', 'wc-cancelled', 'refunded'],
			'exclude_roles' => ['editor', 'no-such-role'],
			'exclude_categories' => [$category, '999999', 'x'],
		]);

		$this->assertSame(['completed', 'refunded'], $settings['statuses']);
		$this->assertSame(['editor'], $settings['exclude_roles']);
		$this->assertSame([$category], $settings['exclude_categories']);
	}

	public function test_webhook_url_must_be_https_and_frequency_known()
	{
		$this->assertSame(
			'',
			WireCore_OAC_Settings::sanitize([
				'webhook_url' => 'http://example.org/hook',
			])['webhook_url']
		);
		$this->assertSame(
			'https://example.org/hook',
			WireCore_OAC_Settings::sanitize([
				'webhook_url' => ' https://example.org/hook ',
			])['webhook_url']
		);
		$this->assertSame(
			'monthly',
			WireCore_OAC_Settings::sanitize(['export_frequency' => 'daily'])[
				'export_frequency'
			]
		);
	}

	public function test_empty_status_list_is_kept()
	{
		$this->settings(['statuses' => []]);

		$this->assertSame([], WireCore_OAC_Settings::get()['statuses']);
		$this->assertSame(0, WireCore_OAC_Archiver::run_batch()['archived']);
	}
}
