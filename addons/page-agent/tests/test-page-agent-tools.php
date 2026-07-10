<?php
/**
 * Page Agent Tool Tests
 *
 * Tests for the page_agent_execute tool: definition,
 * canonical envelope, parameter validation, and capability checks.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Test_Page_Agent_Tools
 *
 * @since 0.1.0
 */
class Test_Page_Agent_Tools extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @since 0.1.0
	 * @var WP_MCP_AI_Tool_Page_Agent_Execute
	 */
	protected $tool;

	/**
	 * Test admin user.
	 *
	 * @since 0.1.0
	 * @var WP_User
	 */
	protected $admin_user;

	/**
	 * Set up test environment.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Page_Agent_Execute' ) ) {
			require_once NVOOS_PAGE_AGENT_PATH . 'includes/tools/class-wp-mcp-ai-tool-page-agent-execute.php';
		}

		$this->tool       = new WP_MCP_AI_Tool_Page_Agent_Execute();
		$this->admin_user = self::factory()->user->create_and_get(
			array( 'role' => 'administrator' )
		);
	}

	/**
	 * Tear down test environment.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( $this->admin_user ) {
			wp_delete_user( $this->admin_user->ID );
		}
		parent::tearDown();
	}

	// ── Tool Identity Tests ──────────────────────────────────

	/**
	 * Test that the tool returns the correct slug.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_get_slug() {
		$this->assertEquals( 'page_agent_execute', $this->tool->get_slug() );
	}

	/**
	 * Test that the tool returns a non-empty name.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_get_name() {
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertIsString( $this->tool->get_name() );
	}

	/**
	 * Test that the tool returns a non-empty description.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_get_description() {
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertIsString( $this->tool->get_description() );
		$this->assertStringContainsString( 'Page Agent', $this->tool->get_description() );
	}

	// ── Capability Tests ─────────────────────────────────────

	/**
	 * Test that the tool requires edit_posts capability.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_required_capability() {
		$this->assertEquals( 'edit_posts', $this->tool->get_required_capability() );
	}

	// ── Schema Tests ─────────────────────────────────────────

	/**
	 * Test that get_parameters_schema returns a valid JSON Schema.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_parameters_schema_is_valid() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Required fields.
		$this->assertContains( 'instruction', $schema['required'] );

		// Properties.
		$this->assertArrayHasKey( 'instruction', $schema['properties'] );
		$this->assertArrayHasKey( 'wait_for_result', $schema['properties'] );
		$this->assertArrayHasKey( 'max_steps', $schema['properties'] );

		// Instruction property.
		$this->assertEquals( 'string', $schema['properties']['instruction']['type'] );

		// wait_for_result property.
		$this->assertEquals( 'boolean', $schema['properties']['wait_for_result']['type'] );
		$this->assertTrue( $schema['properties']['wait_for_result']['default'] );

		// max_steps property.
		$this->assertEquals( 'integer', $schema['properties']['max_steps']['type'] );
		$this->assertEquals( 1, $schema['properties']['max_steps']['minimum'] );
		$this->assertEquals( 200, $schema['properties']['max_steps']['maximum'] );
	}

	// ── Execution Tests ──────────────────────────────────────

	/**
	 * Test that execute returns the canonical envelope for a valid instruction.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_returns_delegate_envelope() {
		$result = $this->tool->execute(
			array( 'instruction' => 'Click the Add New button' ),
			array( 'user_id' => $this->admin_user->ID )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'data', $result );

		// Verify envelope structure.
		$data = $result['data'];
		$this->assertEquals( 'page_agent_delegate', $data['type'] );
		$this->assertEquals( 'Click the Add New button', $data['instruction'] );
		$this->assertTrue( $data['wait_for_result'] );
		$this->assertEquals( 'pending', $data['status'] );
		$this->assertArrayHasKey( 'timestamp', $data );
	}

	/**
	 * Test that execute returns error for an empty instruction.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_rejects_empty_instruction() {
		$result = $this->tool->execute(
			array( 'instruction' => '' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test that execute respects wait_for_result = false.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_respects_wait_for_result_false() {
		$result = $this->tool->execute(
			array(
				'instruction'     => 'Navigate to Settings',
				'wait_for_result' => false,
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['data']['wait_for_result'] );
	}

	/**
	 * Test that execute includes max_steps override when provided.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_includes_max_steps_override() {
		$result = $this->tool->execute(
			array(
				'instruction' => 'Fill all form fields',
				'max_steps'   => 25,
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'max_steps', $result['data'] );
		$this->assertEquals( 25, $result['data']['max_steps'] );
	}

	/**
	 * Test that execute excludes max_steps when zero.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_excludes_zero_max_steps() {
		$result = $this->tool->execute(
			array(
				'instruction' => 'Check page title',
				'max_steps'   => 0,
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'max_steps', $result['data'] );
	}

	/**
	 * Test that execute sanitizes the instruction.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_sanitizes_instruction() {
		$result = $this->tool->execute(
			array( 'instruction' => 'Click <script>alert("xss")</script> button' ),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringNotContainsString( '<script>', $result['data']['instruction'] );
		$this->assertStringContainsString( 'Click', $result['data']['instruction'] );
	}
}
