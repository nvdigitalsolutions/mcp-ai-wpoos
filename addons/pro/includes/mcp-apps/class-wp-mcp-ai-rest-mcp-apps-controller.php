<?php
/**
 * REST Controller for MCP Apps management.
 *
 * Provides REST API endpoints for testing MCP App connections,
 * discovering available tools from remote MCP servers, and managing
 * OAuth 2.0 web login authentication.
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
 * REST API controller for MCP Apps.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_REST_MCP_Apps_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Transient key prefix for OAuth flow state.
	 *
	 * @var string
	 */
	const OAUTH_STATE_TRANSIENT = 'wp_mcp_ai_mcp_app_oauth_state_';

	/**
	 * OAuth state transient TTL (10 minutes).
	 *
	 * @var int
	 */
	const OAUTH_STATE_TTL = 600;

	/**
	 * Register REST routes.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/test',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_connection' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'server_url'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
							'description'       => __( 'Remote MCP server endpoint URL.', 'mcp-ai-wpoos-pro' ),
						),
						'auth_type'   => array(
							'type'              => 'string',
							'default'           => 'none',
							'enum'              => array( 'none', 'bearer', 'header', 'oauth' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'token'       => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'header_name' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'timeout'     => array(
							'type'    => 'integer',
							'default' => 30,
							'minimum' => 1,
							'maximum' => 120,
						),
						'verify_ssl'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/discover',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'discover_tools' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'server_url'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
						),
						'auth_type'   => array(
							'type'              => 'string',
							'default'           => 'none',
							'enum'              => array( 'none', 'bearer', 'header', 'oauth' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'token'       => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'header_name' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'timeout'     => array(
							'type'    => 'integer',
							'default' => 30,
							'minimum' => 1,
							'maximum' => 120,
						),
						'verify_ssl'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/(?P<assistant_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_assistant_apps' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'assistant_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// OAuth: probe whether a remote server supports OAuth web login.
		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/oauth/probe',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'probe_oauth' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'server_url' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
						),
					),
				),
			)
		);

		// OAuth: initiate the authorization flow (returns redirect URL).
		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/oauth/init',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'initiate_oauth' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'server_url'   => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
						),
						'assistant_id' => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
						'scope'        => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// OAuth callback — handles redirect from remote authorization server.
		// Public endpoint because the remote server redirects the browser here.
		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/oauth/callback',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_oauth_callback' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'code'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'state' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		// OAuth: refresh an existing OAuth token.
		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/oauth/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh_oauth_token' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'server_url'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
						),
						'refresh_token' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// OAuth: revoke a token.
		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/oauth/revoke',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'revoke_oauth_token' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'server_url' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
						),
						'token'      => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback for admin-only endpoints.
	 *
	 * @since 1.8.0
	 * @return bool|WP_Error
	 */
	public function check_admin_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage MCP Apps.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Test connection to a remote MCP server.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection( WP_REST_Request $request ) {
		$server_url = $request->get_param( 'server_url' );

		// Validate URL against the allowlist before issuing any outbound HTTP.
		$allowed = WP_MCP_AI_MCP_App_Registry::is_url_allowed( $server_url );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$config = array(
			'server_url'  => $server_url,
			'auth_type'   => $request->get_param( 'auth_type' ),
			'token'       => $request->get_param( 'token' ),
			'header_name' => $request->get_param( 'header_name' ),
			'timeout'     => $request->get_param( 'timeout' ),
			'verify_ssl'  => $request->get_param( 'verify_ssl' ),
		);

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$result   = $registry->test_connection( $config );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Discover tools from a remote MCP server.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function discover_tools( WP_REST_Request $request ) {
		$config = array(
			'server_url'  => $request->get_param( 'server_url' ),
			'auth_type'   => $request->get_param( 'auth_type' ),
			'token'       => $request->get_param( 'token' ),
			'header_name' => $request->get_param( 'header_name' ),
			'timeout'     => $request->get_param( 'timeout' ),
			'verify_ssl'  => $request->get_param( 'verify_ssl' ),
		);

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$tools    = $registry->discover_tools( $config );

		if ( is_wp_error( $tools ) ) {
			return $tools;
		}

		// Format tools for display.
		$formatted = array();
		foreach ( $tools as $tool ) {
			$formatted_tool = array(
				'name'        => isset( $tool['name'] ) ? $tool['name'] : '',
				'description' => isset( $tool['description'] ) ? $tool['description'] : '',
				'has_ui'      => ! empty( $tool['_meta']['ui']['resourceUri'] ) || ! empty( $tool['_meta']['ui/resourceUri'] ),
			);

			if ( isset( $tool['inputSchema'] ) ) {
				$formatted_tool['parameters'] = $tool['inputSchema'];
			}

			$formatted[] = $formatted_tool;
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'tool_count' => count( $formatted ),
				'tools'      => $formatted,
			)
		);
	}

	/**
	 * Get MCP Apps configured for an assistant.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_assistant_apps( WP_REST_Request $request ) {
		$assistant_id = $request->get_param( 'assistant_id' );

		$post = get_post( $assistant_id );
		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_not_found',
				__( 'Assistant not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$apps     = $registry->get_apps( $assistant_id );

		// Mask tokens in response.
		$safe_apps = array();
		foreach ( $apps as $app ) {
			$app_copy = $app;
			if ( ! empty( $app_copy['token'] ) ) {
				$token_length      = strlen( $app_copy['token'] );
				$app_copy['token'] = ( $token_length > 8 )
					? '••••••••' . substr( $app_copy['token'], -4 )
					: '••••••••';
			}
			// Mask OAuth tokens too.
			if ( ! empty( $app_copy['oauth_data']['access_token'] ) ) {
				$app_copy['oauth_data']['access_token'] = '••••••••';
			}
			if ( ! empty( $app_copy['oauth_data']['refresh_token'] ) ) {
				$app_copy['oauth_data']['refresh_token'] = '••••••••';
			}
			$safe_apps[] = $app_copy;
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'assistant_id' => $assistant_id,
				'apps'         => $safe_apps,
				'app_count'    => count( $safe_apps ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// OAuth Endpoint Handlers
	// -----------------------------------------------------------------------

	/**
	 * Probe whether a remote MCP server supports OAuth web login.
	 *
	 * Attempts OAuth metadata discovery from the remote server and returns
	 * whether OAuth authentication is available.
	 *
	 * @since 1.9.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function probe_oauth( WP_REST_Request $request ) {
		$server_url = $request->get_param( 'server_url' );

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_oauth_unavailable',
				__( 'OAuth client is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$oauth_client = new WP_MCP_AI_MCP_App_OAuth_Client( $server_url );
		$metadata     = $oauth_client->discover_metadata();

		if ( is_wp_error( $metadata ) ) {
			return rest_ensure_response(
				array(
					'success'        => true,
					'supports_oauth' => false,
					'message'        => $metadata->get_error_message(),
				)
			);
		}

		$scopes           = isset( $metadata['scopes_supported'] ) ? $metadata['scopes_supported'] : array();
		$has_registration = ! empty( $metadata['registration_endpoint'] );

		return rest_ensure_response(
			array(
				'success'                => true,
				'supports_oauth'         => true,
				'has_registration'       => $has_registration,
				'scopes_supported'       => $scopes,
				'authorization_endpoint' => isset( $metadata['authorization_endpoint'] ) ? $metadata['authorization_endpoint'] : '',
				'metadata'               => array(
					'issuer' => isset( $metadata['issuer'] ) ? $metadata['issuer'] : '',
				),
			)
		);
	}

	/**
	 * Initiate the OAuth 2.0 authorization code flow for an MCP App.
	 *
	 * Generates PKCE challenge, registers as a dynamic client if needed,
	 * stores flow state in a transient, and returns the authorization URL
	 * that the admin should be redirected to.
	 *
	 * @since 1.9.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function initiate_oauth( WP_REST_Request $request ) {
		$server_url   = $request->get_param( 'server_url' );
		$assistant_id = $request->get_param( 'assistant_id' );
		$scope        = $request->get_param( 'scope' );

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_oauth_unavailable',
				__( 'OAuth client is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$oauth_client = new WP_MCP_AI_MCP_App_OAuth_Client( $server_url );

		// Check if OAuth is supported.
		$discovery = $oauth_client->discover_metadata();
		if ( is_wp_error( $discovery ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_not_supported',
				sprintf(
					/* translators: 1: Server URL, 2: Error message. */
					__( 'OAuth 2.0 discovery failed for %1$s: %2$s', 'mcp-ai-wpoos-pro' ),
					$server_url,
					$discovery->get_error_message()
				),
				array(
					'status'     => 400,
					'error_code' => $discovery->get_error_code(),
					'message'    => $discovery->get_error_message(),
				)
			);
		}

		// Register client if supported.
		$reg_result = $oauth_client->register_client();
		if ( is_wp_error( $reg_result ) ) {
			return $reg_result;
		}

		// Generate PKCE and state.
		$pkce  = $oauth_client->generate_pkce();
		$state = $oauth_client->generate_state();

		// Build authorization URL.
		$auth_url = $oauth_client->get_authorization_url( $scope );
		if ( is_wp_error( $auth_url ) ) {
			return $auth_url;
		}

		// Store flow state in a transient for the callback.
		$flow_state = array(
			'server_url'    => $server_url,
			'assistant_id'  => absint( $assistant_id ),
			'code_verifier' => $oauth_client->get_code_verifier(),
			'client_id'     => $oauth_client->get_client_id(),
			'redirect_uri'  => $oauth_client->get_redirect_uri(),
			'scope'         => $scope,
			'created_at'    => time(),
		);

		set_transient( self::OAUTH_STATE_TRANSIENT . $state, $flow_state, self::OAUTH_STATE_TTL );

		return rest_ensure_response(
			array(
				'success'           => true,
				'authorization_url' => $auth_url,
				'state'             => $state,
				'client_id'         => $oauth_client->get_client_id(),
			)
		);
	}

	/**
	 * Handle the OAuth callback from the remote authorization server.
	 *
	 * The remote server redirects the user's browser here after they
	 * authorize the MCP App. We exchange the authorization code for
	 * tokens, store them, and display a success page.
	 *
	 * @since 1.9.0
	 * @param WP_REST_Request $request Request object.
	 * @return void
	 */
	public function handle_oauth_callback( WP_REST_Request $request ) {
		$code  = $request->get_param( 'code' );
		$state = $request->get_param( 'state' );

		if ( empty( $code ) || empty( $state ) ) {
			// Check for error from authorization server.
			$error = $request->get_param( 'error' );
			if ( ! empty( $error ) ) {
				$error_desc = $request->get_param( 'error_description' );
				wp_die(
					esc_html(
						sprintf(
						/* translators: 1: Error code, 2: Error description. */
							__( 'OAuth authorization failed: %1$s — %2$s', 'mcp-ai-wpoos-pro' ),
							$error,
							$error_desc ? $error_desc : __( 'No description.', 'mcp-ai-wpoos-pro' )
						)
					),
					esc_html__( 'OAuth Error', 'mcp-ai-wpoos-pro' ),
					array( 'response' => 400 )
				);
			}

			wp_die(
				esc_html__( 'Invalid OAuth callback: missing code or state parameter.', 'mcp-ai-wpoos-pro' ),
				esc_html__( 'OAuth Error', 'mcp-ai-wpoos-pro' ),
				array( 'response' => 400 )
			);
		}

		// Retrieve flow state from transient.
		$flow_state = get_transient( self::OAUTH_STATE_TRANSIENT . $state );
		if ( ! is_array( $flow_state ) ) {
			wp_die(
				esc_html__( 'OAuth state has expired or is invalid. Please try again.', 'mcp-ai-wpoos-pro' ),
				esc_html__( 'OAuth Error', 'mcp-ai-wpoos-pro' ),
				array( 'response' => 400 )
			);
		}

		// Clean up the transient immediately.
		delete_transient( self::OAUTH_STATE_TRANSIENT . $state );

		// Exchange the authorization code for tokens.
		$oauth_client = new WP_MCP_AI_MCP_App_OAuth_Client( $flow_state['server_url'] );
		$oauth_client->set_client_id( $flow_state['client_id'] );

		$token_result = $oauth_client->exchange_code( $code, $state, $flow_state['code_verifier'] );

		if ( is_wp_error( $token_result ) ) {
			wp_die(
				esc_html(
					sprintf(
					/* translators: %s: Error message. */
						__( 'Failed to exchange authorization code: %s', 'mcp-ai-wpoos-pro' ),
						$token_result->get_error_message()
					)
				),
				esc_html__( 'OAuth Error', 'mcp-ai-wpoos-pro' ),
				array( 'response' => 400 )
			);
		}

		// If an assistant ID was provided, auto-save the OAuth config.
		$assistant_id = absint( $flow_state['assistant_id'] );
		if ( $assistant_id ) {
			$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
			$existing = $registry->get_apps( $assistant_id );

			// Build app config with OAuth data.
			$new_app = array(
				'label'      => wp_parse_url( $flow_state['server_url'], PHP_URL_HOST ),
				'server_url' => $flow_state['server_url'],
				'auth_type'  => 'oauth',
				'enabled'    => true,
				'timeout'    => 30,
				'verify_ssl' => true,
				'oauth_data' => $token_result,
			);

			// Check for existing app with same URL to update.
			$updated = false;
			foreach ( $existing as $i => $app ) {
				if ( isset( $app['server_url'] ) && $app['server_url'] === $flow_state['server_url'] ) {
					$existing[ $i ] = array_merge( $app, $new_app );
					$updated        = true;
					break;
				}
			}
			if ( ! $updated ) {
				$existing[] = $new_app;
			}

			$registry->save_apps( $assistant_id, $existing );
		}

		// Render success page and terminate (browser-facing callback).
		$this->render_oauth_success_page( $flow_state['server_url'], $token_result, $assistant_id );
	}

	/**
	 * Refresh an OAuth access token.
	 *
	 * @since 1.9.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh_oauth_token( WP_REST_Request $request ) {
		$server_url    = $request->get_param( 'server_url' );
		$refresh_token = $request->get_param( 'refresh_token' );

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_oauth_unavailable',
				__( 'OAuth client is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$oauth_client = new WP_MCP_AI_MCP_App_OAuth_Client( $server_url );
		$result       = $oauth_client->refresh_token( $refresh_token );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'token_data' => $result,
			)
		);
	}

	/**
	 * Revoke an OAuth token with the remote server.
	 *
	 * @since 1.9.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_oauth_token( WP_REST_Request $request ) {
		$server_url = $request->get_param( 'server_url' );
		$token      = $request->get_param( 'token' );

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_oauth_unavailable',
				__( 'OAuth client is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$oauth_client = new WP_MCP_AI_MCP_App_OAuth_Client( $server_url );
		$success      = $oauth_client->revoke_token( $token );

		return rest_ensure_response(
			array(
				'success' => $success,
			)
		);
	}

	/**
	 * Render the OAuth success page after callback.
	 *
	 * Displays a brief HTML page confirming successful authentication
	 * and providing next steps.
	 *
	 * @since 1.9.0
	 * @param string $server_url   The remote MCP server URL.
	 * @param array  $token_data   The token response data.
	 * @param int    $assistant_id Optional assistant ID where the app was saved.
	 * @return void
	 */
	protected function render_oauth_success_page( $server_url, $token_data, $assistant_id = 0 ) {
		$expires_in = isset( $token_data['expires_in'] ) ? absint( $token_data['expires_in'] ) : 3600;
		$scope      = isset( $token_data['scope'] ) ? esc_html( $token_data['scope'] ) : __( 'default', 'mcp-ai-wpoos-pro' );

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'MCP App Connected', 'mcp-ai-wpoos-pro' ); ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 40px auto; max-width: 600px; padding: 0 20px; color: #333; }
				.success-icon { font-size: 48px; text-align: center; margin-bottom: 20px; }
				h1 { font-size: 24px; text-align: center; margin-bottom: 10px; }
				p { font-size: 16px; line-height: 1.5; text-align: center; color: #666; }
				.info-box { background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 8px; padding: 16px; margin: 20px 0; }
				.info-box dt { font-weight: 600; margin-top: 8px; }
				.info-box dd { margin-left: 0; color: #555; }
				.actions { text-align: center; margin-top: 24px; }
				.btn { display: inline-block; padding: 10px 24px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 4px; font-size: 14px; }
				.btn:hover { background: #135e96; }
			</style>
		</head>
		<body>
			<div class="success-icon">✅</div>
			<h1><?php esc_html_e( 'MCP App Connected!', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php esc_html_e( 'Your MCP App has been successfully authenticated via web login.', 'mcp-ai-wpoos-pro' ); ?></p>

			<div class="info-box">
				<dl>
					<dt><?php esc_html_e( 'Server', 'mcp-ai-wpoos-pro' ); ?></dt>
					<dd><?php echo esc_html( $server_url ); ?></dd>

					<dt><?php esc_html_e( 'Scope', 'mcp-ai-wpoos-pro' ); ?></dt>
					<dd><?php echo esc_html( $scope ); ?></dd>

					<dt><?php esc_html_e( 'Token Expires', 'mcp-ai-wpoos-pro' ); ?></dt>
					<dd><?php echo esc_html( human_time_diff( time() + $expires_in ) ); ?></dd>
				</dl>
			</div>

			<p class="description">
				<?php esc_html_e( 'The access token will be automatically refreshed when needed. You can close this window now.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<?php if ( $assistant_id ) : ?>
				<div class="actions">
					<a class="btn" href="<?php echo esc_url( admin_url( 'post.php?post=' . $assistant_id . '&action=edit' ) ); ?>">
						<?php esc_html_e( 'Back to Assistant Settings', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</body>
		</html>
		<?php
		exit;
	}
}
