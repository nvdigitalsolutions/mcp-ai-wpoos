<?php
/**
 * Tests for the Slash Command Prompts Bridge
 *
 * Verifies that registered slash commands are exposed as MCP prompt
 * templates (prompts/list shape) with argument docs and metadata, that
 * toolkit filtering normalises kebab/snake slugs, and that prompts/get
 * rendering produces actionable instructions referencing the backing tool.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Slash Command Prompts Bridge Test Case
 */
class Test_Slash_Command_Prompts extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/slash-commands/slash-commands-init.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-tool-adapter.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-prompts.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php';

		wp_mcp_ai_init_slash_commands();

		// The toolkit manager registers its commands on the init hook, which the
		// test harness does not re-fire; register them manually.
		WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance()->register_toolkit_commands();

		// Register a deterministic tool-backed command for the render tests.
		$handler = wp_mcp_ai_get_slash_command_handler();
		$handler->register_tool_command(
			'prompt-demo',
			array(
				'tool'        => 'create_post',
				'description' => __( 'Demo command for prompt tests.', 'mcp-ai-wpoos' ),
				'usage'       => '/prompt-demo --title="Hello"',
				'capability'  => 'edit_posts',
				'parameters'  => array(
					'title' => array(
						'description' => __( 'Post title.', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Test prompts/list entries are namespaced and carry metadata.
	 */
	public function test_get_prompts_returns_namespaced_entries() {
		$prompts = WP_MCP_AI_Slash_Command_Prompts::get_prompts();

		$this->assertNotEmpty( $prompts );

		$by_name = array();
		foreach ( $prompts as $prompt ) {
			$by_name[ $prompt['name'] ] = $prompt;
		}

		$this->assertArrayHasKey( 'slash.prompt-demo', $by_name );

		$entry = $by_name['slash.prompt-demo'];
		$this->assertNotEmpty( $entry['description'] );
		$this->assertSame( 'prompt-demo', $entry['metadata']['slash_command'] );
		$this->assertSame( 'create_post', $entry['metadata']['tool'] );
		$this->assertSame( 'edit_posts', $entry['metadata']['capability'] );

		// Argument documentation is exposed for client-side prompting.
		$arguments = $entry['arguments'];
		$this->assertNotEmpty( $arguments );
		$this->assertSame( 'title', $arguments[0]['name'] );
		$this->assertTrue( $arguments[0]['required'] );
	}

	/**
	 * Test toolkit filtering normalises kebab and snake slugs.
	 */
	public function test_for_toolkit_normalises_slug_formats() {
		$snake = WP_MCP_AI_Slash_Command_Prompts::for_toolkit( 'social_media' );
		$kebab = WP_MCP_AI_Slash_Command_Prompts::for_toolkit( 'social-media' );

		$this->assertNotEmpty( $snake );
		$this->assertEquals( $snake, $kebab );

		$names = array();
		foreach ( $snake as $prompt ) {
			$names[] = $prompt['name'];
		}
		$this->assertContains( 'slash.social-post', $names );
	}

	/**
	 * Test prompts/get rendering produces an actionable instruction.
	 */
	public function test_render_produces_instruction() {
		$text = WP_MCP_AI_Slash_Command_Prompts::render( 'slash.prompt-demo' );

		$this->assertIsString( $text );
		$this->assertStringContainsString( '/prompt-demo', $text );
		$this->assertStringContainsString( 'create_post', $text );
	}

	/**
	 * Test render substitutes client-supplied arguments.
	 */
	public function test_render_substitutes_arguments() {
		$text = WP_MCP_AI_Slash_Command_Prompts::render(
			'slash.prompt-demo',
			array( 'title' => 'Hello World' )
		);

		$this->assertStringContainsString( '--title="Hello World"', $text );
	}

	/**
	 * Test unknown prompt names return null.
	 */
	public function test_render_unknown_prompt_returns_null() {
		$this->assertNull( WP_MCP_AI_Slash_Command_Prompts::render( 'slash.not-a-command' ) );
		$this->assertNull( WP_MCP_AI_Slash_Command_Prompts::render( 'other.not-a-command' ) );
	}
}
