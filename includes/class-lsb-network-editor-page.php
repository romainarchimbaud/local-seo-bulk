<?php

/**
 * Network admin page: editor — per-slug network entity values.
 *
 * @package LocalSeoBulk
 */

if (! defined('ABSPATH')) exit;

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
        add_action('network_admin_menu', [$this, 'register_menu']);
        add_filter('set_screen_option_lsb_items_per_page', [$this, 'save_screen_option'], 10, 3);
    }

    public function register_menu() {
        $hook = add_submenu_page(
            LSB_Network_Scope_Page::PAGE_SLUG,
            __('Éditeur réseau', 'local-seo-bulk'),
            __('Éditeur', 'local-seo-bulk'),
            'manage_network_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
        add_action('load-' . $hook, [$this, 'add_screen_options']);
    }

    public function add_screen_options() {
        add_screen_option('per_page', [
            'label'   => __('Éléments par page', 'local-seo-bulk'),
            'default' => 50,
            'option'  => 'lsb_items_per_page',
        ]);
    }

    public function save_screen_option($screen_option, $option, $value) {
        return (int) $value;
    }

    public function render_page() {
        if (! current_user_can('manage_network_options')) return;

        $scopes = $this->store->get_scopes();

        if (empty($scopes)) {
            echo '<div class="wrap"><h1>' . esc_html__('Éditeur réseau', 'local-seo-bulk') . '</h1>';
            echo '<p>' . wp_kses_post(sprintf(
                /* translators: link to scopes page */
                __('Aucun scope configuré. <a href="%s">Créer un scope</a> d\'abord.', 'local-seo-bulk'),
                esc_url(network_admin_url('admin.php?page=' . LSB_Network_Scope_Page::PAGE_SLUG . '&action=add'))
            )) . '</p></div>';
            return;
        }

        $active_scope = isset($_GET['lsb_scope']) ? sanitize_key($_GET['lsb_scope']) : '';
        if (! $active_scope || ! isset($scopes[$active_scope])) {
            reset($scopes);
            $active_scope = key($scopes);
        }
        $active_field = isset($_GET['lsb_tab']) ? sanitize_key($_GET['lsb_tab']) : 'h1';
        if (! in_array($active_field, ['h1', 'title', 'desc'], true)) $active_field = 'h1';

        $index    = $this->entity_index->get_index();
        $base_url = network_admin_url('admin.php?page=' . self::PAGE_SLUG);

        wp_enqueue_script('lsb-admin');
        wp_enqueue_style('lsb-admin');
?>
        <div class="wrap lsb-editor-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Éditeur SEO réseau', 'local-seo-bulk'); ?>
                <a href="<?php echo esc_url(add_query_arg(['action' => 'lsb_refresh_entity_index', '_wpnonce' => wp_create_nonce('lsb_refresh_entity_index')], admin_url('admin-post.php'))); ?>" class="page-title-action"><?php esc_html_e('Rafraîchir l\'index', 'local-seo-bulk'); ?></a>
            </h1>
            <hr class="wp-header-end">

            <div id="lsb-network-import-dialog" style="display:none;margin:1em 0;padding:1em;border:1px solid #ccd0d4;background:#fff">
                <h3 style="margin-top:0"><?php esc_html_e('Importer des valeurs réseau depuis un CSV', 'local-seo-bulk'); ?></h3>
                <p class="description"><?php esc_html_e('Colonnes attendues : scope_id, slug, h1, title, desc. Les colonnes vides sont ignorées.', 'local-seo-bulk'); ?>
                    <a href="<?php echo esc_url(add_query_arg([
                                    'action' => 'lsb_network_csv_template',
                                    'nonce'  => wp_create_nonce('lsb_ajax_nonce'),
                                ], admin_url('admin-ajax.php'))); ?>">
                        <?php esc_html_e('Télécharger le modèle CSV', 'local-seo-bulk'); ?>
                    </a>
                </p>
                <input type="file" id="lsb-network-csv-file" accept=".csv" style="display:block;margin:1.5em 0.5em;">
                <input type="hidden" id="lsb-network-import-nonce" value="<?php echo esc_attr(wp_create_nonce('lsb_ajax_nonce')); ?>">
                <button type="button" class="button button-primary" id="lsb-do-network-import"><?php esc_html_e('Importer', 'local-seo-bulk'); ?></button>
                <button type="button" class="button" id="lsb-close-network-import"><?php esc_html_e('Annuler', 'local-seo-bulk'); ?></button>
                <p id="lsb-network-import-result" style="margin-top:.5em"></p>
            </div>

            <script>
                (function($) {
                    $(function() {
                        $('#lsb-open-network-import').on('click', function() {
                            $('#lsb-network-import-dialog').toggle();
                        });
                        $('#lsb-close-network-import').on('click', function() {
                            $('#lsb-network-import-dialog').hide();
                        });
                        $('#lsb-do-network-import').on('click', function() {
                            var file = $('#lsb-network-csv-file')[0].files[0];
                            if (!file) {
                                alert(<?php echo json_encode(__('Veuillez sélectionner un fichier CSV.', 'local-seo-bulk')); ?>);
                                return;
                            }
                            var fd = new FormData();
                            fd.append('action', 'lsb_import_network_csv');
                            fd.append('nonce', $('#lsb-network-import-nonce').val());
                            fd.append('lsb_csv', file);
                            $('#lsb-do-network-import').prop('disabled', true);
                            $.ajax({
                                url: <?php echo json_encode(admin_url('admin-ajax.php')); ?>,
                                type: 'POST',
                                data: fd,
                                processData: false,
                                contentType: false,
                            }).done(function(resp) {
                                if (resp.success) {
                                    $('#lsb-network-import-result').text(
                                        <?php echo json_encode(__('Importé : ', 'local-seo-bulk')); ?> + resp.data.imported +
                                        <?php echo json_encode(__(' — Ignoré : ', 'local-seo-bulk')); ?> + resp.data.skipped
                                    );
                                    var missed = 0;
                                    $.each(resp.data.rows || [], function(_, row) {
                                        $.each(row.fields, function(field, val) {
                                            if (val === '') return;
                                            var $input = $('.lsb-network-input[data-scope="' + row.scope_id + '"][data-slug="' + row.slug + '"][data-field="' + field + '"]');
                                            if ($input.length) {
                                                $input.val(val).data('initial-value', val);
                                                $input.closest('tr').removeClass('lsb-dirty');
                                            } else {
                                                missed++;
                                            }
                                        });
                                    });
                                    if (missed > 0) {
                                        setTimeout(function() {
                                            location.reload();
                                        }, 600);
                                    }
                                } else {
                                    $('#lsb-network-import-result').text(resp.data.message || <?php echo json_encode(__('Erreur.', 'local-seo-bulk')); ?>);
                                }
                            }).fail(function() {
                                $('#lsb-network-import-result').text(<?php echo json_encode(__('Erreur réseau.', 'local-seo-bulk')); ?>);
                            }).always(function() {
                                $('#lsb-do-network-import').prop('disabled', false);
                            });
                        });
                    }); // document ready
                })(jQuery);
            </script>

            <?php if (isset($_GET['index_refreshed'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Index des entités rafraîchi.', 'local-seo-bulk'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Scope tabs bar: tabs left, save button right -->
            <div class="lsb-tabs-bar lsb-scope-tabs-bar">
                <nav class="nav-tab-wrapper lsb-tab-header">
                    <?php foreach ($scopes as $scope_id => $scope) : ?>
                        <a href="<?php echo esc_url(add_query_arg(['lsb_scope' => $scope_id, 'lsb_tab' => $active_field], $base_url)); ?>"
                            class="nav-tab <?php echo $scope_id === $active_scope ? 'nav-tab-active' : ''; ?>">
                            <?php echo esc_html($scope['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="lsb-scope-actions">
                    <button type="button" class="button" id="lsb-open-network-import"><?php esc_html_e('Importer CSV', 'local-seo-bulk'); ?></button>
                    <button type="button" class="button button-primary" id="lsb-save-all"><?php esc_html_e('Tout enregistrer', 'local-seo-bulk'); ?></button>
                    <span class="lsb-dirty-count" id="lsb-dirty-count"></span>
                </div>
            </div>

            <?php
            $all_rows     = isset($index[$active_scope]) ? $index[$active_scope] : [];
            $scope_config = $scopes[$active_scope];
            $field_labels = ['h1' => 'H1', 'title' => 'Meta title', 'desc' => 'Meta description'];

            $per_page     = (int) get_user_meta(get_current_user_id(), 'lsb_items_per_page', true);
            if ($per_page < 1) $per_page = 50;
            $total_rows   = count($all_rows);
            $total_pages  = max(1, (int) ceil($total_rows / $per_page));
            $current_page = max(1, min($total_pages, (int) ($_GET['paged'] ?? 1)));
            $rows         = array_slice($all_rows, ($current_page - 1) * $per_page, $per_page, true);
            ?>

            <form id="lsb-editor-form" method="post">
                <?php wp_nonce_field('lsb_ajax_nonce', 'lsb_nonce'); ?>

                <!-- Bulk left | Field tabs center | Pagination right -->
                <div class="lsb-tabs-bar">
                    <div class="alignleft actions bulkactions lsb-bulk-bar">
                        <label for="lsb-bulk-action-net" class="screen-reader-text"><?php esc_html_e('Sélectionner une action groupée', 'local-seo-bulk'); ?></label>
                        <select id="lsb-bulk-action-net">
                            <option value="-1"><?php esc_html_e('Actions groupées', 'local-seo-bulk'); ?></option>
                            <option value="lsb_bulk_clear"><?php esc_html_e('Vider', 'local-seo-bulk'); ?></option>
                        </select>
                        <input type="button" class="button action" id="lsb-bulk-apply-net" value="<?php esc_attr_e('Appliquer', 'local-seo-bulk'); ?>">
                    </div>
                    <nav class="nav-tab-wrapper lsb-tab-header">
                        <?php foreach ($field_labels as $fk => $fl) : ?>
                            <a href="<?php echo esc_url(add_query_arg(['lsb_scope' => $active_scope, 'lsb_tab' => $fk], $base_url)); ?>"
                                class="nav-tab lsb-field-tab <?php echo $fk === $active_field ? 'nav-tab-active' : ''; ?>"
                                data-field="<?php echo esc_attr($fk); ?>">
                                <?php echo esc_html($fl); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <div class="tablenav top">
                        <?php $this->render_pagination($current_page, $total_pages, $total_rows, $active_scope, $active_field); ?>
                        <br class="clear">
                    </div>
                </div>

                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column">
                                <input id="cb-select-all-net" type="checkbox">
                            </td>
                            <th><?php esc_html_e('Titre (exemple)', 'local-seo-bulk'); ?></th>
                            <th><?php esc_html_e('Slug', 'local-seo-bulk'); ?></th>
                            <th style="width:60px"><?php esc_html_e('Sites', 'local-seo-bulk'); ?></th>
                            <th style="min-width:480px"><?php esc_html_e('Valeur réseau', 'local-seo-bulk'); ?></th>
                            <th><?php esc_html_e('Actions', 'local-seo-bulk'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)) : ?>
                            <tr>
                                <td colspan="6"><?php esc_html_e('Aucune entité dans l\'index. Cliquez sur « Rafraîchir l\'index ».', 'local-seo-bulk'); ?></td>
                            </tr>
                            <?php else : foreach ($rows as $slug => $row) : ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" class="lsb-net-cb" name="lsb_net_item[]" value="<?php echo esc_attr($slug); ?>">
                                    </th>
                                    <td>
                                        <strong><?php echo esc_html($row['sample_title']); ?></strong>
                                    </td>
                                    <td>
                                        <code style="font-size:.85em;color:#666"><?php echo esc_html($slug); ?></code>
                                    </td>
                                    <td style="color:#999;font-size:.85em;white-space:nowrap">
                                        <?php echo count($row['sites']); ?> sites
                                    </td>
                                    <td>
                                        <?php foreach ($field_labels as $fk => $fl) :
                                            $fval     = $this->store->get_entity_value($active_scope, $slug, $fk);
                                            $input_id = 'lsb-net-' . esc_attr($active_scope) . '-' . esc_attr($slug) . '-' . esc_attr($fk);
                                            $hidden   = $fk !== $active_field ? ' style="display:none"' : '';
                                        ?>
                                            <div class="lsb-field-panel" data-field="<?php echo esc_attr($fk); ?>" <?php echo $hidden; ?>>
                                                <?php if ('desc' === $fk) : ?>
                                                    <textarea id="<?php echo esc_attr($input_id); ?>"
                                                        class="lsb-value-input lsb-network-input"
                                                        rows="2"
                                                        style="width:100%"
                                                        data-scope="<?php echo esc_attr($active_scope); ?>"
                                                        data-slug="<?php echo esc_attr($slug); ?>"
                                                        data-field="<?php echo esc_attr($fk); ?>"><?php echo esc_textarea($fval); ?></textarea>
                                                <?php else : ?>
                                                    <input type="text"
                                                        id="<?php echo esc_attr($input_id); ?>"
                                                        class="lsb-value-input lsb-network-input"
                                                        style="width:100%"
                                                        value="<?php echo esc_attr($fval); ?>"
                                                        data-scope="<?php echo esc_attr($active_scope); ?>"
                                                        data-slug="<?php echo esc_attr($slug); ?>"
                                                        data-field="<?php echo esc_attr($fk); ?>">
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <div class="lsb-actions-wrap">
                                            <button type="button" class="button lsb-save-network-row"
                                                data-scope="<?php echo esc_attr($active_scope); ?>"
                                                data-slug="<?php echo esc_attr($slug); ?>">
                                                <?php esc_html_e('Enregistrer', 'local-seo-bulk'); ?>
                                            </button>
                                            <button type="button" class="button-link lsb-clear-network-row"
                                                data-scope="<?php echo esc_attr($active_scope); ?>"
                                                data-slug="<?php echo esc_attr($slug); ?>"
                                                data-field="<?php echo esc_attr($active_field); ?>">
                                                <?php esc_html_e('Vider', 'local-seo-bulk'); ?>
                                            </button>
                                            <span class="lsb-row-status"></span>
                                        </div>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
<?php
    }

    private function render_pagination($current_page, $total_pages, $total_rows, $active_scope, $active_field) {
        $base  = network_admin_url('admin.php?page=' . self::PAGE_SLUG . '&lsb_scope=' . $active_scope . '&lsb_tab=' . $active_field);
        $class = $total_pages <= 1 ? ' one-page' : '';
        echo '<div class="tablenav-pages' . $class . '">';
        echo '<span class="displaying-num">' . esc_html(sprintf(_n('%d entité', '%d entités', $total_rows, 'local-seo-bulk'), $total_rows)) . '</span>';

        if ($total_pages > 1) {
            $links = [];

            if (1 === $current_page) {
                $links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
                $links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
            } else {
                $links[] = sprintf(
                    '<a class="first-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&laquo;</span></a>',
                    esc_url($base),
                    __('First page')
                );
                $links[] = sprintf(
                    '<a class="prev-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&lsaquo;</span></a>',
                    esc_url(add_query_arg('paged', max(1, $current_page - 1), $base)),
                    __('Previous page')
                );
            }

            $links[] = sprintf(
                '<span class="paging-input"><label for="current-page-selector" class="screen-reader-text">%s</label><input class="current-page" id="current-page-selector" type="text" name="paged" value="%d" size="%d" aria-describedby="table-paging"><span class="tablenav-paging-text"> %s <span class="total-pages">%d</span></span></span>',
                __('Current Page'),
                $current_page,
                strlen((string) $total_pages),
                _x('of', 'paging'),
                $total_pages
            );

            if ($current_page === $total_pages) {
                $links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
                $links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
            } else {
                $links[] = sprintf(
                    '<a class="next-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&rsaquo;</span></a>',
                    esc_url(add_query_arg('paged', min($total_pages, $current_page + 1), $base)),
                    __('Next page')
                );
                $links[] = sprintf(
                    '<a class="last-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&raquo;</span></a>',
                    esc_url(add_query_arg('paged', $total_pages, $base)),
                    __('Last page')
                );
            }

            echo '<span class="pagination-links">' . implode("\n", $links) . '</span>';
        }

        echo '</div>';
    }
}
