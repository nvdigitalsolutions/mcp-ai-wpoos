<?php
/**
 * Tests for the assistant portability tools.
 *
 * Covers registry presence, metadata, and basic execute() behaviour for
 * `export_assistant`, `import_assistant`, and `duplicate_assistant`.
 *
 * @package WP_MCP_AI
 * @since   1.1.80
 */

/**
 * Assistant portability tool tests.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Assistant_Portability_Tools_Test extends WP_UnitTestCase {

	/**
	 * Admin user ID for capability checks.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$cpt_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
			if ( file_exists( $cpt_file ) ) {
				require_once $cpt_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			$engine_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-portability.php';
			if ( file_exists( $engine_file ) ) {
				require_once $engine_file;
			}
		}

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
	}

	/**
	 * Reset the current user.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * All three portability tools are registered.
	 */
	public function test_portability_tools_are_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( array( 'export_assistant', 'import_assistant', 'duplicate_assistant' ) as $slug ) {
			$tool = $registry->get_tool( $slug );
			$this->assertNotNull( $tool, sprintf( 'The %s tool should be registered.', $slug ) );
			$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
		}
	}

	/**
	 * Export_assistant returns a canonical JSON bundle.
	 */
	public function test_export_assistant_execute() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Tool Export Assistant',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'gpt-4.1' );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'export_assistant' );

		$result = $tool->execute(
			array(
				'assistant_ids' => array( $assistant_id ),
				'include_a2a'   => false,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'json', $result );

		$decoded = json_decode( $result['json'], true );
		$this->assertSame( 'nvoos-assistant', $decoded['format'] );
		$this->assertArrayNotHasKey( '_wp_mcp_ai_credentials', $decoded['assistants'][0]['meta'] );
	}

	/**
	 * Import_assistant creates an assistant from a payload.
	 */
	public function test_import_assistant_execute() {
		$payload = wp_json_encode(
			array(
				'format'     => 'nvoos-assistant',
				'assistants' => array(
					array(
						'title' => 'Tool Imported Assistant',
						'meta'  => array( '_wp_mcp_ai_model' => 'gemini-pro' ),
					),
				),
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'import_assistant' );

		$result = $tool->execute( array( 'json' => $payload ), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 'gemini-pro', get_post_meta( $result['items'][0]['assistant_id'], '_wp_mcp_ai_model', true ) );
	}

	/**
	 * Duplicate_assistant clones without credentials.
	 */
	public function test_duplicate_assistant_execute() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Tool Source Assistant',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'gpt-4.1' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_credentials', 'hash' );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'duplicate_assistant' );

		$result = $tool->execute( array( 'assistant_id' => $assistant_id ), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$new_id = $result['assistant_id'];
		$this->assertNotSame( $assistant_id, $new_id );
		$this->assertSame( 'gpt-4.1', get_post_meta( $new_id, '_wp_mcp_ai_model', true ) );
		$this->assertSame( '', get_post_meta( $new_id, '_wp_mcp_ai_credentials', true ) );
		$this->assertSame( 'draft', get_post_status( $new_id ) );
	}
}
