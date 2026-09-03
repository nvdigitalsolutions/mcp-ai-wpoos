<?php
/**
 * Tests for the Token Budget Manager.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Token_Budget_Manager_Test extends WP_UnitTestCase {

	/**
	 * Test token estimation.
	 */
	public function test_estimate_tokens() {
		$text   = 'This is a simple test message.';
		$tokens = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( $text );

		$this->assertGreaterThan( 0, $tokens );
		$this->assertIsInt( $tokens );
	}

	/**
	 * Test empty string returns zero tokens.
	 */
	public function test_estimate_tokens_empty() {
		$tokens = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( '' );
		$this->assertSame( 0, $tokens );
	}

	/**
	 * Test model limit retrieval.
	 */
	public function test_get_model_limit() {
		$limit = WP_MCP_AI_Token_Budget_Manager::get_model_limit( 'gpt-4o' );
		$this->assertSame( 128000, $limit );

		$limit = WP_MCP_AI_Token_Budget_Manager::get_model_limit( 'gpt-4o-mini' );
		$this->assertSame( 128000, $limit );

		// Test DeepSeek R1 model.
		$limit = WP_MCP_AI_Token_Budget_Manager::get_model_limit( 'deepseek-r1-0528-qwen3-8b' );
		$this->assertSame( 32768, $limit );

		// Unknown model should return default.
		$limit = WP_MCP_AI_Token_Budget_Manager::get_model_limit( 'unknown-model' );
		$this->assertSame( 8192, $limit );
	}

	/**
	 * Test budget calculation.
	 */
	public function test_calculate_budget() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'Hello, how are you?',
			),
		);

		$budget = WP_MCP_AI_Token_Budget_Manager::calculate_budget( 'gpt-4o', $messages );

		$this->assertIsArray( $budget );
		$this->assertArrayHasKey( 'available', $budget );
		$this->assertArrayHasKey( 'used', $budget );
		$this->assertArrayHasKey( 'limit', $budget );
		$this->assertArrayHasKey( 'reserved', $budget );
		$this->assertGreaterThan( 0, $budget['available'] );
		$this->assertGreaterThan( 0, $budget['used'] );
	}

	/**
	 * Test message truncation.
	 */
	public function test_truncate_messages() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'Message 1',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Response 1',
			),
			array(
				'role'    => 'user',
				'content' => 'Message 2',
			),
		);

		// Truncate to very small limit.
		$truncated = WP_MCP_AI_Token_Budget_Manager::truncate_messages( $messages, 'gpt-4o', 50 );

		// Should preserve system message and most recent user message.
		$this->assertNotEmpty( $truncated );
		$this->assertLessThanOrEqual( count( $messages ), count( $truncated ) );

		// System message should be preserved.
		$has_system = false;
		foreach ( $truncated as $msg ) {
			if ( isset( $msg['role'] ) && 'system' === $msg['role'] ) {
				$has_system = true;
				break;
			}
		}
		$this->assertTrue( $has_system );
	}

	/**
	 * Test document splitting.
	 */
	public function test_split_document() {
		$content = str_repeat( 'This is a test sentence. ', 200 );
		$chunks  = WP_MCP_AI_Token_Budget_Manager::split_document( $content, 500, 50 );

		$this->assertIsArray( $chunks );
		$this->assertGreaterThan( 1, count( $chunks ) );

		// Each chunk should be non-empty.
		foreach ( $chunks as $chunk ) {
			$this->assertNotEmpty( $chunk );
		}
	}

	/**
	 * Test split document with small content.
	 */
	public function test_split_document_small_content() {
		$content = 'This is a small piece of content.';
		$chunks  = WP_MCP_AI_Token_Budget_Manager::split_document( $content, 1000 );

		$this->assertCount( 1, $chunks );
		$this->assertSame( $content, $chunks[0] );
	}

	/**
	 * Test should_stream recommendation.
	 */
	public function test_should_stream() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Short message',
			),
		);

		// Small message shouldn't trigger streaming even with a large threshold
		// (the reserved budget scales with the model's 128k context window).
		$should_stream = WP_MCP_AI_Token_Budget_Manager::should_stream( $messages, 'gpt-4o', 200000 );
		$this->assertFalse( $should_stream );

		// Small threshold should trigger streaming.
		$should_stream = WP_MCP_AI_Token_Budget_Manager::should_stream( $messages, 'gpt-4o', 100 );
		$this->assertTrue( $should_stream );
	}

	/**
	 * Test message optimization with truncation.
	 */
	public function test_optimize_messages_with_truncation() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'System prompt',
			),
			array(
				'role'    => 'user',
				'content' => 'User message 1',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Assistant response 1',
			),
			array(
				'role'    => 'user',
				'content' => 'User message 2',
			),
		);

		$optimized = WP_MCP_AI_Token_Budget_Manager::optimize_messages(
			$messages,
			'gpt-4o',
			array(
				'max_tokens'        => 100,
				'enable_truncation' => true,
			)
		);

		$this->assertIsArray( $optimized );
		$this->assertLessThanOrEqual( count( $messages ), count( $optimized ) );
	}

	/**
	 * Test message optimization with compression.
	 */
	public function test_optimize_messages_with_compression() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => "This    has    multiple     spaces\n\n\nand   newlines.",
			),
		);

		$optimized = WP_MCP_AI_Token_Budget_Manager::optimize_messages(
			$messages,
			'gpt-4o',
			array( 'enable_compression' => true )
		);

		$this->assertIsArray( $optimized );
		$this->assertCount( 1, $optimized );

		// Content should be compressed.
		$content = $optimized[0]['content'];
		$this->assertStringNotContainsString( '    ', $content );
		$this->assertStringNotContainsString( "\n\n\n", $content );
	}

	/**
	 * Test structured content token estimation.
	 */
	public function test_calculate_budget_with_structured_content() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Describe this image',
					),
					array(
						'type' => 'image',
						'url'  => 'https://example.com/image.jpg',
					),
				),
			),
		);

		$budget = WP_MCP_AI_Token_Budget_Manager::calculate_budget( 'gpt-4o', $messages );

		$this->assertIsArray( $budget );
		$this->assertGreaterThan( 0, $budget['used'] );
	}

	/**
	 * Test truncation when target is much lower than context window.
	 *
	 * Regression test: truncate_messages() previously compared used tokens
	 * against the model context window instead of the target, so truncation
	 * was skipped when TPM << context window (e.g. 40 000 TPM on Claude with
	 * a 200 000 context window).
	 */
	public function test_truncate_messages_respects_low_target() {
		// Build a conversation that is well under the Claude context window (200k)
		// but significantly over a 1 000-token target.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
		);

		// Each message: ~100 chars → ~25 tokens.  40 messages ≈ 1 000 tokens.
		for ( $i = 0; $i < 80; $i++ ) {
			$messages[] = array(
				'role'    => ( 0 === $i % 2 ) ? 'user' : 'assistant',
				'content' => str_repeat( 'word ', 20 ), // ~100 chars → ~25 tokens.
			);
		}

		// Total ≈ 2 000+ tokens but well under Claude's 200 000 context window.
		$target = 500; // A small TPM-derived target.

		$truncated = WP_MCP_AI_Token_Budget_Manager::truncate_messages( $messages, 'claude-3.5-sonnet', $target );

		// The truncated set MUST be smaller than the original.
		$this->assertLessThan( count( $messages ), count( $truncated ), 'truncate_messages should have removed messages to meet the target' );

		// Estimate tokens in truncated result.
		$tokens = 0;
		foreach ( $truncated as $msg ) {
			$tokens += WP_MCP_AI_Token_Budget_Manager::estimate_tokens( wp_json_encode( $msg ) );
		}

		$this->assertLessThanOrEqual( $target, $tokens, 'Truncated messages should fit within the target token budget' );

		// System message must be preserved.
		$roles = wp_list_pluck( $truncated, 'role' );
		$this->assertContains( 'system', $roles, 'System message should be preserved after truncation' );
	}

	/**
	 * Test truncation returns messages as-is when already under target.
	 */
	public function test_truncate_messages_noop_when_within_target() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'Short system prompt.',
			),
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		// Generous target that exceeds the tiny conversation.
		$truncated = WP_MCP_AI_Token_Budget_Manager::truncate_messages( $messages, 'gpt-4o', 10000 );

		$this->assertCount( count( $messages ), $truncated, 'Messages should not be truncated when within target' );
	}
}
