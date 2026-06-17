<?php
/**
 * Tests for the JetEngine MCP Resources class.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */
class Test_JetEngine_MCP_Resources extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Load the resources class.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_MCP_Resources' ) ) {
			$file = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-mcp-resources.php'
				: dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-jetengine-mcp-resources.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		// Reset singleton.
		$reflection = new ReflectionClass( 'WP_MCP_AI_JetEngine_MCP_Resources' );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_JetEngine_MCP_Resources' ) );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_singleton_returns_same_instance() {
		$instance1 = WP_MCP_AI_JetEngine_MCP_Resources::get_instance();
		$instance2 = WP_MCP_AI_JetEngine_MCP_Resources::get_instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_get_post_types_returns_array() {
		$resources = WP_MCP_AI_JetEngine_MCP_Resources::get_instance();
		$result    = $resources->get_post_types();

		$this->assertIsArray( $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_get_taxonomies_returns_array() {
		$resources = WP_MCP_AI_JetEngine_MCP_Resources::get_instance();
		$result    = $resources->get_taxonomies();

		$this->assertIsArray( $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_get_meta_boxes_returns_array() {
		$resources = WP_MCP_AI_JetEngine_MCP_Resources::get_instance();
		$result    = $resources->get_meta_boxes();

		$this->assertIsArray( $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_get_glossaries_returns_array() {
		$resources = WP_MCP_AI_JetEngine_MCP_Resources::get_instance();
		$result    = $resources->get_glossaries();

		$this->assertIsArray( $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_get_macros_returns_array() {
		$resources = WP_MCP_AI_JetEngine_MCP_Resources::get_instance();
		$result    = $resources->get_macros();

		$this->assertIsArray( $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_get_relations_returns_array() {
		$resources = WP_MCP_AI_JetEngine_MCP_Resources::get_instance();
		$result    = $resources->get_relations();

		$this->assertIsArray( $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_inject_site_context_without_mcp() {
		$resources = WP_MCP_AI_JetEngine_MCP_Resources::get_instance();
		$context   = 'Original context';
		$result    = $resources->inject_site_context( $context );

		// Without JetEngine MCP, should return original context.
		$this->assertEquals( $context, $result );
	}
}
