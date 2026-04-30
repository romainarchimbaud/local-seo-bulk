<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSB_Settings {

	private $network_store;

	public function __construct( LSB_Network_Store $network_store ) {
		$this->network_store = $network_store;
	}

	public function init() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_settings() {
		register_setting( 'lsb_editor_types_group', 'lsb_editor_types', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_editor_types' ],
		] );

		register_setting( 'lsb_site_settings_group', 'lsb_site_kill_switch', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		] );

		register_setting( 'lsb_h1_force_types_group', 'lsb_h1_force_types', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_h1_force_types' ],
		] );
	}

	// --- Sanitize callbacks ---

	public function sanitize_editor_types( $input ) {
		return [
			'scopes'     => isset( $input['scopes'] )     && is_array( $input['scopes'] )     ? array_map( 'sanitize_key', $input['scopes'] )     : [],
			'post_types' => isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_map( 'sanitize_key', $input['post_types'] ) : [],
			'taxonomies' => isset( $input['taxonomies'] ) && is_array( $input['taxonomies'] ) ? array_map( 'sanitize_key', $input['taxonomies'] ) : [],
		];
	}

	public function sanitize_h1_force_types( $input ) {
		if ( ! is_array( $input ) ) return [];
		return array_map( 'sanitize_key', $input );
	}

	// --- Helpers ---

	/**
	 * Returns saved editor types, or false if never saved (show all by default).
	 */
	public function get_editor_types() {
		return get_option( 'lsb_editor_types', false );
	}

	public function get_settings() {
		return get_option( 'lsb_settings', [] );
	}

	// --- Render page ---

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$saved       = $this->get_editor_types();
		$scopes      = $this->network_store->get_scopes();
		$post_types  = get_post_types( [ 'public' => true ], 'objects' );
		$taxonomies  = get_taxonomies( [ 'public' => true ], 'objects' );
		unset( $post_types['attachment'] );

		$enabled_scopes = $saved !== false ? ( $saved['scopes']     ?? [] ) : array_keys( $scopes );
		$enabled_pt     = $saved !== false ? ( $saved['post_types'] ?? [] ) : array_keys( $post_types );
		$enabled_tax    = $saved !== false ? ( $saved['taxonomies'] ?? [] ) : array_keys( $taxonomies );
		?>
		<div class="wrap lsb-settings-wrap">
			<h1><?php esc_html_e( 'Réglages — Local SEO Bulk', 'local-seo-bulk' ); ?></h1>

			<!-- ─── Adresse SEO ──────────────────────────────── -->
			<div class="lsb-settings-card">
				<h2><?php esc_html_e( 'Adresse SEO', 'local-seo-bulk' ); ?></h2>
				<?php
				$all_addresses = get_site_option( 'lsb_network_seo_addresses', [] );
				$addr          = $all_addresses[ get_current_blog_id() ] ?? [];
				$network_url   = network_admin_url( 'admin.php?page=' . 'lsb-network-addresses' );
				?>
				<?php if ( ! empty( array_filter( $addr ) ) ) : ?>
					<table class="form-table" role="presentation" style="margin-bottom:.5em">
						<tr>
							<th scope="row" style="width:8em"><?php esc_html_e( 'Ville', 'local-seo-bulk' ); ?></th>
							<td><?php echo esc_html( $addr['ville'] ?? '—' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Code postal', 'local-seo-bulk' ); ?></th>
							<td><?php echo esc_html( $addr['code_postal'] ?? '—' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Adresse', 'local-seo-bulk' ); ?></th>
							<td><?php echo esc_html( $addr['adresse'] ?? '—' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Département', 'local-seo-bulk' ); ?></th>
							<td><?php echo esc_html( $addr['departement'] ?? '—' ); ?></td>
						</tr>
					</table>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Aucune adresse SEO définie pour ce site.', 'local-seo-bulk' ); ?></p>
				<?php endif; ?>
				<p><?php printf(
					wp_kses(
						/* translators: %s: link to network addresses page */
						__( 'Les adresses SEO sont gérées au niveau réseau. <a href="%s">Configurer les adresses SEO réseau →</a>', 'local-seo-bulk' ),
						[ 'a' => [ 'href' => [] ] ]
					),
					esc_url( $network_url )
				); ?></p>

				<hr style="margin:1.5em 0">

				<h3 style="margin-top:0"><?php esc_html_e( 'Variables disponibles', 'local-seo-bulk' ); ?></h3>
				<table class="lsb-vars-table">
					<thead><tr><th><?php esc_html_e( 'Shortcode', 'local-seo-bulk' ); ?></th><th><?php esc_html_e( 'Variable Yoast', 'local-seo-bulk' ); ?></th><th><?php esc_html_e( 'Token éditeur', 'local-seo-bulk' ); ?></th></tr></thead>
					<tbody>
						<tr><td><code>[lsb_ville]</code></td><td><code>%%lsb_ville%%</code></td><td><code>%%lsb_ville%%</code></td></tr>
						<tr><td><code>[lsb_code_postal]</code></td><td><code>%%lsb_code_postal%%</code></td><td><code>%%lsb_code_postal%%</code></td></tr>
						<tr><td><code>[lsb_adresse]</code></td><td><code>%%lsb_adresse%%</code></td><td><code>%%lsb_adresse%%</code></td></tr>
						<tr><td><code>[lsb_departement]</code></td><td><code>%%lsb_departement%%</code></td><td><code>%%lsb_departement%%</code></td></tr>
						<tr><td><code>[lsb_h1]</code></td><td>—</td><td>—</td></tr>
					</tbody>
				</table>
			</div>

			<!-- ─── Types actifs ─────────────────────────────────── -->
			<div class="lsb-settings-card">
				<h2><?php esc_html_e( 'Types actifs dans l\'éditeur', 'local-seo-bulk' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Cochez les types à afficher dans la liste déroulante de l\'éditeur.', 'local-seo-bulk' ); ?></p>

				<form method="post" action="options.php" style="margin-top:1em">
					<?php settings_fields( 'lsb_editor_types_group' ); ?>

					<?php if ( ! empty( $scopes ) ) : ?>
					<div class="lsb-type-group">
						<h3><?php esc_html_e( 'Scopes réseau', 'local-seo-bulk' ); ?></h3>
						<div class="lsb-checkbox-grid">
							<?php foreach ( $scopes as $sid => $scope ) : ?>
								<label class="lsb-checkbox-label">
									<input type="checkbox" name="lsb_editor_types[scopes][]" value="<?php echo esc_attr( $sid ); ?>"
										<?php checked( in_array( $sid, $enabled_scopes, true ) ); ?>>
									<?php echo esc_html( $scope['label'] ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<div class="lsb-type-group">
						<h3><?php esc_html_e( 'Types de contenus', 'local-seo-bulk' ); ?></h3>
						<div class="lsb-checkbox-grid">
							<?php foreach ( $post_types as $pt ) : ?>
								<label class="lsb-checkbox-label">
									<input type="checkbox" name="lsb_editor_types[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, $enabled_pt, true ) ); ?>>
									<?php echo esc_html( $pt->labels->name ); ?>
									<span class="lsb-type-slug"><?php echo esc_html( $pt->name ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="lsb-type-group">
						<h3><?php esc_html_e( 'Taxonomies', 'local-seo-bulk' ); ?></h3>
						<div class="lsb-checkbox-grid">
							<?php foreach ( $taxonomies as $tax ) : ?>
								<label class="lsb-checkbox-label">
									<input type="checkbox" name="lsb_editor_types[taxonomies][]" value="<?php echo esc_attr( $tax->name ); ?>"
										<?php checked( in_array( $tax->name, $enabled_tax, true ) ); ?>>
									<?php echo esc_html( $tax->labels->name ); ?>
									<span class="lsb-type-slug"><?php echo esc_html( $tax->name ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<?php submit_button( __( 'Enregistrer les types', 'local-seo-bulk' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<!-- ─── Forcer le H1 ─────────────────────────────────── -->
			<div class="lsb-settings-card" id="lsb-force-h1-card">
				<h2><?php esc_html_e( 'Forcer le H1', 'local-seo-bulk' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Activer le remplacement automatique du H1 pour ces types, même sans scope réseau configuré.', 'local-seo-bulk' ); ?></p>

				<form method="post" action="options.php" style="margin-top:1em">
					<?php settings_fields( 'lsb_h1_force_types_group' ); ?>

					<?php
					$force_types_saved = get_option( 'lsb_h1_force_types', false );
					$active_pt         = $enabled_pt;
					$active_tax        = $enabled_tax;
					// Default: all active types checked when option has never been saved.
					$force_types = ( false !== $force_types_saved )
						? $force_types_saved
						: array_merge( $active_pt, $active_tax );
					?>

					<?php if ( ! empty( $active_pt ) ) : ?>
					<div class="lsb-type-group" id="lsb-force-h1-post-types">
						<h3><?php esc_html_e( 'Types de contenus', 'local-seo-bulk' ); ?></h3>
						<div class="lsb-checkbox-grid">
							<?php foreach ( $post_types as $pt ) :
								if ( ! in_array( $pt->name, $active_pt, true ) ) continue; ?>
								<label class="lsb-checkbox-label lsb-force-h1-item" data-type="<?php echo esc_attr( $pt->name ); ?>">
									<input type="checkbox" name="lsb_h1_force_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, $force_types, true ) ); ?>>
									<?php echo esc_html( $pt->labels->name ); ?>
									<span class="lsb-type-slug"><?php echo esc_html( $pt->name ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $active_tax ) ) : ?>
					<div class="lsb-type-group" id="lsb-force-h1-taxonomies">
						<h3><?php esc_html_e( 'Taxonomies', 'local-seo-bulk' ); ?></h3>
						<div class="lsb-checkbox-grid">
							<?php foreach ( $taxonomies as $tax ) :
								if ( ! in_array( $tax->name, $active_tax, true ) ) continue; ?>
								<label class="lsb-checkbox-label lsb-force-h1-item" data-type="<?php echo esc_attr( $tax->name ); ?>">
									<input type="checkbox" name="lsb_h1_force_types[]" value="<?php echo esc_attr( $tax->name ); ?>"
										<?php checked( in_array( $tax->name, $force_types, true ) ); ?>>
									<?php echo esc_html( $tax->labels->name ); ?>
									<span class="lsb-type-slug"><?php echo esc_html( $tax->name ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<?php submit_button( __( 'Enregistrer', 'local-seo-bulk' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<!-- ─── Options du site ──────────────────────────────── -->
			<div class="lsb-settings-card">
				<h2><?php esc_html_e( 'Options du site', 'local-seo-bulk' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( 'lsb_site_settings_group' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Kill switch', 'local-seo-bulk' ); ?></th>
							<td>
								<?php $checked = ! empty( get_option( 'lsb_site_kill_switch', 0 ) ); ?>
								<label>
									<input type="checkbox" name="lsb_site_kill_switch" value="1" <?php checked( $checked ); ?>>
									<?php esc_html_e( 'Désactiver tous les remplacements SEO sur ce site', 'local-seo-bulk' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Les données sont conservées, Yoast reprend le contrôle.', 'local-seo-bulk' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( null, 'primary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
