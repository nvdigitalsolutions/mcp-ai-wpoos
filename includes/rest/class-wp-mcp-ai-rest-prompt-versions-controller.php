<?php
/**
 * REST API controller for assistant prompt versions (Proposal 091, Track B).
 *
 * Exposes the prompt-versioning store over REST:
 *
 *   - GET  /mcp-ai/v1/assistants/{id}/prompt-versions
 *   - POST /mcp-ai/v1/assistants/{id}/prompt-versions/{version}/restore
 *   - POST /mcp-ai/v1/assistants/{id}/prompt-versions/{version}/promote
 *
 * All routes are admin-only (`manage_options`) — prompt content is sensitive
 * and restore/promote mutate assistant configuration.
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent fatal error if WP_REST_Controller is not available yet.
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

/**
 * Assistant prompt versions REST controller.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_REST_Prompt_Versions_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Capability required for every route.
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @since 1.2.13
	 */
	public function __construct() {
		// Routes are registered by calling register_routes() from the main REST controller.
	}

	/**
	 * Register the prompt-version routes.
	 *
	 * @since 1.2.13
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/assistants/(?P<id>[\d]+)/prompt-versions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_id_args(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/assistants/(?P<id>[\d]+)/prompt-versions/(?P<version>[\d]+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_restore' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array_merge(
					$this->get_id_args(),
					array(
						'version' => array(
							'description' => __( 'Version number to restore.', 'mcp-ai-wpoos' ),
							'type'        => 'integer',
							'minimum'     => 1,
							'required'    => true,
						),
					)
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/assistants/(?P<id>[\d]+)/prompt-versions/(?P<version>[\d]+)/promote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_promote' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array_merge(
					$this->get_id_args(),
					array(
						'version' => array(
							'description' => __( 'Version number to promote to production.', 'mcp-ai-wpoos' ),
							'type'        => 'integer',
							'minimum'     => 1,
							'required'    => true,
						),
					)
				),
			)
		);
	}

	/**
	 * Shared route args for the assistant ID.
	 *
	 * @since 1.2.13
	 * @return array[]
	 */
	private function get_id_args() {
		return array(
			'id' => array(
				'description'       => __( 'Assistant post ID.', 'mcp-ai-wpoos' ),
				'type'              => 'integer',
				'minimum'           => 1,
				'required'          => true,
				'validate_callback' => function ( $value ) {
					$post = get_post( absint( $value ) );
					if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
						return new WP_Error(
							'wp_mcp_ai_prompt_version_assistant_not_found',
							__( 'Assistant not found.', 'mcp-ai-wpoos' ),
							array( 'status' => 404 )
						);
					}
					return true;
				},
			),
		);
	}

	/**
	 * Permission callback: admin-only.
	 *
	 * @since 1.2.13
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You are not allowed to manage prompt versions.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * List prompt versions for an assistant.
	 *
	 * @since 1.2.13
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_list( $request ) {
		$post_id   = absint( $request['id'] );
		$versions  = WP_MCP_AI_Prompt_Versions::get_versions( $post_id );
		$published = WP_MCP_AI_Prompt_Versions::get_production( $post_id );

		$data = array_map(
			function ( $entry ) use ( $published ) {
				return array_merge(
					$entry,
					array(
						'production' => $entry['v'] === $published,
					)
				);
			},
			$versions
		);

		return rest_ensure_response(
			array(
				'success'    => true,
				'assistant'  => $post_id,
				'production' => $published,
				'versions'   => $data,
			)
		);
	}

	/**
	 * Restore a prompt version.
	 *
	 * @since 1.2.13
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_restore( $request ) {
		$result = WP_MCP_AI_Prompt_Versions::restore( absint( $request['id'] ), absint( $request['version'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Promote a prompt version to production.
	 *
	 * @since 1.2.13
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_promote( $request ) {
		$result = WP_MCP_AI_Prompt_Versions::promote( absint( $request['id'] ), absint( $request['version'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}
