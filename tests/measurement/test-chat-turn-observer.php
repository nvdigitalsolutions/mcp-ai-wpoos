<?php
/**
 * Tests for the chat-turn observer.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observer tests.
 */
class Test_WP_MCP_AI_Chat_Turn_Observer extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Chat_Turn_Metrics::register( WP_MCP_AI_Measurement_Registry::get_instance() );

		WP_MCP_AI_Metric_Collector::get_instance()->clear_buffer();
		WP_MCP_AI_Chat_Turn_Observer::reset_instance();
		WP_MCP_AI_Chat_Turn_Observer::get_instance()->attach();
	}

	/**
	 * Teardown.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Chat_Turn_Observer::reset_instance();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * Fake successful provider response.
	 *
	 * @return array<string,mixed>
	 */
	private function fake_response_ok() {
		return array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
			'choices'  => array( array( 'message' => array( 'content' => 'hi' ) ) ),
			'usage'    => array(
				'prompt_tokens'     => 42,
				'completion_tokens' => 7,
				'total_tokens'      => 49,
			),
		);
	}

	/**
	 * Success path emits count + duration + token metrics.
	 */
	public function test_success_path_emits_count_duration_and_tokens() {
		do_action(
			'wp_mcp_ai_before_chat_request',
			7,
			array(),
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
			),
			null
		);
		do_action( 'wp_mcp_ai_after_chat_response', 7, $this->fake_response_ok(), null );

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );

		$this->assertContains( 'chat.turn.count', $ids );
		$this->assertContains( 'chat.turn.duration_ms', $ids );
		$this->assertContains( 'token_usage.prompt_tokens', $ids );
		$this->assertContains( 'token_usage.completion_tokens', $ids );
		$this->assertNotContains( 'chat.turn.error.count', $ids );
	}

	/**
	 * Error path (WP_Error response) emits error count and no token metrics.
	 */
	public function test_error_path_emits_error_count_no_tokens() {
		$err = new WP_Error( 'provider_failed', 'boom' );
		do_action( 'wp_mcp_ai_before_chat_request', 7, array(), array( 'provider' => 'openai' ), null );
		do_action( 'wp_mcp_ai_after_chat_response', 7, $err, null );

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );

		$this->assertContains( 'chat.turn.error.count', $ids );
		$this->assertContains( 'chat.turn.count', $ids );
		$this->assertNotContains( 'token_usage.prompt_tokens', $ids );
	}

	/**
	 * Provider error inside a non-WP_Error response still counts as error.
	 */
	public function test_provider_error_array_counted_as_error() {
		do_action( 'wp_mcp_ai_before_chat_request', 7, array(), array( 'provider' => 'openai' ), null );
		do_action(
			'wp_mcp_ai_after_chat_response',
			7,
			array(
				'provider' => 'openai',
				'error'    => array( 'message' => 'rate limit' ),
			),
			null
		);

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );
		$this->assertContains( 'chat.turn.error.count', $ids );
	}

	/**
	 * Cost hook emits the cost-usd metric once.
	 */
	public function test_cost_hook_emits_total_cost_usd() {
		do_action(
			'wp_mcp_ai_before_chat_request',
			7,
			array(),
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
			),
			null
		);
		$response = $this->fake_response_ok();
		do_action( 'wp_mcp_ai_after_chat_response', 7, $response, null );
		do_action(
			'wp_mcp_ai_cost_calculated',
			array(
				'cost_usd' => 0.000123,
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
			),
			7,
			42,
			$response,
			null
		);

		$cost_events = array_values(
			array_filter(
				WP_MCP_AI_Metric_Collector::get_instance()->buffered(),
				static function ( $e ) {
					return 'token_usage.total_cost_usd' === $e['id'];
				}
			)
		);
		$this->assertCount( 1, $cost_events );
		$this->assertEqualsWithDelta( 0.000123, (float) $cost_events[0]['value'], 1e-9 );
	}

	/**
	 * Zero / negative / missing cost does not emit.
	 */
	public function test_zero_cost_does_not_emit() {
		do_action( 'wp_mcp_ai_cost_calculated', array( 'cost_usd' => 0 ), 7, 42, null, null );
		do_action( 'wp_mcp_ai_cost_calculated', array( 'cost_usd' => -1.5 ), 7, 42, null, null );
		do_action( 'wp_mcp_ai_cost_calculated', array(), 7, 42, null, null );

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );
		$this->assertNotContains( 'token_usage.total_cost_usd', $ids );
	}

	/**
	 * Context payload stays inside the Internal privacy tier.
	 */
	public function test_context_payload_stays_internal_tier() {
		$secret_messages                              = array(
			array(
				'role'    => 'user',
				'content' => 'SUPERSECRETPROMPT',
			),
		);
		$secret_options                               = array(
			'provider'       => 'openai',
			'model'          => 'gpt-4o-mini',
			'api_key'        => 'sk-LEAKINGKEY',
			'system_message' => 'LEAKINGSYSTEMPROMPT',
		);
		$response                                     = $this->fake_response_ok();
		$response['choices'][0]['message']['content'] = 'LEAKINGCOMPLETION';

		do_action( 'wp_mcp_ai_before_chat_request', 7, $secret_messages, $secret_options, null );
		do_action( 'wp_mcp_ai_after_chat_response', 7, $response, null );
		do_action(
			'wp_mcp_ai_cost_calculated',
			array(
				'cost_usd' => 0.1,
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
			),
			7,
			42,
			$response,
			null
		);

		$buffered = WP_MCP_AI_Metric_Collector::get_instance()->buffered();
		$this->assertNotEmpty( $buffered );
		foreach ( $buffered as $event ) {
			$serialized = (string) wp_json_encode( $event['context'] );
			$this->assertStringNotContainsString( 'SUPERSECRETPROMPT', $serialized, 'Observer context must not leak prompt content.' );
			$this->assertStringNotContainsString( 'sk-LEAKINGKEY', $serialized, 'Observer context must not leak api keys.' );
			$this->assertStringNotContainsString( 'LEAKINGSYSTEMPROMPT', $serialized );
			$this->assertStringNotContainsString( 'LEAKINGCOMPLETION', $serialized );
		}
	}

	/**
	 * Nested calls with different assistants pop correctly.
	 */
	public function test_nested_calls_with_different_assistants() {
		$observer = WP_MCP_AI_Chat_Turn_Observer::get_instance();
		do_action( 'wp_mcp_ai_before_chat_request', 7, array(), array( 'provider' => 'openai' ), null );
		do_action( 'wp_mcp_ai_before_chat_request', 9, array(), array( 'provider' => 'openai' ), null );
		$this->assertSame( 2, $observer->depth() );

		do_action( 'wp_mcp_ai_after_chat_response', 7, $this->fake_response_ok(), null );
		$this->assertSame( 1, $observer->depth() );
		do_action( 'wp_mcp_ai_after_chat_response', 9, $this->fake_response_ok(), null );
		$this->assertSame( 0, $observer->depth() );
	}

	/**
	 * Mismatched after without a matching before emits count but skips duration.
	 */
	public function test_mismatched_after_skips_duration() {
		$observer = WP_MCP_AI_Chat_Turn_Observer::get_instance();
		do_action( 'wp_mcp_ai_after_chat_response', 999, $this->fake_response_ok(), null );
		$this->assertSame( 0, $observer->depth() );

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );
		$this->assertContains( 'chat.turn.count', $ids );
		$this->assertNotContains( 'chat.turn.duration_ms', $ids );
	}

	/**
	 * Filter disables the observer entirely.
	 */
	public function test_filter_disables_observer() {
		WP_MCP_AI_Chat_Turn_Observer::reset_instance();
		$filter = static function () {
			return false;
		};
		add_filter( 'wp_mcp_ai_chat_turn_observer_enabled', $filter );
		$attached = WP_MCP_AI_Chat_Turn_Observer::get_instance()->attach();
		$this->assertFalse( $attached );
		remove_filter( 'wp_mcp_ai_chat_turn_observer_enabled', $filter );
	}

	/**
	 * Detach is idempotent and clears stack.
	 */
	public function test_detach_is_idempotent_and_clears_stack() {
		$observer = WP_MCP_AI_Chat_Turn_Observer::get_instance();
		do_action( 'wp_mcp_ai_before_chat_request', 7, array(), array(), null );
		$observer->detach();
		$this->assertSame( 0, $observer->depth() );
		$observer->detach();
		$this->assertSame( 0, $observer->depth() );
	}

	/**
	 * Provider/model are captured from the options payload.
	 */
	public function test_provider_and_model_recorded_in_context() {
		do_action(
			'wp_mcp_ai_before_chat_request',
			7,
			array(),
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
			),
			null
		);
		do_action( 'wp_mcp_ai_after_chat_response', 7, $this->fake_response_ok(), null );

		$turn_events = array_values(
			array_filter(
				WP_MCP_AI_Metric_Collector::get_instance()->buffered(),
				static function ( $e ) {
					return 'chat.turn.count' === $e['id'];
				}
			)
		);
		$this->assertNotEmpty( $turn_events );
		$ctx = $turn_events[0]['context'];
		$this->assertSame( 'openai', $ctx['provider'] );
		$this->assertSame( 'gpt-4o-mini', $ctx['model'] );
	}
}
