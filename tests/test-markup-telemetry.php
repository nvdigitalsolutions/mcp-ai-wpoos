<?php
/**
 * Markup subsystem telemetry tests.
 *
 * Verifies that {@see WP_MCP_AI_Markup_Telemetry} subscribes to the four
 * markup lifecycle actions, increments outcome / per-tool / per-mode
 * counters correctly, ignores unknown resolution statuses, and degrades
 * gracefully when a non-Request payload is dispatched (e.g. from a
 * third-party that fires the action with the wrong shape).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-request.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-telemetry.php';

/**
 * Test_Markup_Telemetry test case.
 *
 * @group markup
 * @group telemetry
 */
class Test_Markup_Telemetry extends WP_UnitTestCase {

	/**
	 * Set up: reset persisted counters.
	 *
	 * The recorder itself is the globally registered instance from
	 * includes/markup-init.php (registered at plugins_loaded). Registering a
	 * second instance here would double-count every event.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Markup_Telemetry::reset();
	}

	/**
	 * Tear down: clear option to keep tests isolated.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Markup_Telemetry::reset();
		parent::tearDown();
	}

	/**
	 * Build a synthetic markup request (image / mask) bound to a slug.
	 *
	 * @param string $slug Tool slug.
	 * @param string $mode Mode.
	 * @return WP_MCP_AI_Markup_Request
	 */
	private function build_request( $slug = 'test_tool', $mode = 'mask' ) {
		return new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => $slug,
				'target_type' => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'        => $mode,
				'target'      => array( 'attachment_id' => 1 ),
			)
		);
	}

	/**
	 * Default summary shape exposes every outcome bucket initialised to 0.
	 */
	public function test_default_summary_shape() {
		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertArrayHasKey( 'counts', $summary );
		foreach ( WP_MCP_AI_Markup_Telemetry::outcomes() as $outcome ) {
			$this->assertSame( 0, $summary['counts'][ $outcome ] );
		}
		$this->assertSame( array(), $summary['tools'] );
		$this->assertSame( array(), $summary['modes'] );
	}

	/**
	 * `wp_mcp_ai_markup_request_created` increments `created`.
	 */
	public function test_request_created_increments_counter() {
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'edit_openai_image' ), null );
		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['created'] );
		$this->assertSame( 1, $summary['tools']['edit_openai_image']['created'] );
		$this->assertSame( 1, $summary['modes']['mask']['created'] );
	}

	/**
	 * Resolved + completed bumps the completed bucket.
	 */
	public function test_resolved_completed_bumps_completed() {
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request( 'crop_image', 'crop' ), 'completed' );
		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['completed'] );
		$this->assertSame( 1, $summary['tools']['crop_image']['completed'] );
		$this->assertSame( 1, $summary['modes']['crop']['completed'] );
	}

	/**
	 * Resolved + cancelled / invalid / tool_error all map to their bucket.
	 */
	public function test_resolved_negative_outcomes_map_correctly() {
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request(), 'cancelled' );
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request(), 'invalid' );
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request(), 'tool_error' );
		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['cancelled'] );
		$this->assertSame( 1, $summary['counts']['invalid'] );
		$this->assertSame( 1, $summary['counts']['tool_error'] );
		$this->assertSame( 0, $summary['counts']['completed'] );
	}

	/**
	 * Unknown resolution statuses are silently ignored — nothing recorded.
	 */
	public function test_unknown_resolution_status_is_ignored() {
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request(), 'banana' );
		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		foreach ( $summary['counts'] as $value ) {
			$this->assertSame( 0, $value );
		}
		$this->assertSame( array(), $summary['tools'] );
	}

	/**
	 * Submitted and validated each have their own counter — the
	 * subsequent `resolved/completed` action is what bumps `completed`.
	 */
	public function test_submitted_and_validated_are_separate_buckets() {
		$req = $this->build_request( 'edit_gemini_image', 'region' );
		do_action( 'wp_mcp_ai_markup_submitted', $req );
		do_action( 'wp_mcp_ai_markup_validated', $req, array( 'shapes' => array() ) );
		do_action( 'wp_mcp_ai_markup_resolved', $req, 'completed' );

		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['submitted'] );
		$this->assertSame( 1, $summary['counts']['validated'] );
		$this->assertSame( 1, $summary['counts']['completed'] );
		$this->assertSame( 1, $summary['tools']['edit_gemini_image']['completed'] );
		$this->assertSame( 1, $summary['modes']['region']['completed'] );
	}

	/**
	 * Counters accumulate across multiple requests for the same tool.
	 */
	public function test_per_tool_breakdown_accumulates() {
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'crop_image', 'crop' ), null );
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'crop_image', 'crop' ), null );
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'edit_openai_image', 'mask' ), null );

		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertSame( 3, $summary['counts']['created'] );
		$this->assertSame( 2, $summary['tools']['crop_image']['created'] );
		$this->assertSame( 1, $summary['tools']['edit_openai_image']['created'] );
		$this->assertSame( 2, $summary['modes']['crop']['created'] );
		$this->assertSame( 1, $summary['modes']['mask']['created'] );
	}

	/**
	 * A non-Request payload (third-party misuse) does not blow up and
	 * still bumps the outcome counter (without per-tool / per-mode keys).
	 */
	public function test_non_request_payload_does_not_explode() {
		do_action( 'wp_mcp_ai_markup_request_created', 'not_a_request_object', null );
		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['created'] );
		$this->assertSame( array(), $summary['tools'] );
		$this->assertSame( array(), $summary['modes'] );
	}

	/**
	 * `last_seen` is populated with a recent timestamp on every recorded outcome.
	 */
	public function test_last_seen_timestamp_is_recorded() {
		$before = time() - 1;
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request(), null );
		$after = time() + 1;

		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertArrayHasKey( 'created', $summary['last_seen'] );
		$ts = (int) $summary['last_seen']['created'];
		$this->assertGreaterThanOrEqual( $before, $ts );
		$this->assertLessThanOrEqual( $after, $ts );
	}
}
