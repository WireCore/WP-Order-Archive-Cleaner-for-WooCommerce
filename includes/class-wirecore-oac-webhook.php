<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Sends a JSON POST request to the configured HTTPS URL whenever an order is
 * archived, signed with HMAC-SHA256 if a secret is set.
 */
class WireCore_OAC_Webhook
{
	const SIGNATURE_HEADER = 'X-WireCore-Signature';

	public static function init()
	{
		add_action(
			'woocommerce_order_status_' . WireCore_OAC_Archiver::STATUS,
			[self::class, 'send'],
			10,
			2
		);
	}

	public static function send($order_id, $order = null)
	{
		$settings = WireCore_OAC_Settings::get();
		$order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
		if (
			!$settings['webhook_enabled'] ||
			!$order ||
			!self::allowed_url($settings['webhook_url'])
		) {
			return;
		}

		$created = $order->get_date_created();
		$body = wp_json_encode(
			self::payload($order, $created ? $created->date('c') : '')
		);
		$headers = ['Content-Type' => 'application/json'];
		if ($settings['webhook_secret'] !== '') {
			$headers[self::SIGNATURE_HEADER] =
				'sha256=' .
				hash_hmac('sha256', $body, $settings['webhook_secret']);
		}

		// wp_safe_remote_post() rejects local and private addresses
		wp_safe_remote_post($settings['webhook_url'], [
			'headers' => $headers,
			'body' => $body,
			'timeout' => 5,
			'blocking' => false,
		]);
	}

	public static function payload(WC_Order $order, $created)
	{
		return [
			'event' => 'order.archived',
			'order_id' => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'previous_status' => (string) $order->get_meta(
				WireCore_OAC_Archiver::META_PREVIOUS_STATUS
			),
			'customer_email' => $order->get_billing_email(),
			'total' => $order->get_total(),
			'currency' => $order->get_currency(),
			'date_created' => $created,
			'site_url' => home_url(),
		];
	}

	public static function allowed_url($url)
	{
		return $url !== '' &&
			wp_parse_url($url, PHP_URL_SCHEME) === 'https' &&
			(bool) wp_http_validate_url($url);
	}
}
