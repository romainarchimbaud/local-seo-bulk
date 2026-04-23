<?php
/**
 * Site-level bulk SEO editor page.
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Editor_Page {

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

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$active_tab = isset( $_GET['lsb_tab'] ) ? sanitize_key( $_GET['lsb_tab'] ) : 'h1';
		if ( ! in_array( $active_tab, [ 'h1', 'title', 'desc' ], true ) ) $active_tab = 'h1';

		// Parse combined object selector: "scope|{id}", "post_type|{slug}", "taxonomy|{slug}"
		$raw_object  = isset( $_GET['lsb_object'] ) ? sanitize_text_field( wp_unslash( $_GET['lsb_object'] ) ) : '';
		$obj_parts   = explode( '|', $raw_object, 2 );
		$active_kind = isset( $obj_parts[0] ) ? $obj_parts[0] : '';
		$active_val  = isset( $obj_parts[1] ) ? sanitize_key( $obj_parts[1] ) : '';

		// Build select option groups
		$scopes     = $this->network_store->get_scopes();
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

		// Always exclude attachment
		unset( $post_types['attachment'] );

		// Filter by saved settings (if never saved, show all)
		$editor_types = get_option( 'lsb_editor_types', false );
		if ( $editor_types !== false ) {
			$enabled_scopes = $editor_types['scopes']     ?? array_keys( $scopes );
			$enabled_pt     = $editor_types['post_types'] ?? [];
			$enabled_tax    = $editor_types['taxonomies'] ?? [];
			$scopes     = array_filter( $scopes, function( $scope, $sid ) use ( $enabled_scopes ) {
				return in_array( $sid, $enabled_scopes, true );
			}, ARRAY_FILTER_USE_BOTH );
			$post_types = array_filter( $post_types, function( $pt ) use ( $enabled_pt ) {
				return in_array( $pt->name, $enabled_pt, true );
			} );
			$taxonomies = array_filter( $taxonomies, function( $tax ) use ( $enabled_tax ) {
				return in_array( $tax->name, $enabled_tax, true );
			} );
		}

		$active_object_value = $active_kind && $active_val ? $active_kind . '|' . $active_val : '';
		$search = isset( $_GET['lsb_search'] ) ? sanitize_text_field( wp_unslash( $_GET['lsb_search'] ) ) : '';

		$entities = $this->load_entities( $active_kind, $active_val, $scopes, $active_tab, $search );

		$table = new LSB_List_Table( $active_tab, $this->meta_store, $this->token_resolver, $this->resolver, '' );
		$table->set_entities( $entities );
		$table->prepare_items();

		$base_url = admin_url( 'admin.php?page=lsb-editor' );

		$field_tabs = [ 'h1' => 'H1', 'title' => 'Meta title', 'desc' => 'Meta description' ];
		?>
		<div class="wrap lsb-editor-wrap">

			<!-- Standard WP title: notices are repositioned after this h1 by WP JS -->
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Éditeur SEO en masse', 'local-seo-bulk' ); ?></h1>
			<hr class="wp-header-end">

			<?php
			$lsb_addr        = get_option( 'lsb_address', [] );
			$lsb_addr_missing = empty( $lsb_addr['ville'] ) && empty( $lsb_addr['code_postal'] );
			if ( $lsb_addr_missing ) : ?>
			<div class="notice notice-warning">
				<p><?php printf(
					wp_kses( __( 'Veuillez terminer la configuration de SEO Masse : renseignez l\'adresse du magasin dans <a href="%s">Réglages</a> pour activer les variables <code>%%lsb_ville%%</code>, <code>%%lsb_code_postal%%</code> et <code>%%lsb_adresse%%</code>.', 'local-seo-bulk' ), [ 'a' => [ 'href' => [] ], 'code' => [] ] ),
					esc_url( admin_url( 'admin.php?page=lsb-settings' ) )
				); ?></p>
			</div>
			<?php endif; ?>

			<div <?php if ( $lsb_addr_missing ) echo 'style="opacity:.4;pointer-events:none;user-select:none"'; ?>>

			<!-- Toolbar: type select + field tabs left, save-all right -->
			<div class="lsb-toolbar">
				<form method="get" action="">
					<input type="hidden" name="page" value="lsb-editor">

					<label for="lsb-object-select"><?php esc_html_e( 'Type :', 'local-seo-bulk' ); ?></label>
					<select id="lsb-object-select" name="lsb_object" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'Sélectionner votre type…', 'local-seo-bulk' ); ?></option>

						<?php if ( ! empty( $scopes ) ) : ?>
							<optgroup label="<?php esc_attr_e( 'Scopes réseau', 'local-seo-bulk' ); ?>">
								<?php foreach ( $scopes as $sid => $scope ) : ?>
									<option value="<?php echo esc_attr( 'scope|' . $sid ); ?>"
										<?php selected( $active_object_value, 'scope|' . $sid ); ?>>
										<?php echo esc_html( $scope['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>

						<?php if ( ! empty( $post_types ) ) : ?>
							<optgroup label="<?php esc_attr_e( 'Types de contenus', 'local-seo-bulk' ); ?>">
								<?php foreach ( $post_types as $pt ) : ?>
									<option value="<?php echo esc_attr( 'post_type|' . $pt->name ); ?>"
										<?php selected( $active_object_value, 'post_type|' . $pt->name ); ?>>
										<?php echo esc_html( $pt->labels->name ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>

						<?php if ( ! empty( $taxonomies ) ) : ?>
							<optgroup label="<?php esc_attr_e( 'Taxonomies', 'local-seo-bulk' ); ?>">
								<?php foreach ( $taxonomies as $tax ) : ?>
									<option value="<?php echo esc_attr( 'taxonomy|' . $tax->name ); ?>"
										<?php selected( $active_object_value, 'taxonomy|' . $tax->name ); ?>>
										<?php echo esc_html( $tax->labels->name ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>

					</select>

					<input type="hidden" name="lsb_tab" value="<?php echo esc_attr( $active_tab ); ?>">
					<input type="search" name="lsb_search" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'Rechercher…', 'local-seo-bulk' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Filtrer', 'local-seo-bulk' ); ?></button>
					<?php if ( $search ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'lsb_object' => $active_object_value, 'lsb_tab' => $active_tab ], $base_url ) ); ?>" class="button"><?php esc_html_e( 'Réinitialiser', 'local-seo-bulk' ); ?></a>
					<?php endif; ?>
				</form>

				<div class="lsb-bulk-actions">
					<button type="button" class="button button-primary" id="lsb-save-all"><?php esc_html_e( 'Tout enregistrer', 'local-seo-bulk' ); ?></button>
					<span class="lsb-dirty-count" id="lsb-dirty-count"></span>
					<?php if ( $active_object_value ) : ?>
					<button type="button" class="button" id="lsb-open-import"><?php esc_html_e( 'Importer CSV', 'local-seo-bulk' ); ?></button>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $active_object_value ) : ?>
			<div id="lsb-import-dialog" style="display:none;margin-top:1em;padding:1em;border:1px solid #ccd0d4;background:#fff">
				<h3 style="margin-top:0"><?php esc_html_e( 'Importer des valeurs depuis un CSV', 'local-seo-bulk' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Colonnes attendues : slug, h1, title, desc. Les colonnes vides sont ignorées.', 'local-seo-bulk' ); ?></p>
				<p>
					<a href="<?php echo esc_url( add_query_arg( [
						'action'     => 'lsb_csv_template',
						'lsb_object' => $active_object_value,
						'nonce'      => wp_create_nonce( 'lsb_ajax_nonce' ),
					], admin_url( 'admin-ajax.php' ) ) ); ?>" class="button">
						<?php esc_html_e( 'Télécharger le modèle CSV', 'local-seo-bulk' ); ?>
					</a>
				</p>
				<input type="file" id="lsb-csv-file" accept=".csv" style="display:block;margin-bottom:.5em">
				<input type="hidden" id="lsb-import-object" value="<?php echo esc_attr( $active_object_value ); ?>">
				<input type="hidden" id="lsb-import-nonce" value="<?php echo esc_attr( wp_create_nonce( 'lsb_ajax_nonce' ) ); ?>">
				<button type="button" class="button button-primary" id="lsb-do-import"><?php esc_html_e( 'Importer', 'local-seo-bulk' ); ?></button>
				<button type="button" class="button" id="lsb-close-import"><?php esc_html_e( 'Annuler', 'local-seo-bulk' ); ?></button>
				<p id="lsb-import-result" style="margin-top:.5em"></p>
			</div>
			<?php endif; ?>

			<form id="lsb-editor-form" method="post">
				<?php wp_nonce_field( 'lsb_ajax_nonce', 'lsb_nonce', true ); ?>

				<!-- Tabs + pagination on same line -->
				<div class="lsb-tabs-bar">
					<nav class="nav-tab-wrapper lsb-tab-header">
						<?php foreach ( $field_tabs as $fk => $fl ) : ?>
							<a href="<?php echo esc_url( add_query_arg( [ 'lsb_object' => $active_object_value, 'lsb_tab' => $fk ], $base_url ) ); ?>"
							   class="nav-tab lsb-field-tab <?php echo $fk === $active_tab ? 'nav-tab-active' : ''; ?>"
							   data-field="<?php echo esc_attr( $fk ); ?>">
								<?php echo esc_html( $fl ); ?>
							</a>
						<?php endforeach; ?>
					</nav>
					<?php $table->render_top_nav(); ?>
				</div>

				<?php $table->display(); ?>
			</form>
			</div><!-- end overlay wrapper -->

			<?php if ( $active_object_value ) : ?>
			<script>
			( function( $ ) {
				$( '#lsb-open-import' ).on( 'click', function() {
					$( '#lsb-import-dialog' ).toggle();
				} );
				$( '#lsb-close-import' ).on( 'click', function() {
					$( '#lsb-import-dialog' ).hide();
				} );
				$( '#lsb-do-import' ).on( 'click', function() {
					var file = $( '#lsb-csv-file' )[0].files[0];
					if ( ! file ) { alert( <?php echo json_encode( __( 'Veuillez sélectionner un fichier CSV.', 'local-seo-bulk' ) ); ?> ); return; }
					var fd = new FormData();
					fd.append( 'action', 'lsb_import_csv' );
					fd.append( 'nonce', $( '#lsb-import-nonce' ).val() );
					fd.append( 'lsb_object', $( '#lsb-import-object' ).val() );
					fd.append( 'lsb_csv', file );
					$( '#lsb-do-import' ).prop( 'disabled', true );
					$.ajax( {
						url:         lsbData.ajaxUrl,
						type:        'POST',
						data:        fd,
						processData: false,
						contentType: false,
					} ).done( function( resp ) {
						if ( resp.success ) {
							$( '#lsb-import-result' ).text(
								<?php echo json_encode( __( 'Importé : ', 'local-seo-bulk' ) ); ?> + resp.data.imported +
								<?php echo json_encode( __( ' — Ignoré : ', 'local-seo-bulk' ) ); ?> + resp.data.skipped
							);
						} else {
							$( '#lsb-import-result' ).text( resp.data.message || <?php echo json_encode( __( 'Erreur.', 'local-seo-bulk' ) ); ?> );
						}
					} ).fail( function() {
						$( '#lsb-import-result' ).text( <?php echo json_encode( __( 'Erreur réseau.', 'local-seo-bulk' ) ); ?> );
					} ).always( function() {
						$( '#lsb-do-import' ).prop( 'disabled', false );
					} );
				} );
			} )( jQuery );
			</script>
			<?php endif; ?>
		</div>
		<?php
	}

	private function load_entities( $kind, $val, $scopes, $active_tab, $search ) {
		if ( ! $kind || ! $val ) return [];

		if ( 'scope' === $kind ) {
			$scope = $scopes[ $val ] ?? null;
			if ( ! $scope ) return [];
			$objects = $this->scope_matcher->find_matching_objects( $scope, 500 );
			return $this->build_entities_from_objects( $objects, $active_tab, $search, $val );
		}

		if ( 'post_type' === $kind ) {
			$objects = get_posts( [
				'post_type'      => $val,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			] );
			return $this->build_entities_from_objects( $objects, $active_tab, $search );
		}

		if ( 'taxonomy' === $kind ) {
			$objects = get_terms( [
				'taxonomy'   => $val,
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'name',
				'order'      => 'ASC',
			] );
			if ( is_wp_error( $objects ) ) return [];
			return $this->build_entities_from_objects( $objects, $active_tab, $search );
		}

		return [];
	}

	/**
	 * @param array       $objects    WP_Post|WP_Term list
	 * @param string      $active_tab h1|title|desc
	 * @param string      $search     filter string
	 * @param string|null $forced_scope_id  when already known (scope mode)
	 */
	private function build_entities_from_objects( array $objects, $active_tab, $search, $forced_scope_id = null ) {
		$entities = [];

		foreach ( $objects as $obj ) {
			$title = $obj instanceof WP_Post ? get_the_title( $obj ) : $obj->name;
			if ( $search && stripos( $title, $search ) === false ) continue;

			if ( null !== $forced_scope_id ) {
				$scope_id = $forced_scope_id;
			} else {
				$scope    = $this->scope_matcher->get_scope_for_object( $obj );
				$scope_id = $scope ? $scope['id'] : '';
			}

			$obj_slug = $this->scope_matcher->get_object_slug( $obj );
			$net_raw  = $scope_id
				? $this->resolver->resolve_network_raw( $scope_id, $obj_slug, $active_tab )
				: [ 'raw' => '', 'tier' => 0 ];

			if ( $obj instanceof WP_Post ) {
				$entity   = [ 'type' => 'post', 'id' => $obj->ID ];
				$type_obj = get_post_type_object( $obj->post_type );
				$type_lbl = $type_obj ? $type_obj->labels->singular_name : $obj->post_type;
				$url      = get_permalink( $obj );
				$edit_url = get_edit_post_link( $obj->ID, 'raw' );
				$current  = $this->get_post_current_value( $obj->ID, $active_tab );
			} else {
				$entity   = [ 'type' => 'term', 'id' => $obj->term_id ];
				$tax_obj  = get_taxonomy( $obj->taxonomy );
				$type_lbl = $tax_obj ? $tax_obj->labels->singular_name : $obj->taxonomy;
				$url      = get_term_link( $obj );
				$edit_url = get_edit_term_link( $obj->term_id, $obj->taxonomy );
				$current  = $this->get_term_current_value( $obj->term_id, $active_tab );
			}

			$entities[] = [
				'entity'          => $entity,
				'title'           => $title,
				'url'             => is_wp_error( $url ) ? '' : $url,
				'edit_url'        => $edit_url ?: '',
				'type_label'      => $type_lbl,
				'current_value'   => $current,
				'slug'            => $obj_slug,
				'scope_id'        => $scope_id,
				'network_pattern' => $net_raw['raw'],
				'network_tier'    => $net_raw['tier'],
			];
		}

		return $entities;
	}

	private function get_post_current_value( $post_id, $active_tab ) {
		switch ( $active_tab ) {
			case 'title': return get_post_meta( $post_id, '_yoast_wpseo_title', true ) ?: get_the_title( $post_id );
			case 'desc':  return get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ?: '';
			default:      return get_the_title( $post_id );
		}
	}

	private function get_term_current_value( $term_id, $active_tab ) {
		switch ( $active_tab ) {
			case 'title': return get_term_meta( $term_id, 'wpseo_title', true ) ?: '';
			case 'desc':  return get_term_meta( $term_id, 'wpseo_desc', true ) ?: '';
			default:
				$term = get_term( $term_id );
				return $term ? $term->name : '';
		}
	}
}
