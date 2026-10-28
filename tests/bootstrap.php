<?php
/**
 * Bootstrap for the PHPUnit integration tests.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');
$wirecore_oac_tests_dir = getenv('WP_PHPUNIT__DIR');

require $wirecore_oac_tests_dir . '/includes/functions.php';

// WooCommerce from composer (wpackagist-plugin/woocommerce)
tests_add_filter('muplugins_loaded', function () {
	require dirname(__DIR__) .
		'/vendor/wpackagist-plugin/woocommerce/woocommerce.php';
	require dirname(__DIR__) . '/wirecore-order-archive-cleaner.php';
});

// After the theme directories are registered, WooCommerce checks the theme
tests_add_filter('after_setup_theme', function () {
	WC_Install::install();
	// Reload the capabilities added by WooCommerce
	$GLOBALS['wp_roles'] = null;
	wp_roles();

	// WIRECORE_OAC_HPOS=1: orders in the HPOS tables instead of posts
	$hpos = (bool) getenv('WIRECORE_OAC_HPOS');
	if ($hpos) {
		wc_get_container()
			->get(
				\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class
			)
			->create_database_tables();
	}
	update_option(
		'woocommerce_custom_orders_table_enabled',
		$hpos ? 'yes' : 'no'
	);
});

require $wirecore_oac_tests_dir . '/includes/bootstrap.php';

require __DIR__ . '/class-wirecore-oac-testcase.php';
