<?php
/**
 * Tests for WP_MCP_AI_Pro_Slash_Command_Persona.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for Pro slash command /persona.
 */
class Test_Pro_Slash_Command_Persona extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var WP_MCP_AI_Pro_Slash_Command_Persona
	 */
	private $command;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Slash_Command_Persona' ) ) {
			require_once dirname( __DIR__ ) . '/includes/slash-commands/commands/class-wp-mcp-ai-pro-slash-command-persona.php';
		}

		$this->command   = new WP_MCP_AI_Pro_Slash_Command_Persona();
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_delete_user( $this->editor_id );
		parent::tearDown();
	}

	/**
	 * Test that guest requests are blocked.
	 */
	public function test_guest_block(): void {
		$result = $this->command->execute( array(), array(), array( 'guest_request' => true ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'guest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that subscriber cannot use /persona.
	 */
	public function test_capability_gate_subscriber(): void {
		$sub_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );

		$result = $this->command->execute( array(), array(), array( 'user_id' => $sub_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'forbidden', $result->get_error_code() );

		wp_delete_user( $sub_id );
	}

	/**
	 * Test graceful degradation when Profession Service not loaded.
	 */
	public function test_missing_service_class(): void {
		if ( class_exists( 'WP_MCP_AI_Profession_Service' ) && class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			$this->markTestSkipped( 'Profession Service is loaded; skipping degradation test.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'service_unavailable', $result->get_error_code() );
	}

	/**
	 * Test that loading a persona fires the wp_mcp_ai_persona_loaded action.
	 */
	public function test_load_persona_fires_action(): void {
		if ( ! class_exists( 'WP_MCP_AI_Profession_Service' ) || ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			$this->markTestSkipped( 'Profession Service not loaded.' );
		}

		wp_set_current_user( $this->editor_id );

		// If service is available, test that slug lookups work gracefully.
		$fired  = false;
		$action = function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'wp_mcp_ai_persona_loaded', $action );

		$result = $this->command->execute(
			array( 'non-existent-slug-for-testing' ),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		remove_action( 'wp_mcp_ai_persona_loaded', $action );

		// Should either return not_found error or a valid result.
		if ( is_wp_error( $result ) ) {
			$this->assertEquals( 'not_found', $result->get_error_code() );
		} else {
			// If it succeeded (slug exists), the action should have fired.
			$this->assertTrue( $fired );
		}
	}

	/**
	 * Test --show with empty slug returns WP_Error.
	 */
	public function test_show_empty_slug(): void {
		if ( ! class_exists( 'WP_MCP_AI_Profession_Service' ) || ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			$this->markTestSkipped( 'Profession Service not loaded.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array(),
			array( 'show' => '' ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_slug', $result->get_error_code() );
	}
}
