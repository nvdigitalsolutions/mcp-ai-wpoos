<?php
/**
 * Tests for the send_group_email_validated tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-group-email-validated.php';

/**
 * Test case for the Symfony Validator version of send_group_email tool.
 */
class WP_MCP_AI_Send_Group_Email_Validated_Tool_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that the tool has correct metadata.
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_Send_Group_Email_Validated();

		$this->assertSame( 'send_group_email_validated', $tool->get_slug() );
		$this->assertSame( 'Send Group Email (Validated)', $tool->get_name() );
		$this->assertStringContainsString( 'email', strtolower( $tool->get_description() ) );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'subject', $schema['properties'] );
		$this->assertArrayHasKey( 'message', $schema['properties'] );
		$this->assertArrayHasKey( 'recipients', $schema['properties'] );
	}

	/**
	 * Test tool execution with valid data.
	 */
	public function test_execute_with_valid_data() {
		// Create user with publish_posts capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Mock wp_mail to prevent actual email sending.
		add_filter( 'wp_mcp_ai_send_group_email_pre_send', '__return_true' );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$result = $tool->execute(
			array(
				'subject'    => 'Test Email',
				'message'    => 'This is a test message.',
				'recipients' => array( 'test@example.com' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'wp_mcp_ai_send_group_email_pre_send', '__return_true' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'sent', $result );
		$this->assertTrue( $result['sent'] );
		$this->assertArrayHasKey( 'recipients', $result );
	}

	/**
	 * Test tool rejects subject exceeding maximum length.
	 */
	public function test_execute_rejects_long_subject() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$result = $tool->execute(
			array(
				'subject'    => str_repeat( 'a', 201 ),
				'message'    => 'Test message',
				'recipients' => array( 'test@example.com' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects invalid email format.
	 */
	public function test_execute_rejects_invalid_email() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$result = $tool->execute(
			array(
				'subject'    => 'Test',
				'message'    => 'Test message',
				'recipients' => array( 'test@example.com' ),
				'from_email' => 'invalid-email',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool accepts valid email format.
	 */
	public function test_execute_accepts_valid_email() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		add_filter( 'wp_mcp_ai_send_group_email_pre_send', '__return_true' );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$result = $tool->execute(
			array(
				'subject'    => 'Test',
				'message'    => 'Test message',
				'recipients' => array( 'test@example.com' ),
				'from_email' => 'sender@example.com',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'wp_mcp_ai_send_group_email_pre_send', '__return_true' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['sent'] );
	}

	/**
	 * Test tool rejects negative attachment ID.
	 */
	public function test_execute_rejects_negative_attachment_id() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$result = $tool->execute(
			array(
				'subject'       => 'Test',
				'message'       => 'Test message',
				'recipients'    => array( 'test@example.com' ),
				'attachment_id' => -1,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects invalid URL.
	 */
	public function test_execute_rejects_invalid_url() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$result = $tool->execute(
			array(
				'subject'    => 'Test',
				'message'    => 'Test message',
				'recipients' => array( 'test@example.com' ),
				'url'        => 'not-a-valid-url',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects long from_name.
	 */
	public function test_execute_rejects_long_from_name() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$result = $tool->execute(
			array(
				'subject'    => 'Test',
				'message'    => 'Test message',
				'recipients' => array( 'test@example.com' ),
				'from_name'  => str_repeat( 'a', 101 ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool requires permission.
	 */
	public function test_execute_requires_permission() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$result = $tool->execute(
			array(
				'subject'    => 'Test',
				'message'    => 'Test message',
				'recipients' => array( 'test@example.com' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test capability flags are delegated.
	 */
	public function test_capability_flags() {
		$tool            = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$original_tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$validated_flags = $tool->get_capability_flags();
		$original_flags  = $original_tool->get_capability_flags();

		$this->assertSame( $original_flags, $validated_flags );
	}

	/**
	 * Test tool accepts array of headers.
	 */
	public function test_execute_accepts_headers_array() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		add_filter( 'wp_mcp_ai_send_group_email_pre_send', '__return_true' );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email_Validated();
		$result = $tool->execute(
			array(
				'subject'    => 'Test',
				'message'    => 'Test message',
				'recipients' => array( 'test@example.com' ),
				'headers'    => array( 'X-Custom-Header: value' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'wp_mcp_ai_send_group_email_pre_send', '__return_true' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['sent'] );
	}
}
