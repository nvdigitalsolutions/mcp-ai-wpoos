<?php
/**
 * Tests for the Gemini Corpus native RAG integration.
 *
 * Covers:
 * - META_CORPUS_NAME constant and post meta registration.
 * - sanitize_corpus_name_meta() sanitization helper.
 * - corpus_name inclusion in get_assistant_configuration().
 * - save_post handler persistence.
 * - REST validator corpus_name propagation in sanitize_options().
 * - build_payload() semanticRetriever injection when corpus_name is set.
 * - Gemini client CRUD methods: create_corpus, list_corpora, get_corpus, delete_corpus, query_corpus.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Gemini Corpus native RAG.
 */
class WP_MCP_AI_Gemini_Corpus_RAG_Test extends WP_UnitTestCase {

	/**
	 * Verify META_CORPUS_NAME constant is defined.
	 */
	public function test_meta_corpus_name_constant_exists() {
		$this->assertTrue( defined( 'WP_MCP_AI_Assistant_CPT::META_CORPUS_NAME' ) );
		$this->assertSame( '_wp_mcp_ai_corpus_name', WP_MCP_AI_Assistant_CPT::META_CORPUS_NAME );
	}

	/**
	 * Verify the corpus_name meta is registered for the assistant CPT.
	 */
	public function test_corpus_name_meta_registered() {
		do_action( 'init' );
		$registered = get_registered_meta_keys( 'post', WP_MCP_AI_Assistant_CPT::POST_TYPE );
		$this->assertArrayHasKey( WP_MCP_AI_Assistant_CPT::META_CORPUS_NAME, $registered );
		$this->assertSame( 'string', $registered[ WP_MCP_AI_Assistant_CPT::META_CORPUS_NAME ]['type'] );
	}

	/**
	 * Verify sanitize_corpus_name_meta() handles various input types.
	 */
	public function test_sanitize_corpus_name_meta() {
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_corpus_name_meta( 123 ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_corpus_name_meta( null ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_corpus_name_meta( '' ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_corpus_name_meta( array( 'key' => 'val' ) ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_corpus_name_meta( (object) array( 'name' => 'corpus' ) ) );
		$this->assertSame( 'corpora/my-corpus', WP_MCP_AI_Assistant_CPT::sanitize_corpus_name_meta( 'corpora/my-corpus' ) );
		$this->assertSame( 'my-corpus-id', WP_MCP_AI_Assistant_CPT::sanitize_corpus_name_meta( 'my-corpus-id' ) );
		// HTML tags are stripped.
		$this->assertSame( 'corpus-name', WP_MCP_AI_Assistant_CPT::sanitize_corpus_name_meta( '<b>corpus-name</b>' ) );
	}

	/**
	 * Verify corpus_name is included in get_assistant_configuration() output.
	 */
	public function test_get_assistant_configuration_includes_corpus_name() {
		$post_id = $this->factory()->post->create( array( 'post_type' => WP_MCP_AI_Assistant_CPT::POST_TYPE ) );
		update_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_CORPUS_NAME, 'corpora/test-corpus' );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post_id );

		$this->assertArrayHasKey( 'corpus_name', $config );
		$this->assertSame( 'corpora/test-corpus', $config['corpus_name'] );
	}

	/**
	 * Verify corpus_name defaults to an empty string when not set.
	 */
	public function test_get_assistant_configuration_corpus_name_defaults_to_empty() {
		$post_id = $this->factory()->post->create( array( 'post_type' => WP_MCP_AI_Assistant_CPT::POST_TYPE ) );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post_id );

		$this->assertArrayHasKey( 'corpus_name', $config );
		$this->assertSame( '', $config['corpus_name'] );
	}

	/**
	 * Verify the REST validator propagates corpus_name from assistant config to options.
	 */
	public function test_sanitize_options_propagates_corpus_name_from_config() {
		$validator      = new WP_MCP_AI_REST_Validator();
		$assistant_config = array(
			'corpus_name' => 'corpora/my-corpus',
			'provider'    => 'gemini',
		);

		$options = $validator->sanitize_options( array(), $assistant_config );

		$this->assertArrayHasKey( 'corpus_name', $options );
		$this->assertSame( 'corpora/my-corpus', $options['corpus_name'] );
	}

	/**
	 * Verify the REST validator uses the request-level corpus_name when explicitly provided.
	 */
	public function test_sanitize_options_request_corpus_name_overrides_config() {
		$validator        = new WP_MCP_AI_REST_Validator();
		$assistant_config = array(
			'corpus_name' => 'corpora/config-corpus',
			'provider'    => 'gemini',
		);

		$options = $validator->sanitize_options(
			array( 'corpus_name' => 'corpora/request-corpus' ),
			$assistant_config
		);

		$this->assertSame( 'corpora/request-corpus', $options['corpus_name'] );
	}

	/**
	 * Verify the REST validator sets corpus_name to empty string when absent from both.
	 */
	public function test_sanitize_options_corpus_name_defaults_to_empty() {
		$validator = new WP_MCP_AI_REST_Validator();

		$options = $validator->sanitize_options( array(), array( 'provider' => 'gemini' ) );

		$this->assertArrayHasKey( 'corpus_name', $options );
		$this->assertSame( '', $options['corpus_name'] );
	}

	/**
	 * Verify build_payload() injects a semanticRetriever tool when corpus_name is set.
	 */
	public function test_build_payload_injects_semantic_retriever_with_corpus_name() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client   = new WP_MCP_AI_Gemini_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the refund policy?',
			),
		);
		$options  = array(
			'corpus_name' => 'corpora/my-policies-corpus',
		);

		$captured_request = null;
		$filter_callback  = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array( array( 'text' => 'Our refund policy is 30 days.' ) ),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 5,
							'candidatesTokenCount' => 10,
						),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$client->create_chat_completion( $messages, $options );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'tools', $payload );

		// Find the semanticRetriever tool entry.
		$found_retrieval = false;
		foreach ( $payload['tools'] as $tool ) {
			if ( isset( $tool['retrieval']['semanticRetriever'] ) ) {
				$found_retrieval = true;
				$sr              = $tool['retrieval']['semanticRetriever'];
				$this->assertSame( 'corpora/my-policies-corpus', $sr['source'] );
				$this->assertArrayHasKey( 'query', $sr );
				$this->assertArrayHasKey( 'parts', $sr['query'] );
				$this->assertSame( 'What is the refund policy?', $sr['query']['parts'][0]['text'] );
				break;
			}
		}

		$this->assertTrue( $found_retrieval, 'semanticRetriever tool was not injected into the payload.' );
	}

	/**
	 * Verify build_payload() auto-prefixes bare corpus IDs with "corpora/".
	 */
	public function test_build_payload_auto_prefixes_bare_corpus_id() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client   = new WP_MCP_AI_Gemini_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Tell me about our products.',
			),
		);

		$captured_request = null;
		$filter_callback  = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array( 'args' => $args );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array( array( 'text' => 'Here are our products.' ) ),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 5,
							'candidatesTokenCount' => 10,
						),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$client->create_chat_completion( $messages, array( 'corpus_name' => 'bare-corpus-id' ) );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );

		$found_source = null;
		foreach ( $payload['tools'] as $tool ) {
			if ( isset( $tool['retrieval']['semanticRetriever']['source'] ) ) {
				$found_source = $tool['retrieval']['semanticRetriever']['source'];
				break;
			}
		}

		$this->assertSame( 'corpora/bare-corpus-id', $found_source );
	}

	/**
	 * Verify build_payload() does NOT inject semanticRetriever when corpus_name is absent.
	 */
	public function test_build_payload_no_retriever_without_corpus_name() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client   = new WP_MCP_AI_Gemini_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello world.',
			),
		);

		$captured_request = null;
		$filter_callback  = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array( 'args' => $args );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array( array( 'text' => 'Hello!' ) ),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 2,
							'candidatesTokenCount' => 5,
						),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$client->create_chat_completion( $messages, array() );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );

		// No retrieval tool should be present.
		if ( isset( $payload['tools'] ) ) {
			foreach ( $payload['tools'] as $tool ) {
				$this->assertArrayNotHasKey( 'retrieval', $tool, 'Unexpected retrieval tool found in payload.' );
			}
		}
	}

	/**
	 * Verify create_corpus() returns WP_Error when API key is missing.
	 */
	public function test_create_corpus_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->create_corpus( 'My Corpus' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $result->get_error_code() );
	}

	/**
	 * Verify create_corpus() returns WP_Error when display name is empty.
	 */
	public function test_create_corpus_requires_display_name() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->create_corpus( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_display_name', $result->get_error_code() );
	}

	/**
	 * Verify create_corpus() sends the correct API request and returns the decoded body.
	 */
	public function test_create_corpus_sends_correct_request() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;

		$corpus_response = array(
			'name'         => 'corpora/test-corpus-123',
			'display_name' => 'My Test Corpus',
		);

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request, $corpus_response ) {
			$captured_request = array( 'args' => $args, 'url' => $url );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $corpus_response ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = $client->create_corpus( 'My Test Corpus' );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'corpora', $captured_request['url'] );
		$this->assertIsArray( $result );
		$this->assertSame( 'corpora/test-corpus-123', $result['name'] );

		$sent_body = json_decode( $captured_request['args']['body'], true );
		$this->assertSame( 'My Test Corpus', $sent_body['display_name'] );
	}

	/**
	 * Verify list_corpora() returns WP_Error when API key is missing.
	 */
	public function test_list_corpora_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->list_corpora();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $result->get_error_code() );
	}

	/**
	 * Verify get_corpus() returns WP_Error when corpus name is empty.
	 */
	public function test_get_corpus_requires_corpus_name() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->get_corpus( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_corpus_name', $result->get_error_code() );
	}

	/**
	 * Verify get_corpus() prefixes bare IDs and requests the correct endpoint.
	 */
	public function test_get_corpus_prefixes_bare_id() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array( 'url' => $url );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'name' => 'corpora/my-bare-id' ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = $client->get_corpus( 'my-bare-id' );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'corpora/my-bare-id', $captured_request['url'] );
		$this->assertIsArray( $result );
	}

	/**
	 * Verify delete_corpus() returns WP_Error when corpus name is empty.
	 */
	public function test_delete_corpus_requires_corpus_name() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->delete_corpus( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_corpus_name', $result->get_error_code() );
	}

	/**
	 * Verify delete_corpus() returns true on a successful 200 response.
	 */
	public function test_delete_corpus_returns_true_on_success() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client          = new WP_MCP_AI_Gemini_Client();
		$filter_callback = function () {
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = $client->delete_corpus( 'corpora/my-corpus' );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertTrue( $result );
	}

	/**
	 * Verify query_corpus() returns WP_Error when corpus name is empty.
	 */
	public function test_query_corpus_requires_corpus_name() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->query_corpus( '', 'search query' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_corpus_name', $result->get_error_code() );
	}

	/**
	 * Verify query_corpus() returns WP_Error when query string is empty.
	 */
	public function test_query_corpus_requires_query_string() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->query_corpus( 'corpora/my-corpus', '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_query', $result->get_error_code() );
	}

	/**
	 * Verify query_corpus() sends query to the correct endpoint.
	 */
	public function test_query_corpus_sends_correct_request() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array( 'args' => $args, 'url' => $url );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'relevantChunks' => array() ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = $client->query_corpus( 'corpora/my-corpus', 'What is the refund policy?' );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'corpora/my-corpus:query', $captured_request['url'] );

		$sent_body = json_decode( $captured_request['args']['body'], true );
		$this->assertArrayHasKey( 'query', $sent_body );
		$this->assertSame( 'What is the refund policy?', $sent_body['query']['parts'][0]['text'] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'relevantChunks', $result );
	}

	/**
	 * Verify the API_CORPORA_ENDPOINT constant is defined correctly.
	 */
	public function test_api_corpora_endpoint_constant() {
		$this->assertTrue( defined( 'WP_MCP_AI_Gemini_Client::API_CORPORA_ENDPOINT' ) );
		$this->assertStringContainsString( 'corpora', WP_MCP_AI_Gemini_Client::API_CORPORA_ENDPOINT );
		$this->assertStringContainsString( 'generativelanguage.googleapis.com', WP_MCP_AI_Gemini_Client::API_CORPORA_ENDPOINT );
	}
}
