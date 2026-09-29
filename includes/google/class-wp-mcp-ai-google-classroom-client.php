<?php
/**
 * Google Classroom API client.
 *
 * Thin REST client over the existing HTTP layer for the Classroom API v1.
 * Deliberately mirrors the Calendar client so that error classification,
 * retry policy, and token resolution behave identically across Google
 * integrations. Classroom-specific behaviours encoded here:
 *
 * - Repeated query parameters (`courseStates`, `studentIds`, `states`, ...)
 *   are built as repeated `key=value` pairs, because the Classroom API does
 *   not accept comma-joined values for them.
 * - `RESOURCE_EXHAUSTED` is the Classroom quota-exhaustion reason and must be
 *   retried with exponential backoff (official guidance).
 * - `@MissingGrant` means the authorising user has not granted the app the
 *   Classroom scopes (or the grant was revoked) — terminal and actionable:
 *   the connection must be reconnected, not retried.
 * - `courses.patch` requires an explicit `updateMask` query parameter.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Google_Classroom_Client' ) ) {
	/**
	 * Google Classroom API v1 client.
	 */
	class WP_MCP_AI_Google_Classroom_Client {

		/**
		 * Classroom API base URL.
		 *
		 * @var string
		 */
		const API_BASE = 'https://classroom.googleapis.com/v1';

		/**
		 * Default HTTP timeout in seconds.
		 *
		 * @var int
		 */
		const DEFAULT_TIMEOUT = 20;

		/**
		 * Maximum retry attempts for retryable failures.
		 *
		 * @var int
		 */
		const MAX_ATTEMPTS = 4;

		/**
		 * Maximum backoff wait in seconds.
		 *
		 * @var int
		 */
		const MAX_BACKOFF_SECONDS = 16;

		/**
		 * Maximum page size accepted by list endpoints.
		 *
		 * @var int
		 */
		const MAX_PAGE_SIZE = 100;

		/**
		 * Callable returning an access token, or a literal token string.
		 *
		 * @var callable|string
		 */
		protected $token_provider;

		/**
		 * Optional `quotaUser` value for per-user quota attribution.
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
		 * @since 1.0.0
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

		// Courses.

		/**
		 * List courses visible to the authorised user.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $params Query parameters. `courseStates` may be
		 *                                    an array and is sent as repeated params.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function list_courses( array $params = array() ) {
			return $this->request( 'GET', '/courses', $params );
		}

		/**
		 * Get a single course.
		 *
		 * @since 1.0.0
		 *
		 * @param string $course_id Course identifier.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function get_course( $course_id ) {
			return $this->request( 'GET', '/courses/' . rawurlencode( $course_id ) );
		}

		/**
		 * Create a course.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $body Course resource.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function create_course( array $body ) {
			return $this->request( 'POST', '/courses', array(), $body );
		}

		/**
		 * Update a course.
		 *
		 * The Classroom API requires an explicit `updateMask` listing the
		 * fields being changed.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $course_id  Course identifier.
		 * @param array<string,mixed> $body       Course fields to change.
		 * @param array<string>       $update_mask Field names being changed.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function update_course( $course_id, array $body, array $update_mask ) {
			$params = array();

			if ( ! empty( $update_mask ) ) {
				$params['updateMask'] = implode( ',', $update_mask );
			}

			return $this->request( 'PATCH', '/courses/' . rawurlencode( $course_id ), $params, $body );
		}

		/**
		 * Delete a course.
		 *
		 * @since 1.0.0
		 *
		 * @param string $course_id Course identifier.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function delete_course( $course_id ) {
			return $this->request( 'DELETE', '/courses/' . rawurlencode( $course_id ) );
		}

		// Rosters.

		/**
		 * List students in a course.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $course_id Course identifier.
		 * @param array<string,mixed> $params    Query parameters.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function list_students( $course_id, array $params = array() ) {
			return $this->request( 'GET', '/courses/' . rawurlencode( $course_id ) . '/students', $params );
		}

		/**
		 * Get a single student enrolment.
		 *
		 * @since 1.0.0
		 *
		 * @param string $course_id Course identifier.
		 * @param string $user_id   Student user ID (numeric or email address).
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function get_student( $course_id, $user_id ) {
			return $this->request(
				'GET',
				'/courses/' . rawurlencode( $course_id ) . '/students/' . rawurlencode( $user_id )
			);
		}

		/**
		 * Remove a student from a course.
		 *
		 * @since 1.0.0
		 *
		 * @param string $course_id Course identifier.
		 * @param string $user_id   Student user ID (numeric or email address).
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function delete_student( $course_id, $user_id ) {
			return $this->request(
				'DELETE',
				'/courses/' . rawurlencode( $course_id ) . '/students/' . rawurlencode( $user_id )
			);
		}

		/**
		 * List teachers in a course.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $course_id Course identifier.
		 * @param array<string,mixed> $params    Query parameters.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function list_teachers( $course_id, array $params = array() ) {
			return $this->request( 'GET', '/courses/' . rawurlencode( $course_id ) . '/teachers', $params );
		}

		// Announcements.

		/**
		 * Create an announcement in a course stream.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $course_id Course identifier.
		 * @param array<string,mixed> $body      Announcement resource.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function create_announcement( $course_id, array $body ) {
			return $this->request( 'POST', '/courses/' . rawurlencode( $course_id ) . '/announcements', array(), $body );
		}

		/**
		 * List announcements in a course stream.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $course_id Course identifier.
		 * @param array<string,mixed> $params    Query parameters.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function list_announcements( $course_id, array $params = array() ) {
			return $this->request( 'GET', '/courses/' . rawurlencode( $course_id ) . '/announcements', $params );
		}

		// Coursework.

		/**
		 * Create a coursework item.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $course_id Course identifier.
		 * @param array<string,mixed> $body      CourseWork resource.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function create_coursework( $course_id, array $body ) {
			return $this->request( 'POST', '/courses/' . rawurlencode( $course_id ) . '/courseWork', array(), $body );
		}

		/**
		 * List coursework items in a course.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $course_id Course identifier.
		 * @param array<string,mixed> $params    Query parameters.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function list_coursework( $course_id, array $params = array() ) {
			return $this->request( 'GET', '/courses/' . rawurlencode( $course_id ) . '/courseWork', $params );
		}

		// Submissions.

		/**
		 * List student submissions for a coursework item.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $course_id     Course identifier.
		 * @param string              $coursework_id CourseWork identifier.
		 * @param array<string,mixed> $params        Query parameters. `states` may be
		 *                                           an array (repeated params).
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function list_student_submissions( $course_id, $coursework_id, array $params = array() ) {
			return $this->request(
				'GET',
				'/courses/' . rawurlencode( $course_id ) . '/courseWork/' . rawurlencode( $coursework_id ) . '/studentSubmissions',
				$params
			);
		}

		// Profiles & guardians.

		/**
		 * Get a user profile.
		 *
		 * @since 1.0.0
		 *
		 * @param string $user_id User ID (numeric or email address).
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function get_user_profile( $user_id ) {
			return $this->request( 'GET', '/userProfiles/' . rawurlencode( $user_id ) );
		}

		/**
		 * List guardians for a student.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $student_id Student user ID.
		 * @param array<string,mixed> $params     Query parameters.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function list_guardians( $student_id, array $params = array() ) {
			return $this->request( 'GET', '/userProfiles/' . rawurlencode( $student_id ) . '/guardians', $params );
		}

		// Push registrations.

		/**
		 * Create a push-notification registration.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $body Registration resource.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function create_registration( array $body ) {
			return $this->request( 'POST', '/registrations', array(), $body );
		}

		/**
		 * Delete a push-notification registration.
		 *
		 * @since 1.0.0
		 *
		 * @param string $registration_id Registration identifier.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
		 */
		public function delete_registration( $registration_id ) {
			return $this->request( 'DELETE', '/registrations/' . rawurlencode( $registration_id ) );
		}

		/**
		 * Paginate a list endpoint until pages are exhausted.
		 *
		 * @since 1.0.0
		 *
		 * @param callable            $fetch     Callable taking `$params` and returning
		 *                                       a decoded response or WP_Error.
		 * @param array<string,mixed> $params    Initial query parameters.
		 * @param int                 $max_pages Hard cap on pages fetched.
		 * @return array<string,mixed>|WP_Error {
		 *     @type array $items All items across pages.
		 * }
		 */
		public static function paginate( callable $fetch, array $params = array(), $max_pages = 50 ) {
			$items     = array();
			$page      = 0;
			$max_pages = max( 1, absint( $max_pages ) );

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

				$page_token = isset( $response['nextPageToken'] ) ? (string) $response['nextPageToken'] : '';

				if ( '' === $page_token ) {
					break;
				}

				$params['pageToken'] = $page_token;
				++$page;
			} while ( $page < $max_pages );

			return array( 'items' => $items );
		}

		// Transport.

		/**
		 * Perform an authenticated Classroom API request with retry.
		 *
		 * @since 1.0.0
		 *
		 * @param string                   $method HTTP method.
		 * @param string                   $path   Path relative to the API base.
		 * @param array<string,mixed>      $params Query parameters. Array values are
		 *                                         sent as repeated parameters.
		 * @param array<string,mixed>|null $body   Optional JSON request body.
		 * @return array<string,mixed>|WP_Error Decoded response or WP_Error.
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
				$query = array();

				foreach ( $params as $key => $value ) {
					if ( is_array( $value ) ) {
						foreach ( $value as $item ) {
							$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( $this->stringify_param( $item ) );
						}
					} else {
						$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( $this->stringify_param( $value ) );
					}
				}

				$url .= '?' . implode( '&', $query );
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

			return $last_error instanceof WP_Error
				? $last_error
				: new WP_Error(
					'wp_mcp_ai_classroom_retry_exhausted',
					__( 'The Google Classroom API remained unavailable after several retries.', 'mcp-ai-wpoos' ),
					array( 'status' => 503 )
				);
		}

		/**
		 * Classify a raw HTTP response into success, retry, or a terminal error.
		 *
		 * @since 1.0.0
		 *
		 * @param array|WP_Error $response Raw `wp_remote_request()` response.
		 * @return array{disposition:string,data?:array<string,mixed>,error?:WP_Error}
		 */
		protected function interpret_response( $response ) {
			if ( is_wp_error( $response ) ) {
				// Transport failures are transient by nature.
				return array(
					'disposition' => 'retry',
					'error'       => new WP_Error(
						'wp_mcp_ai_classroom_transport_error',
						__( 'Unable to reach the Google Classroom API.', 'mcp-ai-wpoos' ),
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

			// A missing grant is terminal and actionable: the account must
			// reconnect and approve the Classroom scopes. Retrying is useless.
			if ( '@MissingGrant' === $reason || 'missingGrant' === strtolower( (string) $reason ) ) {
				return array(
					'disposition' => 'error',
					'error'       => new WP_Error(
						'wp_mcp_ai_classroom_missing_grant',
						__( 'The connected Google account has not granted the Google Classroom permissions (or the grant was revoked). Reconnect the Google Classroom connection and approve all requested permissions.', 'mcp-ai-wpoos' ),
						array(
							'status' => 403,
							'reason' => $reason,
						)
					),
				);
			}

			if ( $this->is_retryable( $status, $reason ) ) {
				return array(
					'disposition' => 'retry',
					'error'       => new WP_Error(
						'wp_mcp_ai_classroom_rate_limited',
						'' !== $message ? $message : __( 'The Google Classroom API is rate limiting this request.', 'mcp-ai-wpoos' ),
						array(
							'status' => $status,
							'reason' => $reason,
						)
					),
				);
			}

			return array(
				'disposition' => 'error',
				'error'       => new WP_Error(
					self::error_code_for( $status, $reason ),
					'' !== $message ? $message : __( 'The Google Classroom API rejected the request.', 'mcp-ai-wpoos' ),
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
		 * Google documents `RESOURCE_EXHAUSTED` (Classroom's quota reason) as
		 * returnable under `403` or `429` and instructs clients to retry with
		 * exponential backoff. A bare `403` without a quota reason is an
		 * authorisation failure and must not be retried.
		 *
		 * @since 1.0.0
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
					array( 'RESOURCE_EXHAUSTED', 'resourceExhausted', 'backendError', 'internalError' ),
					true
				);
			}

			return false;
		}

		/**
		 * Map a status and reason pair to a stable WP_Error code.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $status HTTP status code.
		 * @param string $reason Google error reason.
		 * @return string WP_Error code.
		 */
		protected static function error_code_for( $status, $reason ) {
			$reason_lower = strtolower( (string) $reason );

			if ( false !== strpos( $reason_lower, 'courseworknotfound' ) || false !== strpos( $reason_lower, 'coursenotfound' ) || false !== strpos( $reason_lower, 'registrationnotfound' ) ) {
				return 'wp_mcp_ai_classroom_not_found';
			}

			if ( false !== strpos( $reason_lower, 'failedprecondition' ) ) {
				return 'wp_mcp_ai_classroom_failed_precondition';
			}

			if ( false !== strpos( $reason_lower, 'permissiondenied' ) || false !== strpos( $reason_lower, 'forbidden' ) ) {
				return 'wp_mcp_ai_classroom_forbidden';
			}

			switch ( $status ) {
				case 400:
					return 'wp_mcp_ai_classroom_bad_request';
				case 401:
					return 'wp_mcp_ai_classroom_unauthorized';
				case 403:
					return 'wp_mcp_ai_classroom_forbidden';
				case 404:
					return 'wp_mcp_ai_classroom_not_found';
				case 409:
					return 'wp_mcp_ai_classroom_conflict';
				case 412:
					return 'wp_mcp_ai_classroom_precondition_failed';
				default:
					return 'wp_mcp_ai_classroom_error';
			}
		}

		/**
		 * Extract Google's error reason from a decoded error body.
		 *
		 * @since 1.0.0
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
		 * @since 1.0.0
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
		 * Sleep according to Google's recommended exponential backoff with
		 * jitter, de-synchronised across clients.
		 *
		 * @since 1.0.0
		 *
		 * @param int $attempt Attempt number, starting at 1.
		 * @return void
		 */
		protected function sleep_for_backoff( $attempt ) {
			$base   = min( (int) pow( 2, $attempt ), self::MAX_BACKOFF_SECONDS );
			$jitter = wp_rand( 0, 1000 ) / 1000;
			$wait   = min( $base + $jitter, (float) self::MAX_BACKOFF_SECONDS );

			/**
			 * Filters the backoff duration before a Classroom API retry.
			 *
			 * Returning 0 disables sleeping, which test suites rely on.
			 *
			 * @since 1.0.0
			 *
			 * @param float $wait    Seconds to wait.
			 * @param int   $attempt Attempt number.
			 */
			$wait = (float) apply_filters( 'wp_mcp_ai_google_classroom_retry_backoff', $wait, $attempt );

			if ( $wait <= 0 ) {
				return;
			}

			usleep( (int) round( $wait * 1000000 ) );
		}

		/**
		 * Resolve the access token for this instance.
		 *
		 * @since 1.0.0
		 *
		 * @return string|WP_Error Access token or WP_Error.
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
				return new WP_Error(
					'wp_mcp_ai_classroom_missing_token',
					__( 'No Google access token is available for this Classroom request.', 'mcp-ai-wpoos' ),
					array( 'status' => 401 )
				);
			}

			$this->resolved_token = $token;

			return $this->resolved_token;
		}

		/**
		 * Normalise a query parameter for URL building.
		 *
		 * Booleans must be sent as the literal strings `true` and `false`.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $value Parameter value.
		 * @return string Normalised value.
		 */
		protected function stringify_param( $value ) {
			if ( is_bool( $value ) ) {
				return $value ? 'true' : 'false';
			}

			return (string) $value;
		}

		/**
		 * Whether a WP_Error signals an expired or revoked authorisation.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $error Candidate error.
		 * @return bool
		 */
		public static function is_auth_failure( $error ) {
			if ( ! $error instanceof WP_Error ) {
				return false;
			}

			return in_array(
				$error->get_error_code(),
				array(
					'wp_mcp_ai_classroom_unauthorized',
					'wp_mcp_ai_classroom_missing_grant',
					'wp_mcp_ai_classroom_missing_token',
					'wp_mcp_ai_oauth_invalid_grant',
				),
				true
			);
		}
	}
}
