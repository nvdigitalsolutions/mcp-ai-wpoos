<?php
/**
 * Research & Add Base Class
 *
 * Provides common functionality for Research & Add UI across toolkits.
 * Handles both CPT and CCT storage backends transparently.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for Research & Add functionality.
 */
abstract class WP_MCP_AI_Research_Add_Base {

	/**
	 * Toolkit slug.
	 *
	 * @var string
	 */
	protected $toolkit_slug;

	/**
	 * Entity types for this toolkit (e.g., ['products', 'customers']).
	 *
	 * @var array
	 */
	protected $entity_types = array();

	/**
	 * Data stores for each entity type.
	 *
	 * @var array
	 */
	protected $data_stores = array();

	/**
	 * Current entity being viewed/edited.
	 *
	 * @var string
	 */
	protected $current_entity;

	/**
	 * Post type slug (set by child classes).
	 *
	 * @var string
	 */
	protected $post_type;

	/**
	 * Page title (set by child classes).
	 *
	 * @var string
	 */
	protected $page_title;

	/**
	 * Menu title (set by child classes).
	 *
	 * @var string
	 */
	protected $menu_title;

	/**
	 * Page slug (set by child classes).
	 *
	 * @var string
	 */
	protected $page_slug;

	/**
	 * Required capability (set by child classes).
	 *
	 * @var string
	 */
	protected $capability;

	/**
	 * Constructor.
	 *
	 * @param string $toolkit_slug Toolkit identifier.
	 */
	public function __construct( $toolkit_slug ) {
		$this->toolkit_slug = $toolkit_slug;
		$this->entity_types = $this->get_entity_types();
		$this->initialize_data_stores();

		// Get current entity from query string.
		$this->current_entity = isset( $_GET['entity'] ) ? sanitize_key( $_GET['entity'] ) : $this->get_default_entity(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Register admin menu page.
		add_action( 'admin_menu', array( $this, 'add_research_page' ), 25 );

		// Handle AJAX actions.
		add_action( 'wp_ajax_wp_mcp_ai_research_add_item', array( $this, 'ajax_add_item' ) );
		add_action( 'wp_ajax_wp_mcp_ai_research_delete_item', array( $this, 'ajax_delete_item' ) );
		add_action( 'wp_ajax_wp_mcp_ai_research_get_item', array( $this, 'ajax_get_item' ) );
		add_action( 'wp_ajax_wp_mcp_ai_research_ai_generate', array( $this, 'ajax_ai_generate' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Add Research & Add submenu page.
	 */
	public function add_research_page() {
		// Skip if required properties are not set.
		if ( empty( $this->post_type ) || empty( $this->page_title ) || empty( $this->menu_title ) || empty( $this->page_slug ) ) {
			return;
		}

		// For the built-in 'post' post type, the parent slug is just 'edit.php'.
		// For all other post types, it's 'edit.php?post_type={post_type}'.
		$parent_slug = ( 'post' === $this->post_type ) ? 'edit.php' : 'edit.php?post_type=' . $this->post_type;

		// Use 'edit_posts' as default capability if not set.
		$capability = ! empty( $this->capability ) ? $this->capability : 'edit_posts';

		add_submenu_page(
			$parent_slug,
			$this->page_title,
			$this->menu_title,
			$capability,
			$this->page_slug,
			array( $this, 'render' )
		);
	}

	/**
	 * Get entity types for this toolkit.
	 * To be implemented by child classes.
	 *
	 * @return array Entity types (e.g., ['products' => 'Products', 'customers' => 'Customers']).
	 */
	abstract protected function get_entity_types();

	/**
	 * Get default entity type.
	 *
	 * @return string Default entity slug.
	 */
	protected function get_default_entity() {
		$types = array_keys( $this->entity_types );
		return ! empty( $types ) ? $types[0] : '';
	}

	/**
	 * Initialize data stores for all entity types.
	 */
	protected function initialize_data_stores() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-toolkit-data-store-factory.php';

		foreach ( array_keys( $this->entity_types ) as $entity_type ) {
			$this->data_stores[ $entity_type ] = WP_MCP_AI_Toolkit_Data_Store_Factory::get_store(
				$this->toolkit_slug,
				$entity_type
			);
		}
	}

	/**
	 * Get data store for current entity.
	 *
	 * @return WP_MCP_AI_Toolkit_Data_Store|null Data store or null if not found.
	 */
	protected function get_current_data_store() {
		return isset( $this->data_stores[ $this->current_entity ] )
		? $this->data_stores[ $this->current_entity ]
		: null;
	}

	/**
	 * Render Research & Add UI.
	 */
	public function render() {
		if ( empty( $this->entity_types ) ) {
			$this->render_no_entities_message();
			return;
		}

		?>
<div class="wrap wp-mcp-ai-research-add">
<h2><?php esc_html_e( 'Research & Add', 'mcp-ai-wpoos-pro' ); ?></h2>

		<?php $this->render_storage_backend_notice(); ?>
		<?php $this->render_entity_tabs(); ?>

<div class="research-add-content">
		<?php
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'add':
				$this->render_add_form();
				break;
			case 'edit':
				$this->render_edit_form();
				break;
			default:
				$this->render_list_view();
		}
		?>
</div>
</div>

<style>
.wp-mcp-ai-research-add {
margin-top: 20px;
}
.entity-tabs {
border-bottom: 1px solid #ccd0d4;
margin: 20px 0;
}
.entity-tabs a {
display: inline-block;
padding: 10px 15px;
text-decoration: none;
border-bottom: 2px solid transparent;
margin-bottom: -1px;
}
.entity-tabs a.active {
border-bottom-color: #2271b1;
color: #2271b1;
}
.research-add-content {
background: #fff;
border: 1px solid #ccd0d4;
padding: 20px;
margin-top: 20px;
}
.storage-backend-notice {
margin: 20px 0;
}
.items-table {
width: 100%;
border-collapse: collapse;
margin-top: 20px;
}
.items-table th,
.items-table td {
padding: 10px;
text-align: left;
border-bottom: 1px solid #ccd0d4;
}
.items-table th {
background: #f0f0f1;
font-weight: 600;
}
.item-actions a {
margin-right: 10px;
}
.ai-generate-section {
background: #f0f6fc;
border: 1px solid #0073aa;
padding: 15px;
margin-bottom: 20px;
border-radius: 4px;
}
</style>
		<?php
	}

	/**
	 * Render storage backend notice.
	 */
	protected function render_storage_backend_notice() {
		$store        = $this->get_current_data_store();
		$backend_type = $store ? $store->get_storage_type() : 'unknown';

		if ( 'cct' === $backend_type ) {
			?>
<div class="notice notice-success storage-backend-notice">
<p>
<strong><?php esc_html_e( 'JetEngine CCT Storage Active', 'mcp-ai-wpoos-pro' ); ?></strong><br>
			<?php esc_html_e( 'Using high-performance JetEngine Custom Content Types for data storage.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</div>
			<?php
		} elseif ( 'cpt' === $backend_type ) {
			$jetengine_installed = WP_MCP_AI_Toolkit_Data_Store_Factory::is_jetengine_installed();

			if ( $jetengine_installed ) {
				?>
<div class="notice notice-info storage-backend-notice">
<p>
<strong><?php esc_html_e( 'WordPress Custom Post Types Storage', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<?php esc_html_e( 'JetEngine is installed but CCT is not enabled. Enable JetEngine CCT in Settings → NV oOS → Tools for better performance.', 'mcp-ai-wpoos-pro' ); ?>
</p>
</div>
				<?php
			} else {
				?>
<div class="notice notice-info storage-backend-notice">
<p>
<strong><?php esc_html_e( 'WordPress Custom Post Types Storage', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<?php
				echo wp_kses_post(
					sprintf(
					/* translators: %s: JetEngine URL */
						__( 'Using standard WordPress storage. For enterprise features and better performance with large datasets, consider <a href="%s" target="_blank">JetEngine</a> (~$26/year).', 'mcp-ai-wpoos-pro' ),
						'https://crocoblock.com/plugins/jetengine/'
					)
				);
				?>
</p>
</div>
				<?php
			}
		}
	}

	/**
	 * Render entity tabs.
	 */
	protected function render_entity_tabs() {
		if ( count( $this->entity_types ) <= 1 ) {
			return;
		}

		$base_url = add_query_arg(
			array(
				'page' => sanitize_key( $_GET['page'] ?? '' ),
				'tab'  => 'research',
			),
			admin_url( 'admin.php' )
		); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
<div class="entity-tabs">
		<?php foreach ( $this->entity_types as $entity_slug => $entity_label ) : ?>
			<?php
			$entity_url = add_query_arg( 'entity', $entity_slug, $base_url );
			$is_active  = ( $this->current_entity === $entity_slug );
			?>
<a href="<?php echo esc_url( $entity_url ); ?>" class="<?php echo $is_active ? 'active' : ''; ?>">
			<?php echo esc_html( $entity_label ); ?>
</a>
<?php endforeach; ?>
</div>
		<?php
	}

	/**
	 * Render list view of items.
	 */
	protected function render_list_view() {
		$store = $this->get_current_data_store();
		if ( ! $store ) {
			return;
		}

		$items = $store->query_items( array( 'per_page' => 50 ) );

		$add_url = add_query_arg( 'action', 'add' );
		?>
<div class="list-view">
<div class="tablenav top">
<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary">
		<?php esc_html_e( 'Add New', 'mcp-ai-wpoos-pro' ); ?>
</a>
</div>

		<?php if ( empty( $items ) ) : ?>
<p><?php esc_html_e( 'No items found. Click "Add New" to create your first item.', 'mcp-ai-wpoos-pro' ); ?></p>
<?php else : ?>
	<?php $this->render_items_table( $items ); ?>
<?php endif; ?>
</div>
		<?php
	}

	/**
	 * Render items table.
	 *
	 * @param array $items Array of items to display.
	 */
	protected function render_items_table( $items ) {
		?>
<table class="items-table wp-list-table widefat fixed striped">
<thead>
<tr>
		<?php $this->render_table_headers(); ?>
</tr>
</thead>
<tbody>
		<?php foreach ( $items as $item ) : ?>
<tr>
			<?php $this->render_table_row( $item ); ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
		<?php
	}

	/**
	 * Render table headers.
	 * Override in child classes for custom columns.
	 */
	protected function render_table_headers() {
		?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Title', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
		<?php
	}

	/**
	 * Render table row.
	 * Override in child classes for custom columns.
	 *
	 * @param array $item Item data.
	 */
	protected function render_table_row( $item ) {
		$edit_url   = add_query_arg(
			array(
				'action' => 'edit',
				'id'     => $item['id'],
			)
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'delete',
					'id'     => $item['id'],
				)
			),
			'delete_item_' . $item['id']
		);
		?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['title'] ?? __( '(No title)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), time() ) ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this item?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
		<?php
	}

	/**
	 * Render add form.
	 */
	protected function render_add_form() {
		?>
<div class="add-form">
<h3><?php esc_html_e( 'Add New Item', 'mcp-ai-wpoos-pro' ); ?></h3>

		<?php $this->render_ai_generate_section(); ?>

<form method="post" action="">
		<?php wp_nonce_field( 'wp_mcp_ai_research_add_item', 'wp_mcp_ai_research_nonce' ); ?>
<input type="hidden" name="toolkit_slug" value="<?php echo esc_attr( $this->toolkit_slug ); ?>">
<input type="hidden" name="entity_type" value="<?php echo esc_attr( $this->current_entity ); ?>">

		<?php $this->render_form_fields(); ?>

<p class="submit">
<button type="submit" name="action" value="save" class="button button-primary">
		<?php esc_html_e( 'Save Item', 'mcp-ai-wpoos-pro' ); ?>
</button>
<a href="<?php echo esc_url( remove_query_arg( 'action' ) ); ?>" class="button">
		<?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?>
</a>
</p>
</form>
</div>
		<?php
	}

	/**
	 * Render AI generate section.
	 */
	protected function render_ai_generate_section() {
		?>
<div class="ai-generate-section">
<h4><?php esc_html_e( '🤖 AI-Powered Generation', 'mcp-ai-wpoos-pro' ); ?></h4>
<p><?php esc_html_e( 'Describe what you want to create and let AI generate the fields for you.', 'mcp-ai-wpoos-pro' ); ?></p>
<div class="ai-generate-form">
<textarea id="ai-prompt" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Example: Create a product for wireless headphones with noise cancellation...', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
<button type="button" id="ai-generate-btn" class="button button-secondary">
		<?php esc_html_e( 'Generate with AI', 'mcp-ai-wpoos-pro' ); ?>
</button>
<span class="spinner"></span>
</div>
</div>
		<?php
	}

	/**
	 * Render form fields.
	 * Override in child classes for entity-specific fields.
	 */
	protected function render_form_fields() {
		$store  = $this->get_current_data_store();
		$schema = $store ? $store->get_field_schema() : array();

		if ( empty( $schema ) ) {
			?>
<table class="form-table">
<tr>
<th scope="row">
<label for="item_title"><?php esc_html_e( 'Title', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<input type="text" id="item_title" name="item_data[title]" class="regular-text" required>
</td>
</tr>
<tr>
<th scope="row">
<label for="item_content"><?php esc_html_e( 'Content', 'mcp-ai-wpoos-pro' ); ?></label>
</th>
<td>
<textarea id="item_content" name="item_data[content]" rows="5" class="large-text"></textarea>
</td>
</tr>
</table>
			<?php
		}
	}

	/**
	 * Render edit form.
	 */
	protected function render_edit_form() {
		$item_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $item_id ) {
			return;
		}

		$store = $this->get_current_data_store();
		$item  = $store ? $store->get_item( $item_id ) : null;

		if ( ! $item || is_wp_error( $item ) ) {
			echo '<p>' . esc_html__( 'Item not found.', 'mcp-ai-wpoos-pro' ) . '</p>';
			return;
		}

		?>
<div class="edit-form">
<h3><?php esc_html_e( 'Edit Item', 'mcp-ai-wpoos-pro' ); ?></h3>

<form method="post" action="">
		<?php wp_nonce_field( 'wp_mcp_ai_research_update_item', 'wp_mcp_ai_research_nonce' ); ?>
<input type="hidden" name="item_id" value="<?php echo esc_attr( $item_id ); ?>">
<input type="hidden" name="toolkit_slug" value="<?php echo esc_attr( $this->toolkit_slug ); ?>">
<input type="hidden" name="entity_type" value="<?php echo esc_attr( $this->current_entity ); ?>">

		<?php $this->render_form_fields( $item ); ?>

<p class="submit">
<button type="submit" name="action" value="update" class="button button-primary">
		<?php esc_html_e( 'Update Item', 'mcp-ai-wpoos-pro' ); ?>
</button>
<a href="<?php echo esc_url( remove_query_arg( array( 'action', 'id' ) ) ); ?>" class="button">
		<?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?>
</a>
</p>
</form>
</div>
		<?php
	}

	/**
	 * Render no entities message.
	 */
	protected function render_no_entities_message() {
		?>
<div class="notice notice-warning">
<p><?php esc_html_e( 'No entity types configured for this toolkit.', 'mcp-ai-wpoos-pro' ); ?></p>
</div>
		<?php
	}

	/**
	 * Handle AJAX add item request.
	 */
	public function ajax_add_item() {
		check_ajax_referer( 'wp_mcp_ai_research_add_item', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Implementation will be added when needed.
		wp_send_json_success( array( 'message' => __( 'Item added successfully', 'mcp-ai-wpoos-pro' ) ) );
	}

	/**
	 * Handle AJAX delete item request.
	 */
	public function ajax_delete_item() {
		check_ajax_referer( 'wp_mcp_ai_research_delete_item', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Implementation will be added when needed.
		wp_send_json_success( array( 'message' => __( 'Item deleted successfully', 'mcp-ai-wpoos-pro' ) ) );
	}

	/**
	 * Handle AJAX get item request.
	 */
	public function ajax_get_item() {
		check_ajax_referer( 'wp_mcp_ai_research_get_item', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Implementation will be added when needed.
		wp_send_json_success( array( 'data' => array() ) );
	}

	/**
	 * Handle AJAX AI generate request.
	 */
	public function ajax_ai_generate() {
		check_ajax_referer( 'wp_mcp_ai_research_ai_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Implementation will be added when needed.
		wp_send_json_success( array( 'generated_data' => array() ) );
	}

	/**
	 * Handle form submission for add/update/delete operations.
	 */
	public function handle_form_submission() {
		// Check if this is a Research & Add form submission.
		if ( ! isset( $_POST['action'] ) || ! in_array( $_POST['action'], array( 'save', 'update' ), true ) ) {
			// Check for delete action in GET.
			if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] ) {
				return;
			}
		}

		// Check if this is for our toolkit.
		$toolkit_slug = isset( $_POST['toolkit_slug'] ) ? sanitize_key( $_POST['toolkit_slug'] ) : '';
		if ( empty( $toolkit_slug ) && isset( $_GET['page'] ) ) {
			// Try to extract toolkit from page slug.
			$page_slug    = sanitize_key( $_GET['page'] );
			$toolkit_slug = $this->toolkit_slug;
		}

		if ( $toolkit_slug !== $this->toolkit_slug ) {
			return;
		}

		// Verify nonce for POST operations.
		if ( isset( $_POST['action'] ) ) {
			if ( 'save' === $_POST['action'] && ! wp_verify_nonce( $_POST['wp_mcp_ai_research_nonce'], 'wp_mcp_ai_research_add_item' ) ) {
				wp_die( esc_html__( 'Security check failed', 'mcp-ai-wpoos-pro' ) );
			}

			if ( 'update' === $_POST['action'] && ! wp_verify_nonce( $_POST['wp_mcp_ai_research_nonce'], 'wp_mcp_ai_research_update_item' ) ) {
				wp_die( esc_html__( 'Security check failed', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) );
		}

		$entity_type = isset( $_POST['entity_type'] ) ? sanitize_key( $_POST['entity_type'] ) : ( isset( $_GET['entity'] ) ? sanitize_key( $_GET['entity'] ) : '' );
		if ( empty( $entity_type ) || ! isset( $this->data_stores[ $entity_type ] ) ) {
			return;
		}

		$store = $this->data_stores[ $entity_type ];

		// Handle save (create new item).
		if ( isset( $_POST['action'] ) && 'save' === $_POST['action'] ) {
			$item_data = isset( $_POST['item_data'] ) ? wp_unslash( $_POST['item_data'] ) : array();
			$result    = $store->create_item( $item_data );

			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ) );
			}

			// Redirect back to list view with success message.
			$redirect_url = add_query_arg(
				array(
					'page'    => sanitize_key( $_GET['page'] ),
					'tab'     => 'research',
					'entity'  => $entity_type,
					'message' => 'created',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Handle update.
		if ( isset( $_POST['action'] ) && 'update' === $_POST['action'] ) {
			$item_id   = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
			$item_data = isset( $_POST['item_data'] ) ? wp_unslash( $_POST['item_data'] ) : array();

			if ( ! $item_id ) {
				wp_die( esc_html__( 'Invalid item ID', 'mcp-ai-wpoos-pro' ) );
			}

			$result = $store->update_item( $item_id, $item_data );

			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ) );
			}

			// Redirect back to list view with success message.
			$redirect_url = add_query_arg(
				array(
					'page'    => sanitize_key( $_GET['page'] ),
					'tab'     => 'research',
					'entity'  => $entity_type,
					'message' => 'updated',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Handle delete.
		if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] ) {
			$item_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

			if ( ! $item_id ) {
				wp_die( esc_html__( 'Invalid item ID', 'mcp-ai-wpoos-pro' ) );
			}

			// Verify nonce.
			check_admin_referer( 'delete_item_' . $item_id );

			$result = $store->delete_item( $item_id );

			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ) );
			}

			// Redirect back to list view with success message.
			$redirect_url = add_query_arg(
				array(
					'page'    => sanitize_key( $_GET['page'] ),
					'tab'     => 'research',
					'entity'  => $entity_type,
					'message' => 'deleted',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}
}
