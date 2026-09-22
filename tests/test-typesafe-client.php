<?php
/**
 * Tests for WP_MCP_AI_Typesafe_Client.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for TypeSafe (Jev) decision client.
 */
class Test_Typesafe_Client extends WP_UnitTestCase {

	/**
	 * Client instance.
	 *
	 * @var WP_MCP_AI_Typesafe_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-decision-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-typesafe-client.php';

		$this->client = new WP_MCP_AI_Typesafe_Client();

		// Retries must not sleep inside the test suite.
		add_filter( 'wp_mcp_ai_typesafe_retry_sleep', '__return_zero' );

		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_typesafe_retry_sleep' );
		remove_all_filters( 'wp_mcp_ai_typesafe_cache_ttl' );
		remove_all_filters( 'wp_mcp_ai_typesafe_endpoint' );
		delete_option( 'wp_mcp_ai_settings' );
		wp_cache_flush();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Constants & accessors.
	// -------------------------------------------------------------------------

	/**
	 * Test class constants are set correctly.
	 */
	public function test_constants() {
		$this->assertEquals( 'https://api.typesafe.ai', WP_MCP_AI_Typesafe_Client::DEFAULT_BASE_URL );
		$this->assertEquals( '/v1/systemone', WP_MCP_AI_Typesafe_Client::API_ENDPOINT );
		$this->assertEquals( 'jev-latest', WP_MCP_AI_Typesafe_Client::DEFAULT_MODEL );
	}

	/**
	 * Test get_api_key() returns empty string when not configured.
	 */
	public function test_get_api_key_returns_empty_when_unconfigured() {
		$this->assertEmpty( $this->client->get_api_key() );
	}

	/**
	 * Test get_api_key() returns configured value.
	 */
	public function test_get_api_key_returns_configured_value() {
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_api_key' => 'sk-ts-test' ) );

		$this->assertEquals( 'sk-ts-test', $this->client->get_api_key() );
	}

	/**
	 * Test set_api_key() overrides the persisted key for the instance only.
	 */
	public function test_set_api_key_overrides_persisted_key() {
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_api_key' => 'sk-ts-persisted' ) );
		$this->client->set_api_key( 'sk-ts-override' );

		$this->assertEquals( 'sk-ts-override', $this->client->get_api_key() );
	}

	/**
	 * Test get_model() returns configured model.
	 */
	public function test_get_model_returns_configured_model() {
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_model' => 'jev-1.13.0' ) );

		$this->assertEquals( 'jev-1.13.0', $this->client->get_model() );
	}

	/**
	 * Test get_base_url() defaults to DEFAULT_BASE_URL and strips trailing slashes.
	 */
	public function test_get_base_url_defaults_and_strips_trailing_slash() {
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_base_url' => 'https://api.typesafe.ai/' ) );

		$this->assertEquals( WP_MCP_AI_Typesafe_Client::DEFAULT_BASE_URL, $this->client->get_base_url() );
	}

	/**
	 * Test get_base_url() honours a custom setting.
	 */
	public function test_get_base_url_honours_custom_setting() {
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_base_url' => 'https://proxy.example.com' ) );

		$this->assertEquals( 'https://proxy.example.com', $this->client->get_base_url() );
	}

	// -------------------------------------------------------------------------
	// decide() — error paths.
	// -------------------------------------------------------------------------

	/**
	 * Test decide() returns WP_Error with configure action when no key is set.
	 */
	public function test_decide_without_api_key_returns_error_with_action() {
		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Is this urgent?',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_typesafe_api_key', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'actions', $data );
	}

	/**
	 * Test decide() rejects a question with an invalid type.
	 */
	public function test_decide_rejects_invalid_question_type() {
		$this->client->set_api_key( 'sk-ts-test' );

		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'essay',
					'instructions' => 'Write something.',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_typesafe_invalid_question_type', $result->get_error_code() );
	}

	/**
	 * Test decide() rejects a question missing instructions.
	 */
	public function test_decide_rejects_missing_instructions() {
		$this->client->set_api_key( 'sk-ts-test' );

		$result = $this->client->decide( 'state', array( 'q' => array( 'type' => 'noul' ) ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_typesafe_missing_instructions', $result->get_error_code() );
	}

	/**
	 * Test decide() rejects an empty question map.
	 */
	public function test_decide_rejects_empty_questions() {
		$this->client->set_api_key( 'sk-ts-test' );

		$result = $this->client->decide( 'state', array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_typesafe_no_questions', $result->get_error_code() );
	}

	/**
	 * Test decide() rejects a score question with fewer than two levels.
	 */
	public function test_decide_rejects_score_with_single_level() {
		$this->client->set_api_key( 'sk-ts-test' );

		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'score',
					'instructions' => 'Rate it.',
					'criteria'     => array( 'Only one level' ),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_typesafe_invalid_criteria', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// decide() — request shape.
	// -------------------------------------------------------------------------

	/**
	 * Test decide() sends the flat { model, state, questions } body to /v1/systemone.
	 */
	public function test_decide_sends_flat_request_body() {
		$this->client->set_api_key( 'sk-ts-test' );

		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured ) {
				$captured = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-1.13.0',
							'answers' => array(
								'q' => array( 'noul' => 0.83 ),
							),
							'usage'   => array( 'input_tokens' => 42 ),
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->client->decide(
			array( 'message' => 'charged twice' ),
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Urgent?',
				),
			),
			array( 'model' => 'jev-1.13.0' )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '/v1/systemone', $captured['url'] );
		$this->assertEquals( 'Bearer sk-ts-test', $captured['args']['headers']['Authorization'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertEquals( 'jev-1.13.0', $body['model'] );
		$this->assertEquals( array( 'message' => 'charged twice' ), $body['state'] );
		$this->assertArrayHasKey( 'q', $body['questions'] );
		$this->assertEquals( 'noul', $body['questions']['q']['type'] );
	}

	// -------------------------------------------------------------------------
	// decide() — response normalisation.
	// -------------------------------------------------------------------------

	/**
	 * Test decide() normalises a full mixed-answer response.
	 */
	public function test_decide_normalises_mixed_answers() {
		$this->client->set_api_key( 'sk-ts-test' );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-1.13.0',
							'id'      => 'req_123',
							'answers' => array(
								'department'  => array(
									'choice'        => 'billing',
									'probabilities' => array(
										'billing'   => 0.9,
										'technical' => 0.1,
									),
									'confidence'    => 0.9,
								),
								'frustration' => array(
									'score'         => 1.43,
									'probabilities' => array( 0.1, 0.4, 0.5 ),
									'confidence'    => 0.71,
								),
								'urgent'      => array(
									'noul' => 0.83,
								),
							),
							'usage'   => array( 'input_tokens' => 200 ),
						)
					),
				);
			},
			10
		);

		$result = $this->client->decide(
			'state',
			array(
				'department'  => array(
					'type'         => 'choice',
					'instructions' => 'Which team?',
					'criteria'     => array(
						'billing'   => 'Billing',
						'technical' => 'Technical',
					),
				),
				'frustration' => array(
					'type'         => 'score',
					'instructions' => 'How frustrated?',
					'criteria'     => array( 'Calm', 'Civil', 'Angry' ),
				),
				'urgent'      => array(
					'type'         => 'noul',
					'instructions' => 'Urgent?',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'jev-1.13.0', $result['model'] );
		$this->assertEquals( 'req_123', $result['request_id'] );

		$this->assertEquals( 'choice', $result['answers']['department']['type'] );
		$this->assertEquals( 'billing', $result['answers']['department']['choice'] );
		$this->assertEquals( 0.9, $result['answers']['department']['confidence'] );

		$this->assertEquals( 'score', $result['answers']['frustration']['type'] );
		$this->assertEqualsWithDelta( 1.43, $result['answers']['frustration']['score'], 0.0001 );

		$this->assertEquals( 'noul', $result['answers']['urgent']['type'] );
		$this->assertEqualsWithDelta( 0.83, $result['answers']['urgent']['noul'], 0.0001 );

		$this->assertEquals( 200, $result['usage']['input_tokens'] );
		$this->assertEquals( 0, $result['usage']['output_tokens'] );
	}

	/**
	 * Test decide() falls back to the requested model when the response omits it.
	 */
	public function test_decide_falls_back_to_requested_model() {
		$this->client->set_api_key( 'sk-ts-test' );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'answers' => array( 'q' => array( 'noul' => 0.5 ) ),
						)
					),
				);
			},
			10
		);

		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Yes?',
				),
			),
			array( 'model' => 'jev-1.13.0' )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertEquals( 'jev-1.13.0', $result['model'] );
	}

	// -------------------------------------------------------------------------
	// decide() — HTTP error paths.
	// -------------------------------------------------------------------------

	/**
	 * Test decide() maps 401 to an auth error.
	 */
	public function test_decide_maps_401_to_auth_error() {
		$this->client->set_api_key( 'sk-ts-bad' );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Unauthorized' ) ) ),
				);
			},
			10
		);

		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Yes?',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_typesafe_auth_error', $result->get_error_code() );
	}

	/**
	 * Test decide() maps 429 to rate_limit_exceeded and honours retry-after.
	 */
	public function test_decide_maps_429_and_honours_retry_after() {
		$this->client->set_api_key( 'sk-ts-test' );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array( 'retry-after' => '30' ),
					'response' => array( 'code' => 429 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Slow down' ) ) ),
				);
			},
			10
		);

		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Yes?',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_rate_limit_exceeded', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertEquals( 30, $data['retry_after'] );
	}

	/**
	 * Test decide() returns invalid_response on malformed JSON.
	 */
	public function test_decide_returns_error_on_malformed_json() {
		$this->client->set_api_key( 'sk-ts-test' );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => 'not-json',
				);
			},
			10
		);

		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Yes?',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_typesafe_invalid_response', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Phase 0 fidelity — endpoint, structured fields, noul criteria.
	// -------------------------------------------------------------------------

	/**
	 * Test get_endpoint() defaults, honours the setting, and is filterable.
	 */
	public function test_get_endpoint_defaults_setting_and_filter() {
		$this->assertEquals( '/v1/systemone', $this->client->get_endpoint() );

		update_option( 'wp_mcp_ai_settings', array( 'typesafe_endpoint' => '/v1/decisions' ) );
		$this->assertEquals( '/v1/decisions', $this->client->get_endpoint() );

		update_option( 'wp_mcp_ai_settings', array() );
		add_filter(
			'wp_mcp_ai_typesafe_endpoint',
			static function () {
				return '/custom/route';
			}
		);
		$this->assertEquals( '/custom/route', $this->client->get_endpoint() );
	}

	/**
	 * Test decide() serialises noul criteria (true/false boundary descriptions).
	 */
	public function test_decide_serialises_noul_criteria() {
		$this->client->set_api_key( 'sk-ts-test' );

		$captured = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured ) {
				$captured = json_decode( $args['body'], true );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'model' => 'jev-1.13.0', 'answers' => array( 'q' => array( 'noul' => 0.7 ) ) ) ),
				);
			},
			10,
			2
		);

		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Urgent?',
					'criteria'     => array(
						'true'  => 'Explicitly time-sensitive.',
						'false' => 'No urgency expressed.',
					),
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'criteria', $captured['questions']['q'] );
		$this->assertEquals( 'Explicitly time-sensitive.', $captured['questions']['q']['criteria']['true'] );
	}

	/**
	 * Test decide() accepts structured (EntryType) instructions.
	 */
	public function test_decide_accepts_structured_fields() {
		$this->client->set_api_key( 'sk-ts-test' );

		$captured = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured ) {
				$captured = json_decode( $args['body'], true );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'model' => 'jev-1.13.0', 'answers' => array( 'q' => array( 'noul' => 0.5 ) ) ) ),
				);
			},
			10,
			2
		);

		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => array(
						'question' => 'Is the sender domain legitimate?',
						'focus'    => array( 'domain', 'display_name' ),
					),
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $captured['questions']['q']['instructions'] );
		$this->assertEquals( 'Is the sender domain legitimate?', $captured['questions']['q']['instructions']['question'] );
	}

	/**
	 * Test decide() rejects an empty noul criteria map.
	 */
	public function test_decide_rejects_empty_noul_criteria() {
		$this->client->set_api_key( 'sk-ts-test' );

		$result = $this->client->decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Urgent?',
					'criteria'     => array(),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_typesafe_invalid_criteria', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Phase 0 fidelity — retries.
	// -------------------------------------------------------------------------

	/**
	 * Test decide() retries a 429 and succeeds on the second attempt.
	 */
	public function test_decide_retries_then_succeeds() {
		$this->client->set_api_key( 'sk-ts-test' );

		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				$calls++;

				if ( 1 === $calls ) {
					return array(
						'headers'  => array( 'retry-after' => '1' ),
						'response' => array( 'code' => 429 ),
						'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Slow down' ) ) ),
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'model' => 'jev-1.13.0', 'answers' => array( 'q' => array( 'noul' => 0.9 ) ) ) ),
				);
			},
			10
		);

		$result = $this->client->decide( 'state', array( 'q' => array( 'type' => 'noul', 'instructions' => 'Yes?' ) ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 2, $calls );
	}

	/**
	 * Test decide() never retries 401 auth errors.
	 */
	public function test_decide_does_not_retry_auth_errors() {
		$this->client->set_api_key( 'sk-ts-bad' );

		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				$calls++;

				return array(
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Unauthorized' ) ) ),
				);
			},
			10
		);

		$result = $this->client->decide( 'state', array( 'q' => array( 'type' => 'noul', 'instructions' => 'Yes?' ) ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_typesafe_auth_error', $result->get_error_code() );
		$this->assertEquals( 1, $calls );
	}

	// -------------------------------------------------------------------------
	// Phase 0 fidelity — opt-in decision cache.
	// -------------------------------------------------------------------------

	/**
	 * Test decide() serves identical requests from the opt-in cache at zero cost.
	 */
	public function test_decide_serves_identical_requests_from_cache() {
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_api_key' => 'sk-ts-test', 'enable_typesafe_cache' => true ) );

		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				$calls++;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'model' => 'jev-1.13.0', 'answers' => array( 'q' => array( 'noul' => 0.9 ) ), 'usage' => array( 'input_tokens' => 50 ) ) ),
				);
			},
			10
		);

		$questions = array( 'q' => array( 'type' => 'noul', 'instructions' => 'Yes?' ) );

		$first  = $this->client->decide( 'state', $questions );
		$second = $this->client->decide( 'state', $questions );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertEquals( 1, $calls );

		$this->assertArrayNotHasKey( 'cached', $first );
		$this->assertTrue( ! empty( $second['cached'] ) );
		$this->assertEquals( 0, $second['usage']['input_tokens'] );

		$this->assertTrue( $this->client->cache_enabled() );
	}

	/**
	 * Test decide() skips the cache entirely when disabled.
	 */
	public function test_decide_skips_cache_when_disabled() {
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_api_key' => 'sk-ts-test' ) );

		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				$calls++;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'model' => 'jev-1.13.0', 'answers' => array( 'q' => array( 'noul' => 0.9 ) ) ) ),
				);
			},
			10
		);

		$questions = array( 'q' => array( 'type' => 'noul', 'instructions' => 'Yes?' ) );
		$this->client->decide( 'state', $questions );
		$this->client->decide( 'state', $questions );

		remove_all_filters( 'pre_http_request' );

		$this->assertEquals( 2, $calls );
		$this->assertFalse( $this->client->cache_enabled() );
	}

	/**
	 * Test decide() does not serve expired cache entries.
	 */
	public function test_decide_cache_respects_ttl() {
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_api_key' => 'sk-ts-test', 'enable_typesafe_cache' => true ) );
		add_filter(
			'wp_mcp_ai_typesafe_cache_ttl',
			static function () {
				return 1;
			}
		);

		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				$calls++;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'model' => 'jev-1.13.0', 'answers' => array( 'q' => array( 'noul' => 0.9 ) ) ) ),
				);
			},
			10
		);

		$questions = array( 'q' => array( 'type' => 'noul', 'instructions' => 'Yes?' ) );
		$this->client->decide( 'state', $questions );
		$this->client->decide( 'state', $questions ); // Cache hit.
		sleep( 2 );
		$this->client->decide( 'state', $questions ); // Expired → network call.

		remove_all_filters( 'pre_http_request' );

		$this->assertEquals( 2, $calls );
	}

	// -------------------------------------------------------------------------
	// Misc surfaces.
	// -------------------------------------------------------------------------

	/**
	 * Test list_models() returns the known ids and honours a custom configured model.
	 */
	public function test_list_models_returns_known_ids() {
		update_option( 'wp_mcp_ai_settings', array( 'typesafe_model' => 'jev-1.13.0' ) );

		$models = $this->client->list_models();

		$this->assertContains( 'jev-latest', $models );
		$this->assertContains( 'jev-preview', $models );
		$this->assertContains( 'jev-1.13.0', $models );
	}

	/**
	 * Test get_provider_slug() returns 'typesafe'.
	 */
	public function test_get_provider_slug() {
		$this->assertEquals( 'typesafe', $this->client->get_provider_slug() );
	}

	/**
	 * Test test_connection() returns WP_Error when no key is configured.
	 */
	public function test_test_connection_without_key_returns_error() {
		$result = $this->client->test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_typesafe_api_key', $result->get_error_code() );
	}

	/**
	 * Test test_connection() reports success on a 200 response.
	 */
	public function test_test_connection_success() {
		$this->client->set_api_key( 'sk-ts-test' );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-1.13.0',
							'answers' => array(),
						)
					),
				);
			},
			10
		);

		$result = $this->client->test_connection();

		remove_all_filters( 'pre_http_request' );

		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
	}
}
