<?php
/**
 * Tests for chat-turn stock metric registration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat-turn stock metric tests.
 */
class Test_WP_MCP_AI_Chat_Turn_Metrics extends WP_UnitTestCase {

	/**
	 * Test definitions include expected metrics.
	 */
	public function test_definitions_include_expected_metrics() {
		$defs = WP_MCP_AI_Chat_Turn_Metrics::definitions();
		$ids  = array_column( $defs, 'id' );

		$this->assertContains( 'chat.turn.count', $ids );
		$this->assertContains( 'chat.turn.error.count', $ids );
		$this->assertContains( 'chat.turn.duration_ms', $ids );
		$this->assertContains( 'token_usage.prompt_tokens', $ids );
		$this->assertContains( 'token_usage.completion_tokens', $ids );
		$this->assertContains( 'token_usage.total_cost_usd', $ids );
		$this->assertContains( 'chat.agentic.iterations', $ids );
	}

	/**
	 * Every chat-turn metric declares a counter pairing.
	 */
	public function test_every_chat_turn_metric_declares_counter() {
		foreach ( WP_MCP_AI_Chat_Turn_Metrics::definitions() as $def ) {
			$this->assertNotEmpty(
				$def['counter_metric'],
				sprintf( 'Chat-turn metric %s is missing a counter_metric pairing.', $def['id'] )
			);
		}
	}

	/**
	 * Every chat-turn metric stays in the internal privacy tier.
	 */
	public function test_every_chat_turn_metric_is_internal_tier() {
		foreach ( WP_MCP_AI_Chat_Turn_Metrics::definitions() as $def ) {
			$this->assertSame(
				WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				$def['privacy_tier'],
				sprintf( 'Chat-turn metric %s must stay in the internal privacy tier; richer payloads require registry re-classification.', $def['id'] )
			);
		}
	}

	/**
	 * Filter can suppress all chat-turn metrics.
	 */
	public function test_filter_can_suppress_all_chat_turn_metrics() {
		$filter = static function () {
			return array();
		};
		add_filter( 'wp_mcp_ai_chat_turn_metrics_definitions', $filter );
		$this->assertSame( array(), WP_MCP_AI_Chat_Turn_Metrics::definitions() );
		remove_filter( 'wp_mcp_ai_chat_turn_metrics_definitions', $filter );
	}

	/**
	 * Non-array filter return is ignored (defensive).
	 */
	public function test_filter_non_array_return_ignored() {
		$filter = static function () {
			return 'not an array';
		};
		add_filter( 'wp_mcp_ai_chat_turn_metrics_definitions', $filter );
		$defs = WP_MCP_AI_Chat_Turn_Metrics::definitions();
		$this->assertIsArray( $defs );
		$this->assertNotEmpty( $defs );
		remove_filter( 'wp_mcp_ai_chat_turn_metrics_definitions', $filter );
	}

	/**
	 * Register returns count of new registrations.
	 */
	public function test_register_returns_count_of_new_registrations() {
		WP_MCP_AI_Measurement_Registry::reset_instance();
		$registry = WP_MCP_AI_Measurement_Registry::get_instance();
		$count    = WP_MCP_AI_Chat_Turn_Metrics::register( $registry );
		$this->assertGreaterThanOrEqual( 7, $count );
		$this->assertNotNull( $registry->get( 'chat.turn.count' ) );
		$this->assertNotNull( $registry->get( 'token_usage.total_cost_usd' ) );
		WP_MCP_AI_Measurement_Registry::reset_instance();
	}

	/**
	 * Register ignores non-registry arguments.
	 */
	public function test_register_non_registry_returns_zero() {
		$this->assertSame( 0, WP_MCP_AI_Chat_Turn_Metrics::register( 'not a registry' ) );
	}
}
