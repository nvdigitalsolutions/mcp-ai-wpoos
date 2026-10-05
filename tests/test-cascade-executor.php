<?php
/**
 * Tests for WP_MCP_AI_Cascade_Executor — legacy-layer cascade routing.
 *
 * @package WP_MCP_AI
 * @since   1.1.97
 */

/**
 * Cascade executor test suite.
 */
class Test_Cascade_Executor extends WP_UnitTestCase {

	/**
	 * Recorded cascade decisions.
	 *
	 * @var array
	 */
	public $decisions = array();

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-language-model-router.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cascade-executor.php';

		WP_MCP_AI_Cascade_Executor::reset_for_tests();

		$this->decisions = array();
		add_action( 'wp_mcp_ai_cascade_decision', array( $this, 'spy_on_decision' ), 99, 1 );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_action( 'wp_mcp_ai_cascade_decision', array( $this, 'spy_on_decision' ), 99 );

		remove_all_filters( 'wp_mcp_ai_cascade_enabled' );
		remove_all_filters( 'wp_mcp_ai_cascade_classifier' );
		remove_all_filters( 'wp_mcp_ai_cascade_validator' );
		remove_all_filters( 'wp_mcp_ai_cascade_default_tier_1_model' );
		remove_all_filters( 'wp_mcp_ai_cascade_confidence_threshold' );

		WP_MCP_AI_Cascade_Executor::reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Spy callback for cascade decisions.
	 *
	 * @param array $outcome Decision outcome.
	 * @return void
	 */
	public function spy_on_decision( $outcome ) {
		$this->decisions[] = $outcome;
	}

	/**
	 * Build a recording router stub.
	 *
	 * @return WP_MCP_AI_Language_Model_Router
	 */
	private function stub_router() {
		$openai = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini = $this->createMock( WP_MCP_AI_Gemini_Client::class );

		return new class( $openai, $gemini ) extends WP_MCP_AI_Language_Model_Router {
			/**
			 * Recorded calls.
			 *
			 * @var array
			 */
			public $calls = array();

			/**
			 * Response queue.
			 *
			 * @var array
			 */
			public $responses = array();

			/**
			 * Record and respond without hitting a real provider.
			 *
			 * @param array $messages Chat messages.
			 * @param array $options  Request options.
			 * @return array|WP_Error
			 */
			public function create_chat_completion( array $messages, array $options = array() ) {
				$this->calls[] = $options;

				return ! empty( $this->responses ) ? array_shift( $this->responses ) : array(
					'choices' => array(
						array(
							'message' => array( 'content' => 'Primary answer of adequate length.' ),
						),
					),
				);
			}
		};
	}

	/**
	 * A generic messages fixture.
	 *
	 * @return array
	 */
	private function messages() {
		return array(
			array(
				'role'    => 'user',
				'content' => 'Summarize the store hours.',
			),
		);
	}

	/**
	 * Enable the cascade and classify as simple.
	 *
	 * @param float $confidence Classifier confidence.
	 * @return void
	 */
	private function enable_simple_cascade( $confidence = 0.95 ) {
		add_filter( 'wp_mcp_ai_cascade_enabled', '__return_true' );
		add_filter(
			'wp_mcp_ai_cascade_classifier',
			function () use ( $confidence ) {
				return array(
					'tier'       => 'simple',
					'confidence' => $confidence,
					'reason'     => 'fixture',
				);
			}
		);
	}

	/**
	 * Disabled by default: passthrough with zero side effects.
	 */
	public function test_passthrough_when_disabled() {
		$router  = $this->stub_router();
		$options = array(
			'provider'             => 'openai',
			'cascade_tier_1_model' => 'gpt-4o-mini',
		);

		$this->assertNull( WP_MCP_AI_Cascade_Executor::maybe_route( $router, $this->messages(), $options ) );
		$this->assertCount( 0, $router->calls );
		$this->assertSame( 1, WP_MCP_AI_Cascade_Executor::get_stats()['passthrough'] );
	}

	/**
	 * Enabled without a tier-1 target: passthrough.
	 */
	public function test_passthrough_without_tier1_target() {
		$this->enable_simple_cascade();

		$router = $this->stub_router();

		$this->assertNull( WP_MCP_AI_Cascade_Executor::maybe_route( $router, $this->messages(), array( 'provider' => 'openai' ) ) );
		$this->assertCount( 0, $router->calls );
	}

	/**
	 * Enabled with a tier-1 target but no classifier: passthrough
	 * (fail-closed).
	 */
	public function test_passthrough_without_classifier() {
		add_filter( 'wp_mcp_ai_cascade_enabled', '__return_true' );

		$router = $this->stub_router();

		$this->assertNull(
			WP_MCP_AI_Cascade_Executor::maybe_route(
				$router,
				$this->messages(),
				array(
					'provider'             => 'openai',
					'cascade_tier_1_model' => 'gpt-4o-mini',
				)
			)
		);
		$this->assertCount( 0, $router->calls );
	}

	/**
	 * A complex classification skips the cheap tier entirely.
	 */
	public function test_complex_classification_passes_through() {
		add_filter( 'wp_mcp_ai_cascade_enabled', '__return_true' );
		add_filter(
			'wp_mcp_ai_cascade_classifier',
			function () {
				return array(
					'tier'       => 'complex',
					'confidence' => 0.1,
					'reason'     => 'frontier needed',
				);
			}
		);

		$router = $this->stub_router();

		$this->assertNull(
			WP_MCP_AI_Cascade_Executor::maybe_route(
				$router,
				$this->messages(),
				array(
					'provider'             => 'openai',
					'cascade_tier_1_model' => 'gpt-4o-mini',
				)
			)
		);
		$this->assertCount( 0, $router->calls );
		$this->assertSame( 0, WP_MCP_AI_Cascade_Executor::get_stats()['routed_simple'] );
	}

	/**
	 * Streaming requests never cascade.
	 */
	public function test_stream_requests_never_cascade() {
		$this->enable_simple_cascade();

		$router = $this->stub_router();

		$this->assertNull(
			WP_MCP_AI_Cascade_Executor::maybe_route(
				$router,
				$this->messages(),
				array(
					'provider'             => 'openai',
					'cascade_tier_1_model' => 'gpt-4o-mini',
					'stream'               => true,
				)
			)
		);
		$this->assertCount( 0, $router->calls );
	}

	/**
	 * An accepted cheap-tier answer is returned as-is.
	 */
	public function test_simple_accepted_returns_tier1_result() {
		$this->enable_simple_cascade();

		$router = $this->stub_router();
		$result = WP_MCP_AI_Cascade_Executor::maybe_route(
			$router,
			$this->messages(),
			array(
				'provider'             => 'openai',
				'cascade_tier_1_model' => 'gpt-4o-mini',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Primary answer of adequate length.', $result['choices'][0]['message']['content'] );
		$this->assertCount( 1, $router->calls );
		$this->assertSame( 'gpt-4o-mini', $router->calls[0]['model'] );
		$this->assertTrue( $router->calls[0]['wp_mcp_ai_cascade_bypass'] );

		$stats = WP_MCP_AI_Cascade_Executor::get_stats();
		$this->assertSame( 1, $stats['routed_simple'] );
		$this->assertSame( 1, $stats['accepted'] );
		$this->assertSame( 0, $stats['escalated'] );

		$this->assertCount( 1, $this->decisions );
		$this->assertSame( 'accepted', $this->decisions[0]['decision'] );
	}

	/**
	 * An empty cheap-tier answer escalates to the primary provider.
	 */
	public function test_unacceptable_response_escalates() {
		$this->enable_simple_cascade();

		$router            = $this->stub_router();
		$router->responses = array(
			array( 'choices' => array( array( 'message' => array( 'content' => '' ) ) ) ),
			array( 'choices' => array( array( 'message' => array( 'content' => 'Primary escalation answer with adequate length.' ) ) ) ),
		);

		$result = WP_MCP_AI_Cascade_Executor::maybe_route(
			$router,
			$this->messages(),
			array(
				'provider'             => 'openai',
				'cascade_tier_1_model' => 'gpt-4o-mini',
			)
		);

		$this->assertSame( 'Primary escalation answer with adequate length.', $result['choices'][0]['message']['content'] );
		$this->assertCount( 2, $router->calls );
		// The escalation call carries the original (primary) model.
		$this->assertArrayNotHasKey( 'model', $router->calls[1] );

		$stats = WP_MCP_AI_Cascade_Executor::get_stats();
		$this->assertSame( 1, $stats['escalated'] );
		$this->assertSame( 0, $stats['accepted'] );
		$this->assertSame( 'escalated', $this->decisions[0]['decision'] );
	}

	/**
	 * A low-confidence validator verdict escalates even when acceptable.
	 */
	public function test_low_confidence_escalates() {
		$this->enable_simple_cascade( 0.95 );
		add_filter(
			'wp_mcp_ai_cascade_validator',
			function () {
				return array(
					'acceptable' => true,
					'confidence' => 0.5,
					'reason'     => 'semantic judge unsure',
				);
			}
		);

		$router            = $this->stub_router();
		$router->responses = array(
			array( 'choices' => array( array( 'message' => array( 'content' => 'Cheap answer.' ) ) ) ),
			array( 'choices' => array( array( 'message' => array( 'content' => 'Primary escalation answer with adequate length.' ) ) ) ),
		);

		$result = WP_MCP_AI_Cascade_Executor::maybe_route(
			$router,
			$this->messages(),
			array(
				'provider'             => 'openai',
				'cascade_tier_1_model' => 'gpt-4o-mini',
			)
		);

		$this->assertSame( 'Primary escalation answer with adequate length.', $result['choices'][0]['message']['content'] );
		$this->assertSame( 1, WP_MCP_AI_Cascade_Executor::get_stats()['escalated'] );
	}

	/**
	 * A cheap-tier error fails open to the primary provider.
	 */
	public function test_tier1_error_escalates() {
		$this->enable_simple_cascade();

		$router            = $this->stub_router();
		$router->responses = array(
			new WP_Error( 'timeout', 'Tier-1 timed out.' ),
			array( 'choices' => array( array( 'message' => array( 'content' => 'Primary fallback answer with adequate length.' ) ) ) ),
		);

		$result = WP_MCP_AI_Cascade_Executor::maybe_route(
			$router,
			$this->messages(),
			array(
				'provider'             => 'openai',
				'cascade_tier_1_model' => 'gpt-4o-mini',
			)
		);

		$this->assertSame( 'Primary fallback answer with adequate length.', $result['choices'][0]['message']['content'] );

		$stats = WP_MCP_AI_Cascade_Executor::get_stats();
		$this->assertSame( 1, $stats['tier1_errors'] );
		$this->assertSame( 1, $stats['escalated'] );
		$this->assertSame( 'tier1_error', $this->decisions[0]['decision'] );
	}

	/**
	 * A tier-1 provider switch routes the cheap call to that provider.
	 */
	public function test_tier1_provider_switch() {
		$this->enable_simple_cascade();

		$router = $this->stub_router();

		$result = WP_MCP_AI_Cascade_Executor::maybe_route(
			$router,
			$this->messages(),
			array(
				'provider'                => 'openai',
				'cascade_tier_1_provider' => 'deepseek',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'deepseek', $router->calls[0]['provider'] );
		$this->assertArrayNotHasKey( 'model', $router->calls[0] );
	}

	/**
	 * The bypass flag short-circuits before the enable filter is consulted.
	 */
	public function test_bypass_flag_short_circuits() {
		$router = $this->stub_router();

		$this->assertNull(
			WP_MCP_AI_Cascade_Executor::maybe_route(
				$router,
				$this->messages(),
				array(
					'provider'                 => 'openai',
					'wp_mcp_ai_cascade_bypass' => true,
				)
			)
		);
		$this->assertCount( 0, $router->calls );
	}

	/**
	 * Deterministic default verdicts.
	 */
	public function test_default_verdict() {
		$this->assertFalse( WP_MCP_AI_Cascade_Executor::default_verdict( new WP_Error( 'x', 'boom' ) )['acceptable'] );
		$this->assertFalse( WP_MCP_AI_Cascade_Executor::default_verdict( array( 'error' => 'boom' ) )['acceptable'] );
		$this->assertFalse( WP_MCP_AI_Cascade_Executor::default_verdict( array( 'choices' => array( array( 'message' => array( 'content' => '   ' ) ) ) ) )['acceptable'] );

		$verdict = WP_MCP_AI_Cascade_Executor::default_verdict( array( 'choices' => array( array( 'message' => array( 'content' => 'Real answer.' ) ) ) ) );
		$this->assertTrue( $verdict['acceptable'] );
		$this->assertSame( 0.9, $verdict['confidence'] );
	}

	/**
	 * End-to-end: the router gate consults the executor on the live path.
	 */
	public function test_router_gate_integrates_with_executor() {
		$openai = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$openai->method( 'create_chat_completion' )->willReturn(
			array( 'choices' => array( array( 'message' => array( 'content' => 'Router-level answer with adequate length.' ) ) ) )
		);
		$gemini = $this->createMock( WP_MCP_AI_Gemini_Client::class );

		$router = new WP_MCP_AI_Language_Model_Router( $openai, $gemini );

		$this->enable_simple_cascade();

		$result = $router->create_chat_completion(
			$this->messages(),
			array(
				'provider'             => 'openai',
				'cascade_tier_1_model' => 'gpt-4o-mini',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, WP_MCP_AI_Cascade_Executor::get_stats()['accepted'] );
	}

	/**
	 * End-to-end: with the cascade off, the router dispatches directly.
	 */
	public function test_router_gate_passthrough_when_disabled() {
		$openai = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$openai->method( 'create_chat_completion' )->willReturn(
			array( 'choices' => array( array( 'message' => array( 'content' => 'Direct answer with adequate length.' ) ) ) )
		);
		$gemini = $this->createMock( WP_MCP_AI_Gemini_Client::class );

		$router = new WP_MCP_AI_Language_Model_Router( $openai, $gemini );

		$result = $router->create_chat_completion(
			$this->messages(),
			array(
				'provider'             => 'openai',
				'cascade_tier_1_model' => 'gpt-4o-mini',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, WP_MCP_AI_Cascade_Executor::get_stats()['passthrough'] );
	}
}
