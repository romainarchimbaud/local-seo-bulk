<?php
/**
 * Cleanup à la désinstallation du plugin.
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

$site_options = [ 'lsb_network_scopes', 'lsb_network_entity_values' ];
$site_transients = [ 'lsb_network_cpt_cache', 'lsb_network_entity_index' ];
$per_site_options = [ 'lsb_address', 'lsb_settings', 'lsb_site_kill_switch' ];
$meta_keys = [ '_lsb_h1', '_lsb_title', '_lsb_desc' ];

foreach ( $site_options as $opt ) {
	delete_site_option( $opt );
}
foreach ( $site_transients as $t ) {
	delete_site_transient( $t );
}

$cleanup_site = static function () use ( $wpdb, $per_site_options, $meta_keys ) {
	foreach ( $per_site_options as $option ) {
		delete_option( $option );
	}
	foreach ( $meta_keys as $key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $key ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->termmeta, [ 'meta_key' => $key ] );
	}
};

if ( is_multisite() ) {
	$sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
	foreach ( $sites as $site_id ) {
		switch_to_blog( $site_id );
		$cleanup_site();
		restore_current_blog();
	}
} else {
	$cleanup_site();
}
