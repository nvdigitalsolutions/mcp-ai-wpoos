<?php
/**
 * Tests for WP_MCP_AI_Shortcodes coordinator class.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * @group shortcodes-coordinator
 */
class WP_MCP_AI_Shortcodes_Coordinator_Tests extends WP_UnitTestCase {

	/**
	 * Test class instantiation.
	 */
	public function test_class_instantiation() {
		$shortcodes = new WP_MCP_AI_Shortcodes();
		$this->assertInstanceOf( 'WP_MCP_AI_Shortcodes', $shortcodes );
	}

	/**
	 * Test chat shortcode handler is created.
	 */
	public function test_chat_shortcode_handler_created() {
		$shortcodes = new WP_MCP_AI_Shortcodes();
		$handler    = $shortcodes->get_chat_shortcode();

		$this->assertInstanceOf( 'WP_MCP_AI_Shortcode', $handler );
	}

	/**
	 * Test get_chat_shortcode returns same instance.
	 */
	public function test_get_chat_shortcode_consistency() {
		$shortcodes = new WP_MCP_AI_Shortcodes();
		$handler1   = $shortcodes->get_chat_shortcode();
		$handler2   = $shortcodes->get_chat_shortcode();

		$this->assertSame( $handler1, $handler2 );
	}

	/**
	 * Test backwards compatibility global is set.
	 */
	public function test_backwards_compatibility_global() {
		// Clear any existing global.
		unset( $GLOBALS['wp_mcp_ai_shortcode'] );

		$shortcodes = new WP_MCP_AI_Shortcodes();

		$this->assertArrayHasKey( 'wp_mcp_ai_shortcode', $GLOBALS );
		$this->assertInstanceOf( 'WP_MCP_AI_Shortcode', $GLOBALS['wp_mcp_ai_shortcode'] );
		$this->assertSame( $shortcodes->get_chat_shortcode(), $GLOBALS['wp_mcp_ai_shortcode'] );
	}

	/**
	 * Test multiple instances create separate handlers.
	 */
	public function test_multiple_instances_separate_handlers() {
		$shortcodes1 = new WP_MCP_AI_Shortcodes();
		$shortcodes2 = new WP_MCP_AI_Shortcodes();

		$handler1 = $shortcodes1->get_chat_shortcode();
		$handler2 = $shortcodes2->get_chat_shortcode();

		// Each instance should have its own handler.
		$this->assertNotSame( $handler1, $handler2 );
	}

	/**
	 * Test chat shortcode has required methods.
	 */
	public function test_chat_shortcode_has_required_methods() {
		$shortcodes = new WP_MCP_AI_Shortcodes();
		$handler    = $shortcodes->get_chat_shortcode();

		$reflection = new ReflectionClass( $handler );

		// Verify essential methods exist.
		$this->assertTrue( $reflection->hasMethod( 'render' ) || $reflection->hasMethod( 'shortcode' ) );
	}

	/**
	 * Test shortcode coordinator is lightweight.
	 */
	public function test_coordinator_is_lightweight() {
		$shortcodes = new WP_MCP_AI_Shortcodes();
		$reflection = new ReflectionClass( $shortcodes );

		// Should only have one property (the chat_shortcode).
		$properties = $reflection->getProperties();
		$this->assertCount( 1, $properties );
		$this->assertEquals( 'chat_shortcode', $properties[0]->getName() );
	}

	/**
	 * Test class has get_chat_shortcode method.
	 */
	public function test_has_get_chat_shortcode_method() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Shortcodes' );

		$this->assertTrue( $reflection->hasMethod( 'get_chat_shortcode' ) );
		$this->assertTrue( $reflection->getMethod( 'get_chat_shortcode' )->isPublic() );
	}

	/**
	 * Test constructor creates handler immediately.
	 */
	public function test_constructor_creates_handler_immediately() {
		// Clear global.
		unset( $GLOBALS['wp_mcp_ai_shortcode'] );

		// Create instance.
		$shortcodes = new WP_MCP_AI_Shortcodes();

		// Handler should be created immediately (not lazy loaded).
		$handler = $shortcodes->get_chat_shortcode();
		$this->assertNotNull( $handler );
	}

	/**
	 * Test chat shortcode handler is protected property.
	 */
	public function test_chat_shortcode_is_protected() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Shortcodes' );
		$property   = $reflection->getProperty( 'chat_shortcode' );

		$this->assertTrue( $property->isProtected() );
	}
}
