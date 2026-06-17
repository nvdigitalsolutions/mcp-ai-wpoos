<?php
/**
 * AJAX tests for messaging-channel handlers (Pro).
 *
 * All handlers live in WP_MCP_AI_Pro_Remote_Sites_Admin and use per-action
 * nonce strings. The tests gate on Pro class availability and stub outbound
 * HTTP so no real API calls escape the test sandbox.
 *
 * Handlers covered:
 *  WhatsApp
 *   - wp_mcp_ai_fetch_whatsapp_phone_numbers
 *   - wp_mcp_ai_test_whatsapp_live
 *   - wp_mcp_ai_test_whatsapp_auto_reply
 *   - wp_mcp_ai_register_whatsapp_phone_number
 *   - wp_mcp_ai_create_whatsapp_group
 *  Telegram
 *   - wp_mcp_ai_test_telegram_live
 *   - wp_mcp_ai_test_telegram_auto_reply
 *   - wp_mcp_ai_set_telegram_webhook
 *   - wp_mcp_ai_get_telegram_webhook_info
 *   - wp_mcp_ai_register_telegram_commands
 *  Facebook Messenger
 *   - wp_mcp_ai_generate_messenger_token
 *   - wp_mcp_ai_test_messenger_live
 *   - wp_mcp_ai_test_messenger_auto_reply
 *  Google Chat
 *   - wp_mcp_ai_test_google_chat_live
 *   - wp_mcp_ai_fetch_google_chat_spaces
 *   - wp_mcp_ai_test_google_chat_auto_reply
 *   - wp_mcp_ai_test_google_chat_incoming_trigger
 *   - wp_mcp_ai_get_google_chat_webhook_log
 *   - wp_mcp_ai_clear_google_chat_webhook_log
 *  Microsoft Teams
 *   - wp_mcp_ai_generate_teams_manifest
 *   - wp_mcp_ai_generate_teams_app_package
 *  Office 365
 *   - wp_mcp_ai_test_office365_live
 *   - wp_mcp_ai_test_office365_auto_reply
 *  iCloud
 *   - wp_mcp_ai_test_icloud_live
 *   - wp_mcp_ai_test_icloud_auto_reply
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName

/**
 * Messaging-channels AJAX cluster (Pro addon).
 */
class Test_Messaging_Channels_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/** Pro class required for this suite. */
	const PRO_CLASS = 'WP_MCP_AI_Pro_Remote_Sites_Admin';

	/** Sets up shared state before any test in the class. */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( self::PRO_CLASS ) ) {
			self::markTestSkipped( 'Pro addon (WP_MCP_AI_Pro_Remote_Sites_Admin) is not loaded.' );
		}
	}

	// ---
	// Internal helper: cap-gate + nonce-gate checks for any messaging handler.
	// ---

	/**
	 * Assert that a handler rejects a subscriber.
	 *
	 * @param string $action AJAX action (without wp_ajax_ prefix).
	 * @param array  $extra  Any extra POST params needed alongside nonce.
	 * @param string $nonce  Nonce action string.
	 */
	private function assertRejectsSubscriber( string $action, array $extra, string $nonce ): void {
		$this->as_subscriber();
		$response = $this->dispatch(
			$action,
			array_merge( array( 'nonce' => wp_create_nonce( $nonce ) ), $extra )
		);
		$this->assertAjaxError( $response );
	}

	/**
	 * Assert that a handler rejects a bad nonce.
	 *
	 * @param string $action AJAX action.
	 * @param array  $extra  Any extra POST params.
	 */
	private function assertRejectsBadNonce( string $action, array $extra = array() ): void {
		$this->as_admin();
		$response = $this->dispatch(
			$action,
			array_merge( array( 'nonce' => 'bad_nonce' ), $extra )
		);
		$this->assertAjaxForbidden( $response );
	}

	// ---
	// wp_mcp_ai_fetch_whatsapp_phone_numbers
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_fetch_whatsapp_phone_numbers_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_fetch_whatsapp_phone_numbers' );
	}

	/** Guards against insufficient capabilities. */
	public function test_fetch_whatsapp_phone_numbers_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_fetch_whatsapp_phone_numbers',
			array(
				'business_account_id' => '123',
				'access_token'        => 'tok',
			),
			'wp_mcp_ai_fetch_whatsapp_phone_numbers'
		);
	}

	/** Validates the missing credentials parameter. */
	public function test_fetch_whatsapp_phone_numbers_validates_missing_credentials() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_fetch_whatsapp_phone_numbers',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_fetch_whatsapp_phone_numbers' ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Fetch whatsapp phone numbers stubs outbound http. */
	public function test_fetch_whatsapp_phone_numbers_stubs_outbound_http() {
		$this->stub_http_response(
			'graph.facebook.com',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							array(
								'id'                   => '12345',
								'display_phone_number' => '+1 555 000 0000',
								'verified_name'        => 'Test',
							),
						),
					)
				),
			)
		);
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_fetch_whatsapp_phone_numbers',
			array(
				'nonce'               => wp_create_nonce( 'wp_mcp_ai_fetch_whatsapp_phone_numbers' ),
				'business_account_id' => '12345',
				'access_token'        => 'test_token',
			)
		);
		$this->assertAjaxSuccess( $response );
		$data = $this->getResponseData( $response );
		$this->assertArrayHasKey( 'phone_numbers', $data );
	}

	// ---
	// wp_mcp_ai_test_whatsapp_live
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_whatsapp_live_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_whatsapp_live' );
	}

	/** Guards against insufficient capabilities. */
	public function test_whatsapp_live_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_whatsapp_live',
			array(
				'access_token'    => 'tok',
				'phone_number_id' => '123',
			),
			'wp_mcp_ai_test_whatsapp_live'
		);
	}

	/** Validates the missing credentials parameter. */
	public function test_whatsapp_live_validates_missing_credentials() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_test_whatsapp_live',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_test_whatsapp_live' ) )
		);
		// Expects an error (missing access_token / phone_number_id).
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_test_whatsapp_auto_reply
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_whatsapp_auto_reply_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_whatsapp_auto_reply' );
	}

	/** Guards against insufficient capabilities. */
	public function test_whatsapp_auto_reply_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_whatsapp_auto_reply',
			array(
				'access_token'    => 'tok',
				'phone_number_id' => '123',
				'assistant_id'    => '1',
			),
			'wp_mcp_ai_test_whatsapp_auto_reply'
		);
	}

	// ---
	// wp_mcp_ai_register_whatsapp_phone_number
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_register_whatsapp_phone_number_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_register_whatsapp_phone_number' );
	}

	/** Guards against insufficient capabilities. */
	public function test_register_whatsapp_phone_number_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_register_whatsapp_phone_number',
			array(
				'access_token'    => 'tok',
				'phone_number_id' => '123',
				'pin'             => '123456',
			),
			'wp_mcp_ai_register_whatsapp_phone_number'
		);
	}

	// ---
	// wp_mcp_ai_create_whatsapp_group
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_create_whatsapp_group_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_create_whatsapp_group' );
	}

	/** Guards against insufficient capabilities. */
	public function test_create_whatsapp_group_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_create_whatsapp_group',
			array(
				'access_token'    => 'tok',
				'phone_number_id' => '123',
				'group_name'      => 'G',
			),
			'wp_mcp_ai_create_whatsapp_group'
		);
	}

	// ---
	// wp_mcp_ai_test_telegram_live
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_telegram_live_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_telegram_live' );
	}

	/** Guards against insufficient capabilities. */
	public function test_telegram_live_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_telegram_live',
			array( 'bot_token' => 'tok' ),
			'wp_mcp_ai_test_telegram_live'
		);
	}

	/** Validates the missing bot token parameter. */
	public function test_telegram_live_validates_missing_bot_token() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_test_telegram_live',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_test_telegram_live' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_test_telegram_auto_reply
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_telegram_auto_reply_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_telegram_auto_reply' );
	}

	/** Guards against insufficient capabilities. */
	public function test_telegram_auto_reply_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_telegram_auto_reply',
			array(
				'bot_token'    => 'tok',
				'assistant_id' => '1',
			),
			'wp_mcp_ai_test_telegram_auto_reply'
		);
	}

	// ---
	// wp_mcp_ai_set_telegram_webhook
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_set_telegram_webhook_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_set_telegram_webhook' );
	}

	/** Guards against insufficient capabilities. */
	public function test_set_telegram_webhook_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_set_telegram_webhook',
			array(
				'bot_token'   => 'tok',
				'webhook_url' => 'https://example.com/wh',
			),
			'wp_mcp_ai_set_telegram_webhook'
		);
	}

	/** Validates the missing token parameter. */
	public function test_set_telegram_webhook_validates_missing_token() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_set_telegram_webhook',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_set_telegram_webhook' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_get_telegram_webhook_info
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_telegram_webhook_info_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_get_telegram_webhook_info' );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_telegram_webhook_info_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_get_telegram_webhook_info',
			array( 'bot_token' => 'tok' ),
			'wp_mcp_ai_get_telegram_webhook_info'
		);
	}

	/** Validates the missing token parameter. */
	public function test_get_telegram_webhook_info_validates_missing_token() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_get_telegram_webhook_info',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_get_telegram_webhook_info' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_register_telegram_commands
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_register_telegram_commands_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_register_telegram_commands' );
	}

	/** Guards against insufficient capabilities. */
	public function test_register_telegram_commands_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_register_telegram_commands',
			array(
				'bot_token' => 'tok',
				'commands'  => wp_json_encode( array() ),
			),
			'wp_mcp_ai_register_telegram_commands'
		);
	}

	// ---
	// wp_mcp_ai_generate_messenger_token
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_generate_messenger_token_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_generate_messenger_token' );
	}

	/** Guards against insufficient capabilities. */
	public function test_generate_messenger_token_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_generate_messenger_token',
			array(
				'app_id'     => 'x',
				'app_secret' => 'y',
				'code'       => 'z',
			),
			'wp_mcp_ai_generate_messenger_token'
		);
	}

	/** Validates the missing app id parameter. */
	public function test_generate_messenger_token_validates_missing_app_id() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_generate_messenger_token',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_generate_messenger_token' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_test_messenger_live
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_messenger_live_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_messenger_live' );
	}

	/** Guards against insufficient capabilities. */
	public function test_messenger_live_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_messenger_live',
			array(
				'access_token' => 'tok',
				'page_id'      => '123',
			),
			'wp_mcp_ai_test_messenger_live'
		);
	}

	// ---
	// wp_mcp_ai_test_messenger_auto_reply
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_messenger_auto_reply_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_messenger_auto_reply' );
	}

	/** Guards against insufficient capabilities. */
	public function test_messenger_auto_reply_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_messenger_auto_reply',
			array(
				'access_token' => 'tok',
				'page_id'      => '123',
				'assistant_id' => '1',
			),
			'wp_mcp_ai_test_messenger_auto_reply'
		);
	}

	// ---
	// wp_mcp_ai_test_google_chat_live
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_google_chat_live_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_google_chat_live' );
	}

	/** Guards against insufficient capabilities. */
	public function test_google_chat_live_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_google_chat_live',
			array( 'service_account_json' => '{}' ),
			'wp_mcp_ai_test_google_chat_live'
		);
	}

	/** Validates the missing credentials parameter. */
	public function test_google_chat_live_validates_missing_credentials() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_test_google_chat_live',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_test_google_chat_live' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_fetch_google_chat_spaces
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_fetch_google_chat_spaces_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_fetch_google_chat_spaces' );
	}

	/** Guards against insufficient capabilities. */
	public function test_fetch_google_chat_spaces_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_fetch_google_chat_spaces',
			array( 'service_account_json' => '{}' ),
			'wp_mcp_ai_fetch_google_chat_spaces'
		);
	}

	// ---
	// wp_mcp_ai_test_google_chat_auto_reply
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_google_chat_auto_reply_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_google_chat_auto_reply' );
	}

	/** Guards against insufficient capabilities. */
	public function test_google_chat_auto_reply_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_google_chat_auto_reply',
			array(
				'service_account_json' => '{}',
				'assistant_id'         => '1',
			),
			'wp_mcp_ai_test_google_chat_auto_reply'
		);
	}

	// ---
	// wp_mcp_ai_test_google_chat_incoming_trigger
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_google_chat_incoming_trigger_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_google_chat_incoming_trigger' );
	}

	/** Guards against insufficient capabilities. */
	public function test_google_chat_incoming_trigger_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_google_chat_incoming_trigger',
			array( 'service_account_json' => '{}' ),
			'wp_mcp_ai_test_google_chat_incoming_trigger'
		);
	}

	// ---
	// wp_mcp_ai_get_google_chat_webhook_log
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_google_chat_webhook_log_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_get_google_chat_webhook_log' );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_google_chat_webhook_log_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_get_google_chat_webhook_log',
			array(),
			'wp_mcp_ai_get_google_chat_webhook_log'
		);
	}

	/** Dispatches successfully on the happy path. */
	public function test_get_google_chat_webhook_log_happy_path() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_get_google_chat_webhook_log',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_get_google_chat_webhook_log' ) )
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_clear_google_chat_webhook_log
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_clear_google_chat_webhook_log_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_clear_google_chat_webhook_log' );
	}

	/** Guards against insufficient capabilities. */
	public function test_clear_google_chat_webhook_log_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_clear_google_chat_webhook_log',
			array(),
			'wp_mcp_ai_clear_google_chat_webhook_log'
		);
	}

	/** Dispatches successfully on the happy path. */
	public function test_clear_google_chat_webhook_log_happy_path() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_clear_google_chat_webhook_log',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_clear_google_chat_webhook_log' ) )
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_generate_teams_manifest
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_generate_teams_manifest_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_generate_teams_manifest' );
	}

	/** Guards against insufficient capabilities. */
	public function test_generate_teams_manifest_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_generate_teams_manifest',
			array(
				'app_id'   => 'aid',
				'bot_id'   => 'bid',
				'app_name' => 'My App',
			),
			'wp_mcp_ai_generate_teams_manifest'
		);
	}

	// ---
	// wp_mcp_ai_generate_teams_app_package
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_generate_teams_app_package_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_generate_teams_app_package' );
	}

	/** Guards against insufficient capabilities. */
	public function test_generate_teams_app_package_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_generate_teams_app_package',
			array(
				'app_id'   => 'aid',
				'bot_id'   => 'bid',
				'app_name' => 'My App',
			),
			'wp_mcp_ai_generate_teams_app_package'
		);
	}

	// ---
	// wp_mcp_ai_test_office365_live
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_office365_live_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_office365_live' );
	}

	/** Guards against insufficient capabilities. */
	public function test_office365_live_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_office365_live',
			array(
				'client_id'     => 'cid',
				'client_secret' => 'sec',
				'tenant_id'     => 'tid',
			),
			'wp_mcp_ai_test_office365_live'
		);
	}

	// ---
	// wp_mcp_ai_test_office365_auto_reply
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_office365_auto_reply_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_office365_auto_reply' );
	}

	/** Guards against insufficient capabilities. */
	public function test_office365_auto_reply_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_office365_auto_reply',
			array(
				'client_id'     => 'cid',
				'client_secret' => 'sec',
				'tenant_id'     => 'tid',
				'assistant_id'  => '1',
			),
			'wp_mcp_ai_test_office365_auto_reply'
		);
	}

	// ---
	// wp_mcp_ai_test_icloud_live
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_icloud_live_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_icloud_live' );
	}

	/** Guards against insufficient capabilities. */
	public function test_icloud_live_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_icloud_live',
			array(
				'apple_id'     => 'me@example.com',
				'app_password' => 'pwd',
			),
			'wp_mcp_ai_test_icloud_live'
		);
	}

	// ---
	// wp_mcp_ai_test_icloud_auto_reply
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_icloud_auto_reply_rejects_bad_nonce() {
		$this->assertRejectsBadNonce( 'wp_mcp_ai_test_icloud_auto_reply' );
	}

	/** Guards against insufficient capabilities. */
	public function test_icloud_auto_reply_rejects_subscriber() {
		$this->assertRejectsSubscriber(
			'wp_mcp_ai_test_icloud_auto_reply',
			array(
				'apple_id'     => 'me@example.com',
				'app_password' => 'pwd',
				'assistant_id' => '1',
			),
			'wp_mcp_ai_test_icloud_auto_reply'
		);
	}
}
