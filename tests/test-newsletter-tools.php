<?php
/**
 * Tests for Newsletter plugin integration tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Newsletter plugin tools.
 */
class Test_Newsletter_Tools extends WP_UnitTestCase {
	/**
	 * Test newsletter_add_subscriber tool registration.
	 */
	public function test_newsletter_add_subscriber_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'newsletter_add_subscriber' );

		if ( class_exists( 'Newsletter' ) ) {
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
			$this->assertEquals( 'newsletter_add_subscriber', $tool->get_slug() );
		} else {
			$this->assertNull( $tool, 'Tool should not be registered when Newsletter plugin is not active' );
		}
	}

	/**
	 * Test newsletter_get_subscribers tool registration.
	 */
	public function test_newsletter_get_subscribers_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'newsletter_get_subscribers' );

		if ( class_exists( 'Newsletter' ) ) {
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
			$this->assertEquals( 'newsletter_get_subscribers', $tool->get_slug() );
		} else {
			$this->assertNull( $tool, 'Tool should not be registered when Newsletter plugin is not active' );
		}
	}

	/**
	 * Test newsletter_unsubscribe tool registration.
	 */
	public function test_newsletter_unsubscribe_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'newsletter_unsubscribe' );

		if ( class_exists( 'Newsletter' ) ) {
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
			$this->assertEquals( 'newsletter_unsubscribe', $tool->get_slug() );
		} else {
			$this->assertNull( $tool, 'Tool should not be registered when Newsletter plugin is not active' );
		}
	}

	/**
	 * Test newsletter_get_subscriber_stats tool registration.
	 */
	public function test_newsletter_get_subscriber_stats_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'newsletter_get_subscriber_stats' );

		if ( class_exists( 'Newsletter' ) ) {
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
			$this->assertEquals( 'newsletter_get_subscriber_stats', $tool->get_slug() );
		} else {
			$this->assertNull( $tool, 'Tool should not be registered when Newsletter plugin is not active' );
		}
	}

	/**
	 * Test newsletter_create_email tool registration.
	 */
	public function test_newsletter_create_email_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'newsletter_create_email' );

		if ( class_exists( 'Newsletter' ) ) {
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
			$this->assertEquals( 'newsletter_create_email', $tool->get_slug() );
		} else {
			$this->assertNull( $tool, 'Tool should not be registered when Newsletter plugin is not active' );
		}
	}

	/**
	 * Test newsletter_get_emails tool registration.
	 */
	public function test_newsletter_get_emails_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'newsletter_get_emails' );

		if ( class_exists( 'Newsletter' ) ) {
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
			$this->assertEquals( 'newsletter_get_emails', $tool->get_slug() );
		} else {
			$this->assertNull( $tool, 'Tool should not be registered when Newsletter plugin is not active' );
		}
	}

	/**
	 * Test newsletter tools are in correct group.
	 */
	public function test_newsletter_tools_in_plugins_group() {
		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map  = $registry->get_tool_group_map();

		$newsletter_tools = array(
			'newsletter_add_subscriber',
			'newsletter_get_subscribers',
			'newsletter_unsubscribe',
			'newsletter_get_subscriber_stats',
			'newsletter_create_email',
			'newsletter_get_emails',
		);

		foreach ( $newsletter_tools as $tool_slug ) {
			$this->assertArrayHasKey( $tool_slug, $group_map, "Tool {$tool_slug} should be in group map" );
			$this->assertEquals( 'wordpress-plugins', $group_map[ $tool_slug ], "Tool {$tool_slug} should be in wordpress-plugins group" );
		}
	}

	/**
	 * Test newsletter_add_subscriber tool has correct capability flags.
	 */
	public function test_newsletter_add_subscriber_capability_flags() {
		if ( ! class_exists( 'Newsletter' ) ) {
			$this->markTestSkipped( 'Newsletter plugin not active' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$flags    = $registry->get_tool_capability_flags( 'newsletter_add_subscriber' );

		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'modifies-data', $flags );
		$this->assertContains( 'local-only', $flags );
	}

	/**
	 * Test newsletter_get_subscribers tool has correct capability flags.
	 */
	public function test_newsletter_get_subscribers_capability_flags() {
		if ( ! class_exists( 'Newsletter' ) ) {
			$this->markTestSkipped( 'Newsletter plugin not active' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$flags    = $registry->get_tool_capability_flags( 'newsletter_get_subscribers' );

		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'local-only', $flags );
	}

	/**
	 * Test newsletter tools require manage_options capability.
	 */
	public function test_newsletter_tools_require_permission() {
		if ( ! class_exists( 'Newsletter' ) ) {
			$this->markTestSkipped( 'Newsletter plugin not active' );
		}

		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'newsletter_get_subscribers' );

		$result = $tool->execute( array(), array( 'user_id' => $subscriber_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test newsletter_add_subscriber parameter schema.
	 */
	public function test_newsletter_add_subscriber_schema() {
		if ( ! class_exists( 'Newsletter' ) ) {
			$this->markTestSkipped( 'Newsletter plugin not active' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'newsletter_add_subscriber' );
		$schema   = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'email', $schema['properties'] );
		$this->assertArrayHasKey( 'name', $schema['properties'] );
		$this->assertArrayHasKey( 'lists', $schema['properties'] );
		$this->assertArrayHasKey( 'status', $schema['properties'] );
		$this->assertContains( 'email', $schema['required'] );
	}
}
