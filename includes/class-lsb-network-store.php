<?php
/**
 * Network-level storage for scopes and per-slug entity values.
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Network_Store {

	const OPT_SCOPES         = 'lsb_network_scopes';
	const OPT_ENTITY_VALUES  = 'lsb_network_entity_values';
	const FIELDS             = [ 'h1', 'title', 'desc' ];

	/**
	 * @return array scope_id => scope config
	 */
	public function get_scopes() {
		$scopes = get_site_option( self::OPT_SCOPES, [] );
		return is_array( $scopes ) ? $scopes : [];
	}

	public function get_scope( $scope_id ) {
		$scopes = $this->get_scopes();
		return isset( $scopes[ $scope_id ] ) ? $scopes[ $scope_id ] : null;
	}

	/**
	 * Save or create a scope.
	 */
	public function save_scope( $scope_id, array $config ) {
		$scopes = $this->get_scopes();
		$scopes[ $scope_id ] = $this->sanitize_scope( array_merge( [ 'id' => $scope_id ], $config ) );
		update_site_option( self::OPT_SCOPES, $scopes );
		return $scopes[ $scope_id ];
	}

	public function delete_scope( $scope_id ) {
		$scopes = $this->get_scopes();
		if ( isset( $scopes[ $scope_id ] ) ) {
			unset( $scopes[ $scope_id ] );
			update_site_option( self::OPT_SCOPES, $scopes );
		}
		$values = $this->get_all_entity_values();
		if ( isset( $values[ $scope_id ] ) ) {
			unset( $values[ $scope_id ] );
			update_site_option( self::OPT_ENTITY_VALUES, $values );
		}
	}

	/**
	 * Read scope-level pattern (tier 3).
	 *
	 * @return string
	 */
	public function get_scope_pattern( $scope_id, $field ) {
		$scope = $this->get_scope( $scope_id );
		if ( ! $scope || ! in_array( $field, self::FIELDS, true ) ) return '';
		return isset( $scope['patterns'][ $field ] ) ? (string) $scope['patterns'][ $field ] : '';
	}

	public function get_scope_force( $scope_id, $field ) {
		$scope = $this->get_scope( $scope_id );
		if ( ! $scope ) return false;
		$key = 'force_' . $field;
		return ! empty( $scope['patterns'][ $key ] );
	}

	// ---- Entity values (tier 2) ----

	public function get_all_entity_values() {
		$values = get_site_option( self::OPT_ENTITY_VALUES, [] );
		return is_array( $values ) ? $values : [];
	}

	public function get_entity_value( $scope_id, $slug, $field ) {
		$values = $this->get_all_entity_values();
		if ( ! isset( $values[ $scope_id ][ $slug ][ $field ] ) ) return '';
		return (string) $values[ $scope_id ][ $slug ][ $field ];
	}

	public function get_entity_row( $scope_id, $slug ) {
		$values = $this->get_all_entity_values();
		return isset( $values[ $scope_id ][ $slug ] ) ? $values[ $scope_id ][ $slug ] : [];
	}

	public function set_entity_value( $scope_id, $slug, $field, $value ) {
		if ( ! in_array( $field, self::FIELDS, true ) ) return false;
		$values = $this->get_all_entity_values();
		if ( ! isset( $values[ $scope_id ] ) )         $values[ $scope_id ] = [];
		if ( ! isset( $values[ $scope_id ][ $slug ] ) ) $values[ $scope_id ][ $slug ] = [];
		$values[ $scope_id ][ $slug ][ $field ] = (string) $value;
		return update_site_option( self::OPT_ENTITY_VALUES, $values );
	}

	public function delete_entity_slug( $scope_id, $slug ) {
		$values = $this->get_all_entity_values();
		if ( isset( $values[ $scope_id ][ $slug ] ) ) {
			unset( $values[ $scope_id ][ $slug ] );
			update_site_option( self::OPT_ENTITY_VALUES, $values );
		}
	}

	public function delete_entity_field( $scope_id, $slug, $field ) {
		if ( ! in_array( $field, self::FIELDS, true ) ) return;
		$values = $this->get_all_entity_values();
		if ( ! isset( $values[ $scope_id ][ $slug ] ) ) return;
		unset( $values[ $scope_id ][ $slug ][ $field ] );
		if ( empty( $values[ $scope_id ][ $slug ] ) ) {
			unset( $values[ $scope_id ][ $slug ] );
		}
		update_site_option( self::OPT_ENTITY_VALUES, $values );
	}

	// ---- Sanitization ----

	private function sanitize_scope( $config ) {
		$clean = [
			'id'          => sanitize_key( $config['id'] ?? '' ),
			'label'       => sanitize_text_field( $config['label'] ?? '' ),
			'object_kind' => in_array( ( $config['object_kind'] ?? '' ), [ 'post_type', 'taxonomy' ], true ) ? $config['object_kind'] : 'post_type',
			'slug'        => sanitize_key( $config['slug'] ?? '' ),
			'replace_h1'  => isset( $config['replace_h1'] ) ? (bool) $config['replace_h1'] : true,
			'filter'      => [],
			'patterns'    => [],
		];

		$filter = $config['filter'] ?? [];
		$type   = in_array( ( $filter['type'] ?? '' ), [ 'all', 'parents', 'children', 'custom_meta' ], true ) ? $filter['type'] : 'all';
		$clean['filter'] = [
			'type'       => $type,
			'meta_key'   => sanitize_key( $filter['meta_key'] ?? '' ),
			'meta_value' => sanitize_text_field( $filter['meta_value'] ?? '' ),
		];

		$patterns = $config['patterns'] ?? [];
		foreach ( self::FIELDS as $field ) {
			$clean['patterns'][ $field ]            = isset( $patterns[ $field ] ) ? (string) $patterns[ $field ] : '';
			$clean['patterns'][ 'force_' . $field ] = ! empty( $patterns[ 'force_' . $field ] );
		}

		return $clean;
	}
}
