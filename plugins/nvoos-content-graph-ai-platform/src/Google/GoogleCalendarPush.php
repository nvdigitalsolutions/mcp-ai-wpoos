<?php
/**
 * Google Calendar push notification receiver and channel manager (Wave E4,
 * sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Google_Calendar_Push`:
 * byte-identical constants (REST namespace/route, channels option, 7-day
 * TTL, 1-day renewal threshold), the `rest_api_init` route registration,
 * the header-token `verify_notification()` permission callback with its
 * error codes, the high-water-mark-deduplicated `handle_notification()`
 * that defers the read to a one-off scheduled sync, the HTTPS /
 * public-host / private-range `is_push_eligible()` gate with its
 * `wp_mcp_ai_google_calendar_push_eligible` filter, the channel record
 * store, the write-before-watch `watch()` flow, the credential-loss-safe
 * `stop()` / `stop_all_for_connection()` teardown, and the
 * replacement-first `renew_expiring_channels()` cron callback.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - Per-mode collaborator seams (`defined( 'WP_MCP_AI_PATH' )` is the
 *    discriminator): credentials/scope/client/sync classes resolve to
 *    the base monolith classes in monolith installs and to this
 *    package's `Google\*` classes standalone.
 *  - Core classes (`WP_REST_Server`, `WP_REST_Request`,
 *    `WP_REST_Response`) and `WP_Error` are fully qualified.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Google
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Google;

/**
 * Manages Google Calendar push notification channels and their webhook.
 *
 * @since 2.1.0
 */
class GoogleCalendarPush {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * REST route for the notification receiver.
	 *
	 * @var string
	 */
	const REST_ROUTE = '/google-calendar/webhook';

	/**
	 * Option storing live channel records keyed by channel ID.
	 *
	 * @var string
	 */
	const CHANNELS_OPTION = 'wp_mcp_ai_google_calendar_channels';

	/**
	 * Requested channel lifetime, in seconds.
	 *
	 * Google's documented maximum and default for Calendar is 604800 (7 days).
	 *
	 * @var int
	 */
	const CHANNEL_TTL = 604800;

	/**
	 * Renew a channel once its remaining life falls below this, in seconds.
	 *
	 * One day of headroom before the 7-day expiry.
	 *
	 * @var int
	 */
	const RENEW_THRESHOLD = 86400;

	/**
	 * Register hooks.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the notification receiver route.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_notification' ),
				'permission_callback' => array( $this, 'verify_notification' ),
			)
		);
	}

	/**
	 * Verify an inbound notification.
	 *
	 * Authentication is by the opaque channel token that we generated and Google
	 * echoes back in `X-Goog-Channel-Token`, combined with a lookup of the
	 * channel ID. This is a state-changing route, so it must never use
	 * `__return_true`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return true|\WP_Error True when the notification is authentic.
	 */
	public function verify_notification( $request ) {
		$channel_id = (string) $request->get_header( 'x_goog_channel_id' );
		$token      = (string) $request->get_header( 'x_goog_channel_token' );

		if ( '' === $channel_id || '' === $token ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_push_unauthenticated',
				__( 'Missing Google notification headers.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 401 )
			);
		}

		$channel = self::get_channel( $channel_id );

		if ( empty( $channel ) || empty( $channel['token'] ) ) {
			// Unknown channel. This can legitimately happen when Google delivers
			// the `sync` handshake before our `watch` response is committed, but
			// there is nothing to authenticate against, so reject and let the
			// safety-net poll cover the gap.
			return new \WP_Error(
				'wp_mcp_ai_calendar_push_unknown_channel',
				__( 'Unrecognised Google notification channel.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		if ( ! hash_equals( (string) $channel['token'], $token ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_push_bad_token',
				__( 'Google notification token mismatch.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle a verified notification.
	 *
	 * Returns `200` as fast as possible and defers the API read to a one-off
	 * scheduled job. Google treats `200`, `201`, `202`, `204`, and `102` as
	 * success; anything else counts as a delivery failure.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response Always a 200 acknowledgement.
	 */
	public function handle_notification( $request ) {
		$channel_id     = (string) $request->get_header( 'x_goog_channel_id' );
		$resource_state = (string) $request->get_header( 'x_goog_resource_state' );
		$message_number = (int) $request->get_header( 'x_goog_message_number' );

		$channel = self::get_channel( $channel_id );

		// `sync` is the post-subscription handshake, not a change notification.
		// Message number is always 1 for it.
		if ( 'sync' === $resource_state ) {
			return new \WP_REST_Response( array( 'acknowledged' => true ), 200 );
		}

		// Message numbers increase but are not sequential, so dedupe on a
		// high-water mark rather than expecting contiguity. This also absorbs
		// the overlap window during channel renewal, when two live channels
		// report the same change.
		if ( $message_number > 0 ) {
			$seen_key = 'wp_mcp_ai_gcal_msgnum_' . md5( $channel_id );
			$seen     = (int) get_transient( $seen_key );

			if ( $message_number <= $seen ) {
				return new \WP_REST_Response( array( 'duplicate' => true ), 200 );
			}

			set_transient( $seen_key, $message_number, self::CHANNEL_TTL + DAY_IN_SECONDS );
		}

		if ( ! empty( $channel ) && 'exists' === $resource_state ) {
			$connection_id = isset( $channel['connection_id'] ) ? (string) $channel['connection_id'] : '';
			$calendar_id   = isset( $channel['calendar_id'] ) ? (string) $channel['calendar_id'] : 'primary';

			$sync_class = self::sync_class();

			// Defer the read: the notification body is empty, so we must call the
			// API anyway, and doing it here would risk exceeding Google's
			// delivery timeout budget.
			if ( ! wp_next_scheduled( $sync_class::SYNC_HOOK, array( $connection_id, $calendar_id ) ) ) {
				wp_schedule_single_event(
					time() + 5,
					$sync_class::SYNC_HOOK,
					array( $connection_id, $calendar_id )
				);
			}
		}

		return new \WP_REST_Response( array( 'acknowledged' => true ), 200 );
	}

	// Eligibility.

	/**
	 * Whether this site can receive Google push notifications.
	 *
	 * Google requires HTTPS with a valid CA-signed certificate, so loopback and
	 * private-network hosts can never work. Detecting this up front turns an
	 * opaque `watch` failure into an actionable admin message.
	 *
	 * @since 2.1.0
	 *
	 * @return true|\WP_Error True when eligible, WP_Error describing why not.
	 */
	public static function is_push_eligible() {
		$url  = rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_push_requires_https',
				__( 'Google push notifications require the site to be served over HTTPS with a valid certificate. Incremental polling will be used instead.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! $host || 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_push_requires_public_host',
				__( 'Google push notifications require a publicly resolvable domain name. Incremental polling will be used instead.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Private and reserved ranges cannot be reached by Google.
		$resolved = gethostbyname( $host );

		if ( $resolved && $resolved !== $host && ! filter_var( $resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_push_private_host',
				__( 'This site resolves to a private network address, which Google cannot reach. Incremental polling will be used instead.', 'nvoos-content-graph-ai-platform' )
			);
		}

		/**
		 * Filters whether Google Calendar push notifications are eligible.
		 *
		 * Return a WP_Error to disable push with an explanation.
		 *
		 * @since 2.1.0
		 *
		 * @param true|\WP_Error $eligible Eligibility result.
		 * @param string         $url      Notification receiver URL.
		 */
		return apply_filters( 'wp_mcp_ai_google_calendar_push_eligible', true, $url );
	}

	// Channel lifecycle.

	/**
	 * Read all stored channel records.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_channels() {
		$channels = get_option( self::CHANNELS_OPTION, array() );

		return is_array( $channels ) ? $channels : array();
	}

	/**
	 * Read one channel record.
	 *
	 * @since 2.1.0
	 *
	 * @param string $channel_id Channel identifier.
	 * @return array<string,mixed> Channel record, or an empty array.
	 */
	public static function get_channel( $channel_id ) {
		$channels   = self::get_channels();
		$channel_id = (string) $channel_id;

		return isset( $channels[ $channel_id ] ) && is_array( $channels[ $channel_id ] )
			? $channels[ $channel_id ]
			: array();
	}

	/**
	 * Persist a channel record.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $channel_id Channel identifier.
	 * @param array<string,mixed> $record     Channel record.
	 * @return void
	 */
	protected static function save_channel( $channel_id, array $record ) {
		$channels                         = self::get_channels();
		$channels[ (string) $channel_id ] = $record;

		update_option( self::CHANNELS_OPTION, $channels, false );
	}

	/**
	 * Remove a channel record.
	 *
	 * @since 2.1.0
	 *
	 * @param string $channel_id Channel identifier.
	 * @return void
	 */
	protected static function forget_channel( $channel_id ) {
		$channels   = self::get_channels();
		$channel_id = (string) $channel_id;

		if ( isset( $channels[ $channel_id ] ) ) {
			unset( $channels[ $channel_id ] );
			update_option( self::CHANNELS_OPTION, $channels, false );
		}
	}

	/**
	 * Open a push notification channel for a calendar.
	 *
	 * The channel record is written *before* calling `watch`, because Google can
	 * deliver the `sync` handshake before the `watch` response returns.
	 *
	 * @since 2.1.0
	 *
	 * @param string $connection_id Connection ID, or empty for base settings.
	 * @param string $calendar_id   Calendar identifier, or empty for the default.
	 * @return array<string,mixed>|\WP_Error Channel record or WP_Error.
	 */
	public static function watch( $connection_id = '', $calendar_id = '' ) {
		$eligible = self::is_push_eligible();

		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$credentials_class = self::credentials_class();
		$scopes_class      = self::scopes_class();

		$credentials = $credentials_class::resolve( $connection_id );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$scope_check = $credentials_class::require_scope(
			$credentials,
			$scopes_class::SCOPE_EVENTS_READONLY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$calendar_id = $credentials_class::resolve_calendar_id( $credentials, $calendar_id );

		$client = $credentials_class::make_client( $credentials );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Channel IDs are capped at 64 characters by Google.
		$channel_id = substr( 'nvoos-' . wp_generate_uuid4(), 0, 64 );

		// The token is an opaque correlator, capped at 256 characters. It must
		// never carry a credential, since it travels to Google and back.
		$token = wp_generate_password( 32, false, false );

		$record = array(
			'channel_id'    => $channel_id,
			'token'         => $token,
			'connection_id' => '' !== $connection_id ? sanitize_key( $connection_id ) : '',
			'calendar_id'   => $calendar_id,
			'resource_id'   => '',
			'expiration'    => 0,
			'created_at'    => time(),
		);

		// Written first to survive the sync-handshake race.
		self::save_channel( $channel_id, $record );

		$response = $client->watch_events(
			$calendar_id,
			array(
				'id'      => $channel_id,
				'type'    => 'web_hook',
				'address' => rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
				'token'   => $token,
				'params'  => array( 'ttl' => (string) self::CHANNEL_TTL ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::forget_channel( $channel_id );

			return $response;
		}

		$record['resource_id'] = isset( $response['resourceId'] ) ? (string) $response['resourceId'] : '';

		// Google returns `expiration` as a Unix timestamp in milliseconds.
		$record['expiration'] = isset( $response['expiration'] )
			? (int) round( ( (float) $response['expiration'] ) / 1000 )
			: ( time() + self::CHANNEL_TTL );

		self::save_channel( $channel_id, $record );

		self::sync_class()::save_state(
			$record['connection_id'],
			$calendar_id,
			array(
				'channel_id'          => $channel_id,
				'channel_resource_id' => $record['resource_id'],
				'channel_expiration'  => $record['expiration'],
			)
		);

		return $record;
	}

	/**
	 * Close a push notification channel.
	 *
	 * @since 2.1.0
	 *
	 * @param string $channel_id Channel identifier.
	 * @return true|\WP_Error True on success, WP_Error otherwise.
	 */
	public static function stop( $channel_id ) {
		$channel = self::get_channel( $channel_id );

		if ( empty( $channel ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_push_channel_not_found',
				__( 'That Google notification channel is not registered.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$credentials_class = self::credentials_class();

		$credentials = $credentials_class::resolve(
			isset( $channel['connection_id'] ) ? (string) $channel['connection_id'] : ''
		);

		// Even when credentials are gone we still drop the local record, so a
		// deleted connection cannot leave an unreferenceable channel behind.
		if ( is_wp_error( $credentials ) ) {
			self::forget_channel( $channel_id );

			return $credentials;
		}

		$client = $credentials_class::make_client( $credentials );

		if ( is_wp_error( $client ) ) {
			self::forget_channel( $channel_id );

			return $client;
		}

		$result = $client->stop_channel( $channel_id, isset( $channel['resource_id'] ) ? $channel['resource_id'] : '' );

		self::forget_channel( $channel_id );
		delete_transient( 'wp_mcp_ai_gcal_msgnum_' . md5( (string) $channel_id ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Stop every channel belonging to a connection.
	 *
	 * Call this when a connection is disconnected or deleted, and from
	 * `uninstall.php`.
	 *
	 * @since 2.1.0
	 *
	 * @param string $connection_id Connection ID, or empty for base settings.
	 * @return int Number of channels stopped.
	 */
	public static function stop_all_for_connection( $connection_id ) {
		$connection_id = '' !== $connection_id ? sanitize_key( $connection_id ) : '';
		$stopped       = 0;

		foreach ( self::get_channels() as $channel_id => $channel ) {
			$owner = isset( $channel['connection_id'] ) ? (string) $channel['connection_id'] : '';

			if ( $owner !== $connection_id ) {
				continue;
			}

			self::stop( $channel_id );
			++$stopped;
		}

		return $stopped;
	}

	/**
	 * Cron callback: renew channels approaching expiry.
	 *
	 * Google performs no auto-renewal, and renewal requires a *new* channel ID.
	 * The old channel is stopped only after the replacement is confirmed, so a
	 * failed renewal never leaves the calendar unwatched.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function renew_expiring_channels() {
		$now = time();

		foreach ( self::get_channels() as $channel_id => $channel ) {
			$expiration = isset( $channel['expiration'] ) ? (int) $channel['expiration'] : 0;

			if ( $expiration > 0 && ( $expiration - $now ) > self::RENEW_THRESHOLD ) {
				continue;
			}

			$replacement = self::watch(
				isset( $channel['connection_id'] ) ? (string) $channel['connection_id'] : '',
				isset( $channel['calendar_id'] ) ? (string) $channel['calendar_id'] : ''
			);

			// Only retire the old channel once a replacement exists, otherwise a
			// transient failure would silently end notifications.
			if ( ! is_wp_error( $replacement ) ) {
				self::stop( $channel_id );
			}
		}
	}

	/**
	 * Resolve the credential resolver class per install mode.
	 *
	 * @return string Class name.
	 */
	protected static function credentials_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Google_Calendar_Credentials' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-credentials.php';
			}

			return 'WP_MCP_AI_Google_Calendar_Credentials';
		}

		return GoogleCalendarCredentials::class;
	}

	/**
	 * Resolve the scope registry class per install mode.
	 *
	 * @return string Class name.
	 */
	protected static function scopes_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Google_Calendar_Scopes' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-scopes.php';
			}

			return 'WP_MCP_AI_Google_Calendar_Scopes';
		}

		return GoogleCalendarScopes::class;
	}

	/**
	 * Resolve the sync engine class per install mode.
	 *
	 * @return string Class name.
	 */
	protected static function sync_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Google_Calendar_Sync' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-sync.php';
			}

			return 'WP_MCP_AI_Google_Calendar_Sync';
		}

		return GoogleCalendarSync::class;
	}
}
