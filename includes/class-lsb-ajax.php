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

	public function __construct(
		LSB_Meta_Store $meta_store,
		LSB_Token_Resolver $token_resolver,
		LSB_Network_Store $network_store,
		LSB_Scope_Matcher $scope_matcher,
		LSB_Resolver $resolver,
		LSB_Network_Entity_Index $entity_index
	) {
		$this->meta_store     = $meta_store;
		$this->token_resolver = $token_resolver;
		$this->network_store  = $network_store;
		$this->scope_matcher  = $scope_matcher;
		$this->resolver       = $resolver;
		$this->entity_index   = $entity_index;
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
		add_action( 'wp_ajax_lsb_save_network_address_row',    [ $this, 'save_network_address_row' ] );
		add_action( 'wp_ajax_lsb_save_network_address_all',    [ $this, 'save_network_address_all' ] );
		add_action( 'wp_ajax_lsb_import_network_address_csv',  [ $this, 'import_network_address_csv' ] );
		add_action( 'wp_ajax_lsb_export_network_address_csv',  [ $this, 'export_network_address_csv' ] );
		add_action( 'wp_ajax_lsb_prefill_network_addresses',   [ $this, 'prefill_network_addresses_from_acf' ] );
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
		$filename = isset( $_FILES['lsb_csv']['name'] ) ? sanitize_file_name( $_FILES['lsb_csv']['name'] ) : '';

		// Validate CSV MIME type
		if ( ! $this->validate_csv_mime_type( $file, $filename ) ) {
			wp_send_json_error( [ 'message' => __( 'Le fichier doit être un fichier CSV valide.', 'local-seo-bulk' ) ] );
		}

		$delimiter = $this->detect_csv_delimiter( $file );
		$fh = fopen( $file, 'r' );
		if ( ! $fh ) {
			wp_send_json_error( [ 'message' => __( 'Impossible de lire le fichier.', 'local-seo-bulk' ) ] );
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = [];
		$line_num = 0;

		while ( ( $row = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
			$line_num++;
			if ( 1 === $line_num ) continue; // skip header
			if ( empty( $row[0] ) || str_starts_with( trim( $row[0] ), '#' ) ) continue;

			$slug  = trim( $row[0] );
			$h1    = isset( $row[1] ) ? sanitize_text_field( wp_unslash( trim( $row[1] ) ) ) : '';
			$title = isset( $row[2] ) ? sanitize_text_field( wp_unslash( trim( $row[2] ) ) ) : '';
			$desc  = isset( $row[3] ) ? sanitize_text_field( wp_unslash( trim( $row[3] ) ) ) : '';

			$object = null;
			if ( 'post_type' === $kind ) {
				if ( str_starts_with( $slug, '/' ) ) {
					// URL path slug (new format): resolve via url_to_postid
					$parsed  = wp_parse_url( network_home_url() );
					$origin  = $parsed['scheme'] . '://' . $parsed['host'];
					if ( ! empty( $parsed['port'] ) ) $origin .= ':' . $parsed['port'];
					$post_id = url_to_postid( $origin . '/' . ltrim( $slug, '/' ) );
					if ( $post_id ) {
						$p = get_post( $post_id );
						if ( $p && $p->post_type === $type_slug && $p->post_status === 'publish' ) {
							$object = $p;
						}
					}
				} else {
					// Legacy: bare post_name slug
					$posts = get_posts( [
						'post_type'      => $type_slug,
						'name'           => sanitize_title( $slug ),
						'posts_per_page' => 1,
						'post_status'    => 'publish',
						'no_found_rows'  => true,
					] );
					$object = ! empty( $posts ) ? $posts[0] : null;
				}
			} elseif ( 'taxonomy' === $kind ) {
				$term_slug = str_starts_with( $slug, '/' ) ? basename( rtrim( $slug, '/' ) ) : sanitize_title( $slug );
				$term      = get_term_by( 'slug', $term_slug, $type_slug );
				$object    = $term ?: null;
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

		$lsb_object = sanitize_text_field( wp_unslash( $_GET['lsb_object'] ?? '' ) );
		$parts      = explode( '|', $lsb_object, 2 );
		$kind       = $parts[0] ?? '';
		$type_slug  = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
		$fname      = sanitize_file_name( str_replace( '|', '-', $lsb_object ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lsb-import-' . $fname . '.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'slug', 'h1', 'title', 'desc' ] );

		$objects = [];
		if ( 'post_type' === $kind && $type_slug ) {
			$objects = get_posts( [
				'post_type'      => $type_slug,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			] );
		} elseif ( 'taxonomy' === $kind && $type_slug ) {
			$objects = get_terms( [ 'taxonomy' => $type_slug, 'hide_empty' => false, 'number' => 500, 'orderby' => 'name', 'order' => 'ASC' ] );
			if ( is_wp_error( $objects ) ) $objects = [];
		} elseif ( 'scope' === $kind && $type_slug ) {
			$scopes = $this->network_store->get_scopes();
			$scope  = $scopes[ $type_slug ] ?? null;
			if ( $scope ) {
				$objects = $this->scope_matcher->find_matching_objects( $scope, 500 );
			}
		}

		if ( empty( $objects ) ) {
			fputcsv( $out, [ '# exemple-slug', 'Mon H1 %%lsb_ville%%', 'Mon titre | %%sitename%%', 'Ma description.' ] );
		} else {
			foreach ( $objects as $obj ) {
				if ( $obj instanceof WP_Post ) {
					$permalink = get_permalink( $obj );
					$slug      = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : $obj->post_name;
					$entity    = [ 'type' => 'post', 'id' => $obj->ID ];
				} else {
					$term_link = get_term_link( $obj );
					$slug      = ! is_wp_error( $term_link ) ? wp_parse_url( $term_link, PHP_URL_PATH ) : $obj->slug;
					$entity    = [ 'type' => 'term', 'id' => $obj->term_id ];
				}
				fputcsv( $out, [
					$slug,
					$this->meta_store->get( $entity, 'h1' ) ?: '',
					$this->meta_store->get( $entity, 'title' ) ?: '',
					$this->meta_store->get( $entity, 'desc' ) ?: '',
				] );
			}
		}

		fclose( $out );
		exit;
	}

	public function download_network_csv_template() {
		if ( ! current_user_can( 'manage_network_options' ) ) wp_die( 'Forbidden' );
		check_ajax_referer( 'lsb_ajax_nonce', 'nonce' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lsb-network-import.csv"' );
		header( 'Pragma: no-cache' );

		$out   = fopen( 'php://output', 'w' );
		$index = $this->entity_index->get_index();

		fputcsv( $out, [ 'scope_id', 'slug', 'h1', 'title', 'desc' ] );

		$has_rows = false;
		foreach ( $index as $scope_id => $rows ) {
			foreach ( $rows as $slug => $row ) {
				fputcsv( $out, [
					$scope_id,
					$slug,
					$this->network_store->get_entity_value( $scope_id, $slug, 'h1' ) ?: '',
					$this->network_store->get_entity_value( $scope_id, $slug, 'title' ) ?: '',
					$this->network_store->get_entity_value( $scope_id, $slug, 'desc' ) ?: '',
				] );
				$has_rows = true;
			}
		}

		if ( ! $has_rows ) {
			fputcsv( $out, [ '# produits-parents', 'exemple-slug', 'Mon H1 %%lsb_ville%%', 'Mon titre | %%sitename%%', 'Ma description.' ] );
		}

		fclose( $out );
		exit;
	}

	public function import_network_csv() {
		$this->verify_nonce( true );

		if ( empty( $_FILES['lsb_csv']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Fichier manquant.', 'local-seo-bulk' ) ] );
		}

		$file = $_FILES['lsb_csv']['tmp_name'];
		$filename = isset( $_FILES['lsb_csv']['name'] ) ? sanitize_file_name( $_FILES['lsb_csv']['name'] ) : '';

		// Validate CSV MIME type
		if ( ! $this->validate_csv_mime_type( $file, $filename ) ) {
			wp_send_json_error( [ 'message' => __( 'Le fichier doit être un fichier CSV valide.', 'local-seo-bulk' ) ] );
		}

		$delimiter = $this->detect_csv_delimiter( $file );
		$fh = fopen( $file, 'r' );
		if ( ! $fh ) {
			wp_send_json_error( [ 'message' => __( 'Impossible de lire le fichier.', 'local-seo-bulk' ) ] );
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = [];
		$rows     = [];
		$line_num = 0;

		while ( ( $row = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
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

			$rows[] = [
				'scope_id' => $scope_id,
				'slug'     => $slug,
				'fields'   => [ 'h1' => $h1, 'title' => $title, 'desc' => $desc ],
			];
			$imported++;
		}

		fclose( $fh );

		wp_send_json_success( [
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'rows'     => $rows,
		] );
	}

	// ---- Helpers ----

	private function detect_csv_delimiter( $file ) {
		$fh   = fopen( $file, 'r' );
		$line = fgets( $fh );
		fclose( $fh );
		return ( substr_count( $line, ';' ) > substr_count( $line, ',' ) ) ? ';' : ',';
	}

	private function verify_nonce( $network = false ) {
		$cap = $network ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'local-seo-bulk' ) ], 403 );
		}
		if ( ! check_ajax_referer( 'lsb_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce invalide.', 'local-seo-bulk' ) ], 403 );
		}
	}

	private function validate_csv_mime_type( $file_path, $filename = '' ) {
		// Always require .csv extension — text/plain is too broad to trust alone.
		if ( $filename && strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) !== 'csv' ) {
			return false;
		}

		// Use finfo to verify content-level MIME type.
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime_type = finfo_file( $finfo, $file_path );
				finfo_close( $finfo );
				$valid_types = [ 'text/csv', 'text/plain', 'application/csv' ];
				return in_array( $mime_type, $valid_types, true );
			}
		}

		// finfo unavailable and extension already passed above.
		return true;
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

	public function import_network_address_csv() {
		$this->verify_nonce( true );

		if ( empty( $_FILES['lsb_csv']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Aucun fichier reçu.', 'local-seo-bulk' ) ] );
		}

		$file     = $_FILES['lsb_csv']['tmp_name'];
		$filename = isset( $_FILES['lsb_csv']['name'] ) ? sanitize_file_name( $_FILES['lsb_csv']['name'] ) : '';
		if ( ! $this->validate_csv_mime_type( $file, $filename ) ) {
			wp_send_json_error( [ 'message' => __( 'Le fichier doit être un CSV valide.', 'local-seo-bulk' ) ] );
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore
		if ( ! $handle ) {
			wp_send_json_error( [ 'message' => __( 'Impossible de lire le fichier.', 'local-seo-bulk' ) ] );
		}

		$delim  = $this->detect_csv_delimiter( $file );
		$header = fgetcsv( $handle, 0, $delim );
		if ( ! $header ) {
			fclose( $handle ); // phpcs:ignore
			wp_send_json_error( [ 'message' => __( 'Fichier vide.', 'local-seo-bulk' ) ] );
		}

		$map = array_flip( array_map( 'trim', $header ) );

		if ( ! isset( $map['blog_id'] ) ) {
			fclose( $handle ); // phpcs:ignore
			wp_send_json_error( [ 'message' => __( 'Colonne blog_id manquante.', 'local-seo-bulk' ) ] );
		}

		$all      = get_site_option( 'lsb_network_seo_addresses', [] );
		$imported = 0;
		$skipped  = 0;

		while ( ( $cols = fgetcsv( $handle, 0, $delim ) ) !== false ) {
			if ( empty( $cols[0] ) || str_starts_with( trim( $cols[0] ), '#' ) ) continue;

			$blog_id = (int) ( $cols[ $map['blog_id'] ] ?? 0 );
			if ( ! $blog_id ) { $skipped++; continue; }

			$all[ $blog_id ] = [
				'ville'       => sanitize_text_field( wp_unslash( $cols[ $map['ville']       ?? PHP_INT_MAX ] ?? '' ) ),
				'code_postal' => sanitize_text_field( wp_unslash( $cols[ $map['code_postal'] ?? PHP_INT_MAX ] ?? '' ) ),
				'adresse'     => sanitize_text_field( wp_unslash( $cols[ $map['adresse']     ?? PHP_INT_MAX ] ?? '' ) ),
				'departement' => sanitize_text_field( wp_unslash( $cols[ $map['departement'] ?? PHP_INT_MAX ] ?? '' ) ),
			];
			$imported++;
		}

		fclose( $handle ); // phpcs:ignore
		update_site_option( 'lsb_network_seo_addresses', $all );
		wp_send_json_success( [ 'imported' => $imported, 'skipped' => $skipped ] );
	}

	public function export_network_address_csv() {
		if ( ! check_ajax_referer( 'lsb_ajax_nonce', 'nonce', false ) ) wp_die( 'Nonce invalide.' );
		if ( ! current_user_can( 'manage_network_options' ) ) wp_die( 'Permission refusée.' );

		$all   = get_site_option( 'lsb_network_seo_addresses', [] );
		$sites = get_sites( [ 'number' => 0, 'deleted' => 0 ] );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lsb-network-addresses.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore
		fputcsv( $out, [ 'blog_id', 'ville', 'code_postal', 'adresse', 'departement' ], ';' );
		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;
			$addr    = $all[ $blog_id ] ?? [];
			fputcsv( $out, [
				$blog_id,
				$addr['ville']       ?? '',
				$addr['code_postal'] ?? '',
				$addr['adresse']     ?? '',
				$addr['departement'] ?? '',
			], ';' );
		}
		fclose( $out ); // phpcs:ignore
		exit;
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
