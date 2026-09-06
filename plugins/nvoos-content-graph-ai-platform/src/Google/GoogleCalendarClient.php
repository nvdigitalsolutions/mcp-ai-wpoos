<?php
/**
 * Google Calendar API v3 client (Wave E4, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Google_Calendar_Client`:
 * byte-identical constants (API base, result caps, retry budget, backoff
 * ceiling, timeout, channel TTL/length caps, the `SYNC_FORBIDDEN_PARAMS`
 * set), the CalendarList / Events / FreeBusy / push-channel method
 * surface, `build_sync_params()` full-vs-incremental split,
 * `clamp_max_results()`, token-driven `paginate()`, the authenticated
 * `request()` retry loop, `interpret_response()` with its three-way 410
 * discrimination, the `403/429/5xx` retry classification, the stable
 * error-code map, the error body extractors, the filtered
 * `wp_mcp_ai_google_calendar_retry_backoff` sleep, the lazy token
 * resolver, the boolean-aware `stringify_param()`, and the
 * full-sync-required / auth-failure probes.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Google
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Google;

/**
 * Google Calendar API v3 client.
 *
 * @since 2.1.0
 */
class GoogleCalendarClient {

	/**
	 * Calendar API v3 base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://www.googleapis.com/calendar/v3';

	/**
	 * Default page size for list requests.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_RESULTS = 250;

	/**
	 * Hard upper bound Google enforces on `maxResults`.
	 *
	 * @var int
	 */
	const MAX_RESULTS_CAP = 2500;

	/**
	 * Maximum number of attempts for a retryable request.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Ceiling for exponential backoff, in seconds.
	 *
	 * @var int
	 */
	const MAX_BACKOFF_SECONDS = 32;

	/**
	 * Default HTTP timeout, in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Maximum channel TTL Google allows for Calendar push, in seconds (7 days).
	 *
	 * @var int
	 */
	const MAX_CHANNEL_TTL = 604800;

	/**
	 * Maximum length of a push channel identifier.
	 *
	 * @var int
	 */
	const MAX_CHANNEL_ID_LENGTH = 64;

	/**
	 * Maximum length of a push channel token.
	 *
	 * @var int
	 */
	const MAX_CHANNEL_TOKEN_LENGTH = 256;

	/**
	 * `events.list` parameters that are invalid when combined with `syncToken`.
	 *
	 * Supplying any of these alongside a sync token returns HTTP 400.
	 *
	 * @var array<string>
	 */
	const SYNC_FORBIDDEN_PARAMS = array(
		'iCalUID',
		'orderBy',
		'privateExtendedProperty',
		'q',
		'sharedExtendedProperty',
		'timeMin',
		'timeMax',
		'updatedMin',
	);

	/**
	 * Callable returning an access token, or a literal token string.
	 *
	 * A callable is preferred so the token can be minted lazily and refreshed
	 * mid-flight without the caller holding a stale value.
	 *
	 * @var callable|string
	 */
	protected $token_provider;

	/**
	 * Optional `quotaUser` value for per-user quota attribution.
	 *
	 * Required under domain-wide delegation, where Google otherwise charges the
	 * service account rather than the impersonated user.
	 *
	 * @var string
	 */
	protected $quota_user = '';

	/**
	 * HTTP timeout in seconds.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Cached resolved access token for this instance.
	 *
	 * @var string
	 */
	protected $resolved_token = '';

	/**
	 * Constructor.
	 *
	 * @since 2.1.0
	 *
	 * @param callable|string     $token_provider Access token, or a callable returning
	 *                                            a token string or WP_Error.
	 * @param array<string,mixed> $options {
	 *     Optional. Client options.
	 *
	 *     @type string $quota_user Value for the `quotaUser` parameter.
	 *     @type int    $timeout    HTTP timeout in seconds.
	 * }
	 */
	public function __construct( $token_provider, array $options = array() ) {
		$this->token_provider = $token_provider;

		if ( isset( $options['quota_user'] ) ) {
			$this->quota_user = sanitize_text_field( (string) $options['quota_user'] );
		}

		$timeout       = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : self::DEFAULT_TIMEOUT;
		$this->timeout = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
	}

	// CalendarList.

	/**
	 * List the calendars the authorised user is subscribed to.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $params Optional query parameters.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function list_calendars( array $params = array() ) {
		return $this->request( 'GET', '/users/me/calendarList', $params );
	}

	/**
	 * Get a single calendar-list entry.
	 *
	 * @since 2.1.0
	 *
	 * @param string $calendar_id Calendar identifier.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function get_calendar( $calendar_id ) {
		return $this->request( 'GET', '/users/me/calendarList/' . rawurlencode( $calendar_id ) );
	}

	/**
	 * Create a secondary calendar.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $body Calendar resource.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function insert_calendar( array $body ) {
		return $this->request( 'POST', '/calendars', array(), $body );
	}

	// Events.

	/**
	 * List events on a calendar.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id Calendar identifier, or `primary`.
	 * @param array<string,mixed> $params      Query parameters.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function list_events( $calendar_id, array $params = array() ) {
		return $this->request(
			'GET',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events',
			$params
		);
	}

	/**
	 * Get a single event.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id Calendar identifier.
	 * @param string              $event_id    Event identifier.
	 * @param array<string,mixed> $params      Query parameters.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function get_event( $calendar_id, $event_id, array $params = array() ) {
		return $this->request(
			'GET',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			$params
		);
	}

	/**
	 * Create an event.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id Calendar identifier.
	 * @param array<string,mixed> $body        Event resource.
	 * @param array<string,mixed> $params      Query parameters, e.g. `sendUpdates`.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function insert_event( $calendar_id, array $body, array $params = array() ) {
		return $this->request(
			'POST',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events',
			$params,
			$body
		);
	}

	/**
	 * Replace an event.
	 *
	 * Prefer this over `patch_event()` in hot paths: `events.patch` costs three
	 * quota units, while `get` + `update` costs two.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id Calendar identifier.
	 * @param string              $event_id    Event identifier.
	 * @param array<string,mixed> $body        Full event resource.
	 * @param array<string,mixed> $params      Query parameters.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function update_event( $calendar_id, $event_id, array $body, array $params = array() ) {
		return $this->request(
			'PUT',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			$params,
			$body
		);
	}

	/**
	 * Partially update an event.
	 *
	 * Costs three quota units. Use when you are not the event organiser, since
	 * `insert`/`update` treat omitted shared properties as "reset to default"
	 * and will fail with `forbiddenForNonOrganizer`.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id Calendar identifier.
	 * @param string              $event_id    Event identifier.
	 * @param array<string,mixed> $body        Partial event resource.
	 * @param array<string,mixed> $params      Query parameters.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function patch_event( $calendar_id, $event_id, array $body, array $params = array() ) {
		return $this->request(
			'PATCH',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			$params,
			$body
		);
	}

	/**
	 * Delete an event.
	 *
	 * A `410 deleted` response means the event was already gone, which is
	 * reported as success rather than an error.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id Calendar identifier.
	 * @param string              $event_id    Event identifier.
	 * @param array<string,mixed> $params      Query parameters, e.g. `sendUpdates`.
	 * @return array<string,mixed>|\WP_Error Result array or WP_Error.
	 */
	public function delete_event( $calendar_id, $event_id, array $params = array() ) {
		$result = $this->request(
			'DELETE',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			$params
		);

		if ( is_wp_error( $result ) && 'wp_mcp_ai_calendar_already_deleted' === $result->get_error_code() ) {
			return array( 'already_deleted' => true );
		}

		return $result;
	}

	/**
	 * Create an event from a natural-language string.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id Calendar identifier.
	 * @param string              $text        Natural-language event description.
	 * @param array<string,mixed> $params      Additional query parameters.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function quick_add_event( $calendar_id, $text, array $params = array() ) {
		$params['text'] = $text;

		return $this->request(
			'POST',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events/quickAdd',
			$params
		);
	}

	/**
	 * Move an event to a different calendar.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id     Source calendar identifier.
	 * @param string              $event_id        Event identifier.
	 * @param string              $destination_id  Destination calendar identifier.
	 * @param array<string,mixed> $params          Additional query parameters.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function move_event( $calendar_id, $event_id, $destination_id, array $params = array() ) {
		$params['destination'] = $destination_id;

		return $this->request(
			'POST',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ) . '/move',
			$params
		);
	}

	/**
	 * List the instances of a recurring event.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id Calendar identifier.
	 * @param string              $event_id    Recurring event identifier.
	 * @param array<string,mixed> $params      Query parameters.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function list_instances( $calendar_id, $event_id, array $params = array() ) {
		return $this->request(
			'GET',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ) . '/instances',
			$params
		);
	}

	// FreeBusy.

	/**
	 * Query free/busy information.
	 *
	 * Google caps `items` at 50 calendars and group expansion at 100 members.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $body FreeBusy request body.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function freebusy( array $body ) {
		return $this->request( 'POST', '/freeBusy', array(), $body );
	}

	// Push notification channels.

	/**
	 * Open a push notification channel on a calendar's events collection.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $calendar_id Calendar identifier.
	 * @param array<string,mixed> $channel     Channel resource. Requires `id`,
	 *                                         `type`, and `address`.
	 * @param array<string,mixed> $params      Additional query parameters.
	 * @return array<string,mixed>|\WP_Error Decoded channel resource or WP_Error.
	 */
	public function watch_events( $calendar_id, array $channel, array $params = array() ) {
		return $this->request(
			'POST',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events/watch',
			$params,
			$channel
		);
	}

	/**
	 * Close a push notification channel.
	 *
	 * @since 2.1.0
	 *
	 * @param string $channel_id  Channel identifier supplied at watch time.
	 * @param string $resource_id Opaque resource identifier returned by `watch`.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function stop_channel( $channel_id, $resource_id ) {
		return $this->request(
			'POST',
			'/channels/stop',
			array(),
			array(
				'id'         => $channel_id,
				'resourceId' => $resource_id,
			)
		);
	}

	// Pagination and sync helpers.

	/**
	 * Build a safe `events.list` parameter set for a sync mode.
	 *
	 * Full sync and incremental sync accept different parameters. `timeMin`,
	 * `timeMax`, `q`, `orderBy`, `updatedMin`, `iCalUID`, and the two extended
	 * property filters are legal on a full sync but return HTTP 400 when
	 * combined with `syncToken`. `showDeleted=false` is likewise rejected,
	 * because deletions must be delivered so clients can purge local rows.
	 *
	 * Every request in a sync sequence must otherwise use an identical
	 * parameter set, so callers should pass the same `$base` in both modes.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $mode       Either `full` or `incremental`.
	 * @param array<string,mixed> $base       Base parameters.
	 * @param string              $sync_token Sync token. Required for `incremental`.
	 * @return array<string,mixed> Safe parameter set.
	 */
	public static function build_sync_params( $mode, array $base = array(), $sync_token = '' ) {
		$params = $base;

		// Shared invariants for both modes.
		if ( ! isset( $params['maxResults'] ) ) {
			$params['maxResults'] = self::DEFAULT_MAX_RESULTS;
		}

		$params['maxResults'] = self::clamp_max_results( $params['maxResults'] );

		if ( ! isset( $params['singleEvents'] ) ) {
			$params['singleEvents'] = 'true';
		}

		if ( 'incremental' === $mode ) {
			foreach ( self::SYNC_FORBIDDEN_PARAMS as $forbidden ) {
				unset( $params[ $forbidden ] );
			}

			// Deletions must be returned during incremental sync.
			$params['showDeleted'] = 'true';
			$params['syncToken']   = (string) $sync_token;

			return $params;
		}

		// Full sync: showDeleted is optional but recommended for parity.
		if ( ! isset( $params['showDeleted'] ) ) {
			$params['showDeleted'] = 'true';
		}

		unset( $params['syncToken'] );

		return $params;
	}

	/**
	 * Clamp a `maxResults` value to Google's accepted range.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $value Requested page size.
	 * @return int Clamped page size.
	 */
	public static function clamp_max_results( $value ) {
		// Deliberately not absint(): that maps -5 to 5, silently accepting a
		// nonsensical negative page size instead of falling back to the default.
		$value = is_numeric( $value ) ? (int) $value : 0;

		if ( $value < 1 ) {
			return self::DEFAULT_MAX_RESULTS;
		}

		return min( $value, self::MAX_RESULTS_CAP );
	}

	/**
	 * Walk every page of a list endpoint and collect the items.
	 *
	 * Google may return a short page even when more results exist, so
	 * termination is driven solely by the absence of `nextPageToken`.
	 *
	 * @since 2.1.0
	 *
	 * @param callable            $fetch     Receives a parameter array and returns
	 *                                       a decoded response or WP_Error.
	 * @param array<string,mixed> $params    Initial parameters.
	 * @param int                 $max_pages Safety cap on page count.
	 * @return array{items:array<int,array<string,mixed>>,next_sync_token:string}|\WP_Error
	 */
	public static function paginate( callable $fetch, array $params = array(), $max_pages = 50 ) {
		$items           = array();
		$next_sync_token = '';
		$page            = 0;
		$max_pages       = max( 1, absint( $max_pages ) );

		do {
			$response = call_user_func( $fetch, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! empty( $response['items'] ) && is_array( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
					$items[] = $item;
				}
			}

			// `nextPageToken` and `nextSyncToken` are mutually exclusive; only
			// the final page of a sequence carries the sync token.
			$page_token = isset( $response['nextPageToken'] ) ? (string) $response['nextPageToken'] : '';

			if ( isset( $response['nextSyncToken'] ) ) {
				$next_sync_token = (string) $response['nextSyncToken'];
			}

			if ( '' === $page_token ) {
				break;
			}

			$params['pageToken'] = $page_token;
			++$page;
		} while ( $page < $max_pages );

		return array(
			'items'           => $items,
			'next_sync_token' => $next_sync_token,
		);
	}

	// Transport.

	/**
	 * Perform an authenticated Calendar API request with retry.
	 *
	 * @since 2.1.0
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path relative to the API base.
	 * @param array<string,mixed>      $params Query parameters.
	 * @param array<string,mixed>|null $body   Optional JSON request body.
	 * @return array<string,mixed>|\WP_Error Decoded response or WP_Error.
	 */
	public function request( $method, $path, array $params = array(), $body = null ) {
		$token = $this->resolve_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		if ( '' !== $this->quota_user ) {
			$params['quotaUser'] = $this->quota_user;
		}

		$url = self::API_BASE . $path;

		if ( ! empty( $params ) ) {
			$url = add_query_arg( array_map( array( $this, 'stringify_param' ), $params ), $url );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$attempt    = 0;
		$last_error = null;

		while ( $attempt < self::MAX_ATTEMPTS ) {
			$response = wp_remote_request( $url, $args );
			$outcome  = $this->interpret_response( $response );

			if ( 'success' === $outcome['disposition'] ) {
				return $outcome['data'];
			}

			if ( 'retry' !== $outcome['disposition'] ) {
				return $outcome['error'];
			}

			$last_error = $outcome['error'];
			++$attempt;

			if ( $attempt >= self::MAX_ATTEMPTS ) {
				break;
			}

			$this->sleep_for_backoff( $attempt );
		}

		return $last_error instanceof \WP_Error
			? $last_error
			: new \WP_Error(
				'wp_mcp_ai_calendar_retry_exhausted',
				__( 'The Google Calendar API remained unavailable after several retries.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 503 )
			);
	}

	/**
	 * Classify a raw HTTP response into success, retry, or a terminal error.
	 *
	 * @since 2.1.0
	 *
	 * @param array|\WP_Error $response Raw `wp_remote_request()` response.
	 * @return array{disposition:string,data?:array<string,mixed>,error?:\WP_Error}
	 */
	protected function interpret_response( $response ) {
		if ( is_wp_error( $response ) ) {
			// Transport failures are transient by nature.
			return array(
				'disposition' => 'retry',
				'error'       => new \WP_Error(
					'wp_mcp_ai_calendar_transport_error',
					__( 'Unable to reach the Google Calendar API.', 'nvoos-content-graph-ai-platform' ),
					array(
						'status' => 503,
						'error'  => $response->get_error_message(),
					)
				),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = '' === $raw ? array() : json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'disposition' => 'success',
				'data'        => $data,
			);
		}

		$reason  = self::extract_error_reason( $data );
		$message = self::extract_error_message( $data );

		// 410 carries three distinct meanings on this API.
		if ( 410 === $status ) {
			if ( 'deleted' === $reason ) {
				return array(
					'disposition' => 'error',
					'error'       => new \WP_Error(
						'wp_mcp_ai_calendar_already_deleted',
						__( 'The Google Calendar resource has already been deleted.', 'nvoos-content-graph-ai-platform' ),
						array(
							'status' => 410,
							'reason' => $reason,
						)
					),
				);
			}

			return array(
				'disposition' => 'error',
				'error'       => new \WP_Error(
					'wp_mcp_ai_calendar_full_sync_required',
					__( 'The Google Calendar sync token is no longer valid. A full resynchronisation is required.', 'nvoos-content-graph-ai-platform' ),
					array(
						'status' => 410,
						'reason' => $reason,
					)
				),
			);
		}

		if ( $this->is_retryable( $status, $reason ) ) {
			return array(
				'disposition' => 'retry',
				'error'       => new \WP_Error(
					'wp_mcp_ai_calendar_rate_limited',
					'' !== $message ? $message : __( 'The Google Calendar API is rate limiting this request.', 'nvoos-content-graph-ai-platform' ),
					array(
						'status' => $status,
						'reason' => $reason,
					)
				),
			);
		}

		return array(
			'disposition' => 'error',
			'error'       => new \WP_Error(
				self::error_code_for( $status, $reason ),
				'' !== $message ? $message : __( 'The Google Calendar API rejected the request.', 'nvoos-content-graph-ai-platform' ),
				array(
					'status'   => $status,
					'reason'   => $reason,
					'response' => $data,
				)
			),
		);
	}

	/**
	 * Whether a status and reason pair should be retried.
	 *
	 * Google documents `rateLimitExceeded` as returnable under either `403` or
	 * `429` and states both should be handled identically with exponential
	 * backoff. A bare `403` without a rate-limit reason is an authorisation
	 * failure and must not be retried.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $status HTTP status code.
	 * @param string $reason Google error reason.
	 * @return bool
	 */
	protected function is_retryable( $status, $reason ) {
		if ( in_array( $status, array( 429, 500, 502, 503, 504 ), true ) ) {
			return true;
		}

		if ( 403 === $status ) {
			return in_array(
				$reason,
				array( 'rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'backendError' ),
				true
			);
		}

		return false;
	}

	/**
	 * Map a status and reason pair to a stable WP_Error code.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $status HTTP status code.
	 * @param string $reason Google error reason.
	 * @return string WP_Error code.
	 */
	protected static function error_code_for( $status, $reason ) {
		if ( 'forbiddenForNonOrganizer' === $reason ) {
			return 'wp_mcp_ai_calendar_not_organizer';
		}

		switch ( $status ) {
			case 400:
				return 'wp_mcp_ai_calendar_bad_request';
			case 401:
				return 'wp_mcp_ai_calendar_unauthorized';
			case 403:
				return 'wp_mcp_ai_calendar_forbidden';
			case 404:
				return 'wp_mcp_ai_calendar_not_found';
			case 409:
				return 'wp_mcp_ai_calendar_conflict';
			case 412:
				return 'wp_mcp_ai_calendar_precondition_failed';
			default:
				return 'wp_mcp_ai_calendar_error';
		}
	}

	/**
	 * Extract Google's error reason from a decoded error body.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $data Decoded response body.
	 * @return string Reason, or an empty string.
	 */
	public static function extract_error_reason( array $data ) {
		if ( isset( $data['error']['errors'][0]['reason'] ) ) {
			return (string) $data['error']['errors'][0]['reason'];
		}

		if ( isset( $data['error']['status'] ) ) {
			return (string) $data['error']['status'];
		}

		return '';
	}

	/**
	 * Extract a human-readable message from a decoded error body.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $data Decoded response body.
	 * @return string Message, or an empty string.
	 */
	public static function extract_error_message( array $data ) {
		if ( isset( $data['error']['message'] ) ) {
			return (string) $data['error']['message'];
		}

		if ( isset( $data['error_description'] ) ) {
			return (string) $data['error_description'];
		}

		return '';
	}

	/**
	 * Sleep according to Google's recommended exponential backoff.
	 *
	 * The jitter component is recalculated on every attempt, which is the point
	 * of the algorithm: it de-synchronises fleets of clients that would
	 * otherwise retry in lockstep.
	 *
	 * @since 2.1.0
	 *
	 * @param int $attempt Attempt number, starting at 1.
	 * @return void
	 */
	protected function sleep_for_backoff( $attempt ) {
		$base   = min( (int) pow( 2, $attempt ), self::MAX_BACKOFF_SECONDS );
		$jitter = wp_rand( 0, 1000 ) / 1000;
		$wait   = min( $base + $jitter, (float) self::MAX_BACKOFF_SECONDS );

		/**
		 * Filters the backoff duration before a Calendar API retry.
		 *
		 * Returning 0 disables sleeping, which test suites rely on.
		 *
		 * @since 2.1.0
		 *
		 * @param float $wait    Seconds to wait.
		 * @param int   $attempt Attempt number.
		 */
		$wait = (float) apply_filters( 'wp_mcp_ai_google_calendar_retry_backoff', $wait, $attempt );

		if ( $wait <= 0 ) {
			return;
		}

		usleep( (int) round( $wait * 1000000 ) );
	}

	/**
	 * Resolve the access token for this instance.
	 *
	 * @since 2.1.0
	 *
	 * @return string|\WP_Error Access token or WP_Error.
	 */
	protected function resolve_token() {
		if ( '' !== $this->resolved_token ) {
			return $this->resolved_token;
		}

		$token = $this->token_provider;

		if ( is_callable( $token ) ) {
			$token = call_user_func( $token );
		}

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_missing_token',
				__( 'No Google access token is available for this Calendar request.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 401 )
			);
		}

		$this->resolved_token = $token;

		return $this->resolved_token;
	}

	/**
	 * Normalise a query parameter for URL building.
	 *
	 * Booleans must be sent as the literal strings `true` and `false`, not as
	 * `1` and `0`, which Google rejects.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $value Parameter value.
	 * @return string Normalised value.
	 */
	protected function stringify_param( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_array( $value ) ) {
			return implode( ',', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * Whether a WP_Error signals that a full resynchronisation is required.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $error Candidate error.
	 * @return bool
	 */
	public static function is_full_sync_required( $error ) {
		return $error instanceof \WP_Error
			&& 'wp_mcp_ai_calendar_full_sync_required' === $error->get_error_code();
	}

	/**
	 * Whether a WP_Error signals an expired or revoked authorisation.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $error Candidate error.
	 * @return bool
	 */
	public static function is_auth_failure( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return false;
		}

		return in_array(
			$error->get_error_code(),
			array(
				'wp_mcp_ai_calendar_unauthorized',
				'wp_mcp_ai_oauth_invalid_grant',
				'wp_mcp_ai_calendar_missing_token',
			),
			true
		);
	}
}
