<?php
/**
 * Tests for the Google Calendar tool.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Google_Calendar_Tool_Test extends WP_UnitTestCase {

	/**
	 * Reset state between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The tool should block users lacking the required capability.
	 */
	public function test_execute_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Google_Calendar_Event();
		$result = $tool->execute(
			array(
				'summary'     => 'Unauthorized attempt',
				'start_time'  => '2024-07-04T10:00:00Z',
				'end_time'    => '2024-07-04T11:00:00Z',
				'calendar_id' => 'primary',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Verify that a supplied access token is used to create an event.
	 */
	public function test_execute_uses_access_token_filter() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Create_Google_Calendar_Event();

		$access_token_filter = function ( $token ) {
			return 'test-token';
		};

		add_filter( 'wp_mcp_ai_google_calendar_access_token', $access_token_filter, 10, 4 );

		$captured_request = null;
		$http_stub        = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'url'  => $url,
				'args' => $args,
			);

			return array(
				'body'     => wp_json_encode(
					array(
						'id'     => 'evt_123',
						'status' => 'confirmed',
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'summary'     => 'Team Sync',
				'description' => 'Weekly status meeting.',
				'start_time'  => '2024-07-04T10:00:00-05:00',
				'end_time'    => '2024-07-04T11:00:00-05:00',
				'calendar_id' => 'primary',
				'attendees'   => array(
					'one@example.com',
					array(
						'email' => 'two@example.com',
						'name'  => 'Two',
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		remove_filter( 'wp_mcp_ai_google_calendar_access_token', $access_token_filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'evt_123', $result['id'] );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( '/calendars/primary/events', $captured_request['url'] );
		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertSame( 'Bearer test-token', $captured_request['args']['headers']['Authorization'] );

		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertSame( 'Team Sync', $body['summary'] );
		$this->assertCount( 2, $body['attendees'] );
	}

	/**
	 * Service account credentials should be exchanged for a token before creating an event.
	 */
	public function test_execute_exchanges_service_account_credentials() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Create_Google_Calendar_Event();

		$key_resource = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$private_key  = '';
		openssl_pkey_export( $key_resource, $private_key );

		$credentials_filter = function () use ( $private_key ) {
			return array(
				'client_email'    => 'service-account@example.com',
				'private_key'     => $private_key,
				'delegated_email' => 'operator@example.com',
			);
		};

		add_filter( 'wp_mcp_ai_google_calendar_service_account_credentials', $credentials_filter, 10, 4 );

		$requests  = array();
		$http_stub = function ( $preempt, $args, $url ) use ( &$requests ) {
			$requests[] = array(
				'url'  => $url,
				'args' => $args,
			);

			if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
				$body = $args['body'];
				if ( is_string( $body ) ) {
					parse_str( $body, $body );
				}

				$this->assertArrayHasKey( 'assertion', $body );

				return array(
					'body'     => wp_json_encode(
						array(
							'access_token' => 'service-token',
							'expires_in'   => 3600,
						)
					),
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
				);
			}

			return array(
				'body'     => wp_json_encode(
					array(
						'id'     => 'evt_service',
						'status' => 'confirmed',
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'summary'     => 'Delegated Meeting',
				'start_time'  => '2024-07-05T09:00:00Z',
				'end_time'    => '2024-07-05T09:30:00Z',
				'calendar_id' => 'primary',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		remove_filter( 'wp_mcp_ai_google_calendar_service_account_credentials', $credentials_filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'evt_service', $result['id'] );

		$this->assertCount( 2, $requests );
		$this->assertStringContainsString( 'oauth2.googleapis.com/token', $requests[0]['url'] );
		$this->assertStringContainsString( '/calendars/primary/events', $requests[1]['url'] );

		$event_headers = $requests[1]['args']['headers'];
		$this->assertSame( 'Bearer service-token', $event_headers['Authorization'] );
	}
}
