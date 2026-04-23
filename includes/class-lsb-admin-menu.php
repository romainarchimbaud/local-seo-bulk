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
	}

	public function register_menus() {
		add_menu_page(
			__( 'SEO Masse', 'local-seo-bulk' ),
			__( 'SEO Masse', 'local-seo-bulk' ),
			'manage_options',
			'lsb-editor',
			[ $this->editor_page, 'render_page' ],
			'dashicons-location-alt',
			80
		);

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
}
