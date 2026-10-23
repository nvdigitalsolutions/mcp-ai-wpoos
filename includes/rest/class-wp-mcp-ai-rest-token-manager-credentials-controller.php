<?php
/**
 * REST controller for the assistant token manager (Proposal 084 Phase 3).
 *
 * Wraps the token-manager admin page's data path behind REST so the
 * Complete Console can replace it:
 *   GET  /token-manager/overview
 *   POST /token-manager/credentials/{assistant_id}/{credential_id}/revoke
 *   POST /token-manager/credentials/{assistant_id}/{credential_id}/delete
 *   POST /token-manager/restrictions/{user_id}/lift
 *
 * All writes wrap WP_MCP_AI_Credentials / WP_MCP_AI_Restriction_Registry —
 * the same services the wp-admin admin-post handlers use, so no logic
 * forks. Credential hashes are never serialized out of the service layer.
 *
 * @package WP_MCP_AI
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token manager REST controller.
 */
class WP_MCP_AI_REST_Token_Manager_Credentials_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/token-manager';

	/**
	 * Register the token-manager routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/overview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_overview' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/credentials/(?P<assistant_id>\d+)/(?P<credential_id>[a-z0-9_\-]+)/(?P<action>revoke|delete)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_credential_action' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'assistant_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'credential_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'action'        => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'revoke', 'delete' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/restrictions/(?P<user_id>\d+)/lift',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_lift_restriction' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'user_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'type'    => array(
						'type'              => 'string',
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Permission check — token administration is administrator-only.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /token-manager/overview — stats, credentials, and restrictions.
	 *
	 * @since 2.4.0
	 * @return WP_REST_Response
	 */
	public function get_overview() {
		$credentials = $this->collect_credentials();

		$active                 = 0;
		$revoked                = 0;
		$assistants_with_tokens = array();

		foreach ( $credentials as $credential ) {
			if ( 'revoked' === $credential['status'] ) {
				++$revoked;
			} else {
				++$active;
			}
			$assistants_with_tokens[ $credential['assistant_id'] ] = true;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'stats'        => array(
						'total'      => count( $credentials ),
						'active'     => $active,
						'revoked'    => $revoked,
						'assistants' => count( $assistants_with_tokens ),
					),
					'credentials'  => $credentials,
					'restrictions' => $this->collect_restrictions(),
				),
			)
		);
	}

	/**
	 * POST /token-manager/credentials/{assistant}/{credential}/{revoke,delete}.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_credential_action( WP_REST_Request $request ) {
		$assistant_id  = $request->get_param( 'assistant_id' );
		$credential_id = $request->get_param( 'credential_id' );
		$action        = $request->get_param( 'action' );

		$result = 'revoke' === $action
			? WP_MCP_AI_Credentials::revoke_credential( $assistant_id, $credential_id, get_current_user_id() )
			: WP_MCP_AI_Credentials::delete_credential( $assistant_id, $credential_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'action'     => $action,
					'credential' => $this->public_credential( $result, $assistant_id, '' ),
				),
			)
		);
	}

	/**
	 * POST /token-manager/restrictions/{user_id}/lift.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_lift_restriction( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
			return new WP_Error(
				'wp_mcp_ai_restrictions_unavailable',
				__( 'The restriction registry is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 501 )
			);
		}

		$user_id = $request->get_param( 'user_id' );
		$type    = $request->get_param( 'type' );

		$result = WP_MCP_AI_Restriction_Registry::lift( $user_id, $type, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array( 'lifted' => true ),
			)
		);
	}

	/**
	 * Collect credentials across every assistant, newest first.
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_credentials() {
		$rows = array();

		if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
			return $rows;
		}

		$assistants = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Credentials::ASSISTANT_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $assistants as $assistant_id ) {
			$credentials = WP_MCP_AI_Credentials::get_credentials( $assistant_id );
			$assistant   = get_post( $assistant_id );

			if ( ! $assistant || ! is_array( $credentials ) ) {
				continue;
			}

			foreach ( $credentials as $credential ) {
				$rows[] = $this->public_credential( $credential, $assistant_id, $assistant->post_title );
			}
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return strtotime( $b['created_at'] ) - strtotime( $a['created_at'] );
			}
		);

		return $rows;
	}

	/**
	 * Map a stored credential onto the public SPA shape (hash never leaves).
	 *
	 * @since 2.4.0
	 * @param array  $credential    Stored credential record.
	 * @param int    $assistant_id  Assistant post ID.
	 * @param string $assistant_title Assistant post title.
	 * @return array
	 */
	private function public_credential( $credential, $assistant_id, $assistant_title ) {
		$created_by = isset( $credential['created_by'] ) ? absint( $credential['created_by'] ) : 0;
		$revoked_by = isset( $credential['revoked_by'] ) ? absint( $credential['revoked_by'] ) : 0;

		return array(
			'id'              => isset( $credential['id'] ) ? sanitize_key( $credential['id'] ) : '',
			'status'          => empty( $credential['revoked_at'] ) ? 'active' : 'revoked',
			'assistant_id'    => absint( $assistant_id ),
			'assistant_title' => sanitize_text_field( (string) $assistant_title ),
			'created_at'      => isset( $credential['created_at'] ) ? sanitize_text_field( $credential['created_at'] ) : '',
			'created_by'      => $created_by,
			'created_by_name' => $this->user_display_name( $created_by ),
			'revoked_at'      => isset( $credential['revoked_at'] ) ? sanitize_text_field( $credential['revoked_at'] ) : '',
			'revoked_by'      => $revoked_by,
			'expires_at'      => isset( $credential['expires_at'] ) ? sanitize_text_field( $credential['expires_at'] ) : '',
		);
	}

	/**
	 * Active restriction rows (the token-manager page's restrictions panel).
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_restrictions() {
		if ( ! class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
			return array();
		}

		$data = WP_MCP_AI_Restriction_Registry::get_active(
			array(
				'per_page' => 50,
				'page'     => 1,
			)
		);

		if ( ! is_array( $data ) || empty( $data['rows'] ) ) {
			return array();
		}

		$rows = array();
		foreach ( $data['rows'] as $row ) {
			$rows[] = array(
				'user_id'      => isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0,
				'user_login'   => isset( $row['user_login'] ) ? sanitize_text_field( $row['user_login'] ) : '',
				'display_name' => isset( $row['display_name'] ) ? sanitize_text_field( $row['display_name'] ) : '',
				'type'         => isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '',
				'type_label'   => isset( $row['type_label'] ) ? sanitize_text_field( $row['type_label'] ) : '',
				'scope'        => isset( $row['scope'] ) ? sanitize_text_field( $row['scope'] ) : '',
				'reason'       => isset( $row['reason'] ) ? sanitize_text_field( $row['reason'] ) : '',
				'triggered_at' => isset( $row['triggered_at'] ) ? absint( $row['triggered_at'] ) : 0,
			);
		}

		return $rows;
	}

	/**
	 * Resolve a display name, falling back like the admin page.
	 *
	 * @since 2.4.0
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function user_display_name( $user_id ) {
		$user_id = absint( $user_id );
		if ( 0 === $user_id ) {
			return __( 'System', 'mcp-ai-wpoos' );
		}

		$user = get_userdata( $user_id );
		return $user ? sanitize_text_field( $user->display_name ) : __( 'Unknown', 'mcp-ai-wpoos' );
	}

	/**
	 * Attach an HTTP status to a service WP_Error so REST fails readably.
	 *
	 * @since 2.4.0
	 * @param WP_Error $error Service error.
	 * @return WP_Error
	 */
	private function with_status( WP_Error $error ) {
		$status = 400;
		switch ( $error->get_error_code() ) {
			case 'wp_mcp_ai_invalid_assistant':
			case 'wp_mcp_ai_unknown_credential':
				$status = 404;
				break;
			case 'wp_mcp_ai_credential_already_revoked':
				$status = 400;
				break;
		}

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array( 'status' => $status )
		);
	}
}
