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
	 * @return object|false JetEngine CCT module or false.
	 */
	private function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return false;
		}

		$jet_engine = jet_engine();
		if ( ! isset( $jet_engine->modules ) ) {
			return false;
		}

		$modules = $jet_engine->modules;
		if ( ! isset( $modules->modules_manager ) ) {
			return false;
		}

		$module = $modules->modules_manager->get_module( 'custom-content-types' );
		if ( ! $module || ! $module->instance ) {
			return false;
		}

		return $module->instance;
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

		// Register the CCT.
		$this->cct_module->data->set_request( $args );
		$this->cct_module->data->edit_item( false );
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

		// Prepare item for JetEngine.
		$item_data = array(
			'cct_slug' => $this->cct_slug,
		);

		// Add fields from data.
		foreach ( $data as $key => $value ) {
			$item_data[ $key ] = $value;
		}

		// Insert item using JetEngine.
		$item_id = $this->cct_module->manager->insert_item( $item_data );

		if ( ! $item_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create item', 'mcp-ai-wpoos-pro' ) );
		}

		return $item_id;
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

		$item = $this->cct_module->manager->get_item( $item_id, $this->cct_slug );

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

		// Prepare update data.
		$update_data = array_merge(
			array(
				'_ID'      => $item_id,
				'cct_slug' => $this->cct_slug,
			),
			$data
		);

		$result = $this->cct_module->manager->update_item( $update_data );

		if ( ! $result ) {
			return new WP_Error( 'update_failed', __( 'Failed to update item', 'mcp-ai-wpoos-pro' ) );
		}

		return true;
	}

	/**
	 * Delete an item.
	 *
	 * @param int $item_id Item ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_item( $item_id ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'cct_not_available', __( 'JetEngine CCT is not available', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->cct_module->manager->delete_item( $item_id, $this->cct_slug );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete item', 'mcp-ai-wpoos-pro' ) );
		}

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

		$defaults = array(
			'cct_slug' => $this->cct_slug,
			'per_page' => 20,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		$query_args = wp_parse_args( $args, $defaults );
		$items      = $this->cct_module->manager->get_items( $query_args );

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
