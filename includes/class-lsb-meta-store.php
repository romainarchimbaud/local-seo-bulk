<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Meta_Store {
	// Valid field names
	const FIELDS = [ 'h1', 'title', 'desc' ];

	// Map field name to meta key
	private function meta_key( $field ) {
		return '_lsb_' . $field;
	}

	/**
	 * @param array $entity ['type' => 'post'|'term', 'id' => int]
	 * @param string $field  one of FIELDS
	 * @return mixed|false
	 */
	public function get( $entity, $field ) {
		$key = $this->meta_key( $field );
		if ( 'post' === $entity['type'] ) {
			return get_post_meta( $entity['id'], $key, true );
		}
		if ( 'term' === $entity['type'] ) {
			return get_term_meta( $entity['id'], $key, true );
		}
		return false;
	}

	public function update( $entity, $field, $value ) {
		$key = $this->meta_key( $field );
		if ( 'post' === $entity['type'] ) {
			return update_post_meta( $entity['id'], $key, $value );
		}
		if ( 'term' === $entity['type'] ) {
			return update_term_meta( $entity['id'], $key, $value );
		}
		return false;
	}

	public function delete( $entity, $field ) {
		$key = $this->meta_key( $field );
		if ( 'post' === $entity['type'] ) {
			return delete_post_meta( $entity['id'], $key );
		}
		if ( 'term' === $entity['type'] ) {
			return delete_term_meta( $entity['id'], $key );
		}
		return false;
	}

	// Delete all LSB meta for an entity (used at uninstall)
	public function delete_all( $entity ) {
		foreach ( self::FIELDS as $field ) {
			$this->delete( $entity, $field );
		}
	}
}
