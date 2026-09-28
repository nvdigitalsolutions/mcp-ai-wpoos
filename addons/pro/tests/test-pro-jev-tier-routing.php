<?php
/**
 * Tests for WP_MCP_AI_Pro_Jev_Tier_Routing and the classifier's
 * routing_signal_for() (proposal 045, G1).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the Pro Jev tier-routing signal.
 */
class Test_Pro_Jev_Tier_Routing extends WP_UnitTestCase {

	/**
	 * Canned decision answers served through pre_http_request.
	 *
	 * @var array
	 */
	private $canned_answers = array();

	/**
	 * Number of HTTP calls intercepted.
	 *
	 * @var int
	 */
	private $http_calls = 0;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
		}

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-decision-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-typesafe-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openrouter-client.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-classifier.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-tier-routing.php';

		// Retries must not sleep inside the test suite.
		add_filter( 'wp_mcp_ai_typesafe_retry_sleep', '__return_zero' );

		$this->canned_answers = $this->default_answers();
		$this->http_calls     = 0;

		$test = $this;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $test ) {
				unset( $pre, $args, $url );
				++$test->http_calls;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-1.13.0',
							'answers' => $test->canned_answers,
							'usage'   => array(
								'input_tokens'  => 10,
								'output_tokens' => 5,
							),
						)
					),
				);
			},
			10,
			3
		);

		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_typesafe_retry_sleep' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_jev_classifier_enabled' );
		delete_option( 'wp_mcp_ai_settings' );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Default canned answers: complexity 0.4 with high confidence.
	 *
	 * @return array
	 */
	private function default_answers() {
		return array(
			'task_type'      => array(
				'type'          => 'choice',
				'choice'        => 'coding',
				'probabilities' => array( 'coding' => 0.9 ),
				'confidence'    => 0.9,
			),
			'complexity'     => array(
				'type'          => 'score',
				'score'         => 0.4,
				'probabilities' => array(
					'0' => 0.6,
					'1' => 0.4,
				),
				'confidence'    => 0.8,
			),
			'needs_frontier' => array(
				'type' => 'noul',
				'noul' => 0.6,
			),
		);
	}

	/**
	 * Helper: enable the native TypeSafe transport in settings.
	 */
	private function enable_typesafe() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);
	}

	// -------------------------------------------------------------------------
	// routing_signal_for().
	// -------------------------------------------------------------------------

	/**
	 * Test routing_signal_for() maps complexity to confidence in code.
	 */
	public function test_routing_signal_maps_complexity_to_confidence() {
		$this->enable_typesafe();

		$signal = WP_MCP_AI_Pro_Jev_Classifier::routing_signal_for(
			array(
				array(
					'role'    => 'user',
					'content' => 'Write a PHP function.',
				),
			)
		);

		$this->assertTrue( $signal['used_jev'] );
		$this->assertEquals( 'coding', $signal['task_type'] );
		$this->assertEquals( 0.8, $signal['confidence'] );
		$this->assertEquals( 0.6, $signal['needs_frontier'] );
		$this->assertEquals( 0.8, $signal['complexity_confidence'] );
	}

	/**
	 * Test maximum complexity maps to zero confidence (deep tier).
	 */
	public function test_routing_signal_zero_confidence_at_max_complexity() {
		$this->enable_typesafe();

		$this->canned_answers['complexity'] = array(
			'type'          => 'score',
			'score'         => 2.0,
			'probabilities' => array( '2' => 1.0 ),
			'confidence'    => 0.9,
		);

		$signal = WP_MCP_AI_Pro_Jev_Classifier::routing_signal_for(
			array(
				array(
					'role'    => 'user',
					'content' => 'Prove the Riemann hypothesis.',
				),
			)
		);

		$this->assertEquals( 0.0, $signal['confidence'] );
		$this->assertEquals( 2.0, $signal['complexity'] );
	}

	/**
	 * Test a low complexity-confidence read falls back to neutral.
	 */
	public function test_routing_signal_neutral_on_low_complexity_confidence() {
		$this->enable_typesafe();

		$this->canned_answers['complexity'] = array(
			'type'          => 'score',
			'score'         => 0.2,
			'probabilities' => array(
				'0' => 0.7,
				'1' => 0.3,
			),
			'confidence'    => 0.3,
		);

		$signal = WP_MCP_AI_Pro_Jev_Classifier::routing_signal_for(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			)
		);

		$this->assertTrue( $signal['used_jev'] );
		$this->assertEquals( WP_MCP_AI_Pro_Jev_Classifier::NEUTRAL_ROUTING_SIGNAL['confidence'], $signal['confidence'] );
		$this->assertEquals( 0.3, $signal['complexity_confidence'] );
	}

	/**
	 * Test an unavailable classifier returns the neutral signal.
	 */
	public function test_routing_signal_neutral_when_unavailable() {
		delete_option( 'wp_mcp_ai_settings' );

		$signal = WP_MCP_AI_Pro_Jev_Classifier::routing_signal_for(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			)
		);

		$this->assertFalse( $signal['used_jev'] );
		$this->assertEquals( 0.65, $signal['confidence'] );
		$this->assertEquals( 0, $this->http_calls );
	}

	/**
	 * Test classify_prompt() now exposes complexity_confidence.
	 */
	public function test_classify_prompt_exposes_complexity_confidence() {
		$this->enable_typesafe();

		$decision = WP_MCP_AI_Pro_Jev_Classifier::classify_prompt(
			array(
				array(
					'role'    => 'user',
					'content' => 'Write a PHP function.',
				),
			)
		);

		$this->assertArrayHasKey( 'complexity_confidence', $decision );
		$this->assertEquals( 0.8, $decision['complexity_confidence'] );
	}

	// -------------------------------------------------------------------------
	// execution_depth_confidence().
	// -------------------------------------------------------------------------

	/**
	 * Test the depth-confidence filter passes through when disabled.
	 */
	public function test_depth_confidence_passes_through_when_disabled() {
		update_option( 'wp_mcp_ai_settings', array() );

		$result = WP_MCP_AI_Pro_Jev_Tier_Routing::execution_depth_confidence(
			0.0,
			array( 'prompt' => 'Write a PHP function.' ),
			'web_search'
		);

		$this->assertEquals( 0.0, $result );
		$this->assertEquals( 0, $this->http_calls );
	}

	/**
	 * Test the depth-confidence filter injects the Jev signal when unset.
	 */
	public function test_depth_confidence_injects_jev_signal() {
		$this->enable_typesafe();
		update_option(
			'wp_mcp_ai_settings',
			array_merge( get_option( 'wp_mcp_ai_settings' ), array( 'enable_jev_tier_routing' => true ) )
		);

		$result = WP_MCP_AI_Pro_Jev_Tier_Routing::execution_depth_confidence(
			0.0,
			array( 'prompt' => 'Write a PHP function.' ),
			'web_search'
		);

		$this->assertEquals( 0.8, $result );
		$this->assertEquals( 1, $this->http_calls );
	}

	/**
	 * Test a caller-supplied confidence always wins.
	 */
	public function test_depth_confidence_respects_caller_signal() {
		$this->enable_typesafe();
		update_option(
			'wp_mcp_ai_settings',
			array_merge( get_option( 'wp_mcp_ai_settings' ), array( 'enable_jev_tier_routing' => true ) )
		);

		$result = WP_MCP_AI_Pro_Jev_Tier_Routing::execution_depth_confidence(
			0.7,
			array( 'prompt' => 'Write a PHP function.' ),
			'web_search'
		);

		$this->assertEquals( 0.7, $result );
		$this->assertEquals( 0, $this->http_calls );
	}

	/**
	 * Test a context without a prompt makes no decision call.
	 */
	public function test_depth_confidence_skips_context_without_prompt() {
		$this->enable_typesafe();
		update_option(
			'wp_mcp_ai_settings',
			array_merge( get_option( 'wp_mcp_ai_settings' ), array( 'enable_jev_tier_routing' => true ) )
		);

		$result = WP_MCP_AI_Pro_Jev_Tier_Routing::execution_depth_confidence(
			0.0,
			array( 'capacity' => 30 ),
			'web_search'
		);

		$this->assertEquals( 0.0, $result );
		$this->assertEquals( 0, $this->http_calls );
	}

	// -------------------------------------------------------------------------
	// enrich_selection().
	// -------------------------------------------------------------------------

	/**
	 * Test the selection filter attaches decision metadata when enabled.
	 */
	public function test_enrich_selection_attaches_decision_metadata() {
		$this->enable_typesafe();
		update_option(
			'wp_mcp_ai_settings',
			array_merge( get_option( 'wp_mcp_ai_settings' ), array( 'enable_jev_tier_routing' => true ) )
		);

		$config = WP_MCP_AI_Pro_Jev_Tier_Routing::enrich_selection(
			array(
				'model'    => 'gpt-4.1',
				'provider' => 'openai',
				'tier'     => 'verification',
			),
			'coding',
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'Write a PHP function.',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'decision_model', $config );
		$this->assertEquals( 'coding', $config['decision_model']['task_type'] );
		$this->assertSame( 'gpt-4.1', $config['model'] );
	}

	/**
	 * Test the selection filter leaves the config untouched when disabled.
	 */
	public function test_enrich_selection_passes_through_when_disabled() {
		update_option( 'wp_mcp_ai_settings', array() );

		$config = WP_MCP_AI_Pro_Jev_Tier_Routing::enrich_selection(
			array( 'model' => 'gpt-4.1' ),
			'coding',
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'Hello.',
					),
				),
			)
		);

		$this->assertArrayNotHasKey( 'decision_model', $config );
		$this->assertEquals( 0, $this->http_calls );
	}
}
