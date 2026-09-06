<?php
/**
 * Prompt Optimizer port tests (Wave D1a).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\PromptOptimizer`. Every assertion pins
 * behaviour that must match the base plugin's
 * `WP_MCP_AI_Prompt_Optimizer` byte-for-byte (ecosystem port plan,
 * principle: behaviour-preserving).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\PromptOptimizer;

/**
 * @group chat
 */
class Test_PromptOptimizer extends \WP_UnitTestCase {

	public function test_order_for_cache_hit_places_system_prompt_first(): void {
		$messages = array(
			array( 'role' => 'user', 'content' => 'Hello' ),
		);

		$result = PromptOptimizer::order_for_cache_hit(
			$messages,
			array( 'system_prompt' => 'You are a helpful assistant.' )
		);

		$this->assertCount( 2, $result );
		$this->assertSame( 'system', $result[0]['role'] );
		$this->assertSame( 'You are a helpful assistant.', $result[0]['content'] );
		$this->assertSame( 'user', $result[1]['role'] );
		$this->assertSame( 'Hello', $result[1]['content'] );
	}

	public function test_order_for_cache_hit_drops_duplicate_system_messages(): void {
		$messages = array(
			array( 'role' => 'system', 'content' => 'Client-provided system message.' ),
			array( 'role' => 'user', 'content' => 'Hi' ),
		);

		$result = PromptOptimizer::order_for_cache_hit(
			$messages,
			array( 'system_prompt' => 'Canonical system prompt.' )
		);

		// The client system message is dropped; the options one leads.
		$this->assertCount( 2, $result );
		$this->assertSame( 'Canonical system prompt.', $result[0]['content'] );
		$this->assertSame( 'Hi', $result[1]['content'] );
	}

	public function test_order_for_cache_hit_inserts_memory_documents_after_system(): void {
		$messages = array(
			array( 'role' => 'user', 'content' => 'Question' ),
		);

		$result = PromptOptimizer::order_for_cache_hit(
			$messages,
			array(
				'system_prompt'    => 'Core instructions.',
				'memory_documents' => array(
					array( 'title' => 'Notes', 'content' => 'Remember this.' ),
					array( 'title' => '', 'content' => '' ),
				),
			)
		);

		// system → static reference → dynamic user message.
		$this->assertCount( 3, $result );
		$this->assertSame( 'system', $result[0]['role'] );
		$this->assertSame( 'Core instructions.', $result[0]['content'] );
		$this->assertSame( 'system', $result[1]['role'] );
		$this->assertStringContainsString( '[Reference: Notes] Remember this.', $result[1]['content'] );
		$this->assertSame( 'user', $result[2]['role'] );
	}

	public function test_order_for_cache_hit_skips_invalid_entries(): void {
		$result = PromptOptimizer::order_for_cache_hit(
			array(
				'not-an-array',
				array( 'content' => 'No role.' ),
				array( 'role' => 'assistant', 'content' => 'Valid.' ),
			),
			array()
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'assistant', $result[0]['role'] );
	}

	public function test_generate_cache_key_is_stable_and_prefixed(): void {
		$options = array( 'system_prompt' => 'Stable system prompt.' );
		$config  = array( 'ID' => 42 );

		$key_a = PromptOptimizer::generate_cache_key( $options, $config );
		$key_b = PromptOptimizer::generate_cache_key( $options, $config );

		$this->assertSame( $key_a, $key_b );
		// Byte-identical prefix format with the base implementation.
		$this->assertSame( 1, preg_match( '/^wp_mcp_ai_42_[0-9a-f]{32}$/', $key_a ) );
	}

	public function test_generate_cache_key_defaults_assistant_id_to_zero(): void {
		$key = PromptOptimizer::generate_cache_key( array(), array() );
		$this->assertSame( 1, preg_match( '/^wp_mcp_ai_0_[0-9a-f]{32}$/', $key ) );
	}

	public function test_split_system_prompt_at_context_separator(): void {
		$prompt = 'Static role definition.' . PromptOptimizer::CONTEXT_SEPARATOR . 'Current Date: 2026-09-01';

		$split = PromptOptimizer::split_system_prompt( $prompt );

		$this->assertSame( 'Static role definition.', $split['static_core'] );
		$this->assertStringContainsString( 'Current Date: 2026-09-01', $split['dynamic_context'] );
	}

	public function test_split_system_prompt_at_marker_without_separator(): void {
		$prompt = 'Static role definition. Current Date: 2026-09-01';

		$split = PromptOptimizer::split_system_prompt( $prompt );

		$this->assertSame( 'Static role definition.', $split['static_core'] );
		$this->assertSame( 'Current Date: 2026-09-01', $split['dynamic_context'] );
	}

	public function test_split_system_prompt_without_markers_returns_static_only(): void {
		$prompt = 'A plain system prompt with nothing dynamic.';

		$split = PromptOptimizer::split_system_prompt( $prompt );

		$this->assertSame( $prompt, $split['static_core'] );
		$this->assertSame( '', $split['dynamic_context'] );
	}

	public function test_is_caching_beneficial_requires_supported_provider_and_length(): void {
		$long_prompt = array( 'system_prompt' => str_repeat( 'x', 500 ) );

		$this->assertTrue( PromptOptimizer::is_caching_beneficial( $long_prompt, 'openai' ) );
		$this->assertTrue( PromptOptimizer::is_caching_beneficial( $long_prompt, 'anthropic' ) );
		$this->assertFalse( PromptOptimizer::is_caching_beneficial( $long_prompt, 'ollama' ) );

		$short_prompt = array( 'system_prompt' => 'short' );
		$this->assertFalse( PromptOptimizer::is_caching_beneficial( $short_prompt, 'openai' ) );
	}
}
