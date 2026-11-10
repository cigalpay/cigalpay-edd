<?php
/**
 * Plugin Name:       CigalPay for Easy Digital Downloads
 * Plugin URI:        https://cigalpay.com/developers
 * Description:       Accept crypto payments (Bitcoin, USDT, Ethereum, Solana and more) for your digital products. Prices stay in USD or EUR; customers pick the coin on the CigalPay checkout.
 * Version:           1.0.0
 * Author:            CigalPay
 * Author URI:        https://cigalpay.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       cigalpay-for-easy-digital-downloads
 * Requires at least: 6.7
 * Requires PHP:      8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'CIGALPAY_EDD_VERSION', '1.0.0' );
define( 'CIGALPAY_EDD_DIR', plugin_dir_path( __FILE__ ) );

// The signature canonicalizer of the CigalPay PHP SDK, unless the SDK is already loaded.
if ( ! class_exists( '\Cigalpay\Webhook' ) ) {
	require_once CIGALPAY_EDD_DIR . 'includes/sdk/Webhook.php';
}

add_action(
	'plugins_loaded',
	function () {
		// Easy Digital Downloads 3.x (free or Pro).
		if ( ! function_exists( 'edd_build_order' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'CigalPay for Easy Digital Downloads needs Easy Digital Downloads 3.1 or later.', 'cigalpay-for-easy-digital-downloads' ) . '</p></div>';
					}
				}
			);
			return;
		}
		require_once CIGALPAY_EDD_DIR . 'includes/class-cigalpay-edd-api.php';
		require_once CIGALPAY_EDD_DIR . 'includes/class-cigalpay-edd-gateway.php';
		CigalPay_EDD_Gateway::init();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=extensions&section=cigalpay' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cigalpay-for-easy-digital-downloads' ) . '</a>' );
		return $links;
	}
);
