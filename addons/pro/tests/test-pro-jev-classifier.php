<?php
/**
 * Tests for the Pro Jev Classifier service.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Pro_Jev_Classifier.
 */
class Test_Pro_Jev_Classifier extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
		}

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-decision-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-typesafe-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openrouter-client.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-classifier.php';

		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'wp_mcp_ai_jev_classifier_enabled' );
		remove_all_filters( 'pre_http_request' );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Helper: build a source list of $n entries.
	 *
	 * @param int $n Number of sources.
	 * @return array
	 */
	private function build_sources( $n ) {
		$sources = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$sources[] = array(
				'url'     => 'https://example.com/source-' . $i,
				'title'   => 'Source ' . $i,
				'snippet' => 'Snippet for source ' . $i,
			);
		}

		return $sources;
	}

	// -------------------------------------------------------------------------
	// Availability.
	// -------------------------------------------------------------------------

	/**
	 * Test is_available() is false without any credentials.
	 */
	public function test_is_available_false_without_credentials() {
		update_option( 'wp_mcp_ai_settings', array() );

		$this->assertFalse( WP_MCP_AI_Pro_Jev_Classifier::is_available() );
	}

	/**
	 * Test is_available() is true with an enabled TypeSafe key.
	 */
	public function test_is_available_true_with_typesafe() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		$this->assertTrue( WP_MCP_AI_Pro_Jev_Classifier::is_available() );
	}

	/**
	 * Test is_available() is true with an OpenRouter key alone.
	 */
	public function test_is_available_true_with_openrouter() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openrouter_api_key' => 'sk-or-test',
			)
		);

		$this->assertTrue( WP_MCP_AI_Pro_Jev_Classifier::is_available() );
	}

	/**
	 * Test is_available() honours the kill-switch filter.
	 */
	public function test_is_available_honours_kill_switch() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		add_filter( 'wp_mcp_ai_jev_classifier_enabled', '__return_false' );

		$this->assertFalse( WP_MCP_AI_Pro_Jev_Classifier::is_available() );
	}

	/**
	 * Test get_transport() prefers TypeSafe when both are configured.
	 */
	public function test_get_transport_prefers_typesafe() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'    => true,
				'typesafe_api_key'   => 'sk-ts-test',
				'openrouter_api_key' => 'sk-or-test',
			)
		);

		$this->assertEquals( 'typesafe', WP_MCP_AI_Pro_Jev_Classifier::get_transport() );
	}

	/**
	 * Test get_transport() falls back to openrouter.
	 */
	public function test_get_transport_falls_back_to_openrouter() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openrouter_api_key' => 'sk-or-test',
			)
		);

		$this->assertEquals( 'openrouter', WP_MCP_AI_Pro_Jev_Classifier::get_transport() );
	}

	// -------------------------------------------------------------------------
	// decide() dispatch.
	// -------------------------------------------------------------------------

	/**
	 * Test decide() dispatches to the native TypeSafe endpoint.
	 */
	public function test_decide_uses_typesafe_transport() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		$captured_url = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-1.13.0',
							'answers' => array( 'q' => array( 'noul' => 0.8 ) ),
						)
					),
				);
			},
			10,
			3
		);

		$result = WP_MCP_AI_Pro_Jev_Classifier::decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Yes?',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '/v1/systemone', $captured_url );
		$this->assertEquals( 'typesafe', $result['provider'] );
	}

	/**
	 * Test decide() dispatches to the OpenRouter decisions endpoint.
	 */
	public function test_decide_uses_openrouter_transport() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openrouter_api_key' => 'sk-or-test',
			)
		);

		$captured_url = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'typesafe/jev-1.13',
							'answers' => array( 'q' => array( 'noul' => 0.8 ) ),
						)
					),
				);
			},
			10,
			3
		);

		$result = WP_MCP_AI_Pro_Jev_Classifier::decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Yes?',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'decisions', $captured_url );
		$this->assertEquals( 'openrouter', $result['provider'] );
	}

	/**
	 * Test decide() returns unavailable error without credentials.
	 */
	public function test_decide_errors_when_unavailable() {
		update_option( 'wp_mcp_ai_settings', array() );

		$result = WP_MCP_AI_Pro_Jev_Classifier::decide(
			'state',
			array(
				'q' => array(
					'type'         => 'noul',
					'instructions' => 'Yes?',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_jev_unavailable', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// classify_prompt().
	// -------------------------------------------------------------------------

	/**
	 * Test classify_prompt() returns a shaped routing decision.
	 */
	public function test_classify_prompt_returns_routing_decision() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-1.13.0',
							'answers' => array(
								'task_type'      => array(
									'choice'     => 'coding',
									'confidence' => 0.9,
								),
								'complexity'     => array( 'score' => 1.5 ),
								'needs_frontier' => array( 'noul' => 0.7 ),
							),
						)
					),
				);
			},
			10
		);

		$result = WP_MCP_AI_Pro_Jev_Classifier::classify_prompt(
			array(
				array(
					'role'    => 'user',
					'content' => 'Debug this PHP function.',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'coding', $result['task_type'] );
		$this->assertEqualsWithDelta( 1.5, $result['complexity'], 0.0001 );
		$this->assertEqualsWithDelta( 0.7, $result['needs_frontier'], 0.0001 );
		$this->assertEquals( 'typesafe', $result['provider'] );
	}

	/**
	 * Test classify_prompt() rejects empty message sets.
	 */
	public function test_classify_prompt_rejects_empty_messages() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		$result = WP_MCP_AI_Pro_Jev_Classifier::classify_prompt(
			array(
				array(
					'role'    => 'system',
					'content' => 'You are helpful.',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_jev_no_prompt', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// filter_sources_by_relevance().
	// -------------------------------------------------------------------------

	/**
	 * Test filter keeps sources untouched when Jev is unavailable.
	 */
	public function test_filter_fails_open_when_unavailable() {
		update_option( 'wp_mcp_ai_settings', array() );

		$sources = $this->build_sources( 8 );
		$result  = WP_MCP_AI_Pro_Jev_Classifier::filter_sources_by_relevance( $sources, 'chess club' );

		$this->assertFalse( $result['used_jev'] );
		$this->assertSame( $sources, $result['sources'] );
		$this->assertEquals( 0, $result['dropped'] );
	}

	/**
	 * Test filter skips the call when sources are at or below keep_min.
	 */
	public function test_filter_noop_below_keep_min() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		$sources = $this->build_sources( 4 );
		$result  = WP_MCP_AI_Pro_Jev_Classifier::filter_sources_by_relevance( $sources, 'chess club' );

		$this->assertFalse( $result['used_jev'] );
		$this->assertSame( $sources, $result['sources'] );
	}

	/**
	 * Test filter drops clearly irrelevant sources and reorders survivors.
	 */
	public function test_filter_drops_irrelevant_and_reorders() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		// Score map by source index: 0 and 3 irrelevant, 2 the best.
		$score_map = array(
			0 => 0.1,
			1 => 1.2,
			2 => 2.8,
			3 => 0.4,
			4 => 1.1,
			5 => 1.5,
			6 => 1.0,
			7 => 1.3,
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( $score_map ) {
				$body    = json_decode( $args['body'], true );
				$answers = array();

				foreach ( array_keys( $body['questions'] ) as $key ) {
					preg_match( '/relevance_(\d+)/', $key, $matches );
					$index           = (int) $matches[1];
					$answers[ $key ] = array( 'score' => isset( $score_map[ $index ] ) ? $score_map[ $index ] : 1.0 );
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-1.13.0',
							'answers' => $answers,
						)
					),
				);
			},
			10,
			2
		);

		$sources = $this->build_sources( 8 );
		$result  = WP_MCP_AI_Pro_Jev_Classifier::filter_sources_by_relevance( $sources, 'chess club' );

		remove_all_filters( 'pre_http_request' );

		$this->assertTrue( $result['used_jev'] );
		$this->assertEquals( 2, $result['dropped'] );
		$this->assertCount( 6, $result['sources'] );

		// Most relevant (index 2) first, irrelevant dropped.
		$this->assertEquals( 'https://example.com/source-2', $result['sources'][0]['url'] );
		$urls = wp_list_pluck( $result['sources'], 'url' );
		$this->assertNotContains( 'https://example.com/source-0', $urls );
		$this->assertNotContains( 'https://example.com/source-3', $urls );
	}

	/**
	 * Test filter fails open on a transport error.
	 */
	public function test_filter_fails_open_on_transport_error() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Boom' ) ) ),
				);
			},
			10
		);

		$sources = $this->build_sources( 6 );
		$result  = WP_MCP_AI_Pro_Jev_Classifier::filter_sources_by_relevance( $sources, 'chess club' );

		remove_all_filters( 'pre_http_request' );

		$this->assertFalse( $result['used_jev'] );
		$this->assertSame( $sources, $result['sources'] );
	}
}
