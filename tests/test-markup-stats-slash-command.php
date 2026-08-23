<?php
/**
 * /markup-stats slash command tests.
 *
 * Covers empty-state and populated-state rendering, the `--json` raw
 * output, and the `--reset` capability gate.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-request.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-telemetry.php';
require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-markup-stats.php';

/**
 * Test_Markup_Stats_Slash_Command test case.
 *
 * @group markup
 * @group slash_commands
 */
class Test_Markup_Stats_Slash_Command extends WP_UnitTestCase {

	/**
	 * Command under test.
	 *
	 * @var WP_MCP_AI_Slash_Command_Markup_Stats
	 */
	private $command;

	/**
	 * Set up: reset counters, instantiate command.
	 *
	 * Recording is handled by the globally registered recorder from
	 * includes/markup-init.php; a second instance would double-count.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Markup_Telemetry::reset();
		$this->command = new WP_MCP_AI_Slash_Command_Markup_Stats();
	}

	/**
	 * Tear down: clear option.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Markup_Telemetry::reset();
		parent::tearDown();
	}

	/**
	 * Build a synthetic markup request bound to a slug / mode.
	 *
	 * @param string $slug Tool slug.
	 * @param string $mode Markup mode.
	 * @return WP_MCP_AI_Markup_Request
	 */
	private function build_request( $slug, $mode ) {
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
	 * Empty state: the command renders an explanatory message and 0 counts.
	 */
	public function test_empty_state_message() {
		$result = $this->command->execute( array(), array(), array() );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Markup Telemetry', $result['message'] );
		$this->assertStringContainsString( 'No markup events', $result['message'] );
		$this->assertSame( 0, $result['data']['counts']['created'] );
	}

	/**
	 * Populated state: rows are sorted by `created` and rates are computed.
	 */
	public function test_populated_report_lists_top_tools() {
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'edit_openai_image', 'mask' ), null );
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'edit_openai_image', 'mask' ), null );
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request( 'edit_openai_image', 'mask' ), 'completed' );
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'crop_image', 'crop' ), null );
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request( 'crop_image', 'crop' ), 'cancelled' );

		$result = $this->command->execute( array(), array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '`edit_openai_image`', $result['message'] );
		$this->assertStringContainsString( '`crop_image`', $result['message'] );
		$this->assertStringContainsString( 'By tool', $result['message'] );
		$this->assertStringContainsString( 'By mode', $result['message'] );
		$this->assertStringContainsString( '`mask`', $result['message'] );
		$this->assertStringContainsString( '`crop`', $result['message'] );
		$this->assertSame( 3, $result['data']['counts']['created'] );
		$this->assertSame( 1, $result['data']['counts']['completed'] );
		$this->assertSame( 1, $result['data']['counts']['cancelled'] );
	}

	/**
	 * `--json` flag: message is the JSON-encoded summary.
	 */
	public function test_json_flag_returns_raw_summary() {
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request( 'crop_image', 'crop' ), 'completed' );

		$result = $this->command->execute( array(), array( 'json' => true ), array() );
		$this->assertTrue( $result['success'] );
		$decoded = json_decode( $result['message'], true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 1, $decoded['counts']['completed'] );
		$this->assertSame( $result['data'], $decoded );
	}

	/**
	 * `--reset` requires the `manage_options` capability.
	 */
	public function test_reset_requires_manage_options() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'crop_image', 'crop' ), null );

		$result = $this->command->execute( array(), array( 'reset' => true ), array() );
		// The command reports capability failures as WP_Error (the canonical
		// tool envelope), not as a success:false array.
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsString( 'manage_options', $result->get_error_message() );

		// Counter should still be present.
		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['created'] );
	}

	/**
	 * `--reset` succeeds for an admin and clears all counters.
	 */
	public function test_reset_clears_counters_for_admin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'crop_image', 'crop' ), null );
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request( 'crop_image', 'crop' ), 'completed' );

		$result = $this->command->execute( array(), array( 'reset' => true ), array() );
		$this->assertTrue( $result['success'] );

		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		foreach ( $summary['counts'] as $value ) {
			$this->assertSame( 0, $value );
		}
		$this->assertSame( array(), $summary['tools'] );
	}
}
