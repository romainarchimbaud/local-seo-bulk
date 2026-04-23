<?php
/**
 * Network admin page: editor — per-slug network entity values.
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Network_Editor_Page {

	const PAGE_SLUG = 'lsb-network-editor';

	private $store;
	private $entity_index;
	private $token_resolver;

	public function __construct(
		LSB_Network_Store $store,
		LSB_Network_Entity_Index $entity_index,
		LSB_Token_Resolver $token_resolver
	) {
		$this->store          = $store;
		$this->entity_index   = $entity_index;
		$this->token_resolver = $token_resolver;
	}

	public function init() {
		add_action( 'network_admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu() {
		add_submenu_page(
			LSB_Network_Scope_Page::PAGE_SLUG,
			__( 'Éditeur réseau', 'local-seo-bulk' ),
			__( 'Éditeur', 'local-seo-bulk' ),
			'manage_network_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) return;

		$scopes = $this->store->get_scopes();

		if ( empty( $scopes ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Éditeur réseau', 'local-seo-bulk' ) . '</h1>';
			echo '<p>' . wp_kses_post( sprintf(
				/* translators: link to scopes page */
				__( 'Aucun scope configuré. <a href="%s">Créer un scope</a> d\'abord.', 'local-seo-bulk' ),
				esc_url( network_admin_url( 'admin.php?page=' . LSB_Network_Scope_Page::PAGE_SLUG . '&action=add' ) )
			) ) . '</p></div>';
			return;
		}

		$active_scope = isset( $_GET['lsb_scope'] ) ? sanitize_key( $_GET['lsb_scope'] ) : '';
		if ( ! $active_scope || ! isset( $scopes[ $active_scope ] ) ) {
			reset( $scopes );
			$active_scope = key( $scopes );
		}
		$active_field = isset( $_GET['lsb_tab'] ) ? sanitize_key( $_GET['lsb_tab'] ) : 'h1';
		if ( ! in_array( $active_field, [ 'h1', 'title', 'desc' ], true ) ) $active_field = 'h1';

		$index    = $this->entity_index->get_index();
		$base_url = network_admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		wp_enqueue_script( 'lsb-admin' );
		wp_enqueue_style( 'lsb-admin' );
		?>
		<div class="wrap lsb-editor-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Éditeur SEO réseau', 'local-seo-bulk' ); ?>
				<a href="<?php echo esc_url( add_query_arg( [ 'action' => 'lsb_refresh_entity_index', '_wpnonce' => wp_create_nonce( 'lsb_refresh_entity_index' ) ], admin_url( 'admin-post.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Rafraîchir l\'index', 'local-seo-bulk' ); ?></a>
				<button type="button" class="page-title-action" id="lsb-open-network-import"><?php esc_html_e( 'Importer CSV', 'local-seo-bulk' ); ?></button>
			</h1>
			<hr class="wp-header-end">

			<div id="lsb-network-import-dialog" style="display:none;margin:1em 0;padding:1em;border:1px solid #ccd0d4;background:#fff">
				<h3 style="margin-top:0"><?php esc_html_e( 'Importer des valeurs réseau depuis un CSV', 'local-seo-bulk' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Colonnes attendues : scope_id, slug, h1, title, desc. Les colonnes vides sont ignorées.', 'local-seo-bulk' ); ?></p>
				<p>
					<a href="<?php echo esc_url( add_query_arg( [
						'action' => 'lsb_network_csv_template',
						'nonce'  => wp_create_nonce( 'lsb_ajax_nonce' ),
					], admin_url( 'admin-ajax.php' ) ) ); ?>" class="button">
						<?php esc_html_e( 'Télécharger le modèle CSV', 'local-seo-bulk' ); ?>
					</a>
				</p>
				<input type="file" id="lsb-network-csv-file" accept=".csv" style="display:block;margin-bottom:.5em">
				<input type="hidden" id="lsb-network-import-nonce" value="<?php echo esc_attr( wp_create_nonce( 'lsb_ajax_nonce' ) ); ?>">
				<button type="button" class="button button-primary" id="lsb-do-network-import"><?php esc_html_e( 'Importer', 'local-seo-bulk' ); ?></button>
				<button type="button" class="button" id="lsb-close-network-import"><?php esc_html_e( 'Annuler', 'local-seo-bulk' ); ?></button>
				<p id="lsb-network-import-result" style="margin-top:.5em"></p>
			</div>

			<script>
			( function( $ ) {
				$( '#lsb-open-network-import' ).on( 'click', function() {
					$( '#lsb-network-import-dialog' ).toggle();
				} );
				$( '#lsb-close-network-import' ).on( 'click', function() {
					$( '#lsb-network-import-dialog' ).hide();
				} );
				$( '#lsb-do-network-import' ).on( 'click', function() {
					var file = $( '#lsb-network-csv-file' )[0].files[0];
					if ( ! file ) { alert( <?php echo json_encode( __( 'Veuillez sélectionner un fichier CSV.', 'local-seo-bulk' ) ); ?> ); return; }
					var fd = new FormData();
					fd.append( 'action', 'lsb_import_network_csv' );
					fd.append( 'nonce', $( '#lsb-network-import-nonce' ).val() );
					fd.append( 'lsb_csv', file );
					$( '#lsb-do-network-import' ).prop( 'disabled', true );
					$.ajax( {
						url:         <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
						type:        'POST',
						data:        fd,
						processData: false,
						contentType: false,
					} ).done( function( resp ) {
						if ( resp.success ) {
							$( '#lsb-network-import-result' ).text(
								<?php echo json_encode( __( 'Importé : ', 'local-seo-bulk' ) ); ?> + resp.data.imported +
								<?php echo json_encode( __( ' — Ignoré : ', 'local-seo-bulk' ) ); ?> + resp.data.skipped
							);
						} else {
							$( '#lsb-network-import-result' ).text( resp.data.message || <?php echo json_encode( __( 'Erreur.', 'local-seo-bulk' ) ); ?> );
						}
					} ).fail( function() {
						$( '#lsb-network-import-result' ).text( <?php echo json_encode( __( 'Erreur réseau.', 'local-seo-bulk' ) ); ?> );
					} ).always( function() {
						$( '#lsb-do-network-import' ).prop( 'disabled', false );
					} );
				} );
			} )( jQuery );
			</script>

			<?php if ( isset( $_GET['index_refreshed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Index des entités rafraîchi.', 'local-seo-bulk' ); ?></p></div>
			<?php endif; ?>

			<!-- Scope tabs bar: tabs left, save button right -->
			<div class="lsb-tabs-bar lsb-scope-tabs-bar">
				<nav class="nav-tab-wrapper lsb-tab-header">
					<?php foreach ( $scopes as $scope_id => $scope ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'lsb_scope' => $scope_id, 'lsb_tab' => $active_field ], $base_url ) ); ?>"
						   class="nav-tab <?php echo $scope_id === $active_scope ? 'nav-tab-active' : ''; ?>">
							<?php echo esc_html( $scope['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>
				<div class="lsb-scope-actions">
					<button type="button" class="button button-primary" id="lsb-save-all"><?php esc_html_e( 'Tout enregistrer', 'local-seo-bulk' ); ?></button>
					<span class="lsb-dirty-count" id="lsb-dirty-count"></span>
				</div>
			</div>

			<?php
			$rows         = isset( $index[ $active_scope ] ) ? $index[ $active_scope ] : [];
			$scope_config = $scopes[ $active_scope ];
			$field_labels = [ 'h1' => 'H1', 'title' => 'Meta title', 'desc' => 'Meta description' ];
			?>

			<form id="lsb-editor-form" method="post">
				<?php wp_nonce_field( 'lsb_ajax_nonce', 'lsb_nonce' ); ?>

				<!-- Field tabs bar flush with table -->
				<div class="lsb-tabs-bar">
					<nav class="nav-tab-wrapper lsb-tab-header">
						<?php foreach ( $field_labels as $fk => $fl ) : ?>
							<a href="<?php echo esc_url( add_query_arg( [ 'lsb_scope' => $active_scope, 'lsb_tab' => $fk ], $base_url ) ); ?>"
							   class="nav-tab lsb-field-tab <?php echo $fk === $active_field ? 'nav-tab-active' : ''; ?>"
							   data-field="<?php echo esc_attr( $fk ); ?>">
								<?php echo esc_html( $fl ); ?>
							</a>
						<?php endforeach; ?>
					</nav>
					<div class="lsb-field-meta">
						<?php echo esc_html( sprintf( _n( '%d entité', '%d entités', count( $rows ), 'local-seo-bulk' ), count( $rows ) ) ); ?>
					</div>
				</div>

				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Titre (exemple)', 'local-seo-bulk' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'local-seo-bulk' ); ?></th>
							<th style="width:60px"><?php esc_html_e( 'Sites', 'local-seo-bulk' ); ?></th>
							<th style="min-width:480px"><?php esc_html_e( 'Valeur réseau', 'local-seo-bulk' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'local-seo-bulk' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Aucune entité dans l\'index. Cliquez sur « Rafraîchir l\'index ».', 'local-seo-bulk' ); ?></td></tr>
					<?php else : foreach ( $rows as $slug => $row ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $row['sample_title'] ); ?></strong>
							</td>
							<td>
								<code style="font-size:.85em;color:#666"><?php echo esc_html( $slug ); ?></code>
							</td>
							<td style="color:#999;font-size:.85em;white-space:nowrap">
								<?php echo count( $row['sites'] ); ?> sites
							</td>
							<td>
								<?php foreach ( $field_labels as $fk => $fl ) :
									$fval     = $this->store->get_entity_value( $active_scope, $slug, $fk );
									$input_id = 'lsb-net-' . esc_attr( $active_scope ) . '-' . esc_attr( $slug ) . '-' . esc_attr( $fk );
									$hidden   = $fk !== $active_field ? ' style="display:none"' : '';
								?>
									<div class="lsb-field-panel" data-field="<?php echo esc_attr( $fk ); ?>"<?php echo $hidden; ?>>
										<?php if ( 'desc' === $fk ) : ?>
											<textarea id="<?php echo esc_attr( $input_id ); ?>"
												class="lsb-value-input lsb-network-input"
												rows="2"
												style="width:100%"
												data-scope="<?php echo esc_attr( $active_scope ); ?>"
												data-slug="<?php echo esc_attr( $slug ); ?>"
												data-field="<?php echo esc_attr( $fk ); ?>"><?php echo esc_textarea( $fval ); ?></textarea>
										<?php else : ?>
											<input type="text"
												id="<?php echo esc_attr( $input_id ); ?>"
												class="lsb-value-input lsb-network-input"
												style="width:100%"
												value="<?php echo esc_attr( $fval ); ?>"
												data-scope="<?php echo esc_attr( $active_scope ); ?>"
												data-slug="<?php echo esc_attr( $slug ); ?>"
												data-field="<?php echo esc_attr( $fk ); ?>">
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</td>
							<td>
								<div class="lsb-actions-wrap">
									<button type="button" class="button lsb-save-network-row"
										data-scope="<?php echo esc_attr( $active_scope ); ?>"
										data-slug="<?php echo esc_attr( $slug ); ?>">
										<?php esc_html_e( 'Enregistrer', 'local-seo-bulk' ); ?>
									</button>
									<button type="button" class="button-link lsb-clear-network-row"
										data-scope="<?php echo esc_attr( $active_scope ); ?>"
										data-slug="<?php echo esc_attr( $slug ); ?>"
										data-field="<?php echo esc_attr( $active_field ); ?>">
										<?php esc_html_e( 'Vider', 'local-seo-bulk' ); ?>
									</button>
									<span class="lsb-row-status"></span>
								</div>
							</td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</form>
		</div>
		<?php
	}
}
