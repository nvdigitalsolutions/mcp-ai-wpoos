<?php
/**
 * Tests for the OOS parity gap closures (Proposal 029 G2 follow-up):
 *
 *  - Security/budget gate exceptions surface canonical envelopes (428/429)
 *    on the OOS path instead of a generic 500.
 *  - wp_mcp_ai_pre_response_render runs at the final payload on both paths
 *    (Output Guardrail + Citation Verifier).
 *  - AgenticIterationComplete domain events map to the legacy
 *    wp_mcp_ai_agentic_iteration_complete hook.
 *  - The tools/execute waterfall bridges wp_mcp_ai_before_tool_execute for
 *    native OOS tools.
 *
 * @package WP_MCP_AI
 * @since   1.1.97
 */

/**
 * OOS parity gaps test suite.
 */
class Test_OOS_Parity_Gaps extends WP_UnitTestCase {

	/**
	 * Exposed REST helper host.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/exceptions/class-wp-mcp-ai-destructive-confirmation-required.php';
		require_once WP_MCP_AI_PATH . 'includes/exceptions/class-wp-mcp-ai-concurrency-limit-reached.php';
		require_once WP_MCP_AI_PATH . 'includes/exceptions/class-wp-mcp-ai-cost-budget-exceeded.php';

		$registry = $this->createMock( WP_MCP_AI_Tool_Registry::class );
		$router   = $this->createMock( WP_MCP_AI_Language_Model_Router::class );

		$this->rest = new class( $registry, $router ) extends WP_MCP_AI_REST {
			/**
			 * Expose the protected pre-response-render helper.
			 *
			 * @param mixed           $payload_data Response data block.
			 * @param int             $assistant_id Assistant post ID.
			 * @param WP_REST_Request $request      REST request.
			 * @return mixed|WP_Error
			 */
			public function guard( $payload_data, $assistant_id, $request ) {
				return $this->apply_pre_response_render( $payload_data, $assistant_id, $request );
			}
		};
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_pre_response_render' );
		remove_all_actions( 'wp_mcp_ai_agentic_iteration_complete' );
		remove_all_filters( 'wp_mcp_ai_before_tool_execute' );

		parent::tearDown();
	}

	/**
	 * A destructive-confirmation exception translates to the 428 envelope.
	 */
	public function test_translate_gate_exception_destructive_428() {
		$exception = new WP_MCP_AI_Destructive_Confirmation_Required(
			'delete_site',
			array( 'flags' => array( 'destructive' => true ) ),
			'Confirmation required.'
		);

		$error = WP_MCP_AI_REST::translate_gate_exception( $exception );

		$this->assertWPError( $error );
		$this->assertSame( 'wp_mcp_ai_destructive_confirmation_required', $error->get_error_code() );
		$this->assertSame( 428, $error->get_error_data()['status'] );
	}

	/**
	 * Concurrency and cost-budget exceptions translate to their 429 envelopes.
	 */
	public function test_translate_gate_exception_concurrency_and_cost() {
		$concurrency = WP_MCP_AI_REST::translate_gate_exception(
			new WP_MCP_AI_Concurrency_Limit_Reached( 'chat', 'At capacity.' )
		);
		$this->assertWPError( $concurrency );
		$this->assertSame( 'concurrency_limit', $concurrency->get_error_code() );
		$this->assertSame( 429, $concurrency->get_error_data()['status'] );

		$cost = WP_MCP_AI_REST::translate_gate_exception(
			new WP_MCP_AI_Cost_Budget_Exceeded( 42, 'Budget exhausted.' )
		);
		$this->assertWPError( $cost );
		$this->assertSame( 'cost_budget_exceeded', $cost->get_error_code() );
		$this->assertSame( 429, $cost->get_error_data()['status'] );
	}

	/**
	 * Unknown exceptions are not translated.
	 */
	public function test_translate_gate_exception_returns_null_for_unknown() {
		$this->assertNull( WP_MCP_AI_REST::translate_gate_exception( new \Exception( 'boom' ) ) );
	}

	/**
	 * The pre-response-render helper applies a subscriber filter.
	 */
	public function test_pre_response_render_applies_subscriber_filter() {
		add_filter(
			'wp_mcp_ai_pre_response_render',
			function ( $content, $assistant_id, $context ) {
				return 'SANITIZED:' . $assistant_id . ':' . ( isset( $context['surface'] ) ? $context['surface'] : '' ) . ':' . $content;
			},
			10,
			3
		);

		$request = new WP_REST_Request();
		$payload = array(
			'choices' => array(
				array( 'message' => array( 'content' => 'Raw answer.' ) ),
			),
		);

		$result = $this->rest->guard( $payload, 42, $request );

		$this->assertSame( 'SANITIZED:42:rest_chat:Raw answer.', $result['choices'][0]['message']['content'] );
	}

	/**
	 * A blocking subscriber (WP_Error) propagates through the helper.
	 */
	public function test_pre_response_render_propagates_wp_error() {
		add_filter(
			'wp_mcp_ai_pre_response_render',
			function () {
				return new WP_Error( 'guardrail_blocked', 'Blocked by policy.', array( 'status' => 422 ) );
			}
		);

		$result = $this->rest->guard(
			array( 'choices' => array( array( 'message' => array( 'content' => 'Leaks sensitive data.' ) ) ) ),
			42,
			new WP_REST_Request()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'guardrail_blocked', $result->get_error_code() );
	}

	/**
	 * Non-string content passes through untouched.
	 */
	public function test_pre_response_render_passes_through_non_string_content() {
		$payload = array(
			'choices' => array(
				array(
					'message' => array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'structured',
							),
						),
					),
				),
			),
		);

		$result = $this->rest->guard( $payload, 42, new WP_REST_Request() );

		$this->assertSame( $payload, $result );
	}

	/**
	 * A broken subscriber that returns a non-string cannot corrupt the payload.
	 */
	public function test_pre_response_render_ignores_non_string_filter_results() {
		add_filter( 'wp_mcp_ai_pre_response_render', '__return_zero' );

		$payload = array(
			'choices' => array( array( 'message' => array( 'content' => 'Intact answer.' ) ) ),
		);

		$result = $this->rest->guard( $payload, 42, new WP_REST_Request() );

		$this->assertSame( 'Intact answer.', $result['choices'][0]['message']['content'] );
	}

	/**
	 * AgenticIterationComplete domain events fire the legacy hook with the
	 * same argument pair.
	 */
	public function test_iteration_event_maps_to_legacy_hook() {
		if ( ! function_exists( 'wp_mcp_ai_oos_orchestrator' ) ) {
			// The bridge bails early when the lib/ extraction is absent
			// (pruned local copies / wp.org base builds).
			$this->markTestSkipped( 'oos-bridge not loaded (lib/ absent).' );
		}

		$captured = array();
		add_action(
			'wp_mcp_ai_agentic_iteration_complete',
			function ( $iteration, $assistant_id ) use ( &$captured ) {
				$captured = array( $iteration, $assistant_id );
			},
			10,
			2
		);

		$orchestrator = wp_mcp_ai_oos_orchestrator();
		$reflection   = new ReflectionProperty( $orchestrator, 'events' );
		$reflection->setAccessible( true );
		$events = $reflection->getValue( $orchestrator );

		$events->dispatch( new Nvoos\Core\Domain\Event\AgenticIterationComplete( iteration: 3, assistantId: 42 ) );

		$this->assertSame( array( 3, 42 ), $captured );
	}

	/**
	 * The tools/execute waterfall bridges wp_mcp_ai_before_tool_execute for
	 * native OOS tools, with legacy short-circuit semantics.
	 */
	public function test_before_tool_execute_waterfall_bridge() {
		if ( ! function_exists( 'wp_mcp_ai_oos_orchestrator' ) ) {
			$this->markTestSkipped( 'oos-bridge not loaded (lib/ absent).' );
		}

		$orchestrator = wp_mcp_ai_oos_orchestrator();
		$reflection   = new ReflectionProperty( $orchestrator, 'events' );
		$reflection->setAccessible( true );
		$events = $reflection->getValue( $orchestrator );

		$event = new Nvoos\Core\Domain\Event\ToolsExecute(
			slug: 'web_search',
			arguments: array( 'query' => 'test' ),
			context: array( 'user_id' => 1 )
		);

		// Pass-through: the filter allows execution, the final handler runs.
		$seen = null;
		add_filter(
			'wp_mcp_ai_before_tool_execute',
			function ( $pre, $slug, $arguments, $context ) use ( &$seen ) {
				$seen = array( $slug, $arguments, $context );
				return $pre;
			},
			10,
			4
		);

		$result = $events->waterfall(
			'tools/execute',
			$event,
			static function () {
				return array( 'executed' => true );
			}
		);

		$this->assertSame( array( 'executed' => true ), $result );
		$this->assertSame( 'web_search', $seen[0] );
		$this->assertSame( array( 'query' => 'test' ), $seen[1] );

		// Short-circuit: a WP_Error from the filter replaces the dispatch.
		remove_all_filters( 'wp_mcp_ai_before_tool_execute' );
		add_filter(
			'wp_mcp_ai_before_tool_execute',
			function () {
				return new WP_Error( 'necessity_gate', 'Blocked by gate.' );
			},
			10,
			4
		);

		$final_called = false;
		$result       = $events->waterfall(
			'tools/execute',
			$event,
			static function () use ( &$final_called ) {
				$final_called = true;
				return array( 'executed' => true );
			}
		);

		$this->assertWPError( $result );
		$this->assertSame( 'necessity_gate', $result->get_error_code() );
		$this->assertFalse( $final_called );
	}
}
