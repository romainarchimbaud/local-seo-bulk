<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Yoast_Integration {

	private $resolver;
	private $address = null;

	public function __construct( LSB_Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	public function init() {
		add_action( 'init',            [ $this, 'register_yoast_vars' ] );
		add_filter( 'wpseo_title',           [ $this, 'filter_title' ],    10, 1 );
		add_filter( 'wpseo_opengraph_title', [ $this, 'filter_title' ],    10, 1 );
		add_filter( 'wpseo_metadesc',        [ $this, 'filter_metadesc' ], 10, 1 );
		add_filter( 'wpseo_opengraph_desc',  [ $this, 'filter_metadesc' ], 10, 1 );
	}

	public function register_yoast_vars() {
		if ( ! function_exists( 'wpseo_register_var_replacement' ) ) return;

		wpseo_register_var_replacement( '%%lsb_ville%%', function() {
			$a = $this->get_address();
			return $a['ville'] ?? '';
		}, 'advanced', __( 'Ville (Local SEO Bulk)', 'local-seo-bulk' ) );

		wpseo_register_var_replacement( '%%lsb_code_postal%%', function() {
			$a = $this->get_address();
			return $a['code_postal'] ?? '';
		}, 'advanced', __( 'Code postal (Local SEO Bulk)', 'local-seo-bulk' ) );

		wpseo_register_var_replacement( '%%lsb_adresse%%', function() {
			$a = $this->get_address();
			return $a['adresse'] ?? '';
		}, 'advanced', __( 'Adresse (Local SEO Bulk)', 'local-seo-bulk' ) );
	}

	public function filter_title( $title ) {
		if ( $this->is_killed() ) return $title;
		$object = $this->resolver->get_current_object();
		if ( ! $object ) return $title;
		$resolved = $this->resolver->resolve_full( $object, 'title' );
		return $resolved !== '' ? $resolved : $title;
	}

	public function filter_metadesc( $desc ) {
		if ( $this->is_killed() ) return $desc;
		$object = $this->resolver->get_current_object();
		if ( ! $object ) return $desc;
		$resolved = $this->resolver->resolve_full( $object, 'desc' );
		return $resolved !== '' ? $resolved : $desc;
	}

	private function is_killed() {
		return ! empty( get_option( 'lsb_site_kill_switch', 0 ) );
	}

	private function get_address() {
		if ( null === $this->address ) {
			$this->address = get_option( 'lsb_address', [] );
		}
		return $this->address;
	}
}
