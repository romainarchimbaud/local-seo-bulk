<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Token_Resolver {

	private $address = null;

	public function resolve( $raw, $context = null ) {
		if ( empty( $raw ) ) return $raw;

		$value = do_shortcode( $raw );

		$address     = $this->get_address();
		$ville       = ! empty( $address['ville'] )       ? $address['ville']       : '';
		$code_postal = ! empty( $address['code_postal'] ) ? $address['code_postal'] : '';
		$adresse     = ! empty( $address['adresse'] )     ? $address['adresse']     : '';
		$departement = ! empty( $address['departement'] ) ? $address['departement'] : '';

		$value = str_replace(
			[ '%%lsb_ville%%', '%%lsb_code_postal%%', '%%lsb_adresse%%', '%%lsb_departement%%' ],
			[ $ville, $code_postal, $adresse, $departement ],
			$value
		);

		// Yoast native variables — try Yoast API first, then fallback to manual replacement
		if ( strpos( $value, '%%' ) !== false ) {
			$resolved_by_yoast = false;

			if ( ( $context instanceof WP_Post || $context instanceof WP_Term )
				&& function_exists( 'YoastSEO' )
			) {
				$replace_vars = isset( YoastSEO()->helpers->replace_vars ) ? YoastSEO()->helpers->replace_vars : null;
				if ( $replace_vars && method_exists( $replace_vars, 'replace' ) ) {
					$yoast_context     = $context instanceof WP_Post ? $context : null;
					$value             = $replace_vars->replace( $value, $yoast_context );
					$resolved_by_yoast = true;
				}
			}

			// Fallback: resolve common Yoast tokens manually so %%sitename%% and %%sep%% always work
			if ( ! $resolved_by_yoast && strpos( $value, '%%' ) !== false ) {
				$value = $this->resolve_yoast_vars_fallback( $value, $context );
			}

			// Always apply fallback for tokens that Yoast API may not cover (e.g. when context was a WP_Term)
			if ( $resolved_by_yoast && $context instanceof WP_Term && strpos( $value, '%%' ) !== false ) {
				$value = $this->resolve_yoast_vars_fallback( $value, $context );
			}
		}

		return $value;
	}

	private function resolve_yoast_vars_fallback( $value, $context = null ) {
		$replacements = [
			'%%sitename%%' => get_bloginfo( 'name' ),
			'%%sep%%'      => $this->get_yoast_separator(),
		];

		if ( $context instanceof WP_Post ) {
			$replacements['%%title%%'] = get_the_title( $context );
		} elseif ( $context instanceof WP_Term ) {
			$replacements['%%title%%'] = $context->name;
		}

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
	}

	private function get_yoast_separator() {
		if ( class_exists( 'WPSEO_Options' ) ) {
			$sep_key = WPSEO_Options::get( 'separator' );
			$map     = [
				'sc-dash'   => '-',
				'sc-ndash'  => '–',
				'sc-mdash'  => '—',
				'sc-middot' => '·',
				'sc-bull'   => '•',
				'sc-star'   => '*',
				'sc-smstar' => '⋆',
				'sc-pipe'   => '|',
				'sc-tilde'  => '~',
				'sc-laquo'  => '«',
				'sc-raquo'  => '»',
				'sc-lt'     => '<',
				'sc-gt'     => '>',
			];
			return isset( $map[ $sep_key ] ) ? $map[ $sep_key ] : '-';
		}
		return '-';
	}

	public function get_address() {
		if ( null === $this->address ) {
			$all           = get_site_option( 'lsb_network_seo_addresses', [] );
			$this->address = $all[ get_current_blog_id() ] ?? [];
		}
		return $this->address;
	}
}
