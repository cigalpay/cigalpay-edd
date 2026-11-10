<?php
/**
 * Removes the plugin settings. Order notes and order meta are kept: they are the shop's payment records.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cigalpay_edd_settings = get_option( 'edd_settings' );
if ( is_array( $cigalpay_edd_settings ) ) {
	foreach ( array_keys( $cigalpay_edd_settings ) as $cigalpay_edd_key ) {
		if ( 0 === strpos( (string) $cigalpay_edd_key, 'cigalpay_' ) ) {
			unset( $cigalpay_edd_settings[ $cigalpay_edd_key ] );
		}
	}
	update_option( 'edd_settings', $cigalpay_edd_settings );
}
