<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_CSV_Handler {

	private $meta_store;
	private $network_store;
	private $entity_index;
	private $scope_matcher;

	public function __construct(
		LSB_Meta_Store $meta_store,
		LSB_Network_Store $network_store,
		LSB_Network_Entity_Index $entity_index,
		LSB_Scope_Matcher $scope_matcher
	) {
		$this->meta_store    = $meta_store;
		$this->network_store = $network_store;
		$this->entity_index  = $entity_index;
		$this->scope_matcher = $scope_matcher;
	}

	public function import_csv() {
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

		// Pre-build a slug→object map for scope imports (url_path and bare slug keys).
		$scope_objects_map = null;
		if ( 'scope' === $kind ) {
			$scopes_list = $this->network_store->get_scopes();
			$scope_def   = $scopes_list[ $type_slug ] ?? null;
			if ( $scope_def ) {
				$scope_objects_map = [];
				foreach ( $this->scope_matcher->find_matching_objects( $scope_def, 500 ) as $sobj ) {
					if ( $sobj instanceof WP_Post ) {
						$permalink = get_permalink( $sobj );
						$url_path  = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : null;
						if ( $url_path ) $scope_objects_map[ $url_path ] = $sobj;
						$scope_objects_map[ $sobj->post_name ] = $sobj;
					} else {
						$term_link = get_term_link( $sobj );
						$url_path  = ! is_wp_error( $term_link ) ? wp_parse_url( $term_link, PHP_URL_PATH ) : null;
						if ( $url_path ) $scope_objects_map[ $url_path ] = $sobj;
						$scope_objects_map[ $sobj->slug ] = $sobj;
					}
				}
			}
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

			$slug  = trim( $row[0] );
			$h1    = isset( $row[1] ) ? wp_kses_post( wp_unslash( trim( $row[1] ) ) ) : '';
			$title = isset( $row[2] ) ? sanitize_text_field( wp_unslash( trim( $row[2] ) ) ) : '';
			$desc  = isset( $row[3] ) ? sanitize_text_field( wp_unslash( trim( $row[3] ) ) ) : '';

			$object = null;
			if ( 'post_type' === $kind ) {
				if ( str_starts_with( $slug, '/' ) ) {
					// URL path slug: resolve via url_to_postid on the current site.
					$parsed  = wp_parse_url( home_url() );
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
			} elseif ( 'scope' === $kind ) {
				$object = $scope_objects_map !== null ? ( $scope_objects_map[ $slug ] ?? null ) : null;
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

			$rows[] = [
				'entity_type' => $entity['type'],
				'entity_id'   => $entity['id'],
				'fields'      => [ 'h1' => $h1, 'title' => $title, 'desc' => $desc ],
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
		fputcsv( $out, [ 'slug', 'h1', 'title', 'desc' ], ',' );

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

		foreach ( $objects as $obj ) {
			if ( $obj instanceof WP_Post ) {
				$permalink = get_permalink( $obj );
				$slug      = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : $obj->post_name;
			} else {
				$term_link = get_term_link( $obj );
				$slug      = ! is_wp_error( $term_link ) ? wp_parse_url( $term_link, PHP_URL_PATH ) : $obj->slug;
			}
			fputcsv( $out, [ $slug, '', '', '' ], ',' );
		}

		fclose( $out );
		exit;
	}

	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		check_ajax_referer( 'lsb_ajax_nonce', 'nonce' );

		$lsb_object = sanitize_text_field( wp_unslash( $_GET['lsb_object'] ?? '' ) );
		$parts      = explode( '|', $lsb_object, 2 );
		$kind       = $parts[0] ?? '';
		$type_slug  = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
		$fname      = sanitize_file_name( str_replace( '|', '-', $lsb_object ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lsb-export-' . $fname . '.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'slug', 'h1', 'title', 'desc' ], ',' );

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
			], ',' );
		}

		fclose( $out );
		exit;
	}

	public function download_network_csv_template() {
		if ( ! current_user_can( 'manage_network_options' ) ) wp_die( 'Forbidden' );
		check_ajax_referer( 'lsb_ajax_nonce', 'nonce' );

		$scope_id = sanitize_key( $_GET['lsb_scope'] ?? '' );
		$scopes   = $this->network_store->get_scopes();

		if ( $scope_id && isset( $scopes[ $scope_id ] ) ) {
			$scopes_to_export = [ $scope_id => $scopes[ $scope_id ] ];
		} else {
			$scopes_to_export = $scopes;
		}

		$fname = $scope_id ? 'lsb-network-' . $scope_id . '-template.csv' : 'lsb-network-template.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'scope_id', 'slug', 'h1', 'title', 'desc' ], ',' );

		foreach ( $scopes_to_export as $sid => $scope ) {
			$objects = $this->scope_matcher->find_matching_objects( $scope, 500 );
			foreach ( $objects as $obj ) {
				if ( $obj instanceof WP_Post ) {
					$permalink = get_permalink( $obj );
					$slug      = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : $obj->post_name;
				} else {
					$term_link = get_term_link( $obj );
					$slug      = ! is_wp_error( $term_link ) ? wp_parse_url( $term_link, PHP_URL_PATH ) : $obj->slug;
				}
				fputcsv( $out, [ $sid, $slug, '', '', '' ], ',' );
			}
		}

		fclose( $out );
		exit;
	}

	public function export_network_csv() {
		if ( ! current_user_can( 'manage_network_options' ) ) wp_die( 'Forbidden' );
		check_ajax_referer( 'lsb_ajax_nonce', 'nonce' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lsb-network-export.csv"' );
		header( 'Pragma: no-cache' );

		$out   = fopen( 'php://output', 'w' );
		$index = $this->entity_index->get_index();

		fputcsv( $out, [ 'scope_id', 'slug', 'h1', 'title', 'desc' ], ',' );

		foreach ( $index as $scope_id => $rows ) {
			foreach ( $rows as $slug => $_ ) {
				fputcsv( $out, [
					$scope_id,
					$slug,
					$this->network_store->get_entity_value( $scope_id, $slug, 'h1' ) ?: '',
					$this->network_store->get_entity_value( $scope_id, $slug, 'title' ) ?: '',
					$this->network_store->get_entity_value( $scope_id, $slug, 'desc' ) ?: '',
				], ',' );
			}
		}

		fclose( $out );
		exit;
	}

	public function import_network_csv() {
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

			$scope_id  = sanitize_key( trim( $row[0] ) );
			$raw_slug  = trim( $row[1] ?? '' );
			$slug      = str_starts_with( $raw_slug, '/' )
				? sanitize_title( basename( rtrim( $raw_slug, '/' ) ) )
				: sanitize_title( $raw_slug );
			$h1       = wp_kses_post( wp_unslash( trim( $row[2] ?? '' ) ) );
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

	public function import_network_address_csv() {
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
		$rows     = [];

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
			$rows[]   = array_merge( [ 'blog_id' => $blog_id ], $all[ $blog_id ] );
			$imported++;
		}

		fclose( $handle ); // phpcs:ignore
		update_site_option( 'lsb_network_seo_addresses', $all );
		wp_send_json_success( [ 'imported' => $imported, 'skipped' => $skipped, 'rows' => $rows ] );
	}

	public function export_network_address_csv() {
		$all   = get_site_option( 'lsb_network_seo_addresses', [] );
		$sites = get_sites( [ 'number' => 0, 'deleted' => 0 ] );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lsb-network-addresses.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore
		fputcsv( $out, [ 'blog_id', 'ville', 'code_postal', 'adresse', 'departement' ], ',' );
		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;
			$addr    = $all[ $blog_id ] ?? [];
			fputcsv( $out, [
				$blog_id,
				$addr['ville']       ?? '',
				$addr['code_postal'] ?? '',
				$addr['adresse']     ?? '',
				$addr['departement'] ?? '',
			], ',' );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	public function download_network_address_csv_template() {
		if ( ! current_user_can( 'manage_network_options' ) ) wp_die( 'Forbidden' );
		check_ajax_referer( 'lsb_ajax_nonce', 'nonce' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lsb-network-addresses-template.csv"' );
		header( 'Pragma: no-cache' );

		$sites = get_sites( [ 'number' => 0, 'deleted' => 0 ] );
		$out   = fopen( 'php://output', 'w' );

		fputcsv( $out, [ 'blog_id', 'slug', 'ville', 'code_postal', 'adresse', 'departement' ], ',' );

		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;
			$details = get_blog_details( $blog_id );
			$slug    = $details ? sanitize_title( $details->blogname ) : '';
			fputcsv( $out, [ $blog_id, $slug, '', '', '', '' ], ',' );
		}

		fclose( $out );
		exit;
	}

	// ---- Helpers ----

	private function detect_csv_delimiter( $file ) {
		$fh   = fopen( $file, 'r' );
		$line = fgets( $fh );
		fclose( $fh );
		return ( substr_count( $line, ';' ) > substr_count( $line, ',' ) ) ? ';' : ',';
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
}
