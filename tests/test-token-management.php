<?php
/**
 * Tests for token management and chunking functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test token counting, chunking, and summarization features.
 */
class WP_MCP_AI_Token_Management_Test extends WP_UnitTestCase {

	/**
	 * Test text chunker token estimation.
	 */
	public function test_text_chunker_estimate_tokens() {
		// Test with known string: "Hello world" = 11 chars / 4 = ~3 tokens.
		$text      = 'Hello world';
		$estimated = WP_MCP_AI_Text_Chunker::estimate_tokens( $text );

		$this->assertGreaterThanOrEqual( 2, $estimated );
		$this->assertLessThanOrEqual( 4, $estimated );
	}

	/**
	 * Test text chunker chunking with default settings.
	 */
	public function test_text_chunker_chunk_text() {
		// Create a long text that will need chunking.
		$paragraph = str_repeat( 'This is a sentence. ', 100 ); // ~2000 chars.
		$text      = str_repeat( $paragraph . "\n\n", 3 ); // ~6000 chars.

		$chunks = WP_MCP_AI_Text_Chunker::chunk_text( $text, 1200, 100 );

		$this->assertIsArray( $chunks );
		$this->assertGreaterThan( 1, count( $chunks ), 'Text should be split into multiple chunks' );

		// Each chunk should be roughly around target size.
		foreach ( $chunks as $chunk ) {
			$this->assertIsString( $chunk );
			$this->assertLessThanOrEqual( 1500, strlen( $chunk ), 'Chunk should not exceed target significantly' );
		}
	}

	/**
	 * Test text chunker trimming to token budget.
	 */
	public function test_text_chunker_trim_to_token_budget() {
		$long_text = str_repeat( 'Word ', 10000 ); // ~50,000 chars = ~12,500 tokens.
		$trimmed   = WP_MCP_AI_Text_Chunker::trim_to_token_budget( $long_text, 100 );

		$estimated_tokens = WP_MCP_AI_Text_Chunker::estimate_tokens( $trimmed );
		$this->assertLessThanOrEqual( 110, $estimated_tokens, 'Trimmed text should fit within budget +10% margin' );
	}

	/**
	 * Test document summarizer on short text (should not summarize).
	 */
	public function test_document_summarizer_short_text() {
		$short_text = 'This is a short document.';
		$result     = WP_MCP_AI_Document_Summarizer::summarize_if_needed( $short_text );

		$this->assertEquals( $short_text, $result, 'Short text should not be summarized' );
	}

	/**
	 * Test document summarizer on long text (should summarize).
	 */
	public function test_document_summarizer_long_text() {
		$long_text = str_repeat( 'Paragraph content. ', 500 ); // ~10,000 chars.
		$result    = WP_MCP_AI_Document_Summarizer::summarize_if_needed( $long_text );

		$this->assertNotEquals( $long_text, $result, 'Long text should be summarized' );
		$this->assertLessThan( strlen( $long_text ), strlen( $result ), 'Summary should be shorter than original' );
		$this->assertStringContainsString( '[...]', $result, 'Summary should contain ellipsis markers' );
	}

	/**
	 * Test resource manager max concurrent requests.
	 */
	public function test_resource_manager_max_concurrent_requests() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

		$max_concurrent = $resource_mgr->get_max_concurrent_requests();
		$this->assertGreaterThanOrEqual( 1, $max_concurrent );
		$this->assertLessThanOrEqual( 10, $max_concurrent );

		// Test setting.
		$resource_mgr->set_max_concurrent_requests( 1 );
		$this->assertEquals( 1, $resource_mgr->get_max_concurrent_requests() );

		// Reset to default.
		$resource_mgr->set_max_concurrent_requests( 2 );
	}

	/**
	 * Test resource manager max input tokens.
	 */
	public function test_resource_manager_max_input_tokens() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

		$max_tokens = $resource_mgr->get_max_input_tokens();
		$this->assertGreaterThanOrEqual( 1000, $max_tokens );

		// Test setting.
		$resource_mgr->set_max_input_tokens( 100000 );
		$this->assertEquals( 100000, $resource_mgr->get_max_input_tokens() );

		// Reset to default.
		$resource_mgr->set_max_input_tokens( 120000 );
	}

	/**
	 * Test resource manager token budget validation.
	 */
	public function test_resource_manager_validate_token_budget() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

		// Within budget.
		$result = $resource_mgr->validate_token_budget( 50000 );
		$this->assertTrue( $result );

		// Over budget.
		$result = $resource_mgr->validate_token_budget( 200000 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_token_budget_exceeded', $result->get_error_code() );
	}

	/**
	 * Test OpenAI client token counting.
	 */
	public function test_openai_client_count_tokens() {
		$client = new WP_MCP_AI_OpenAI_Client();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, how are you?',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I am doing well, thank you!',
			),
		);

		$token_count = $client->count_tokens( $messages );

		$this->assertIsInt( $token_count );
		$this->assertGreaterThan( 0, $token_count );
		// Rough estimate: ~50 chars total / 4 = ~12 tokens + overhead.
		$this->assertGreaterThanOrEqual( 10, $token_count );
		$this->assertLessThanOrEqual( 20, $token_count );
	}

	/**
	 * Test job queue manager uses resource manager settings.
	 */
	public function test_job_queue_uses_resource_manager() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
		$original_max = $resource_mgr->get_max_concurrent_requests();

		// Set a specific value.
		$resource_mgr->set_max_concurrent_requests( 1 );

		// Process queue should respect this setting.
		// Since queue is empty, it should just report 0 processed.
		$result = WP_MCP_AI_Job_Queue_Manager::process_queue();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'processed', $result );

		// Reset.
		$resource_mgr->set_max_concurrent_requests( $original_max );
		WP_MCP_AI_Job_Queue_Manager::clear_queue();
	}
}
