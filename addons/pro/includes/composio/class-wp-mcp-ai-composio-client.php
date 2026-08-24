<?php
/**
 * Composio Connect — REST API v3.1 client.
 *
 * Thin, testable wrapper around wp_remote_request() that owns the base URL,
 * the API-version pin, header assembly, error normalisation and rate-limit
 * cooldowns for the Composio backend API (https://backend.composio.dev).
 *
 * The client intentionally does NOT use the Remote Site Manager's
 * make_request() transport: Composio uses absolute /api/v3.1/... paths and
 * x-api-key header authentication, whereas make_request() is built for
 * WordPress-style REST sites. The client does reuse WordPress primitives
 * (wp_remote_request, transients) so it stays consistent with the plugin's
 * transport stack.
 *
 * PHP 8.1+ only (Pro addon).
 *
 * @see https://docs.composio.dev/reference/api-reference/connected-accounts
 * @see https://docs.composio.dev/reference/api-reference/tools
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composio REST API v3.1 client.
 */
class WP_MCP_AI_Composio_Client {

	/**
	 * Pinned Composio API version.
	 */
	const API_VERSION = 'v3.1';

	/**
	 * Default Composio backend base URL.
	 */
	const DEFAULT_BASE_URL = 'https://backend.composio.dev';

	/**
	 * Transient key prefix for GET caching.
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_composio_cache_';

	/**
	 * Transient key for the 429 cooldown flag.
	 */
	const COOLDOWN_PREFIX = 'wp_mcp_ai_composio_cooldown_';

	/**
	 * Default cache TTL for the tool catalog (24 hours).
	 */
	const TOOLS_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Default cache TTL for connected-account listings (5 minutes).
	 */
	const ACCOUNTS_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Composio project API key (ak_...).
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * API base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base_url = self::DEFAULT_BASE_URL;

	/**
	 * Optional connection ID used for cache/cooldown key scoping.
	 *
	 * @var string
	 */
	private $connection_id = '';

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 *
	 * @param string $api_key       Composio project API key (ak_...).
	 * @param string $base_url      Optional. API base URL. Defaults to https://backend.composio.dev.
	 * @param string $connection_id Optional. Remote-site connection ID for cache scoping.
	 */
	public function __construct( $api_key, $base_url = '', $connection_id = '' ) {
		// Trim the key so copy/paste whitespace can never turn a valid key
		// into an upstream 401.
		$this->api_key       = trim( (string) $api_key );
		$this->connection_id = sanitize_key( (string) $connection_id );

		if ( ! empty( $base_url ) ) {
			$base_url = esc_url_raw( $base_url );
			if ( false !== $base_url ) {
				$this->base_url = rtrim( $base_url, '/' );
			}
		}
	}

	/**
	 * Build a client instance from a stored remote-site connection record.
	 *
	 * Decrypts the stored API key via the Remote Site Manager when the
	 * connection carries the `_api_key_encrypted` flag.
	 *
	 * @since 1.4.0
	 *
	 * @param array $connection Remote-site connection record.
	 * @return WP_MCP_AI_Composio_Client
	 */
	public static function from_connection( array $connection ) {
		$api_key = isset( $connection['api_key'] ) ? (string) $connection['api_key'] : '';

		// Stored API keys are always encrypted at rest; decrypt unconditionally
		// and fall back to the raw value when decryption fails (e.g. plaintext
		// values injected programmatically or a rotated master key). Keeping the
		// raw value on failure lets the request path surface the real upstream
		// 401 instead of a misleading "key not configured" error.
		if ( ! empty( $api_key ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$decrypted = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $api_key );
			if ( '' !== $decrypted ) {
				$api_key = $decrypted;
			}
		}

		$base_url = isset( $connection['base_url'] ) && ! empty( $connection['base_url'] )
			? $connection['base_url']
			: ( isset( $connection['url'] ) ? $connection['url'] : '' );
		$conn_id  = isset( $connection['id'] ) ? $connection['id'] : '';

		return new self( $api_key, $base_url, $conn_id );
	}

	/**
	 * Test the connection by fetching the tool enum (smallest authenticated call).
	 *
	 * @since 1.4.0
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		$result = $this->request( 'GET', '/api/' . self::API_VERSION . '/tools/enum' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$enum = isset( $result['response']['enum'] ) ? $result['response']['enum'] : '';

		return array(
			'success'     => true,
			'connected'   => true,
			'base_url'    => $this->base_url,
			'tools_count' => is_string( $enum ) ? count( array_filter( explode( ',', $enum ) ) ) : 0,
			'message'     => __( 'Composio API connection successful.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * List connected accounts with optional filters.
	 *
	 * @since 1.4.0
	 *
	 * @param array $filters Optional. Query filters: user_id, status, toolkit.
	 * @return array|WP_Error
	 */
	public function list_connected_accounts( array $filters = array() ) {
		$path = '/api/' . self::API_VERSION . '/connected_accounts';

		if ( ! empty( $filters ) ) {
			$path = add_query_arg( $filters, $path );
		}

		$result = $this->request( 'GET', $path, array(), array(), self::ACCOUNTS_CACHE_TTL );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$accounts = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : array();

		// Mirror the count into a cheap per-connection transient so admin
		// surfaces (assistant metabox badges) render without an API round-trip.
		if ( '' !== $this->connection_id ) {
			set_transient(
				self::get_account_count_key( $this->connection_id ),
				count( $accounts ),
				10 * MINUTE_IN_SECONDS
			);
		}

		return $accounts;
	}

	/**
	 * Read the cached connected-account count for a connection.
	 *
	 * Returns false when no cached count exists (the listing has not been
	 * fetched yet) so callers can render gracefully without a live API call.
	 *
	 * @since 1.4.0
	 *
	 * @param string $connection_id Composio connection ID.
	 * @return int|false Cached count or false when unavailable.
	 */
	public static function get_cached_account_count( $connection_id ) {
		$connection_id = sanitize_key( (string) $connection_id );

		if ( '' === $connection_id ) {
			return false;
		}

		$count = get_transient( self::get_account_count_key( $connection_id ) );

		return false === $count ? false : absint( $count );
	}

	/**
	 * Build the transient key for a connection's cached account count.
	 *
	 * @since 1.4.0
	 *
	 * @param string $connection_id Composio connection ID.
	 * @return string
	 */
	private static function get_account_count_key( $connection_id ) {
		return 'wp_mcp_ai_composio_account_count_' . $connection_id;
	}

	/**
	 * Get a single connected account by ID.
	 *
	 * @since 1.4.0
	 *
	 * @param string $account_id Connected account nanoid.
	 * @return array|WP_Error
	 */
	public function get_connected_account( $account_id ) {
		$account_id = sanitize_text_field( (string) $account_id );

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request( 'GET', '/api/' . self::API_VERSION . '/connected_accounts/' . rawurlencode( $account_id ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Create a Composio Connect Link (hosted authentication session).
	 *
	 * @since 1.4.0
	 *
	 * @param string $toolkit      Toolkit slug (e.g. "gmail", "slack").
	 * @param string $user_id      Application-defined stable user identifier.
	 * @param string $redirect_url Where the user returns after authentication.
	 * @param array  $opts         Optional. Extra link options (auth_config_id, callback_url, verify_callback_url, auth_scheme).
	 * @return array|WP_Error
	 */
	public function create_connect_link( $toolkit, $user_id, $redirect_url, array $opts = array() ) {
		$toolkit      = sanitize_key( (string) $toolkit );
		$user_id      = sanitize_text_field( (string) $user_id );
		$redirect_url = esc_url_raw( $redirect_url );

		if ( '' === $toolkit || '' === $user_id || '' === $redirect_url ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_link_params', __( 'Toolkit, user ID and redirect URL are required to create a Connect Link.', 'mcp-ai-wpoos-pro' ) );
		}

		$body = array_merge(
			array(
				'toolkit'      => $toolkit,
				'user_id'      => $user_id,
				'redirect_url' => $redirect_url,
			),
			$opts
		);

		$result = $this->request( 'POST', '/api/' . self::API_VERSION . '/connected_accounts/link', array(), $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Redeem a single-use session_uri after callback identity verification.
	 *
	 * @since 1.4.0
	 *
	 * @param string $session_uri Single-use session URI from the provider callback.
	 * @param string $user_id     Signed-in application user ID to verify against.
	 * @return array|WP_Error
	 */
	public function complete_auth( $session_uri, $user_id ) {
		$session_uri = esc_url_raw( (string) $session_uri );
		$user_id     = sanitize_text_field( (string) $user_id );

		if ( '' === $session_uri || '' === $user_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_complete_auth_params', __( 'Session URI and user ID are required to complete authentication.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request(
			'POST',
			'/api/' . self::API_VERSION . '/connected_accounts/complete_auth',
			array(),
			array(
				'session_uri' => $session_uri,
				'user_id'     => $user_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Update a connected account (alias/labels only — never credentials).
	 *
	 * @since 1.4.0
	 *
	 * @param string $account_id Connected account nanoid.
	 * @param array  $patch      Fields to update.
	 * @return array|WP_Error
	 */
	public function update_connected_account( $account_id, array $patch ) {
		$account_id = sanitize_text_field( (string) $account_id );

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request( 'PATCH', '/api/' . self::API_VERSION . '/connected_accounts/' . rawurlencode( $account_id ), array(), $patch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Enable or disable a connected account.
	 *
	 * @since 1.4.0
	 *
	 * @param string $account_id Connected account nanoid.
	 * @param string $status     "active" or "inactive".
	 * @return array|WP_Error
	 */
	public function set_connected_account_status( $account_id, $status ) {
		$account_id = sanitize_text_field( (string) $account_id );
		$status     = 'inactive' === $status ? 'inactive' : 'active';

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request(
			'PATCH',
			'/api/' . self::API_VERSION . '/connected_accounts/' . rawurlencode( $account_id ) . '/status',
			array(),
			array( 'status' => $status )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Revoke a connected account at the provider.
	 *
	 * @since 1.4.0
	 *
	 * @param string $account_id Connected account nanoid.
	 * @return array|WP_Error
	 */
	public function revoke_connected_account( $account_id ) {
		$account_id = sanitize_text_field( (string) $account_id );

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		return $this->request( 'POST', '/api/' . self::API_VERSION . '/connected_accounts/' . rawurlencode( $account_id ) . '/revoke' );
	}

	/**
	 * Delete (soft-delete) a connected account.
	 *
	 * @since 1.4.0
	 *
	 * @param string $account_id       Connected account nanoid.
	 * @param bool   $revoke_on_delete Whether to also revoke upstream credentials.
	 * @return array|WP_Error
	 */
	public function delete_connected_account( $account_id, $revoke_on_delete = false ) {
		$account_id = sanitize_text_field( (string) $account_id );

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$path = '/api/' . self::API_VERSION . '/connected_accounts/' . rawurlencode( $account_id );
		if ( $revoke_on_delete ) {
			$path = add_query_arg( 'revoke_on_delete', 'true', $path );
		}

		return $this->request( 'DELETE', $path );
	}

	/**
	 * List available tools with optional filters.
	 *
	 * @since 1.4.0
	 *
	 * @param array $filters Optional. Query filters: toolkits, search, page, limit.
	 * @return array|WP_Error
	 */
	public function list_tools( array $filters = array() ) {
		$path = '/api/' . self::API_VERSION . '/tools';

		if ( ! empty( $filters ) ) {
			$path = add_query_arg( $filters, $path );
		}

		$result = $this->request( 'GET', $path, array(), array(), self::TOOLS_CACHE_TTL );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Get a single tool's input/output schema.
	 *
	 * @since 1.4.0
	 *
	 * @param string $tool_slug SCREAMING_SNAKE tool slug.
	 * @return array|WP_Error
	 */
	public function get_tool_schema( $tool_slug ) {
		$tool_slug = sanitize_text_field( (string) $tool_slug );

		if ( '' === $tool_slug || ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $tool_slug ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_invalid_tool_slug', __( 'Tool slug must be SCREAMING_SNAKE_CASE.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request( 'GET', '/api/' . self::API_VERSION . '/tools/' . rawurlencode( $tool_slug ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Execute a tool on behalf of a connected account.
	 *
	 * @since 1.4.0
	 *
	 * @param string $tool_slug  SCREAMING_SNAKE tool slug.
	 * @param string $account_id Connected account nanoid.
	 * @param array  $arguments  Tool input arguments.
	 * @return array|WP_Error
	 */
	public function execute_tool( $tool_slug, $account_id, array $arguments ) {
		$tool_slug  = sanitize_text_field( (string) $tool_slug );
		$account_id = sanitize_text_field( (string) $account_id );

		if ( '' === $tool_slug || ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $tool_slug ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_invalid_tool_slug', __( 'Tool slug must be SCREAMING_SNAKE_CASE.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required to execute tools.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request(
			'POST',
			'/api/' . self::API_VERSION . '/tools/execute/' . rawurlencode( $tool_slug ),
			array(),
			array(
				'connected_account_id' => $account_id,
				'toolkit_versions'     => 'latest',
				'arguments'            => $arguments,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Generate structured tool inputs from a natural-language description.
	 *
	 * @since 1.4.0
	 *
	 * @param string $tool_slug SCREAMING_SNAKE tool slug.
	 * @param string $text      Natural-language instruction.
	 * @return array|WP_Error
	 */
	public function generate_tool_inputs( $tool_slug, $text ) {
		$tool_slug = sanitize_text_field( (string) $tool_slug );
		$text      = sanitize_textarea_field( (string) $text );

		if ( '' === $tool_slug || ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $tool_slug ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_invalid_tool_slug', __( 'Tool slug must be SCREAMING_SNAKE_CASE.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_text', __( 'A natural-language instruction is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request(
			'POST',
			'/api/' . self::API_VERSION . '/tools/execute/' . rawurlencode( $tool_slug ) . '/input',
			array(),
			array( 'text' => $text )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * List available trigger types.
	 *
	 * @since 1.4.0
	 *
	 * @param array $filters Optional. Query filters: toolkit.
	 * @return array|WP_Error
	 */
	public function list_trigger_types( array $filters = array() ) {
		$path = '/api/' . self::API_VERSION . '/triggers/types';

		if ( ! empty( $filters ) ) {
			$path = add_query_arg( $filters, $path );
		}

		$result = $this->request( 'GET', $path, array(), array(), HOUR_IN_SECONDS );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * List active trigger instances for the project.
	 *
	 * @since 1.4.0
	 *
	 * @return array|WP_Error
	 */
	public function list_active_triggers() {
		$result = $this->request( 'GET', '/api/' . self::API_VERSION . '/triggers/active', array(), array(), MINUTE_IN_SECONDS );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Create or re-enable a trigger instance.
	 *
	 * @since 1.4.0
	 *
	 * @param string $trigger_slug Trigger slug (e.g. GMAIL_NEW_MESSAGE).
	 * @param array  $config       Trigger config: connected_account_id or user_id plus trigger_config.
	 * @return array|WP_Error
	 */
	public function upsert_trigger( $trigger_slug, array $config ) {
		$trigger_slug = sanitize_text_field( (string) $trigger_slug );

		if ( '' === $trigger_slug || ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $trigger_slug ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_invalid_trigger_slug', __( 'Trigger slug must be SCREAMING_SNAKE_CASE.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request(
			'POST',
			'/api/' . self::API_VERSION . '/trigger_instances/' . rawurlencode( $trigger_slug ) . '/upsert',
			array(),
			$config
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Enable or disable a trigger instance.
	 *
	 * @since 1.4.0
	 *
	 * @param string $trigger_id Trigger instance ID.
	 * @param string $status     "enable" or "disable".
	 * @return array|WP_Error
	 */
	public function set_trigger_status( $trigger_id, $status ) {
		$trigger_id = sanitize_text_field( (string) $trigger_id );
		$status     = 'disable' === $status ? 'disable' : 'enable';

		if ( '' === $trigger_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_trigger', __( 'A trigger instance ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request(
			'PATCH',
			'/api/' . self::API_VERSION . '/trigger_instances/' . rawurlencode( $trigger_id ),
			array(),
			array( 'status' => $status )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Delete a trigger instance.
	 *
	 * @since 1.4.0
	 *
	 * @param string $trigger_id Trigger instance ID.
	 * @return array|WP_Error
	 */
	public function delete_trigger( $trigger_id ) {
		$trigger_id = sanitize_text_field( (string) $trigger_id );

		if ( '' === $trigger_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_trigger', __( 'A trigger instance ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		return $this->request( 'DELETE', '/api/' . self::API_VERSION . '/trigger_instances/' . rawurlencode( $trigger_id ) );
	}

	/**
	 * Create the project webhook subscription that delivers trigger events.
	 *
	 * @since 1.4.0
	 *
	 * @param string $url         Public HTTPS URL receiving Composio events.
	 * @param array  $event_types Event types to subscribe to (e.g. composio.trigger.message).
	 * @return array|WP_Error
	 */
	public function create_webhook_subscription( $url, array $event_types ) {
		$url = esc_url_raw( $url );

		if ( '' === $url || empty( $event_types ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_webhook_params', __( 'A webhook URL and at least one event type are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request(
			'POST',
			'/api/' . self::API_VERSION . '/webhook_subscriptions',
			array(),
			array(
				'url'         => $url,
				'event_types' => array_values( array_map( 'sanitize_key', $event_types ) ),
				'version'     => 3,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Get the current webhook subscription (one per project).
	 *
	 * @since 1.4.0
	 *
	 * @return array|WP_Error
	 */
	public function get_webhook_subscription() {
		$result = $this->request( 'GET', '/api/' . self::API_VERSION . '/webhook_subscriptions' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscriptions = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : array();
		return ! empty( $subscriptions ) ? reset( $subscriptions ) : array();
	}

	/**
	 * Update the webhook subscription (e.g. event-type filters).
	 *
	 * @since 1.4.0
	 *
	 * @param string $subscription_id Subscription ID.
	 * @param array  $patch           Fields to update.
	 * @return array|WP_Error
	 */
	public function update_webhook_subscription( $subscription_id, array $patch ) {
		$subscription_id = sanitize_text_field( (string) $subscription_id );

		if ( '' === $subscription_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_subscription', __( 'A webhook subscription ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request( 'PATCH', '/api/' . self::API_VERSION . '/webhook_subscriptions/' . rawurlencode( $subscription_id ), array(), $patch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Delete the webhook subscription.
	 *
	 * @since 1.4.0
	 *
	 * @param string $subscription_id Subscription ID.
	 * @return array|WP_Error
	 */
	public function delete_webhook_subscription( $subscription_id ) {
		$subscription_id = sanitize_text_field( (string) $subscription_id );

		if ( '' === $subscription_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_subscription', __( 'A webhook subscription ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		return $this->request( 'DELETE', '/api/' . self::API_VERSION . '/webhook_subscriptions/' . rawurlencode( $subscription_id ) );
	}

	/**
	 * Rotate the webhook signing secret.
	 *
	 * @since 1.4.0
	 *
	 * @param string $subscription_id Subscription ID.
	 * @return array|WP_Error
	 */
	public function rotate_webhook_secret( $subscription_id ) {
		$subscription_id = sanitize_text_field( (string) $subscription_id );

		if ( '' === $subscription_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_subscription', __( 'A webhook subscription ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request(
			'POST',
			'/api/' . self::API_VERSION . '/webhook_subscriptions/' . rawurlencode( $subscription_id ) . '/rotate_secret',
			array(),
			array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Verify a webhook payload signature (constant-time HMAC-SHA256 comparison).
	 *
	 * Supports hex and base64 signature encodings so header-format changes on
	 * the Composio side degrade gracefully until the canonical format is
	 * confirmed during the live spike.
	 *
	 * @since 1.4.0
	 *
	 * @param string $raw_body  Raw request body exactly as received.
	 * @param string $signature Signature value from the webhook request header.
	 * @param string $secret    Signing secret.
	 * @return bool
	 */
	public static function verify_webhook_signature( $raw_body, $signature, $secret ) {
		$raw_body  = (string) $raw_body;
		$signature = null === $signature ? '' : (string) $signature;
		$secret    = (string) $secret;

		if ( '' === $raw_body || '' === $signature || '' === $secret ) {
			return false;
		}

		$computed = hash_hmac( 'sha256', $raw_body, $secret, false );

		if ( hash_equals( $computed, $signature ) ) {
			return true;
		}

		// Also accept base64-encoded raw HMAC output.
		$computed_b64 = base64_encode( hash_hmac( 'sha256', $raw_body, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return hash_equals( $computed_b64, $signature );
	}

	/**
	 * Perform an authenticated HTTP request against the Composio API.
	 *
	 * @since 1.4.0
	 *
	 * @param string $method    HTTP verb (GET, POST, PATCH, DELETE).
	 * @param string $path      API path starting with /api/....
	 * @param array  $query     Optional. Query-string parameters.
	 * @param array  $body      Optional. JSON request body.
	 * @param int    $cache_ttl Optional. Cache successful GET responses for this many seconds (0 = no cache).
	 * @return array|WP_Error Raw decoded response with 'response' + 'rate_limit' keys, or WP_Error.
	 */
	public function request( $method, $path, array $query = array(), array $body = array(), $cache_ttl = 0 ) {
		$method = strtoupper( (string) $method );

		if ( '' === $this->api_key ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_api_key', __( 'Composio API key is not configured for this connection.', 'mcp-ai-wpoos-pro' ) );
		}

		$url = $this->base_url . '/' . ltrim( $path, '/' );

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		// Enforce 429 cooldown so bursts do not hammer the upstream API.
		$cooldown_key = self::COOLDOWN_PREFIX . md5( $this->connection_id . '|' . $method . '|' . $path );
		$cooldown     = get_transient( $cooldown_key );
		if ( false !== $cooldown ) {
			return new WP_Error(
				'wp_mcp_ai_composio_rate_limited',
				/* translators: %d: cooldown seconds remaining */
				sprintf( __( 'Composio API is rate-limited. Retry in %d seconds.', 'mcp-ai-wpoos-pro' ), absint( $cooldown ) )
			);
		}

		// Serve cached GET results when caching is enabled.
		$cache_key = '';
		if ( 'GET' === $method && $cache_ttl > 0 ) {
			$cache_key = self::CACHE_PREFIX . md5( $this->connection_id . '|' . $url );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$headers = array(
			'x-api-key'       => $this->api_key,
			'Accept'          => 'application/json',
			'Accept-Encoding' => 'gzip, deflate',
			'User-Agent'      => 'nvoos-pro/' . WP_MCP_AI_PRO_VERSION,
		);

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
		);

		if ( in_array( $method, array( 'POST', 'PATCH', 'PUT' ), true ) ) {
			$args['body']                    = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$transport_message = $response->get_error_message();

			// Connection failures and timeouts on this path are almost always a
			// hosting egress problem, not a credential problem — surface an
			// actionable hint alongside the raw transport error.
			if ( 'http_request_failed' === $response->get_error_code() ) {
				$host               = wp_parse_url( $this->base_url, PHP_URL_HOST );
				$transport_message .= ' ' . sprintf(
					/* translators: %s: API host */
					__( 'Your server could not reach %s. Check DNS resolution and outbound HTTPS (firewall, proxy, or hosting egress rules) on this host.', 'mcp-ai-wpoos-pro' ),
					is_string( $host ) && '' !== $host ? $host : $this->base_url
				);
			}

			return new WP_Error(
				'wp_mcp_ai_composio_request_failed',
				/* translators: %s: raw transport error */
				sprintf( __( 'Composio API request failed: %s', 'mcp-ai-wpoos-pro' ), $transport_message )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( 429 === $status_code ) {
			$retry_after = absint( wp_remote_retrieve_header( $response, 'retry-after' ) );
			$wait        = max( 30, min( 300, $retry_after > 0 ? $retry_after : 60 ) );
			set_transient( $cooldown_key, $wait, $wait );

			return new WP_Error(
				'wp_mcp_ai_composio_rate_limited',
				/* translators: %d: retry-after seconds */
				sprintf( __( 'Composio API rate limit reached. Retry in %d seconds.', 'mcp-ai-wpoos-pro' ), $wait )
			);
		}

		if ( $status_code >= 400 ) {
			$message = __( 'Composio API returned an error.', 'mcp-ai-wpoos-pro' );
			$hint    = '';

			// Composio's documented error shape nests the message under
			// "error": { "error": { "message": ..., "suggested_fix": ... } }.
			// Accept that shape plus the older flat variants so the admin UI
			// always shows the real upstream reason.
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['error'] ) ) {
					if ( is_array( $decoded['error'] ) ) {
						if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) && '' !== $decoded['error']['message'] ) {
							$message = $decoded['error']['message'];
						}
						if ( isset( $decoded['error']['suggested_fix'] ) && is_string( $decoded['error']['suggested_fix'] ) && '' !== $decoded['error']['suggested_fix'] ) {
							$hint = $decoded['error']['suggested_fix'];
						}
					} elseif ( is_string( $decoded['error'] ) && '' !== $decoded['error'] ) {
						$message = $decoded['error'];
					}
				} elseif ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) && '' !== $decoded['message'] ) {
					$message = $decoded['message'];
				} elseif ( isset( $decoded['detail'] ) && is_string( $decoded['detail'] ) && '' !== $decoded['detail'] ) {
					$message = $decoded['detail'];
				}
			}

			// 401/403 mean the key is invalid, revoked, or under-scoped — give
			// the admin a concrete next step when upstream did not supply one.
			if ( ( 401 === $status_code || 403 === $status_code ) && '' === $hint ) {
				$hint = __( 'Verify the project API key in the Composio dashboard (Settings → API Keys) — it may be revoked, expired, or belong to a different project.', 'mcp-ai-wpoos-pro' );
			}

			if ( '' !== $hint ) {
				$message .= ' ' . $hint;
			}

			return new WP_Error(
				'wp_mcp_ai_composio_http_' . $status_code,
				/* translators: 1: HTTP status code, 2: upstream message */
				sprintf( __( 'HTTP %1$d: %2$s', 'mcp-ai-wpoos-pro' ), $status_code, $message ),
				array( 'status' => $status_code )
			);
		}

		$result = array(
			'response' => is_array( $decoded ) ? $decoded : array( 'raw' => $raw_body ),
		);

		// Surface rate-limit headers for observability.
		$rate_limit = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );
		if ( '' !== $rate_limit ) {
			$result['rate_limit'] = array(
				'limit'     => absint( $rate_limit ),
				'remaining' => absint( wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ),
			);
		}

		if ( 'GET' === $method && $cache_ttl > 0 && '' !== $cache_key ) {
			set_transient( $cache_key, $result, $cache_ttl );
		}

		return $result;
	}

	/**
	 * Clear all cached GET responses for this connection.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function flush_cache() {
		// Transients are keyed by hash — we cannot enumerate them cheaply, so
		// we bump a per-connection generation counter instead.
		$generation = absint( get_transient( 'wp_mcp_ai_composio_generation_' . $this->connection_id ) ) + 1;
		set_transient( 'wp_mcp_ai_composio_generation_' . $this->connection_id, $generation, WEEK_IN_SECONDS );
	}
}
