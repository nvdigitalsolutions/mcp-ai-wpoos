<?php
/**
 * Google Classroom push-notification receiver and registration lifecycle.
 *
 * Classroom push notifications are delivered through Cloud Pub/Sub rather than
 * the channel model Calendar uses. This class owns:
 *
 * - The webhook REST route (`mcp-ai/v1/google-classroom/webhook`) that Pub/Sub
 *   pushes to. Authenticated by a shared secret embedded in the subscription
 *   URL (`?token=`), verified with `hash_equals()`. This is a state-changing
 *   route, so the permission callback never uses `__return_true`.
 * - The registration store (option `wp_mcp_ai_google_classroom_registrations`)
 *   with the weekly-renewal lifecycle: registrations expire after ~7 days and
 *   Google performs no auto-renewal, so a daily cron re-issues the identical
 *   `registrations.create()` call for anything inside its renewal window.
 * - Ack-fast processing: the handler decodes the notification, acknowledges
 *   with 200 immediately, and defers the API read + delta application to a
 *   one-off `wp_mcp_ai_google_classroom_notification` cron event. Never make
 *   API calls inline in the webhook request.
 *
 * Domain-wide delegation is not supported for registrations (Google returns
 * `@MissingGrant`), so every registration is created under the connection's
 * own per-user OAuth grant.
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

if ( ! class_exists( 'WP_MCP_AI_Google_Classroom_Push' ) ) {
	/**
	 * Google Classroom push notification receiver.
	 */
	class WP_MCP_AI_Google_Classroom_Push {

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
		const REST_ROUTE = '/google-classroom/webhook';

		/**
		 * Option storing live registration records.
		 *
		 * @var string
		 */
		const REGISTRATIONS_OPTION = 'wp_mcp_ai_google_classroom_registrations';

		/**
		 * Option storing the Pub/Sub push endpoint shared secret.
		 *
		 * @var string
		 */
		const SECRET_OPTION = 'wp_mcp_ai_google_classroom_push_secret';

		/**
		 * Option storing the Cloud Pub/Sub topic name.
		 *
		 * @var string
		 */
		const TOPIC_OPTION = 'wp_mcp_ai_google_classroom_push_topic';

		/**
		 * Default registration lifetime, in seconds. Registrations expire after
		 * ~7 days per the official docs.
		 *
		 * @var int
		 */
		const REGISTRATION_TTL = 604800;

		/**
		 * Renew a registration once its remaining life falls below this.
		 *
		 * @var int
		 */
		const RENEW_THRESHOLD = 86400;

		/**
		 * Cron hook for the daily renewal pass.
		 *
		 * @var string
		 */
		const RENEW_HOOK = 'wp_mcp_ai_google_classroom_renew_registrations';

		/**
		 * Cron hook fired when a verified notification needs processing.
		 *
		 * @var string
		 */
		const NOTIFICATION_HOOK = 'wp_mcp_ai_google_classroom_notification';

		/**
		 * Register hooks.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
			add_action( self::RENEW_HOOK, array( __CLASS__, 'renew_expiring_registrations' ) );
		}

		/**
		 * Register the notification receiver route.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function register_routes() {
			register_rest_route(
				self::REST_NAMESPACE,
				self::REST_ROUTE,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_notification' ),
					'permission_callback' => array( $this, 'verify_notification' ),
				)
			);
		}

		/**
		 * Verify an inbound Pub/Sub push.
		 *
		 * The subscription endpoint URL carries `?token=<site secret>`;
		 * Pub/Sub echoes it back on every push. Compared with `hash_equals()`
		 * so a missing or wrong token is rejected before any processing.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Request $request Inbound request.
		 * @return true|WP_Error True when the notification is authentic.
		 */
		public function verify_notification( $request ) {
			$provided = (string) $request->get_param( 'token' );
			$expected = self::get_push_secret();

			if ( '' === $provided || '' === $expected || ! hash_equals( $expected, $provided ) ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_unauthenticated',
					__( 'Invalid Google Classroom push token.', 'mcp-ai-wpoos' ),
					array( 'status' => 401 )
				);
			}

			return true;
		}

		/**
		 * Handle a verified notification.
		 *
		 * Decodes the Pub/Sub envelope, dedupes on message ID, and defers the
		 * real work to a one-off cron event. Always acknowledges with 200 so
		 * Pub/Sub does not redeliver a message we cannot process.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Request $request Inbound request.
		 * @return WP_REST_Response Always a 200 acknowledgement.
		 */
		public function handle_notification( $request ) {
			$body   = $request->get_json_params();
			$body   = is_array( $body ) ? $body : array();
			$parsed = self::decode_pubsub_message( $body );

			if ( is_wp_error( $parsed ) ) {
				// Malformed payloads are acknowledged so Pub/Sub stops
				// retrying; the safety-net periodic sync covers the gap.
				return new WP_REST_Response( array( 'acknowledged' => true ), 200 );
			}

			$message_id = isset( $parsed['message_id'] ) ? (string) $parsed['message_id'] : '';

			if ( '' !== $message_id ) {
				$seen_key = 'wp_mcp_ai_gcr_msgid_' . md5( $message_id );

				if ( get_transient( $seen_key ) ) {
					return new WP_REST_Response( array( 'duplicate' => true ), 200 );
				}

				set_transient( $seen_key, time(), self::REGISTRATION_TTL + DAY_IN_SECONDS );
			}

			$payload = isset( $parsed['payload'] ) && is_array( $parsed['payload'] ) ? $parsed['payload'] : array();

			// Resolve the owning connection from the registration attribute,
			// falling back to payload inspection for feeds delivered without it.
			$connection_id = self::connection_for_notification( $parsed, $payload );

			$event = array(
				'connection_id' => $connection_id,
				// Note: sanitize_key() would strip the dots from collection names
				// like `courses.students` — use sanitize_text_field() instead.
				'collection'    => isset( $payload['collection'] ) ? sanitize_text_field( (string) $payload['collection'] ) : '',
				'event_type'    => isset( $payload['eventType'] ) ? sanitize_key( (string) $payload['eventType'] ) : '',
				'resource_id'   => isset( $payload['resourceId'] ) && is_array( $payload['resourceId'] ) ? $payload['resourceId'] : array(),
			);

			if ( '' === $event['collection'] || '' === $event['event_type'] ) {
				return new WP_REST_Response( array( 'acknowledged' => true ), 200 );
			}

			if ( ! wp_next_scheduled( self::NOTIFICATION_HOOK, array( $event ) ) ) {
				wp_schedule_single_event( time() + 5, self::NOTIFICATION_HOOK, array( $event ) );
			}

			return new WP_REST_Response( array( 'acknowledged' => true ), 200 );
		}

		/**
		 * Decode a Cloud Pub/Sub push envelope into message metadata + payload.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $body Raw JSON body.
		 * @return array<string,mixed>|WP_Error {
		 *     @type string $message_id      Pub/Sub message ID.
		 *     @type string $registration_id Registration ID attribute, when present.
		 *     @type array  $payload         Decoded Classroom notification.
		 * }
		 */
		public static function decode_pubsub_message( array $body ) {
			$message = isset( $body['message'] ) && is_array( $body['message'] ) ? $body['message'] : array();

			if ( empty( $message ) ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_bad_envelope',
					__( 'The push message had no Pub/Sub envelope.', 'mcp-ai-wpoos' )
				);
			}

			$data = isset( $message['data'] ) ? (string) $message['data'] : '';

			if ( '' === $data ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_empty_payload',
					__( 'The push message carried no payload.', 'mcp-ai-wpoos' )
				);
			}

			// Pub/Sub encodes `data` as base64; accept raw JSON as a fallback
			// for transports that deliver it unencoded.
			$decoded = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Pub/Sub payload decoding is the API contract.

			$payload = json_decode( false === $decoded || '' === $decoded ? $data : $decoded, true );

			if ( ! is_array( $payload ) ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_bad_payload',
					__( 'The push message payload was not valid JSON.', 'mcp-ai-wpoos' )
				);
			}

			$attributes = isset( $message['attributes'] ) && is_array( $message['attributes'] ) ? $message['attributes'] : array();

			return array(
				'message_id'      => isset( $message['messageId'] ) ? (string) $message['messageId'] : '',
				'registration_id' => isset( $attributes['registrationId'] ) ? (string) $attributes['registrationId'] : '',
				'payload'         => $payload,
			);
		}

		/**
		 * Resolve the connection a notification belongs to.
		 *
		 * Prefers the registration attribute; falls back to scanning stored
		 * registrations for the course in the payload.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $parsed  Decoded envelope.
		 * @param array<string,mixed> $payload Classroom notification.
		 * @return string Connection ID, or an empty string.
		 */
		protected static function connection_for_notification( array $parsed, array $payload ) {
			$registration_id = isset( $parsed['registration_id'] ) ? (string) $parsed['registration_id'] : '';

			if ( '' !== $registration_id ) {
				foreach ( self::get_registrations() as $record ) {
					if ( isset( $record['registration_id'] ) && (string) $record['registration_id'] === $registration_id ) {
						return isset( $record['connection_id'] ) ? (string) $record['connection_id'] : '';
					}
				}
			}

			$course_id = isset( $payload['resourceId']['courseId'] ) ? (string) $payload['resourceId']['courseId'] : '';

			if ( '' !== $course_id ) {
				foreach ( self::get_registrations() as $record ) {
					if ( isset( $record['course_id'] ) && (string) $record['course_id'] === $course_id ) {
						return isset( $record['connection_id'] ) ? (string) $record['connection_id'] : '';
					}
				}
			}

			return '';
		}

		/**
		 * Whether this site can receive Google push notifications.
		 *
		 * Google requires HTTPS with a valid CA-signed certificate, so loopback
		 * and private-network hosts can never work.
		 *
		 * @since 1.0.0
		 *
		 * @return true|WP_Error True when eligible, WP_Error describing why not.
		 */
		public static function is_push_eligible() {
			$url  = rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
			$host = wp_parse_url( $url, PHP_URL_HOST );

			if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_requires_https',
					__( 'Google Classroom push notifications require the site to be served over HTTPS with a valid certificate. Use the periodic sync instead.', 'mcp-ai-wpoos' )
				);
			}

			if ( ! $host || 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_requires_public_host',
					__( 'Google Classroom push notifications require a publicly resolvable domain name. Use the periodic sync instead.', 'mcp-ai-wpoos' )
				);
			}

			$resolved = gethostbyname( $host );

			if ( $resolved && $resolved !== $host && ! filter_var( $resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_private_host',
					__( 'This site resolves to a private network address, which Google cannot reach. Use the periodic sync instead.', 'mcp-ai-wpoos' )
				);
			}

			/**
			 * Filters whether Google Classroom push notifications are eligible.
			 *
			 * Return a WP_Error to disable push with an explanation.
			 *
			 * @since 1.0.0
			 *
			 * @param true|WP_Error $eligible Eligibility result.
			 * @param string        $url      Notification receiver URL.
			 */
			return apply_filters( 'wp_mcp_ai_google_classroom_push_eligible', true, $url );
		}

		// Secret & topic.

		/**
		 * Get (or lazily create) the push endpoint shared secret.
		 *
		 * @since 1.0.0
		 *
		 * @return string Shared secret.
		 */
		public static function get_push_secret() {
			$secret = get_option( self::SECRET_OPTION, '' );

			if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
				$secret = wp_generate_password( 48, false, false );
				update_option( self::SECRET_OPTION, $secret, false );
			}

			return $secret;
		}

		/**
		 * Get the configured Cloud Pub/Sub topic name.
		 *
		 * @since 1.0.0
		 *
		 * @return string Topic name in `projects/{project}/topics/{topic}` form.
		 */
		public static function get_topic_name() {
			$topic = get_option( self::TOPIC_OPTION, '' );
			$topic = is_string( $topic ) ? trim( $topic ) : '';

			/**
			 * Filters the Cloud Pub/Sub topic used for Classroom notifications.
			 *
			 * @since 1.0.0
			 *
			 * @param string $topic Topic name.
			 */
			return (string) apply_filters( 'wp_mcp_ai_google_classroom_push_topic', $topic );
		}

		/**
		 * Build the subscription push endpoint URL for the topic owner.
		 *
		 * @since 1.0.0
		 *
		 * @return string Endpoint URL with the shared secret embedded.
		 */
		public static function get_push_endpoint_url() {
			return add_query_arg(
				array( 'token' => self::get_push_secret() ),
				rest_url( self::REST_NAMESPACE . self::REST_ROUTE )
			);
		}

		// Registration lifecycle.

		/**
		 * Read all stored registration records.
		 *
		 * @since 1.0.0
		 *
		 * @return array<string,array<string,mixed>> Key => record.
		 */
		public static function get_registrations() {
			$registrations = get_option( self::REGISTRATIONS_OPTION, array() );

			return is_array( $registrations ) ? $registrations : array();
		}

		/**
		 * Build the store key for a registration.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID.
		 * @param string $course_id     Course ID, or empty for domain feeds.
		 * @param string $feed_type     Feed type slug.
		 * @return string Store key.
		 */
		protected static function registration_key( $connection_id, $course_id, $feed_type ) {
			return sanitize_key( $connection_id ) . '|' . sanitize_key( $course_id ) . '|' . sanitize_key( $feed_type );
		}

		/**
		 * Register push notifications for a course feed.
		 *
		 * Requires the `classroom.push-notifications` scope plus the scopes
		 * needed to view the feed data, and a configured Pub/Sub topic that
		 * grants `classroom-notifications@system.gserviceaccount.com` publish
		 * rights.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID.
		 * @param string $course_id     Course ID.
		 * @param string $feed_type     One of `COURSE_ROSTER_CHANGES` or `COURSE_WORK_CHANGES`.
		 * @return array<string,mixed>|WP_Error Registration record or WP_Error.
		 */
		public static function register_for_course( $connection_id, $course_id, $feed_type ) {
			$eligible = self::is_push_eligible();

			if ( is_wp_error( $eligible ) ) {
				return $eligible;
			}

			$topic = self::get_topic_name();

			if ( '' === $topic ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_topic_missing',
					__( 'No Cloud Pub/Sub topic is configured. Set it under Pro → NV oOS Pro → Remote Sites → Google Classroom, then grant classroom-notifications@system.gserviceaccount.com publish rights on the topic.', 'mcp-ai-wpoos' )
				);
			}

			$credentials = WP_MCP_AI_Google_Classroom_Credentials::resolve( $connection_id );

			if ( is_wp_error( $credentials ) ) {
				return $credentials;
			}

			$scope_check = WP_MCP_AI_Google_Classroom_Credentials::require_scope(
				$credentials,
				WP_MCP_AI_Google_Classroom_Scopes::SCOPE_PUSH_NOTIFICATIONS
			);

			if ( is_wp_error( $scope_check ) ) {
				return $scope_check;
			}

			$client = WP_MCP_AI_Google_Classroom_Credentials::make_client( $credentials );

			if ( is_wp_error( $client ) ) {
				return $client;
			}

			$feed = array(
				'feedType' => $feed_type,
			);

			if ( 'COURSE_ROSTER_CHANGES' === $feed_type ) {
				$feed['courseRosterChangesInfo'] = array( 'courseId' => $course_id );
			} elseif ( 'COURSE_WORK_CHANGES' === $feed_type ) {
				$feed['courseWorkChangesInfo'] = array( 'courseId' => $course_id );
			} else {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_bad_feed',
					__( 'Unknown Classroom notification feed type.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$response = $client->create_registration(
				array(
					'feed'             => $feed,
					'cloudPubsubTopic' => array( 'topicName' => $topic ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$registration_id = isset( $response['registrationId'] ) ? (string) $response['registrationId'] : '';

			if ( '' === $registration_id ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_no_registration_id',
					__( 'Google accepted the registration but returned no identifier.', 'mcp-ai-wpoos' )
				);
			}

			// `expiryTime` is an RFC3339 timestamp, not a Unix epoch.
			$expiry = 0;

			if ( isset( $response['expiryTime'] ) ) {
				$parsed_time = strtotime( (string) $response['expiryTime'] );
				$expiry      = false === $parsed_time ? 0 : $parsed_time;
			}

			if ( $expiry <= 0 ) {
				$expiry = time() + self::REGISTRATION_TTL;
			}

			$record = array(
				'key'             => self::registration_key( $connection_id, $course_id, $feed_type ),
				'registration_id' => $registration_id,
				'connection_id'   => sanitize_key( $connection_id ),
				'course_id'       => sanitize_text_field( $course_id ),
				'feed_type'       => $feed_type,
				'topic'           => $topic,
				'expiry_time'     => $expiry,
				'created_at'      => time(),
			);

			self::save_registration( $record );

			return $record;
		}

		/**
		 * Persist a registration record.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $record Registration record.
		 * @return void
		 */
		protected static function save_registration( array $record ) {
			$registrations = self::get_registrations();
			$key           = isset( $record['key'] ) ? (string) $record['key'] : '';

			if ( '' !== $key ) {
				$registrations[ $key ] = $record;
				update_option( self::REGISTRATIONS_OPTION, $registrations, false );
			}
		}

		/**
		 * Remove a registration record.
		 *
		 * @since 1.0.0
		 *
		 * @param string $key Store key.
		 * @return void
		 */
		protected static function forget_registration( $key ) {
			$registrations = self::get_registrations();

			if ( isset( $registrations[ $key ] ) ) {
				unset( $registrations[ $key ] );
				update_option( self::REGISTRATIONS_OPTION, $registrations, false );
			}
		}

		/**
		 * Unregister notifications for a course feed.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID.
		 * @param string $course_id     Course ID.
		 * @param string $feed_type     Feed type slug.
		 * @return true|WP_Error True on success, WP_Error otherwise.
		 */
		public static function unregister_for_course( $connection_id, $course_id, $feed_type ) {
			$key     = self::registration_key( $connection_id, $course_id, $feed_type );
			$records = self::get_registrations();

			if ( empty( $records[ $key ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_push_registration_not_found',
					__( 'No Google Classroom push registration matches that course and feed.', 'mcp-ai-wpoos' )
				);
			}

			$record = $records[ $key ];

			$credentials = WP_MCP_AI_Google_Classroom_Credentials::resolve(
				isset( $record['connection_id'] ) ? (string) $record['connection_id'] : ''
			);

			if ( ! is_wp_error( $credentials ) ) {
				$client = WP_MCP_AI_Google_Classroom_Credentials::make_client( $credentials );

				if ( ! is_wp_error( $client ) ) {
					$client->delete_registration( (string) $record['registration_id'] );
				}
			}

			// Drop the local record regardless, so a dead credential cannot
			// leave an unreferenceable registration behind.
			self::forget_registration( $key );

			return true;
		}

		/**
		 * Unregister every registration belonging to a connection.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID.
		 * @return int Number of registrations removed.
		 */
		public static function unregister_all_for_connection( $connection_id ) {
			$connection_id = sanitize_key( $connection_id );
			$removed       = 0;

			foreach ( self::get_registrations() as $record ) {
				if ( isset( $record['connection_id'] ) && (string) $record['connection_id'] === $connection_id ) {
					self::unregister_for_course(
						$connection_id,
						isset( $record['course_id'] ) ? (string) $record['course_id'] : '',
						isset( $record['feed_type'] ) ? (string) $record['feed_type'] : ''
					);
					++$removed;
				}
			}

			return $removed;
		}

		/**
		 * Cron callback: renew registrations approaching expiry.
		 *
		 * Google performs no auto-renewal; the identical `registrations.create()`
		 * call extends a registration. The old record is replaced only after the
		 * replacement is confirmed, so a failed renewal never leaves the course
		 * unwatched.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public static function renew_expiring_registrations() {
			$now = time();

			foreach ( self::get_registrations() as $record ) {
				$expiry = isset( $record['expiry_time'] ) ? (int) $record['expiry_time'] : 0;

				if ( $expiry > 0 && ( $expiry - $now ) > self::RENEW_THRESHOLD ) {
					continue;
				}

				$replacement = self::register_for_course(
					isset( $record['connection_id'] ) ? (string) $record['connection_id'] : '',
					isset( $record['course_id'] ) ? (string) $record['course_id'] : '',
					isset( $record['feed_type'] ) ? (string) $record['feed_type'] : ''
				);

				if ( is_wp_error( $replacement ) ) {
					continue;
				}

				// The identical create replaces the Google-side registration;
				// the old local record is already superseded under the same key.
				$old_key = isset( $record['key'] ) ? (string) $record['key'] : '';

				if ( '' !== $old_key && $old_key !== $replacement['key'] ) {
					self::forget_registration( $old_key );
				}
			}
		}
	}
}
