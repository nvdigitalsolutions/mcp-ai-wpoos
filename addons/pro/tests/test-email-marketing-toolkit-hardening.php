<?php
/**
 * Tests for the email-marketing toolkit audit.
 *
 * The toolkit audit verified email-marketing clean across the four failure
 * classes: zero shell calls, zero provider client instantiations, zero
 * string-assuming parsing of provider responses (the tools are pure HTTP
 * wrappers around Brevo/Mailjet/Mailgun), and every advertised enum matches
 * its switch or the upstream API vocabulary.
 *
 * This suite locks the contracts that make the toolkit clean, and closes the
 * coverage gap for the Brevo trio (send / manage / statistics) plus the
 * blueprint importer, which previously had no tests at all:
 *  - credential gates and capability enforcement,
 *  - payload construction (recipient normalisation, parts, reply-to, tags),
 *  - HTTP hardening (is_wp_error, status-code, JSON-shape guards),
 *  - the action/type enum-to-switch routing contract, and
 *  - the blueprint slug enum versus the declared blueprint list.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group email-marketing
 * @group pro
 */

/**
 * Email-marketing toolkit hardening test case.
 */
class Test_Email_Marketing_Toolkit_Hardening extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		$this->admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		// Load the classes under test.
		$base = WP_MCP_AI_PRO_PATH . 'includes/tools/email-marketing/';

		require_once $base . 'class-wp-mcp-ai-pro-tool-send-brevo-email.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-manage-brevo-contacts.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-get-brevo-statistics.php';
		require_once $base . 'examples/class-wp-mcp-ai-tool-import-email-marketing-blueprint.php';

		if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		}

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		delete_option( 'wp_mcp_ai_credentials' );
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_brevo_pre_send' );
		remove_all_filters( 'wp_mcp_ai_brevo_request_args' );
		remove_all_filters( 'wp_mcp_ai_brevo_payload' );
		remove_all_filters( 'wp_mcp_ai_manage_brevo_contacts_capability' );
		remove_all_filters( 'wp_mcp_ai_send_brevo_email_capability' );
		remove_all_filters( 'wp_mcp_ai_get_brevo_statistics_capability' );
		parent::tearDown();
	}

	/**
	 * Store Brevo settings with a test API key.
	 *
	 * @param array $overrides Setting overrides.
	 * @return array The stored settings.
	 */
	private function brevo_settings( array $overrides = array() ) {
		$settings                     = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['brevo_api_key']    = 'key-test123';
		$settings['brevo_from_email'] = 'from@example.com';
		$settings                     = array_merge( $settings, $overrides );

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		return $settings;
	}

	/**
	 * Build a canned HTTP response for the pre_http_request mock.
	 *
	 * @param int   $code       HTTP status code.
	 * @param array $body_array Response body array (JSON-encoded).
	 * @return array Mock response array.
	 */
	private function http_response( $code, array $body_array ) {
		return array(
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $body_array ),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Build a canned HTTP response with a raw (non-JSON-encoded) body.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Raw response body.
	 * @return array Mock response array.
	 */
	private function raw_http_response( $code, $body ) {
		return array(
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'body'     => $body,
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Register a pre_http_request mock returning the given response and
	 * recording every requested URL. Any earlier mock is removed first.
	 *
	 * @param array|WP_Error $response     Response to return.
	 * @param array          $captured_url Reference that receives the requested URLs.
	 * @return void
	 */
	private function mock_http( $response, &$captured_url ) {
		remove_all_filters( 'pre_http_request' );
		$captured_url = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( $response, &$captured_url ) {
				$captured_url[] = $url;

				return $response;
			},
			10,
			3
		);
	}

	/**
	 * The send tool must reject calls without a configured Brevo API key.
	 */
	public function test_send_brevo_requires_api_key() {
		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_credentials', $result->get_error_code() );
	}

	/**
	 * The send tool must reject users without the required capability.
	 */
	public function test_send_brevo_enforces_capability() {
		$this->brevo_settings();

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $subscriber )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * The send tool must surface honest errors for missing required fields.
	 */
	public function test_send_brevo_requires_fields() {
		$this->brevo_settings();

		$tool = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();

		// Missing subject.
		$result = $tool->execute(
			array( 'to' => array( 'user@example.com' ) ),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_subject', $result->get_error_code() );

		// Missing recipients.
		$result = $tool->execute(
			array( 'subject' => 'Hello' ),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_recipients', $result->get_error_code() );

		// Missing body.
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
			),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_body', $result->get_error_code() );

		// Missing sender.
		$this->brevo_settings( array( 'brevo_from_email' => '' ) );
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_sender', $result->get_error_code() );
	}

	/**
	 * A successful send must post a correctly normalised payload to Brevo.
	 */
	public function test_send_brevo_sends_message() {
		$this->brevo_settings( array( 'brevo_from_name' => 'Sender' ) );

		$urls = array();

		$this->mock_http( $this->http_response( 201, array( 'messageId' => '<2024.1.2@example.com>' ) ), $urls );

		$captured = array();
		add_filter(
			'wp_mcp_ai_brevo_pre_send',
			function ( $preempt, $payload, $request_args ) use ( &$captured ) {
				$captured = array(
					'payload' => $payload,
					'args'    => $request_args,
				);

				return null;
			},
			10,
			4
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject'        => 'Hello',
				'to'             => array(
					'recipient@example.com',
					array(
						'email' => 'named@example.com',
						'name'  => 'Recipient',
					),
					// Duplicate of the first entry; must be deduplicated.
					'recipient@example.com',
				),
				'cc'             => array( 'cc@example.com' ),
				'bcc'            => array( 'bcc@example.com' ),
				'text'           => 'Plain body',
				'html'           => '<p>HTML body</p>',
				'reply_to_email' => 'reply@example.com',
				'reply_to_name'  => 'Reply',
				'tags'           => array(
					'one',
					'two',
					'three',
					'four',
					'five',
					'six',
					'seven',
					'eight',
					'nine',
					'ten',
					'eleven',
					'twelve',
				),
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertSame( '<2024.1.2@example.com>', $result['message_id'] );

		// The request must hit the Brevo SMTP endpoint.
		$this->assertNotEmpty( $urls );
		$this->assertSame( WP_MCP_AI_Pro_Tool_Send_Brevo_Email::API_ENDPOINT, $urls[0] );

		// The API key must travel in the api-key header.
		$this->assertSame( 'key-test123', $captured['args']['headers']['api-key'] );

		$payload = $captured['payload'];

		$this->assertSame( 'from@example.com', $payload['sender']['email'] );
		$this->assertSame( 'Sender', $payload['sender']['name'] );
		$this->assertSame( 'Hello', $payload['subject'] );

		// Recipient normalisation: strings and objects, deduplicated.
		$this->assertCount( 2, $payload['to'] );
		$this->assertSame( 'recipient@example.com', $payload['to'][0]['email'] );
		$this->assertSame( 'named@example.com', $payload['to'][1]['email'] );
		$this->assertSame( 'Recipient', $payload['to'][1]['name'] );

		$this->assertSame( 'cc@example.com', $payload['cc'][0]['email'] );
		$this->assertSame( 'bcc@example.com', $payload['bcc'][0]['email'] );

		$this->assertSame( 'Plain body', $payload['textContent'] );
		$this->assertSame( '<p>HTML body</p>', $payload['htmlContent'] );

		$this->assertSame( 'reply@example.com', $payload['replyTo']['email'] );
		$this->assertSame( 'Reply', $payload['replyTo']['name'] );

		// Tags must be truncated to 10.
		$this->assertCount( 10, $payload['tags'] );
		$this->assertSame( 'ten', $payload['tags'][9] );
	}

	/**
	 * A non-201 Brevo status must surface the API-provided message.
	 */
	public function test_send_brevo_surfaces_api_status_error() {
		$this->brevo_settings();

		$urls = array();
		$this->mock_http(
			$this->http_response(
				400,
				array(
					'code'    => 'invalid_parameter',
					'message' => 'Bad sender address.',
				)
			),
			$urls
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_http_status', $result->get_error_code() );
		$this->assertStringContainsString( 'Bad sender address.', $result->get_error_message() );
	}

	/**
	 * A transport-level failure must surface as an HTTP error.
	 */
	public function test_send_brevo_surfaces_http_failure() {
		$this->brevo_settings();

		$urls = array();
		$this->mock_http( new WP_Error( 'http_request_failed', 'Connection refused.' ), $urls );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$result = $tool->execute(
			array(
				'subject' => 'Hello',
				'to'      => array( 'user@example.com' ),
				'text'    => 'Body',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_http_error', $result->get_error_code() );
	}

	/**
	 * The manage tool must require credentials and reject unknown actions.
	 */
	public function test_manage_brevo_requires_key_and_valid_action() {
		$tool = new WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts();

		// No API key.
		$result = $tool->execute(
			array( 'action' => 'list_contacts' ),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_credentials', $result->get_error_code() );

		// Unknown action.
		$this->brevo_settings();
		$result = $tool->execute(
			array( 'action' => 'bogus_action' ),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_invalid_action', $result->get_error_code() );
	}

	/**
	 * Every action value advertised in the schema must route to a handler.
	 */
	public function test_manage_brevo_action_enum_routes_to_handlers() {
		$this->brevo_settings();

		$tool   = new WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts();
		$schema = $tool->get_parameters_schema();
		$enum   = $schema['properties']['action']['enum'];

		$this->assertSame(
			array(
				'list_contacts',
				'get_contact',
				'add_contact',
				'update_contact',
				'remove_contact',
				'list_lists',
				'add_to_list',
				'remove_from_list',
			),
			$enum
		);

		$urls = array();
		$this->mock_http(
			$this->http_response(
				200,
				array(
					'id'       => 1,
					'contacts' => array(),
					'lists'    => array(),
					'count'    => 0,
				)
			),
			$urls
		);

		foreach ( $enum as $action ) {
			$result = $tool->execute(
				array(
					'action'     => $action,
					'email'      => 'loop@example.com',
					'first_name' => 'Loop',
					'list_ids'   => array( 1 ),
				),
				array( 'user_id' => $this->admin_user )
			);

			if ( is_wp_error( $result ) ) {
				$this->assertNotSame( 'wp_mcp_ai_brevo_invalid_action', $result->get_error_code(), "Action '$action' did not route to a handler." );
			} else {
				$this->assertIsArray( $result );
			}
		}

		$this->assertNotEmpty( $urls );
	}

	/**
	 * List, add, and remove contact actions must hit the expected endpoints.
	 */
	public function test_manage_brevo_contact_paths() {
		$this->brevo_settings();

		$urls = array();
		$this->mock_http(
			$this->http_response(
				200,
				array(
					'contacts' => array( array( 'email' => 'a@example.com' ) ),
					'count'    => 1,
				)
			),
			$urls
		);

		$tool = new WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts();

		// List contacts.
		$result = $tool->execute(
			array( 'action' => 'list_contacts' ),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'a@example.com', $result['contacts'][0]['email'] );
		$this->assertStringContainsString( '/contacts', $urls[0] );

		// Remove a contact.
		$result = $tool->execute(
			array(
				'action' => 'remove_contact',
				'email'  => 'a@example.com',
			),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 'a@example.com', $result['email'] );
		$this->assertStringContainsString( '/contacts/a%40example.com', end( $urls ) );

		// Update without fields must fail honestly.
		$result = $tool->execute(
			array(
				'action' => 'update_contact',
				'email'  => 'a@example.com',
			),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_missing_update_data', $result->get_error_code() );

		// Add to list must fire one request per list ID.
		$urls = array();
		$this->mock_http( $this->http_response( 200, array( 'ok' => true ) ), $urls );

		$result = $tool->execute(
			array(
				'action'   => 'add_to_list',
				'email'    => 'a@example.com',
				'list_ids' => array(
					7,
					8,
				),
			),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertNotWPError( $result );
		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( '/contacts/lists/7/contacts/add', $urls[0] );
		$this->assertStringContainsString( '/contacts/lists/8/contacts/add', $urls[1] );
	}

	/**
	 * A non-2xx manage-tool response must surface the API error message.
	 */
	public function test_manage_brevo_surfaces_api_error() {
		$this->brevo_settings();

		$urls = array();
		$this->mock_http(
			$this->http_response(
				404,
				array( 'message' => 'Contact not found.' )
			),
			$urls
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts();
		$result = $tool->execute(
			array( 'action' => 'list_contacts' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_api_error', $result->get_error_code() );
		$this->assertStringContainsString( 'Contact not found.', $result->get_error_message() );
	}

	/**
	 * The statistics tool must route each type value to the right endpoint.
	 */
	public function test_get_brevo_statistics_routes_by_type() {
		$this->brevo_settings();

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Brevo_Statistics();
		$schema = $tool->get_parameters_schema();
		$this->assertSame(
			array(
				'campaigns',
				'transactional',
			),
			$schema['properties']['type']['enum']
		);

		// Default type routes to the campaigns list.
		$urls = array();
		$this->mock_http(
			$this->http_response(
				200,
				array(
					'campaigns' => array(),
					'count'     => 0,
				)
			),
			$urls
		);

		$result = $tool->execute( array(), array( 'user_id' => $this->admin_user ) );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '/emailCampaigns', $urls[0] );

		// A campaign ID routes to the per-campaign report.
		$urls = array();
		$this->mock_http( $this->http_response( 200, array( 'uniqueClicks' => 0 ) ), $urls );

		$result = $tool->execute(
			array( 'campaign_id' => 42 ),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 42, $result['campaign_id'] );
		$this->assertStringContainsString( '/emailCampaigns/42/sendReport', $urls[0] );

		// Transactional type routes to the SMTP aggregate report.
		$urls = array();
		$this->mock_http( $this->http_response( 200, array( 'reports' => array() ) ), $urls );

		$result = $tool->execute(
			array(
				'type'       => 'transactional',
				'start_date' => '2026-01-01',
				'end_date'   => '2026-01-31',
			),
			array( 'user_id' => $this->admin_user )
		);
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '/smtp/statistics/aggregatedReport', $urls[0] );
		$this->assertStringContainsString( 'startDate=2026-01-01', $urls[0] );
		$this->assertStringContainsString( 'endDate=2026-01-31', $urls[0] );
	}

	/**
	 * The statistics tool must reject non-2xx statuses and non-array bodies.
	 */
	public function test_get_brevo_statistics_surfaces_status_and_shape_errors() {
		$this->brevo_settings();

		$tool = new WP_MCP_AI_Pro_Tool_Get_Brevo_Statistics();

		// Non-200 status.
		$urls = array();
		$this->mock_http( $this->http_response( 500, array( 'message' => 'Server exploded.' ) ), $urls );

		$result = $tool->execute( array(), array( 'user_id' => $this->admin_user ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_http_status', $result->get_error_code() );
		$this->assertStringContainsString( 'Server exploded.', $result->get_error_message() );

		// Non-array JSON body.
		$urls = array();
		$this->mock_http( $this->raw_http_response( 200, '"a plain string, not an object"' ), $urls );

		$result = $tool->execute( array(), array( 'user_id' => $this->admin_user ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_brevo_invalid_response', $result->get_error_code() );
	}

	/**
	 * The blueprint importer must reject unknown blueprint slugs.
	 */
	public function test_import_blueprint_rejects_unknown_slug() {
		$tool   = new WP_MCP_AI_Tool_Import_Email_Marketing_Blueprint();
		$result = $tool->execute(
			array( 'blueprint' => 'not-a-real-blueprint' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'blueprint_not_found', $result->get_error_code() );
	}

	/**
	 * The blueprint enum must match the declared blueprint slug list.
	 */
	public function test_import_blueprint_enum_matches_declared_slugs() {
		$tool   = new WP_MCP_AI_Tool_Import_Email_Marketing_Blueprint();
		$schema = $tool->get_parameters_schema();

		$this->assertSame(
			WP_MCP_AI_Tool_Import_Email_Marketing_Blueprint::BLUEPRINT_SLUGS,
			$schema['properties']['blueprint']['enum']
		);
	}

	/**
	 * Capability flags must advertise the tool contract.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Pro_Tool_Send_Brevo_Email();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'network-dependent', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}
}
