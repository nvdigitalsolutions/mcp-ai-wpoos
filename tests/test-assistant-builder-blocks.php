<?php
/**
 * Test Assistant Builder Blocks.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for WP_MCP_AI_Assistant_Builder_Blocks class.
 */
class Test_Assistant_Builder_Blocks extends WP_UnitTestCase {

	/**
	 * Test that the blocks class is properly loaded.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Assistant_Builder_Blocks' ) );
	}

	/**
	 * Test that the init method exists and is callable.
	 */
	public function test_init_method_exists() {
		$this->assertTrue( method_exists( 'WP_MCP_AI_Assistant_Builder_Blocks', 'init' ) );
	}

	/**
	 * Test that the register_blocks method exists.
	 */
	public function test_register_blocks_method_exists() {
		$this->assertTrue( method_exists( 'WP_MCP_AI_Assistant_Builder_Blocks', 'register_blocks' ) );
	}

	/**
	 * Test that the enqueue methods exist.
	 */
	public function test_enqueue_methods_exist() {
		$this->assertTrue( method_exists( 'WP_MCP_AI_Assistant_Builder_Blocks', 'enqueue_block_editor_assets' ) );
		$this->assertTrue( method_exists( 'WP_MCP_AI_Assistant_Builder_Blocks', 'enqueue_frontend_assets' ) );
	}

	/**
	 * Test that the register_block_category method exists.
	 */
	public function test_register_block_category_method_exists() {
		$this->assertTrue( method_exists( 'WP_MCP_AI_Assistant_Builder_Blocks', 'register_block_category' ) );
	}

	/**
	 * Test block.json files exist for all blocks.
	 */
	public function test_block_json_files_exist() {
		$blocks_dir = WP_MCP_AI_PATH . 'includes/blocks/';

		$block_types = array(
			'chat',
			'assistant-selector',
			'tools-grid',
			'knowledge-base',
			'assistant-builder',
		);

		foreach ( $block_types as $block_type ) {
			$block_json = $blocks_dir . $block_type . '/block.json';
			$this->assertFileExists( $block_json, "block.json should exist for {$block_type}" );
		}
	}

	/**
	 * Test render.php files exist for all blocks.
	 */
	public function test_render_php_files_exist() {
		$blocks_dir = WP_MCP_AI_PATH . 'includes/blocks/';

		$block_types = array(
			'chat',
			'assistant-selector',
			'tools-grid',
			'knowledge-base',
			'assistant-builder',
		);

		foreach ( $block_types as $block_type ) {
			$render_php = $blocks_dir . $block_type . '/render.php';
			$this->assertFileExists( $render_php, "render.php should exist for {$block_type}" );
		}
	}

	/**
	 * Test block.json files contain valid JSON.
	 */
	public function test_block_json_files_valid() {
		$blocks_dir = WP_MCP_AI_PATH . 'includes/blocks/';

		$block_types = array(
			'chat',
			'assistant-selector',
			'tools-grid',
			'knowledge-base',
			'assistant-builder',
		);

		foreach ( $block_types as $block_type ) {
			$block_json = $blocks_dir . $block_type . '/block.json';
			$content    = file_get_contents( $block_json );
			$decoded    = json_decode( $content, true );

			$this->assertNotNull( $decoded, "block.json for {$block_type} should contain valid JSON" );
			$this->assertArrayHasKey( 'name', $decoded, "block.json for {$block_type} should have a name" );
			$this->assertStringStartsWith( 'wp-mcp-ai/', $decoded['name'], 'Block name should start with wp-mcp-ai/' );
		}
	}

	/**
	 * Test that JavaScript asset files exist.
	 */
	public function test_javascript_assets_exist() {
		$this->assertFileExists(
			WP_MCP_AI_PATH . 'assets/js/blocks/assistant-builder-blocks.js',
			'Block editor JavaScript should exist'
		);

		$this->assertFileExists(
			WP_MCP_AI_PATH . 'assets/js/blocks/assistant-builder-blocks-frontend.js',
			'Frontend JavaScript should exist'
		);
	}

	/**
	 * Test that CSS asset file exists.
	 */
	public function test_css_assets_exist() {
		$this->assertFileExists(
			WP_MCP_AI_PATH . 'assets/css/blocks/assistant-builder-blocks.css',
			'Block CSS should exist'
		);
	}

	/**
	 * Test init method can be called without errors.
	 */
	public function test_init_does_not_crash() {
		// Reset the initialized flag using reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Assistant_Builder_Blocks' );
		$property   = $reflection->getProperty( 'initialized' );
		$property->setAccessible( true );
		$property->setValue( null, false );

		// This should not throw any errors.
		WP_MCP_AI_Assistant_Builder_Blocks::init();

		// Verify hooks were registered.
		$this->assertNotFalse(
			has_action( 'init', array( 'WP_MCP_AI_Assistant_Builder_Blocks', 'register_blocks' ) )
		);

		$this->assertNotFalse(
			has_action( 'enqueue_block_editor_assets', array( 'WP_MCP_AI_Assistant_Builder_Blocks', 'enqueue_block_editor_assets' ) )
		);

		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( 'WP_MCP_AI_Assistant_Builder_Blocks', 'enqueue_frontend_assets' ) )
		);
	}

	/**
	 * Test register_block_category returns correct structure.
	 */
	public function test_register_block_category_returns_correct_structure() {
		$existing_categories = array(
			array(
				'slug'  => 'text',
				'title' => 'Text',
			),
		);

		// Create a mock context.
		$context = new stdClass();

		$result = WP_MCP_AI_Assistant_Builder_Blocks::register_block_category(
			$existing_categories,
			$context
		);

		// Should have WP oOS category first.
		$this->assertIsArray( $result );
		$this->assertGreaterThan( count( $existing_categories ), count( $result ) );
		$this->assertEquals( 'wp-mcp-ai', $result[0]['slug'] );
		$this->assertStringContainsString( 'WP oOS', $result[0]['title'] );
	}
}
