<?php
/**
 * Base class for the plugin tests: resets the settings between tests and
 * offers helpers for settings and orders.
 */
abstract class WireCore_OAC_TestCase extends WP_UnitTestCase
{
	public function set_up()
	{
		parent::set_up();
		self::reset_settings_cache();
		delete_option(WireCore_OAC_Settings::OPTION);
		delete_option(WireCore_OAC_Archiver::LAST_RUN);
		self::reset_settings_cache();
		reset_phpmailer_instance();
	}

	public function tear_down()
	{
		self::reset_settings_cache();
		parent::tear_down();
	}

	/**
	 * The options table is rolled back after every test, the static cache of
	 * the settings class is not.
	 */
	protected static function reset_settings_cache()
	{
		$property = new ReflectionProperty(
			WireCore_OAC_Settings::class,
			'cache'
		);
		// Needed before PHP 8.1, deprecated since PHP 8.5
		if (PHP_VERSION_ID < 80100) {
			$property->setAccessible(true);
		}
		$property->setValue(null, null);
	}

	protected function settings(array $changes)
	{
		WireCore_OAC_Settings::save(
			array_merge(WireCore_OAC_Settings::get(), $changes)
		);
	}

	/**
	 * @return WC_Order
	 */
	protected function order(
		$status = 'completed',
		$created = '-2 years',
		array $props = []
	) {
		$order = wc_create_order();
		$order->set_props(
			$props + [
				'billing_first_name' => 'Ada',
				'billing_last_name' => 'Lovelace',
				'billing_email' => 'ada@example.org',
				'total' => 20,
			]
		);
		$order->set_status($status);
		$order->set_date_created(strtotime($created));
		$order->save();
		return $order;
	}

	protected function status($order)
	{
		return wc_get_order($order->get_id())->get_status();
	}
}
