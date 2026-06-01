<?php

/**
 * @package LocalSeoBulk
 */

if (! defined('ABSPATH')) exit;

class LSB_Shortcodes {

    private $meta_store;
    private $resolver;
    private $token_resolver;

    public function __construct(LSB_Meta_Store $meta_store, LSB_Resolver $resolver, LSB_Token_Resolver $token_resolver) {
        $this->meta_store     = $meta_store;
        $this->resolver       = $resolver;
        $this->token_resolver = $token_resolver;
    }

    public function register() {
        add_shortcode('lsb_ville',       [$this, 'shortcode_ville']);
        add_shortcode('lsb_code_postal', [$this, 'shortcode_code_postal']);
        add_shortcode('lsb_adresse',     [$this, 'shortcode_adresse']);
        add_shortcode('lsb_departement',  [$this, 'shortcode_departement']);
        add_shortcode('lsb_h1',          [$this, 'shortcode_h1']);
        add_shortcode('lsb_nom_site',    [$this, 'shortcode_nom_site']);
    }

    public function shortcode_nom_site($atts) {
        $name = get_bloginfo('name');
        return esc_html($name);
    }

    public function shortcode_ville($atts) {
        $address = $this->token_resolver->get_address();
        return esc_html($address['ville'] ?? '');
    }

    public function shortcode_code_postal($atts) {
        $address = $this->token_resolver->get_address();
        return esc_html($address['code_postal'] ?? '');
    }

    public function shortcode_adresse($atts) {
        $address = $this->token_resolver->get_address();
        return esc_html($address['adresse'] ?? '');
    }

    public function shortcode_departement($atts) {
        $address = $this->token_resolver->get_address();
        return esc_html($address['departement'] ?? '');
    }

    public function shortcode_h1($atts) {
        $post = get_queried_object();
        if (! $post instanceof WP_Post) return '';
        $h1 = $this->resolver->resolve_full($post, 'h1');
        return esc_html($h1);
    }
}
