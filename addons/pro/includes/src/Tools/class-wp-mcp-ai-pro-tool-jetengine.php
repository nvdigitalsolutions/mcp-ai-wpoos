<?php
/**
 * JetEngine Tool - Pro add-on tool for JetEngine CCT operations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for JetEngine CCT operations.
 *
 * Provides operations for JetEngine Custom Content Types including:
 * - Listing CCT items
 * - Getting item details
 * - Creating/updating items
 *
 * Requires JetEngine plugin to be active.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_JetEngine implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if JetEngine is active.
	 */
	public static function is_available() {
		return class_exists( 'Jet_Engine' );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'JetEngine tool requires JetEngine to be installed and activated.', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'jetengine';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'JetEngine CCT', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query and manage JetEngine Custom Content Type items. Supports CRUD operations on CCT data.', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'   => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: list_types, list_items, get_item, create_item, update_item.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'list_types', 'list_items', 'get_item', 'create_item', 'update_item' ),
					'default'     => 'list_types',
				),
				'cct_slug' => array(
					'type'        => 'string',
					'description' => __( 'CCT type slug.', 'wp-mcp-ai-pro' ),
				),
				'item_id'  => array(
					'type'        => 'integer',
					'description' => __( 'CCT item ID for get/update actions.', 'wp-mcp-ai-pro' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to return. Default: 10. Max: 100.', 'wp-mcp-ai-pro' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-pro' ),
					'default'     => 1,
				),
				'fields'   => array(
					'type'        => 'object',
					'description' => __( 'Field values for create/update operations.', 'wp-mcp-ai-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'read-only',        // list/get operations.
			'write',            // create/update operations.
			'requires-plugin',  // Requires JetEngine.
			'local-only',       // No external API calls.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if JetEngine is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'jetengine_not_active',
				__( 'JetEngine is not installed or activated.', 'wp-mcp-ai-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_types';

		switch ( $action ) {
			case 'list_types':
				return $this->list_types();
			case 'list_items':
				return $this->list_items( $arguments );
			case 'get_item':
				return $this->get_item( $arguments );
			case 'create_item':
				return $this->create_item( $arguments, $context );
			case 'update_item':
				return $this->update_item( $arguments, $context );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-pro' )
				);
		}
	}

	/**
	 * List available CCT types.
	 *
	 * @return array
	 */
	protected function list_types() {
		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return array( 'types' => array() );
		}

		$types  = $module->instance->manager->get_types();
		$result = array();

		foreach ( $types as $type ) {
			$result[] = array(
				'id'     => $type->id,
				'slug'   => $type->slug,
				'name'   => $type->name,
				'fields' => count( $type->fields ),
			);
		}

		return array( 'types' => $result );
	}

	/**
	 * List items for a CCT type.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function list_items( $arguments ) {
		if ( empty( $arguments['cct_slug'] ) ) {
			return new WP_Error(
				'missing_cct_slug',
				__( 'CCT slug is required for list_items action.', 'wp-mcp-ai-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'wp-mcp-ai-pro' )
			);
		}

		$type = $module->instance->manager->get_type( sanitize_key( $arguments['cct_slug'] ) );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'wp-mcp-ai-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$items = $type->db->query(
			array(
				'limit'  => $per_page,
				'offset' => $offset,
			)
		);

		$total = $type->db->count();

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => ceil( $total / $per_page ),
			'page'        => $page,
		);
	}

	/**
	 * Get a single CCT item.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_item( $arguments ) {
		if ( empty( $arguments['cct_slug'] ) || empty( $arguments['item_id'] ) ) {
			return new WP_Error(
				'missing_params',
				__( 'CCT slug and item ID are required for get_item action.', 'wp-mcp-ai-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'wp-mcp-ai-pro' )
			);
		}

		$type = $module->instance->manager->get_type( sanitize_key( $arguments['cct_slug'] ) );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'wp-mcp-ai-pro' )
			);
		}

		$item = $type->db->get_item( absint( $arguments['item_id'] ) );

		if ( ! $item ) {
			return new WP_Error(
				'item_not_found',
				__( 'CCT item not found.', 'wp-mcp-ai-pro' )
			);
		}

		return $item;
	}

	/**
	 * Create a new CCT item.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function create_item( $arguments, $context ) {
		if ( empty( $arguments['cct_slug'] ) ) {
			return new WP_Error(
				'missing_cct_slug',
				__( 'CCT slug is required for create_item action.', 'wp-mcp-ai-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to create CCT items.', 'wp-mcp-ai-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'wp-mcp-ai-pro' )
			);
		}

		$type = $module->instance->manager->get_type( sanitize_key( $arguments['cct_slug'] ) );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'wp-mcp-ai-pro' )
			);
		}

		$fields = isset( $arguments['fields'] ) && is_array( $arguments['fields'] ) ? $arguments['fields'] : array();

		$item_id = $type->db->insert( $fields );

		if ( ! $item_id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create CCT item.', 'wp-mcp-ai-pro' )
			);
		}

		return $type->db->get_item( $item_id );
	}

	/**
	 * Update an existing CCT item.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function update_item( $arguments, $context ) {
		if ( empty( $arguments['cct_slug'] ) || empty( $arguments['item_id'] ) ) {
			return new WP_Error(
				'missing_params',
				__( 'CCT slug and item ID are required for update_item action.', 'wp-mcp-ai-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to update CCT items.', 'wp-mcp-ai-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'wp-mcp-ai-pro' )
			);
		}

		$type = $module->instance->manager->get_type( sanitize_key( $arguments['cct_slug'] ) );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'wp-mcp-ai-pro' )
			);
		}

		$item_id = absint( $arguments['item_id'] );
		$fields  = isset( $arguments['fields'] ) && is_array( $arguments['fields'] ) ? $arguments['fields'] : array();

		$result = $type->db->update( $fields, array( '_ID' => $item_id ) );

		if ( ! $result ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update CCT item.', 'wp-mcp-ai-pro' )
			);
		}

		return $type->db->get_item( $item_id );
	}
}
