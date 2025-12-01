<?php
/**
 * tests/test-mailjet-tool.php
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-mailjet-email.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Tests for the Mailjet sending tool.
 */
class WP_MCP_AI_Send_Mailjet_Email_Tool_Test extends WP_UnitTestCase {
	/**
	 * Prepare defaults for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Ensure filters are cleaned up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_mcp_ai_mailjet_pre_send' );
		parent::tearDown();
	}

	/**
	 * The tool should require Mailjet credentials before sending.
	 */
	public function test_execute_requires_credentials() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_Mailjet_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailjet_missing_credentials', $result->get_error_code() );
	}

	/**
	 * The tool should not allow sending when the requester lacks the required capability.
	 */
	public function test_execute_enforces_capability() {
		$settings                       = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailjet_api_key']    = 'key';
		$settings['mailjet_api_secret'] = 'secret';
		$settings['mailjet_from_email'] = 'from@example.com';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_Mailjet_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Successful requests should short-circuit through the pre-send filter and return Mailjet data.
	 */
	public function test_execute_sends_message() {
		$settings                       = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailjet_api_key']    = 'key';
		$settings['mailjet_api_secret'] = 'secret';
		$settings['mailjet_from_email'] = 'from@example.com';
		$settings['mailjet_from_name']  = 'Sender';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$captured_request = null;

		add_filter(
			'wp_mcp_ai_mailjet_pre_send',
			function ( $preempt, $payload, $request_args ) use ( &$captured_request ) {
				$captured_request = array(
					'payload' => $payload,
					'args'    => $request_args,
				);

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'Messages' => array(
								array(
									'Status' => 'success',
									'To'     => array(
										array(
											'Email'       => 'recipient@example.com',
											'MessageUUID' => 'uuid-1',
										),
									),
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Send_Mailjet_Email();
		$result = $tool->execute(
			array(
				'subject'   => 'Hello',
				'to'        => array(
					array(
						'email' => 'recipient@example.com',
						'name'  => 'Recipient',
					),
				),
				'cc'        => array( 'cc@example.com' ),
				'text'      => 'Plain body',
				'html'      => '<p>HTML body</p>',
				'custom_id' => 'example',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertIsArray( $result['messages'] );
		$this->assertNotEmpty( $captured_request );
		$this->assertSame( 'Hello', $captured_request['payload']['Messages'][0]['Subject'] );
		$this->assertSame( 'from@example.com', $captured_request['payload']['Messages'][0]['From']['Email'] );
		$this->assertSame( 'Sender', $captured_request['payload']['Messages'][0]['From']['Name'] );
		$this->assertSame( 'example', $captured_request['payload']['Messages'][0]['CustomID'] );
		$this->assertSame( 'Basic ' . base64_encode( 'key:secret' ), $captured_request['args']['headers']['Authorization'] );
	}

	/**
	 * The tool should require either text or HTML content.
	 */
	public function test_execute_requires_body() {
		$settings                       = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailjet_api_key']    = 'key';
		$settings['mailjet_api_secret'] = 'secret';
		$settings['mailjet_from_email'] = 'from@example.com';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_Mailjet_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'recipient@example.com' ),
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailjet_missing_body', $result->get_error_code() );
	}
}
