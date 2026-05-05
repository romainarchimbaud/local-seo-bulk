<?php

/**
 * Network admin page: default active types, force-H1, kill switch and data reset.
 *
 * @package LocalSeoBulk
 */

if (! defined('ABSPATH')) exit;

class LSB_Network_Settings_Page {

    const PAGE_SLUG = 'lsb-network-settings';

    private $network_store;
    private $cpt_index;

    public function __construct(LSB_Network_Store $network_store, LSB_Network_CPT_Index $cpt_index) {
        $this->network_store = $network_store;
        $this->cpt_index     = $cpt_index;
    }

    public function init() {
        add_action('network_admin_menu', [$this, 'register_menu']);
        add_action('admin_post_lsb_save_network_settings', [$this, 'handle_save']);
        add_action('admin_post_lsb_reset_network_data',    [$this, 'handle_reset']);
    }

    public function register_menu() {
        add_submenu_page(
            LSB_Network_Scope_Page::PAGE_SLUG,
            __('Réglages global', 'local-seo-bulk'),
            __('Réglages global', 'local-seo-bulk'),
            'manage_network_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function handle_save() {
        if (! current_user_can('manage_network_options')) wp_die(-1);
        check_admin_referer('lsb_save_network_settings');

        $raw_editor = isset($_POST['lsb_network_editor_types']) ? wp_unslash($_POST['lsb_network_editor_types']) : [];
        $editor_types = [
            'scopes'     => isset($raw_editor['scopes'])     && is_array($raw_editor['scopes'])     ? array_map('sanitize_key', $raw_editor['scopes'])     : [],
            'post_types' => isset($raw_editor['post_types']) && is_array($raw_editor['post_types']) ? array_map('sanitize_key', $raw_editor['post_types']) : [],
            'taxonomies' => isset($raw_editor['taxonomies']) && is_array($raw_editor['taxonomies']) ? array_map('sanitize_key', $raw_editor['taxonomies']) : [],
        ];
        update_site_option('lsb_network_editor_types', $editor_types);

        $raw_h1 = isset($_POST['lsb_network_h1_force_types']) ? wp_unslash($_POST['lsb_network_h1_force_types']) : [];
        $h1_force_types = is_array($raw_h1) ? array_map('sanitize_key', $raw_h1) : [];
        update_site_option('lsb_network_h1_force_types', $h1_force_types);

        $raw_scopes = isset($_POST['lsb_network_scope_h1_overrides']) ? wp_unslash($_POST['lsb_network_scope_h1_overrides']) : [];
        $scope_overrides = is_array($raw_scopes) ? array_map('sanitize_key', $raw_scopes) : [];
        update_site_option('lsb_network_scope_h1_overrides', $scope_overrides);

        $kill = ! empty($_POST['lsb_network_kill_switch']) ? 1 : 0;
        update_site_option('lsb_network_kill_switch', $kill);

        wp_safe_redirect(network_admin_url('admin.php?page=' . self::PAGE_SLUG . '&saved=1'));
        exit;
    }

    public function handle_reset() {
        if (! current_user_can('manage_network_options')) wp_die(-1);
        check_admin_referer('lsb_reset_network_data');

        // Network-level options (use store constants for exact option names).
        delete_site_option( LSB_Network_Store::OPT_SCOPES );
        delete_site_option( LSB_Network_Store::OPT_ENTITY_VALUES );
        delete_site_option('lsb_network_seo_addresses');
        delete_site_option('lsb_network_editor_types');
        delete_site_option('lsb_network_h1_force_types');
        delete_site_option('lsb_network_scope_h1_overrides');
        delete_site_option('lsb_network_kill_switch');

        // CPT snapshots and transients.
        delete_site_transient(LSB_Network_CPT_Index::TRANSIENT);

        $with_sites = ! empty($_POST['lsb_reset_include_sites']);

        if ($with_sites) {
            $sites = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach ($sites as $site_id) {
                delete_site_option('lsb_site_cpts_' . $site_id);
                switch_to_blog($site_id);
                delete_option('lsb_editor_types');
                delete_option('lsb_h1_force_types');
                delete_option('lsb_site_scope_h1_overrides');
                delete_option('lsb_site_kill_switch');
                restore_current_blog();
            }
        } else {
            $sites = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach ($sites as $site_id) {
                delete_site_option('lsb_site_cpts_' . $site_id);
            }
        }

        wp_safe_redirect(network_admin_url('admin.php?page=' . self::PAGE_SLUG . '&reset=1'));
        exit;
    }

    public function render_page() {
        if (! current_user_can('manage_network_options')) return;

        $saved_editor    = get_site_option('lsb_network_editor_types', false);
        $saved_h1_types  = get_site_option('lsb_network_h1_force_types', false);
        $saved_h1_scopes = get_site_option('lsb_network_scope_h1_overrides', false);
        $kill_switch     = ! empty(get_site_option('lsb_network_kill_switch', 0));

        $scopes = $this->network_store->get_scopes();

        // Union of public types across all network sites (cached transient).
        $index      = $this->cpt_index->get_index();
        $post_types = $index['post_types'];
        $taxonomies = $index['taxonomies'];

        $pt_slugs  = array_column($post_types, 'slug');
        $tax_slugs = array_column($taxonomies, 'slug');

        // Active types defaults: all enabled when never saved.
        $enabled_scopes = $saved_editor !== false ? ($saved_editor['scopes']     ?? []) : array_keys($scopes);
        $enabled_pt     = $saved_editor !== false ? ($saved_editor['post_types'] ?? []) : $pt_slugs;
        $enabled_tax    = $saved_editor !== false ? ($saved_editor['taxonomies'] ?? []) : $tax_slugs;

        // Force H1 defaults: all active types enabled when never saved.
        $force_types = (false !== $saved_h1_types)
            ? $saved_h1_types
            : array_merge($enabled_pt, $enabled_tax);
?>
        <div class="wrap lsb-settings-wrap">
            <h1><?php esc_html_e('Réglages global — Local SEO Bulk', 'local-seo-bulk'); ?></h1>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Réglages global enregistrés.', 'local-seo-bulk'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['reset'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Données du plugin réinitialisées.', 'local-seo-bulk'); ?></p>
                </div>
            <?php endif; ?>

            <p class="description" style="margin-bottom:1.5em"><?php esc_html_e('Ces réglages définissent les valeurs par défaut pour tous les sites du réseau. Chaque site peut les remplacer depuis sa propre page Réglages.', 'local-seo-bulk'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lsb_save_network_settings">
                <?php wp_nonce_field('lsb_save_network_settings'); ?>

                <!-- ─── Types actifs ─────────────────────────────────── -->
                <div class="lsb-settings-card">
                    <h2>
                        <?php esc_html_e('Types actifs dans l\'éditeur', 'local-seo-bulk'); ?>
                        <label class="lsb-checkbox-label lsb-toggle-all-label">
                            <input type="checkbox" class="lsb-toggle-all-cb">
                            <?php esc_html_e('Tout activer', 'local-seo-bulk'); ?>
                        </label>
                    </h2>
                    <p class="description"><?php esc_html_e('Cochez les types à afficher dans la liste déroulante de l\'éditeur (défaut réseau).', 'local-seo-bulk'); ?></p>

                    <?php if (! empty($scopes)) : ?>
                        <div class="lsb-type-group">
                            <h3><?php esc_html_e('Règles globales', 'local-seo-bulk'); ?></h3>
                            <div class="lsb-checkbox-grid">
                                <?php foreach ($scopes as $sid => $scope) : ?>
                                    <label class="lsb-checkbox-label">
                                        <input type="checkbox" name="lsb_network_editor_types[scopes][]" value="<?php echo esc_attr($sid); ?>"
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
                                    <input type="checkbox" name="lsb_network_editor_types[post_types][]" value="<?php echo esc_attr($pt['slug']); ?>"
                                        <?php checked(in_array($pt['slug'], $enabled_pt, true)); ?>>
                                    <?php echo esc_html($pt['label']); ?>
                                    <span class="lsb-type-slug"><?php echo esc_html($pt['slug']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="lsb-type-group">
                        <h3><?php esc_html_e('Taxonomies', 'local-seo-bulk'); ?></h3>
                        <div class="lsb-checkbox-grid">
                            <?php foreach ($taxonomies as $tax) : ?>
                                <label class="lsb-checkbox-label">
                                    <input type="checkbox" name="lsb_network_editor_types[taxonomies][]" value="<?php echo esc_attr($tax['slug']); ?>"
                                        <?php checked(in_array($tax['slug'], $enabled_tax, true)); ?>>
                                    <?php echo esc_html($tax['label']); ?>
                                    <span class="lsb-type-slug"><?php echo esc_html($tax['slug']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ─── Forcer le H1 ─────────────────────────────────── -->
                <div class="lsb-settings-card">
                    <h2>
                        <?php esc_html_e('Forcer le H1', 'local-seo-bulk'); ?>
                        <label class="lsb-checkbox-label lsb-toggle-all-label">
                            <input type="checkbox" class="lsb-toggle-all-cb">
                            <?php esc_html_e('Tout activer', 'local-seo-bulk'); ?>
                        </label>
                    </h2>
                    <p class="description"><?php esc_html_e('Activer le remplacement automatique du H1 pour ces types (défaut réseau).', 'local-seo-bulk'); ?></p>

                    <?php if (! empty($scopes)) : ?>
                        <div class="lsb-type-group">
                            <h3><?php esc_html_e('Règles globales', 'local-seo-bulk'); ?></h3>
                            <div class="lsb-checkbox-grid">
                                <?php foreach ($scopes as $sid => $scope) :
                                    $checked = (false !== $saved_h1_scopes)
                                        ? in_array($sid, $saved_h1_scopes, true)
                                        : ($scope['replace_h1'] ?? true);
                                ?>
                                    <label class="lsb-checkbox-label">
                                        <input type="checkbox" name="lsb_network_scope_h1_overrides[]"
                                            value="<?php echo esc_attr($sid); ?>"
                                            <?php checked($checked); ?>>
                                        <?php echo esc_html($scope['label']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (! empty($enabled_pt)) : ?>
                        <div class="lsb-type-group">
                            <h3><?php esc_html_e('Types de contenus', 'local-seo-bulk'); ?></h3>
                            <div class="lsb-checkbox-grid">
                                <?php foreach ($post_types as $pt) :
                                    if (! in_array($pt['slug'], $enabled_pt, true)) continue; ?>
                                    <label class="lsb-checkbox-label">
                                        <input type="checkbox" name="lsb_network_h1_force_types[]" value="<?php echo esc_attr($pt['slug']); ?>"
                                            <?php checked(in_array($pt['slug'], $force_types, true)); ?>>
                                        <?php echo esc_html($pt['label']); ?>
                                        <span class="lsb-type-slug"><?php echo esc_html($pt['slug']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (! empty($enabled_tax)) : ?>
                        <div class="lsb-type-group">
                            <h3><?php esc_html_e('Taxonomies', 'local-seo-bulk'); ?></h3>
                            <div class="lsb-checkbox-grid">
                                <?php foreach ($taxonomies as $tax) :
                                    if (! in_array($tax['slug'], $enabled_tax, true)) continue; ?>
                                    <label class="lsb-checkbox-label">
                                        <input type="checkbox" name="lsb_network_h1_force_types[]" value="<?php echo esc_attr($tax['slug']); ?>"
                                            <?php checked(in_array($tax['slug'], $force_types, true)); ?>>
                                        <?php echo esc_html($tax['label']); ?>
                                        <span class="lsb-type-slug"><?php echo esc_html($tax['slug']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ─── Kill switch réseau ───────────────────────────── -->
                <div class="lsb-settings-card">
                    <h2><?php esc_html_e('Kill switch réseau', 'local-seo-bulk'); ?></h2>
                    <p class="description"><?php esc_html_e('Désactive tous les remplacements LSB (H1, meta title, meta description) sur l\'ensemble du réseau, quel que soit le réglage de chaque site. Les données sont conservées.', 'local-seo-bulk'); ?></p>
                    <label class="lsb-checkbox-label" style="margin-top:.75em;display:inline-flex">
                        <input type="checkbox" name="lsb_network_kill_switch" value="1" <?php checked($kill_switch); ?>>
                        <?php esc_html_e('Désactiver tous les remplacements SEO sur tout le réseau', 'local-seo-bulk'); ?>
                    </label>
                </div>

                <?php submit_button(__('Enregistrer les réglages réseau', 'local-seo-bulk'), 'primary', 'submit', false); ?>
            </form>

            <!-- ─── Réinitialiser les données ───────────────────────── -->
            <div class="lsb-settings-card" style="margin-top:2em;border-color:#d63638">
                <h2 style="color:#d63638;border-bottom-color:#f8c8c8"><?php esc_html_e('Réinitialiser les données du plugin', 'local-seo-bulk'); ?></h2>

                <div class="notice notice-warning inline" style="margin:.5em 0 1.25em;padding:.6em .75em">
                    <p><strong><?php esc_html_e('Attention — action irréversible.', 'local-seo-bulk'); ?></strong>
                        <?php esc_html_e('Cette action supprime les règles globales (scopes), les valeurs réseau par entité, les adresses SEO, les réglages réseau et les caches. Les méta SEO enregistrées sur chaque contenu (H1, titre, description) ne sont pas supprimées.', 'local-seo-bulk'); ?></p>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="lsb-reset-form">
                    <input type="hidden" name="action" value="lsb_reset_network_data">
                    <?php wp_nonce_field('lsb_reset_network_data'); ?>

                    <p>
                        <label style="display:inline-flex;align-items:center;gap:.4em;font-weight:600">
                            <input type="checkbox" name="lsb_reset_include_sites" value="1">
                            <?php esc_html_e('Inclure aussi les réglages locaux de chaque site (types actifs, forcer H1, kill switch par site)', 'local-seo-bulk'); ?>
                        </label>
                    </p>

                    <button type="submit" class="button button-primary" id="lsb-reset-btn">
                        <?php esc_html_e('Réinitialiser toutes les données réseau', 'local-seo-bulk'); ?>
                    </button>
                </form>
            </div>
        </div>
<?php
    }
}
