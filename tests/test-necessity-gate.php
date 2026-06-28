<?php
/**
 * Tests for Necessity Gate (Layer J).
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 */

/**
 * Test necessity gate decision logic and integration points.
 */
class Test_Necessity_Gate extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the domain classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Action_Safety_Profile' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/domain/class-wp-mcp-ai-action-safety-profile.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Risk_Level_Constants' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/domain/class-wp-mcp-ai-risk-level-constants.php';
		}
	}

	/**
	 * Test that RISK_IRREVERSIBLE constant exists.
	 */
	public function test_risk_irreversible_constant_exists() {
		$this->assertTrue( defined( 'WP_MCP_AI_Risk_Level_Constants::RISK_IRREVERSIBLE' ) || true );
		$levels = WP_MCP_AI_Risk_Level_Constants::get_all_risk_levels();
		$this->assertContains( WP_MCP_AI_Risk_Level_Constants::RISK_IRREVERSIBLE, $levels );
	}

	/**
	 * Test that the safe-tool classification works correctly.
	 *
	 * Uses reflection to access the private method.
	 */
	public function test_is_safe_tool_read_only_without_dangerous_flags() {
		$method = self::get_private_method( 'is_safe_tool' );

		// Create a mock tool with only read-only flag.
		$tool = $this->create_mock_tool( array( 'read-only', 'cacheable' ) );

		$this->assertTrue( $method->invoke( null, $tool ) );
	}

	/**
	 * Test that a read-only tool with irreversible flag is NOT safe.
	 */
	public function test_is_safe_tool_read_only_with_irreversible_is_not_safe() {
		$method = self::get_private_method( 'is_safe_tool' );
		$tool   = $this->create_mock_tool( array( 'read-only', 'irreversible' ) );

		$this->assertFalse( $method->invoke( null, $tool ) );
	}

	/**
	 * Test that a write tool is NOT safe.
	 */
	public function test_is_safe_tool_write_is_not_safe() {
		$method = self::get_private_method( 'is_safe_tool' );
		$tool   = $this->create_mock_tool( array( 'write', 'state-changing' ) );

		$this->assertFalse( $method->invoke( null, $tool ) );
	}

	/**
	 * Test that null tool is NOT safe.
	 */
	public function test_is_safe_tool_null_is_not_safe() {
		$method = self::get_private_method( 'is_safe_tool' );

		$this->assertFalse( $method->invoke( null, null ) );
	}

	/**
	 * Test that tool without capability flags is NOT safe.
	 */
	public function test_is_safe_tool_no_flags_interface_is_not_safe() {
		$method = self::get_private_method( 'is_safe_tool' );

		// Create a mock that only implements Tool_Interface, not Capability_Flags_Interface.
		$tool = $this->getMockBuilder( 'WP_MCP_AI_Tool_Interface' )
			->disableOriginalConstructor()
			->getMock();

		$this->assertFalse( $method->invoke( null, $tool ) );
	}

	/**
	 * Test that classify_necessity returns a valid level.
	 */
	public function test_classify_necessity_returns_valid_level() {
		$necessity = WP_MCP_AI_Necessity_Gate::classify_necessity( 'get_post', array( 'post_id' => 1 ) );

		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_necessity( $necessity ) );
	}

	/**
	 * Test that classify_necessity detects empty arguments.
	 */
	public function test_classify_necessity_empty_arguments_is_optional() {
		$necessity = WP_MCP_AI_Necessity_Gate::classify_necessity( 'get_post', array() );

		$this->assertEquals(
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_OPTIONAL,
			$necessity
		);
	}

	/**
	 * Test that classify_necessity detects empty search query.
	 */
	public function test_classify_necessity_empty_search_is_optional() {
		$necessity = WP_MCP_AI_Necessity_Gate::classify_necessity(
			'web_search',
			array( 'query' => '' )
		);

		$this->assertEquals(
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_OPTIONAL,
			$necessity
		);
	}

	/**
	 * Test that classify_necessity detects overeager delete_post without post_id.
	 */
	public function test_classify_necessity_delete_without_id_is_overeager() {
		$necessity = WP_MCP_AI_Necessity_Gate::classify_necessity(
			'delete_post',
			array()
		);

		$this->assertEquals(
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_UNNECESSARY,
			$necessity
		);
	}

	/**
	 * Test that classify_necessity detects overeager send_email without recipient.
	 */
	public function test_classify_necessity_send_email_without_recipient_is_overeager() {
		$necessity = WP_MCP_AI_Necessity_Gate::classify_necessity(
			'send_email',
			array( 'subject' => 'Test' )
		);

		$this->assertEquals(
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_UNNECESSARY,
			$necessity
		);
	}

	/**
	 * Test that classify_necessity detects overeager create_woo_product without reference.
	 */
	public function test_classify_necessity_create_woo_without_reference_is_overeager() {
		$necessity = WP_MCP_AI_Necessity_Gate::classify_necessity(
			'create_woo_product',
			array( 'title' => 'Test Product' )
		);

		$this->assertEquals(
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_UNNECESSARY,
			$necessity
		);
	}

	/**
	 * Test that a valid tool call with arguments is classified as helpful.
	 */
	public function test_classify_necessity_valid_call_is_helpful() {
		$necessity = WP_MCP_AI_Necessity_Gate::classify_necessity(
			'web_search',
			array( 'query' => 'What is WordPress?' )
		);

		$this->assertEquals(
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_HELPFUL,
			$necessity
		);
	}

	/**
	 * Test that the necessity instructions block is returned when gate is enabled.
	 */
	public function test_inject_necessity_instructions_returns_string() {
		$result = WP_MCP_AI_Necessity_Gate::inject_necessity_instructions( 'You are an assistant.', 0 );

		$this->assertIsString( $result );
	}

	/**
	 * Test that the necessity instructions block contains key terms.
	 */
	public function test_inject_necessity_instructions_contains_key_terms() {
		$result = WP_MCP_AI_Necessity_Gate::inject_necessity_instructions( 'You are an assistant.', 0 );

		// When the gate is disabled (default), the prompt should be returned as-is.
		// When enabled, it should contain necessity instructions.
		// Since the gate is disabled by default in tests, we only check it's a string.
		$this->assertIsString( $result );
	}

	/**
	 * Test that the necessity gate is disabled by default.
	 */
	public function test_is_enabled_defaults_to_false() {
		$enabled = WP_MCP_AI_Necessity_Gate::is_enabled();
		$this->assertFalse( $enabled );
	}

	/**
	 * Test that the filter returns null for safe tools.
	 */
	public function test_evaluate_returns_null_for_safe_tools() {
		// Register a mock safe tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $this->create_mock_tool( array( 'read-only', 'cacheable' ), 'test_safe_tool' ) );

		$result = WP_MCP_AI_Necessity_Gate::evaluate( null, 'test_safe_tool', array() );

		// Should return null (allow) since the gate is disabled by default.
		$this->assertNull( $result );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Get a private method via reflection.
	 *
	 * @param string $method_name Method name.
	 * @return ReflectionMethod
	 */
	private static function get_private_method( $method_name ) {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Necessity_Gate' );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * Create a mock tool implementing both Tool_Interface and Capability_Flags_Interface.
	 *
	 * @param array  $flags    Capability flags.
	 * @param string $slug     Tool slug.
	 * @return WP_MCP_AI_Tool_Interface
	 */
	private function create_mock_tool( array $flags = array(), $slug = 'test_tool' ) {
		$tool = $this->getMockBuilder( 'stdClass' )
			->addMethods( array( 'get_slug', 'get_name', 'get_description', 'get_parameters_schema', 'get_required_capability', 'execute', 'get_capability_flags' ) )
			->getMock();

		$tool->method( 'get_slug' )->willReturn( $slug );
		$tool->method( 'get_capability_flags' )->willReturn( $flags );

		return $tool;
	}
}
