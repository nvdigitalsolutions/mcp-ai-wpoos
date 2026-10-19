<?php
/**
 * Schedule Anything — Schedule Presets Registry
 *
 * Static catalogue of installable schedule presets (Proposal 081, D5).
 * The SPA reads presets through GET /mcp-ai-pro/v1/presets and installs
 * them through POST /mcp-ai-pro/v1/presets/{id}/install, which maps a
 * preset's schedule_data onto WP_MCP_AI_Pro_Schedule_Manager::create_schedule().
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.2.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preset registry.
 *
 * @since 1.2.8
 */
class WP_MCP_AI_SA_Schedule_Presets {

	/**
	 * Install-idempotency option: install_key => schedule_id.
	 *
	 * @since 1.2.8
	 *
	 * @var string
	 */
	const INSTALLS_OPTION = 'wp_mcp_ai_sa_installs';

	/**
	 * The preset catalogue.
	 *
	 * The `schedule_data` key carries the machine configuration handed to
	 * WP_MCP_AI_Pro_Schedule_Manager::create_schedule(). `schedule` is the
	 * registered WP cron interval slug (hourly, twicedaily, daily, weekly,
	 * or the manager's custom wp_mcp_ai_monthly); the top-level `schedule`
	 * field is the human-readable display string.
	 *
	 * @since 1.2.8
	 *
	 * @return array<string, array>
	 */
	public static function get_catalogue() {
		$hook    = 'wp_mcp_ai_pro_schedule_exec';
		$presets = array(
			'cal-reminder-followup'    => array(
				'name'          => 'Daily Appointment Reminder & Follow-up',
				'description'   => 'Sends appointment reminders and post-appointment follow-ups every morning.',
				'toolkit'       => 'calendar-booking',
				'category'      => 'bookings',
				'icon'          => '📅',
				'schedule_type' => 'task',
				'schedule'      => 'Daily at 8:00 AM',
				'tags'          => array( 'reminders', 'follow-up' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'cal-reminder-followup' ),
					'schedule'      => 'daily',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'cal-availability-report'  => array(
				'name'          => 'Weekly Availability Digest',
				'description'   => 'Weekly report of open slots, no-shows, and booking trends.',
				'toolkit'       => 'calendar-booking',
				'category'      => 'bookings',
				'icon'          => '🗓️',
				'schedule_type' => 'task',
				'schedule'      => 'Weekly on Monday',
				'tags'          => array( 'reporting', 'availability' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'cal-availability-report' ),
					'schedule'      => 'weekly',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'crm-lead-nurture'         => array(
				'name'          => 'Daily Lead Nurture Sequence',
				'description'   => 'Moves leads through the nurture sequence and flags stalled deals.',
				'toolkit'       => 'crm',
				'category'      => 'sales',
				'icon'          => '🤝',
				'schedule_type' => 'task',
				'schedule'      => 'Daily at 9:00 AM',
				'tags'          => array( 'leads', 'nurture' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'crm-lead-nurture' ),
					'schedule'      => 'daily',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'crm-contact-hygiene'      => array(
				'name'          => 'Weekly Contact Hygiene Sweep',
				'description'   => 'Merges duplicate contacts and refreshes stale company data weekly.',
				'toolkit'       => 'crm',
				'category'      => 'sales',
				'icon'          => '🧹',
				'schedule_type' => 'task',
				'schedule'      => 'Weekly on Friday',
				'tags'          => array( 'contacts', 'hygiene' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'crm-contact-hygiene' ),
					'schedule'      => 'weekly',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'ecom-abandoned-cart'      => array(
				'name'          => 'Abandoned Cart Recovery',
				'description'   => 'Daily recovery emails for carts abandoned in the last 24 hours.',
				'toolkit'       => 'ecommerce',
				'category'      => 'store-ops',
				'icon'          => '🛒',
				'schedule_type' => 'task',
				'schedule'      => 'Daily at 10:00 AM',
				'tags'          => array( 'cart', 'recovery' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'ecom-abandoned-cart' ),
					'schedule'      => 'daily',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'ecom-inventory-alert'     => array(
				'name'          => 'Low-Stock Inventory Alert',
				'description'   => 'Twice-daily scan that flags products below their reorder threshold.',
				'toolkit'       => 'ecommerce',
				'category'      => 'store-ops',
				'icon'          => '📦',
				'schedule_type' => 'task',
				'schedule'      => 'Twice daily',
				'tags'          => array( 'inventory', 'alerts' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'ecom-inventory-alert' ),
					'schedule'      => 'twicedaily',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'social-morning-post'      => array(
				'name'          => 'Morning Content Calendar Broadcast',
				'description'   => 'Publishes the day\'s scheduled content across connected channels.',
				'toolkit'       => 'social-media',
				'category'      => 'social',
				'icon'          => '📣',
				'schedule_type' => 'task',
				'schedule'      => 'Daily at 7:00 AM',
				'tags'          => array( 'publishing', 'calendar' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'social-morning-post' ),
					'schedule'      => 'daily',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'social-weekly-report'     => array(
				'name'          => 'Weekly Social Performance Digest',
				'description'   => 'Engagement, follower, and top-post summary every Monday.',
				'toolkit'       => 'social-media',
				'category'      => 'social',
				'icon'          => '📊',
				'schedule_type' => 'task',
				'schedule'      => 'Weekly on Monday',
				'tags'          => array( 'reporting', 'analytics' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'social-weekly-report' ),
					'schedule'      => 'weekly',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'analytics-traffic-digest' => array(
				'name'          => 'Weekly Traffic & SEO Digest',
				'description'   => 'Traffic, rankings, and Core Web Vitals summary with recommendations.',
				'toolkit'       => 'analytics',
				'category'      => 'reporting',
				'icon'          => '📈',
				'schedule_type' => 'task',
				'schedule'      => 'Weekly on Monday',
				'tags'          => array( 'seo', 'traffic' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'analytics-traffic-digest' ),
					'schedule'      => 'weekly',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'analytics-performance'    => array(
				'name'          => 'Monthly Performance Digest',
				'description'   => 'Month-end performance digest across traffic, sales, and campaigns.',
				'toolkit'       => 'analytics',
				'category'      => 'reporting',
				'icon'          => '🧾',
				'schedule_type' => 'task',
				'schedule'      => 'Monthly (30 days)',
				'tags'          => array( 'reporting', 'monthly' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'analytics-performance' ),
					'schedule'      => 'wp_mcp_ai_monthly',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'docs-daily-brief'         => array(
				'name'          => 'Daily Ops Brief (PDF)',
				'description'   => 'Generates a one-page daily operations brief PDF each morning.',
				'toolkit'       => 'document-generation',
				'category'      => 'documents',
				'icon'          => '📄',
				'schedule_type' => 'task',
				'schedule'      => 'Daily at 6:00 AM',
				'tags'          => array( 'pdf', 'briefing' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'docs-daily-brief' ),
					'schedule'      => 'daily',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
			'docs-weekly-report'       => array(
				'name'          => 'Weekly Campaign Report (PDF)',
				'description'   => 'Weekly PDF campaign performance report with charts.',
				'toolkit'       => 'document-generation',
				'category'      => 'documents',
				'icon'          => '📑',
				'schedule_type' => 'task',
				'schedule'      => 'Weekly on Monday',
				'tags'          => array( 'pdf', 'campaign' ),
				'schedule_data' => array(
					'schedule_type' => 'task',
					'hook'          => $hook,
					'args'          => array( 'preset' => 'docs-weekly-report' ),
					'schedule'      => 'weekly',
					'timestamp'     => null,
					'enabled'       => false,
				),
			),
		);

		/**
		 * Filter the schedule preset catalogue.
		 *
		 * @since 1.2.8
		 *
		 * @param array $presets Presets keyed by id.
		 */
		return apply_filters( 'wp_mcp_ai_sa_presets', $presets );
	}

	/**
	 * Query presets with search/filter/pagination.
	 *
	 * @since 1.2.8
	 *
	 * @param array $args Optional. search (string), category (string), toolkit
	 *                    (string), page (int), per_page (int).
	 * @return array{total: int, presets: array<int, array>}
	 */
	public static function get_presets( array $args = array() ) {
		$catalogue = self::get_catalogue();
		$search    = isset( $args['search'] ) ? strtolower( sanitize_text_field( $args['search'] ) ) : '';
		$category  = isset( $args['category'] ) ? sanitize_key( $args['category'] ) : '';
		$toolkit   = isset( $args['toolkit'] ) ? sanitize_key( $args['toolkit'] ) : '';
		$page      = max( 1, isset( $args['page'] ) ? absint( $args['page'] ) : 1 );
		$per_page  = min( 50, max( 1, isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20 ) );

		$matched = array();
		foreach ( $catalogue as $id => $preset ) {
			if ( $search ) {
				$haystack = strtolower( $id . ' ' . $preset['name'] . ' ' . $preset['description'] . ' ' . implode( ' ', $preset['tags'] ) );
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}
			if ( $category && $preset['category'] !== $category ) {
				continue;
			}
			if ( $toolkit && $preset['toolkit'] !== $toolkit ) {
				continue;
			}
			$matched[ $id ] = array_merge( array( 'id' => $id ), $preset );
		}

		$total  = count( $matched );
		$offset = ( $page - 1 ) * $per_page;
		$paged  = array_slice( array_values( $matched ), $offset, $per_page );

		return array(
			'total'   => $total,
			'presets' => $paged,
		);
	}

	/**
	 * Fetch a single preset by id.
	 *
	 * @since 1.2.8
	 *
	 * @param string $id Preset id.
	 * @return array|null Preset (with 'id') or null when unknown.
	 */
	public static function get_preset( $id ) {
		$catalogue = self::get_catalogue();

		if ( ! isset( $catalogue[ $id ] ) ) {
			return null;
		}

		return array_merge( array( 'id' => $id ), $catalogue[ $id ] );
	}

	/**
	 * Build the deterministic idempotency key for an install request.
	 *
	 * @since 1.2.8
	 *
	 * @param string $id        Preset id.
	 * @param array  $overrides Raw overrides payload.
	 * @return string Install key.
	 */
	public static function install_key( $id, array $overrides ) {
		$hash = substr( hash( 'sha256', wp_json_encode( $overrides ) ? wp_json_encode( $overrides ) : (string) microtime( true ) ), 0, 16 );

		return 'preset:' . $id . ':h' . $hash;
	}

	/**
	 * Look up a previously created schedule id for an install key.
	 *
	 * @since 1.2.8
	 *
	 * @param string $key Install key.
	 * @return string|null Schedule id or null.
	 */
	public static function get_installed_schedule_id( $key ) {
		$installs = get_option( self::INSTALLS_OPTION, array() );

		return isset( $installs[ $key ] ) ? (string) $installs[ $key ] : null;
	}

	/**
	 * Record an install key → schedule id mapping.
	 *
	 * @since 1.2.8
	 *
	 * @param string $key         Install key.
	 * @param string $schedule_id Created schedule id.
	 * @return void
	 */
	public static function record_install( $key, $schedule_id ) {
		$installs         = get_option( self::INSTALLS_OPTION, array() );
		$installs[ $key ] = $schedule_id;
		$installs         = array_slice( $installs, -100, null, true ); // Cap the map.
		update_option( self::INSTALLS_OPTION, $installs );
	}

	/**
	 * Install a catalogue preset as a schedule (server-side provisioning).
	 *
	 * Mirrors the REST install flow (lookup, idempotency, create, record)
	 * for callers that provision tenants without a user request, e.g. the
	 * Schedule Anything Platform addon's tier seeding.
	 *
	 * @since 1.2.9
	 *
	 * @param string $id      Catalogue preset id.
	 * @param int    $user_id Schedule author user id.
	 * @return string|WP_Error Schedule id on success, WP_Error on failure.
	 */
	public static function install_catalogue_preset( $id, $user_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return new WP_Error(
				'sa_schedule_manager_missing',
				__( 'The Schedule Manager is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$preset = self::get_preset( $id );
		if ( null === $preset ) {
			return new WP_Error(
				'sa_preset_not_found',
				__( 'Preset not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$key = self::install_key( $id, array() );

		// Idempotent replay: re-provisioning returns the original schedule.
		$existing_id = self::get_installed_schedule_id( $key );
		if ( $existing_id ) {
			$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
			if ( isset( $schedules[ $existing_id ] ) ) {
				return $existing_id;
			}
		}

		$payload     = self::build_install_payload( $preset, array() );
		$schedule_id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $payload, absint( $user_id ) );

		if ( is_wp_error( $schedule_id ) ) {
			return $schedule_id;
		}

		self::record_install( $key, $schedule_id );

		return $schedule_id;
	}

	/**
	 * Seconds for a registered cron interval slug.
	 *
	 * @since 1.2.8
	 *
	 * @param string $schedule Cron interval slug.
	 * @return int Seconds; 0 when unknown.
	 */
	public static function schedule_seconds( $schedule ) {
		$map = array(
			'hourly'            => HOUR_IN_SECONDS,
			'twicedaily'        => 12 * HOUR_IN_SECONDS,
			'daily'             => DAY_IN_SECONDS,
			'weekly'            => WEEK_IN_SECONDS,
			'wp_mcp_ai_monthly' => 30 * DAY_IN_SECONDS,
		);

		return isset( $map[ $schedule ] ) ? $map[ $schedule ] : 0;
	}

	/**
	 * Whitelisted override keys per install request (mass-assignment guard).
	 *
	 * @since 1.2.8
	 *
	 * @return array<int, string>
	 */
	public static function override_whitelist() {
		/**
		 * Filter the override keys accepted by the preset install endpoint.
		 *
		 * @since 1.2.8
		 *
		 * @param array $keys Default: array( 'name', 'description', 'schedule_type', 'hook', 'args', 'schedule', 'timestamp', 'enabled', 'tags' ).
		 */
		return apply_filters(
			'wp_mcp_ai_sa_preset_override_keys',
			array( 'name', 'description', 'schedule_type', 'hook', 'args', 'schedule', 'timestamp', 'enabled', 'tags' )
		);
	}

	/**
	 * Sanitize an overrides payload against the whitelist.
	 *
	 * @since 1.2.8
	 *
	 * @param array $overrides Raw overrides.
	 * @return array Sanitized, whitelisted overrides.
	 */
	public static function sanitize_overrides( array $overrides ) {
		$allowed = self::override_whitelist();
		$clean   = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $overrides ) ) {
				continue;
			}

			$value = $overrides[ $key ];

			switch ( $key ) {
				case 'name':
				case 'description':
				case 'schedule_type':
				case 'hook':
				case 'schedule':
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'timestamp':
					$clean[ $key ] = absint( $value );
					break;
				case 'enabled':
					$clean[ $key ] = (bool) $value;
					break;
				case 'args':
					$clean[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) : array();
					break;
				case 'tags':
					$clean[ $key ] = is_array( $value ) ? array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) ) ) : array();
					break;
			}
		}

		return $clean;
	}

	/**
	 * Build the create_schedule payload from a preset + sanitized overrides.
	 *
	 * @since 1.2.8
	 *
	 * @param array $preset    Preset record.
	 * @param array $overrides Sanitized overrides.
	 * @return array Manager payload.
	 */
	public static function build_install_payload( array $preset, array $overrides ) {
		$data = array_merge( $preset['schedule_data'], $overrides );

		// Name the schedule after the preset unless overridden.
		if ( ! isset( $data['name'] ) ) {
			$data['name'] = $preset['name'];
		}

		// Default the first run to one interval from now when unspecified.
		if ( empty( $data['timestamp'] ) ) {
			$seconds           = isset( $data['schedule'] ) ? self::schedule_seconds( $data['schedule'] ) : DAY_IN_SECONDS;
			$data['timestamp'] = time() + $seconds;
		}

		return $data;
	}
}
