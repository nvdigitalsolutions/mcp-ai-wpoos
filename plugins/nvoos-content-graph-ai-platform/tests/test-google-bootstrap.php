<?php
/**
 * Google Calendar bootstrap port tests (Wave E4, sub-cluster 3).
 *
 * Characterization suite for the ported `GoogleCalendarBootstrap`: the
 * init.php hook surface — the `cron_schedules` filter, the sync/renew
 * cron actions, the push receiver instantiation, and the
 * connection-gated initial scheduling — plus the folded-in global
 * function equivalents (`has_connection`, `run_sync`,
 * `schedule_renewal`). Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Google\GoogleCalendarBootstrap;
use NvoosContentGraphAiPlatform\Google\GoogleCalendarSync;
use NvoosContentGraphAiPlatform\Google\GoogleCalendarPush;

/**
 * Google Calendar bootstrap characterization.
 */
class Test_Google_Bootstrap extends \WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );

		// Monolith: the base admin-settings component caches its merged
		// settings statically; reset so cross-test writes are visible.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		GoogleCalendarSync::unschedule();

		parent::tearDown();
	}

	/**
	 * With no connections configured, the connection gate reports false.
	 */
	public function test_has_connection_is_false_without_targets() {
		$this->assertFalse( GoogleCalendarBootstrap::has_connection() );
	}

	/**
	 * The sync-targets filter feeds the connection gate.
	 */
	public function test_has_connection_respects_the_targets_filter() {
		add_filter(
			'wp_mcp_ai_google_calendar_sync_targets',
			function () {
				return array(
					array(
						'connection_id' => '',
						'calendar_id'   => 'primary',
					),
				);
			}
		);

		$this->assertTrue( GoogleCalendarBootstrap::has_connection() );
	}

	/**
	 * `register()` wires the cron-schedules filter under the ported sync class.
	 */
	public function test_register_wires_the_cron_schedules_filter() {
		GoogleCalendarBootstrap::register();

		$this->assertNotFalse(
			has_filter( 'cron_schedules', array( GoogleCalendarSync::class, 'register_cron_schedule' ) )
		);
	}

	/**
	 * `register()` wires the sync and renew cron callbacks.
	 */
	public function test_register_wires_the_cron_callbacks() {
		GoogleCalendarBootstrap::register();

		$this->assertNotFalse(
			has_action( GoogleCalendarSync::SYNC_HOOK, array( GoogleCalendarBootstrap::class, 'run_sync' ) )
		);
		$this->assertNotFalse(
			has_action( GoogleCalendarSync::RENEW_HOOK, array( GoogleCalendarPush::class, 'renew_expiring_channels' ) )
		);
	}

	/**
	 * `register()` instantiates the push receiver, which registers the webhook
	 * route on `rest_api_init`.
	 */
	public function test_register_instantiates_the_push_receiver() {
		GoogleCalendarBootstrap::register();

		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/' . GoogleCalendarPush::REST_NAMESPACE . GoogleCalendarPush::REST_ROUTE,
			$routes
		);
	}

	/**
	 * The argument-free cron callback runs the scheduled sync for every target
	 * without error.
	 */
	public function test_run_sync_without_arguments_runs_scheduled_sync() {
		GoogleCalendarBootstrap::run_sync();

		// No targets configured: the run completes without writing any state.
		$this->assertSame( array(), GoogleCalendarSync::get_all_state() );
	}

	/**
	 * A targeted cron callback resolves credentials and degrades to the
	 * documented error when nothing is configured.
	 */
	public function test_run_sync_with_targets_degrades_cleanly() {
		GoogleCalendarBootstrap::run_sync( 'conn_a', 'primary' );

		// Credential resolution failed before any state write: the target's
		// state stays at defaults rather than accumulating phantom failures.
		$state = GoogleCalendarSync::get_state( 'conn_a', 'primary' );

		$this->assertSame( 0, $state['failure_count'] );
		$this->assertSame( '', $state['sync_token'] );
	}

	/**
	 * On an ineligible site the renewal check is never scheduled, because push
	 * could not be delivered anyway.
	 */
	public function test_schedule_renewal_skips_ineligible_sites() {
		GoogleCalendarBootstrap::schedule_renewal();

		$this->assertFalse( wp_next_scheduled( GoogleCalendarSync::RENEW_HOOK ) );
	}

	/**
	 * The renewal schedule is idempotent when eligibility is forced. The test
	 * harness serves over HTTP, so the home URL is rewritten to HTTPS first —
	 * the eligibility filter cannot override the hard HTTPS gate.
	 */
	public function test_schedule_renewal_is_idempotent_when_eligible() {
		update_option( 'siteurl', 'https://example.org' );
		update_option( 'home', 'https://example.org' );
		add_filter( 'wp_mcp_ai_google_calendar_push_eligible', '__return_true' );

		GoogleCalendarBootstrap::schedule_renewal();
		$first = wp_next_scheduled( GoogleCalendarSync::RENEW_HOOK );

		GoogleCalendarBootstrap::schedule_renewal();
		$second = wp_next_scheduled( GoogleCalendarSync::RENEW_HOOK );

		$this->assertNotFalse( $first );
		$this->assertSame( $first, $second );
	}
}
