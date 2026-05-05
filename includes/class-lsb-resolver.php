<?php
/**
 * Centralises 3-tier resolution: local meta > network entity value > scope pattern.
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Resolver {

	private $meta_store;
	private $network_store;
	private $scope_matcher;
	private $token_resolver;

	public function __construct(
		LSB_Meta_Store $meta_store,
		LSB_Network_Store $network_store,
		LSB_Scope_Matcher $scope_matcher,
		LSB_Token_Resolver $token_resolver
	) {
		$this->meta_store     = $meta_store;
		$this->network_store  = $network_store;
		$this->scope_matcher  = $scope_matcher;
		$this->token_resolver = $token_resolver;
	}

	/**
	 * Resolve the raw (unresolved) value for an entity + field through the 3 tiers.
	 * Returns an array: [ 'raw' => string, 'tier' => 1|2|3|0, 'scope' => array|null ]
	 */
	public function resolve_raw( $object, $field ) {
		// Tier 1: local meta — works without a scope
		$entity = $this->entity_from_object( $object );
		if ( $entity ) {
			$local = $this->meta_store->get( $entity, $field );
			if ( ! empty( $local ) ) {
				$scope = $this->scope_matcher->get_scope_for_object( $object );
				return [ 'raw' => (string) $local, 'tier' => 1, 'scope' => $scope ];
			}
		}

		// Tiers 2 and 3 require a network scope
		$scope = $this->scope_matcher->get_scope_for_object( $object );
		if ( ! $scope ) {
			return [ 'raw' => '', 'tier' => 0, 'scope' => null ];
		}

		$slug = $this->scope_matcher->get_object_slug( $object );
		$net  = $this->network_store->get_entity_value( $scope['id'], $slug, $field );
		if ( ! empty( $net ) ) {
			return [ 'raw' => $net, 'tier' => 2, 'scope' => $scope ];
		}

		$pattern = $this->network_store->get_scope_pattern( $scope['id'], $field );
		if ( ! empty( $pattern ) ) {
			return [ 'raw' => $pattern, 'tier' => 3, 'scope' => $scope ];
		}

		return [ 'raw' => '', 'tier' => 0, 'scope' => $scope ];
	}

	/**
	 * Resolve the effective network-level raw value for a slug (tier 2 fallback to tier 3).
	 * Used by the site editor's "Pattern réseau effectif" column.
	 */
	public function resolve_network_raw( $scope_id, $slug, $field ) {
		$net = $this->network_store->get_entity_value( $scope_id, $slug, $field );
		if ( ! empty( $net ) ) return [ 'raw' => $net, 'tier' => 2 ];
		$pattern = $this->network_store->get_scope_pattern( $scope_id, $field );
		if ( ! empty( $pattern ) ) return [ 'raw' => $pattern, 'tier' => 3 ];
		return [ 'raw' => '', 'tier' => 0 ];
	}

	/**
	 * Full resolution (raw + token substitution) for an entity + field.
	 * Returns resolved string (may be empty).
	 */
	public function resolve_full( $object, $field ) {
		$res = $this->resolve_raw( $object, $field );
		if ( empty( $res['raw'] ) ) return '';
		return $this->token_resolver->resolve( $res['raw'], $object );
	}

	private function entity_from_object( $object ) {
		if ( $object instanceof WP_Post ) return [ 'type' => 'post', 'id' => $object->ID ];
		if ( $object instanceof WP_Term ) return [ 'type' => 'term', 'id' => $object->term_id ];
		return null;
	}

	public function get_current_object() {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) return $post;
		}
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) return $term;
		}
		return null;
	}
}
