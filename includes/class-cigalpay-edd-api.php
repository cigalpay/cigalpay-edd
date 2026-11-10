<?php
/**
 * The CigalPay calls the shop needs, over the WordPress HTTP API. Same
 * requests and signature check as the CigalPay PHP SDK (cigalpay/cigalpay-php-sdk).
 */

defined( 'ABSPATH' ) || exit;

final class CigalPay_EDD_API {

	const CHECKOUT_URL     = 'https://coins-api.cigalpay.com';
	const MERCHANT_API_URL = 'https://merchant-api.cigalpay.com';

	/** Payment statuses sent in notifications. CONFIRMED and FINISHED mean paid. */
	const STATUS_WAITING        = 'WAITING';
	const STATUS_CONFIRMING     = 'CONFIRMING';
	const STATUS_CONFIRMED      = 'CONFIRMED';
	const STATUS_FINISHED       = 'FINISHED';
	const STATUS_PARTIALLY_PAID = 'PARTIALLY_PAID';
	const STATUS_EXPIRED        = 'EXPIRED';
	const STATUS_FAILED         = 'FAILED';

	/**
	 * Creates a hosted checkout. Returns its reply (id, chargeLink, ...) or a WP_Error.
	 *
	 * @param array $checkout accountId, amount, currency, extraId, description, lang, callback, ipn
	 * @return array|WP_Error
	 */
	public static function create_checkout( array $checkout ) {
		/**
		 * The CigalPay checkout API address (e.g. a local one for tests).
		 *
		 * @param string $url
		 */
		$base     = untrailingslashit( apply_filters( 'cigalpay_edd_checkout_url', self::CHECKOUT_URL ) );
		$response = wp_remote_post(
			$base . '/api/pre-invoice',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $checkout ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['id'] ) || empty( $data['chargeLink'] ) ) {
			$message = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : 'unexpected reply';
			return new WP_Error( 'cigalpay_checkout', sprintf( 'HTTP %d: %s', $status, $message ) );
		}
		return $data;
	}

	/**
	 * Refunds a paid payment from the CigalPay balance. Returns the reply
	 * (refund, link: where the customer enters their address) or a WP_Error.
	 *
	 * @param string $api_key
	 * @param array  $refund paymentId, checkoutAmount (in the order currency), customerEmail, reason
	 * @return array|WP_Error
	 */
	public static function create_refund( $api_key, array $refund ) {
		/**
		 * The CigalPay merchant API address (e.g. a local one for tests).
		 *
		 * @param string $url
		 */
		$base     = untrailingslashit( apply_filters( 'cigalpay_edd_merchant_api_url', self::MERCHANT_API_URL ) );
		$response = wp_remote_post(
			$base . '/api/refunds',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'Accept'           => 'application/json',
					'cigalpay-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $refund ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['refund']['id'] ) ) {
			$message = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : sprintf( 'unexpected reply (HTTP %d)', $status );
			return new WP_Error( 'cigalpay_refund', $message );
		}
		return $data;
	}

	/**
	 * Checks a notification's x-cigalpay-sig header against the raw body, as received.
	 *
	 * @param string $raw_body
	 * @param string $signature
	 * @param string $secret notification signing secret
	 */
	public static function verify_notification( $raw_body, $signature, $secret ) {
		if ( ! is_string( $raw_body ) || ! is_string( $signature ) || '' === $signature || ! is_string( $secret ) || '' === $secret ) {
			return false;
		}
		$signed = \Cigalpay\Webhook::canonical( $raw_body );
		if ( null === $signed ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha512', $signed, $secret ), $signature );
	}

	/** True when a notification's status means the order is paid. */
	public static function is_paid( $status ) {
		return self::STATUS_CONFIRMED === $status || self::STATUS_FINISHED === $status;
	}
}
