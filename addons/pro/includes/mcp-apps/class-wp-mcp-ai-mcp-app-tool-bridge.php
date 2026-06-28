<?php
/**
 * MCP App Tool Bridge.
 *
 * Bridges tools from remote MCP servers into the local tool registry,
 * making them available for assistants to use during chat sessions.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proxies a single remote MCP tool into the local tool system.
 *
 * Wraps a tool definition from a remote MCP server and implements
 * the local tool interface, forwarding execution to the remote server.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_MCP_App_Tool_Bridge implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Unique tool slug (prefixed to avoid collisions).
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * Tool name from the remote server.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Tool description from the remote server.
	 *
	 * @var string
	 */
	protected $description;

	/**
	 * Tool parameters schema from the remote server.
	 *
	 * @var array
	 */
	protected $parameters_schema;

	/**
	 * Remote tool name (used for tools/call).
	 *
	 * @var string
	 */
	protected $remote_tool_name;

	/**
	 * MCP App configuration for reconnecting.
	 *
	 * @var array
	 */
	protected $app_config;

	/**
	 * App display label for context.
	 *
	 * @var string
	 */
	protected $app_label;

	/**
	 * UI resource URI if the tool has an associated MCP App UI.
	 *
	 * @var string
	 */
	protected $ui_resource_uri;

	/**
	 * Constructor.
	 *
	 * @since 1.8.0
	 * @param array  $remote_tool Remote tool definition from MCP server.
	 * @param array  $app_config  MCP App connection configuration.
	 * @param string $app_label   Human-readable app label.
	 */
	public function __construct( array $remote_tool, array $app_config, $app_label = '' ) {
		$this->remote_tool_name  = isset( $remote_tool['name'] ) ? sanitize_text_field( $remote_tool['name'] ) : '';
		$this->slug              = 'mcp_app_' . sanitize_key( $app_label ) . '_' . sanitize_key( $this->remote_tool_name );
		$this->name              = isset( $remote_tool['name'] ) ? sanitize_text_field( $remote_tool['name'] ) : $this->slug;
		$this->description       = isset( $remote_tool['description'] ) ? sanitize_text_field( $remote_tool['description'] ) : '';
		$this->parameters_schema = isset( $remote_tool['inputSchema'] ) ? $remote_tool['inputSchema'] : array( 'type' => 'object' );
		$this->app_config        = $app_config;
		$this->app_label         = $app_label;

		// Extract UI resource URI from tool metadata (SEP-1865).
		$this->ui_resource_uri = '';
		if ( isset( $remote_tool['_meta']['ui']['resourceUri'] ) ) {
			$this->ui_resource_uri = sanitize_text_field( $remote_tool['_meta']['ui']['resourceUri'] );
		} elseif ( isset( $remote_tool['_meta']['ui/resourceUri'] ) ) {
			// Deprecated flat format.
			$this->ui_resource_uri = sanitize_text_field( $remote_tool['_meta']['ui/resourceUri'] );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		if ( ! empty( $this->app_label ) ) {
			/* translators: 1: Tool name, 2: MCP App label. */
			return sprintf( __( '%1$s (%2$s)', 'mcp-ai-wpoos-pro' ), $this->name, $this->app_label );
		}

		return $this->name;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		$desc = $this->description;

		if ( ! empty( $this->app_label ) ) {
			/* translators: 1: Tool description, 2: MCP App label. */
			$desc = sprintf( __( '%1$s (via MCP App: %2$s)', 'mcp-ai-wpoos-pro' ), $desc, $this->app_label );
		}

		return $desc;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return $this->parameters_schema;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the remote tool via the MCP App client.
	 *
	 * @since 1.8.0
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Require at least edit_posts for remote tool execution.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			// Allow guest requests if context permits.
			if ( empty( $context['guest_request'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to execute remote MCP tools.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		$client = new WP_MCP_AI_MCP_App_Client( $this->app_config );

		// Initialize session.
		$init_result = $client->initialize();
		if ( is_wp_error( $init_result ) ) {
			return $init_result;
		}

		// Call the remote tool.
		$result = $client->call_tool( $this->remote_tool_name, $arguments );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Normalize result to standard format.
		$output = array(
			'success'    => true,
			'source'     => 'mcp_app',
			'app_label'  => $this->app_label,
			'server_url' => $client->get_server_url(),
		);

		// Handle MCP tool result content array format.
		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			$text_parts = array();
			foreach ( $result['content'] as $content_block ) {
				if ( isset( $content_block['type'] ) && 'text' === $content_block['type'] && isset( $content_block['text'] ) ) {
					$text_parts[] = $content_block['text'];
				}
			}
			$output['message'] = implode( "\n", $text_parts );
			$output['content'] = $result['content'];
		} elseif ( isset( $result['message'] ) ) {
			$output['message'] = $result['message'];
		} else {
			$output['message'] = __( 'Tool executed successfully.', 'mcp-ai-wpoos-pro' );
			$output['data']    = $result;
		}

		// Include UI resource URI if available.
		if ( ! empty( $this->ui_resource_uri ) ) {
			$output['ui_resource_uri'] = $this->ui_resource_uri;
		}

		// Check for isError flag in MCP response.
		if ( ! empty( $result['isError'] ) ) {
			$output['success'] = false;
		}

		return $output;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.8.0
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		$definition = array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'mcp_apps',
			'pattern_compatibility' => array( 'skill_router', 'tool_chain' ),
			'profession_tags'       => array( 'integration_specialist' ),
			'risk_level'            => 'medium',
		);

		if ( ! empty( $this->ui_resource_uri ) ) {
			$definition['ui_resource_uri'] = $this->ui_resource_uri;
		}

		return $definition;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',        // Calls external MCP server.
			'requires-capability', // Requires user capabilities.
		);
	}

	/**
	 * Get the UI resource URI if available.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	public function get_ui_resource_uri() {
		return $this->ui_resource_uri;
	}

	/**
	 * Get the remote tool name.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	public function get_remote_tool_name() {
		return $this->remote_tool_name;
	}

	/**
	 * Get the app config.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	public function get_app_config() {
		return $this->app_config;
	}
}
