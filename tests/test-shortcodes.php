<?php
/**
 * Test coverage for the shortcode front-end integrations.
 *
 * @package WP_MCP_AI\Tests
 */

class Test_Shortcodes extends WP_UnitTestCase {
	/**
	 * Administrator user ID used for capability checks.
	 *
	 * @var int
	 */
	protected $admin_id;

	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		wp_scripts()->reset();
		wp_scripts()->remove( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		wp_styles()->reset();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		do_action( 'init' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Retrieve the DOMXPath helper for inspecting generated markup.
	 *
	 * @param string $html Rendered HTML snippet.
	 * @return DOMXPath
	 */
	protected function get_dom_xpath( $html ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Ensure that rendering the chat shortcode enqueues the assets once.
	 */
	public function test_chat_shortcode_enqueues_scripts_once() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		$chat_markup = do_shortcode( sprintf( '[%s assistant="%d" allow_guests="1"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-wp-mcp-ai-chat', $chat_markup );

		$this->assertTrue( wp_script_is( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'enqueued' ) );

		$script_counts = array_count_values( wp_scripts()->queue );
		$handle        = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		$this->assertArrayHasKey( $handle, $script_counts );
		$this->assertSame( 1, $script_counts[ $handle ], sprintf( '%s should be enqueued exactly once.', $handle ) );

		wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
		$this->assertTrue( wp_style_is( WP_MCP_AI_Shortcode::STYLE_HANDLE, 'enqueued' ) );
	}

	/**
	 * Ensure the localized script exposes the REST nonce for same-origin requests.
	 */
	public function test_chat_shortcode_localizes_rest_nonce() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Nonce Assistant',
			)
		);

		wp_scripts()->reset();

		$markup = do_shortcode( sprintf( '[%s assistant="%d"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-wp-mcp-ai-chat', $markup );

		$handle = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		$this->assertArrayHasKey( $handle, wp_scripts()->registered );

		$registered = wp_scripts()->registered[ $handle ];

		$localised_data = $registered->extra['data'] ?? array();
		if ( is_string( $localised_data ) ) {
			$localised_data = array( $localised_data );
		}
		$localised = implode( "\n", $localised_data );
		$this->assertMatchesRegularExpression( '/"nonce":"[^"]+"/', $localised );

		$instance_config = implode( "\n", $registered->extra['before'] ?? array() );
		$this->assertStringNotContainsString( '"guestToken"', $instance_config, 'Guest tokens should not be present when allow_guests is disabled.' );
	}

	/**
	 * Ensure allowing guests injects the guest token into the instance config.
	 */
	public function test_chat_shortcode_includes_guest_token_when_enabled() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Guest Token Assistant',
			)
		);

		wp_scripts()->reset();

		$markup = do_shortcode( sprintf( '[%s assistant="%d" allow_guests="true"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-wp-mcp-ai-chat', $markup );

		$handle = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		$this->assertArrayHasKey( $handle, wp_scripts()->registered );

		$registered = wp_scripts()->registered[ $handle ];

		$instance_config = implode( "\n", $registered->extra['before'] ?? array() );
		$this->assertMatchesRegularExpression( '/"guestToken":"[A-Za-z0-9]+"/', $instance_config );

		$localised_data = $registered->extra['data'] ?? array();
		if ( is_string( $localised_data ) ) {
			$localised_data = array( $localised_data );
		}
		$localised = implode( "\n", $localised_data );
		$this->assertMatchesRegularExpression( '/"nonce":"[^"]+"/', $localised, 'Nonce should remain present for uploads and downloads.' );
	}

	/**
	 * Ensure the save_transcript attribute toggles transcript persistence in the front-end config.
	 */
	public function test_chat_shortcode_save_transcript_attribute_disables_storage() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Transcript Toggle Assistant',
			)
		);

		wp_scripts()->reset();

		$chat_markup = do_shortcode( sprintf( '[%s assistant="%d" save_transcript="false"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-wp-mcp-ai-chat', $chat_markup );

		$handle = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		$this->assertArrayHasKey( $handle, wp_scripts()->registered );
		$registered = wp_scripts()->registered[ $handle ];
		$this->assertArrayHasKey( 'before', $registered->extra );
		$inline = implode( "\n", $registered->extra['before'] );

		$this->assertStringContainsString( '"saveTranscript":false', $inline );
		$this->assertMatchesRegularExpression( '/"sessionKey":"[a-z0-9\-]+"/i', $inline );
	}

	/**
	 * Ensure the transcription controls remain visible for users that can upload files.
	 */
	public function test_chat_shortcode_transcription_controls_visible_for_upload_capability() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Transcription Enabled Assistant',
			)
		);

		$chat_markup = do_shortcode( sprintf( '[%s assistant="%d"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );

		$xpath        = $this->get_dom_xpath( $chat_markup );
		$button_nodes = $xpath->query( '//button[contains(@class, "wp-mcp-ai-chat__transcribe")]' );
		$this->assertSame( 1, $button_nodes->length, 'Expected a single transcription button to be rendered.' );

		$button = $button_nodes->item( 0 );
		$this->assertFalse( $button->hasAttribute( 'hidden' ), 'Transcription button should be visible when uploads are allowed.' );
		$this->assertFalse( $button->hasAttribute( 'disabled' ), 'Transcription button should be enabled when uploads are allowed.' );

		$input_nodes = $xpath->query( '//input[contains(@class, "wp-mcp-ai-chat__transcribe-input")]' );
		$this->assertSame( 1, $input_nodes->length, 'Expected a transcription file input to be rendered.' );
		$this->assertFalse( $input_nodes->item( 0 )->hasAttribute( 'disabled' ), 'Transcription file input should be enabled for upload-capable users.' );
	}

	/**
	 * Ensure the transcription controls are hidden and disabled when uploads are disallowed.
	 */
	public function test_chat_shortcode_transcription_controls_hidden_without_upload_capability() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Transcription Restricted Assistant',
			)
		);

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		wp_scripts()->reset();

		$chat_markup = do_shortcode( sprintf( '[%s assistant="%d" allow_guests="1"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );

		$xpath        = $this->get_dom_xpath( $chat_markup );
		$button_nodes = $xpath->query( '//button[contains(@class, "wp-mcp-ai-chat__transcribe")]' );
		$this->assertSame( 1, $button_nodes->length, 'Expected a single transcription button to be rendered.' );

		$button = $button_nodes->item( 0 );
		$this->assertTrue( $button->hasAttribute( 'hidden' ), 'Transcription button should be hidden when uploads are disallowed.' );
		$this->assertTrue( $button->hasAttribute( 'disabled' ), 'Transcription button should be disabled when uploads are disallowed.' );

		$input_nodes = $xpath->query( '//input[contains(@class, "wp-mcp-ai-chat__transcribe-input")]' );
		$this->assertSame( 1, $input_nodes->length, 'Expected a transcription file input to be rendered.' );
		$this->assertTrue( $input_nodes->item( 0 )->hasAttribute( 'disabled' ), 'Transcription file input should be disabled when uploads are disallowed.' );

		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Ensure the chat stylesheet can be enqueued when requested.
	 */
	public function test_chat_stylesheet_can_be_enqueued() {
		wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );

		$this->assertTrue( wp_style_is( WP_MCP_AI_Shortcode::STYLE_HANDLE, 'enqueued' ) );
	}

	/**
	 * Ensure guest access tokens cannot surface non-public attachments via the search tool.
	 */
	public function test_guest_token_attachment_search_only_returns_public_files() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Guest Knowledge Assistant',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'search_attachments' ) );

		$public_parent = self::factory()->post->create(
			array(
				'post_author' => $this->admin_id,
				'post_status' => 'publish',
			)
		);

		$private_parent = self::factory()->post->create(
			array(
				'post_author' => $this->admin_id,
				'post_status' => 'private',
			)
		);

		$public_upload = wp_upload_bits( 'guest-public-' . uniqid() . '.txt', null, 'Public guest file' );
		$this->assertIsArray( $public_upload );
		$this->assertArrayHasKey( 'file', $public_upload );
		$this->assertFalse( $public_upload['error'] );

		$public_id = self::factory()->attachment->create_upload_object( $public_upload['file'], $public_parent );
		wp_update_post(
			array(
				'ID'             => $public_id,
				'post_title'     => 'Guest Visible File',
				'post_author'    => $this->admin_id,
				'post_mime_type' => 'text/plain',
			)
		);

		$private_upload = wp_upload_bits( 'guest-private-' . uniqid() . '.txt', null, 'Hidden guest file' );
		$this->assertIsArray( $private_upload );
		$this->assertArrayHasKey( 'file', $private_upload );
		$this->assertFalse( $private_upload['error'] );

		$private_id = self::factory()->attachment->create_upload_object( $private_upload['file'], $private_parent );
		wp_update_post(
			array(
				'ID'             => $private_id,
				'post_title'     => 'Guest Hidden File',
				'post_author'    => $this->admin_id,
				'post_parent'    => $private_parent,
				'post_mime_type' => 'text/plain',
			)
		);

		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );
		$this->assertNotEmpty( $guest_token );
		$this->assertSame( $assistant_id, WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, $assistant_id ) );

		wp_set_current_user( 0 );

		rest_get_server();
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'tool', 'search_attachments' );
		$request->set_param( 'guest_token', $guest_token );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 401, $response->get_status(), 'Guest tokens should not gain direct tool access.' );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'wp_mcp_ai_anonymous_user', $data['code'] );

		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Ensure the instance config includes restNonce for cron-status endpoint authentication.
	 */
	public function test_chat_shortcode_instance_config_includes_rest_nonce() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'RestNonce Config Assistant',
			)
		);

		wp_scripts()->reset();

		$markup = do_shortcode( sprintf( '[%s assistant="%d"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-wp-mcp-ai-chat', $markup );

		$handle = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		$this->assertArrayHasKey( $handle, wp_scripts()->registered );

		$registered = wp_scripts()->registered[ $handle ];

		// Check instance config has restNonce for cron-status authentication.
		$instance_config = implode( "\n", $registered->extra['before'] ?? array() );
		$this->assertMatchesRegularExpression( '/"restNonce":"[^"]+"/', $instance_config, 'Instance config should include restNonce for cron-status endpoint.' );

		// Parse the JSON to verify it's a valid nonce.
		preg_match( '/wpMcpAiChatInstances\["[^"]+"\]\s*=\s*({.*?});/', $instance_config, $matches );
		if ( ! empty( $matches[1] ) ) {
			$config = json_decode( $matches[1], true );
			$this->assertIsArray( $config, 'Instance config should be valid JSON.' );
			$this->assertArrayHasKey( 'restNonce', $config, 'Instance config should have restNonce key.' );
			$this->assertNotEmpty( $config['restNonce'], 'restNonce should not be empty.' );
			$this->assertTrue( wp_verify_nonce( $config['restNonce'], 'wp_rest' ), 'restNonce should be a valid wp_rest nonce.' );
		}
	}

	/**
	 * Ensure the instance config includes restUrl for async tool polling.
	 */
	public function test_chat_shortcode_instance_config_includes_rest_url() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'RestUrl Config Assistant',
			)
		);

		wp_scripts()->reset();

		$markup = do_shortcode( sprintf( '[%s assistant="%d"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-wp-mcp-ai-chat', $markup );

		$handle = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		$this->assertArrayHasKey( $handle, wp_scripts()->registered );

		$registered = wp_scripts()->registered[ $handle ];

		// Check instance config has restUrl for async tool polling.
		$instance_config = implode( "\n", $registered->extra['before'] ?? array() );
		$this->assertMatchesRegularExpression( '/"restUrl":"[^"]+"/', $instance_config, 'Instance config should include restUrl for async tool polling.' );

		// Parse the JSON to verify it's a valid URL.
		preg_match( '/wpMcpAiChatInstances\["[^"]+"\]\s*=\s*({.*?});/', $instance_config, $matches );
		if ( ! empty( $matches[1] ) ) {
			$config = json_decode( $matches[1], true );
			$this->assertIsArray( $config, 'Instance config should be valid JSON.' );
			$this->assertArrayHasKey( 'restUrl', $config, 'Instance config should have restUrl key.' );
			$this->assertNotEmpty( $config['restUrl'], 'restUrl should not be empty.' );
			$this->assertStringContainsString( '/wp-json/mcp-ai/v1', $config['restUrl'], 'restUrl should point to the MCP AI REST namespace.' );
		}
	}

	/**
	 * Ensure the global localized script includes toolsEndpoint for voice chat and transcription.
	 */
	public function test_chat_shortcode_localizes_tools_endpoint() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Tools Endpoint Assistant',
			)
		);

		wp_scripts()->reset();

		$markup = do_shortcode( sprintf( '[%s assistant="%d"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-wp-mcp-ai-chat', $markup );

		$handle = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		$this->assertArrayHasKey( $handle, wp_scripts()->registered );

		$registered = wp_scripts()->registered[ $handle ];

		// Check global localized data has toolsEndpoint.
		$localised_data = $registered->extra['data'] ?? array();
		if ( is_string( $localised_data ) ) {
			$localised_data = array( $localised_data );
		}
		$localised = implode( "\n", $localised_data );

		// Verify toolsEndpoint is present in global config.
		$this->assertMatchesRegularExpression( '/"toolsEndpoint":"[^"]+"/', $localised, 'Global config should include toolsEndpoint.' );

		// Parse and validate the toolsEndpoint URL.
		if ( preg_match( '/var wpMcpAiChat\s*=\s*({.*?});/', $localised, $matches ) ) {
			$global_config = json_decode( $matches[1], true );
			$this->assertIsArray( $global_config, 'Global config should be valid JSON.' );
			$this->assertArrayHasKey( 'toolsEndpoint', $global_config, 'Global config should have toolsEndpoint key.' );
			$this->assertNotEmpty( $global_config['toolsEndpoint'], 'toolsEndpoint should not be empty.' );
			$this->assertStringContainsString( '/wp-json/mcp-ai/v1/tools', $global_config['toolsEndpoint'], 'toolsEndpoint should point to the tools endpoint.' );
		}
	}
}
