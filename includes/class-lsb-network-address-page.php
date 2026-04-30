<?php
/**
 * Network admin page: SEO addresses per site.
 *
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Network_Address_Page {

	const PAGE_SLUG = 'lsb-network-addresses';

	public function init() {
		add_action( 'network_admin_menu', [ $this, 'register_menu' ] );
		add_filter( 'set_screen_option_lsb_items_per_page', [ $this, 'save_screen_option' ], 10, 3 );
	}

	public function register_menu() {
		$hook = add_submenu_page(
			LSB_Network_Scope_Page::PAGE_SLUG,
			__( 'Adresses SEO', 'local-seo-bulk' ),
			__( 'Adresses SEO', 'local-seo-bulk' ),
			'manage_network_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
		add_action( 'load-' . $hook, [ $this, 'add_screen_options' ] );
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

	public function render_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) return;

		$all_addresses = get_site_option( 'lsb_network_seo_addresses', [] );
		$all_sites     = get_sites( [ 'number' => 0, 'deleted' => 0 ] );
		$acf_field     = get_site_option( 'lsb_network_address_acf_field', 'adresse' );

		$per_page     = (int) get_user_meta( get_current_user_id(), 'lsb_items_per_page', true );
		if ( $per_page < 1 ) $per_page = 50;
		$total_rows   = count( $all_sites );
		$total_pages  = max( 1, (int) ceil( $total_rows / $per_page ) );
		$current_page = max( 1, min( $total_pages, (int) wp_unslash( $_GET['paged'] ?? 1 ) ) );
		$sites_page   = array_slice( $all_sites, ( $current_page - 1 ) * $per_page, $per_page );

		$export_url = add_query_arg( [
			'action' => 'lsb_export_network_address_csv',
			'nonce'  => wp_create_nonce( 'lsb_ajax_nonce' ),
		], admin_url( 'admin-ajax.php' ) );

		$template_url = add_query_arg( [
			'action' => 'lsb_network_address_csv_template',
			'nonce'  => wp_create_nonce( 'lsb_ajax_nonce' ),
		], admin_url( 'admin-ajax.php' ) );

		wp_enqueue_script( 'lsb-admin' );
		wp_enqueue_style( 'lsb-admin' );
		?>
		<div class="wrap lsb-editor-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Adresses SEO réseau', 'local-seo-bulk' ); ?></h1>
			<hr class="wp-header-end">

			<!-- Import dialog -->
			<div id="lsb-address-import-dialog" style="display:none;margin:1em 0;padding:1em;border:1px solid #ccd0d4;background:#fff">
				<h3 style="margin-top:0"><?php esc_html_e( 'Importer des adresses SEO depuis un CSV', 'local-seo-bulk' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Colonnes : blog_id, ville, code_postal, adresse, departement. Délimiteur : point-virgule.', 'local-seo-bulk' ); ?>
					<a href="<?php echo esc_url( $template_url ); ?>"><?php esc_html_e( 'Télécharger le modèle CSV', 'local-seo-bulk' ); ?></a>
				</p>
				<input type="file" id="lsb-address-csv-file" accept=".csv" style="display:block;margin:1.5em 0.5em">
				<input type="hidden" id="lsb-address-import-nonce" value="<?php echo esc_attr( wp_create_nonce( 'lsb_ajax_nonce' ) ); ?>">
				<button type="button" class="button button-primary" id="lsb-do-address-import"><?php esc_html_e( 'Importer', 'local-seo-bulk' ); ?></button>
				<button type="button" class="button" id="lsb-close-address-import"><?php esc_html_e( 'Annuler', 'local-seo-bulk' ); ?></button>
				<p id="lsb-address-import-result" style="margin-top:.5em"></p>
			</div>

			<!-- ACF prefill panel -->
			<div id="lsb-address-prefill-panel" style="display:none;margin:1em 0;padding:1em;border:1px solid #ccd0d4;background:#fff">
				<h3 style="margin-top:0"><?php esc_html_e( 'Pré-remplir depuis ACF', 'local-seo-bulk' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Remplit les entrées vides depuis le champ ACF Google Maps de chaque site. Département non inclus (non présent dans Google Maps).', 'local-seo-bulk' ); ?></p>
				<label><?php esc_html_e( 'Nom du champ ACF :', 'local-seo-bulk' ); ?>
					<input type="text" id="lsb-acf-field-name" value="<?php echo esc_attr( $acf_field ); ?>" class="regular-text" style="margin-left:.5em">
				</label>
				<br><br>
				<input type="hidden" id="lsb-address-prefill-nonce" value="<?php echo esc_attr( wp_create_nonce( 'lsb_ajax_nonce' ) ); ?>">
				<button type="button" class="button button-primary" id="lsb-do-address-prefill"><?php esc_html_e( 'Pré-remplir', 'local-seo-bulk' ); ?></button>
				<button type="button" class="button" id="lsb-close-address-prefill"><?php esc_html_e( 'Fermer', 'local-seo-bulk' ); ?></button>
				<p id="lsb-address-prefill-result" style="margin-top:.5em"></p>
			</div>

			<!-- Toolbar -->
			<div class="lsb-tabs-bar lsb-scope-tabs-bar">
				<div class="alignleft actions bulkactions lsb-bulk-bar">
					<label for="lsb-address-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Actions groupées', 'local-seo-bulk' ); ?></label>
					<select id="lsb-address-bulk-action">
						<option value="-1"><?php esc_html_e( 'Actions groupées', 'local-seo-bulk' ); ?></option>
						<option value="lsb_address_bulk_clear"><?php esc_html_e( 'Vider', 'local-seo-bulk' ); ?></option>
					</select>
					<input type="button" class="button action" id="lsb-address-bulk-apply" value="<?php esc_attr_e( 'Appliquer', 'local-seo-bulk' ); ?>">
				</div>
				<div class="lsb-scope-actions">
					<button type="button" class="button" id="lsb-open-address-import"><?php esc_html_e( 'Importer CSV', 'local-seo-bulk' ); ?></button>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'Exporter CSV', 'local-seo-bulk' ); ?></a>
					<a href="<?php echo esc_url( $template_url ); ?>" class="button"><?php esc_html_e( 'Télécharger le modèle', 'local-seo-bulk' ); ?></a>
					<button type="button" class="button" id="lsb-open-address-prefill"><?php esc_html_e( 'Pré-remplir ACF', 'local-seo-bulk' ); ?></button>
					<button type="button" class="button button-primary" id="lsb-save-all"><?php esc_html_e( 'Tout enregistrer', 'local-seo-bulk' ); ?></button>
					<span class="lsb-dirty-count" id="lsb-dirty-count"></span>
				</div>
			</div>

			<!-- Pagination bar -->
			<div class="lsb-tabs-bar">
				<div></div>
				<div></div>
				<div class="tablenav top">
					<?php $this->render_pagination( $current_page, $total_pages, $total_rows ); ?>
					<br class="clear">
				</div>
			</div>

			<form id="lsb-address-form" method="post">
				<?php wp_nonce_field( 'lsb_ajax_nonce', 'lsb_nonce' ); ?>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<td id="cb" class="manage-column column-cb check-column">
								<input id="cb-select-all-address" type="checkbox">
							</td>
							<th><?php esc_html_e( 'Site', 'local-seo-bulk' ); ?></th>
							<th style="min-width:120px"><?php esc_html_e( 'Ville SEO', 'local-seo-bulk' ); ?></th>
							<th style="width:8em"><?php esc_html_e( 'Code Postal', 'local-seo-bulk' ); ?></th>
							<th style="min-width:180px"><?php esc_html_e( 'Adresse', 'local-seo-bulk' ); ?></th>
							<th style="min-width:120px"><?php esc_html_e( 'Département', 'local-seo-bulk' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'local-seo-bulk' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sites_page as $site ) :
							$blog_id      = (int) $site->blog_id;
							$addr         = $all_addresses[ $blog_id ] ?? [];
							$site_details = get_blog_details( $blog_id );
							?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" class="lsb-address-cb" value="<?php echo esc_attr( $blog_id ); ?>">
								</th>
								<td>
									<strong><?php echo esc_html( $site_details->blogname ); ?></strong>
									<span style="color:#999;font-size:.85em;display:block"><?php echo esc_html( $site_details->siteurl ); ?></span>
								</td>
								<td>
									<input type="text" class="lsb-value-input lsb-address-input" style="width:100%"
										data-blog-id="<?php echo esc_attr( $blog_id ); ?>" data-field="ville"
										value="<?php echo esc_attr( $addr['ville'] ?? '' ); ?>">
								</td>
								<td>
									<input type="text" class="lsb-value-input lsb-address-input" style="width:100%"
										data-blog-id="<?php echo esc_attr( $blog_id ); ?>" data-field="code_postal"
										value="<?php echo esc_attr( $addr['code_postal'] ?? '' ); ?>">
								</td>
								<td>
									<input type="text" class="lsb-value-input lsb-address-input" style="width:100%"
										data-blog-id="<?php echo esc_attr( $blog_id ); ?>" data-field="adresse"
										value="<?php echo esc_attr( $addr['adresse'] ?? '' ); ?>">
								</td>
								<td>
									<input type="text" class="lsb-value-input lsb-address-input" style="width:100%"
										data-blog-id="<?php echo esc_attr( $blog_id ); ?>" data-field="departement"
										value="<?php echo esc_attr( $addr['departement'] ?? '' ); ?>">
								</td>
								<td>
									<div class="lsb-actions-wrap">
										<button type="button" class="button lsb-save-address-row"
											data-blog-id="<?php echo esc_attr( $blog_id ); ?>">
											<?php esc_html_e( 'Enregistrer', 'local-seo-bulk' ); ?>
										</button>
										<button type="button" class="button-link lsb-clear-address-row"
											data-blog-id="<?php echo esc_attr( $blog_id ); ?>">
											<?php esc_html_e( 'Vider', 'local-seo-bulk' ); ?>
										</button>
										<span class="lsb-row-status"></span>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</form>

			<!-- Import / prefill inline JS -->
			<script>
			(function($) {
				$(function() {
					// Import dialog (closes prefill panel if open)
					$('#lsb-open-address-import').on('click', function() {
						$('#lsb-address-prefill-panel').hide();
						$('#lsb-address-import-dialog').toggle();
					});
					$('#lsb-close-address-import').on('click', function() { $('#lsb-address-import-dialog').hide(); });
					$('#lsb-do-address-import').on('click', function() {
						var file = $('#lsb-address-csv-file')[0].files[0];
						if (!file) { alert(<?php echo json_encode( __( 'Sélectionnez un fichier CSV.', 'local-seo-bulk' ) ); ?>); return; }
						var fd = new FormData();
						fd.append('action', 'lsb_import_network_address_csv');
						fd.append('nonce', $('#lsb-address-import-nonce').val());
						fd.append('lsb_csv', file);
						$(this).prop('disabled', true);
						$.ajax({ url: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>, type: 'POST', data: fd, processData: false, contentType: false })
							.done(function(r) {
								if (r.success) {
									$('#lsb-address-import-result').text(<?php echo json_encode( __( 'Importé : ', 'local-seo-bulk' ) ); ?> + r.data.imported + <?php echo json_encode( ' — Ignoré : ' ); ?> + r.data.skipped);
									setTimeout(function() { location.reload(); }, 800);
								} else {
									$('#lsb-address-import-result').text(r.data.message || <?php echo json_encode( __( 'Erreur.', 'local-seo-bulk' ) ); ?>);
								}
							})
							.fail(function() { $('#lsb-address-import-result').text(<?php echo json_encode( __( 'Erreur réseau.', 'local-seo-bulk' ) ); ?>); })
							.always(function() { $('#lsb-do-address-import').prop('disabled', false); });
					});

					// Prefill panel (closes import dialog if open)
					$('#lsb-open-address-prefill').on('click', function() {
						$('#lsb-address-import-dialog').hide();
						$('#lsb-address-prefill-panel').toggle();
					});
					$('#lsb-close-address-prefill').on('click', function() { $('#lsb-address-prefill-panel').hide(); });
					$('#lsb-do-address-prefill').on('click', function() {
						if (!confirm(<?php echo json_encode( __( 'Pré-remplir les entrées vides depuis ACF ? Les entrées existantes ne seront pas modifiées.', 'local-seo-bulk' ) ); ?>)) return;
						$(this).prop('disabled', true);
						$.post(<?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
							action: 'lsb_prefill_network_addresses',
							nonce: $('#lsb-address-prefill-nonce').val(),
							acf_field: $('#lsb-acf-field-name').val()
						}).done(function(r) {
							if (r.success) {
								$('#lsb-address-prefill-result').text(<?php echo json_encode( __( 'Sites remplis : ', 'local-seo-bulk' ) ); ?> + r.data.filled);
								if (r.data.filled > 0) setTimeout(function() { location.reload(); }, 800);
							} else {
								$('#lsb-address-prefill-result').text(r.data.message || <?php echo json_encode( __( 'Erreur.', 'local-seo-bulk' ) ); ?>);
							}
						})
						.fail(function() { $('#lsb-address-prefill-result').text(<?php echo json_encode( __( 'Erreur réseau.', 'local-seo-bulk' ) ); ?>); })
						.always(function() { $('#lsb-do-address-prefill').prop('disabled', false); });
					});
				});
			}(jQuery));
			</script>
		</div>
		<?php
	}

	private function render_pagination( $current_page, $total_pages, $total_rows ) {
		$base  = network_admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$class = $total_pages <= 1 ? ' one-page' : '';
		echo '<div class="tablenav-pages' . $class . '">';
		echo '<span class="displaying-num">' . esc_html( sprintf( _n( '%d site', '%d sites', $total_rows, 'local-seo-bulk' ), $total_rows ) ) . '</span>';

		if ( $total_pages > 1 ) {
			$links = [];
			if ( 1 === $current_page ) {
				$links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
				$links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
			} else {
				$links[] = sprintf( '<a class="first-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&laquo;</span></a>', esc_url( $base ), __( 'First page' ) );
				$links[] = sprintf( '<a class="prev-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&lsaquo;</span></a>', esc_url( add_query_arg( 'paged', max( 1, $current_page - 1 ), $base ) ), __( 'Previous page' ) );
			}
			$links[] = sprintf( '<span class="paging-input"><label for="current-page-selector" class="screen-reader-text">%s</label><input class="current-page" id="current-page-selector" type="text" name="paged" value="%d" size="%d" aria-describedby="table-paging"><span class="tablenav-paging-text"> %s <span class="total-pages">%d</span></span></span>', __( 'Current Page' ), $current_page, strlen( (string) $total_pages ), _x( 'of', 'paging' ), $total_pages );
			if ( $current_page === $total_pages ) {
				$links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
				$links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
			} else {
				$links[] = sprintf( '<a class="next-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&rsaquo;</span></a>', esc_url( add_query_arg( 'paged', min( $total_pages, $current_page + 1 ), $base ) ), __( 'Next page' ) );
				$links[] = sprintf( '<a class="last-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&raquo;</span></a>', esc_url( add_query_arg( 'paged', $total_pages, $base ) ), __( 'Last page' ) );
			}
			echo '<span class="pagination-links">' . implode( "\n", $links ) . '</span>';
		}
		echo '</div>';
	}
}
