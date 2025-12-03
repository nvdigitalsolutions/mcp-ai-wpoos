<?php

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-gmail.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Tests for the Gmail search assistant tool.
 */
class WP_MCP_AI_Search_Gmail_Tool_Test extends WP_UnitTestCase {
	/**
	 * Prepare default settings for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Ensure the tool enforces capability checks.
	 */
	public function test_execute_requires_permission() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Gmail();
		$result = $tool->execute(
			array(
				'query' => 'subject:test',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gmail_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure credentials must be configured before executing the tool.
	 */
	public function test_execute_requires_credentials() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Gmail();
		$result = $tool->execute(
			array(
				'query' => 'from:test@example.com',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gmail_missing_credentials', $result->get_error_code() );
	}

	/**
	 * Ensure successful searches return structured message data.
	 */
	public function test_execute_returns_messages() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gmail_client_id']     = 'client-id';
		$settings['gmail_client_secret'] = 'client-secret';
		$settings['gmail_refresh_token'] = 'refresh-token';
		$settings['gmail_user_email']    = 'me';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$http_requests = array();
		$http_stub     = function ( $preempt, $args, $url ) use ( &$http_requests ) {
			$http_requests[] = array(
				'url'  => $url,
				'args' => $args,
			);

			if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'access_token' => 'mock-access-token' ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}

			if ( false !== strpos( $url, '/gmail/v1/users/me/messages?' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'messages'           => array(
								array(
									'id'       => 'abc123',
									'threadId' => 'thread-1',
								),
								array(
									'id'       => 'def456',
									'threadId' => 'thread-2',
								),
							),
							'resultSizeEstimate' => 2,
							'nextPageToken'      => 'next-token',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}

			if ( false !== strpos( $url, '/messages/abc123' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'id'           => 'abc123',
							'threadId'     => 'thread-1',
							'labelIds'     => array( 'INBOX', 'IMPORTANT' ),
							'snippet'      => 'First snippet',
							'internalDate' => '1700000000000',
							'payload'      => array(
								'headers' => array(
									array(
										'name'  => 'Subject',
										'value' => 'First Subject',
									),
									array(
										'name'  => 'From',
										'value' => 'Example Sender <sender@example.com>',
									),
									array(
										'name'  => 'To',
										'value' => 'recipient@example.com',
									),
									array(
										'name'  => 'Date',
										'value' => 'Tue, 14 May 2024 12:00:00 +0000',
									),
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}

			if ( false !== strpos( $url, '/messages/def456' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'id'           => 'def456',
							'threadId'     => 'thread-2',
							'labelIds'     => array( 'INBOX' ),
							'snippet'      => 'Second snippet',
							'internalDate' => '1700003600000',
							'payload'      => array(
								'headers' => array(
									array(
										'name'  => 'Subject',
										'value' => 'Second Subject',
									),
									array(
										'name'  => 'From',
										'value' => 'Another Sender <other@example.com>',
									),
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}

			return false;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Gmail();
		$result = $tool->execute(
			array(
				'query'       => 'from:sender@example.com',
				'max_results' => 2,
				'label_ids'   => array( 'INBOX' ),
				'page_token'  => 'prev-token',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertCount( 2, $result['messages'] );
		$this->assertSame( 'next-token', $result['next_page_token'] );
		$this->assertSame( 2, $result['result_size_estimate'] );

		$first = $result['messages'][0];
		$this->assertSame( 'abc123', $first['id'] );
		$this->assertSame( 'thread-1', $first['thread_id'] );
		$this->assertSame( 'First Subject', $first['subject'] );
		$this->assertSame( 'Example Sender <sender@example.com>', $first['from'] );
		$this->assertSame( 'recipient@example.com', $first['to'] );
		$this->assertSame( 'First snippet', $first['snippet'] );
		$this->assertSame( 1700000000, $first['timestamp'] );
		$this->assertStringContainsString( 'abc123', $first['permalink'] );
		$this->assertSame( array( 'INBOX', 'IMPORTANT' ), $first['labels'] );

		$second = $result['messages'][1];
		$this->assertSame( 'def456', $second['id'] );
		$this->assertSame( 'Second Subject', $second['subject'] );
		$this->assertSame( 'Another Sender <other@example.com>', $second['from'] );
		$this->assertSame( 'Second snippet', $second['snippet'] );
		$this->assertSame( 1700003600, $second['timestamp'] );

		$this->assertNotEmpty( $http_requests );
		$list_request = $http_requests[1];
		$this->assertStringContainsString( 'maxResults=2', $list_request['url'] );
		$this->assertStringContainsString( 'labelIds=INBOX', $list_request['url'] );
		$this->assertStringContainsString( 'pageToken=prev-token', $list_request['url'] );
		$this->assertStringContainsString( rawurlencode( 'from:sender@example.com' ), $list_request['url'] );
	}
}
