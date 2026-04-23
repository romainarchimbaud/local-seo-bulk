<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Admin_Menu {

	private $settings;
	private $editor_page;

	public function __construct( LSB_Settings $settings, LSB_Editor_Page $editor_page ) {
		$this->settings    = $settings;
		$this->editor_page = $editor_page;
	}

	public function init() {
		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_filter( 'set_screen_option_lsb_items_per_page', [ $this, 'save_screen_option' ], 10, 3 );
	}

	public function register_menus() {
		$hook = add_menu_page(
			__( 'SEO Masse', 'local-seo-bulk' ),
			__( 'SEO Masse', 'local-seo-bulk' ),
			'manage_options',
			'lsb-editor',
			[ $this->editor_page, 'render_page' ],
			'dashicons-location-alt',
			80
		);
		add_action( 'load-' . $hook, [ $this, 'add_screen_options' ] );

		add_submenu_page(
			'lsb-editor',
			__( 'Éditeur SEO', 'local-seo-bulk' ),
			__( 'Éditeur', 'local-seo-bulk' ),
			'manage_options',
			'lsb-editor',
			[ $this->editor_page, 'render_page' ]
		);

		add_submenu_page(
			'lsb-editor',
			__( 'Réglages — Local SEO Bulk', 'local-seo-bulk' ),
			__( 'Réglages', 'local-seo-bulk' ),
			'manage_options',
			'lsb-settings',
			[ $this->settings, 'render_settings_page' ]
		);
	}

	public function add_screen_options() {
		add_screen_option( 'per_page', [
			'label'   => __( 'Éléments par page', 'local-seo-bulk' ),
			'default' => 50,
			'option'  => 'lsb_items_per_page',
		] );
	}

	public function save_screen_option( $screen_option, $option, $value ) {
		return (int) $value;
	}
}
