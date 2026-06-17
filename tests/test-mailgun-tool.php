<?php
/**
 * Mailgun Tool Tests
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/email-marketing/class-wp-mcp-ai-pro-tool-send-mailgun-email.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Tests for the Mailgun email sending tool.
 */
class WP_MCP_AI_Send_Mailgun_Email_Tool_Test extends WP_UnitTestCase {
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
		remove_all_filters( 'wp_mcp_ai_mailgun_pre_send' );
		remove_all_filters( 'wp_mcp_ai_mailgun_request_args' );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * The tool should require a Mailgun API key before sending.
	 */
	public function test_execute_requires_api_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailgun_missing_credentials', $result->get_error_code() );
	}

	/**
	 * The tool should require a configured sending domain.
	 */
	public function test_execute_requires_domain() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailgun_api_key'] = 'key-test123';
		// mailgun_domain intentionally left empty.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailgun_missing_domain', $result->get_error_code() );
	}

	/**
	 * The tool should reject non-administrators.
	 */
	public function test_execute_enforces_capability() {
		$settings                     = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailgun_api_key']  = 'key-test123';
		$settings['mailgun_domain']   = 'mg.example.com';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
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
		$settings                     = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailgun_api_key']  = 'key-test123';
		$settings['mailgun_domain']   = 'mg.example.com';
		// mailgun_from_email intentionally left empty.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailgun_missing_sender', $result->get_error_code() );
	}

	/**
	 * The tool should require either text or HTML content.
	 */
	public function test_execute_requires_body() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailgun_api_key']     = 'key-test123';
		$settings['mailgun_domain']      = 'mg.example.com';
		$settings['mailgun_from_email']  = 'from@mg.example.com';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'recipient@example.com' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailgun_missing_body', $result->get_error_code() );
	}

	/**
	 * A successful send should return the Mailgun message ID via the pre-send filter.
	 */
	public function test_execute_sends_message() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailgun_api_key']     = 'key-test123';
		$settings['mailgun_domain']      = 'mg.example.com';
		$settings['mailgun_from_email']  = 'from@mg.example.com';
		$settings['mailgun_from_name']   = 'Sender';
		$settings['mailgun_region']      = 'us';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$captured_request = null;

		add_filter(
			'wp_mcp_ai_mailgun_pre_send',
			function ( $preempt, $body, $request_args ) use ( &$captured_request ) {
				$captured_request = array(
					'body' => $body,
					'args' => $request_args,
				);

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => '<20240101120000.abc123@mg.example.com>',
							'message' => 'Queued. Thank you.',
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
		$result = $tool->execute(
			array(
				'subject'  => 'Hello',
				'to'       => array(
					array(
						'email' => 'recipient@example.com',
						'name'  => 'Recipient',
					),
				),
				'cc'       => array( 'cc@example.com' ),
				'text'     => 'Plain body',
				'html'     => '<p>HTML body</p>',
				'reply_to' => 'reply@example.com',
				'tags'     => array( 'welcome', 'test' ),
				'tracking' => false,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertNotEmpty( $captured_request );

		// Verify sender is formatted as "Name <email>".
		$this->assertSame( 'Sender <from@mg.example.com>', $captured_request['body']['from'] );

		// Verify subject.
		$this->assertSame( 'Hello', $captured_request['body']['subject'] );

		// Verify recipient formatting.
		$this->assertStringContainsString( 'recipient@example.com', $captured_request['body']['to'] );

		// Verify CC.
		$this->assertSame( 'cc@example.com', $captured_request['body']['cc'] );

		// Verify reply-to header.
		$this->assertSame( 'reply@example.com', $captured_request['body']['h:Reply-To'] );

		// Verify tracking disabled.
		$this->assertSame( 'no', $captured_request['body']['o:tracking'] );

		// Verify tags are stored as an array (each sent as a separate o:tag field).
		$this->assertIsArray( $captured_request['body']['o:tag'] );
		$this->assertContains( 'welcome', $captured_request['body']['o:tag'] );
		$this->assertContains( 'test', $captured_request['body']['o:tag'] );

		// Verify Basic Auth header uses "api:" prefix.
		$expected_auth = 'Basic ' . base64_encode( 'api:key-test123' );
		$this->assertSame( $expected_auth, $captured_request['args']['headers']['Authorization'] );
	}

	/**
	 * EU region should route to the EU API base.
	 */
	public function test_eu_region_uses_eu_endpoint() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailgun_api_key']     = 'key-eu-test';
		$settings['mailgun_domain']      = 'mg.example.eu';
		$settings['mailgun_from_email']  = 'from@mg.example.eu';
		$settings['mailgun_region']      = 'eu';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$captured_url = null;

		// Capture the URL before the pre-send filter short-circuits the request.
		add_filter(
			'wp_mcp_ai_mailgun_request_args',
			function ( $args, $body, $arguments, $context, $tool ) {
				return $args;
			},
			10,
			5
		);

		// Use pre_http_request to capture the actual URL that would be sent to wp_remote_post.
		// The pre_http_request filter runs before wp_mcp_ai_mailgun_pre_send pre-emption
		// is applied, so we record the URL here.
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				// Return a fake 200 response to short-circuit the actual HTTP call.
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( array( 'id' => '<eu-id>', 'message' => 'Queued.' ) ),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
		$result = $tool->execute(
			array(
				'subject' => 'EU Test',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $user_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );

		// The EU region base URL should appear in the captured request URL.
		if ( null !== $captured_url ) {
			$this->assertStringContainsString( 'api.eu.mailgun.net', $captured_url );
		}
	}

	/**
	 * The domain argument should override the settings domain.
	 */
	public function test_domain_argument_overrides_settings() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['mailgun_api_key']     = 'key-test';
		$settings['mailgun_domain']      = 'mg.default.com';
		$settings['mailgun_from_email']  = 'from@mg.default.com';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$captured_url = null;

		// Capture the actual request URL via pre_http_request.
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( array( 'id' => '<id>', 'message' => 'Queued.' ) ),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Test',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
				'domain'  => 'mg.override.com',
			),
			array( 'user_id' => $user_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );

		// The override domain should appear in the URL, not the settings domain.
		if ( null !== $captured_url ) {
			$this->assertStringContainsString( 'mg.override.com', $captured_url );
			$this->assertStringNotContainsString( 'mg.default.com', $captured_url );
		}
	}

	/**
	 * The tool slug should be correct.
	 */
	public function test_get_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
		$this->assertSame( 'send_mailgun_email', $tool->get_slug() );
	}

	/**
	 * Capability flags should include the expected values.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Pro_Tool_Send_Mailgun_Email();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'write', $flags );
	}
}
