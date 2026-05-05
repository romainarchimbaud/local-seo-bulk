<?php
/**
 * Union dédupliquée par slug des entités (posts/terms) matchant un scope, tous sites confondus.
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Network_Entity_Index {

	const TRANSIENT = 'lsb_network_entity_index';
	const TTL       = 6 * HOUR_IN_SECONDS;

	private $store;
	private $matcher;

	public function __construct( LSB_Network_Store $store, LSB_Scope_Matcher $matcher ) {
		$this->store   = $store;
		$this->matcher = $matcher;
	}

	/**
	 * Full index: scope_id => [ slug => { slug, sample_title, sites: [blog_id…] } ]
	 */
	public function get_index( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_site_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) return $cached;
		}

		$index  = [];
		$scopes = $this->store->get_scopes();
		if ( empty( $scopes ) ) {
			set_site_transient( self::TRANSIENT, $index, self::TTL );
			return $index;
		}

		$sites = is_multisite()
			? get_sites( [ 'fields' => 'ids', 'number' => 0 ] )
			: [ get_current_blog_id() ];

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			foreach ( $scopes as $scope_id => $scope ) {
				$objects = $this->matcher->find_matching_objects( $scope, 500 );
				foreach ( $objects as $obj ) {
					$slug = $this->matcher->get_object_slug( $obj );
					if ( ! $slug ) continue;
					$title = $obj instanceof WP_Post ? get_the_title( $obj ) : $obj->name;
					if ( ! isset( $index[ $scope_id ][ $slug ] ) ) {
						if ( $obj instanceof WP_Post ) {
							$permalink = get_permalink( $obj );
							$url_path  = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : $slug;
						} else {
							$term_link = get_term_link( $obj );
							$url_path  = ! is_wp_error( $term_link ) ? wp_parse_url( $term_link, PHP_URL_PATH ) : $slug;
						}
						$index[ $scope_id ][ $slug ] = [
							'slug'         => $slug,
							'url_path'     => $url_path,
							'sample_title' => $title,
							'sites'        => [],
						];
					}
					if ( ! in_array( $site_id, $index[ $scope_id ][ $slug ]['sites'], true ) ) {
						$index[ $scope_id ][ $slug ]['sites'][] = (int) $site_id;
					}
				}
			}
			restore_current_blog();
		}

		foreach ( $index as $scope_id => $rows ) {
			ksort( $rows );
			$index[ $scope_id ] = $rows;
		}

		set_site_transient( self::TRANSIENT, $index, self::TTL );
		return $index;
	}

	public function flush() {
		delete_site_transient( self::TRANSIENT );
	}
}
