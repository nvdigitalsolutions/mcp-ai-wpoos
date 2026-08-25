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
	 * Composio user identity ("user_id") this client acts as.
	 *
	 * Composio's v3.1 execute endpoint rejects a connected account that arrives
	 * without the user identity it belongs to ("User ID is required with
	 * connected account"), so the identity resolved from the connection travels
	 * with every tool call.
	 *
	 * @var string
	 */
	private $user_id = '';

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 *
	 * @param string $api_key       Composio project API key (ak_...).
	 * @param string $base_url      Optional. API base URL. Defaults to https://backend.composio.dev.
	 * @param string $connection_id Optional. Remote-site connection ID for cache scoping.
	 * @param string $user_id       Optional. Composio user identity to act as.
	 */
	public function __construct( $api_key, $base_url = '', $connection_id = '', $user_id = '' ) {
		// Trim the key so copy/paste whitespace can never turn a valid key
		// into an upstream 401.
		$this->api_key       = trim( (string) $api_key );
		$this->connection_id = sanitize_key( (string) $connection_id );
		$this->user_id       = sanitize_text_field( (string) $user_id );

		if ( ! empty( $base_url ) ) {
			$base_url = esc_url_raw( $base_url );
			if ( false !== $base_url ) {
				$this->base_url = rtrim( $base_url, '/' );
			}
		}
	}

	/**
	 * Get the Composio user identity bound to this client.
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	public function get_user_id() {
		return $this->user_id;
	}

	/**
	 * Bind a Composio user identity to this client.
	 *
	 * @since 1.4.0
	 *
	 * @param string $user_id Composio user identity.
	 * @return void
	 */
	public function set_user_id( $user_id ) {
		$this->user_id = sanitize_text_field( (string) $user_id );
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

		return new self( $api_key, $base_url, $conn_id, self::resolve_connection_user_id( $connection ) );
	}

	/**
	 * Resolve the Composio user identity for a connection record.
	 *
	 * Identity resolution lives in the auth handler because it owns the
	 * admin_shared / per_wp_user contract. The class is lazy-loaded here so
	 * client-only call sites (the assistant metabox, the Remote Sites edit
	 * form) still get a correctly bound identity.
	 *
	 * @since 1.4.0
	 *
	 * @param array $connection Remote-site connection record.
	 * @return string
	 */
	private static function resolve_connection_user_id( array $connection ) {
		if ( ! class_exists( 'WP_MCP_AI_Composio_Auth_Handler' ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$auth_handler_file = WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-auth-handler.php';
			if ( file_exists( $auth_handler_file ) ) {
				require_once $auth_handler_file;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Composio_Auth_Handler' ) ) {
			return WP_MCP_AI_Composio_Auth_Handler::resolve_user_id( $connection );
		}

		return isset( $connection['default_user_id'] ) ? (string) $connection['default_user_id'] : '';
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

		// Map legacy flat filters onto the v3.1 array query parameters
		// (toolkit_slugs / statuses / user_ids). FastAPI-style backends accept
		// comma-separated values for array parameters.
		$query = array();
		if ( ! empty( $filters['toolkit'] ) ) {
			$query['toolkit_slugs'] = implode( ',', array_map( 'sanitize_key', (array) $filters['toolkit'] ) );
		}
		if ( ! empty( $filters['status'] ) ) {
			// Composio's status enum is SCREAMING_SNAKE (ACTIVE, EXPIRED, ...).
			// sanitize_key() lowercases, which previously produced a filter that
			// silently matched nothing.
			$query['statuses'] = implode(
				',',
				array_map(
					static function ( $status ) {
						return strtoupper( sanitize_key( $status ) );
					},
					(array) $filters['status']
				)
			);
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$query['user_ids'] = implode( ',', array_map( 'sanitize_text_field', (array) $filters['user_id'] ) );
		}

		if ( ! empty( $query ) ) {
			$path = add_query_arg( $query, $path );
		}

		$result = $this->request( 'GET', $path, array(), array(), self::ACCOUNTS_CACHE_TTL );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$accounts = self::unwrap_items( isset( $result['response'] ) ? $result['response'] : array() );

		$accounts = array_values(
			array_map(
				array( __CLASS__, 'normalize_account' ),
				array_filter( $accounts, 'is_array' )
			)
		);

		// Mirror the count into a cheap per-connection transient so admin
		// surfaces (assistant metabox badges) render without an API round-trip.
		// Only the unfiltered listing represents the true total.
		if ( '' !== $this->connection_id && empty( $filters ) ) {
			set_transient(
				self::get_account_count_key( $this->connection_id ),
				count( $accounts ),
				10 * MINUTE_IN_SECONDS
			);
		}

		return $accounts;
	}

	/**
	 * Unwrap a v3.1 paginated collection into a flat list of items.
	 *
	 * Every v3.1 collection endpoint answers with
	 * `{ items: [...], next_cursor, total_pages, current_page, total_items }`.
	 * A legacy flat array is accepted too so older payloads and test doubles
	 * keep working.
	 *
	 * @since 1.4.1
	 *
	 * @param mixed $response Decoded response body.
	 * @return array List of items.
	 */
	public static function unwrap_items( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}

		if ( isset( $response['items'] ) && is_array( $response['items'] ) ) {
			return $response['items'];
		}

		// A flat list — every key is a sequential integer.
		if ( array() === $response || array_keys( $response ) === range( 0, count( $response ) - 1 ) ) {
			return $response;
		}

		return array();
	}

	/**
	 * Extract the pagination envelope from a v3.1 collection response.
	 *
	 * @since 1.4.1
	 *
	 * @param mixed $response  Decoded response body.
	 * @param int   $item_count Number of items unwrapped from the response.
	 * @return array{next_cursor:string,total_items:int,total_pages:int,current_page:int}
	 */
	public static function extract_pagination( $response, $item_count = 0 ) {
		$response = is_array( $response ) ? $response : array();

		return array(
			'next_cursor'  => isset( $response['next_cursor'] ) && is_scalar( $response['next_cursor'] ) ? (string) $response['next_cursor'] : '',
			'total_items'  => isset( $response['total_items'] ) ? absint( $response['total_items'] ) : absint( $item_count ),
			'total_pages'  => isset( $response['total_pages'] ) ? absint( $response['total_pages'] ) : 1,
			'current_page' => isset( $response['current_page'] ) ? absint( $response['current_page'] ) : 1,
		);
	}

	/**
	 * Normalise a connected-account payload into a stable, flat shape.
	 *
	 * Version 3.1 nests the toolkit under `{ slug: ... }` and buries credential
	 * expiry inside `state.val`, so every consumer previously had to re-derive
	 * the same fields (and most did not, which is how `toolkit` rendered blank
	 * and expiry was reported as "not expired" for a dead token). The original
	 * payload keys are preserved; normalised keys are layered on top.
	 *
	 * @since 1.4.1
	 *
	 * @param array $account Raw connected-account payload.
	 * @return array Normalised payload.
	 */
	public static function normalize_account( array $account ) {
		$toolkit = isset( $account['toolkit'] ) ? $account['toolkit'] : '';
		if ( is_array( $toolkit ) ) {
			$toolkit = isset( $toolkit['slug'] ) ? (string) $toolkit['slug'] : '';
		}

		$owner = '';
		foreach ( array( 'user_id', 'entity_id' ) as $key ) {
			if ( isset( $account[ $key ] ) && is_scalar( $account[ $key ] ) && '' !== (string) $account[ $key ] ) {
				$owner = (string) $account[ $key ];
				break;
			}
		}
		if ( '' === $owner && isset( $account['user']['id'] ) && is_scalar( $account['user']['id'] ) ) {
			$owner = (string) $account['user']['id'];
		}

		$state = isset( $account['state']['val'] ) && is_array( $account['state']['val'] ) ? $account['state']['val'] : array();

		// Credential expiry: v3.1 stores `expired_at` (set once the credential
		// has expired) and `expires_in` (seconds, on freshly minted OAuth2
		// tokens) inside state.val. There is no top-level expiry field.
		$expires_at = '';
		foreach ( array( 'expired_at', 'expires_at' ) as $key ) {
			if ( isset( $state[ $key ] ) && is_scalar( $state[ $key ] ) && '' !== (string) $state[ $key ] ) {
				$expires_at = (string) $state[ $key ];
				break;
			}
		}
		if ( '' === $expires_at && isset( $state['expires_in'] ) && is_numeric( $state['expires_in'] ) ) {
			$updated    = isset( $account['updated_at'] ) ? strtotime( (string) $account['updated_at'] ) : false;
			$expires_at = gmdate( 'c', ( false !== $updated ? $updated : time() ) + absint( $state['expires_in'] ) );
		}

		$status_reason = '';
		foreach ( array( 'status_reason', 'error_description', 'error' ) as $key ) {
			if ( isset( $account[ $key ] ) && is_scalar( $account[ $key ] ) && '' !== (string) $account[ $key ] ) {
				$status_reason = (string) $account[ $key ];
				break;
			}
			if ( isset( $state[ $key ] ) && is_scalar( $state[ $key ] ) && '' !== (string) $state[ $key ] ) {
				$status_reason = (string) $state[ $key ];
				break;
			}
		}

		return array_merge(
			$account,
			array(
				'id'            => isset( $account['id'] ) ? (string) $account['id'] : '',
				'toolkit'       => strtolower( (string) $toolkit ),
				'status'        => isset( $account['status'] ) ? strtoupper( (string) $account['status'] ) : '',
				'status_reason' => $status_reason,
				'user_id'       => $owner,
				'expires_at'    => $expires_at,
				'disabled'      => ! empty( $account['is_disabled'] ),
				'auth_scheme'   => isset( $account['auth_config']['auth_scheme'] ) ? (string) $account['auth_config']['auth_scheme'] : ( isset( $account['authScheme'] ) ? (string) $account['authScheme'] : '' ),
				'created_at'    => isset( $account['created_at'] ) ? (string) $account['created_at'] : '',
				'updated_at'    => isset( $account['updated_at'] ) ? (string) $account['updated_at'] : '',
			)
		);
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
	 * Flush every cached connected-account listing for a connection.
	 *
	 * The GET cache key is derived from the connection ID plus the absolute
	 * request URL, so the default backend URL and any custom base_url variant
	 * are both cleared, along with the cheap per-connection count transient
	 * used by admin badges.
	 *
	 * @since 1.4.0
	 *
	 * @param string $connection_id Composio connection ID.
	 * @return void
	 */
	public static function clear_accounts_cache( $connection_id ) {
		$connection_id = sanitize_key( (string) $connection_id );

		if ( '' === $connection_id ) {
			return;
		}

		$base_urls = array( self::DEFAULT_BASE_URL );

		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( null !== $connection ) {
				if ( ! empty( $connection['base_url'] ) ) {
					$base_urls[] = rtrim( esc_url_raw( $connection['base_url'] ), '/' );
				} elseif ( ! empty( $connection['url'] ) ) {
					$base_urls[] = rtrim( esc_url_raw( $connection['url'] ), '/' );
				}
			}
		}

		foreach ( array_unique( $base_urls ) as $base_url ) {
			delete_transient( self::CACHE_PREFIX . md5( $connection_id . '|' . $base_url . '/api/' . self::API_VERSION . '/connected_accounts' ) );
		}

		delete_transient( self::get_account_count_key( $connection_id ) );
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
	 * @param int    $cache_ttl  Optional. Cache the lookup for this many seconds (0 = no cache).
	 * @return array|WP_Error
	 */
	public function get_connected_account( $account_id, $cache_ttl = 0 ) {
		$account_id = sanitize_text_field( (string) $account_id );

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->request( 'GET', '/api/' . self::API_VERSION . '/connected_accounts/' . rawurlencode( $account_id ), array(), array(), absint( $cache_ttl ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$account = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : array();

		return empty( $account ) ? $account : self::normalize_account( $account );
	}

	/**
	 * Re-authorise an existing connected account in place.
	 *
	 * Composio has no "reconnect" verb: creating a fresh Connect Link mints a
	 * *new* `ca_...` account and leaves the broken one behind, which is how
	 * orphaned connections accumulate. `POST /connected_accounts/{id}/refresh`
	 * re-initiates the provider flow against the *same* account ID and returns
	 * a `redirect_url`, so the account keeps its ID, alias and any triggers
	 * pinned to it.
	 *
	 * The route is marked Legacy upstream and may be withdrawn; callers must be
	 * prepared for a WP_Error and fall back to a fresh Connect Link.
	 *
	 * @since 1.4.1
	 *
	 * @param string $account_id   Connected account nanoid.
	 * @param string $redirect_url Optional. URL to return the user to afterwards.
	 * @return array|WP_Error Response with id/status/redirect_url, or WP_Error.
	 */
	public function reconnect_connected_account( $account_id, $redirect_url = '' ) {
		$account_id = sanitize_text_field( (string) $account_id );

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required to reconnect.', 'mcp-ai-wpoos-pro' ) );
		}

		$body = array();
		if ( '' !== (string) $redirect_url ) {
			$body['redirect_url'] = esc_url_raw( $redirect_url );
		}

		$result = $this->request(
			'POST',
			'/api/' . self::API_VERSION . '/connected_accounts/' . rawurlencode( $account_id ) . '/refresh',
			array(),
			$body
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Resolve the auth config ID to use when creating a Connect Link for a toolkit.
	 *
	 * Composio's Connect Link endpoint requires an auth config ID (ac_...),
	 * not a toolkit slug. Preference order: Composio-managed default config,
	 * then any Composio-managed config, then any enabled config for the toolkit.
	 *
	 * @since 1.4.0
	 *
	 * @param string $toolkit Toolkit slug (e.g. "gmail").
	 * @return string|WP_Error Auth config ID or WP_Error.
	 */
	public function resolve_auth_config_id( $toolkit ) {
		$toolkit = sanitize_key( (string) $toolkit );

		if ( '' === $toolkit ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_toolkit', __( 'A toolkit slug is required to resolve an auth config.', 'mcp-ai-wpoos-pro' ) );
		}

		$path = '/api/' . self::API_VERSION . '/auth_configs';
		$path = add_query_arg(
			array(
				'toolkit_slug' => $toolkit,
				'limit'        => 50,
			),
			$path
		);

		// Auth configs change rarely — cache for an hour via the GET cache.
		$result = $this->request( 'GET', $path, array(), array(), HOUR_IN_SECONDS );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = isset( $result['response']['items'] ) && is_array( $result['response']['items'] ) ? $result['response']['items'] : array();

		$managed_default = '';
		$managed_any     = '';
		$any_enabled     = '';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || ! is_string( $item['id'] ) ) {
				continue;
			}

			// Skip disabled configs (status defaults to enabled when absent).
			if ( isset( $item['status'] ) && is_string( $item['status'] ) && 'ENABLED' !== strtoupper( $item['status'] ) ) {
				continue;
			}

			$is_managed = ! empty( $item['is_composio_managed'] );
			$is_default = isset( $item['type'] ) && 'default' === $item['type'];

			if ( $is_managed && $is_default && '' === $managed_default ) {
				$managed_default = $item['id'];
				break;
			}
			if ( $is_managed && '' === $managed_any ) {
				$managed_any = $item['id'];
			}
			if ( '' === $any_enabled ) {
				$any_enabled = $item['id'];
			}
		}

		$auth_config_id = '' !== $managed_default ? $managed_default : ( '' !== $managed_any ? $managed_any : $any_enabled );

		if ( '' === $auth_config_id ) {
			return new WP_Error(
				'wp_mcp_ai_composio_no_auth_config',
				/* translators: %s: toolkit slug */
				sprintf( __( 'No enabled auth config was found for the %s toolkit. Connect the toolkit once in the Composio dashboard first (or create an auth config for it), then try again.', 'mcp-ai-wpoos-pro' ), $toolkit )
			);
		}

		return $auth_config_id;
	}

	/**
	 * Create a Composio Connect Link (hosted authentication session).
	 *
	 * The toolkit slug is resolved to a project auth config (ac_...) before
	 * the link is created, because Composio's link endpoint requires an auth
	 * config ID rather than a toolkit slug.
	 *
	 * @since 1.4.0
	 *
	 * @param string $toolkit      Toolkit slug (e.g. "gmail", "slack").
	 * @param string $user_id      Application-defined stable user identifier.
	 * @param string $redirect_url Fallback callback URL used when $opts['callback_url'] is not provided.
	 * @param array  $opts         Optional. Extra link options (callback_url, alias).
	 * @return array|WP_Error Response array with link_token/redirect_url/connected_account_id, or WP_Error.
	 */
	public function create_connect_link( $toolkit, $user_id, $redirect_url, array $opts = array() ) {
		$toolkit      = sanitize_key( (string) $toolkit );
		$user_id      = sanitize_text_field( (string) $user_id );
		$redirect_url = esc_url_raw( $redirect_url );

		if ( '' === $toolkit || '' === $user_id || '' === $redirect_url ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_link_params', __( 'Toolkit, user ID and redirect URL are required to create a Connect Link.', 'mcp-ai-wpoos-pro' ) );
		}

		$auth_config_id = $this->resolve_auth_config_id( $toolkit );

		if ( is_wp_error( $auth_config_id ) ) {
			return $auth_config_id;
		}

		$callback_url = isset( $opts['callback_url'] ) && '' !== (string) $opts['callback_url'] ? esc_url_raw( $opts['callback_url'] ) : $redirect_url;

		$body = array(
			'auth_config_id' => $auth_config_id,
			'user_id'        => $user_id,
			'callback_url'   => $callback_url,
		);

		if ( isset( $opts['alias'] ) && '' !== (string) $opts['alias'] ) {
			$body['alias'] = sanitize_text_field( $opts['alias'] );
		}

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
	 * List available tools, unwrapped and normalised.
	 *
	 * `GET /tools` answers with the v3.1 pagination envelope
	 * (`{ items, next_cursor, total_pages, current_page, total_items }`).
	 * Returning that envelope raw was the cause of blank tool discovery: callers
	 * iterating the response saw the `items` key itself as the first "tool",
	 * yielding exactly one entry with no slug. This method always returns the
	 * envelope in a documented shape with `items` guaranteed to be a list of
	 * normalised tool records.
	 *
	 * Filters are mapped onto the parameter names the endpoint actually accepts:
	 * `toolkit_slug` (singular), `tool_slugs`, `query` (`search` is deprecated
	 * upstream), `limit` and `cursor`. `page` is not a supported parameter.
	 *
	 * @since 1.4.0
	 * @since 1.4.1 Unwraps the pagination envelope and normalises tool records.
	 *
	 * @param array $filters Optional. toolkit_slug|toolkit, search|query, tool_slugs, limit, cursor, important, include_deprecated.
	 * @return array{items:array,next_cursor:string,total_items:int,total_pages:int,current_page:int}|WP_Error
	 */
	public function list_tools( array $filters = array() ) {
		$query = array();

		$toolkit = '';
		foreach ( array( 'toolkit_slug', 'toolkit', 'toolkits' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$toolkit = sanitize_key( is_array( $filters[ $key ] ) ? reset( $filters[ $key ] ) : $filters[ $key ] );
				break;
			}
		}
		if ( '' !== $toolkit ) {
			$query['toolkit_slug'] = $toolkit;
		}

		// `search` was deprecated in favour of `query`; accept either from callers.
		foreach ( array( 'query', 'search' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$query['query'] = sanitize_text_field( (string) $filters[ $key ] );
				break;
			}
		}

		if ( ! empty( $filters['tool_slugs'] ) ) {
			$slugs = is_array( $filters['tool_slugs'] ) ? $filters['tool_slugs'] : explode( ',', (string) $filters['tool_slugs'] );
			$slugs = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $slugs ) ) );
			if ( ! empty( $slugs ) ) {
				$query['tool_slugs'] = implode( ',', $slugs );
			}
		}

		if ( ! empty( $filters['cursor'] ) ) {
			$query['cursor'] = sanitize_text_field( (string) $filters['cursor'] );
		}

		if ( isset( $filters['important'] ) ) {
			$query['important'] = $filters['important'] ? 'true' : 'false';
		}

		if ( isset( $filters['include_deprecated'] ) ) {
			$query['include_deprecated'] = $filters['include_deprecated'] ? 'true' : 'false';
		}

		$query['limit'] = isset( $filters['limit'] ) ? min( 1000, max( 1, absint( $filters['limit'] ) ) ) : 50;

		$path   = add_query_arg( $query, '/api/' . self::API_VERSION . '/tools' );
		$result = $this->request( 'GET', $path, array(), array(), self::TOOLS_CACHE_TTL );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = isset( $result['response'] ) ? $result['response'] : array();
		$items    = self::unwrap_items( $response );

		$tools = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$normalized = self::normalize_tool( $item );

			// A record with no slug is not addressable and would reproduce the
			// blank-entry symptom downstream.
			if ( '' === $normalized['slug'] ) {
				continue;
			}

			$tools[] = $normalized;
		}

		return array_merge(
			array( 'items' => $tools ),
			self::extract_pagination( $response, count( $tools ) )
		);
	}

	/**
	 * Normalise a tool catalog record into a stable, flat shape.
	 *
	 * Version 3.1 nests the toolkit as `{ slug, name, logo }` and names the
	 * identifier `slug`; older payloads used a flat `toolkit` string and
	 * `tool_slug`. Both are accepted. The raw `input_parameters` block is
	 * preserved so callers can inspect required arguments without a second
	 * round-trip.
	 *
	 * @since 1.4.1
	 *
	 * @param array $tool Raw tool record.
	 * @return array Normalised tool record.
	 */
	public static function normalize_tool( array $tool ) {
		$slug = '';
		foreach ( array( 'slug', 'tool_slug', 'name_slug' ) as $key ) {
			if ( isset( $tool[ $key ] ) && is_scalar( $tool[ $key ] ) && '' !== (string) $tool[ $key ] ) {
				$slug = strtoupper( (string) $tool[ $key ] );
				break;
			}
		}

		$toolkit      = isset( $tool['toolkit'] ) ? $tool['toolkit'] : '';
		$toolkit_name = '';
		if ( is_array( $toolkit ) ) {
			$toolkit_name = isset( $toolkit['name'] ) ? (string) $toolkit['name'] : '';
			$toolkit      = isset( $toolkit['slug'] ) ? (string) $toolkit['slug'] : '';
		}
		$toolkit = strtolower( (string) $toolkit );

		// Fall back to the slug prefix, which is the toolkit by construction.
		if ( '' === $toolkit && '' !== $slug && false !== strpos( $slug, '_' ) ) {
			$toolkit = strtolower( substr( $slug, 0, strpos( $slug, '_' ) ) );
		}

		$description = '';
		foreach ( array( 'description', 'human_description' ) as $key ) {
			if ( isset( $tool[ $key ] ) && is_string( $tool[ $key ] ) && '' !== $tool[ $key ] ) {
				$description = $tool[ $key ];
				break;
			}
		}

		$input = isset( $tool['input_parameters'] ) && is_array( $tool['input_parameters'] ) ? $tool['input_parameters'] : array();

		return array(
			'slug'             => $slug,
			'name'             => isset( $tool['name'] ) && is_string( $tool['name'] ) ? $tool['name'] : $slug,
			'description'      => $description,
			'toolkit'          => $toolkit,
			'toolkit_name'     => $toolkit_name,
			'deprecated'       => ! empty( $tool['deprecated'] ) || ! empty( $tool['is_deprecated'] ),
			'no_auth'          => ! empty( $tool['no_auth'] ),
			'version'          => isset( $tool['version'] ) && is_scalar( $tool['version'] ) ? (string) $tool['version'] : '',
			'required_inputs'  => isset( $input['required'] ) && is_array( $input['required'] ) ? array_values( array_map( 'strval', $input['required'] ) ) : array(),
			'input_parameters' => $input,
		);
	}

	/**
	 * List toolkits (apps) available to the project.
	 *
	 * Needed for a browsable "what can I do?" view: the tool catalog alone has
	 * no cheap way to enumerate the ~1,000 available apps.
	 *
	 * @since 1.4.1
	 *
	 * @param array $filters Optional. search, category, limit, cursor, sort_by, managed_by.
	 * @return array{items:array,next_cursor:string,total_items:int,total_pages:int,current_page:int}|WP_Error
	 */
	public function list_toolkits( array $filters = array() ) {
		$query = array(
			'limit' => isset( $filters['limit'] ) ? min( 1000, max( 1, absint( $filters['limit'] ) ) ) : 100,
		);

		if ( ! empty( $filters['search'] ) ) {
			$query['search'] = sanitize_text_field( (string) $filters['search'] );
		}
		if ( ! empty( $filters['category'] ) ) {
			$query['category'] = sanitize_key( (string) $filters['category'] );
		}
		if ( ! empty( $filters['cursor'] ) ) {
			$query['cursor'] = sanitize_text_field( (string) $filters['cursor'] );
		}
		if ( ! empty( $filters['sort_by'] ) && in_array( $filters['sort_by'], array( 'usage', 'alphabetically' ), true ) ) {
			$query['sort_by'] = $filters['sort_by'];
		}
		if ( ! empty( $filters['managed_by'] ) && in_array( $filters['managed_by'], array( 'composio', 'all', 'project' ), true ) ) {
			$query['managed_by'] = $filters['managed_by'];
		}

		$path   = add_query_arg( $query, '/api/' . self::API_VERSION . '/toolkits' );
		$result = $this->request( 'GET', $path, array(), array(), self::TOOLS_CACHE_TTL );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = isset( $result['response'] ) ? $result['response'] : array();
		$items    = self::unwrap_items( $response );

		$toolkits = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['slug'] ) ) {
				continue;
			}

			$meta       = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
			$categories = array();
			if ( isset( $meta['categories'] ) && is_array( $meta['categories'] ) ) {
				foreach ( $meta['categories'] as $category ) {
					if ( is_array( $category ) && isset( $category['name'] ) ) {
						$categories[] = (string) $category['name'];
					} elseif ( is_string( $category ) ) {
						$categories[] = $category;
					}
				}
			}

			$toolkits[] = array(
				'slug'        => strtolower( (string) $item['slug'] ),
				'name'        => isset( $item['name'] ) ? (string) $item['name'] : (string) $item['slug'],
				'description' => isset( $meta['description'] ) ? (string) $meta['description'] : '',
				'categories'  => $categories,
				'tools_count' => isset( $meta['tools_count'] ) ? absint( $meta['tools_count'] ) : 0,
				'no_auth'     => ! empty( $item['no_auth'] ),
				'deprecated'  => ! empty( $item['deprecated'] ),
			);
		}

		return array_merge(
			array( 'items' => $toolkits ),
			self::extract_pagination( $response, count( $toolkits ) )
		);
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
	 * Composio signals tool failure *in band*: the execute endpoint answers
	 * HTTP 200 with `{ successful: false, error: "..." }`. Returning that body
	 * as-is made a revoked-credential failure look like a success to every
	 * caller, so an in-band failure is converted into a `WP_Error` here — the
	 * canonical failure shape the rest of the plugin already understands.
	 * Auth-class failures get their own error code so callers can offer a
	 * reconnect instead of a raw stack trace.
	 *
	 * @since 1.4.0
	 * @since 1.4.1 In-band `successful: false` responses become WP_Error.
	 *
	 * @param string $tool_slug  SCREAMING_SNAKE tool slug.
	 * @param string $account_id Connected account nanoid.
	 * @param array  $arguments  Tool input arguments.
	 * @param string $user_id    Optional. Composio user identity that owns the account.
	 *                           Defaults to the identity bound to this client.
	 * @return array|WP_Error
	 */
	public function execute_tool( $tool_slug, $account_id, array $arguments, $user_id = '' ) {
		$tool_slug  = sanitize_text_field( (string) $tool_slug );
		$account_id = sanitize_text_field( (string) $account_id );
		$user_id    = '' !== (string) $user_id ? sanitize_text_field( (string) $user_id ) : $this->user_id;

		if ( '' === $tool_slug || ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $tool_slug ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_invalid_tool_slug', __( 'Tool slug must be SCREAMING_SNAKE_CASE.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $account_id ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_account', __( 'A connected account ID is required to execute tools.', 'mcp-ai-wpoos-pro' ) );
		}

		$body = array(
			'connected_account_id' => $account_id,
			'toolkit_versions'     => 'latest',
			'arguments'            => $arguments,
		);

		// Composio rejects a connected account that arrives without the user
		// identity it belongs to ("User ID is required with connected account"),
		// so the resolved identity is always sent alongside the account.
		if ( '' !== $user_id ) {
			$body['user_id'] = $user_id;
		}

		$result = $this->request(
			'POST',
			'/api/' . self::API_VERSION . '/tools/execute/' . rawurlencode( $tool_slug ),
			array(),
			$body
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : array();

		$in_band = self::detect_execution_failure( $response, $tool_slug, $account_id );

		if ( is_wp_error( $in_band ) ) {
			return $in_band;
		}

		return isset( $result['response'] ) ? $result['response'] : array();
	}

	/**
	 * Convert Composio's in-band execution failure envelope into a WP_Error.
	 *
	 * Only an *explicitly* falsy `successful` key is treated as a failure, so
	 * payloads that omit the key (older shapes, test doubles) are unaffected.
	 *
	 * @since 1.4.1
	 *
	 * @param array  $response   Decoded execute response.
	 * @param string $tool_slug  Tool slug that was executed.
	 * @param string $account_id Connected account nanoid.
	 * @return true|WP_Error True when the execution succeeded.
	 */
	public static function detect_execution_failure( array $response, $tool_slug = '', $account_id = '' ) {
		if ( ! array_key_exists( 'successful', $response ) ) {
			return true;
		}

		$flag = $response['successful'];

		// Only an explicitly falsy flag is a failure. A non-boolean value (an
		// unexpected shape) is treated as success so this guard can never invent
		// a failure that Composio did not report.
		$is_failure = ( false === $flag || 0 === $flag || '0' === $flag || 'false' === $flag || null === $flag );

		if ( ! $is_failure ) {
			return true;
		}

		$message = '';
		if ( isset( $response['error'] ) && is_string( $response['error'] ) && '' !== $response['error'] ) {
			$message = $response['error'];
		} elseif ( isset( $response['error']['message'] ) && is_string( $response['error']['message'] ) ) {
			$message = $response['error']['message'];
		} elseif ( isset( $response['message'] ) && is_string( $response['message'] ) ) {
			$message = $response['message'];
		}

		if ( '' === $message ) {
			$message = __( 'Composio reported the tool execution as unsuccessful without an error message.', 'mcp-ai-wpoos-pro' );
		}

		$is_auth = class_exists( 'WP_MCP_AI_Composio_Account_Health' )
			&& WP_MCP_AI_Composio_Account_Health::is_auth_error( '', $message );

		return new WP_Error(
			$is_auth ? 'wp_mcp_ai_composio_account_auth_required' : 'wp_mcp_ai_composio_tool_failed',
			sprintf(
				/* translators: 1: tool slug, 2: upstream error message */
				__( 'Composio tool %1$s failed: %2$s', 'mcp-ai-wpoos-pro' ),
				'' !== $tool_slug ? $tool_slug : __( '(unknown)', 'mcp-ai-wpoos-pro' ),
				$message
			),
			array(
				'tool_slug'            => $tool_slug,
				'connected_account_id' => $account_id,
				'auth_failure'         => $is_auth,
				'log_id'               => isset( $response['log_id'] ) && is_scalar( $response['log_id'] ) ? (string) $response['log_id'] : '',
				'upstream_error'       => $message,
			)
		);
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

		// A trigger needs an owner: either a pinned connected account or the user
		// identity whose active account Composio should resolve.
		if ( empty( $config['connected_account_id'] ) && empty( $config['user_id'] ) && '' !== $this->user_id ) {
			$config['user_id'] = $this->user_id;
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
