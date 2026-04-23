<?php

/**
 * Network admin page: manage scopes (CRUD + scope-level patterns).
 *
 * @package LocalSeoBulk
 */

if (! defined('ABSPATH')) exit;

class LSB_Network_Scope_Page {

    const PAGE_SLUG = 'lsb-network-scopes';

    private $store;
    private $cpt_index;

    public function __construct(LSB_Network_Store $store, LSB_Network_CPT_Index $cpt_index) {
        $this->store     = $store;
        $this->cpt_index = $cpt_index;
    }

    public function init() {
        add_action('network_admin_menu', [$this, 'register_menu']);
        add_action('admin_post_lsb_save_scope',         [$this, 'handle_save_scope']);
        add_action('admin_post_lsb_delete_scope',       [$this, 'handle_delete_scope']);
        add_action('admin_post_lsb_bulk_delete_scopes', [$this, 'handle_bulk_delete_scopes']);
        add_action('admin_post_lsb_refresh_cpt_index',  [$this, 'handle_refresh_cpt_index']);
        add_filter('set_screen_option_lsb_items_per_page', [$this, 'save_screen_option'], 10, 3);
    }

    public function register_menu() {
        $hook = add_menu_page(
            __('SEO Masse', 'local-seo-bulk'),
            __('SEO Masse', 'local-seo-bulk'),
            'manage_network_options',
            self::PAGE_SLUG,
            [$this, 'render_page'],
            'dashicons-location-alt',
            80
        );
        add_action('load-' . $hook, [$this, 'add_screen_options']);

        add_submenu_page(
            self::PAGE_SLUG,
            __('Scopes', 'local-seo-bulk'),
            __('Scopes', 'local-seo-bulk'),
            'manage_network_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function add_screen_options() {
        add_screen_option('per_page', [
            'label'   => __('Scopes par page', 'local-seo-bulk'),
            'default' => 50,
            'option'  => 'lsb_items_per_page',
        ]);
    }

    public function save_screen_option($screen_option, $option, $value) {
        return (int) $value;
    }

    public function render_page() {
        if (! current_user_can('manage_network_options')) return;

        $editing = isset($_GET['edit']) ? sanitize_key($_GET['edit']) : '';
        $adding  = isset($_GET['action']) && 'add' === $_GET['action'];
        if ($editing || $adding) {
            $this->render_form($editing);
            return;
        }
        $this->render_list();
    }

    private function render_list() {
        $all_scopes  = $this->store->get_scopes();
        $entity_vals = $this->store->get_all_entity_values();
        $base        = network_admin_url('admin.php?page=' . self::PAGE_SLUG);

        $per_page     = (int) get_user_meta(get_current_user_id(), 'lsb_items_per_page', true);
        if ($per_page < 1) $per_page = 50;
        $total_scopes = count($all_scopes);
        $total_pages  = max(1, (int) ceil($total_scopes / $per_page));
        $current_page = max(1, min($total_pages, (int) ($_GET['paged'] ?? 1)));
        $scopes       = array_slice($all_scopes, ($current_page - 1) * $per_page, $per_page, true);
?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('SEO Masse — Scopes réseau', 'local-seo-bulk'); ?></h1>
            <a href="<?php echo esc_url(add_query_arg('action', 'add', $base)); ?>" class="page-title-action"><?php esc_html_e('Ajouter un scope', 'local-seo-bulk'); ?></a>
            <hr class="wp-header-end">

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Scope enregistré.', 'local-seo-bulk'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['deleted']) || isset($_GET['bulk_deleted'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Scope(s) supprimé(s).', 'local-seo-bulk'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="lsb-scopes-form">
                <input type="hidden" name="action" value="lsb_bulk_delete_scopes">
                <?php wp_nonce_field('lsb_bulk_delete_scopes'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e('Choisir une action groupée', 'local-seo-bulk'); ?></label>
                        <select name="bulk_action" id="bulk-action-selector-top">
                            <option value="-1"><?php esc_html_e('Actions groupées', 'local-seo-bulk'); ?></option>
                            <option value="delete"><?php esc_html_e('Supprimer', 'local-seo-bulk'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e('Appliquer', 'local-seo-bulk'); ?>">
                    </div>
                    <div class="tablenav-pages <?php echo $total_pages <= 1 ? 'one-page' : ''; ?>">
                        <span class="displaying-num"><?php echo esc_html(sprintf(_n('%d scope', '%d scopes', $total_scopes, 'local-seo-bulk'), $total_scopes)); ?></span>
                        <?php if ($total_pages > 1) :
                            echo wp_kses_post(paginate_links([
                                'base'      => add_query_arg('paged', '%#%', $base),
                                'format'    => '',
                                'current'   => $current_page,
                                'total'     => $total_pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            ]));
                        endif; ?>
                    </div>
                    <br class="clear">
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <label class="screen-reader-text" for="cb-select-all"><?php esc_html_e('Tout sélectionner', 'local-seo-bulk'); ?></label>
                                <input id="cb-select-all" type="checkbox">
                            </td>
                            <th class="manage-column"><?php esc_html_e('ID', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Libellé', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Type', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Slug CPT/Taxo', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Filtre', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Entités réseau', 'local-seo-bulk'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="the-list">
                        <?php if (empty($scopes)) : ?>
                            <tr>
                                <td colspan="7"><?php esc_html_e('Aucun scope configuré.', 'local-seo-bulk'); ?></td>
                            </tr>
                        <?php else : foreach ($scopes as $id => $scope) :
                            $edit_url   = add_query_arg('edit', $id, $base);
                            $delete_url = wp_nonce_url(admin_url('admin-post.php?action=lsb_delete_scope&scope_id=' . $id), 'lsb_delete_scope_' . $id);
                            $f          = $scope['filter'];
                            $count      = isset($entity_vals[$id]) ? count($entity_vals[$id]) : 0;
                        ?>
                            <tr>
                                <th class="check-column">
                                    <label class="screen-reader-text" for="cb-select-<?php echo esc_attr($id); ?>"><?php echo esc_html($id); ?></label>
                                    <input id="cb-select-<?php echo esc_attr($id); ?>" type="checkbox" name="scope_ids[]" value="<?php echo esc_attr($id); ?>">
                                </th>
                                <td class="column-primary">
                                    <strong><a href="<?php echo esc_url($edit_url); ?>"><code><?php echo esc_html($id); ?></code></a></strong>
                                    <div class="row-actions">
                                        <span class="edit"><a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Modifier', 'local-seo-bulk'); ?></a> | </span>
                                        <span class="delete"><a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('<?php esc_attr_e('Supprimer ce scope ?', 'local-seo-bulk'); ?>');" class="submitdelete"><?php esc_html_e('Supprimer', 'local-seo-bulk'); ?></a></span>
                                    </div>
                                </td>
                                <td><?php echo esc_html($scope['label']); ?></td>
                                <td><?php echo esc_html('post_type' === $scope['object_kind'] ? __('Post type', 'local-seo-bulk') : __('Taxonomie', 'local-seo-bulk')); ?></td>
                                <td><code><?php echo esc_html($scope['slug']); ?></code></td>
                                <td>
                                    <?php
                                    echo esc_html($f['type']);
                                    if ('custom_meta' === $f['type']) {
                                        echo ' <small>(' . esc_html($f['meta_key']) . '=' . esc_html($f['meta_value']) . ')</small>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($count); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input id="cb-select-all-2" type="checkbox">
                            </td>
                            <th class="manage-column"><?php esc_html_e('ID', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Libellé', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Type', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Slug CPT/Taxo', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Filtre', 'local-seo-bulk'); ?></th>
                            <th class="manage-column"><?php esc_html_e('Entités réseau', 'local-seo-bulk'); ?></th>
                        </tr>
                    </tfoot>
                </table>

                <div class="tablenav bottom">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action_bottom">
                            <option value="-1"><?php esc_html_e('Actions groupées', 'local-seo-bulk'); ?></option>
                            <option value="delete"><?php esc_html_e('Supprimer', 'local-seo-bulk'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e('Appliquer', 'local-seo-bulk'); ?>">
                    </div>
                    <?php if ($total_pages > 1) : ?>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html(sprintf(_n('%d scope', '%d scopes', $total_scopes, 'local-seo-bulk'), $total_scopes)); ?></span>
                        <?php echo wp_kses_post(paginate_links([
                            'base'      => add_query_arg('paged', '%#%', $base),
                            'format'    => '',
                            'current'   => $current_page,
                            'total'     => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ])); ?>
                    </div>
                    <?php endif; ?>
                    <br class="clear">
                </div>
            </form>

            <p style="margin-top:1em;">
                <a href="<?php echo esc_url(network_admin_url('admin.php?page=lsb-network-editor')); ?>" class="button"><?php esc_html_e('Aller à l\'éditeur réseau →', 'local-seo-bulk'); ?></a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lsb_refresh_cpt_index'), 'lsb_refresh_cpt_index')); ?>" class="button"><?php esc_html_e('Rafraîchir la liste des CPT/taxos', 'local-seo-bulk'); ?></a>
            </p>
        </div>

        <script>
        (function() {
            function syncCheckboxes(source, targets) {
                targets.forEach(function(cb) { cb.checked = source.checked; });
            }
            var all1 = document.getElementById('cb-select-all');
            var all2 = document.getElementById('cb-select-all-2');
            var rows = Array.from(document.querySelectorAll('input[name="scope_ids[]"]'));
            if (all1) {
                all1.addEventListener('change', function() { syncCheckboxes(this, rows); if (all2) all2.checked = this.checked; });
            }
            if (all2) {
                all2.addEventListener('change', function() { syncCheckboxes(this, rows); if (all1) all1.checked = this.checked; });
            }
            // Handle bottom bulk action select (mirror to top before submit)
            var form = document.getElementById('lsb-scopes-form');
            var topAction   = document.querySelector('select[name="bulk_action"]');
            var bottomAction = document.querySelector('select[name="bulk_action_bottom"]');
            if (form && bottomAction) {
                form.addEventListener('submit', function() {
                    if (bottomAction.value !== '-1' && topAction) {
                        topAction.value = bottomAction.value;
                    }
                });
            }
        }());
        </script>
    <?php
    }

    private function render_form($scope_id) {
        $scope = $scope_id ? $this->store->get_scope($scope_id) : null;
        $index = $this->cpt_index->get_index();

        $id          = $scope['id']          ?? '';
        $label       = $scope['label']       ?? '';
        $object_kind = $scope['object_kind'] ?? 'post_type';
        $slug        = $scope['slug']        ?? '';
        $filter      = $scope['filter']      ?? ['type' => 'all', 'meta_key' => '', 'meta_value' => ''];
        $replace_h1  = $scope['replace_h1']  ?? true;
        $is_tax      = 'taxonomy' === $object_kind;
    ?>
        <div class="wrap">
            <h1><?php echo $scope_id ? esc_html__('Modifier le scope', 'local-seo-bulk') : esc_html__('Nouveau scope', 'local-seo-bulk'); ?></h1>

            <div class="lsb-settings-card" style="max-width:720px">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="lsb_save_scope">
                    <?php wp_nonce_field('lsb_save_scope'); ?>
                    <input type="hidden" name="original_id" value="<?php echo esc_attr($id); ?>">

                    <table class="form-table">
                        <tr>
                            <th><label for="lsb-scope-id"><?php esc_html_e('Identifiant (slug)', 'local-seo-bulk'); ?></label></th>
                            <td>
                                <input type="text" id="lsb-scope-id" name="scope_id" value="<?php echo esc_attr($id); ?>" class="regular-text" required>
                                <p class="description"><?php esc_html_e('Minuscules, tirets, ex: produits-parents', 'local-seo-bulk'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="lsb-label"><?php esc_html_e('Libellé', 'local-seo-bulk'); ?></label></th>
                            <td><input id="lsb-label" type="text" name="label" value="<?php echo esc_attr($label); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th id="lsb-kind-label"><?php echo $is_tax ? esc_html__('Taxonomie', 'local-seo-bulk') : esc_html__('Custom Post Type', 'local-seo-bulk'); ?></th>
                            <td>
                                <div class="lsb-radio-pill-group">
                                    <label class="lsb-radio-pill">
                                        <input type="radio" name="object_kind" value="post_type" <?php checked($object_kind, 'post_type'); ?>>
                                        <?php esc_html_e('Custom Post Type', 'local-seo-bulk'); ?>
                                    </label>
                                    <label class="lsb-radio-pill">
                                        <input type="radio" name="object_kind" value="taxonomy" <?php checked($object_kind, 'taxonomy'); ?>>
                                        <?php esc_html_e('Taxonomie', 'local-seo-bulk'); ?>
                                    </label>
                                </div>

                                <select id="lsb-pt-select" name="slug" <?php echo $is_tax ? 'disabled' : ''; ?> required>
                                    <option value=""><?php esc_html_e('— Sélectionner un post type —', 'local-seo-bulk'); ?></option>
                                    <?php foreach ($index['post_types'] as $pt) : ?>
                                        <option value="<?php echo esc_attr($pt['slug']); ?>" <?php selected(! $is_tax ? $slug : '', $pt['slug']); ?>><?php echo esc_html($pt['label'] . ' (' . $pt['slug'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <select id="lsb-tax-select" name="slug" <?php echo ! $is_tax ? 'disabled' : ''; ?> <?php echo $is_tax ? 'required' : ''; ?> style="<?php echo ! $is_tax ? 'display:none' : ''; ?>">
                                    <option value=""><?php esc_html_e('— Sélectionner une taxonomie —', 'local-seo-bulk'); ?></option>
                                    <?php foreach ($index['taxonomies'] as $tx) : ?>
                                        <option value="<?php echo esc_attr($tx['slug']); ?>" <?php selected($is_tax ? $slug : '', $tx['slug']); ?>><?php echo esc_html($tx['label'] . ' (' . $tx['slug'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Filtre', 'local-seo-bulk'); ?></th>
                            <td>
                                <label><input type="radio" name="filter_type" value="all" <?php checked($filter['type'], 'all'); ?>> <?php esc_html_e('Tous', 'local-seo-bulk'); ?></label><br>
                                <label><input type="radio" name="filter_type" value="parents" <?php checked($filter['type'], 'parents'); ?>> <?php esc_html_e('Parents (post_parent=0)', 'local-seo-bulk'); ?></label><br>
                                <label><input type="radio" name="filter_type" value="children" <?php checked($filter['type'], 'children'); ?>> <?php esc_html_e('Enfants (post_parent>0)', 'local-seo-bulk'); ?></label><br>
                                <label><input type="radio" name="filter_type" value="custom_meta" <?php checked($filter['type'], 'custom_meta'); ?>> <?php esc_html_e('Règle meta custom', 'local-seo-bulk'); ?></label>
                                <div style="margin-top:.5em;">
                                    <input type="text" name="meta_key" value="<?php echo esc_attr($filter['meta_key']); ?>" placeholder="meta_key" style="width:200px">
                                    <input type="text" name="meta_value" value="<?php echo esc_attr($filter['meta_value']); ?>" placeholder="valeur attendue" style="width:250px">
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Remplacement H1', 'local-seo-bulk'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="replace_h1" value="1" <?php checked($replace_h1); ?>>
                                    <?php esc_html_e('Forcer le remplacement automatique du H1', 'local-seo-bulk'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Si décoché, le H1 du template ne sera pas remplacé. Vous devrez insérer manuellement [lsb_h1] dans vos templates pour l\'afficher.', 'local-seo-bulk'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div style="display:flex;gap:.5em;align-items:center;margin-top:1em">
                        <?php submit_button(null, 'primary', 'submit', false); ?>
                        <a href="<?php echo esc_url(network_admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" class="button"><?php esc_html_e('Annuler', 'local-seo-bulk'); ?></a>
                    </div>
                </form>
            </div>
        </div>

        <script>
            (function() {
                var radios = document.querySelectorAll('input[name="object_kind"]');
                var ptSel = document.getElementById('lsb-pt-select');
                var taxSel = document.getElementById('lsb-tax-select');
                var kindLbl = document.getElementById('lsb-kind-label');

                function switchKind(kind) {
                    var isTax = kind === 'taxonomy';
                    ptSel.disabled = isTax;
                    ptSel.style.display = isTax ? 'none' : '';
                    taxSel.disabled = !isTax;
                    taxSel.style.display = isTax ? '' : 'none';
                    kindLbl.textContent = isTax ? '<?php echo esc_js(__('Taxonomie', 'local-seo-bulk')); ?>' : '<?php echo esc_js(__('Custom Post Type', 'local-seo-bulk')); ?>';
                }

                radios.forEach(function(r) {
                    r.addEventListener('change', function() {
                        switchKind(this.value);
                    });
                });
            }());
        </script>
<?php
    }

    public function handle_save_scope() {
        if (! current_user_can('manage_network_options')) wp_die('Forbidden');
        check_admin_referer('lsb_save_scope');

        $scope_id    = sanitize_key($_POST['scope_id']    ?? '');
        $original_id = sanitize_key($_POST['original_id'] ?? '');
        if (! $scope_id) wp_die('Invalid scope id');

        $config = [
            'label'       => sanitize_text_field($_POST['label'] ?? ''),
            'object_kind' => sanitize_key($_POST['object_kind'] ?? 'post_type'),
            'slug'        => sanitize_key($_POST['slug']        ?? ''),
            'replace_h1'  => ! empty($_POST['replace_h1']),
            'filter'      => [
                'type'       => sanitize_key($_POST['filter_type'] ?? 'all'),
                'meta_key'   => sanitize_key($_POST['meta_key'] ?? ''),
                'meta_value' => sanitize_text_field($_POST['meta_value'] ?? ''),
            ],
        ];

        if ($original_id && $original_id !== $scope_id) {
            $this->store->delete_scope($original_id);
        }
        $this->store->save_scope($scope_id, $config);

        // Flush entity index so editor picks up changes.
        delete_site_transient(LSB_Network_Entity_Index::TRANSIENT);

        wp_safe_redirect(network_admin_url('admin.php?page=' . self::PAGE_SLUG . '&saved=1'));
        exit;
    }

    public function handle_bulk_delete_scopes() {
        if (! current_user_can('manage_network_options')) wp_die('Forbidden');
        check_admin_referer('lsb_bulk_delete_scopes');

        $action    = sanitize_key($_POST['bulk_action'] ?? '-1');
        $scope_ids = array_map('sanitize_key', (array) ($_POST['scope_ids'] ?? []));

        if ('delete' === $action && ! empty($scope_ids)) {
            foreach ($scope_ids as $scope_id) {
                $this->store->delete_scope($scope_id);
            }
            delete_site_transient(LSB_Network_Entity_Index::TRANSIENT);
        }

        wp_safe_redirect(network_admin_url('admin.php?page=' . self::PAGE_SLUG . '&bulk_deleted=1'));
        exit;
    }

    public function handle_delete_scope() {
        if (! current_user_can('manage_network_options')) wp_die('Forbidden');
        $scope_id = sanitize_key($_GET['scope_id'] ?? '');
        check_admin_referer('lsb_delete_scope_' . $scope_id);
        $this->store->delete_scope($scope_id);
        delete_site_transient(LSB_Network_Entity_Index::TRANSIENT);
        wp_safe_redirect(network_admin_url('admin.php?page=' . self::PAGE_SLUG . '&deleted=1'));
        exit;
    }

    public function handle_refresh_cpt_index() {
        if (! current_user_can('manage_network_options')) wp_die('Forbidden');
        check_admin_referer('lsb_refresh_cpt_index');
        $this->cpt_index->flush();
        $this->cpt_index->get_index(true);
        wp_safe_redirect(network_admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }
}
