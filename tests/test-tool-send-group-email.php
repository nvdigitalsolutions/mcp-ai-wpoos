<?php
/**
 * Tests for send_group_email tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test send_group_email tool — high-risk, sends real emails.
 */
class Test_Tool_Send_Group_Email extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Send_Group_Email
	 */
	private $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->tool     = new WP_MCP_AI_Tool_Send_Group_Email();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'send_group_email', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Unauthenticated request (user_id=0) is rejected.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array(
				'recipients' => array( array( 'email' => 'test@example.com' ) ),
				'subject'    => 'Test',
				'message'    => 'Hello',
			),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Missing recipients list returns wp_mcp_ai_missing_recipients error.
	 */
	public function test_missing_recipients_returns_error() {
		$result = $this->tool->execute(
			array(
				'recipients' => array(),
				'subject'    => 'No recipients',
				'message'    => 'Hello',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_recipients', $result->get_error_code() );
	}

	/**
	 * Missing subject returns wp_mcp_ai_missing_subject error.
	 */
	public function test_missing_subject_returns_error() {
		$result = $this->tool->execute(
			array(
				'recipients' => array( array( 'email' => 'test@example.com' ) ),
				'subject'    => '',
				'message'    => 'Hello',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_subject', $result->get_error_code() );
	}

	/**
	 * Missing message body returns wp_mcp_ai_missing_message error.
	 */
	public function test_missing_message_returns_error() {
		$result = $this->tool->execute(
			array(
				'recipients' => array( array( 'email' => 'test@example.com' ) ),
				'subject'    => 'Valid Subject',
				'message'    => '',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_message', $result->get_error_code() );
	}

	/**
	 * Recipient limit exceeded returns wp_mcp_ai_recipient_limit_exceeded error.
	 */
	public function test_recipient_limit_exceeded_returns_error() {
		// Build a recipients array exceeding the default limit (500).
		$recipients = array();
		for ( $i = 0; $i < 510; $i++ ) {
			$recipients[] = array( 'email' => "user{$i}@example.com" );
		}

		$result = $this->tool->execute(
			array(
				'recipients' => $recipients,
				'subject'    => 'Mass email',
				'message'    => 'Hello everyone',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_recipient_limit_exceeded', $result->get_error_code() );
	}
}
