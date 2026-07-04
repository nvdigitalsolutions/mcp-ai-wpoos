<?php
/**
 * Toolkit CCT Data Store
 *
 * JetEngine Custom Content Type implementation of toolkit data storage.
 * This is the enhanced storage backend that requires JetEngine plugin.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/interfaces/interface-wp-mcp-ai-toolkit-data-store.php';

/**
 * CCT-based data store for toolkit entities.
 */
class WP_MCP_AI_Toolkit_CCT_Store implements WP_MCP_AI_Toolkit_Data_Store {

	/**
	 * Toolkit slug.
	 *
	 * @var string
	 */
	private $toolkit_slug;

	/**
	 * Entity type.
	 *
	 * @var string
	 */
	private $entity_type;

	/**
	 * CCT slug.
	 *
	 * @var string
	 */
	private $cct_slug;

	/**
	 * Field schema for this entity.
	 *
	 * @var array
	 */
	private $field_schema;

	/**
	 * JetEngine CCT module instance.
	 *
	 * @var object
	 */
	private $cct_module;

	/**
	 * Cached CCT Factory instance for the configured slug.
	 *
	 * @since 1.9.3
	 *
	 * @var \Jet_Engine\Modules\Custom_Content_Types\Factory|null
	 */
	private $factory = null;

	/**
	 * Field ID base for this toolkit (allocated ranges).
	 *
	 * @var int
	 */
	private $field_id_base;

	/**
	 * Constructor.
	 *
	 * @param string $toolkit_slug Toolkit identifier.
	 * @param string $entity_type  Entity type.
	 */
	public function __construct( $toolkit_slug, $entity_type ) {
		$this->toolkit_slug  = $toolkit_slug;
		$this->entity_type   = $entity_type;
		$this->cct_slug      = $this->generate_cct_slug();
		$this->field_schema  = $this->load_field_schema();
		$this->cct_module    = $this->get_cct_module();
		$this->field_id_base = $this->get_field_id_base();

		// Register the CCT if JetEngine is available. JetEngine's CCT module
		// hydrates its table cache on `init` at priorities 1-10; priority 11
		// is the safe window that runs after JetEngine has finished.
		add_action( 'init', array( $this, 'maybe_register_cct' ), 11 );
	}

	/**
	 * Generate CCT slug from toolkit and entity.
	 *
	 * @return string CCT slug.
	 */
	private function generate_cct_slug() {
		// Format: {toolkit}_{entity}.
		return $this->toolkit_slug . '_' . $this->entity_type;
	}

	/**
	 * Get field ID base for this toolkit.
	 *
	 * Field ID allocation (from plan):
	 * - 31000-31999: E-commerce Toolkit
	 * - 32000-32999: Social Media Toolkit
	 * - 33000-33999: Multilingual Toolkit
	 * - 34000-34999: Financial Planner Toolkit
	 * - 35000-35999: Calendar Booking Toolkit
	 * - 36000-36999: DJ Management Toolkit
	 * - 37000-37999: Media Toolkit
	 * - 38000-38999: AI Tool Builder Toolkit
	 *
	 * @return int Field ID base.
	 */
	private function get_field_id_base() {
		$bases = array(
			'ecommerce'         => 31000,
			'social_media'      => 32000,
			'multilingual'      => 33000,
			'financial_planner' => 34000,
			'calendar_booking'  => 35000,
			'dj_management'     => 36000,
			'media'             => 37000,
			'ai_tool_builder'   => 38000,
		);

		return isset( $bases[ $this->toolkit_slug ] ) ? $bases[ $this->toolkit_slug ] : 39000;
	}

	/**
	 * Load field schema for this entity type.
	 *
	 * @return array Field definitions.
	 */
	private function load_field_schema() {
		$schema = array();

		// Allow toolkits to define their field schemas.
		$schema = apply_filters(
			'wp_mcp_ai_toolkit_cct_field_schema',
			$schema,
			$this->toolkit_slug,
			$this->entity_type
		);

		return $schema;
	}

	/**
	 * Get JetEngine CCT module.
	 *
	 * Uses the canonical Module::instance() singleton — the path
	 * documented by Crocoblock for PHP-side CCT access. The Module
	 * class exposes ->manager (with ->manager->data for post_types
	 * registration records); it has NO ->data property itself.
	 *
	 * @return object|false JetEngine CCT module or false.
	 */
	private function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return false;
		}

		// Canonical accessor: the CCT module singleton. Its class is only
		// loaded when the module is active.
		if ( class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) ) {
			$module = \Jet_Engine\Modules\Custom_Content_Types\Module::instance();
			if ( ! empty( $module->manager ) ) {
				return $module;
			}
		}

		// Fallback: walk the modules registry.
		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return false;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return false;
		}

		if ( ! method_exists( $engine->modules, 'get_module' ) ) {
			return false;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );

		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return false;
		}

		$instance = $module_wrapper->instance;

		return ! empty( $instance->manager ) ? $instance : false;
	}

	/**
	 * Retrieve the JetEngine Factory (type object) for the configured CCT.
	 *
	 * The Factory is the canonical PHP handle for CCT item CRUD: item
	 * queries go through $factory->db and writes go through the
	 * Item_Handler returned by $factory->get_item_handler().
	 *
	 * When the CCT was just registered in the current request the
	 * Manager's content-types registry (hydrated on init) won't contain
	 * it yet, so this method falls back to constructing a Factory
	 * directly from the stored CCT record.
	 *
	 * @since 1.9.3
	 *
	 * @return \Jet_Engine\Modules\Custom_Content_Types\Factory|null
	 */
	private function get_cct_factory() {
		if ( null !== $this->factory ) {
			return $this->factory;
		}

		$module = $this->cct_module;

		if ( ! $module || empty( $module->manager ) || ! method_exists( $module->manager, 'get_content_types' ) ) {
			return null;
		}

		$factory = $module->manager->get_content_types( $this->cct_slug );

		if ( ! empty( $factory ) && ! is_array( $factory ) ) {
			$this->factory = $factory;
			return $this->factory;
		}

		// Same-request fallback: the CCT record exists in the DB but the
		// runtime registry was hydrated before it was created. Build the
		// Factory manually from the stored record so writes work
		// immediately after auto-registration.
		if ( empty( $module->manager->data ) || empty( $module->manager->data->db ) ) {
			return null;
		}

		$records = $module->manager->data->db->query(
			'post_types',
			array(
				'slug'   => $this->cct_slug,
				'status' => 'content-type',
			),
			null,
			false
		);

		if ( empty( $records ) || ! is_array( $records ) ) {
			return null;
		}

		$record = reset( $records );

		if ( ! is_array( $record ) && ! is_object( $record ) ) {
			return null;
		}

		if ( is_object( $record ) ) {
			$record = get_object_vars( $record );
		}

		$args   = isset( $record['args'] ) ? maybe_unserialize( $record['args'] ) : array();
		$fields = isset( $record['meta_fields'] ) ? maybe_unserialize( $record['meta_fields'] ) : array();

		if ( ! is_array( $args ) ) {
			$args = array();
		}
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$args['slug'] = $this->cct_slug;

		if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Factory' ) && method_exists( $module, 'module_path' ) ) {
			$factory_path = $module->module_path( 'factory.php' );
			if ( $factory_path && file_exists( $factory_path ) ) {
				require_once $factory_path;
			}
		}

		if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Factory' ) ) {
			return null;
		}

		$type_id      = isset( $record['id'] ) ? absint( $record['id'] ) : 0;
		$this->factory = new \Jet_Engine\Modules\Custom_Content_Types\Factory( $args, $fields, $type_id );

		return $this->factory;
	}

	/**
	 * Retrieve the JetEngine Item_Handler for the configured CCT.
	 *
	 * @since 1.9.3
	 *
	 * @return \Jet_Engine\Modules\Custom_Content_Types\Item_Handler|null
	 */
	private function get_item_handler() {
		$factory = $this->get_cct_factory();

		if ( ! $factory || ! method_exists( $factory, 'get_item_handler' ) ) {
			return null;
		}

		return $factory->get_item_handler();
	}

	/**
	 * Maybe register CCT if not already registered.
	 */
	public function maybe_register_cct() {
		if ( ! $this->cct_module ) {
			return;
		}

		// Check if CCT already exists.
		if ( $this->cct_module->manager->get_content_types( $this->cct_slug ) ) {
			return;
		}

		// Build CCT fields from schema.
		$fields = $this->build_cct_fields();
		if ( empty( $fields ) ) {
			return;
		}

		$args = array(
			'slug'             => $this->cct_slug,
			'name'             => sprintf( '%s %s', ucwords( str_replace( '_', ' ', $this->toolkit_slug ) ), ucwords( str_replace( '_', ' ', $this->entity_type ) ) ),
			'show_edit_link'   => true,
			'hide_field_names' => false,
			'fields'           => $fields,
		);

		// Register the CCT via Manager (edit_item with slug + fields).
		// Manager::edit_item() is the public registration API that handles
		// the full Data sanitize-create lifecycle. The previous code called
		// Data::edit_item() directly, but Data exposes edit_item() on
		// Jet_Engine_Base_Data which requires a capability check and expects
		// the request to already be set — it is not a direct CCT-registration
		// API and silently fails when the fields array doesn't match the
		// meta_fields shape the Data layer expects.
		if ( method_exists( $this->cct_module->manager, 'edit_item' ) ) {
			$this->cct_module->manager->edit_item( false, $args );
		}
	}

	/**
	 * Build CCT fields from schema.
	 *
	 * @return array CCT field definitions.
	 */
	private function build_cct_fields() {
		$fields      = array();
		$field_index = 0;

		foreach ( $this->field_schema as $field_name => $field_def ) {
			$field_id = $this->field_id_base + $field_index;
			++$field_index;

			$field = array(
				'id'    => $field_id,
				'name'  => $field_name,
				'title' => isset( $field_def['title'] ) ? $field_def['title'] : ucwords( str_replace( '_', ' ', $field_name ) ),
				'type'  => isset( $field_def['type'] ) ? $field_def['type'] : 'text',
				'width' => isset( $field_def['width'] ) ? $field_def['width'] : '100%',
			);

			// Add other field properties.
			if ( isset( $field_def['is_required'] ) ) {
				$field['is_required'] = $field_def['is_required'];
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Create a new item.
	 *
	 * @param array $data Item data.
	 * @return int|WP_Error Item ID on success, WP_Error on failure.
	 */
	public function create_item( $data ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'cct_not_available', __( 'JetEngine CCT is not available', 'mcp-ai-wpoos-pro' ) );
		}

		$handler = $this->get_item_handler();
		if ( ! $handler ) {
			return new WP_Error( 'cct_not_available', __( 'JetEngine CCT is not available', 'mcp-ai-wpoos-pro' ) );
		}

		$item_data           = $data;
		$item_data['cct_status'] = 'publish';

		// Item_Handler::update_item() creates when no _ID is passed.
		$item_id = $handler->update_item( $item_data );

		if ( ! $item_id || is_wp_error( $item_id ) ) {
			return is_wp_error( $item_id ) ? $item_id : new WP_Error( 'create_failed', __( 'Failed to create item', 'mcp-ai-wpoos-pro' ) );
		}

		return absint( $item_id );
	}

	/**
	 * Get an item.
	 *
	 * @param int $item_id Item ID.
	 * @return array|WP_Error Item data on success, WP_Error on failure.
	 */
	public function get_item( $item_id ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'cct_not_available', __( 'JetEngine CCT is not available', 'mcp-ai-wpoos-pro' ) );
		}

		$factory = $this->get_cct_factory();
		if ( ! $factory || empty( $factory->db ) ) {
			return new WP_Error( 'cct_not_available', __( 'JetEngine CCT is not available', 'mcp-ai-wpoos-pro' ) );
		}

		$factory->db->set_format_flag( ARRAY_A );
		$item = $factory->db->get_item( absint( $item_id ) );

		if ( ! $item ) {
			return new WP_Error( 'item_not_found', __( 'Item not found', 'mcp-ai-wpoos-pro' ) );
		}

		return $item;
	}

	/**
	 * Update an item.
	 *
	 * @param int   $item_id Item ID.
	 * @param array $data    Updated data.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_item( $item_id, $data ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'cct_not_available', __( 'JetEngine CCT is not available', 'mcp-ai-wpoos-pro' ) );
		}

		$handler = $this->get_item_handler();
		if ( ! $handler ) {
			return new WP_Error( 'cct_not_available', __( 'JetEngine CCT is not available', 'mcp-ai-wpoos-pro' ) );
		}

		$update_data       = $data;
		$update_data['_ID'] = absint( $item_id );

		$result = $handler->update_item( $update_data );

		if ( ! $result || is_wp_error( $result ) ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'update_failed', __( 'Failed to update item', 'mcp-ai-wpoos-pro' ) );
		}

		return true;
	}

	/**
	 * Delete an item.
	 *
	 * Uses the CCT DB layer directly. The Item_Handler delete path
	 * enforces an interactive capability check and calls wp_die() on
	 * failure, which would kill background/cron requests.
	 *
	 * @param int $item_id Item ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_item( $item_id ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'cct_not_available', __( 'JetEngine CCT is not available', 'mcp-ai-wpoos-pro' ) );
		}

		$factory = $this->get_cct_factory();
		if ( ! $factory || empty( $factory->db ) ) {
			return new WP_Error( 'cct_not_available', __( 'JetEngine CCT is not available', 'mcp-ai-wpoos-pro' ) );
		}

		$factory->db->delete( array( '_ID' => absint( $item_id ) ) );

		return true;
	}

	/**
	 * Query items.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of items.
	 */
	public function query_items( $args = array() ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		$factory = $this->get_cct_factory();
		if ( ! $factory || empty( $factory->db ) ) {
			return array();
		}

		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$order = array(
			array(
				'orderby' => isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : '_ID',
				'order'   => isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ? 'asc' : 'desc',
			),
		);

		$factory->db->set_format_flag( ARRAY_A );

		$items = $factory->db->query( array( 'cct_status' => 'publish' ), $per_page, $offset, $order );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Get storage type.
	 *
	 * @return string Always 'cct'.
	 */
	public function get_storage_type() {
		return 'cct';
	}

	/**
	 * Get CCT slug.
	 *
	 * @return string CCT slug.
	 */
	public function get_content_type_slug() {
		return $this->cct_slug;
	}

	/**
	 * Check if CCT storage is available.
	 *
	 * @return bool True if JetEngine CCT is available.
	 */
	public function is_available() {
		return ! empty( $this->cct_module );
	}

	/**
	 * Get field schema.
	 *
	 * @return array Field definitions.
	 */
	public function get_field_schema() {
		return $this->field_schema;
	}
}
