<?php
/**
 * Determines which network scope applies to a given post/term.
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Scope_Matcher {

	private $store;

	public function __construct( LSB_Network_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Return the scope config that matches the entity, or null.
	 *
	 * @param WP_Post|WP_Term $object
	 * @return array|null
	 */
	public function get_scope_for_object( $object ) {
		$scopes = $this->store->get_scopes();
		foreach ( $scopes as $scope ) {
			if ( $this->object_matches_scope( $object, $scope ) ) {
				return $scope;
			}
		}
		return null;
	}

	public function object_matches_scope( $object, array $scope ) {
		if ( ! $object ) return false;
		$kind = $scope['object_kind'] ?? 'post_type';
		$slug = $scope['slug'] ?? '';
		if ( ! $slug ) return false;

		if ( 'post_type' === $kind ) {
			if ( ! ( $object instanceof WP_Post ) ) return false;
			if ( $object->post_type !== $slug ) return false;
			return $this->post_matches_filter( $object, $scope['filter'] ?? [] );
		}

		if ( 'taxonomy' === $kind ) {
			if ( ! ( $object instanceof WP_Term ) ) return false;
			if ( $object->taxonomy !== $slug ) return false;
			return $this->term_matches_filter( $object, $scope['filter'] ?? [] );
		}

		return false;
	}

	private function post_matches_filter( WP_Post $post, array $filter ) {
		$type = $filter['type'] ?? 'all';
		switch ( $type ) {
			case 'parents':
				return 0 === (int) $post->post_parent;
			case 'children':
				return (int) $post->post_parent > 0;
			case 'custom_meta':
				$key = $filter['meta_key'] ?? '';
				$val = $filter['meta_value'] ?? '';
				if ( ! $key ) return false;
				$meta = get_post_meta( $post->ID, $key, true );
				if ( '' === $val ) return ! empty( $meta );
				return (string) $meta === (string) $val;
			case 'all':
			default:
				return true;
		}
	}

	private function term_matches_filter( WP_Term $term, array $filter ) {
		$type = $filter['type'] ?? 'all';
		switch ( $type ) {
			case 'parents':
				return 0 === (int) $term->parent;
			case 'children':
				return (int) $term->parent > 0;
			case 'custom_meta':
				$key = $filter['meta_key'] ?? '';
				$val = $filter['meta_value'] ?? '';
				if ( ! $key ) return false;
				$meta = get_term_meta( $term->term_id, $key, true );
				if ( '' === $val ) return ! empty( $meta );
				return (string) $meta === (string) $val;
			case 'all':
			default:
				return true;
		}
	}

	/**
	 * Return the slug used to key network entity values for this object.
	 */
	public function get_object_slug( $object ) {
		if ( $object instanceof WP_Post ) return (string) $object->post_name;
		if ( $object instanceof WP_Term ) return (string) $object->slug;
		return '';
	}

	/**
	 * Return all objects on the current site that match the scope filter.
	 *
	 * @return array of WP_Post|WP_Term
	 */
	public function find_matching_objects( array $scope, $limit = 500 ) {
		$out = [];
		$kind = $scope['object_kind'] ?? 'post_type';
		$slug = $scope['slug'] ?? '';
		if ( ! $slug ) return $out;

		if ( 'post_type' === $kind ) {
			$args = [
				'post_type'      => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
			];
			$filter = $scope['filter'] ?? [];
			$type   = $filter['type'] ?? 'all';

			if ( 'parents' === $type ) {
				$args['post_parent'] = 0;
			} elseif ( 'custom_meta' === $type && ! empty( $filter['meta_key'] ) ) {
				$mq = [ 'key' => $filter['meta_key'] ];
				if ( isset( $filter['meta_value'] ) && '' !== $filter['meta_value'] ) {
					$mq['value']   = $filter['meta_value'];
					$mq['compare'] = '=';
				} else {
					$mq['compare'] = 'EXISTS';
				}
				$args['meta_query'] = [ $mq ];
			}

			$query = new WP_Query( $args );
			foreach ( $query->posts as $post ) {
				if ( 'children' === $type && 0 === (int) $post->post_parent ) continue;
				$out[] = $post;
			}
			return $out;
		}

		if ( 'taxonomy' === $kind ) {
			$args = [
				'taxonomy'   => $slug,
				'hide_empty' => false,
				'number'     => $limit,
			];
			$filter = $scope['filter'] ?? [];
			$type   = $filter['type'] ?? 'all';
			if ( 'parents' === $type ) $args['parent'] = 0;
			$terms = get_terms( $args );
			if ( is_wp_error( $terms ) ) return $out;
			foreach ( $terms as $term ) {
				if ( 'children' === $type && 0 === (int) $term->parent ) continue;
				if ( 'custom_meta' === $type ) {
					$key = $filter['meta_key'] ?? '';
					$val = $filter['meta_value'] ?? '';
					if ( ! $key ) continue;
					$meta = get_term_meta( $term->term_id, $key, true );
					if ( '' !== $val && (string) $meta !== (string) $val ) continue;
					if ( '' === $val && empty( $meta ) ) continue;
				}
				$out[] = $term;
			}
			return $out;
		}

		return $out;
	}
}
