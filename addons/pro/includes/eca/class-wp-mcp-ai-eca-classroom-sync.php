<?php
/**
 * Google Classroom ECA sync engine.
 *
 * Owns the background work of the Classroom integration: the nightly
 * course/roster reconcile for sync-enabled connections, and the delta
 * application for Pub/Sub push notifications. Tools stay interactive;
 * cron and webhook paths land here so neither depends on a user context.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classroom ↔ ECA sync engine.
 */
class WP_MCP_AI_ECA_Classroom_Sync {

	/**
	 * Cron hook for the nightly reconcile pass.
	 *
	 * @var string
	 */
	const RECONCILE_HOOK = 'wp_mcp_ai_eca_classroom_reconcile';

	/**
	 * Cron interval slug for the jittered reconcile.
	 *
	 * @var string
	 */
	const INTERVAL_SLUG = 'wp_mcp_ai_eca_classroom_sync_interval';

	/**
	 * Minimum interval in seconds (never run the reconcile more often).
	 *
	 * @var int
	 */
	const MIN_INTERVAL = 6 * HOUR_IN_SECONDS;

	/**
	 * Default interval in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_INTERVAL = DAY_IN_SECONDS;

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval is >= 6 hours and jittered; see jittered_interval().
		add_action( self::RECONCILE_HOOK, array( __CLASS__, 'run_reconcile' ) );
		add_action( WP_MCP_AI_Google_Classroom_Push::NOTIFICATION_HOOK, array( __CLASS__, 'handle_notification_event' ) );

		if ( is_admin() && self::has_sync_targets() ) {
			self::schedule_reconcile();
		}
	}

	/**
	 * Register the jittered reconcile cron interval.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,array<string,mixed>> $schedules Registered schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_cron_schedule( $schedules ) {
		$schedules[ self::INTERVAL_SLUG ] = array(
			'interval' => self::jittered_interval(),
			'display'  => __( 'NV oOS Classroom Sync (jittered)', 'mcp-ai-wpoos-pro' ),
		);

		return $schedules;
	}

	/**
	 * The jittered reconcile interval, never below the minimum.
	 *
	 * @since 1.0.0
	 *
	 * @return int Interval in seconds.
	 */
	public static function jittered_interval() {
		$configured = absint( apply_filters( 'wp_mcp_ai_eca_classroom_sync_interval', self::DEFAULT_INTERVAL ) );

		if ( $configured < self::MIN_INTERVAL ) {
			$configured = self::DEFAULT_INTERVAL;
		}

		// +/- 15% jitter so a fleet of school sites never reconciles in lockstep.
		$jitter = wp_rand( -15, 15 ) / 100;

		return max( self::MIN_INTERVAL, (int) round( $configured * ( 1 + $jitter ) ) );
	}

	/**
	 * Whether any Classroom connection wants periodic sync.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function has_sync_targets() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return false;
		}

		foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $connection ) {
			if ( ! is_array( $connection ) || 'google_classroom' !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
				continue;
			}

			if ( ! empty( $connection['classroom_sync_enabled'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Schedule the daily reconcile once.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function schedule_reconcile(): void {
		if ( wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + self::site_offset( HOUR_IN_SECONDS ), self::INTERVAL_SLUG, self::RECONCILE_HOOK );
	}

	/**
	 * Offset a base duration by a site-stable pseudo-random amount so
	 * installations do not run their reconcile at the same wall-clock time.
	 *
	 * @since 1.0.0
	 *
	 * @param int $base Base offset in seconds.
	 * @return int Offset in seconds.
	 */
	public static function site_offset( $base ) {
		$site_key = (string) get_option( 'siteurl', '' );
		$hash     = 0;

		foreach ( str_split( $site_key ) as $char ) {
			$hash = ( ( $hash * 31 ) + ord( $char ) ) % 997;
		}

		return absint( $base ) + ( $hash % 600 );
	}

	/**
	 * Cron callback: reconcile every sync-enabled Classroom connection.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function run_reconcile(): void {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return;
		}

		foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $connection ) {
			if ( ! is_array( $connection ) || 'google_classroom' !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
				continue;
			}

			if ( empty( $connection['classroom_sync_enabled'] ) || empty( $connection['enabled'] ) ) {
				continue;
			}

			$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

			if ( '' === $connection_id ) {
				continue;
			}

			WP_MCP_AI_ECA_Classroom_Helper::require_foundation();

			$credentials = WP_MCP_AI_Google_Classroom_Credentials::resolve( $connection_id );

			if ( is_wp_error( $credentials ) ) {
				continue;
			}

			$client = WP_MCP_AI_Google_Classroom_Credentials::make_client( $credentials );

			if ( is_wp_error( $client ) ) {
				continue;
			}

			self::sync_courses( $client );
			self::sync_linked_rosters( $client, $credentials );
		}
	}

	/**
	 * Sync course definitions into linked ECA records.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_MCP_AI_Google_Classroom_Client $client Configured client.
	 * @return int Number of courses processed.
	 */
	public static function sync_courses( $client ) {
		$pages = WP_MCP_AI_Google_Classroom_Client::paginate(
			static function ( $params ) use ( $client ) {
				return $client->list_courses( $params );
			},
			array(
				'pageSize'     => 100,
				'courseStates' => array( 'ACTIVE' ),
			)
		);

		if ( is_wp_error( $pages ) ) {
			return 0;
		}

		$count = 0;

		foreach ( isset( $pages['items'] ) ? $pages['items'] : array() as $course ) {
			if ( ! is_array( $course ) || empty( $course['id'] ) ) {
				continue;
			}

			$course_id = sanitize_text_field( (string) $course['id'] );
			$eca_id    = WP_MCP_AI_ECA_Classroom_Helper::find_eca_by_google_course( $course_id );

			if ( 0 === $eca_id ) {
				continue;
			}

			update_post_meta( $eca_id, '_eca_classroom_sync', 'roster-in' );

			if ( isset( $course['section'] ) && '' !== sanitize_text_field( (string) $course['section'] ) ) {
				update_post_meta( $eca_id, '_eca_section', sanitize_text_field( (string) $course['section'] ) );
			}

			++$count;
		}

		return $count;
	}

	/**
	 * Sync the rosters of every ECA linked to a Classroom course.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_MCP_AI_Google_Classroom_Client $client      Configured client.
	 * @param array<string,mixed>               $credentials Resolved credentials.
	 * @return int Number of students processed.
	 */
	public static function sync_linked_rosters( $client, array $credentials ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded background pass over linked ECAs.
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded background pass.
					array(
						'key'     => '_eca_google_course_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$count = 0;

		foreach ( $query->posts as $eca_id ) {
			$course_id = (string) get_post_meta( absint( $eca_id ), '_eca_google_course_id', true );

			if ( '' === $course_id ) {
				continue;
			}

			$pages = WP_MCP_AI_Google_Classroom_Client::paginate(
				static function ( $params ) use ( $client, $course_id ) {
					return $client->list_students( $course_id, $params );
				},
				array( 'pageSize' => 100 )
			);

			if ( is_wp_error( $pages ) ) {
				continue;
			}

			foreach ( isset( $pages['items'] ) ? $pages['items'] : array() as $member ) {
				if ( is_array( $member ) ) {
					$count += self::upsert_student_from_member( $member, false );
				}
			}
		}

		$state = array(
			'last_reconcile'     => time(),
			'students_processed' => $count,
		);
		WP_MCP_AI_ECA_Classroom_Helper::set_sync_state( isset( $credentials['connection_id'] ) ? (string) $credentials['connection_id'] : 'reconcile', $state );

		return $count;
	}

	/**
	 * Upsert one Classroom roster member into a student record.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $member      Classroom `courses.students` member.
	 * @param bool                $force_mis_win Always skip MIS-owned records.
	 * @return int 1 when a record was touched, 0 otherwise.
	 */
	public static function upsert_student_from_member( array $member, $force_mis_win = true ) {
		$google_id = isset( $member['userId'] ) ? sanitize_text_field( (string) $member['userId'] ) : '';
		$profile   = isset( $member['profile'] ) && is_array( $member['profile'] ) ? $member['profile'] : array();
		$full_name = isset( $profile['name']['fullName'] ) ? sanitize_text_field( (string) $profile['name']['fullName'] ) : '';
		$email     = isset( $profile['emailAddress'] ) ? sanitize_email( (string) $profile['emailAddress'] ) : '';

		if ( '' === $google_id && '' === $email ) {
			return 0;
		}

		$post_id = '' !== $google_id
			? WP_MCP_AI_ECA_Classroom_Helper::find_student_by_google_id( $google_id )
			: 0;

		if ( 0 === $post_id && '' !== $email ) {
			$post_id = WP_MCP_AI_ECA_Classroom_Helper::find_student_by_email( $email );
		}

		if ( 0 === $post_id ) {
			$name_parts = WP_MCP_AI_ECA_Classroom_Helper::split_full_name( $full_name );

			$post_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_student',
					'post_status' => 'publish',
					'post_title'  => '' !== $full_name ? $full_name : ( '' !== $email ? $email : $google_id ),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return 0;
			}

			update_post_meta( $post_id, '_student_first_name', $name_parts['first'] );
			update_post_meta( $post_id, '_student_last_name', $name_parts['last'] );
			update_post_meta( $post_id, '_student_email', $email );
			update_post_meta( $post_id, '_student_google_id', $google_id );
			update_post_meta( $post_id, '_student_eca_enrollments', array() );

			return 1;
		}

		if ( '' !== $google_id ) {
			update_post_meta( $post_id, '_student_google_id', $google_id );
		}

		if ( $force_mis_win && '' !== (string) get_post_meta( $post_id, '_student_isams_id', true ) ) {
			return 0;
		}

		return 1;
	}

	/**
	 * Handle a verified push notification (scheduled from the webhook).
	 *
	 * @since 1.0.0
	 *
	 * @param array $event Notification event with connection_id, collection,
	 *                     event_type, and resource_id keys.
	 * @return void
	 */
	public static function handle_notification_event( $event ): void {
		if ( ! is_array( $event ) || empty( $event['collection'] ) ) {
			return;
		}

		// Note: sanitize_key() would strip the dots from collection names like
		// `courses.students` — use sanitize_text_field() instead.
		$collection    = sanitize_text_field( (string) $event['collection'] );
		$event_type    = isset( $event['event_type'] ) ? sanitize_key( (string) $event['event_type'] ) : '';
		$resource      = isset( $event['resource_id'] ) && is_array( $event['resource_id'] ) ? $event['resource_id'] : array();
		$connection_id = isset( $event['connection_id'] ) ? sanitize_key( (string) $event['connection_id'] ) : '';

		if ( 'courses.courseWork' === $collection || 'courses.courseWork.studentSubmissions' === $collection ) {
			/**
			 * Fires when Classroom coursework or a submission changes.
			 *
			 * The ECA toolkit does not model coursework, so consumers (for
			 * example the assistant notification rules) act on this event.
			 *
			 * @since 1.0.0
			 *
			 * @param string $connection_id Connection ID.
			 * @param string $collection    Changed collection.
			 * @param string $event_type    Change type.
			 * @param array  $resource_id   Identifier map.
			 */
			do_action( 'wp_mcp_ai_eca_classroom_coursework_changed', $connection_id, $collection, $event_type, $resource );

			return;
		}

		if ( 'courses.students' !== $collection || '' === $connection_id ) {
			return;
		}

		$course_id = isset( $resource['courseId'] ) ? sanitize_text_field( (string) $resource['courseId'] ) : '';
		$user_id   = isset( $resource['userId'] ) ? sanitize_text_field( (string) $resource['userId'] ) : '';

		if ( '' === $course_id || '' === $user_id ) {
			return;
		}

		WP_MCP_AI_ECA_Classroom_Helper::require_foundation();

		$credentials = WP_MCP_AI_Google_Classroom_Credentials::resolve( $connection_id );

		if ( is_wp_error( $credentials ) ) {
			return;
		}

		$client = WP_MCP_AI_Google_Classroom_Credentials::make_client( $credentials );

		if ( is_wp_error( $client ) ) {
			return;
		}

		$eca_id = WP_MCP_AI_ECA_Classroom_Helper::find_eca_by_google_course( $course_id );

		if ( 'DELETED' === $event_type ) {
			$student_id = '' !== $user_id ? WP_MCP_AI_ECA_Classroom_Helper::find_student_by_google_id( $user_id ) : 0;

			if ( $student_id > 0 && $eca_id > 0 && class_exists( 'WP_MCP_AI_ECA_Enrollments_DB' ) ) {
				$enrollments = WP_MCP_AI_ECA_Enrollments_DB::get_enrollments( $eca_id, 'school', 0 );

				foreach ( $enrollments as $enrollment ) {
					if ( isset( $enrollment['student_id'] ) && absint( $enrollment['student_id'] ) === $student_id ) {
						WP_MCP_AI_ECA_Enrollments_DB::withdraw( absint( $enrollment['id'] ) );
					}
				}
			}

			return;
		}

		// CREATED and UPDATED both resolve to "ensure the member exists".
		$member = $client->get_student( $course_id, $user_id );

		if ( is_wp_error( $member ) ) {
			return;
		}

		self::upsert_student_from_member( $member, false );

		$student_id = '' !== $user_id ? WP_MCP_AI_ECA_Classroom_Helper::find_student_by_google_id( $user_id ) : 0;

		// Enrol the student into the linked ECA when the roster gains them.
		if ( $student_id > 0 && $eca_id > 0 && class_exists( 'WP_MCP_AI_ECA_Enrollments_DB' ) ) {
			if ( ! WP_MCP_AI_ECA_Enrollments_DB::is_enrolled( $student_id, $eca_id, 'school', 0 ) ) {
				WP_MCP_AI_ECA_Enrollments_DB::enroll( $eca_id, $student_id, 'school', 0 );
			}
		}
	}
}
