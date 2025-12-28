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
	 * Test that profession identifiers are preserved in shortcode rendering.
	 *
	 * This ensures that when testing a profession with format "profession_XXX",
	 * the identifier is preserved through the shortcode rendering and passed
	 * to JavaScript, allowing the REST API to detect and load profession knowledge.
	 */
	public function test_profession_identifier_preserved_in_shortcode() {
		// Create a test profession.
		$profession_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
				'post_title'  => 'Test Profession',
			)
		);

		// Add some knowledge base content to the profession.
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_knowledge_base', 'This is test knowledge base content for the profession.' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_role_description', 'You are a test professional.' );

		// Render the shortcode with profession_XXX format.
		$profession_identifier = 'profession_' . $profession_id;

		wp_scripts()->reset();

		$markup = do_shortcode( sprintf( '[%s assistant="%s"]', WP_MCP_AI_Shortcode::SHORTCODE, $profession_identifier ) );
		$this->assertStringContainsString( 'data-wp-mcp-ai-chat', $markup );

		$handle = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		$this->assertArrayHasKey( $handle, wp_scripts()->registered );

		$registered = wp_scripts()->registered[ $handle ];

		// Get the inline script that contains the config.
		$instance_config = implode( "\n", $registered->extra['before'] ?? array() );

		// Parse the JSON config to verify the profession identifier is preserved.
		preg_match( '/wpMcpAiChatInstances\["[^"]+"\]\s*=\s*({.*?});/', $instance_config, $matches );
		$this->assertNotEmpty( $matches, 'Should find instance config in inline script.' );

		if ( ! empty( $matches[1] ) ) {
			$config = json_decode( $matches[1], true );
			$this->assertIsArray( $config, 'Instance config should be valid JSON.' );
			$this->assertArrayHasKey( 'assistantId', $config, 'Instance config should have assistantId key.' );

			// The critical assertion: assistantId should preserve the "profession_XXX" format.
			$this->assertSame( $profession_identifier, $config['assistantId'], 'assistantId should preserve the profession_ prefix for REST API to detect.' );
			$this->assertStringContainsString( 'profession_', $config['assistantId'], 'assistantId should contain profession_ prefix.' );
		}
	}

	/**
	 * Test that resolve_assistant_id preserves profession identifiers.
	 */
	public function test_resolve_assistant_id_preserves_profession_format() {
		// Create a test profession.
		$profession_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
				'post_title'  => 'Resolve Test Profession',
			)
		);

		// Test that profession_XXX format is preserved.
		$profession_identifier = 'profession_' . $profession_id;
		$resolved              = WP_MCP_AI_Shortcode::resolve_assistant_id( $profession_identifier );

		$this->assertSame( $profession_identifier, $resolved, 'resolve_assistant_id should preserve profession_ prefix.' );
		$this->assertIsString( $resolved, 'resolve_assistant_id should return string for profession identifiers.' );
	}

	/**
	 * Test that resolve_assistant_id returns 0 for invalid profession identifiers.
	 */
	public function test_resolve_assistant_id_rejects_invalid_profession() {
		// Test with non-existent profession ID.
		$invalid_identifier = 'profession_99999999';
		$resolved           = WP_MCP_AI_Shortcode::resolve_assistant_id( $invalid_identifier );

		$this->assertSame( 0, $resolved, 'resolve_assistant_id should return 0 for non-existent profession.' );
	}

	/**
	 * Test that profession title is correctly displayed in the chat label.
	 *
	 * This verifies that when a profession test (profession_XXX format) is used,
	 * the profession's title is displayed in the chat interface, not an empty string.
	 */
	public function test_profession_title_displayed_in_chat_label() {
		// Create a test profession with a specific title.
		$profession_title = 'Test Anthropologist';
		$profession_id    = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
				'post_title'  => $profession_title,
			)
		);

		// Render the shortcode with profession_XXX format.
		$profession_identifier = 'profession_' . $profession_id;
		$markup                = do_shortcode( sprintf( '[%s assistant="%s"]', WP_MCP_AI_Shortcode::SHORTCODE, $profession_identifier ) );

		// Verify the markup contains the profession title.
		$this->assertStringContainsString( $profession_title, $markup, 'Profession title should be displayed in the chat interface.' );

		// Verify the title is in the label element.
		$this->assertStringContainsString( 'wp-mcp-ai-chat__label', $markup, 'Chat label class should be present.' );

		// Parse the HTML to verify the title is in the correct location.
		$xpath  = $this->get_dom_xpath( $markup );
		$labels = $xpath->query( '//label[contains(@class, "wp-mcp-ai-chat__label")]' );
		$this->assertGreaterThan( 0, $labels->length, 'Should find the chat label element.' );

		if ( $labels->length > 0 ) {
			$label_text = trim( $labels->item( 0 )->textContent );
			$this->assertSame( $profession_title, $label_text, 'Label should contain the profession title.' );
		}
	}

	/**
	 * Test that the template attribute is rendered as a data attribute and CSS class.
	 */
	public function test_chat_shortcode_renders_template_attribute() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Template Test Assistant',
			)
		);

		// Test speech-bubbles template.
		$markup = do_shortcode( sprintf( '[%s assistant="%d" template="speech-bubbles"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-template="speech-bubbles"', $markup, 'Template data attribute should be present.' );
		$this->assertStringContainsString( 'wp-mcp-ai-chat--template-speech-bubbles', $markup, 'Template CSS class should be present.' );

		// Test compact template.
		$markup = do_shortcode( sprintf( '[%s assistant="%d" template="compact"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-template="compact"', $markup, 'Template data attribute should be present.' );
		$this->assertStringContainsString( 'wp-mcp-ai-chat--template-compact', $markup, 'Template CSS class should be present.' );

		// Test sidebar template.
		$markup = do_shortcode( sprintf( '[%s assistant="%d" template="sidebar"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-template="sidebar"', $markup, 'Template data attribute should be present.' );
		$this->assertStringContainsString( 'wp-mcp-ai-chat--template-sidebar', $markup, 'Template CSS class should be present.' );

		// Test classic template (default).
		$markup = do_shortcode( sprintf( '[%s assistant="%d" template="classic"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-template="classic"', $markup, 'Template data attribute should be present for classic.' );
		$this->assertStringNotContainsString( 'wp-mcp-ai-chat--template-classic', $markup, 'Classic template should not have modifier class.' );
	}

	/**
	 * Test that invalid template values fallback to classic.
	 */
	public function test_chat_shortcode_invalid_template_falls_back_to_classic() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Invalid Template Assistant',
			)
		);

		$markup = do_shortcode( sprintf( '[%s assistant="%d" template="invalid-template"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-template="classic"', $markup, 'Invalid template should fallback to classic.' );
		$this->assertStringNotContainsString( 'invalid-template', $markup, 'Invalid template name should not appear in output.' );
	}

	/**
	 * Test that template defaults to classic when not specified.
	 */
	public function test_chat_shortcode_template_defaults_to_classic() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Default Template Assistant',
			)
		);

		$markup = do_shortcode( sprintf( '[%s assistant="%d"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
		$this->assertStringContainsString( 'data-template="classic"', $markup, 'Template should default to classic when not specified.' );
	}
}
