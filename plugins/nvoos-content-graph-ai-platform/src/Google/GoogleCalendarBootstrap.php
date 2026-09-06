<?php
/**
 * Google Calendar integration bootstrap (Wave E4, sub-cluster 3).
 *
 * Aligned port of the base plugin's `includes/google/google-calendar-init.php`:
 * byte-identical hook surface — the `cron_schedules` filter, the
 * `wp_mcp_ai_google_calendar_sync` / `wp_mcp_ai_google_calendar_renew_channels`
 * cron actions, the push receiver instantiation, and the
 * connection-gated initial scheduling. Self-gating: nothing is scheduled
 * and no channel work happens until at least one Google Calendar
 * connection is authorised.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - The base's global functions become static methods on this class:
 *    `wp_mcp_ai_google_calendar_has_connection()` → `has_connection()`,
 *    `wp_mcp_ai_google_calendar_run_sync()` → `run_sync()`,
 *    `wp_mcp_ai_google_calendar_schedule_renewal()` → `schedule_renewal()`,
 *    and the `init`-hooked `wp_mcp_ai_google_calendar_init()` →
 *    `register()` (hooked on `init` by `Plugin::registerGoogleCalendar()`).
 *  - Standalone-only: the base loader owns the same wiring in monolith
 *    installs; double registration would double-schedule the sync jobs
 *    and double-register the webhook route.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Google
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Google;

/**
 * Registers the Google Calendar scheduling and push-notification hooks.
 *
 * @since 2.1.0
 */
class GoogleCalendarBootstrap {

	/**
	 * Whether any Google Calendar connection is authorised.
	 *
	 * Used to gate scheduling so a site that never configures Calendar pays no
	 * cron cost.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function has_connection() {
		$targets = GoogleCalendarSync::get_sync_targets();

		return ! empty( $targets );
	}

	/**
	 * Register the Google Calendar hooks.
	 *
	 * Standalone-only: `Plugin::registerGoogleCalendar()` hooks this on
	 * `init`, matching the base's `add_action( 'init',
	 * 'wp_mcp_ai_google_calendar_init' )`.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function register(): void {
		// Custom cron interval for the jittered safety-net sync. Registered
		// unconditionally because `cron_schedules` runs before the gate below.
		add_filter(
			'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval is >= 5 minutes; see GoogleCalendarSync::jittered_interval().
			array( GoogleCalendarSync::class, 'register_cron_schedule' )
		);

		// Cron callbacks. Registered unconditionally so an already-scheduled event
		// still fires if a connection is temporarily unreadable.
		add_action(
			GoogleCalendarSync::SYNC_HOOK,
			array( self::class, 'run_sync' ),
			10,
			2
		);
		add_action(
			GoogleCalendarSync::RENEW_HOOK,
			array( GoogleCalendarPush::class, 'renew_expiring_channels' )
		);

		// The notification receiver registers its own `rest_api_init` hook.
		new GoogleCalendarPush();

		// Schedule only once a connection exists.
		if ( is_admin() && self::has_connection() ) {
			GoogleCalendarSync::schedule();
			self::schedule_renewal();
		}
	}

	/**
	 * Cron callback: run a Google Calendar sync.
	 *
	 * The recurring safety-net event passes no arguments and syncs every target;
	 * push-triggered single events pass a specific connection and calendar.
	 *
	 * @since 2.1.0
	 *
	 * @param string $connection_id Optional connection ID.
	 * @param string $calendar_id   Optional calendar identifier.
	 * @return void
	 */
	public static function run_sync( $connection_id = null, $calendar_id = null ): void {
		if ( null === $connection_id && null === $calendar_id ) {
			GoogleCalendarSync::run_scheduled_sync();

			return;
		}

		GoogleCalendarSync::run( (string) $connection_id, (string) $calendar_id );
	}

	/**
	 * Schedule the daily push-channel renewal check.
	 *
	 * Channels expire after at most 7 days with no auto-renewal, so the check runs
	 * daily and renews anything inside its threshold.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function schedule_renewal(): void {
		if ( wp_next_scheduled( GoogleCalendarSync::RENEW_HOOK ) ) {
			return;
		}

		// Only worth scheduling when push can actually be delivered.
		if ( is_wp_error( GoogleCalendarPush::is_push_eligible() ) ) {
			return;
		}

		wp_schedule_event(
			time() + GoogleCalendarSync::site_offset( DAY_IN_SECONDS ),
			'daily',
			GoogleCalendarSync::RENEW_HOOK
		);
	}
}
