<?php
/**
 * Tool for Yahoo Fantasy Football OAuth authentication.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Yahoo Fantasy Sports API OAuth authentication.
 * Manages OAuth tokens and authorization for accessing user's fantasy leagues.
 */
class WP_MCP_AI_Tool_Yahoo_FF_Auth implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'yahoo_ff_auth';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Yahoo Fantasy Football - Authenticate', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Initiates Yahoo Fantasy Sports API OAuth authentication. Generates authorization URL for users to grant access to their fantasy football leagues. Returns status and stored credentials.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'       => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: "get_auth_url" to generate authorization URL, "get_status" to check authentication status, "revoke" to remove stored credentials.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'get_auth_url', 'get_status', 'revoke' ),
					'default'     => 'get_status',
				),
				'callback_url' => array(
					'type'        => 'string',
					'description' => __( 'Callback URL for OAuth redirect (required for get_auth_url action).', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array(),
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

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to access Yahoo Fantasy Sports authentication.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'get_status';

		switch ( $action ) {
			case 'get_auth_url':
				return $this->get_authorization_url( $arguments, $user_id );

			case 'get_status':
				return $this->get_authentication_status( $user_id );

			case 'revoke':
				return $this->revoke_credentials( $user_id );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					/* translators: %s: Invalid action name */
					sprintf( __( 'Invalid action: %s', 'mcp-ai-wpoos' ), $action )
				);
		}
	}

	/**
	 * Generate Yahoo OAuth authorization URL.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Authorization URL or error.
	 */
	protected function get_authorization_url( array $arguments, $user_id ) {
		// Get credentials from centralized settings.
		$settings  = WP_MCP_AI_Admin_Settings::get_settings();
		$client_id = isset( $settings['yahoo_client_id'] ) ? trim( $settings['yahoo_client_id'] ) : '';

		// Fallback to legacy option for backward compatibility.
		if ( empty( $client_id ) ) {
			$client_id = get_option( 'wp_mcp_ai_yahoo_client_id' );
		}

		if ( empty( $client_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'Yahoo API credentials are not configured. Please add your Yahoo Client ID and Client Secret in Settings → NV oOS → Tools → Connections → Yahoo Sports.', 'mcp-ai-wpoos' )
			);
		}

		$callback_url = isset( $arguments['callback_url'] ) ? esc_url_raw( $arguments['callback_url'] ) : '';

		if ( empty( $callback_url ) ) {
			$callback_url = admin_url( 'admin.php?page=wp-mcp-ai-yahoo-callback' );
		}

		// Generate state token for CSRF protection.
		$state = wp_generate_password( 32, false );
		update_user_meta( $user_id, 'wp_mcp_ai_yahoo_oauth_state', $state );
		update_user_meta( $user_id, 'wp_mcp_ai_yahoo_oauth_timestamp', time() );

		$auth_url = add_query_arg(
			array(
				'client_id'     => rawurlencode( $client_id ),
				'redirect_uri'  => rawurlencode( $callback_url ),
				'response_type' => 'code',
				'scope'         => 'fspt-r', // Fantasy Sports Read access.
				'state'         => $state,
			),
			'https://api.login.yahoo.com/oauth2/request_auth'
		);

		// Get research page URL for easy access.
		$research_page_url = admin_url( 'edit.php?post_type=ff_team&page=research-fantasy-football' );

		// Create a user-friendly message with clickable links using markdown format.
		$message = __( 'To connect your Yahoo Fantasy Football account, click the button below:', 'mcp-ai-wpoos' ) . "\n\n";
		$message .= '[**🔗 Connect to Yahoo Fantasy Football**](' . esc_url( $auth_url ) . ')' . "\n\n";
		$message .= __( 'After authorization, you can use the Fantasy Football Research page:', 'mcp-ai-wpoos' ) . "\n\n";
		$message .= '[**📊 Open Fantasy Football Research**](' . esc_url( $research_page_url ) . ')';

		return array(
			'action'       => 'get_auth_url',
			'status'       => 'success',
			'auth_url'     => $auth_url,
			'callback_url' => $callback_url,
			'state'        => $state,
			'message'      => $message,
			'instructions' => __( 'Visit the authorization URL to grant access to your Yahoo Fantasy Football data. After authorization, Yahoo will redirect you to the callback URL with an authorization code.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Get current authentication status.
	 *
	 * @param int $user_id User ID.
	 * @return array Authentication status.
	 */
	protected function get_authentication_status( $user_id ) {
		$access_token  = get_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token', true );
		$refresh_token = get_user_meta( $user_id, 'wp_mcp_ai_yahoo_refresh_token', true );
		$expires_at    = get_user_meta( $user_id, 'wp_mcp_ai_yahoo_token_expires', true );

		$is_authenticated = ! empty( $access_token ) && ! empty( $refresh_token );
		$is_expired       = false;

		if ( $is_authenticated && ! empty( $expires_at ) ) {
			$is_expired = time() > (int) $expires_at;
		}

		// Check credentials from centralized settings.
		$settings         = WP_MCP_AI_Admin_Settings::get_settings();
		$client_id_set    = ! empty( $settings['yahoo_client_id'] );
		$client_secret_set = ! empty( $settings['yahoo_client_secret'] );

		// Fallback to legacy options for backward compatibility.
		if ( ! $client_id_set ) {
			$client_id_set = ! empty( get_option( 'wp_mcp_ai_yahoo_client_id' ) );
		}
		if ( ! $client_secret_set ) {
			$client_secret_set = ! empty( get_option( 'wp_mcp_ai_yahoo_client_secret' ) );
		}

		// Get research page URL.
		$research_page_url = admin_url( 'edit.php?post_type=ff_team&page=research-fantasy-football' );

		// Create user-friendly status message.
		$message = '';
		if ( $is_authenticated && ! $is_expired ) {
			$message = __( 'Your Yahoo Fantasy Football account is connected and ready to use!', 'mcp-ai-wpoos' ) . "\n\n";
			$message .= '[**📊 Open Fantasy Football Research**](' . esc_url( $research_page_url ) . ')';
		} elseif ( $is_authenticated && $is_expired ) {
			$message = __( 'Your Yahoo Fantasy Football authentication token has expired. Please reconnect.', 'mcp-ai-wpoos' );
		} else {
			$message = __( 'You are not currently connected to Yahoo Fantasy Football. Use the get_auth_url action to connect.', 'mcp-ai-wpoos' );
		}

		return array(
			'action'        => 'get_status',
			'authenticated' => $is_authenticated,
			'token_expired' => $is_expired,
			'expires_at'    => $expires_at ? gmdate( 'Y-m-d H:i:s', (int) $expires_at ) : null,
			'has_refresh'   => ! empty( $refresh_token ),
			'message'       => $message,
			'configuration' => array(
				'client_id_set'     => $client_id_set,
				'client_secret_set' => $client_secret_set,
			),
		);
	}

	/**
	 * Revoke stored credentials.
	 *
	 * @param int $user_id User ID.
	 * @return array Revocation result.
	 */
	protected function revoke_credentials( $user_id ) {
		delete_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token' );
		delete_user_meta( $user_id, 'wp_mcp_ai_yahoo_refresh_token' );
		delete_user_meta( $user_id, 'wp_mcp_ai_yahoo_token_expires' );
		delete_user_meta( $user_id, 'wp_mcp_ai_yahoo_oauth_state' );
		delete_user_meta( $user_id, 'wp_mcp_ai_yahoo_oauth_timestamp' );

		return array(
			'action'  => 'revoke',
			'status'  => 'success',
			'message' => __( 'Yahoo Fantasy Sports credentials have been revoked.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'fantasy_football',
			'pattern_compatibility' => array( 'event_driven' ),
			'profession_tags'       => array( 'fantasy_sports_manager', 'sports_analyst' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'requires-credentials',
			'requires-capability',
		);
	}
}
