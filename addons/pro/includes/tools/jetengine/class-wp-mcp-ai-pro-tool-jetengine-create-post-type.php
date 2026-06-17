<?php
/**
 * JetEngine Create Post Type Tool
 *
 * Creates custom post types via JetEngine MCP Server.
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
 * Tool for creating JetEngine custom post types via MCP.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Tool_JetEngine_Create_Post_Type implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'jetengine_create_post_type';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'JetEngine Create Post Type', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create a new JetEngine custom post type via the MCP Server. Provide a slug, singular label, and plural label. Optionally specify additional settings like public visibility, has_archive, menu_icon, and supports array.', 'mcp-ai-wpoos-pro' );
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
				'slug'          => array(
					'type'        => 'string',
					'description' => __( 'Post type slug (lowercase, no spaces, max 20 characters).', 'mcp-ai-wpoos-pro' ),
				),
				'singular_name' => array(
					'type'        => 'string',
					'description' => __( 'Singular display name (e.g., "Course").', 'mcp-ai-wpoos-pro' ),
				),
				'plural_name'   => array(
					'type'        => 'string',
					'description' => __( 'Plural display name (e.g., "Courses").', 'mcp-ai-wpoos-pro' ),
				),
				'public'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the post type is publicly accessible. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'has_archive'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the post type has an archive page. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'menu_icon'     => array(
					'type'        => 'string',
					'description' => __( 'Dashicon class for the admin menu (e.g., "dashicons-admin-post").', 'mcp-ai-wpoos-pro' ),
				),
				'supports'      => array(
					'type'        => 'array',
					'description' => __( 'Features the post type supports (e.g., ["title", "editor", "thumbnail"]).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'slug', 'singular_name', 'plural_name' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-plugin', 'local-only' );
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

		$slug          = isset( $arguments['slug'] ) ? sanitize_key( $arguments['slug'] ) : '';
		$singular_name = isset( $arguments['singular_name'] ) ? sanitize_text_field( $arguments['singular_name'] ) : '';
		$plural_name   = isset( $arguments['plural_name'] ) ? sanitize_text_field( $arguments['plural_name'] ) : '';

		if ( empty( $slug ) || empty( $singular_name ) || empty( $plural_name ) ) {
			return new WP_Error( 'missing_params', __( 'slug, singular_name, and plural_name are required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( strlen( $slug ) > 20 ) {
			return new WP_Error( 'invalid_slug', __( 'Post type slug must be 20 characters or fewer.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( post_type_exists( $slug ) ) {
			return new WP_Error(
				'post_type_exists',
				sprintf(
				/* translators: %s: post type slug */
					__( 'Post type "%s" already exists.', 'mcp-ai-wpoos-pro' ),
					$slug
				)
			);
		}

		$mcp_args = array(
			'slug'          => $slug,
			'singular_name' => $singular_name,
			'plural_name'   => $plural_name,
		);

		if ( isset( $arguments['public'] ) ) {
			$mcp_args['public'] = (bool) $arguments['public'];
		}
		if ( isset( $arguments['has_archive'] ) ) {
			$mcp_args['has_archive'] = (bool) $arguments['has_archive'];
		}
		if ( ! empty( $arguments['menu_icon'] ) ) {
			$mcp_args['menu_icon'] = sanitize_text_field( $arguments['menu_icon'] );
		}
		if ( ! empty( $arguments['supports'] ) && is_array( $arguments['supports'] ) ) {
			$mcp_args['supports'] = array_map( 'sanitize_key', $arguments['supports'] );
		}

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->tools_call( 'create_post_type', $mcp_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: post type slug */
				__( 'Custom post type "%s" created successfully via JetEngine MCP.', 'mcp-ai-wpoos-pro' ),
				$slug
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
