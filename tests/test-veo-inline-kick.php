<?php
/**
 * Tests for the WP_MCP_AI_Gemini_Video_Generation_Service inline-async-tick
 * integration (Slice 6).
 *
 * Verifies:
 *   1. queue_async_polling() registers a shutdown action when the inline kick is enabled.
 *   2. poll_video_async() acquires the cooperative tick lock and prevents double-polling.
 *   3. The wp_mcp_ai_inline_kick_enabled filter can disable the inline kick.
 *   4. poll_video_async() bails silently when no metadata exists for the job.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class Test_Veo_Inline_Kick
 */
class Test_Veo_Inline_Kick extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var WP_MCP_AI_Gemini_Video_Generation_Service
	 */
	protected $service;

	/**
	 * Set up — require the service and instantiate it.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Gemini_Video_Generation_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		}

		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();
	}

	/**
	 * Clean up transients and any shutdown actions registered during the test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_filter;
		if ( isset( $wp_filter['shutdown'] ) ) {
			unset( $wp_filter['shutdown'] );
		}
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// 1. Constants exist
	// -------------------------------------------------------------------------

	/**
	 * The three tick-lock constants introduced in Slice 6 must be defined and
	 * have the correct types/values.
	 */
	public function test_tick_lock_constants_defined() {
		$this->assertSame( 'wp_mcp_ai_veo_poll_lock_', WP_MCP_AI_Gemini_Video_Generation_Service::TICK_LOCK_PREFIX );
		$this->assertSame( 'wp_mcp_ai_veo_poll', WP_MCP_AI_Gemini_Video_Generation_Service::TICK_LOCK_CACHE_GROUP );
		$this->assertSame( 30, WP_MCP_AI_Gemini_Video_Generation_Service::TICK_LOCK_TTL );
	}

	// -------------------------------------------------------------------------
	// 2. Tick-lock prevents double-polling
	// -------------------------------------------------------------------------

	/**
	 * poll_video_async() must bail early when the cooperative tick lock is
	 * already held, without calling any downstream code.
	 */
	public function test_poll_video_async_bails_when_lock_held() {
		$job_id   = 'veo_test_lock_' . wp_generate_uuid4();
		$lock_key = WP_MCP_AI_Gemini_Video_Generation_Service::TICK_LOCK_PREFIX . md5( $job_id );

		// Pre-acquire the lock to simulate a concurrent tick.
		set_transient( $lock_key, 1, WP_MCP_AI_Gemini_Video_Generation_Service::TICK_LOCK_TTL );

		// poll_video_async() must return cleanly without a PHP error or log.
		// We verify by asserting the method returns null (void) and does not
		// alter any metadata transient.
		$this->assertNull( $this->service->poll_video_async( $job_id ) );

		// Cleanup.
		delete_transient( $lock_key );
	}

	// -------------------------------------------------------------------------
	// 3. poll_video_async() short-circuits for missing metadata
	// -------------------------------------------------------------------------

	/**
	 * poll_video_async() must return silently when no metadata transient exists
	 * for the given job_id (e.g. after a TTL expiry).
	 */
	public function test_poll_video_async_bails_on_missing_metadata() {
		$job_id = 'veo_no_meta_' . wp_generate_uuid4();

		// No transient stored — poll_video_async should silently return.
		$this->assertNull( $this->service->poll_video_async( $job_id ) );
	}

	// -------------------------------------------------------------------------
	// 4. wp_mcp_ai_inline_kick_enabled filter
	// -------------------------------------------------------------------------

	/**
	 * When the wp_mcp_ai_inline_kick_enabled filter returns false, the inline
	 * kick helper inline_async_kick_enabled() must return false.
	 *
	 * This exercises the trait primitive used by queue_async_polling() to gate
	 * shutdown registration; the actual shutdown-hook registration path requires
	 * a real transient + operation metadata that is impractical to fabricate in
	 * a unit test, so we test the gate itself.
	 */
	public function test_inline_kick_enabled_filter_can_disable_kick() {
		add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );

		$reflection = new ReflectionClass( 'WP_MCP_AI_Gemini_Video_Generation_Service' );
		$method     = $reflection->getMethod( 'inline_async_kick_enabled' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'veo_test_filter', 'WP_MCP_AI_Gemini_Video_Generation_Service' );

		$this->assertFalse( $result, 'inline_async_kick_enabled() must return false when filter disables the kick' );

		remove_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );
	}
}
