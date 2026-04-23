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
		if ( ! empty( get_option( 'lsb_site_kill_switch', 0 ) ) ) return;

		$object = $this->resolver->get_current_object();
		if ( ! $object ) return;

		$raw = $this->resolver->resolve_raw( $object, 'h1' );
		if ( empty( $raw['raw'] ) ) return;

		// Skip if this scope has automatic H1 replacement disabled.
		if ( isset( $raw['scope']['replace_h1'] ) && false === $raw['scope']['replace_h1'] ) return;

		// If no scope covers this object, check the site-level force H1 setting.
		if ( null === $raw['scope'] ) {
			$force_types = get_option( 'lsb_h1_force_types', [] );
			if ( $object instanceof WP_Post && ! in_array( $object->post_type, $force_types, true ) ) return;
			if ( $object instanceof WP_Term && ! in_array( $object->taxonomy, $force_types, true ) ) return;
		}

		$h1 = $this->resolver->resolve_full( $object, 'h1' );
		if ( '' === $h1 ) return;

		ob_start( function( $html ) use ( $h1 ) {
			return preg_replace(
				'/(<h1\b[^>]*>).*?(<\/h1>)/is',
				'$1' . esc_html( $h1 ) . '$2',
				$html,
				1
			);
		} );
	}
}
