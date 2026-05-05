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
				$snapshot = get_site_option( 'lsb_site_cpts_' . $site_id, null );
				if ( is_array( $snapshot ) ) {
					foreach ( $snapshot['post_types'] ?? [] as $slug ) {
						if ( ! isset( $post_types[ $slug ] ) ) {
							$post_types[ $slug ] = [ 'slug' => $slug, 'label' => $slug ];
						}
					}
					foreach ( $snapshot['taxonomies'] ?? [] as $slug ) {
						if ( ! isset( $taxonomies[ $slug ] ) ) {
							$taxonomies[ $slug ] = [ 'slug' => $slug, 'label' => $slug ];
						}
					}
				} else {
					// Fallback for sites not yet visited: switch_to_blog (same-theme sites only).
					switch_to_blog( $site_id );
					$collected = $this->collect_site_types( true );
					$post_types += $collected['post_types'];
					$taxonomies += $collected['taxonomies'];
					restore_current_blog();
				}
			}
		} else {
			$collected = $this->collect_site_types( false );
			$post_types = $collected['post_types'];
			$taxonomies = $collected['taxonomies'];
		}

		// Enrich labels using currently registered types where available.
		foreach ( array_keys( $post_types ) as $slug ) {
			$obj = get_post_type_object( $slug );
			if ( $obj ) $post_types[ $slug ]['label'] = $obj->labels->name;
		}
		foreach ( array_keys( $taxonomies ) as $slug ) {
			$obj = get_taxonomy( $slug );
			if ( $obj ) $taxonomies[ $slug ]['label'] = $obj->labels->name;
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

	private function collect_site_types( $skip_duplicates = false ) {
		$post_types = [];
		$taxonomies = [];

		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) continue;
			if ( ! $skip_duplicates || ! isset( $post_types[ $pt->name ] ) ) {
				$post_types[ $pt->name ] = [
					'slug'  => $pt->name,
					'label' => $pt->labels->name,
				];
			}
		}
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			if ( ! $skip_duplicates || ! isset( $taxonomies[ $tax->name ] ) ) {
				$taxonomies[ $tax->name ] = [
					'slug'  => $tax->name,
					'label' => $tax->labels->name,
				];
			}
		}
		return [
			'post_types' => $post_types,
			'taxonomies' => $taxonomies,
		];
	}

	public function flush() {
		delete_site_transient( self::TRANSIENT );
	}
}
