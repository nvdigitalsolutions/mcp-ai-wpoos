<?php
/**
 * Tests for the Anthropic client count_tokens and list_models additions.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for count_tokens() and list_models() on WP_MCP_AI_Anthropic_Client.
 */
class WP_MCP_AI_Anthropic_Count_Tokens_Test extends WP_UnitTestCase {

	/**
	 * Restore default settings before each test.
	 */
	public function set_up() {
		parent::set_up();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Clean up HTTP filter after each test.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// list_models()
	// -------------------------------------------------------------------------

	/**
	 * list_models() must return a non-empty array.
	 */
	public function test_list_models_returns_array() {
		$client = new WP_MCP_AI_Anthropic_Client();
		$models = $client->list_models();

		$this->assertIsArray( $models );
		$this->assertNotEmpty( $models );
	}

	/**
	 * Each model entry must have id, name, and context_window keys.
	 */
	public function test_list_models_entries_have_required_keys() {
		$client = new WP_MCP_AI_Anthropic_Client();
		$models = $client->list_models();

		foreach ( $models as $model ) {
			$this->assertIsArray( $model );
			$this->assertArrayHasKey( 'id', $model );
			$this->assertArrayHasKey( 'name', $model );
			$this->assertArrayHasKey( 'context_window', $model );
			$this->assertNotEmpty( $model['id'] );
			$this->assertIsInt( $model['context_window'] );
			$this->assertGreaterThan( 0, $model['context_window'] );
		}
	}

	/**
	 * The static list must include both Claude 3 and Claude 4 model families.
	 */
	public function test_list_models_includes_claude_3_and_4() {
		$client = new WP_MCP_AI_Anthropic_Client();
		$ids    = array_column( $client->list_models(), 'id' );

		// Claude 3 family.
		$this->assertContains( 'claude-3-haiku-20240307', $ids );
		$this->assertContains( 'claude-3-5-sonnet-20241022', $ids );

		// Claude 4 family (per proposal).
		$has_claude4 = false;
		foreach ( $ids as $id ) {
			if ( str_contains( $id, 'claude-' ) && ( str_contains( $id, '-4' ) || str_contains( $id, 'sonnet-4' ) || str_contains( $id, 'opus-4' ) ) ) {
				$has_claude4 = true;
				break;
			}
		}
		$this->assertTrue( $has_claude4, 'list_models() must include at least one Claude 4 model' );
	}

	// -------------------------------------------------------------------------
	// count_tokens()
	// -------------------------------------------------------------------------

	/**
	 * count_tokens() must return an error when no API key is configured.
	 */
	public function test_count_tokens_requires_api_key() {
		$client   = new WP_MCP_AI_Anthropic_Client();
		$messages = array( array( 'role' => 'user', 'content' => 'Hello' ) );
		$result   = $client->count_tokens( $messages );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_anthropic_api_key', $result->get_error_code() );
	}

	/**
	 * count_tokens() must return an error when the messages array is empty.
	 */
	public function test_count_tokens_requires_messages() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Anthropic_Client();
		$result = $client->count_tokens( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_messages', $result->get_error_code() );
	}

	/**
	 * count_tokens() must call the /v1/messages/count_tokens endpoint.
	 */
	public function test_count_tokens_hits_correct_endpoint() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-haiku-20240307';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$captured_url = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'input_tokens' => 14 ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client   = new WP_MCP_AI_Anthropic_Client();
		$messages = array( array( 'role' => 'user', 'content' => 'Hello, Claude.' ) );
		$result   = $client->count_tokens( $messages );

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'count_tokens', $captured_url );
		$this->assertSame( 14, $result );
	}

	/**
	 * count_tokens() must return the input_tokens integer from the API response.
	 */
	public function test_count_tokens_returns_integer() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-haiku-20240307';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'input_tokens' => 42 ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client   = new WP_MCP_AI_Anthropic_Client();
		$messages = array( array( 'role' => 'user', 'content' => 'Count my tokens please.' ) );
		$result   = $client->count_tokens( $messages );

		$this->assertSame( 42, $result );
	}

	/**
	 * count_tokens() must include x-api-key and anthropic-version headers.
	 */
	public function test_count_tokens_sends_correct_headers() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key-abc';
		$defaults['anthropic_model']   = 'claude-3-haiku-20240307';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$captured_args = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'input_tokens' => 5 ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client   = new WP_MCP_AI_Anthropic_Client();
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );
		$client->count_tokens( $messages );

		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'headers', $captured_args );
		$this->assertArrayHasKey( 'x-api-key', $captured_args['headers'] );
		$this->assertSame( 'sk-ant-test-key-abc', $captured_args['headers']['x-api-key'] );
		$this->assertArrayHasKey( 'anthropic-version', $captured_args['headers'] );
	}

	/**
	 * count_tokens() must include system prompt when provided via options.
	 */
	public function test_count_tokens_includes_system_prompt() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-haiku-20240307';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$payload_sent ) {
				$payload_sent = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'input_tokens' => 20 ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client   = new WP_MCP_AI_Anthropic_Client();
		$messages = array( array( 'role' => 'user', 'content' => 'Hello' ) );
		$client->count_tokens( $messages, array( 'system_prompt' => 'You are a helpful assistant.' ) );

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'system', $payload_sent );
		$this->assertStringContainsString( 'helpful assistant', $payload_sent['system'] );
	}

	/**
	 * count_tokens() must return a WP_Error when the API response has no input_tokens field.
	 */
	public function test_count_tokens_handles_missing_input_tokens_field() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'unexpected_field' => 99 ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client   = new WP_MCP_AI_Anthropic_Client();
		$messages = array( array( 'role' => 'user', 'content' => 'Hello' ) );
		$result   = $client->count_tokens( $messages );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_anthropic_count_tokens_missing', $result->get_error_code() );
	}

	/**
	 * The API_COUNT_TOKENS constant must point to the correct Anthropic endpoint.
	 */
	public function test_api_count_tokens_constant_is_correct() {
		$this->assertStringContainsString( 'count_tokens', WP_MCP_AI_Anthropic_Client::API_COUNT_TOKENS );
		$this->assertStringContainsString( 'anthropic.com', WP_MCP_AI_Anthropic_Client::API_COUNT_TOKENS );
	}
}
