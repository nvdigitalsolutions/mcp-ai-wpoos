<?php
/**
 * Brevo Tool Tests
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/email-marketing/class-wp-mcp-ai-pro-tool-send-brevo-email.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/email-marketing/class-wp-mcp-ai-pro-tool-manage-brevo-contacts.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/email-marketing/class-wp-mcp-ai-pro-tool-get-brevo-statistics.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Tests for the Brevo email sending tool.
 */
class WP_MCP_AI_Send_Brevo_Email_Tool_Test extends WP_UnitTestCase {
	/**
	 * Prepare defaults for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_mcp_ai_brevo_pre_send' );
		parent::tearDown();
	}

	/**
	 * The tool should require a Brevo API key before sending.
	 */
	public function test_execute_requires_api_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_credentials', $result->get_error_code() );
	}

	/**
	 * The tool should reject non-administrators.
	 */
	public function test_execute_enforces_capability() {
		$settings                  = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['brevo_api_key'] = 'xkeysib-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * A from email must be supplied either in settings or arguments.
	 */
	public function test_execute_requires_from_email() {
		$settings                  = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['brevo_api_key'] = 'xkeysib-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_sender', $result->get_error_code() );
	}

	/**
	 * The tool should require either text or HTML content.
	 */
	public function test_execute_requires_body() {
		$settings                     = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['brevo_api_key']    = 'xkeysib-test';
		$settings['brevo_from_email'] = 'from@example.com';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'recipient@example.com' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_body', $result->get_error_code() );
	}

	/**
	 * Successful requests should short-circuit through the pre-send filter.
	 */
	public function test_execute_sends_message() {
		$settings                     = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['brevo_api_key']    = 'xkeysib-test';
		$settings['brevo_from_email'] = 'from@example.com';
		$settings['brevo_from_name']  = 'Sender';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$captured_request = null;

		add_filter(
			'wp_mcp_ai_brevo_pre_send',
			function ( $preempt, $payload, $request_args ) use ( &$captured_request ) {
				$captured_request = array(
					'payload' => $payload,
					'args'    => $request_args,
				);

				return array(
					'response' => array(
						'code'    => 201,
						'message' => 'Created',
					),
					'body'     => wp_json_encode( array( 'messageId' => '<msg-uuid@brevo.com>' ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array(
					array(
						'email' => 'recipient@example.com',
						'name'  => 'Recipient',
					),
				),
				'text'    => 'Plain body',
				'html'    => '<p>HTML body</p>',
				'tags'    => array( 'test-tag' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertNotEmpty( $captured_request );
		$this->assertSame( 'Hello', $captured_request['payload']['subject'] );
		$this->assertSame( 'from@example.com', $captured_request['payload']['sender']['email'] );
		$this->assertSame( 'Sender', $captured_request['payload']['sender']['name'] );
		$this->assertSame( 'xkeysib-test', $captured_request['args']['headers']['api-key'] );
		$this->assertContains( 'test-tag', $captured_request['payload']['tags'] );
	}

	/**
	 * The tool slug should be correct.
	 */
	public function test_get_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$this->assertSame( 'send_brevo_email', $tool->get_slug() );
	}

	/**
	 * Capability flags should include the expected values.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'write', $flags );
	}
}

/**
 * Tests for the Brevo contact management tool.
 */
class WP_MCP_AI_Manage_Brevo_Contacts_Tool_Test extends WP_UnitTestCase {
	/**
	 * Prepare defaults for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The tool should require a Brevo API key.
	 */
	public function test_execute_requires_api_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts();
		$result = $tool->execute(
			array( 'action' => 'list_contacts' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_credentials', $result->get_error_code() );
	}

	/**
	 * add_contact action should require an email.
	 */
	public function test_add_contact_requires_email() {
		$settings                  = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['brevo_api_key'] = 'xkeysib-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts();
		$result = $tool->execute(
			array( 'action' => 'add_contact' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_email', $result->get_error_code() );
	}

	/**
	 * The tool slug should be correct.
	 */
	public function test_get_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts();
		$this->assertSame( 'manage_brevo_contacts', $tool->get_slug() );
	}
}

/**
 * Tests for the Brevo statistics tool.
 */
class WP_MCP_AI_Get_Brevo_Statistics_Tool_Test extends WP_UnitTestCase {
	/**
	 * Prepare defaults for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The tool should require a Brevo API key.
	 */
	public function test_execute_requires_api_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Brevo_Statistics();
		$result = $tool->execute(
			array( 'type' => 'campaigns' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_credentials', $result->get_error_code() );
	}

	/**
	 * The tool slug should be correct.
	 */
	public function test_get_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_Get_Brevo_Statistics();
		$this->assertSame( 'get_brevo_statistics', $tool->get_slug() );
	}

	/**
	 * Capability flags should include the expected values.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Pro_Tool_Get_Brevo_Statistics();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'read', $flags );
		$this->assertContains( 'external-api', $flags );
	}
}
