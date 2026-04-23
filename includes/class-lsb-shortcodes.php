<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Shortcodes {

	private $meta_store;
	private $resolver;
	private $address = null;

	public function __construct( LSB_Meta_Store $meta_store, LSB_Resolver $resolver ) {
		$this->meta_store = $meta_store;
		$this->resolver   = $resolver;
	}

	public function register() {
		add_shortcode( 'lsb_ville',       [ $this, 'shortcode_ville' ] );
		add_shortcode( 'lsb_code_postal', [ $this, 'shortcode_code_postal' ] );
		add_shortcode( 'lsb_adresse',     [ $this, 'shortcode_adresse' ] );
		add_shortcode( 'lsb_h1',          [ $this, 'shortcode_h1' ] );
	}

	public function shortcode_ville( $atts ) {
		$address = $this->get_address();
		return esc_html( $address['ville'] ?? '' );
	}

	public function shortcode_code_postal( $atts ) {
		$address = $this->get_address();
		return esc_html( $address['code_postal'] ?? '' );
	}

	public function shortcode_adresse( $atts ) {
		$address = $this->get_address();
		return esc_html( $address['adresse'] ?? '' );
	}

	public function shortcode_h1( $atts ) {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) return '';
		$h1 = $this->resolver->resolve_full( $post, 'h1' );
		return esc_html( $h1 );
	}

	private function get_address() {
		if ( null === $this->address ) {
			$this->address = get_option( 'lsb_address', [] );
		}
		return $this->address;
	}
}
