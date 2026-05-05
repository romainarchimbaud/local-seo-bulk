<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Ajax {

	private $meta_store;
	private $token_resolver;
	private $network_store;
	private $scope_matcher;
	private $resolver;
	private $entity_index;
	private $csv_handler;

	public function __construct(
		LSB_Meta_Store $meta_store,
		LSB_Token_Resolver $token_resolver,
		LSB_Network_Store $network_store,
		LSB_Scope_Matcher $scope_matcher,
		LSB_Resolver $resolver,
		LSB_Network_Entity_Index $entity_index,
		LSB_CSV_Handler $csv_handler
	) {
		$this->meta_store     = $meta_store;
		$this->token_resolver = $token_resolver;
		$this->network_store  = $network_store;
		$this->scope_matcher  = $scope_matcher;
		$this->resolver       = $resolver;
		$this->entity_index   = $entity_index;
		$this->csv_handler    = $csv_handler;
	}

	public function register() {
		// Site-level
		add_action( 'wp_ajax_lsb_save_row',           [ $this, 'save_row' ] );
		add_action( 'wp_ajax_lsb_save_all',           [ $this, 'save_all' ] );
		add_action( 'wp_ajax_lsb_preview_token',      [ $this, 'preview_token' ] );
		// Network-level
		add_action( 'wp_ajax_lsb_save_network_row',   [ $this, 'save_network_row' ] );
		add_action( 'wp_ajax_lsb_save_network_all',   [ $this, 'save_network_all' ] );
		add_action( 'wp_ajax_lsb_delete_network_slug', [ $this, 'delete_network_slug' ] );
		// admin-post handler for entity index refresh
		add_action( 'admin_post_lsb_refresh_entity_index', [ $this, 'handle_refresh_entity_index' ] );
		// CSV import
		add_action( 'wp_ajax_lsb_import_csv',         [ $this, 'import_csv' ] );
		add_action( 'wp_ajax_lsb_csv_template',       [ $this, 'download_csv_template' ] );
		add_action( 'wp_ajax_lsb_import_network_csv',    [ $this, 'import_network_csv' ] );
		add_action( 'wp_ajax_lsb_network_csv_template',  [ $this, 'download_network_csv_template' ] );
		// Network address management
		add_action( 'wp_ajax_lsb_save_network_address_row',        [ $this, 'save_network_address_row' ] );
		add_action( 'wp_ajax_lsb_save_network_address_all',        [ $this, 'save_network_address_all' ] );
		add_action( 'wp_ajax_lsb_import_network_address_csv',      [ $this, 'import_network_address_csv' ] );
		add_action( 'wp_ajax_lsb_export_network_address_csv',      [ $this, 'export_network_address_csv' ] );
		add_action( 'wp_ajax_lsb_network_address_csv_template',    [ $this, 'download_network_address_csv_template' ] );
		add_action( 'wp_ajax_lsb_prefill_network_addresses',       [ $this, 'prefill_network_addresses_from_acf' ] );
		// CSV exports (data)
		add_action( 'wp_ajax_lsb_export_csv',         [ $this, 'export_csv' ] );
		add_action( 'wp_ajax_lsb_export_network_csv', [ $this, 'export_network_csv' ] );
	}

	// ---- Site-level ----

	public function save_row() {
		$this->verify_nonce();

		$field       = sanitize_key( $_POST['field']       ?? '' );
		$entity_type = sanitize_key( $_POST['entity_type'] ?? '' );
		$entity_id   = (int) ( $_POST['entity_id']         ?? 0 );
		$value       = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );

		if ( ! $field || ! $entity_type || ! $entity_id ) {
			wp_send_json_error( [ 'message' => __( 'Paramètres invalides.', 'local-seo-bulk' ) ] );
		}
		if ( '' === $value ) {
			$this->meta_store->delete( [ 'type' => $entity_type, 'id' => $entity_id ], $field );
			wp_send_json_success( [ 'resolved' => '', 'message' => __( 'Valeur effacée.', 'local-seo-bulk' ) ] );
		}

		$entity = [ 'type' => $entity_type, 'id' => $entity_id ];
		$this->meta_store->update( $entity, $field, $value );
		$resolved = $this->token_resolver->resolve( $value );
		wp_send_json_success( [ 'resolved' => $resolved, 'message' => __( 'Enregistré.', 'local-seo-bulk' ) ] );
	}

	public function save_all() {
		$this->verify_nonce();

		$rows = $_POST['rows'] ?? [];
		if ( ! is_array( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Données invalides.', 'local-seo-bulk' ) ] );
		}

		$saved = 0;
		foreach ( $rows as $row ) {
			$field       = sanitize_key( $row['field']       ?? '' );
			$entity_type = sanitize_key( $row['entity_type'] ?? '' );
			$entity_id   = (int) ( $row['entity_id']         ?? 0 );
			$value       = sanitize_text_field( wp_unslash( $row['value'] ?? '' ) );
			if ( ! $field || ! $entity_type || ! $entity_id ) continue;
			$entity = [ 'type' => $entity_type, 'id' => $entity_id ];
			if ( '' === $value ) {
				$this->meta_store->delete( $entity, $field );
			} else {
				$this->meta_store->update( $entity, $field, $value );
			}
			$saved++;
		}
		wp_send_json_success( [
			'saved'   => $saved,
			'message' => sprintf( __( '%d ligne(s) enregistrée(s).', 'local-seo-bulk' ), $saved ),
		] );
	}

	public function preview_token() {
		$this->verify_nonce();
		$value    = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );
		$resolved = $this->token_resolver->resolve( $value );
		wp_send_json_success( [ 'resolved' => $resolved ] );
	}

	// ---- Network-level ----

	public function save_network_row() {
		$this->verify_nonce( true );

		$scope_id = sanitize_key( $_POST['scope_id'] ?? '' );
		$slug     = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
		$field    = sanitize_key( $_POST['field'] ?? '' );
		$value    = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );

		if ( ! $scope_id || ! $slug || ! $field ) {
			wp_send_json_error( [ 'message' => __( 'Paramètres invalides.', 'local-seo-bulk' ) ] );
		}

		if ( '' === $value ) {
			$this->network_store->delete_entity_field( $scope_id, $slug, $field );
			wp_send_json_success( [ 'message' => __( 'Valeur réseau effacée.', 'local-seo-bulk' ) ] );
		}

		$this->network_store->set_entity_value( $scope_id, $slug, $field, $value );
		wp_send_json_success( [ 'message' => __( 'Valeur réseau enregistrée.', 'local-seo-bulk' ) ] );
	}

	public function save_network_all() {
		$this->verify_nonce( true );

		$rows = $_POST['rows'] ?? [];
		if ( ! is_array( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Données invalides.', 'local-seo-bulk' ) ] );
		}

		$saved = 0;
		foreach ( $rows as $row ) {
			$scope_id = sanitize_key( $row['scope_id'] ?? '' );
			$slug     = sanitize_text_field( wp_unslash( $row['slug'] ?? '' ) );
			$field    = sanitize_key( $row['field'] ?? '' );
			$value    = sanitize_text_field( wp_unslash( $row['value'] ?? '' ) );
			if ( ! $scope_id || ! $slug || ! $field ) continue;
			if ( '' === $value ) {
				$this->network_store->delete_entity_field( $scope_id, $slug, $field );
			} else {
				$this->network_store->set_entity_value( $scope_id, $slug, $field, $value );
			}
			$saved++;
		}
		wp_send_json_success( [
			'saved'   => $saved,
			'message' => sprintf( __( '%d ligne(s) réseau enregistrée(s).', 'local-seo-bulk' ), $saved ),
		] );
	}

	public function delete_network_slug() {
		$this->verify_nonce( true );
		$scope_id = sanitize_key( $_POST['scope_id'] ?? '' );
		$slug     = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( ! $scope_id || ! $slug ) {
			wp_send_json_error( [ 'message' => __( 'Paramètres invalides.', 'local-seo-bulk' ) ] );
		}
		$this->network_store->delete_entity_slug( $scope_id, $slug );
		wp_send_json_success( [ 'message' => __( 'Entrée réseau supprimée.', 'local-seo-bulk' ) ] );
	}

	public function handle_refresh_entity_index() {
		if ( ! current_user_can( 'manage_network_options' ) ) wp_die( 'Forbidden' );
		check_admin_referer( 'lsb_refresh_entity_index' );
		delete_site_transient( LSB_Network_Entity_Index::TRANSIENT );
		wp_safe_redirect( network_admin_url( 'admin.php?page=' . LSB_Network_Editor_Page::PAGE_SLUG . '&index_refreshed=1' ) );
		exit;
	}

	public function import_csv(): void {
		$this->verify_nonce();
		$this->csv_handler->import_csv();
	}

	public function download_csv_template(): void {
		$this->csv_handler->download_csv_template();
	}

	public function export_csv(): void {
		$this->csv_handler->export_csv();
	}

	public function download_network_csv_template(): void {
		$this->csv_handler->download_network_csv_template();
	}

	public function export_network_csv(): void {
		$this->csv_handler->export_network_csv();
	}

	public function import_network_csv(): void {
		$this->verify_nonce( true );
		$this->csv_handler->import_network_csv();
	}

	// ---- Helpers ----

	private function verify_nonce( $network = false ) {
		$cap = $network ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'local-seo-bulk' ) ], 403 );
		}
		if ( ! check_ajax_referer( 'lsb_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce invalide.', 'local-seo-bulk' ) ], 403 );
		}
	}

	// ---- Network address management ----

	public function save_network_address_row() {
		$this->verify_nonce( true );

		$blog_id     = (int) ( $_POST['blog_id']     ?? 0 );
		$ville       = sanitize_text_field( wp_unslash( $_POST['ville']       ?? '' ) );
		$code_postal = sanitize_text_field( wp_unslash( $_POST['code_postal'] ?? '' ) );
		$adresse     = sanitize_text_field( wp_unslash( $_POST['adresse']     ?? '' ) );
		$departement = sanitize_text_field( wp_unslash( $_POST['departement'] ?? '' ) );

		if ( ! $blog_id ) {
			wp_send_json_error( [ 'message' => __( 'blog_id invalide.', 'local-seo-bulk' ) ] );
		}
		if ( ! get_site( $blog_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Site introuvable.', 'local-seo-bulk' ) ] );
		}

		$all             = get_site_option( 'lsb_network_seo_addresses', [] );
		$all[ $blog_id ] = [
			'ville'       => $ville,
			'code_postal' => $code_postal,
			'adresse'     => $adresse,
			'departement' => $departement,
		];
		update_site_option( 'lsb_network_seo_addresses', $all );
		wp_send_json_success( [ 'message' => __( 'Enregistré.', 'local-seo-bulk' ) ] );
	}

	public function save_network_address_all() {
		$this->verify_nonce( true );

		$rows = $_POST['rows'] ?? [];
		if ( ! is_array( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Données invalides.', 'local-seo-bulk' ) ] );
		}

		$all   = get_site_option( 'lsb_network_seo_addresses', [] );
		$saved = 0;
		foreach ( $rows as $row_data ) {
			$blog_id = (int) ( $row_data['blog_id'] ?? 0 );
			if ( ! $blog_id ) continue;
			if ( ! get_site( $blog_id ) ) continue;
			$all[ $blog_id ] = [
				'ville'       => sanitize_text_field( wp_unslash( $row_data['ville']       ?? '' ) ),
				'code_postal' => sanitize_text_field( wp_unslash( $row_data['code_postal'] ?? '' ) ),
				'adresse'     => sanitize_text_field( wp_unslash( $row_data['adresse']     ?? '' ) ),
				'departement' => sanitize_text_field( wp_unslash( $row_data['departement'] ?? '' ) ),
			];
			$saved++;
		}
		update_site_option( 'lsb_network_seo_addresses', $all );
		wp_send_json_success( [ 'saved' => $saved, 'message' => __( 'Tout enregistré.', 'local-seo-bulk' ) ] );
	}

	public function import_network_address_csv(): void {
		$this->verify_nonce( true );
		$this->csv_handler->import_network_address_csv();
	}

	public function export_network_address_csv(): void {
		$this->verify_nonce( true );
		$this->csv_handler->export_network_address_csv();
	}

	public function download_network_address_csv_template(): void {
		$this->csv_handler->download_network_address_csv_template();
	}

	public function prefill_network_addresses_from_acf() {
		$this->verify_nonce( true );

		if ( ! function_exists( 'get_field' ) ) {
			wp_send_json_error( [ 'message' => __( 'ACF non disponible.', 'local-seo-bulk' ) ] );
		}

		$acf_field = sanitize_key( wp_unslash( $_POST['acf_field'] ?? 'adresse' ) );
		update_site_option( 'lsb_network_address_acf_field', $acf_field );

		$sites  = get_sites( [ 'number' => 0, 'deleted' => 0 ] );
		$all    = get_site_option( 'lsb_network_seo_addresses', [] );
		$filled = 0;

		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;
			$current = $all[ $blog_id ] ?? [];

			if ( ! empty( $current['ville'] ) && ! empty( $current['code_postal'] ) ) continue;

			switch_to_blog( $blog_id );
			$acf_value = get_field( $acf_field, 'option' );
			restore_current_blog();

			if ( ! is_array( $acf_value ) ) continue;

			$street          = trim( ( $acf_value['street_number'] ?? '' ) . ' ' . ( $acf_value['street_name'] ?? '' ) );
			$all[ $blog_id ] = [
				'ville'       => empty( $current['ville'] )       ? sanitize_text_field( $acf_value['city']      ?? '' ) : $current['ville'],
				'code_postal' => empty( $current['code_postal'] ) ? sanitize_text_field( $acf_value['post_code'] ?? '' ) : $current['code_postal'],
				'adresse'     => empty( $current['adresse'] )     ? sanitize_text_field( $street )                       : $current['adresse'],
				'departement' => $current['departement'] ?? '',
			];
			$filled++;
		}

		update_site_option( 'lsb_network_seo_addresses', $all );
		wp_send_json_success( [ 'filled' => $filled ] );
	}
}
