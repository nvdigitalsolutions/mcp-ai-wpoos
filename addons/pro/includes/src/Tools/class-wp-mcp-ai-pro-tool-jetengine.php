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
		return __( 'JetEngine tool requires JetEngine to be installed and activated.', 'mcp-ai-wpoos-pro' );
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
		return __( 'JetEngine CCT', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query and manage JetEngine Custom Content Type (CCT) items. Use this tool — NOT create_post — when you need to create, read, update, or delete records in any JetEngine CCT such as vital_signs, channel_messages, or any other CCT slug. Supports full CRUD: list_types, list_items, get_item, create_item, update_item, delete_item.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'The action to perform: list_types, list_items, get_item, create_item, update_item, delete_item.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list_types', 'list_items', 'get_item', 'create_item', 'update_item', 'delete_item' ),
					'default'     => 'list_types',
				),
				'cct_slug' => array(
					'type'        => 'string',
					'description' => __( 'CCT type slug.', 'mcp-ai-wpoos-pro' ),
				),
				'item_id'  => array(
					'type'        => 'integer',
					'description' => __( 'CCT item ID for get/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to return. Default: 10. Max: 100.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
				),
				'fields'   => array(
					'type'        => 'object',
					'description' => __( 'Field values for create/update operations.', 'mcp-ai-wpoos-pro' ),
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
			'pro',              // Pro tier tool.
			'read-only',        // list/get operations.
			'write',            // create/update/delete operations.
			'requires-plugin',  // Requires JetEngine.
			'local-only',       // No external API calls.
		);
	}

	/**
	 * Get the extended tool definition for orchestration.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'jetengine_cct',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'developer', 'content_manager', 'health_advisor', 'data_manager' ),
			'risk_level'            => 'standard',
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
				__( 'JetEngine is not installed or activated.', 'mcp-ai-wpoos-pro' )
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
			case 'delete_item':
				return $this->delete_item( $arguments, $context );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'mcp-ai-wpoos-pro' )
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

		if ( ! method_exists( $module->instance->manager, 'get_content_types' ) ) {
			return array( 'types' => array() );
		}

		$types  = $module->instance->manager->get_content_types();
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
				__( 'CCT slug is required for list_items action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
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
				__( 'CCT slug and item ID are required for get_item action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$item = $type->db->get_item( absint( $arguments['item_id'] ) );

		if ( ! $item ) {
			return new WP_Error(
				'item_not_found',
				__( 'CCT item not found.', 'mcp-ai-wpoos-pro' )
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
				__( 'CCT slug is required for create_item action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to create CCT items.', 'mcp-ai-wpoos-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$fields = isset( $arguments['fields'] ) && is_array( $arguments['fields'] ) ? $arguments['fields'] : array();

		$item_id = $type->db->insert( $fields );

		if ( ! $item_id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create CCT item.', 'mcp-ai-wpoos-pro' )
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
				__( 'CCT slug and item ID are required for update_item action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to update CCT items.', 'mcp-ai-wpoos-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$item_id = absint( $arguments['item_id'] );
		$fields  = isset( $arguments['fields'] ) && is_array( $arguments['fields'] ) ? $arguments['fields'] : array();

		$result = $type->db->update( $fields, array( '_ID' => $item_id ) );

		if ( ! $result ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update CCT item.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $type->db->get_item( $item_id );
	}

	/**
	 * Delete a CCT item permanently.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function delete_item( $arguments, $context ) {
		if ( empty( $arguments['cct_slug'] ) || empty( $arguments['item_id'] ) ) {
			return new WP_Error(
				'missing_params',
				__( 'CCT slug and item ID are required for delete_item action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to delete CCT items.', 'mcp-ai-wpoos-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$item_id = absint( $arguments['item_id'] );

		// Verify item exists before deleting.
		$item = $type->db->get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error(
				'item_not_found',
				__( 'CCT item not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $type->db->delete( array( '_ID' => $item_id ) );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete CCT item.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'success' => true,
			'deleted' => true,
			'item_id' => $item_id,
			'message' => sprintf(
				/* translators: 1: CCT slug, 2: item ID */
				__( 'CCT item %1$s #%2$d deleted successfully.', 'mcp-ai-wpoos-pro' ),
				sanitize_key( $arguments['cct_slug'] ),
				$item_id
			),
		);
	}

	/**
	 * Resolve a CCT type object by slug using the correct JetEngine API.
	 *
	 * JetEngine exposes content types through `manager->get_content_types()`.
	 * Older code incorrectly called `get_type()` / `get_types()` which do not
	 * exist on the Manager class and produce a fatal error.  This helper always
	 * uses `get_content_types()` and guards against future API changes with a
	 * `method_exists()` check.
	 *
	 * @param object $module CCT module instance (i.e. `$module_wrapper->instance`), which
	 *                       exposes a `manager` property with a `get_content_types()` method.
	 * @param string $slug   CCT slug to look up.
	 * @return object|null   CCT type object, or null when not found or API unavailable.
	 */
	protected function get_cct_type( $module, $slug ) {
		if ( empty( $module->manager ) ||
			! method_exists( $module->manager, 'get_content_types' ) ) {
			return null;
		}

		$type = $module->manager->get_content_types( sanitize_key( $slug ) );
		return is_object( $type ) ? $type : null;
	}
}
