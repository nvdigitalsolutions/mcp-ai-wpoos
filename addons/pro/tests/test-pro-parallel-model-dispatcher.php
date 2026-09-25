<?php
/**
 * Tests for the Pro Parallel Model Dispatcher, including the Jev routing
 * pre-step.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Pro_Parallel_Model_Dispatcher.
 */
class Test_Pro_Parallel_Model_Dispatcher extends WP_UnitTestCase {

	/**
	 * Dispatcher instance.
	 *
	 * @var WP_MCP_AI_Pro_Parallel_Model_Dispatcher
	 */
	private $dispatcher;

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
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-parallel-model-dispatcher.php';

		$this->dispatcher = new WP_MCP_AI_Pro_Parallel_Model_Dispatcher();

		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'pre_http_request' );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Test dispatch() returns an error when no models are configured.
	 */
	public function test_dispatch_without_models_returns_error() {
		$result = $this->dispatcher->dispatch( array(), array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'no_models', $result->get_error_code() );
	}

	/**
	 * Test dispatch() succeeds and records an error entry for unknown providers.
	 */
	public function test_dispatch_records_error_for_unknown_provider() {
		$result = $this->dispatcher->dispatch(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			),
			array(
				array(
					'provider' => 'not_a_provider',
					'model'    => 'fake-model',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['data']['results'][0]['error'] );
		$this->assertArrayNotHasKey( 'routing', $result['data'] );
	}

	/**
	 * Test dispatch() without jev_routing never attaches a routing decision.
	 */
	public function test_dispatch_without_jev_routing_omits_routing_key() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		$result = $this->dispatcher->dispatch(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			),
			array(
				array(
					'provider' => 'not_a_provider',
					'model'    => 'fake-model',
				),
			)
		);

		$this->assertArrayNotHasKey( 'routing', $result['data'] );
	}

	/**
	 * Test dispatch() with jev_routing attaches the routing decision and the
	 * chat entry fails open when its provider has no key.
	 */
	public function test_dispatch_with_jev_routing_attaches_routing() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		// Answer only the Jev classify call; anything else passes through.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false === strpos( $url, '/v1/systemone' ) ) {
					return $preempt;
				}

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
								'complexity'     => array( 'score' => 1.2 ),
								'needs_frontier' => array( 'noul' => 0.4 ),
							),
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->dispatcher->dispatch(
			array(
				array(
					'role'    => 'user',
					'content' => 'Debug this PHP function.',
				),
			),
			array(
				array(
					'provider' => 'openai',
					'model'    => 'gpt-4o-mini',
				),
			),
			array( 'jev_routing' => true )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'routing', $result['data'] );
		$this->assertEquals( 'coding', $result['data']['routing']['task_type'] );
		$this->assertEqualsWithDelta( 1.2, $result['data']['routing']['complexity'], 0.0001 );

		// The OpenAI entry has no key configured, so it must fail open with
		// an error entry rather than fatalling.
		$this->assertNotEmpty( $result['data']['results'][0]['error'] );
	}

	/**
	 * Test dispatch() with jev_routing fails open when the classifier is
	 * unavailable (no routing key, results still returned).
	 */
	public function test_dispatch_with_jev_routing_fails_open_when_unavailable() {
		update_option( 'wp_mcp_ai_settings', array() );

		$result = $this->dispatcher->dispatch(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			),
			array(
				array(
					'provider' => 'not_a_provider',
					'model'    => 'fake-model',
				),
			),
			array( 'jev_routing' => true )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'routing', $result['data'] );
	}

	/**
	 * Test classify_prompt() returns a clear error when Jev is unavailable.
	 */
	public function test_classify_prompt_fails_open_when_unavailable() {
		update_option( 'wp_mcp_ai_settings', array() );

		$result = $this->dispatcher->classify_prompt(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_jev_unavailable', $result->get_error_code() );
	}

	/**
	 * Test classify_prompt() returns a routing decision when available.
	 */
	public function test_classify_prompt_returns_decision_when_available() {
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
									'choice'     => 'writing',
									'confidence' => 0.8,
								),
								'complexity'     => array( 'score' => 0.9 ),
								'needs_frontier' => array( 'noul' => 0.2 ),
							),
						)
					),
				);
			},
			10
		);

		$result = $this->dispatcher->classify_prompt(
			array(
				array(
					'role'    => 'user',
					'content' => 'Draft an email.',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'writing', $result['task_type'] );
		$this->assertEquals( 'typesafe', $result['provider'] );
	}
}
