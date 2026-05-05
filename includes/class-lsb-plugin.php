<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Plugin {

	private static $instance = null;

	public $meta_store;
	public $token_resolver;
	public $settings;
	public $editor_page;
	public $admin_menu;
	public $shortcodes;
	public $yoast_integration;
	public $h1_replacer;
	public $ajax;
	public $csv_handler;

	public $network_store;
	public $scope_matcher;
	public $resolver;
	public $network_cpt_index;
	public $network_entity_index;
	public $network_scope_page;
	public $network_editor_page;
	public $network_address_page;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function init() {
		$this->load_includes();
		$this->instantiate();
		$this->boot();
	}

	private function load_includes() {
		$inc = LSB_PATH . 'includes/';
		require_once $inc . 'class-lsb-meta-store.php';
		require_once $inc . 'class-lsb-network-store.php';
		require_once $inc . 'class-lsb-scope-matcher.php';
		require_once $inc . 'class-lsb-token-resolver.php';
		require_once $inc . 'class-lsb-resolver.php';
		require_once $inc . 'class-lsb-settings.php';
		require_once $inc . 'class-lsb-shortcodes.php';
		require_once $inc . 'class-lsb-yoast-integration.php';
		require_once $inc . 'class-lsb-h1-replacer.php';
		require_once $inc . 'class-lsb-ajax.php';
		require_once $inc . 'class-lsb-csv-handler.php';
		require_once $inc . 'class-lsb-list-table.php';
		require_once $inc . 'class-lsb-editor-page.php';
		require_once $inc . 'class-lsb-admin-menu.php';
		require_once $inc . 'class-lsb-network-cpt-index.php';
		require_once $inc . 'class-lsb-network-entity-index.php';
		require_once $inc . 'class-lsb-network-scope-page.php';
		require_once $inc . 'class-lsb-network-editor-page.php';
		require_once $inc . 'class-lsb-network-address-page.php';
	}

	private function instantiate() {
		$this->meta_store        = new LSB_Meta_Store();
		$this->network_store     = new LSB_Network_Store();
		$this->scope_matcher     = new LSB_Scope_Matcher( $this->network_store );
		$this->token_resolver    = new LSB_Token_Resolver();
		$this->resolver          = new LSB_Resolver( $this->meta_store, $this->network_store, $this->scope_matcher, $this->token_resolver );
		$this->settings          = new LSB_Settings( $this->network_store );
		$this->shortcodes        = new LSB_Shortcodes( $this->meta_store, $this->resolver, $this->token_resolver );
		$this->yoast_integration = new LSB_Yoast_Integration( $this->resolver, $this->token_resolver );
		$this->h1_replacer       = new LSB_H1_Replacer( $this->resolver );
		$this->network_cpt_index    = new LSB_Network_CPT_Index();
		$this->network_entity_index = new LSB_Network_Entity_Index( $this->network_store, $this->scope_matcher );
		$this->csv_handler       = new LSB_CSV_Handler( $this->meta_store, $this->network_store, $this->network_entity_index, $this->scope_matcher );
		$this->ajax              = new LSB_Ajax( $this->meta_store, $this->token_resolver, $this->network_store, $this->scope_matcher, $this->resolver, $this->network_entity_index, $this->csv_handler );
		$this->editor_page       = new LSB_Editor_Page( $this->meta_store, $this->token_resolver, $this->network_store, $this->scope_matcher, $this->resolver );
		$this->admin_menu        = new LSB_Admin_Menu( $this->settings, $this->editor_page );
		$this->network_scope_page   = new LSB_Network_Scope_Page( $this->network_store, $this->network_cpt_index );
		$this->network_editor_page  = new LSB_Network_Editor_Page( $this->network_store, $this->network_entity_index, $this->token_resolver );
		$this->network_address_page = new LSB_Network_Address_Page();
	}

	private function boot() {
		$this->shortcodes->register();
		$this->yoast_integration->init();
		$this->h1_replacer->init();
		$this->ajax->register();

		$this->network_scope_page->init();
		$this->network_editor_page->init();
		$this->network_address_page->init();

		$this->admin_menu->init();
		$this->settings->init();

		add_action( 'admin_enqueue_scripts',         [ $this, 'enqueue_admin_assets' ] );
		add_action( 'network_admin_enqueue_scripts',  [ $this, 'enqueue_admin_assets' ] );
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'lsb-' ) === false ) return;

		wp_enqueue_style(
			'lsb-admin',
			LSB_URL . 'assets/css/admin.css',
			[],
			LSB_VERSION
		);

		wp_enqueue_script(
			'lsb-admin',
			LSB_URL . 'assets/js/admin.js',
			[ 'jquery', 'wp-util' ],
			LSB_VERSION,
			true
		);

		wp_localize_script( 'lsb-admin', 'lsbData', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'lsb_ajax_nonce' ),
			'i18n'    => [
				'saving'        => __( 'Enregistrement…', 'local-seo-bulk' ),
				'saved'         => __( 'Enregistré', 'local-seo-bulk' ),
				'error'         => __( 'Erreur', 'local-seo-bulk' ),
				'unsaved'       => __( 'modification(s) non enregistrée(s)', 'local-seo-bulk' ),
				'saveAllOk'     => __( 'Toutes les modifications ont été enregistrées.', 'local-seo-bulk' ),
				'saveAllError'  => __( 'Une erreur est survenue lors de l\'enregistrement.', 'local-seo-bulk' ),
				'dismiss'       => __( 'Rejeter cette notice.', 'local-seo-bulk' ),
			],
		] );
	}

	public static function activate( $network_wide = false ) {
		if ( false === get_site_option( 'lsb_network_seo_addresses' ) ) {
			add_site_option( 'lsb_network_seo_addresses', [] );
		}
		if ( $network_wide && is_multisite() ) {
			$sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
			foreach ( $sites as $site_id ) {
				switch_to_blog( $site_id );
				self::activate_site();
				restore_current_blog();
			}
		} else {
			self::activate_site();
		}
	}

	private static function activate_site() {
		if ( false === get_option( 'lsb_site_kill_switch' ) ) {
			add_option( 'lsb_site_kill_switch', 0 );
		}
	}
}
