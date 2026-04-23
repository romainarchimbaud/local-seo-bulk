<?php
/**
 * Union dédupliquée des CPT/taxonomies publics sur tous les sous-sites du réseau.
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Network_CPT_Index {

	const TRANSIENT = 'lsb_network_cpt_cache';
	const TTL       = HOUR_IN_SECONDS;

	public function get_index( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_site_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) return $cached;
		}
		$post_types = [];
		$taxonomies = [];

		if ( is_multisite() ) {
			$sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
			foreach ( $sites as $site_id ) {
				switch_to_blog( $site_id );
				$this->collect_site_types( $post_types, $taxonomies, true );
				restore_current_blog();
			}
		} else {
			$this->collect_site_types( $post_types, $taxonomies, false );
		}

		ksort( $post_types );
		ksort( $taxonomies );
		$index = [
			'post_types' => array_values( $post_types ),
			'taxonomies' => array_values( $taxonomies ),
		];
		set_site_transient( self::TRANSIENT, $index, self::TTL );
		return $index;
	}

	private function collect_site_types( &$post_types, &$taxonomies, $check_exists = false ) {
		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) continue;
			if ( ! $check_exists || ! isset( $post_types[ $pt->name ] ) ) {
				$post_types[ $pt->name ] = [
					'slug'  => $pt->name,
					'label' => $pt->labels->name,
				];
			}
		}
		foreach ( get_taxonomies( [ 'show_ui' => true ], 'objects' ) as $tax ) {
			if ( ! $check_exists || ! isset( $taxonomies[ $tax->name ] ) ) {
				$taxonomies[ $tax->name ] = [
					'slug'  => $tax->name,
					'label' => $tax->labels->name,
				];
			}
		}
	}

	public function flush() {
		delete_site_transient( self::TRANSIENT );
	}
}
