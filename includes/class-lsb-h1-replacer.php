<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_H1_Replacer {

	private $resolver;

	public function __construct( LSB_Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	public function init() {
		add_action( 'template_redirect', [ $this, 'maybe_start_buffer' ], 1 );
	}

	public function maybe_start_buffer() {
		if ( ! empty( get_site_option( 'lsb_network_kill_switch', 0 ) ) ) return;
		if ( ! empty( get_option( 'lsb_site_kill_switch', 0 ) ) ) return;

		$object = $this->resolver->get_current_object();
		if ( ! $object ) return;

		$raw = $this->resolver->resolve_raw( $object, 'h1' );
		if ( empty( $raw['raw'] ) ) return;

		if ( null !== $raw['scope'] ) {
			$scope_id       = $raw['scope']['id'] ?? '';
			$site_overrides = get_option( 'lsb_site_scope_h1_overrides', false );
			if ( false === $site_overrides ) {
				$site_overrides = get_site_option( 'lsb_network_scope_h1_overrides', false );
			}

			if ( false !== $site_overrides ) {
				// Site-level override saved: takes precedence over network flag.
				if ( ! in_array( $scope_id, $site_overrides, true ) ) return;
			} else {
				// No site override: respect the network scope's replace_h1 flag.
				if ( isset( $raw['scope']['replace_h1'] ) && false === $raw['scope']['replace_h1'] ) return;
			}
		} else {
			// No scope (including tier 1 local overrides): check site-level force-H1 types.
			$force_types = get_option( 'lsb_h1_force_types', false );
			if ( false === $force_types ) {
				$force_types = get_site_option( 'lsb_network_h1_force_types', false );
			}
			if ( false !== $force_types ) {
				if ( $object instanceof WP_Post && ! in_array( $object->post_type, $force_types, true ) ) return;
				if ( $object instanceof WP_Term && ! in_array( $object->taxonomy, $force_types, true ) ) return;
			}
			// Option never saved: allow all types (no restriction).
		}

		$h1 = $this->resolver->resolve_full( $object, 'h1' );
		if ( '' === $h1 ) return;

		ob_start( function( $html ) use ( $h1 ) {
			return preg_replace(
				'/(<h1\b[^>]*>).*?(<\/h1>)/is',
				'$1' . wp_kses_post( $h1 ) . '$2',
				$html,
				1
			);
		} );
	}
}
