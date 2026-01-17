<?php
/**
 * Tests for media template tools capability flags.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that media template tools implement capability flags correctly.
 */
class Test_Media_Template_Tools_Capability_Flags extends WP_UnitTestCase {

	/**
	 * Test that WP_MCP_AI_Tool_List_Media_Templates implements get_capability_flags.
	 */
	public function test_list_media_templates_has_capability_flags() {
		$class_file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/class-wp-mcp-ai-tool-list-media-templates.php';
		
		if ( ! file_exists( $class_file ) ) {
			$this->markTestSkipped( 'Media template tools not available (Pro addon)' );
		}

		require_once $class_file;
		$tool = new WP_MCP_AI_Tool_List_Media_Templates();

		// Test that the tool implements the interface.
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Capability_Flags_Interface',
			$tool,
			'List Media Templates tool should implement WP_MCP_AI_Tool_Capability_Flags_Interface'
		);

		// Test that get_capability_flags method exists and returns an array.
		$this->assertTrue(
			method_exists( $tool, 'get_capability_flags' ),
			'List Media Templates tool should have get_capability_flags method'
		);

		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags, 'get_capability_flags should return an array' );
		$this->assertNotEmpty( $flags, 'get_capability_flags should return non-empty array' );

		// Test that it has the 'pro' flag.
		$this->assertContains( 'pro', $flags, 'List Media Templates should have "pro" capability flag' );

		// Test that it has read-only flag (since it's a list/query tool).
		$this->assertContains( 'read-only', $flags, 'List Media Templates should have "read-only" capability flag' );
	}

	/**
	 * Test that WP_MCP_AI_Tool_Create_Media_Template implements get_capability_flags.
	 */
	public function test_create_media_template_has_capability_flags() {
		$class_file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/class-wp-mcp-ai-tool-create-media-template.php';
		
		if ( ! file_exists( $class_file ) ) {
			$this->markTestSkipped( 'Media template tools not available (Pro addon)' );
		}

		require_once $class_file;
		$tool = new WP_MCP_AI_Tool_Create_Media_Template();

		// Test that the tool implements the interface.
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Capability_Flags_Interface',
			$tool,
			'Create Media Template tool should implement WP_MCP_AI_Tool_Capability_Flags_Interface'
		);

		// Test that get_capability_flags method exists and returns an array.
		$this->assertTrue(
			method_exists( $tool, 'get_capability_flags' ),
			'Create Media Template tool should have get_capability_flags method'
		);

		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags, 'get_capability_flags should return an array' );
		$this->assertNotEmpty( $flags, 'get_capability_flags should return non-empty array' );

		// Test that it has the 'pro' flag.
		$this->assertContains( 'pro', $flags, 'Create Media Template should have "pro" capability flag' );

		// Test that it has write flag (since it creates posts).
		$this->assertContains( 'write', $flags, 'Create Media Template should have "write" capability flag' );
	}

	/**
	 * Test that WP_MCP_AI_Tool_Apply_Media_Template implements get_capability_flags.
	 */
	public function test_apply_media_template_has_capability_flags() {
		$class_file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/class-wp-mcp-ai-tool-apply-media-template.php';
		
		if ( ! file_exists( $class_file ) ) {
			$this->markTestSkipped( 'Media template tools not available (Pro addon)' );
		}

		require_once $class_file;
		$tool = new WP_MCP_AI_Tool_Apply_Media_Template();

		// Test that the tool implements the interface.
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Capability_Flags_Interface',
			$tool,
			'Apply Media Template tool should implement WP_MCP_AI_Tool_Capability_Flags_Interface'
		);

		// Test that get_capability_flags method exists and returns an array.
		$this->assertTrue(
			method_exists( $tool, 'get_capability_flags' ),
			'Apply Media Template tool should have get_capability_flags method'
		);

		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags, 'get_capability_flags should return an array' );
		$this->assertNotEmpty( $flags, 'get_capability_flags should return non-empty array' );

		// Test that it has the 'pro' flag.
		$this->assertContains( 'pro', $flags, 'Apply Media Template should have "pro" capability flag' );

		// Test that it has write flag (since it creates media files).
		$this->assertContains( 'write', $flags, 'Apply Media Template should have "write" capability flag' );
	}

	/**
	 * Test that all media template tools can be instantiated without fatal errors.
	 */
	public function test_all_media_template_tools_can_be_instantiated() {
		$tools = array(
			'list_media_templates'   => 'class-wp-mcp-ai-tool-list-media-templates.php',
			'create_media_template'  => 'class-wp-mcp-ai-tool-create-media-template.php',
			'apply_media_template'   => 'class-wp-mcp-ai-tool-apply-media-template.php',
		);

		foreach ( $tools as $tool_name => $filename ) {
			$class_file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/' . $filename;
			
			if ( ! file_exists( $class_file ) ) {
				continue;
			}

			require_once $class_file;
			
			// Convert filename to class name.
			$class_name = 'WP_MCP_AI_Tool_' . str_replace( ' ', '_', ucwords( str_replace( array( 'class-wp-mcp-ai-tool-', '.php', '-' ), array( '', '', ' ' ), $filename ) ) );
			
			// Test that the class exists.
			$this->assertTrue(
				class_exists( $class_name ),
				sprintf( 'Class %s should exist', $class_name )
			);

			// Test that the tool can be instantiated without fatal errors.
			$tool = new $class_name();
			$this->assertInstanceOf(
				'WP_MCP_AI_Tool_Interface',
				$tool,
				sprintf( '%s should implement WP_MCP_AI_Tool_Interface', $class_name )
			);
		}
	}
}
