<?php
/**
 * Tests for tool description engineering: registry usage-guidance assembly
 * and the list_mcp_tools lazy-schema parameters.
 *
 * @package MCP_AI_WPooS
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The two stub tool fixtures work together with the test class to exercise the guidance assembly contract.
 */

/**
 * Stub tool that declares usage guidance via the optional interface.
 */
class Test_Description_Engineering_Guided_Stub_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'test_guided_stub_tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'Guided Stub Tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return 'Stub description';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Execute the stub tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Canonical success envelope.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array(
			'success' => true,
			'message' => 'Stubbed.',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => 'You know the ID',
			'when_not_to_use' => 'Listing or searching',
			'related_tools'   => array( 'test_plain_stub_tool' ),
			'notes'           => 'Keep it short',
		);
	}

	/**
	 * Extended definition (toolkit discovery metadata).
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'        => $this->get_name(),
			'description' => $this->get_description(),
			'toolkit'     => 'stub_toolkit',
		);
	}
}

/**
 * Stub tool without usage guidance.
 */
class Test_Description_Engineering_Plain_Stub_Tool implements WP_MCP_AI_Tool_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'test_plain_stub_tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'Plain Stub Tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return 'Plain description';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Execute the stub tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Canonical success envelope.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array(
			'success' => true,
			'message' => 'Stubbed.',
		);
	}
}

/**
 * Test class for tool description engineering.
 */
class Test_Tool_Description_Engineering extends WP_UnitTestCase {

	/**
	 * Registry instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up: admin user + bootstrapped registry handle.
	 *
	 * The registry is bootstrapped before stub tools are registered so the
	 * lazy init() inside get_tool() cannot rebuild the tools array and wipe
	 * the stubs (the shared-singleton interference pattern).
	 */
	public function setUp(): void {
		parent::setUp();
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();
	}

	/**
	 * A guided tool's model-facing description appends the usage suffix.
	 */
	public function test_model_facing_description_appends_guidance_suffix() {
		$tool = new Test_Description_Engineering_Guided_Stub_Tool();

		$description = $this->registry->get_model_facing_description( $tool );

		$this->assertStringContainsString( 'Stub description', $description );
		$this->assertStringContainsString( '[Usage:', $description );
		$this->assertStringContainsString( 'use when You know the ID', $description );
		$this->assertStringContainsString( 'do NOT use when Listing or searching', $description );
		$this->assertStringContainsString( 'related: test_plain_stub_tool', $description );
	}

	/**
	 * A plain tool's model-facing description is the short description alone.
	 */
	public function test_model_facing_description_without_guidance_is_unchanged() {
		$tool = new Test_Description_Engineering_Plain_Stub_Tool();

		$this->assertSame(
			'Plain description',
			$this->registry->get_model_facing_description( $tool )
		);
	}

	/**
	 * The guidance suffix can be suppressed via its filter.
	 */
	public function test_guidance_suffix_suppressed_by_filter() {
		add_filter( 'wp_mcp_ai_tool_usage_guidance_description_suffix', '__return_empty_string' );

		$tool        = new Test_Description_Engineering_Guided_Stub_Tool();
		$description = $this->registry->get_model_facing_description( $tool );

		remove_all_filters( 'wp_mcp_ai_tool_usage_guidance_description_suffix' );

		$this->assertSame( 'Stub description', $description );
	}

	/**
	 * The registry normalises and sanitises the declared guidance shape.
	 */
	public function test_get_usage_guidance_normalises_shape() {
		$tool = new Test_Description_Engineering_Guided_Stub_Tool();

		$guidance = $this->registry->get_usage_guidance( $tool );

		$this->assertSame( 'You know the ID', $guidance['when_to_use'] );
		$this->assertSame( array( 'test_plain_stub_tool' ), $guidance['related_tools'] );
	}

	/**
	 * The registry returns an empty array for non-guided tools.
	 */
	public function test_get_usage_guidance_empty_for_plain_tool() {
		$this->assertSame(
			array(),
			$this->registry->get_usage_guidance( new Test_Description_Engineering_Plain_Stub_Tool() )
		);
	}

	/**
	 * Passing tool_slug lazy-loads a single registered tool schema.
	 *
	 * The envelope trait merges associative payloads at the top level, so the
	 * tool fields are asserted as top-level keys (the canonical contract).
	 */
	public function test_list_mcp_tools_tool_slug_returns_single_schema() {
		$guided = new Test_Description_Engineering_Guided_Stub_Tool();
		$this->registry->register_tool( $guided );

		$tool   = new WP_MCP_AI_Tool_List_MCP_Tools();
		$result = $tool->execute( array( 'tool_slug' => 'test_guided_stub_tool' ), array() );

		$this->registry->unregister_tool( 'test_guided_stub_tool' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'test_guided_stub_tool', $result['name'] );
		$this->assertStringContainsString( '[Usage:', $result['description'] );
		$this->assertArrayHasKey( 'inputSchema', $result );
	}

	/**
	 * An unknown tool slug yields a WP_Error from list_mcp_tools.
	 */
	public function test_list_mcp_tools_tool_slug_unknown_returns_error() {
		$tool   = new WP_MCP_AI_Tool_List_MCP_Tools();
		$result = $tool->execute( array( 'tool_slug' => 'does_not_exist_anywhere' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'tool_not_found', $result->get_error_code() );
	}

	/**
	 * Lean-catalogue mode omits inputSchema from every list entry.
	 */
	public function test_list_mcp_tools_include_schemas_false_omits_schemas() {
		$guided = new Test_Description_Engineering_Guided_Stub_Tool();
		$this->registry->register_tool( $guided );

		$tool   = new WP_MCP_AI_Tool_List_MCP_Tools();
		$result = $tool->execute(
			array(
				'toolkit'         => 'stub_toolkit',
				'include_schemas' => false,
			),
			array()
		);

		$this->registry->unregister_tool( 'test_guided_stub_tool' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['tools'] );
		foreach ( $result['tools'] as $entry ) {
			$this->assertArrayNotHasKey( 'inputSchema', $entry );
		}
	}
}
