<?php
/**
 * Google Calendar incremental synchronisation.
 *
 * Implements Google's recommended sync protocol: an initial full sync that
 * yields a `nextSyncToken`, followed by incremental syncs that pass that token
 * back. Push notifications act only as a *trigger* for this class — Google
 * documents that notifications are not fully reliable and Calendar
 * notifications carry no body, so every notification results in a `syncToken`
 * read rather than trusting the delivery itself.
 *
 * Three behaviours here are load-bearing and easy to regress:
 *
 * 1. **Two parameter sets.** `timeMin`, `timeMax`, `q`, `orderBy`, `updatedMin`,
 *    `iCalUID`, and both extended-property filters are legal on a full sync but
 *    return HTTP 400 when combined with `syncToken`. The split is enforced by
 *    `WP_MCP_AI_Google_Calendar_Client::build_sync_params()`.
 * 2. **HTTP 410 is not one thing.** `fullSyncRequired` / `updatedMinTooLongAgo`
 *    require dropping local state; `deleted` on a delete is a success.
 * 3. **`status: cancelled` is not one thing either.** A cancelled *exception* of
 *    a live recurring event carries `recurringEventId` and must be retained for
 *    the parent series' lifetime; any other cancelled event is a true deletion
 *    and its local row must be removed.
 *
 * Scheduling deliberately jitters: Google names "a full sync at midnight" as a
 * common bad practice that exhausts per-minute quota, so the recurring interval
 * varies by +/-25% and the initial offset is seeded from the blog ID.
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

require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-credentials.php';

if ( ! class_exists( 'WP_MCP_AI_Google_Calendar_Sync' ) ) {
	/**
	 * Runs full and incremental Google Calendar synchronisation.
	 */
	class WP_MCP_AI_Google_Calendar_Sync {

		/**
		 * Option storing per-target sync state.
		 *
		 * @var string
		 */
		const STATE_OPTION = 'wp_mcp_ai_google_calendar_sync_state';

		/**
		 * Action Scheduler / cron hook for a sync run.
		 *
		 * @var string
		 */
		const SYNC_HOOK = 'wp_mcp_ai_google_calendar_sync';

		/**
		 * Cron hook for renewing push notification channels.
		 *
		 * @var string
		 */
		const RENEW_HOOK = 'wp_mcp_ai_google_calendar_renew_channels';

		/**
		 * Default safety-net sync interval, in seconds (6 hours).
		 *
		 * @var int
		 */
		const DEFAULT_INTERVAL = 21600;

		/**
		 * How far back an initial full sync reaches, in seconds (365 days).
		 *
		 * @var int
		 */
		const FULL_SYNC_LOOKBACK = 31536000;

		/**
		 * Maximum pages walked in a single sync run.
		 *
		 * @var int
		 */
		const MAX_PAGES = 40;

		/**
		 * Consecutive failures after which a target is marked as needing attention.
		 *
		 * @var int
		 */
		const FAILURE_THRESHOLD = 5;

		/**
		 * Build the state key for a sync target.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Remote Sites connection ID, or empty for the
		 *                              base settings-level connection.
		 * @param string $calendar_id   Calendar identifier.
		 * @return string State key.
		 */
		public static function state_key( $connection_id, $calendar_id ) {
			$connection_id = '' !== $connection_id ? sanitize_key( $connection_id ) : 'settings';

			return $connection_id . '|' . md5( (string) $calendar_id );
		}

		/**
		 * Read all stored sync state.
		 *
		 * @since 1.0.0
		 *
		 * @return array<string,array<string,mixed>>
		 */
		public static function get_all_state() {
			$state = get_option( self::STATE_OPTION, array() );

			return is_array( $state ) ? $state : array();
		}

		/**
		 * Read the sync state for one target.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID or empty string.
		 * @param string $calendar_id   Calendar identifier.
		 * @return array<string,mixed> State, with defaults filled in.
		 */
		public static function get_state( $connection_id, $calendar_id ) {
			$all = self::get_all_state();
			$key = self::state_key( $connection_id, $calendar_id );

			$defaults = array(
				'connection_id'            => $connection_id,
				'calendar_id'              => $calendar_id,
				'sync_token'               => '',
				'last_full_sync_at'        => 0,
				'last_incremental_sync_at' => 0,
				'failure_count'            => 0,
				'last_error'               => '',
				'channel_id'               => '',
				'channel_resource_id'      => '',
				'channel_expiration'       => 0,
			);

			return isset( $all[ $key ] ) && is_array( $all[ $key ] )
				? array_merge( $defaults, $all[ $key ] )
				: $defaults;
		}

		/**
		 * Persist the sync state for one target.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $connection_id Connection ID or empty string.
		 * @param string              $calendar_id   Calendar identifier.
		 * @param array<string,mixed> $state         State to merge in.
		 * @return void
		 */
		public static function save_state( $connection_id, $calendar_id, array $state ) {
			$all = self::get_all_state();
			$key = self::state_key( $connection_id, $calendar_id );

			$existing = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

			$all[ $key ] = array_merge( $existing, $state );

			update_option( self::STATE_OPTION, $all, false );
		}

		/**
		 * Delete the sync state for one target.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID or empty string.
		 * @param string $calendar_id   Calendar identifier.
		 * @return void
		 */
		public static function delete_state( $connection_id, $calendar_id ) {
			$all = self::get_all_state();
			$key = self::state_key( $connection_id, $calendar_id );

			if ( isset( $all[ $key ] ) ) {
				unset( $all[ $key ] );
				update_option( self::STATE_OPTION, $all, false );
			}
		}

		/**
		 * Run a synchronisation pass for one calendar.
		 *
		 * Chooses full or incremental mode from the stored token, walks all pages,
		 * classifies every returned event, and stores the resulting sync token.
		 * A `410 fullSyncRequired` response transparently downgrades to a full
		 * resync within the same call.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $connection_id Connection ID, or empty for base settings.
		 * @param string              $calendar_id   Calendar identifier, or empty for the configured default.
		 * @param array<string,mixed> $options {
		 *     Optional. Run options.
		 *
		 *     @type bool $force_full Force a full sync, discarding any stored token.
		 * }
		 * @return array<string,mixed>|WP_Error Sync report or WP_Error.
		 */
		public static function run( $connection_id = '', $calendar_id = '', array $options = array() ) {
			$credentials = WP_MCP_AI_Google_Calendar_Credentials::resolve( $connection_id );

			if ( is_wp_error( $credentials ) ) {
				return $credentials;
			}

			$scope_check = WP_MCP_AI_Google_Calendar_Credentials::require_scope(
				$credentials,
				WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS_READONLY
			);

			if ( is_wp_error( $scope_check ) ) {
				return $scope_check;
			}

			$calendar_id = WP_MCP_AI_Google_Calendar_Credentials::resolve_calendar_id( $credentials, $calendar_id );

			$client = WP_MCP_AI_Google_Calendar_Credentials::make_client( $credentials );

			if ( is_wp_error( $client ) ) {
				return $client;
			}

			$state      = self::get_state( $connection_id, $calendar_id );
			$force_full = ! empty( $options['force_full'] );
			$token      = $force_full ? '' : (string) $state['sync_token'];
			$mode       = '' !== $token ? 'incremental' : 'full';

			$report = self::execute_pass( $client, $credentials, $connection_id, $calendar_id, $mode, $token );

			// A stale sync token requires dropping local state and starting over.
			// Google returns this on token expiry or an ACL change.
			if ( is_wp_error( $report ) && WP_MCP_AI_Google_Calendar_Client::is_full_sync_required( $report ) ) {
				/**
				 * Fires when Google invalidates a Calendar sync token.
				 *
				 * Listeners should purge their locally mirrored events for this
				 * calendar, because the incremental delta is no longer trustworthy.
				 *
				 * @since 1.0.0
				 *
				 * @param string $connection_id Connection ID or empty string.
				 * @param string $calendar_id   Calendar identifier.
				 */
				do_action( 'wp_mcp_ai_google_calendar_full_sync_required', $connection_id, $calendar_id );

				self::save_state(
					$connection_id,
					$calendar_id,
					array(
						'sync_token' => '',
						'last_error' => $report->get_error_message(),
					)
				);

				$report = self::execute_pass( $client, $credentials, $connection_id, $calendar_id, 'full', '' );
				$mode   = 'full_after_invalidation';
			}

			if ( is_wp_error( $report ) ) {
				self::save_state(
					$connection_id,
					$calendar_id,
					array(
						'failure_count' => (int) $state['failure_count'] + 1,
						'last_error'    => $report->get_error_message(),
					)
				);

				return $report;
			}

			$now         = time();
			$state_patch = array(
				'sync_token'    => $report['next_sync_token'],
				'failure_count' => 0,
				'last_error'    => '',
			);

			if ( 'incremental' === $mode ) {
				$state_patch['last_incremental_sync_at'] = $now;
			} else {
				$state_patch['last_full_sync_at']        = $now;
				$state_patch['last_incremental_sync_at'] = $now;
			}

			self::save_state( $connection_id, $calendar_id, $state_patch );

			$report['mode']          = $mode;
			$report['connection_id'] = $connection_id;
			$report['calendar_id']   = $calendar_id;

			/**
			 * Fires after a successful Google Calendar sync pass.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $report        Sync report including `upserted`, `deleted`, `suppressed`.
			 * @param string $connection_id Connection ID or empty string.
			 * @param string $calendar_id   Calendar identifier.
			 */
			do_action( 'wp_mcp_ai_google_calendar_synced', $report, $connection_id, $calendar_id );

			return $report;
		}

		/**
		 * Execute a single paginated sync pass.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_MCP_AI_Google_Calendar_Client $client        API client.
		 * @param array<string,mixed>              $credentials   Resolved credentials.
		 * @param string                           $connection_id Connection ID or empty string.
		 * @param string                           $calendar_id   Calendar identifier.
		 * @param string                           $mode          `full` or `incremental`.
		 * @param string                           $sync_token    Sync token for incremental mode.
		 * @return array<string,mixed>|WP_Error Report or WP_Error.
		 */
		protected static function execute_pass( $client, array $credentials, $connection_id, $calendar_id, $mode, $sync_token ) {
			$timezone = ! empty( $credentials['timezone'] )
				? (string) $credentials['timezone']
				: WP_MCP_AI_Google_Calendar_Credentials::default_timezone();

			$base = array(
				'maxResults'   => WP_MCP_AI_Google_Calendar_Client::DEFAULT_MAX_RESULTS,
				'singleEvents' => 'true',
				'showDeleted'  => 'true',
				'timeZone'     => $timezone,
			);

			// Date narrowing is legal on a full sync only. Passing timeMin alongside
			// a syncToken returns HTTP 400, so build_sync_params() strips it.
			if ( 'full' === $mode ) {
				$base['timeMin'] = gmdate( 'c', time() - self::FULL_SYNC_LOOKBACK );
			}

			/**
			 * Filters the base Google Calendar sync parameters.
			 *
			 * Every request in a sync sequence must use an identical parameter set,
			 * so this filter must return the same values for both modes apart from
			 * the mode-specific keys handled by `build_sync_params()`.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $base          Base query parameters.
			 * @param string $mode          `full` or `incremental`.
			 * @param string $connection_id Connection ID or empty string.
			 * @param string $calendar_id   Calendar identifier.
			 */
			$base = apply_filters( 'wp_mcp_ai_google_calendar_sync_params', $base, $mode, $connection_id, $calendar_id );

			$params = WP_MCP_AI_Google_Calendar_Client::build_sync_params( $mode, $base, $sync_token );

			$paginated = WP_MCP_AI_Google_Calendar_Client::paginate(
				static function ( $page_params ) use ( $client, $calendar_id ) {
					return $client->list_events( $calendar_id, $page_params );
				},
				$params,
				self::MAX_PAGES
			);

			if ( is_wp_error( $paginated ) ) {
				return $paginated;
			}

			$classified = self::classify_events( $paginated['items'] );

			return array(
				'next_sync_token' => $paginated['next_sync_token'],
				'total'           => count( $paginated['items'] ),
				'upserted'        => $classified['upserted'],
				'deleted'         => $classified['deleted'],
				'suppressed'      => $classified['suppressed'],
			);
		}

		/**
		 * Split a batch of events into upserts, deletions, and suppressed instances.
		 *
		 * `status: "cancelled"` has two distinct meanings:
		 *
		 * - With `recurringEventId` set, it is a cancelled *exception* of a still-live
		 *   recurring event. Google states clients should store these for the lifetime
		 *   of the parent series, so the local row must be kept and that single
		 *   occurrence hidden. Deleting it makes the occurrence reappear on the next
		 *   sync.
		 * - Without `recurringEventId`, it is a true deletion and only `id` is
		 *   guaranteed to be populated. The local row must be removed.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int,array<string,mixed>> $items Event resources.
		 * @return array{upserted:array<int,array<string,mixed>>,deleted:array<int,string>,suppressed:array<int,array<string,string>>}
		 */
		public static function classify_events( array $items ) {
			$upserted   = array();
			$deleted    = array();
			$suppressed = array();

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) ) {
					continue;
				}

				$status              = isset( $item['status'] ) ? (string) $item['status'] : '';
				$recurring_parent_id = isset( $item['recurringEventId'] ) ? (string) $item['recurringEventId'] : '';

				if ( 'cancelled' !== $status ) {
					$upserted[] = $item;
					continue;
				}

				if ( '' !== $recurring_parent_id ) {
					// Cancelled exception: retain, suppress this occurrence only.
					// `originalStartTime` — not `start` — identifies the occurrence,
					// because a rescheduled instance keeps its original identity.
					$suppressed[] = array(
						'id'                  => (string) $item['id'],
						'recurring_event_id'  => $recurring_parent_id,
						'original_start_time' => isset( $item['originalStartTime'] )
							? wp_json_encode( $item['originalStartTime'] )
							: '',
					);
					continue;
				}

				$deleted[] = (string) $item['id'];
			}

			return array(
				'upserted'   => $upserted,
				'deleted'    => $deleted,
				'suppressed' => $suppressed,
			);
		}

		// Scheduling.

		/**
		 * Compute a jittered sync interval.
		 *
		 * Google explicitly names midnight full syncs and fixed intervals as
		 * quota-exhausting anti-patterns, and recommends varying the interval by
		 * roughly +/-25% so a fleet of clients never fires in lockstep.
		 *
		 * @since 1.0.0
		 *
		 * @param int $base_interval Base interval in seconds.
		 * @return int Jittered interval in seconds.
		 */
		public static function jittered_interval( $base_interval = self::DEFAULT_INTERVAL ) {
			$base_interval = absint( $base_interval );

			if ( $base_interval < 300 ) {
				$base_interval = self::DEFAULT_INTERVAL;
			}

			$spread = (int) round( $base_interval * 0.25 );

			return max( 300, $base_interval + wp_rand( -$spread, $spread ) );
		}

		/**
		 * Compute a per-site initial offset so sites do not sync simultaneously.
		 *
		 * @since 1.0.0
		 *
		 * @param int $window Window over which to spread starts, in seconds.
		 * @return int Offset in seconds.
		 */
		public static function site_offset( $window = 3600 ) {
			$window = max( 1, absint( $window ) );

			return (int) ( hexdec( substr( md5( (string) get_current_blog_id() . '|' . home_url() ), 0, 6 ) ) % $window );
		}

		/**
		 * Schedule the recurring safety-net sync.
		 *
		 * Push notifications are a trigger, not a transport - Google documents that
		 * a small percentage of messages are dropped under normal conditions - so a
		 * low-frequency poll always runs alongside them.
		 *
		 * The interval itself is owned by `register_cron_schedule()`, which the cron
		 * system consults on every run; this method only decides *when* the first
		 * run happens.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public static function schedule() {
			if ( wp_next_scheduled( self::SYNC_HOOK ) ) {
				return;
			}

			wp_schedule_event(
				time() + self::site_offset(),
				'wp_mcp_ai_google_calendar_sync_interval',
				self::SYNC_HOOK
			);
		}

		/**
		 * Register the custom cron schedule used by the safety-net sync.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,array<string,mixed>> $schedules Existing schedules.
		 * @return array<string,array<string,mixed>> Schedules including the sync interval.
		 */
		public static function register_cron_schedule( $schedules ) {
			$schedules = is_array( $schedules ) ? $schedules : array();

			/**
			 * Filters the base Google Calendar safety-net sync interval.
			 *
			 * The returned value is jittered by +/-25% before use.
			 *
			 * @since 1.0.0
			 *
			 * @param int $interval Base interval in seconds.
			 */
			$base = (int) apply_filters( 'wp_mcp_ai_google_calendar_sync_interval', self::DEFAULT_INTERVAL );

			$schedules['wp_mcp_ai_google_calendar_sync_interval'] = array(
				'interval' => self::jittered_interval( $base ),
				'display'  => __( 'NV oOS Google Calendar sync interval', 'mcp-ai-wpoos' ),
			);

			return $schedules;
		}

		/**
		 * Clear all scheduled Google Calendar jobs.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public static function unschedule() {
			wp_unschedule_hook( self::SYNC_HOOK );
			wp_unschedule_hook( self::RENEW_HOOK );
		}

		/**
		 * Cron callback: sync every configured target.
		 *
		 * Individual target failures are non-fatal so one broken connection cannot
		 * stall the rest.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public static function run_scheduled_sync() {
			foreach ( self::get_sync_targets() as $target ) {
				$result = self::run( $target['connection_id'], $target['calendar_id'] );

				if ( is_wp_error( $result ) && function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log(
						sprintf(
							'Google Calendar sync failed for %1$s / %2$s: %3$s',
							'' !== $target['connection_id'] ? $target['connection_id'] : 'settings',
							$target['calendar_id'],
							$result->get_error_message()
						),
						'error'
					);
				}
			}
		}

		/**
		 * Enumerate every configured sync target.
		 *
		 * Includes the base settings-level connection plus every enabled Pro
		 * Remote Sites Google Calendar connection.
		 *
		 * @since 1.0.0
		 *
		 * @return array<int,array{connection_id:string,calendar_id:string}>
		 */
		public static function get_sync_targets() {
			$targets = array();

			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$settings = WP_MCP_AI_Admin_Settings::get_settings();

				if ( ! empty( $settings['google_calendar_refresh_token'] ) ) {
					$targets[] = array(
						'connection_id' => '',
						'calendar_id'   => ! empty( $settings['google_calendar_default_calendar_id'] )
							? (string) $settings['google_calendar_default_calendar_id']
							: 'primary',
					);
				}
			}

			if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

				if ( is_array( $connections ) ) {
					foreach ( $connections as $connection_id => $connection ) {
						if ( ! is_array( $connection ) ) {
							continue;
						}

						$type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';

						if ( WP_MCP_AI_Google_Calendar_Credentials::CONNECTION_TYPE !== $type ) {
							continue;
						}

						if ( empty( $connection['enabled'] ) || empty( $connection['refresh_token'] ) ) {
							continue;
						}

						$targets[] = array(
							'connection_id' => sanitize_key( $connection_id ),
							'calendar_id'   => ! empty( $connection['calendar_id'] )
								? (string) $connection['calendar_id']
								: 'primary',
						);
					}
				}
			}

			/**
			 * Filters the list of Google Calendar sync targets.
			 *
			 * @since 1.0.0
			 *
			 * @param array $targets Array of `connection_id` / `calendar_id` pairs.
			 */
			return apply_filters( 'wp_mcp_ai_google_calendar_sync_targets', $targets );
		}
	}
}
