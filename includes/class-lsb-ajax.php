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

	public function __construct(
		LSB_Meta_Store $meta_store,
		LSB_Token_Resolver $token_resolver,
		LSB_Network_Store $network_store,
		LSB_Scope_Matcher $scope_matcher,
		LSB_Resolver $resolver
	) {
		$this->meta_store     = $meta_store;
		$this->token_resolver = $token_resolver;
		$this->network_store  = $network_store;
		$this->scope_matcher  = $scope_matcher;
		$this->resolver       = $resolver;
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
			$this->network_store->delete_entity_slug( $scope_id, $slug );
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
				$this->network_store->delete_entity_slug( $scope_id, $slug );
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

	public function import_csv() {
		$this->verify_nonce();

		$lsb_object = sanitize_text_field( wp_unslash( $_POST['lsb_object'] ?? '' ) );
		$parts      = explode( '|', $lsb_object, 2 );
		$kind       = $parts[0] ?? '';
		$type_slug  = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';

		if ( ! $kind || ! $type_slug || empty( $_FILES['lsb_csv']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Paramètres invalides.', 'local-seo-bulk' ) ] );
		}

		$file = $_FILES['lsb_csv']['tmp_name'];
		$fh   = fopen( $file, 'r' );
		if ( ! $fh ) {
			wp_send_json_error( [ 'message' => __( 'Impossible de lire le fichier.', 'local-seo-bulk' ) ] );
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = [];
		$line_num = 0;

		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$line_num++;
			if ( 1 === $line_num ) continue; // skip header
			if ( empty( $row[0] ) || str_starts_with( trim( $row[0] ), '#' ) ) continue;

			$slug  = sanitize_title( trim( $row[0] ) );
			$h1    = isset( $row[1] ) ? sanitize_text_field( wp_unslash( trim( $row[1] ) ) ) : '';
			$title = isset( $row[2] ) ? sanitize_text_field( wp_unslash( trim( $row[2] ) ) ) : '';
			$desc  = isset( $row[3] ) ? sanitize_text_field( wp_unslash( trim( $row[3] ) ) ) : '';

			$object = null;
			if ( 'post_type' === $kind ) {
				$posts = get_posts( [
					'post_type'      => $type_slug,
					'name'           => $slug,
					'posts_per_page' => 1,
					'post_status'    => 'publish',
					'no_found_rows'  => true,
				] );
				$object = ! empty( $posts ) ? $posts[0] : null;
			} elseif ( 'taxonomy' === $kind ) {
				$term   = get_term_by( 'slug', $slug, $type_slug );
				$object = $term ?: null;
			}

			if ( ! $object ) {
				$skipped++;
				$errors[] = sprintf( __( 'Ligne %d : slug "%s" introuvable.', 'local-seo-bulk' ), $line_num, $slug );
				continue;
			}

			$entity = $object instanceof WP_Post
				? [ 'type' => 'post', 'id' => $object->ID ]
				: [ 'type' => 'term', 'id' => $object->term_id ];

			if ( '' !== $h1    ) $this->meta_store->update( $entity, 'h1',    $h1    );
			if ( '' !== $title ) $this->meta_store->update( $entity, 'title', $title );
			if ( '' !== $desc  ) $this->meta_store->update( $entity, 'desc',  $desc  );

			$imported++;
		}

		fclose( $fh );

		wp_send_json_success( [
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		] );
	}

	public function download_csv_template() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		check_ajax_referer( 'lsb_ajax_nonce', 'nonce' );

		$type = sanitize_key( $_GET['lsb_object'] ?? '' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lsb-import-' . $type . '.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'slug', 'h1', 'title', 'desc' ] );
		fputcsv( $out, [ '# exemple-slug', 'Mon H1 %%lsb_ville%%', 'Mon titre | %%sitename%%', 'Ma description.' ] );
		fclose( $out );
		exit;
	}

	public function download_network_csv_template() {
		if ( ! current_user_can( 'manage_network_options' ) ) wp_die( 'Forbidden' );
		check_ajax_referer( 'lsb_ajax_nonce', 'nonce' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lsb-network-import.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'scope_id', 'slug', 'h1', 'title', 'desc' ] );
		fputcsv( $out, [ '# produits-parents', 'exemple-slug', 'Mon H1 %%lsb_ville%%', 'Mon titre | %%sitename%%', 'Ma description.' ] );
		fclose( $out );
		exit;
	}

	public function import_network_csv() {
		$this->verify_nonce( true );

		if ( empty( $_FILES['lsb_csv']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Fichier manquant.', 'local-seo-bulk' ) ] );
		}

		$file = $_FILES['lsb_csv']['tmp_name'];
		$fh   = fopen( $file, 'r' );
		if ( ! $fh ) {
			wp_send_json_error( [ 'message' => __( 'Impossible de lire le fichier.', 'local-seo-bulk' ) ] );
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = [];
		$line_num = 0;

		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$line_num++;
			if ( 1 === $line_num ) continue; // skip header
			if ( empty( $row[0] ) || str_starts_with( trim( $row[0] ), '#' ) ) continue;

			$scope_id = sanitize_key( trim( $row[0] ) );
			$slug     = sanitize_title( trim( $row[1] ?? '' ) );
			$h1       = sanitize_text_field( wp_unslash( trim( $row[2] ?? '' ) ) );
			$title    = sanitize_text_field( wp_unslash( trim( $row[3] ?? '' ) ) );
			$desc     = sanitize_text_field( wp_unslash( trim( $row[4] ?? '' ) ) );

			if ( ! $scope_id || ! $slug ) {
				$skipped++;
				$errors[] = sprintf( __( 'Ligne %d : scope_id ou slug manquant.', 'local-seo-bulk' ), $line_num );
				continue;
			}

			if ( '' !== $h1    ) $this->network_store->set_entity_value( $scope_id, $slug, 'h1',    $h1    );
			if ( '' !== $title ) $this->network_store->set_entity_value( $scope_id, $slug, 'title', $title );
			if ( '' !== $desc  ) $this->network_store->set_entity_value( $scope_id, $slug, 'desc',  $desc  );

			$imported++;
		}

		fclose( $fh );

		wp_send_json_success( [
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		] );
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
}
