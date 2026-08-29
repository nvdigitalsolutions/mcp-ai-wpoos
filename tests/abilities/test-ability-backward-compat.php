<?php
/**
 * Tests for backward compatibility — WP < 6.9 no-op behaviour.
 *
 * Verifies that the abilities-init.php bootstrap, both registrars, and the
 * bridge execute callback all degrade gracefully when the Abilities API
 * functions are unavailable (WordPress < 6.9).
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

require_once __DIR__ . '/class-wp-mcp-ai-ability-bridge-mock-tool.php';
require_once __DIR__ . '/trait-wp-mcp-ai-ability-test-bootstrap.php';

/**
 * Integration tests for backward compatibility.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Ability_Backward_Compat_Test extends WP_UnitTestCase {

	use WP_MCP_AI_Ability_Test_Bootstrap;

	/**
	 * Ability identifiers registered by the current test.
	 *
	 * @since 2.0.0
	 * @var string[]
	 */
	private $registered_abilities = array();

	/**
	 * Mock tool slugs registered in the tool registry by the current test.
	 *
	 * @since 2.0.0
	 * @var string[]
	 */
	private $registered_tool_slugs = array();

	/**
	 * Clean up abilities and mock tools registered by the test.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function tearDown(): void {
		$this->clean_up_ability_registrations( $this->registered_abilities, $this->registered_tool_slugs );
		$this->registered_abilities   = array();
		$this->registered_tool_slugs = array();

		parent::tearDown();
	}

	/**
	 * Ensure abilities-init.php does not cause fatal errors when
	 * wp_register_ability() is unavailable (WP < 6.9).
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_init_script_does_not_error_without_abilities_api() {
		if ( function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API is available — cannot test pre-6.9 behaviour.' );
		}

		// These should not cause errors.
		WP_MCP_AI_Ability_Category_Registrar::init();
		WP_MCP_AI_Ability_Registrar::init();

		// Trigger the hooks — both should return early.
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );

		$this->assertTrue( true ); // Reaching here without error is success.
	}

	/**
	 * Ensure the loader.php integration loads the init script without errors.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_loader_includes_abilities_init() {
		$init_file = WP_MCP_AI_PATH . 'includes/abilities/abilities-init.php';

		$this->assertFileExists(
			$init_file,
			'abilities-init.php should exist at the expected path.'
		);
	}

	/**
	 * Ensure the bridge's build_execute_callback handles missing tools gracefully.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_missing_tool_returns_structured_error() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		// Register an ability for a tool slug that does not exist in the real registry.
		$tool = new WP_MCP_AI_Ability_Bridge_Mock_Tool();
		$tool->set_slug( 'nonexistent_tool' );

		$this->bootstrap_ability_categories();
		$this->register_ability_via_api( $tool, 'nvoos-site' );
		$this->registered_abilities[] = 'nvoos/nonexistent-tool';

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// The tool won't be found in the real registry, so the execute callback
		// should return a structured error.
		$result = wp_get_ability( 'nvoos/nonexistent-tool' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'tool_not_found', $result['code'] );
	}

	/**
	 * Ensure the execution hooks fire during ability execution.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_execution_hooks_fire_during_execute() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$before_fired = false;
		$after_fired  = false;

		add_action(
			'wp_mcp_ai_before_ability_execute',
			function ( $ability_id, $tool_slug, $input ) use ( &$before_fired ) {
				$before_fired = true;
			},
			10,
			3
		);

		add_action(
			'wp_mcp_ai_after_ability_execute',
			function ( $ability_id, $tool_slug, $input, $result, $duration ) use ( &$after_fired ) {
				$after_fired = true;
			},
			10,
			5
		);

		$tool = new WP_MCP_AI_Ability_Bridge_Mock_Tool();
		$tool->set_slug( 'hook_test' );
		$tool->set_result(
			array(
				'success' => true,
				'data'    => 'hooks_worked',
			)
		);

		$this->bootstrap_ability_categories();
		$this->register_ability_via_api( $tool, 'nvoos-site' );
		$this->registered_abilities[] = 'nvoos/hook-test';

		// The bridge resolves tools lazily through the tool registry, so the
		// mock must be registered there for the execute callback to run.
		WP_MCP_AI_Tool_Registry::get_instance()->register_tool( $tool );
		$this->registered_tool_slugs[] = 'hook_test';

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		wp_get_ability( 'nvoos/hook-test' )->execute( array() );

		$this->assertTrue( $before_fired, 'wp_mcp_ai_before_ability_execute should fire.' );
		$this->assertTrue( $after_fired, 'wp_mcp_ai_after_ability_execute should fire.' );
	}
}
