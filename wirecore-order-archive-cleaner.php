<?php
/*
Plugin Name: WireCore Order Archive Cleaner for WooCommerce
Description: Moves old WooCommerce orders to an "Archived" status without deleting them – automatically, with rules, scheduled exports, retention and webhooks.
Author: WireCore GmbH
Author URI: https://wirecore.net
Version: 1.0.0
Requires at least: 6.5
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.2
WC tested up to: 11.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: circle3-order-archive-cleaner-for-woocommerce
Domain Path: /languages
*/

// Prevent direct access
if (!defined('WPINC')) {
	die();
}

define(
	'WIRECORE_OAC_VERSION',
	get_file_data(__FILE__, ['Version' => 'Version'])['Version']
);
define('WIRECORE_OAC_FILE', __FILE__);

require_once __DIR__ . '/includes/class-wirecore-oac-settings.php';
require_once __DIR__ . '/includes/class-wirecore-oac-archiver.php';
require_once __DIR__ . '/includes/class-wirecore-oac-scheduler.php';
require_once __DIR__ . '/includes/class-wirecore-oac-export.php';
require_once __DIR__ . '/includes/class-wirecore-oac-retention.php';
require_once __DIR__ . '/includes/class-wirecore-oac-webhook.php';
require_once __DIR__ . '/includes/class-wirecore-oac-admin.php';
require_once __DIR__ . '/includes/class-wirecore-oac-dashboard.php';

register_activation_hook(__FILE__, ['WireCore_OAC_Scheduler', 'schedule']);
register_deactivation_hook(__FILE__, ['WireCore_OAC_Scheduler', 'unschedule']);

add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}
});

add_action('init', function () {
	// Needed for the bundled translations until language packs exist on
	// translate.wordpress.org.
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain(
		'circle3-order-archive-cleaner-for-woocommerce',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);
});

add_action('plugins_loaded', function () {
	// "Requires Plugins" prevents this on WordPress 6.5+, except when
	// WooCommerce is removed from the file system
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'WireCore Order Archive Cleaner requires WooCommerce to be installed and active.',
					'circle3-order-archive-cleaner-for-woocommerce'
				)
			);
		});
		return;
	}

	WireCore_OAC_Archiver::init();
	WireCore_OAC_Scheduler::init();
	WireCore_OAC_Webhook::init();
	if (is_admin()) {
		WireCore_OAC_Admin::init();
		WireCore_OAC_Dashboard::init();
	}
	if (defined('WP_CLI') && WP_CLI) {
		require_once __DIR__ . '/includes/class-wirecore-oac-cli.php';
		WP_CLI::add_command('wirecore-archive', 'WireCore_OAC_Cli');
	}
});
