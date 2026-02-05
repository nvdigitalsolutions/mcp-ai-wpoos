<?php
/**
 * Test Optimize Performance Slash Command
 *
 * PHPUnit tests for /optimize-perf command functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test optimize-perf command functionality
 */
class Test_Slash_Command_Optimize_Perf extends WP_UnitTestCase {

	/**
	 * Command instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Optimize_Perf
	 */
	private $command;

	/**
	 * Test user ID
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load command class.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-optimize-perf.php';

		$this->command = new WP_MCP_AI_Slash_Command_Optimize_Perf();

		// Create test user with manage_options capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Test command requires manage_options capability
	 */
	public function test_command_requires_capability() {
		// Create user without manage_options capability.
		$editor_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $editor_id );

		$result = $this->command->execute(
			array(),
			array(),
			array( 'user_id' => $editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * Test command executes with valid user
	 */
	public function test_command_executes_for_valid_user() {
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		// Should not be an error.
		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test single phase execution
	 */
	public function test_single_phase_execution() {
		$result = $this->command->execute(
			array(),
			array(
				'phases'  => '1',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Phase 1', $result );
		$this->assertStringContainsString( 'Baseline Measurement', $result );
	}

	/**
	 * Test multiple phases execution
	 */
	public function test_multiple_phases_execution() {
		$result = $this->command->execute(
			array(),
			array(
				'phases'  => '1,2,3',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Phase 1', $result );
		$this->assertStringContainsString( 'Phase 2', $result );
		$this->assertStringContainsString( 'Phase 3', $result );
	}

	/**
	 * Test overall score calculation
	 */
	public function test_overall_score_calculation() {
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Overall Score', $result );
		// Should have a score indicator (emoji).
		$this->assertMatchesRegularExpression( '/Overall Score.*\d+\/100/', $result );
	}

	/**
	 * Test baseline measurement phase
	 */
	public function test_baseline_measurement_phase() {
		$result = $this->command->execute(
			array(),
			array(
				'phases'  => '1',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// Should include load time metric.
		$this->assertStringContainsString( 'Load Time', $result );
	}

	/**
	 * Test database analysis phase
	 */
	public function test_database_analysis_phase() {
		$result = $this->command->execute(
			array(),
			array(
				'phases'  => '2',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Database Analysis', $result );
	}

	/**
	 * Test cache strategy phase
	 */
	public function test_cache_strategy_phase() {
		$result = $this->command->execute(
			array(),
			array(
				'phases'  => '3',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Cache Strategy', $result );
	}

	/**
	 * Test asset optimization phase
	 */
	public function test_asset_optimization_phase() {
		$result = $this->command->execute(
			array(),
			array(
				'phases'  => '4',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Asset Optimization', $result );
	}

	/**
	 * Test plugin audit phase
	 */
	public function test_plugin_audit_phase() {
		$result = $this->command->execute(
			array(),
			array(
				'phases'  => '5',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Plugin Audit', $result );
	}

	/**
	 * Test custom URL parameter
	 */
	public function test_custom_url_parameter() {
		$custom_url = home_url( '/test-page/' );

		$result = $this->command->execute(
			array(),
			array(
				'url'     => $custom_url,
				'phases'  => '1',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test dry-run flag
	 */
	public function test_dry_run_flag() {
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'dry run', $result );
	}

	/**
	 * Test output format
	 */
	public function test_output_format() {
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		// Should be markdown formatted.
		$this->assertStringContainsString( '##', $result );
		$this->assertStringContainsString( '**', $result );
	}

	/**
	 * Cleanup after tests
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
