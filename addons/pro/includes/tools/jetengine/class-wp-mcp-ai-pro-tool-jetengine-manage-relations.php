<?php
/**
 * JetEngine Manage Relations Tool
 *
 * Manages JetEngine relations via MCP Server.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for managing JetEngine relations via MCP.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Tool_JetEngine_Manage_Relations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @return bool True if JetEngine 3.8+ MCP server is available.
	 */
	public static function is_available() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Compat' ) ) {
			return false;
		}
		return WP_MCP_AI_JetEngine_Compat::has_mcp_server();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'Requires JetEngine 3.8+ with MCP Server enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'jetengine_manage_relations';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'JetEngine Manage Relations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'List and manage JetEngine relations between post types, custom content types, users, and taxonomies. Use list action to see existing relations, or create to set up new ones (one-to-one, one-to-many, many-to-many).', 'mcp-ai-wpoos-pro' );
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
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action: list (get existing relations), create (create a new relation).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'create' ),
				),
				'name'          => array(
					'type'        => 'string',
					'description' => __( 'Relation name (for create action).', 'mcp-ai-wpoos-pro' ),
				),
				'parent_object' => array(
					'type'        => 'string',
					'description' => __( 'Parent object type slug (e.g., post type slug, "users").', 'mcp-ai-wpoos-pro' ),
				),
				'child_object'  => array(
					'type'        => 'string',
					'description' => __( 'Child object type slug.', 'mcp-ai-wpoos-pro' ),
				),
				'relation_type' => array(
					'type'        => 'string',
					'description' => __( 'Relation cardinality: one_to_one, one_to_many, many_to_many.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'one_to_one', 'one_to_many', 'many_to_many' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'write', 'requires-plugin', 'local-only' );
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => $this->get_name(),
			'description'         => $this->get_description(),
			'parameters'          => $this->get_parameters_schema(),
			'required_capability' => 'manage_options',
			'toolkit'             => 'jetengine_mcp_bridge',
			'risk_level'          => 'elevated',
			'capability_flags'    => $this->get_capability_flags(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_permissions', __( 'Requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		switch ( $action ) {
			case 'list':
				return $this->list_relations( $client );

			case 'create':
				return $this->create_relation( $client, $arguments );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action. Use list or create.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * List existing relations.
	 *
	 * @param WP_MCP_AI_JetEngine_MCP_Client $client MCP client instance.
	 * @return array|WP_Error Relations list or error.
	 */
	private function list_relations( $client ) {
		$result = $client->tools_call( 'get_relations' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'   => true,
			'relations' => $result,
		);
	}

	/**
	 * Create a new relation.
	 *
	 * @param WP_MCP_AI_JetEngine_MCP_Client $client    MCP client instance.
	 * @param array                          $arguments Tool arguments.
	 * @return array|WP_Error Result or error.
	 */
	private function create_relation( $client, $arguments ) {
		$name          = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$parent_object = isset( $arguments['parent_object'] ) ? sanitize_key( $arguments['parent_object'] ) : '';
		$child_object  = isset( $arguments['child_object'] ) ? sanitize_key( $arguments['child_object'] ) : '';
		$relation_type = isset( $arguments['relation_type'] ) ? sanitize_key( $arguments['relation_type'] ) : '';

		if ( empty( $name ) || empty( $parent_object ) || empty( $child_object ) || empty( $relation_type ) ) {
			return new WP_Error( 'missing_params', __( 'name, parent_object, child_object, and relation_type are required for create action.', 'mcp-ai-wpoos-pro' ) );
		}

		$mcp_args = array(
			'name'          => $name,
			'parent_object' => $parent_object,
			'child_object'  => $child_object,
			'relation_type' => $relation_type,
		);

		$result = $client->tools_call( 'create_relation', $mcp_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: relation name, 2: parent object, 3: child object */
				__( 'Relation "%1$s" created between "%2$s" and "%3$s" via JetEngine MCP.', 'mcp-ai-wpoos-pro' ),
				$name,
				$parent_object,
				$child_object
			),
			'result'  => $result,
		);
	}

	/**
	 * Get MCP client instance.
	 *
	 * @return WP_MCP_AI_JetEngine_MCP_Client|WP_Error Client or error.
	 */
	private function get_client() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_MCP_Client' ) ) {
			$client_file = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-mcp-client.php'
				: '';
			if ( ! empty( $client_file ) && file_exists( $client_file ) ) {
				require_once $client_file;
			} else {
				return new WP_Error( 'mcp_client_missing', __( 'MCP client class is not available.', 'mcp-ai-wpoos-pro' ) );
			}
		}
		return new WP_MCP_AI_JetEngine_MCP_Client();
	}
}
