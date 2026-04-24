<?php
/**
 * @package LocalSeoBulk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LSB_List_Table extends WP_List_Table {

	private $field;
	private $meta_store;
	private $token_resolver;
	private $resolver;
	private $scope_id;
	private $all_entities = [];
	private $top_nav_rendered   = false;
	private $rendering_top_nav = false;

	public function __construct(
		$field,
		LSB_Meta_Store $meta_store,
		LSB_Token_Resolver $token_resolver,
		LSB_Resolver $resolver = null,
		$scope_id = ''
	) {
		parent::__construct( [
			'singular' => 'lsb_item',
			'plural'   => 'lsb_items',
			'ajax'     => false,
		] );

		$this->field          = $field;
		$this->meta_store     = $meta_store;
		$this->token_resolver = $token_resolver;
		$this->resolver       = $resolver;
		$this->scope_id       = $scope_id;
	}

	public function get_columns() {
		return [
			'cb'              => '<input type="checkbox">',
			'entity_title'    => __( 'Titre', 'local-seo-bulk' ),
			'entity_slug'     => __( 'Slug', 'local-seo-bulk' ),
			'entity_type'     => __( 'Type', 'local-seo-bulk' ),
			'current_value'   => __( 'Valeur actuelle', 'local-seo-bulk' ),
			'network_pattern' => __( 'Pattern réseau effectif', 'local-seo-bulk' ),
			'local_value'     => __( 'Valeur locale', 'local-seo-bulk' ),
			'actions'         => __( 'Action', 'local-seo-bulk' ),
		];
	}

	public function get_bulk_actions() {
		return [ 'lsb_bulk_clear' => __( 'Vider', 'local-seo-bulk' ) ];
	}

	public function column_cb( $item ) {
		$entity = $item['entity'];
		return sprintf(
			'<input type="checkbox" name="lsb_item[]" value="%s:%d">',
			esc_attr( $entity['type'] ),
			(int) $entity['id']
		);
	}

	public function get_sortable_columns() { return []; }

	/** Renders just the bulk action select + Apply button (flex item, LEFT of tabs). */
	public function render_bulk_bar() {
		if ( empty( $this->get_bulk_actions() ) ) return;
		echo '<div class="alignleft actions bulkactions lsb-bulk-bar">';
		$this->bulk_actions( 'top' );
		echo '</div>';
	}

	/** Renders just the pagination (flex item, RIGHT of tabs). */
	public function render_pag_bar() {
		$this->pagination( 'top' );
	}

	/** Prevents display() from re-rendering the top tablenav. */
	public function mark_top_rendered() {
		$this->top_nav_rendered = true;
	}

	protected function display_tablenav( $which ) {
		if ( 'top' === $which && $this->top_nav_rendered ) {
			return;
		}
		parent::display_tablenav( $which );
	}

	public function set_entities( $entities ) {
		$this->all_entities = $entities;
	}

	public function prepare_items() {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
		$per_page     = (int) get_user_meta( get_current_user_id(), 'lsb_items_per_page', true );
		if ( $per_page < 1 ) $per_page = 50;
		$current_page = $this->get_pagenum();
		$total        = count( $this->all_entities );
		$this->set_pagination_args( [ 'total_items' => $total, 'per_page' => $per_page ] );
		$this->items = array_slice( $this->all_entities, ( $current_page - 1 ) * $per_page, $per_page );
	}

	public function column_default( $item, $column_name ) { return ''; }

	public function column_entity_title( $item ) {
		return sprintf(
			'<a href="%s"><strong>%s</strong></a><br><span class="row-actions"><a href="%s">%s</a> | <a href="%s" target="_blank">%s</a></span>',
			esc_url( $item['edit_url'] ),
			esc_html( $item['title'] ),
			esc_url( $item['edit_url'] ),
			esc_html__( 'Modifier', 'local-seo-bulk' ),
			esc_url( $item['url'] ),
			esc_html__( 'Voir', 'local-seo-bulk' )
		);
	}

	public function column_entity_slug( $item ) {
		$full_url = $item['url'] ?? '';
		if ( ! $full_url ) {
			return '<span style="color:#999">—</span>';
		}
		$path = '/' . ltrim( (string) wp_parse_url( $full_url, PHP_URL_PATH ), '/' );
		return sprintf(
			'<a href="%s" target="_blank" class="lsb-slug-link"><code>%s</code></a>',
			esc_url( $full_url ),
			esc_html( $path )
		);
	}

	public function column_entity_type( $item ) {
		return esc_html( $item['type_label'] );
	}

	public function column_current_value( $item ) {
		return '<span class="lsb-current-value">' . esc_html( $item['current_value'] ) . '</span>';
	}

	public function column_network_pattern( $item ) {
		$raw  = $item['network_pattern'] ?? '';
		$tier = $item['network_tier'] ?? 0;
		if ( '' === $raw ) return '<span class="lsb-current-value" style="color:#999">—</span>';
		return '<span class="lsb-current-value">' . esc_html( $raw ) . '</span>';
	}

	public function column_local_value( $item ) {
		$entity = $item['entity'];
		$html   = '';

		foreach ( [ 'h1', 'title', 'desc' ] as $fk ) {
			$saved    = $this->meta_store->get( $entity, $fk ) ?: '';
			$id_attr  = 'lsb-input-' . $entity['type'] . '-' . $entity['id'] . '-' . $fk;
			$resolved = $saved ? esc_html( $this->token_resolver->resolve( $saved ) ) : '';
			$hidden   = $fk !== $this->field ? ' style="display:none"' : '';

			if ( 'desc' === $fk ) {
				$input = sprintf(
					'<textarea id="%s" class="lsb-value-input" rows="2" data-entity-type="%s" data-entity-id="%d" data-field="%s">%s</textarea>',
					esc_attr( $id_attr ),
					esc_attr( $entity['type'] ),
					(int) $entity['id'],
					esc_attr( $fk ),
					esc_textarea( $saved )
				);
			} else {
				$input = sprintf(
					'<input type="text" id="%s" class="lsb-value-input" value="%s" data-entity-type="%s" data-entity-id="%d" data-field="%s">',
					esc_attr( $id_attr ),
					esc_attr( $saved ),
					esc_attr( $entity['type'] ),
					(int) $entity['id'],
					esc_attr( $fk )
				);
			}

			$html .= '<div class="lsb-field-panel" data-field="' . esc_attr( $fk ) . '"' . $hidden . '>';
			$html .= $input;
			if ( $resolved ) $html .= '<div class="lsb-preview">' . $resolved . '</div>';
			$html .= '</div>';
		}

		return $html;
	}

	public function column_actions( $item ) {
		$entity   = $item['entity'];
		$save_btn = sprintf(
			'<button type="button" class="button lsb-save-row" data-entity-type="%s" data-entity-id="%d">%s</button>',
			esc_attr( $entity['type'] ),
			(int) $entity['id'],
			esc_html__( 'Enregistrer', 'local-seo-bulk' )
		);
		$clear_btn = sprintf(
			'<button type="button" class="button-link lsb-clear-row" data-entity-type="%s" data-entity-id="%d" data-field="%s">%s</button>',
			esc_attr( $entity['type'] ),
			(int) $entity['id'],
			esc_attr( $this->field ),
			esc_html__( 'Vider', 'local-seo-bulk' )
		);
		return '<div class="lsb-actions-wrap">' . $save_btn . ' ' . $clear_btn . '<span class="lsb-row-status"></span></div>';
	}
}
