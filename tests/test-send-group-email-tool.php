<?php
/**
 * Tests for the send group email tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-group-email.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Tests for the send group email tool.
 */
class WP_MCP_AI_Send_Group_Email_Tool_Test extends WP_UnitTestCase {
	/**
	 * Ensure plugin settings are reset before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Reset globals between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_mcp_ai_send_group_email_pre_send' );
		parent::tearDown();
	}

	/**
	 * Ensure users without the required capability cannot send emails.
	 */
	public function test_execute_requires_permission() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		list( $attachment_id ) = $this->create_payload_attachment(
			array(
				'subject'    => 'Test Subject',
				'message'    => 'Hello team',
				'recipients' => array( 'editor@example.com' ),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure attachments that the current user cannot access are rejected.
	 */
	public function test_execute_requires_attachment_access() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		list( $attachment_id ) = $this->create_payload_attachment(
			array(
				'subject'    => 'Restricted Subject',
				'message'    => 'Restricted message',
				'recipients' => array( 'user@example.com' ),
			)
		);

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_author' => $admin_id,
				'post_status' => 'private',
			)
		);

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$capability_filter = static function () {
			return '';
		};

		add_filter( 'wp_mcp_ai_send_group_email_capability', $capability_filter );

		wp_set_current_user( $subscriber_id );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array(
				'user_id' => $subscriber_id,
			)
		);

		remove_filter( 'wp_mcp_ai_send_group_email_capability', $capability_filter );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_attachment_forbidden', $result->get_error_code() );

		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertSame( 403, isset( $error_data['status'] ) ? $error_data['status'] : null );
	}

	/**
	 * Ensure the tool parses JSON attachments and forwards the email through the WordPress mailer.
	 */
	public function test_execute_sends_mail_using_json_attachment() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		list( $attachment_id ) = $this->create_payload_attachment(
			array(
				'subject'    => 'Weekly Update',
				'body'       => 'OpenAI response summary.',
				'recipients' => array(
					'team@example.com',
					array( 'email' => 'lead@example.com' ),
				),
				'cc'         => array( 'manager@example.com' ),
			),
			'group-email.txt'
		);

		$captured_mail = array();
		add_filter(
			'wp_mcp_ai_send_group_email_pre_send',
			function ( $pre_send, $mail_args ) use ( &$captured_mail ) {
				$captured_mail = $mail_args;
				return true;
			},
			10,
			2
		);

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'message'       => 'Intro message.',
				'from_email'    => 'noreply@example.com',
				'from_name'     => 'AI Assistant',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertNotEmpty( $captured_mail );
		$this->assertSame( array( 'team@example.com', 'lead@example.com' ), $captured_mail['to'] );
		$this->assertSame( 'Weekly Update', $captured_mail['subject'] );
		$this->assertStringContainsString( 'Intro message.', $captured_mail['message'] );
		$this->assertStringContainsString( 'OpenAI response summary.', $captured_mail['message'] );
		$this->assertContains( 'Cc: manager@example.com', $captured_mail['headers'] );
		$this->assertContains( 'From: AI Assistant <noreply@example.com>', $captured_mail['headers'] );
	}

	/**
	 * Ensure plain text payloads that mimic email headers are parsed correctly.
	 */
	public function test_execute_parses_plain_text_payload() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$contents      = "Subject: Launch Plan\nTo: user1@example.com, user2@example.com\nCc: lead@example.com\nBcc: hidden@example.com\n\nHello team,\n\nOpenAI generated response.";
		$attachment_id = $this->create_text_attachment( 'group-email.txt', $contents );

		$captured_mail = array();
		add_filter(
			'wp_mcp_ai_send_group_email_pre_send',
			function ( $pre_send, $mail_args ) use ( &$captured_mail ) {
				$captured_mail = $mail_args;
				return true;
			},
			10,
			2
		);

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertSame( array( 'user1@example.com', 'user2@example.com' ), $captured_mail['to'] );
		$this->assertSame( 'Launch Plan', $captured_mail['subject'] );
		$this->assertSame( "Hello team,\n\nOpenAI generated response.", $captured_mail['message'] );
		$this->assertContains( 'Cc: lead@example.com', $captured_mail['headers'] );
		$this->assertContains( 'Bcc: hidden@example.com', $captured_mail['headers'] );
	}

	/**
	 * Ensure the capability requirement can be configured through settings.
	 */
	public function test_execute_honors_capability_setting() {
		$settings                           = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['group_email_capability'] = 'manage_options';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		list( $attachment_id ) = $this->create_payload_attachment(
			array(
				'subject'    => 'Weekly Update',
				'message'    => 'Hello team',
				'recipients' => array( 'team@example.com' ),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array(
				'user_id' => $editor_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$captured_mail = array();
		add_filter(
			'wp_mcp_ai_send_group_email_pre_send',
			function ( $pre_send, $mail_args ) use ( &$captured_mail ) {
				$captured_mail = $mail_args;
				return true;
			},
			10,
			2
		);

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertSame( array( 'team@example.com' ), $captured_mail['to'] );
	}

	/**
	 * Ensure the recipient limit can be adjusted via settings.
	 */
	public function test_execute_respects_max_recipient_setting() {
		$settings                               = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['group_email_max_recipients'] = 1;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		list( $attachment_id ) = $this->create_payload_attachment(
			array(
				'subject'    => 'Weekly Update',
				'message'    => 'Hello team',
				'recipients' => array( 'team@example.com', 'lead@example.com' ),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_recipient_limit_exceeded', $result->get_error_code() );
	}

	/**
	 * Ensure malicious custom headers are stripped before sending.
	 */
	public function test_execute_strips_malicious_custom_headers() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		list( $attachment_id ) = $this->create_payload_attachment(
			array(
				'subject'    => 'Security Update',
				'message'    => 'Hello team',
				'recipients' => array( 'security@example.com' ),
			)
		);

		$captured_mail = array();
		add_filter(
			'wp_mcp_ai_send_group_email_pre_send',
			function ( $pre_send, $mail_args ) use ( &$captured_mail ) {
				$captured_mail = $mail_args;
				return true;
			},
			10,
			2
		);

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'headers'       => array(
					'X-Normal: Value',
					"X-Evil: good\r\nBcc: attacker@example.com",
					'Invalid Header Without Colon',
					"Reply-To: \x07attack@example.com",
					'Content-Type: text/plain; charset=UTF-8',
				),
			),
			array(
				'user_id' => $admin_id,
			)
		);

		remove_all_filters( 'wp_mcp_ai_send_group_email_pre_send' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertContains( 'X-Normal: Value', $captured_mail['headers'] );
		$this->assertContains( 'Content-Type: text/plain; charset=UTF-8', $captured_mail['headers'] );
		$this->assertContains( 'Reply-To: attack@example.com', $captured_mail['headers'] );
		$this->assertNotContains( 'Bcc: attacker@example.com', $captured_mail['headers'] );
		foreach ( $captured_mail['headers'] as $header ) {
			$this->assertStringNotContainsString( "\r", $header );
			$this->assertStringNotContainsString( "\n", $header );
		}
	}

	/**
	 * Ensure the attachment size limit prevents large payloads from being processed.
	 */
	public function test_execute_rejects_oversized_attachment() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		list( $attachment_id ) = $this->create_payload_attachment(
			array(
				'subject'    => 'Weekly Update',
				'message'    => str_repeat( 'A', 256 ),
				'recipients' => array( 'team@example.com' ),
			)
		);

		add_filter( 'wp_mcp_ai_email_definition_attachment_max_bytes', array( $this, 'force_small_attachment_limit' ) );

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		remove_filter( 'wp_mcp_ai_email_definition_attachment_max_bytes', array( $this, 'force_small_attachment_limit' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_attachment_too_large', $result->get_error_code() );
	}

	/**
	 * Reduce the attachment limit for tests.
	 *
	 * @return int
	 */
	public function force_small_attachment_limit() {
		return 64; // bytes.
	}

	/**
	 * Create an attachment with JSON payload contents.
	 *
	 * @param array  $payload  JSON payload.
	 * @param string $filename Optional filename.
	 * @return array
	 */
	protected function create_payload_attachment( array $payload, $filename = 'email.txt' ) {
		$json   = wp_json_encode( $payload );
		$upload = wp_upload_bits( $filename, null, $json );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_title'  => 'Email Payload',
				'post_status' => 'inherit',
			)
		);

		return array( $attachment_id, $upload['file'] );
	}

	/**
	 * Create a plain text attachment.
	 *
	 * @param string $filename File name.
	 * @param string $contents File contents.
	 * @return int Attachment ID.
	 */
	protected function create_text_attachment( $filename, $contents ) {
		$upload = wp_upload_bits( $filename, null, $contents );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_title'  => 'Email Payload',
				'post_status' => 'inherit',
			)
		);

		return $attachment_id;
	}

	/**
	 * Ensure emails can be sent without attachments using direct parameters.
	 */
	public function test_execute_sends_mail_without_attachment() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$captured_mail = array();
		add_filter(
			'wp_mcp_ai_send_group_email_pre_send',
			function ( $pre_send, $mail_args ) use ( &$captured_mail ) {
				$captured_mail = $mail_args;
				return true;
			},
			10,
			2
		);

		$tool   = new WP_MCP_AI_Tool_Send_Group_Email();
		$result = $tool->execute(
			array(
				'subject'    => 'Direct Email Test',
				'message'    => 'This email was sent without any attachment file.',
				'recipients' => array(
					'user1@example.com',
					'user2@example.com',
					array(
						'email' => 'user3@example.com',
						'name'  => 'User Three',
					),
				),
				'from_email' => 'sender@example.com',
				'from_name'  => 'Test Sender',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertSame( array( 'user1@example.com', 'user2@example.com', 'user3@example.com' ), $captured_mail['to'] );
		$this->assertSame( 'Direct Email Test', $captured_mail['subject'] );
		$this->assertSame( 'This email was sent without any attachment file.', $captured_mail['message'] );
		$this->assertContains( 'From: Test Sender <sender@example.com>', $captured_mail['headers'] );
	}
}
