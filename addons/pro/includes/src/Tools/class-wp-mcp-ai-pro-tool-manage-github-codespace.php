<?php
/**
 * Tool that manages GitHub Codespaces for repository development.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-github-client.php';

/**
 * Provides an assistant tool for managing GitHub Codespaces.
 */
class WP_MCP_AI_Pro_Tool_Manage_Github_Codespace implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_github_codespace';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage GitHub Codespace', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create, start, stop, or list GitHub Codespaces for repository development.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'         => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: create, list, start, stop, get, or delete.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'create', 'list', 'start', 'stop', 'get', 'delete' ),
				),
				'owner'          => array(
					'type'        => 'string',
					'description' => __( 'Repository owner (required for create action).', 'mcp-ai-wpoos-pro' ),
				),
				'repo'           => array(
					'type'        => 'string',
					'description' => __( 'Repository name (required for create action).', 'mcp-ai-wpoos-pro' ),
				),
				'ref'            => array(
					'type'        => 'string',
					'description' => __( 'Git branch, tag, or commit to open in the codespace (default: main).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'main',
				),
				'machine'        => array(
					'type'        => 'string',
					'description' => __( 'Machine type for the codespace (e.g., basicLinux32gb).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'basicLinux32gb',
				),
				'codespace_name' => array(
					'type'        => 'string',
					'description' => __( 'Codespace name (required for start, stop, get, and delete actions).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters( 'wp_mcp_ai_github_codespace_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_github_forbidden', __( 'You do not have permission to manage GitHub Codespaces.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		if ( empty( $action ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_action', __( 'Action parameter is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$client = new WP_MCP_AI_Github_Client();

		switch ( $action ) {
			case 'list':
				return $this->handle_list( $client, $arguments );

			case 'create':
				return $this->handle_create( $client, $arguments );

			case 'start':
				return $this->handle_start( $client, $arguments );

			case 'stop':
				return $this->handle_stop( $client, $arguments );

			case 'get':
				return $this->handle_get( $client, $arguments );

			case 'delete':
				return $this->handle_delete( $client, $arguments );

			default:
				return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle the list action.
	 *
	 * @param WP_MCP_AI_Github_Client $client    GitHub client instance.
	 * @param array                   $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_list( $client, $arguments ) {
		$codespaces = $client->list_codespaces();

		if ( is_wp_error( $codespaces ) ) {
			return $codespaces;
		}

		$formatted = array_map(
			function ( $codespace ) {
				return array(
					'name'         => $codespace['name'],
					'display_name' => $codespace['display_name'] ?? $codespace['name'],
					'repository'   => $codespace['repository']['full_name'] ?? '',
					'state'        => $codespace['state'],
					'machine'      => $codespace['machine']['display_name'] ?? '',
					'web_url'      => $codespace['web_url'],
					'created_at'   => $codespace['created_at'],
					'updated_at'   => $codespace['last_used_at'] ?? $codespace['created_at'],
				);
			},
			$codespaces['codespaces'] ?? array()
		);

		return array(
			'codespaces' => $formatted,
			'count'      => count( $formatted ),
		);
	}

	/**
	 * Handle the create action.
	 *
	 * @param WP_MCP_AI_Github_Client $client    GitHub client instance.
	 * @param array                   $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_create( $client, $arguments ) {
		$owner = isset( $arguments['owner'] ) ? sanitize_text_field( $arguments['owner'] ) : '';
		$repo  = isset( $arguments['repo'] ) ? sanitize_text_field( $arguments['repo'] ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_params', __( 'Owner and repo parameters are required for create action.', 'mcp-ai-wpoos-pro' ) );
		}

		$codespace_args = array();

		if ( ! empty( $arguments['ref'] ) ) {
			$codespace_args['ref'] = sanitize_text_field( $arguments['ref'] );
		}

		if ( ! empty( $arguments['machine'] ) ) {
			$codespace_args['machine'] = sanitize_text_field( $arguments['machine'] );
		}

		$codespace = $client->create_codespace( $owner, $repo, $codespace_args );

		if ( is_wp_error( $codespace ) ) {
			return $codespace;
		}

		return array(
			'success'   => true,
			'message'   => __( 'Codespace created successfully.', 'mcp-ai-wpoos-pro' ),
			'codespace' => array(
				'name'       => $codespace['name'],
				'web_url'    => $codespace['web_url'],
				'state'      => $codespace['state'],
				'repository' => $codespace['repository']['full_name'] ?? '',
			),
		);
	}

	/**
	 * Handle the start action.
	 *
	 * @param WP_MCP_AI_Github_Client $client    GitHub client instance.
	 * @param array                   $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_start( $client, $arguments ) {
		$codespace_name = isset( $arguments['codespace_name'] ) ? sanitize_text_field( $arguments['codespace_name'] ) : '';

		if ( empty( $codespace_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_codespace', __( 'Codespace name is required for start action.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $client->start_codespace( $codespace_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => __( 'Codespace started successfully.', 'mcp-ai-wpoos-pro' ),
			'state'   => $result['state'] ?? 'starting',
		);
	}

	/**
	 * Handle the stop action.
	 *
	 * @param WP_MCP_AI_Github_Client $client    GitHub client instance.
	 * @param array                   $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_stop( $client, $arguments ) {
		$codespace_name = isset( $arguments['codespace_name'] ) ? sanitize_text_field( $arguments['codespace_name'] ) : '';

		if ( empty( $codespace_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_codespace', __( 'Codespace name is required for stop action.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $client->stop_codespace( $codespace_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => __( 'Codespace stopped successfully.', 'mcp-ai-wpoos-pro' ),
			'state'   => $result['state'] ?? 'stopping',
		);
	}

	/**
	 * Handle the get action.
	 *
	 * @param WP_MCP_AI_Github_Client $client    GitHub client instance.
	 * @param array                   $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_get( $client, $arguments ) {
		$codespace_name = isset( $arguments['codespace_name'] ) ? sanitize_text_field( $arguments['codespace_name'] ) : '';

		if ( empty( $codespace_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_codespace', __( 'Codespace name is required for get action.', 'mcp-ai-wpoos-pro' ) );
		}

		$codespace = $client->get_codespace( $codespace_name );

		if ( is_wp_error( $codespace ) ) {
			return $codespace;
		}

		return array(
			'codespace' => array(
				'name'       => $codespace['name'],
				'state'      => $codespace['state'],
				'web_url'    => $codespace['web_url'],
				'repository' => $codespace['repository']['full_name'] ?? '',
				'machine'    => $codespace['machine']['display_name'] ?? '',
				'created_at' => $codespace['created_at'],
			),
		);
	}

	/**
	 * Handle the delete action.
	 *
	 * @param WP_MCP_AI_Github_Client $client    GitHub client instance.
	 * @param array                   $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_delete( $client, $arguments ) {
		$codespace_name = isset( $arguments['codespace_name'] ) ? sanitize_text_field( $arguments['codespace_name'] ) : '';

		if ( empty( $codespace_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_codespace', __( 'Codespace name is required for delete action.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $client->delete_codespace( $codespace_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => __( 'Codespace deleted successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Tool is part of the Pro tier.
			'read-only',            // For list and get actions.
			'write',                // For create action.
			'state-changing',       // Creates, starts, stops, and deletes codespaces.
			'requires-credentials', // Requires GitHub OAuth authentication.
			'requires-capability',  // Requires appropriate user capabilities.
			'external-api',         // Makes external API calls to GitHub.
			'network-dependent',    // Requires internet connectivity.
			'long-running',         // Codespace creation can take time.
			'consumes-tokens',      // GitHub Codespaces may incur costs.
		);
	}
}
