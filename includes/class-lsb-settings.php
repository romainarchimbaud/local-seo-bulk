<?php

/**
 * @package LocalSeoBulk
 */

if (! defined('ABSPATH')) exit;

class LSB_Settings {

    private $network_store;

    public function __construct(LSB_Network_Store $network_store) {
        $this->network_store = $network_store;
    }

    public function init() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_lsb_reset_site_types', [$this, 'handle_reset_site_types']);
        add_action('admin_post_lsb_reset_site_h1',    [$this, 'handle_reset_site_h1']);
    }

    public function register_settings() {
        register_setting('lsb_editor_types_group', 'lsb_editor_types', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_editor_types'],
        ]);

        register_setting('lsb_site_settings_group', 'lsb_site_kill_switch', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting('lsb_h1_force_types_group', 'lsb_h1_force_types', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_h1_force_types'],
        ]);

        register_setting('lsb_h1_force_types_group', 'lsb_site_scope_h1_overrides', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_scope_h1_overrides'],
        ]);
    }

    // --- Sanitize callbacks ---

    public function sanitize_editor_types($input) {
        return [
            'scopes'     => isset($input['scopes'])     && is_array($input['scopes'])     ? array_map('sanitize_key', $input['scopes'])     : [],
            'post_types' => isset($input['post_types']) && is_array($input['post_types']) ? array_map('sanitize_key', $input['post_types']) : [],
            'taxonomies' => isset($input['taxonomies']) && is_array($input['taxonomies']) ? array_map('sanitize_key', $input['taxonomies']) : [],
        ];
    }

    public function sanitize_h1_force_types($input) {
        if (! is_array($input)) return [];
        return array_map('sanitize_key', $input);
    }

    public function sanitize_scope_h1_overrides($input) {
        if (! is_array($input)) return [];
        return array_map('sanitize_key', $input);
    }

    // --- Reset handlers ---

    public function handle_reset_site_types() {
        if (! current_user_can('manage_options')) wp_die(-1);
        check_admin_referer('lsb_reset_site_types');
        delete_option('lsb_editor_types');
        wp_safe_redirect(admin_url('admin.php?page=lsb-settings&reset_types=1'));
        exit;
    }

    public function handle_reset_site_h1() {
        if (! current_user_can('manage_options')) wp_die(-1);
        check_admin_referer('lsb_reset_site_h1');
        delete_option('lsb_h1_force_types');
        delete_option('lsb_site_scope_h1_overrides');
        wp_safe_redirect(admin_url('admin.php?page=lsb-settings&reset_h1=1'));
        exit;
    }

	// --- Helpers ---

    /**
     * Returns editor types: site option if saved, otherwise network default, otherwise false (show all).
     */
    public function get_editor_types() {
        $site = get_option('lsb_editor_types', false);
        if (false !== $site) return $site;
        return get_site_option('lsb_network_editor_types', false);
    }

    public function site_has_editor_types_override() {
        return false !== get_option('lsb_editor_types', false);
    }

    public function site_has_h1_override() {
        return false !== get_option('lsb_h1_force_types', false)
            || false !== get_option('lsb_site_scope_h1_overrides', false);
    }

    public function get_settings() {
        return get_option('lsb_settings', []);
    }

    // --- Render page ---

    public function render_settings_page() {
        if (! current_user_can('manage_options')) return;

        $saved       = $this->get_editor_types();
        $scopes      = $this->network_store->get_scopes();
        $post_types  = get_post_types(['public' => true], 'objects');
        $taxonomies  = get_taxonomies(['public' => true], 'objects');
        unset($post_types['attachment']);

        $enabled_scopes = $saved !== false ? ($saved['scopes']     ?? []) : array_keys($scopes);
        $enabled_pt     = $saved !== false ? ($saved['post_types'] ?? []) : array_keys($post_types);
        $enabled_tax    = $saved !== false ? ($saved['taxonomies'] ?? []) : array_keys($taxonomies);
?>
        <div class="wrap lsb-settings-wrap">
            <h1><?php esc_html_e('Réglages — Local SEO Bulk', 'local-seo-bulk'); ?></h1>

            <?php if (isset($_GET['reset_types'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Types actifs réinitialisés vers la config réseau.', 'local-seo-bulk'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['reset_h1'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Forcer le H1 réinitialisé vers la config réseau.', 'local-seo-bulk'); ?></p>
                </div>
            <?php endif; ?>

            <!-- ─── Adresse SEO ──────────────────────────────── -->
            <div class="lsb-settings-card">
                <h2><?php esc_html_e('Adresse SEO', 'local-seo-bulk'); ?></h2>
                <?php
                $all_addresses = get_site_option('lsb_network_seo_addresses', []);
                $addr          = $all_addresses[get_current_blog_id()] ?? [];
                $network_url   = network_admin_url('admin.php?page=' . 'lsb-network-addresses');
                ?>
                <p><?php printf(
                        wp_kses(
                            /* translators: %s: link to network addresses page */
                            __('Les adresses SEO sont gérées au niveau réseau. <a href="%s">Configurer les adresses SEO réseau →</a>', 'local-seo-bulk'),
                            ['a' => ['href' => []]]
                        ),
                        esc_url($network_url)
                    ); ?></p>
                <?php if (! empty(array_filter($addr))) : ?>
                    <table class="lsb-vars-table lsb-addr-table" style="margin-bottom:1em">
                        <tbody>
                            <tr>
                                <th><?php esc_html_e('Ville', 'local-seo-bulk'); ?></th>
                                <td><?php echo esc_html($addr['ville'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Code postal', 'local-seo-bulk'); ?></th>
                                <td><?php echo esc_html($addr['code_postal'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Adresse', 'local-seo-bulk'); ?></th>
                                <td><?php echo esc_html($addr['adresse'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Département', 'local-seo-bulk'); ?></th>
                                <td><?php echo esc_html($addr['departement'] ?? '—'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('Aucune adresse SEO définie pour ce site.', 'local-seo-bulk'); ?></p>
                <?php endif; ?>


                <hr style="margin:1.5em 0">

                <h3 style="margin-top:0"><?php esc_html_e('Variables disponibles', 'local-seo-bulk'); ?></h3>
                <table class="lsb-vars-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Shortcode', 'local-seo-bulk'); ?></th>
                            <th><?php esc_html_e('Variable Yoast', 'local-seo-bulk'); ?></th>
                            <th><?php esc_html_e('Token éditeur', 'local-seo-bulk'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>[lsb_nom_site]</td>
                            <td>%%lsb_nom_site%%</td>
                            <td>%%lsb_nom_site%%</td>
                        </tr>
                        <tr>
                            <td>[lsb_ville]</td>
                            <td>%%lsb_ville%%</td>
                            <td>%%lsb_ville%%</td>
                        </tr>
                        <tr>
                            <td>[lsb_code_postal]</td>
                            <td>%%lsb_code_postal%%</td>
                            <td>%%lsb_code_postal%%</td>
                        </tr>
                        <tr>
                            <td>[lsb_adresse]</td>
                            <td>%%lsb_adresse%%</td>
                            <td>%%lsb_adresse%%</td>
                        </tr>
                        <tr>
                            <td>[lsb_departement]</td>
                            <td>%%lsb_departement%%</td>
                            <td>%%lsb_departement%%</td>
                        </tr>
                        <tr>
                            <td>[lsb_h1]</td>
                            <td>—</td>
                            <td>—</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ─── Types actifs ─────────────────────────────────── -->
            <div class="lsb-settings-card">
                <h2>
                    <?php esc_html_e('Types actifs dans l\'éditeur', 'local-seo-bulk'); ?>
                    <label class="lsb-checkbox-label lsb-toggle-all-label">
                        <input type="checkbox" class="lsb-toggle-all-cb">
                        <?php esc_html_e('Tout activer', 'local-seo-bulk'); ?>
                    </label>
                </h2>
                <p class="description"><?php esc_html_e('Cochez les types à afficher dans la liste déroulante de l\'éditeur.', 'local-seo-bulk'); ?></p>

                <?php if (! $this->site_has_editor_types_override() && false !== get_site_option('lsb_network_editor_types', false)) : ?>
                    <p class="notice notice-info inline" style="padding:.5em .75em;margin:.5em 0 1em"><?php esc_html_e('Ces réglages héritent de la configuration réseau.', 'local-seo-bulk'); ?></p>
                <?php elseif ($this->site_has_editor_types_override()) : ?>
                    <p style="margin:.5em 0 1em">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <input type="hidden" name="action" value="lsb_reset_site_types">
                        <?php wp_nonce_field('lsb_reset_site_types'); ?>
                        <button type="submit" class="button button-small"><?php esc_html_e('Réinitialiser vers config réseau', 'local-seo-bulk'); ?></button>
                    </form>
                    </p>
                <?php endif; ?>

                <form method="post" action="options.php" style="margin-top:1em">
                    <?php settings_fields('lsb_editor_types_group'); ?>

                    <?php if (! empty($scopes)) : ?>
                        <div class="lsb-type-group">
                            <h3><?php esc_html_e('Règles globales', 'local-seo-bulk'); ?></h3>
                            <div class="lsb-checkbox-grid">
                                <?php foreach ($scopes as $sid => $scope) : ?>
                                    <label class="lsb-checkbox-label">
                                        <input type="checkbox" name="lsb_editor_types[scopes][]" value="<?php echo esc_attr($sid); ?>"
                                            <?php checked(in_array($sid, $enabled_scopes, true)); ?>>
                                        <?php echo esc_html($scope['label']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="lsb-type-group">
                        <h3><?php esc_html_e('Types de contenus', 'local-seo-bulk'); ?></h3>
                        <div class="lsb-checkbox-grid">
                            <?php foreach ($post_types as $pt) : ?>
                                <label class="lsb-checkbox-label">
                                    <input type="checkbox" name="lsb_editor_types[post_types][]" value="<?php echo esc_attr($pt->name); ?>"
                                        <?php checked(in_array($pt->name, $enabled_pt, true)); ?>>
                                    <?php echo esc_html($pt->labels->name); ?>
                                    <span class="lsb-type-slug"><?php echo esc_html($pt->name); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="lsb-type-group">
                        <h3><?php esc_html_e('Taxonomies', 'local-seo-bulk'); ?></h3>
                        <div class="lsb-checkbox-grid">
                            <?php foreach ($taxonomies as $tax) : ?>
                                <label class="lsb-checkbox-label">
                                    <input type="checkbox" name="lsb_editor_types[taxonomies][]" value="<?php echo esc_attr($tax->name); ?>"
                                        <?php checked(in_array($tax->name, $enabled_tax, true)); ?>>
                                    <?php echo esc_html($tax->labels->name); ?>
                                    <span class="lsb-type-slug"><?php echo esc_html($tax->name); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php submit_button(__('Enregistrer les types', 'local-seo-bulk'), 'primary', 'submit', false); ?>
                </form>
            </div>

            <!-- ─── Forcer le H1 ─────────────────────────────────── -->
            <div class="lsb-settings-card" id="lsb-force-h1-card">
                <h2>
                    <?php esc_html_e('Forcer le H1', 'local-seo-bulk'); ?>
                    <label class="lsb-checkbox-label lsb-toggle-all-label">
                        <input type="checkbox" class="lsb-toggle-all-cb">
                        <?php esc_html_e('Tout activer', 'local-seo-bulk'); ?>
                    </label>
                </h2>
                <p class="description"><?php esc_html_e('Activer le remplacement automatique du H1 pour ces types, même sans règle globale configurée.', 'local-seo-bulk'); ?></p>

                <?php if (! $this->site_has_h1_override() && (false !== get_site_option('lsb_network_h1_force_types', false) || false !== get_site_option('lsb_network_scope_h1_overrides', false))) : ?>
                    <p class="notice notice-info inline" style="padding:.5em .75em;margin:.5em 0 1em"><?php esc_html_e('Ces réglages héritent de la configuration réseau.', 'local-seo-bulk'); ?></p>
                <?php elseif ($this->site_has_h1_override()) : ?>
                    <p style="margin:.5em 0 1em">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <input type="hidden" name="action" value="lsb_reset_site_h1">
                        <?php wp_nonce_field('lsb_reset_site_h1'); ?>
                        <button type="submit" class="button button-small"><?php esc_html_e('Réinitialiser vers config réseau', 'local-seo-bulk'); ?></button>
                    </form>
                    </p>
                <?php endif; ?>

                <form method="post" action="options.php" style="margin-top:1em">
                    <?php settings_fields('lsb_h1_force_types_group'); ?>

                    <?php
                    $force_types_saved     = get_option('lsb_h1_force_types', false);
                    $scope_overrides_saved = get_option('lsb_site_scope_h1_overrides', false);
                    $active_pt             = $enabled_pt;
                    $active_tax            = $enabled_tax;

                    $network_h1_types  = get_site_option('lsb_network_h1_force_types', false);
                    $network_h1_scopes = get_site_option('lsb_network_scope_h1_overrides', false);

                    // Resolution chain: site option → network option → all active types.
                    if (false !== $force_types_saved) {
                        $force_types = $force_types_saved;
                    } elseif (false !== $network_h1_types) {
                        $force_types = $network_h1_types;
                    } else {
                        $force_types = array_merge($active_pt, $active_tax);
                    }

                    // Resolution chain for scope overrides: site → network → scope default.
                    $scope_overrides_resolved = (false !== $scope_overrides_saved)
                        ? $scope_overrides_saved
                        : ((false !== $network_h1_scopes) ? $network_h1_scopes : null);
                    ?>

                    <?php if (! empty($scopes)) : ?>
                        <div class="lsb-type-group">
                            <h3><?php esc_html_e('Règles globales', 'local-seo-bulk'); ?></h3>
                            <div class="lsb-checkbox-grid">
                                <?php foreach ($scopes as $sid => $scope) :
                                    $checked = (null !== $scope_overrides_resolved)
                                        ? in_array($sid, $scope_overrides_resolved, true)
                                        : ($scope['replace_h1'] ?? true);
                                ?>
                                    <label class="lsb-checkbox-label">
                                        <input type="checkbox" name="lsb_site_scope_h1_overrides[]"
                                            value="<?php echo esc_attr($sid); ?>"
                                            <?php checked($checked); ?>>
                                        <?php echo esc_html($scope['label']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (! empty($active_pt)) : ?>
                        <div class="lsb-type-group" id="lsb-force-h1-post-types">
                            <h3><?php esc_html_e('Types de contenus', 'local-seo-bulk'); ?></h3>
                            <div class="lsb-checkbox-grid">
                                <?php foreach ($post_types as $pt) :
                                    if (! in_array($pt->name, $active_pt, true)) continue; ?>
                                    <label class="lsb-checkbox-label lsb-force-h1-item" data-type="<?php echo esc_attr($pt->name); ?>">
                                        <input type="checkbox" name="lsb_h1_force_types[]" value="<?php echo esc_attr($pt->name); ?>"
                                            <?php checked(in_array($pt->name, $force_types, true)); ?>>
                                        <?php echo esc_html($pt->labels->name); ?>
                                        <span class="lsb-type-slug"><?php echo esc_html($pt->name); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (! empty($active_tax)) : ?>
                        <div class="lsb-type-group" id="lsb-force-h1-taxonomies">
                            <h3><?php esc_html_e('Taxonomies', 'local-seo-bulk'); ?></h3>
                            <div class="lsb-checkbox-grid">
                                <?php foreach ($taxonomies as $tax) :
                                    if (! in_array($tax->name, $active_tax, true)) continue; ?>
                                    <label class="lsb-checkbox-label lsb-force-h1-item" data-type="<?php echo esc_attr($tax->name); ?>">
                                        <input type="checkbox" name="lsb_h1_force_types[]" value="<?php echo esc_attr($tax->name); ?>"
                                            <?php checked(in_array($tax->name, $force_types, true)); ?>>
                                        <?php echo esc_html($tax->labels->name); ?>
                                        <span class="lsb-type-slug"><?php echo esc_html($tax->name); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php submit_button(__('Enregistrer', 'local-seo-bulk'), 'primary', 'submit', false); ?>
                </form>
            </div>

            <!-- ─── Options du site ──────────────────────────────── -->
            <div class="lsb-settings-card">
                <h2><?php esc_html_e('Options du site', 'local-seo-bulk'); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields('lsb_site_settings_group'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Kill switch', 'local-seo-bulk'); ?></th>
                            <td>
                                <?php $checked = ! empty(get_option('lsb_site_kill_switch', 0)); ?>
                                <label>
                                    <input type="checkbox" name="lsb_site_kill_switch" value="1" <?php checked($checked); ?>>
                                    <?php esc_html_e('Désactiver tous les remplacements SEO sur ce site', 'local-seo-bulk'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Les données sont conservées, Yoast reprend le contrôle.', 'local-seo-bulk'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(null, 'primary', 'submit', false); ?>
                </form>
            </div>
        </div>
<?php
    }
}
