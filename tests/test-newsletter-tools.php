<?php
/**
 * Tests for the Newsletter plugin tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for the Newsletter plugin tool implementations.
 */
class WP_MCP_AI_Newsletter_Tools_Test extends WP_UnitTestCase {

	/**
	 * Reset the current user between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Ensure the add subscriber tool reports as unavailable without the Newsletter plugin.
	 */
	public function test_add_subscriber_tool_unavailable_without_plugin() {
		$this->assertFalse( WP_MCP_AI_Tool_Newsletter_Add_Subscriber::is_available() );
		$this->assertSame(
			__( 'The Newsletter Add Subscriber tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' ),
			WP_MCP_AI_Tool_Newsletter_Add_Subscriber::get_unavailable_reason()
		);
	}

	/**
	 * Ensure the get subscribers tool reports as unavailable without the Newsletter plugin.
	 */
	public function test_get_subscribers_tool_unavailable_without_plugin() {
		$this->assertFalse( WP_MCP_AI_Tool_Newsletter_Get_Subscribers::is_available() );
		$this->assertSame(
			__( 'The Newsletter Get Subscribers tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' ),
			WP_MCP_AI_Tool_Newsletter_Get_Subscribers::get_unavailable_reason()
		);
	}

	/**
	 * Ensure the send email tool reports as unavailable without the Newsletter plugin.
	 */
	public function test_send_email_tool_unavailable_without_plugin() {
		$this->assertFalse( WP_MCP_AI_Tool_Newsletter_Send_Email::is_available() );
		$this->assertSame(
			__( 'The Newsletter Send Email tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' ),
			WP_MCP_AI_Tool_Newsletter_Send_Email::get_unavailable_reason()
		);
	}

	/**
	 * Ensure the get stats tool reports as unavailable without the Newsletter plugin.
	 */
	public function test_get_stats_tool_unavailable_without_plugin() {
		$this->assertFalse( WP_MCP_AI_Tool_Newsletter_Get_Stats::is_available() );
		$this->assertSame(
			__( 'The Newsletter Get Stats tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' ),
			WP_MCP_AI_Tool_Newsletter_Get_Stats::get_unavailable_reason()
		);
	}

	/**
	 * Ensure the manage campaigns tool reports as unavailable without the Newsletter plugin.
	 */
	public function test_manage_campaigns_tool_unavailable_without_plugin() {
		$this->assertFalse( WP_MCP_AI_Tool_Newsletter_Manage_Campaigns::is_available() );
		$this->assertSame(
			__( 'The Newsletter Manage Campaigns tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' ),
			WP_MCP_AI_Tool_Newsletter_Manage_Campaigns::get_unavailable_reason()
		);
	}

	/**
	 * Test add subscriber tool returns correct slug.
	 */
	public function test_add_subscriber_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Newsletter_Add_Subscriber();
		$this->assertSame( 'newsletter_add_subscriber', $tool->get_slug() );
	}

	/**
	 * Test get subscribers tool returns correct slug.
	 */
	public function test_get_subscribers_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Newsletter_Get_Subscribers();
		$this->assertSame( 'newsletter_get_subscribers', $tool->get_slug() );
	}

	/**
	 * Test send email tool returns correct slug.
	 */
	public function test_send_email_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Newsletter_Send_Email();
		$this->assertSame( 'newsletter_send_email', $tool->get_slug() );
	}

	/**
	 * Test get stats tool returns correct slug.
	 */
	public function test_get_stats_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Newsletter_Get_Stats();
		$this->assertSame( 'newsletter_get_stats', $tool->get_slug() );
	}

	/**
	 * Test manage campaigns tool returns correct slug.
	 */
	public function test_manage_campaigns_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Newsletter_Manage_Campaigns();
		$this->assertSame( 'newsletter_manage_campaigns', $tool->get_slug() );
	}

	/**
	 * Test add subscriber tool has proper name.
	 */
	public function test_add_subscriber_tool_name() {
		$tool = new WP_MCP_AI_Tool_Newsletter_Add_Subscriber();
		$this->assertSame( __( 'Newsletter Add/Update Subscriber', 'wp-mcp-ai' ), $tool->get_name() );
	}

	/**
	 * Test add subscriber tool has proper description.
	 */
	public function test_add_subscriber_tool_description() {
		$tool = new WP_MCP_AI_Tool_Newsletter_Add_Subscriber();
		$this->assertSame( __( 'Adds a new subscriber or updates an existing one in The Newsletter Plugin.', 'wp-mcp-ai' ), $tool->get_description() );
	}

	/**
	 * Test add subscriber tool requires email in parameters schema.
	 */
	public function test_add_subscriber_parameters_schema_requires_email() {
		$tool   = new WP_MCP_AI_Tool_Newsletter_Add_Subscriber();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'email', $schema['required'] );
	}

	/**
	 * Test send email tool requires subject and message in parameters schema.
	 */
	public function test_send_email_parameters_schema_required_fields() {
		$tool   = new WP_MCP_AI_Tool_Newsletter_Send_Email();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'subject', $schema['required'] );
		$this->assertContains( 'message', $schema['required'] );
	}

	/**
	 * Test manage campaigns tool has action parameter with correct enum values.
	 */
	public function test_manage_campaigns_action_parameter() {
		$tool   = new WP_MCP_AI_Tool_Newsletter_Manage_Campaigns();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['action'] );
		$this->assertContains( 'list', $schema['properties']['action']['enum'] );
		$this->assertContains( 'send', $schema['properties']['action']['enum'] );
	}

	/**
	 * Test add subscriber tool returns error when plugin is unavailable.
	 */
	public function test_add_subscriber_execute_returns_error_when_unavailable() {
		$tool   = new WP_MCP_AI_Tool_Newsletter_Add_Subscriber();
		$result = $tool->execute( array( 'email' => 'test@example.com' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_newsletter_unavailable', $result->get_error_code() );
	}

	/**
	 * Test get subscribers tool returns error when plugin is unavailable.
	 */
	public function test_get_subscribers_execute_returns_error_when_unavailable() {
		$tool   = new WP_MCP_AI_Tool_Newsletter_Get_Subscribers();
		$result = $tool->execute( array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_newsletter_unavailable', $result->get_error_code() );
	}

	/**
	 * Test send email tool returns error when plugin is unavailable.
	 */
	public function test_send_email_execute_returns_error_when_unavailable() {
		$tool   = new WP_MCP_AI_Tool_Newsletter_Send_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Test',
				'message' => 'Test message',
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_newsletter_unavailable', $result->get_error_code() );
	}

	/**
	 * Test get stats tool returns error when plugin is unavailable.
	 */
	public function test_get_stats_execute_returns_error_when_unavailable() {
		$tool   = new WP_MCP_AI_Tool_Newsletter_Get_Stats();
		$result = $tool->execute( array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_newsletter_unavailable', $result->get_error_code() );
	}

	/**
	 * Test manage campaigns tool returns error when plugin is unavailable.
	 */
	public function test_manage_campaigns_execute_returns_error_when_unavailable() {
		$tool   = new WP_MCP_AI_Tool_Newsletter_Manage_Campaigns();
		$result = $tool->execute( array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_newsletter_unavailable', $result->get_error_code() );
	}

	/**
	 * Test tools implement the interface correctly.
	 */
	public function test_tools_implement_interface() {
		$tools = array(
			new WP_MCP_AI_Tool_Newsletter_Add_Subscriber(),
			new WP_MCP_AI_Tool_Newsletter_Get_Subscribers(),
			new WP_MCP_AI_Tool_Newsletter_Send_Email(),
			new WP_MCP_AI_Tool_Newsletter_Get_Stats(),
			new WP_MCP_AI_Tool_Newsletter_Manage_Campaigns(),
		);

		foreach ( $tools as $tool ) {
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		}
	}
}
