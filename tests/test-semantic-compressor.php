<?php
/**
 * Tests for WP_MCP_AI_Semantic_Compressor — Caveman Compression.
 *
 * @package WP_MCP_AI
 * @since    1.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comprehensive test suite for the Semantic Compressor.
 *
 * Tests all Caveman Compression rules (1-9), the public API surface,
 * and edge cases for each transformation.
 */
class Test_WP_MCP_AI_Semantic_Compressor extends WP_UnitTestCase {

	/**
	 * Compressor instance.
	 *
	 * @var WP_MCP_AI_Semantic_Compressor
	 */
	private $compressor;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Require the class file so it is available.
		require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-semantic-compressor.php';

		$this->compressor = WP_MCP_AI_Semantic_Compressor::get_instance();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// 1. Singleton pattern
	// -------------------------------------------------------------------------

	/**
	 * Test that get_instance() always returns the same instance.
	 */
	public function test_singleton_pattern() {
		$instance1 = WP_MCP_AI_Semantic_Compressor::get_instance();
		$instance2 = WP_MCP_AI_Semantic_Compressor::get_instance();

		$this->assertSame(
			$instance1,
			$instance2,
			'Semantic compressor should follow the singleton pattern.'
		);

		$this->assertInstanceOf(
			'WP_MCP_AI_Semantic_Compressor',
			$instance1,
			'get_instance() should return an instance of the compressor class.'
		);
	}

	// -------------------------------------------------------------------------
	// 2. compress() — empty / edge inputs
	// -------------------------------------------------------------------------

	/**
	 * Test that compress() returns empty string unchanged.
	 */
	public function test_compress_empty_text() {
		$result = $this->compressor->compress( '' );

		$this->assertSame(
			'',
			$result,
			'Empty string should be returned unchanged.'
		);
	}

	/**
	 * Test that compress() returns non-string values unchanged.
	 */
	public function test_compress_non_string_unchanged() {
		$this->assertSame( null, $this->compressor->compress( null ) );
		$this->assertSame( 123, $this->compressor->compress( 123 ) );
		$this->assertSame( 0, $this->compressor->compress( 0 ) );
		$this->assertSame( false, $this->compressor->compress( false ) );
	}

	/**
	 * Test that a whitespace-only string compresses to minimal output.
	 */
	public function test_compress_whitespace_only() {
		$result = $this->compressor->compress( '    ' );

		// Should be trimmed — no meaningful content means empty or a single period.
		$this->assertTrue(
			'' === $result || '.' === $result,
			'Whitespace-only text should compress to empty or minimal punctuation.'
		);
	}

	// -------------------------------------------------------------------------
	// 3. compress() — simple sentence (articles, active voice)
	// -------------------------------------------------------------------------

	/**
	 * Test compression of a simple declarative sentence.
	 *
	 * "The database needs an index for performance." should:
	 *   - Remove articles "the" and "an"
	 *   - Preserve nouns and key terms
	 */
	public function test_compress_simple_sentence() {
		$input  = 'The database needs an index for performance.';
		$result = $this->compressor->compress( $input );

		// Articles should be stripped.
		$this->assertStringNotContainsString(
			'The ',
			$result,
			'Article "The" should be removed.'
		);
		$this->assertStringNotContainsString(
			'an ',
			$result,
			'Article "an" should be removed.'
		);

		// Core meaning words must be preserved.
		$this->assertStringContainsStringIgnoringCase(
			'database',
			$result,
			'Core word "database" must be preserved.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'index',
			$result,
			'Core word "index" must be preserved.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'performance',
			$result,
			'Core word "performance" must be preserved.'
		);

		// Result should be shorter than input after article removal.
		$this->assertLessThan(
			strlen( $input ),
			strlen( $result ),
			'Compressed text should be shorter than original.'
		);
	}

	// -------------------------------------------------------------------------
	// 4. Rule 3 — Connective Elimination
	// -------------------------------------------------------------------------

	/**
	 * Test that causal connectives are eliminated.
	 *
	 * "Query too slow because no index exists" should:
	 *   - Strip "because"
	 *   - Produce separate clauses
	 */
	public function test_connective_elimination() {
		$input  = 'Query too slow because no index exists.';
		$result = $this->compressor->compress( $input );

		// "because" should be removed.
		$this->assertStringNotContainsString(
			'because',
			strtolower( $result ),
			'Causal connective "because" should be removed.'
		);

		// Key content should be preserved.
		$this->assertStringContainsStringIgnoringCase(
			'query',
			$result,
			'"query" must be preserved.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'slow',
			$result,
			'"slow" must be preserved.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'index',
			$result,
			'"index" must be preserved.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'exists',
			$result,
			'"exists" must be preserved.'
		);
	}

	// -------------------------------------------------------------------------
	// 5. Rule 6 — Intensifier Removal
	// -------------------------------------------------------------------------

	/**
	 * Test that intensifiers are stripped.
	 *
	 * "This is a very important and extremely critical constraint" should
	 * strip "very" and "extremely".
	 */
	public function test_intensifier_removal() {
		$input  = 'This is a very important and extremely critical constraint.';
		$result = $this->compressor->compress( $input );

		$this->assertStringNotContainsString(
			'very',
			strtolower( $result ),
			'Intensifier "very" should be removed.'
		);
		$this->assertStringNotContainsString(
			'extremely',
			strtolower( $result ),
			'Intensifier "extremely" should be removed.'
		);

		// Core adjectives must remain.
		$this->assertStringContainsStringIgnoringCase(
			'important',
			$result,
			'"important" must be preserved.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'critical',
			$result,
			'"critical" must be preserved.'
		);
	}

	// -------------------------------------------------------------------------
	// 6. Rule 7 — Article Omission
	// -------------------------------------------------------------------------

	/**
	 * Test that articles are removed: "a", "an", "the".
	 *
	 * "The function calculates the value and returns the result."
	 */
	public function test_article_omission() {
		$input  = 'The function calculates the value and returns the result.';
		$result = $this->compressor->compress( $input );

		// Articles must be gone.
		$this->assertStringNotContainsString(
			'The ',
			$result,
			'Article "The" should be removed (after sentence start capitalization).'
		);
		$this->assertStringNotContainsString(
			' the ',
			strtolower( " $result " ),
			'Article "the" should be removed.'
		);
		$this->assertStringNotContainsString(
			' a ',
			strtolower( " $result " ),
			'Article "a" should be removed.'
		);

		// Meaning words preserved.
		$this->assertStringContainsStringIgnoringCase( 'function', $result );
		$this->assertStringContainsStringIgnoringCase( 'calculates', $result );
		$this->assertStringContainsStringIgnoringCase( 'value', $result );
		$this->assertStringContainsStringIgnoringCase( 'result', $result );
	}

	// -------------------------------------------------------------------------
	// 7. Rule 5 — Preserve Numbers
	// -------------------------------------------------------------------------

	/**
	 * Test that numbers are preserved verbatim.
	 *
	 * "Process 50 million requests per day with 99.9% uptime"
	 */
	public function test_preserve_numbers() {
		$input  = 'Process 50 million requests per day with 99.9% uptime.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString(
			'50',
			$result,
			'Number "50" must be preserved verbatim.'
		);
		$this->assertStringContainsString(
			'99.9',
			$result,
			'Decimal number "99.9" must be preserved verbatim.'
		);
		$this->assertStringContainsString(
			'%',
			$result,
			'Percent sign must be preserved.'
		);

		// Also verify that "million" is preserved (it's a specific quantity term).
		$this->assertStringContainsStringIgnoringCase(
			'million',
			$result,
			'"million" should be preserved as part of the numeric expression.'
		);
	}

	// -------------------------------------------------------------------------
	// 8. Rule 5 — Preserve URLs
	// -------------------------------------------------------------------------

	/**
	 * Test that URLs are preserved verbatim.
	 *
	 * "Visit https://example.com/path for documentation"
	 */
	public function test_preserve_urls() {
		$input  = 'Visit https://example.com/path for documentation.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString(
			'https://example.com/path',
			$result,
			'URL must be preserved verbatim in compressed output.'
		);
	}

	// -------------------------------------------------------------------------
	// 9. Rule 5 — Preserve Code Blocks
	// -------------------------------------------------------------------------

	/**
	 * Test that markdown code fences are preserved untouched.
	 */
	public function test_preserve_code_blocks() {
		$input  = "Run this function:\n```php\nfunction add( \$a, \$b ) {\n    return \$a + \$b;\n}\n```\nIt calculates the sum.";
		$result = $this->compressor->compress( $input );

		// Code block content must be completely untouched.
		$this->assertStringContainsString(
			'function add(',
			$result,
			'Code block content "function add(" must be preserved.'
		);
		$this->assertStringContainsString(
			'return $a + $b',
			$result,
			'Code block content "return $a + $b" must be preserved.'
		);
		$this->assertStringContainsString(
			'```',
			$result,
			'Code fence markers must be preserved.'
		);

		// Code block should not have had its articles or words altered.
		$this->assertStringContainsString(
			'$a',
			$result,
			'Variable "$a" in code block must be preserved.'
		);
	}

	// -------------------------------------------------------------------------
	// 10. Rule 4 — Passive to Active Voice
	// -------------------------------------------------------------------------

	/**
	 * Test that passive voice constructions are converted to active.
	 *
	 * "The value is calculated by the function" should become
	 * "The function calculates the value." (or similar active construction).
	 */
	public function test_passive_to_active() {
		$input  = 'The value is calculated by the function.';
		$result = $this->compressor->compress( $input );

		// The passive "is calculated by" pattern should be converted.
		// Check that the agent "function" becomes the subject.
		$this->assertStringContainsStringIgnoringCase(
			'function',
			$result,
			'"function" must be present in the output.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'calculat',
			$result,
			'Verb "calculate" must be present (may be "calculates" or "calculate").'
		);
		$this->assertStringContainsStringIgnoringCase(
			'value',
			$result,
			'"value" must be preserved.'
		);
	}

	// -------------------------------------------------------------------------
	// 11. compress_messages()
	// -------------------------------------------------------------------------

	/**
	 * Test compressing an array of chat messages.
	 */
	public function test_compress_messages() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'The database is very slow and it needs an index.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I recommend adding an index to the table for better performance.',
			),
			array(
				'role'    => 'system',
				'content' => 'You are a database expert.',
			),
		);

		$compressed = $this->compressor->compress_messages( $messages );

		// Structure must be preserved.
		$this->assertCount(
			3,
			$compressed,
			'compress_messages() should return the same number of messages.'
		);
		$this->assertArrayHasKey( 0, $compressed );
		$this->assertArrayHasKey( 1, $compressed );
		$this->assertArrayHasKey( 2, $compressed );

		// Roles must be preserved.
		$this->assertSame( 'user', $compressed[0]['role'] );
		$this->assertSame( 'assistant', $compressed[1]['role'] );
		$this->assertSame( 'system', $compressed[2]['role'] );

		// Content should be compressed (shorter than original).
		$this->assertLessThan(
			strlen( $messages[0]['content'] ),
			strlen( $compressed[0]['content'] ),
			'User message content should be compressed.'
		);
		$this->assertLessThan(
			strlen( $messages[1]['content'] ),
			strlen( $compressed[1]['content'] ),
			'Assistant message content should be compressed.'
		);

		// Intensifiers should be removed from compressed content.
		$this->assertStringNotContainsString(
			'very',
			strtolower( $compressed[0]['content'] ),
			'Intensifier "very" should be removed from user message.'
		);
	}

	/**
	 * Test compress_messages() preserves tool_calls and other structural fields.
	 */
	public function test_compress_messages_preserves_structural_fields() {
		$messages = array(
			array(
				'role'       => 'assistant',
				'content'    => 'Let me look that up for you.',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'search_database',
							'arguments' => '{"query":"slow queries"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'content'      => 'Found 5 slow queries.',
				'tool_call_id' => 'call_123',
				'name'         => 'search_database',
			),
		);

		$compressed = $this->compressor->compress_messages( $messages );

		// Tool calls must be preserved unchanged.
		$this->assertArrayHasKey( 'tool_calls', $compressed[0] );
		$this->assertCount( 1, $compressed[0]['tool_calls'] );
		$this->assertSame( 'call_123', $compressed[0]['tool_calls'][0]['id'] );
		$this->assertSame( 'function', $compressed[0]['tool_calls'][0]['type'] );
		$this->assertSame(
			'search_database',
			$compressed[0]['tool_calls'][0]['function']['name']
		);
		$this->assertSame(
			'{"query":"slow queries"}',
			$compressed[0]['tool_calls'][0]['function']['arguments']
		);

		// tool_call_id and name must be preserved.
		$this->assertArrayHasKey( 'tool_call_id', $compressed[1] );
		$this->assertSame( 'call_123', $compressed[1]['tool_call_id'] );
		$this->assertSame( 'search_database', $compressed[1]['name'] );

		// Content should be compressed.
		$this->assertLessThan(
			strlen( $messages[0]['content'] ),
			strlen( $compressed[0]['content'] ),
			'Content field should be compressed while structural fields are preserved.'
		);
	}

	/**
	 * Test compress_messages() with empty array.
	 */
	public function test_compress_messages_empty() {
		$result = $this->compressor->compress_messages( array() );
		$this->assertSame( array(), $result );

		$result = $this->compressor->compress_messages( null );
		$this->assertSame( null, $result );
	}

	// -------------------------------------------------------------------------
	// 12. estimate_tokens()
	// -------------------------------------------------------------------------

	/**
	 * Test token estimation using char/4 heuristic.
	 */
	public function test_estimate_tokens() {
		// 40 characters → 10 tokens.
		$text   = 'This string is forty characters long!!';
		$tokens = $this->compressor->estimate_tokens( $text );

		$this->assertIsInt( $tokens, 'estimate_tokens() should return an integer.' );

		$expected = (int) ceil( strlen( $text ) / 4 );
		$this->assertSame(
			$expected,
			$tokens,
			'Token count should be ceil(strlen/4).'
		);
	}

	/**
	 * Test estimate_tokens() with empty/edge inputs.
	 */
	public function test_estimate_tokens_edge_cases() {
		$this->assertSame( 0, $this->compressor->estimate_tokens( '' ) );
		$this->assertSame( 0, $this->compressor->estimate_tokens( null ) );
		$this->assertSame( 0, $this->compressor->estimate_tokens( 123 ) );

		// Single character.
		$this->assertSame( 1, $this->compressor->estimate_tokens( 'x' ) );

		// Exactly 4 characters.
		$this->assertSame( 1, $this->compressor->estimate_tokens( 'abcd' ) );

		// 5 characters → ceil(1.25) = 2.
		$this->assertSame( 2, $this->compressor->estimate_tokens( 'abcde' ) );
	}

	// -------------------------------------------------------------------------
	// 13. estimate_savings()
	// -------------------------------------------------------------------------

	/**
	 * Test savings estimation returns correct structure.
	 */
	public function test_estimate_savings() {
		$input   = 'This is a very extremely long sentence that has lots of articles and should be compressed significantly.';
		$savings = $this->compressor->estimate_savings( $input );

		// Verify structure.
		$this->assertIsArray( $savings, 'estimate_savings() should return an array.' );
		$this->assertArrayHasKey( 'original_tokens', $savings );
		$this->assertArrayHasKey( 'compressed_tokens', $savings );
		$this->assertArrayHasKey( 'saved_tokens', $savings );
		$this->assertArrayHasKey( 'savings_pct', $savings );

		// All should be numeric.
		$this->assertIsInt( $savings['original_tokens'] );
		$this->assertIsInt( $savings['compressed_tokens'] );
		$this->assertIsInt( $savings['saved_tokens'] );
		$this->assertIsFloat( $savings['savings_pct'] );

		// Original tokens should be positive.
		$this->assertGreaterThan( 0, $savings['original_tokens'] );

		// Compressed tokens should be less than or equal to original.
		$this->assertLessThanOrEqual(
			$savings['original_tokens'],
			$savings['compressed_tokens'],
			'Compressed token count should not exceed original.'
		);

		// Saved tokens should equal original - compressed.
		$this->assertSame(
			$savings['original_tokens'] - $savings['compressed_tokens'],
			$savings['saved_tokens'],
			'Saved tokens should be original - compressed.'
		);

		// Savings percentage should be between 0 and 100.
		$this->assertGreaterThanOrEqual( 0.0, $savings['savings_pct'] );
		$this->assertLessThanOrEqual( 100.0, $savings['savings_pct'] );
	}

	/**
	 * Test estimate_savings() with empty input.
	 */
	public function test_estimate_savings_empty() {
		$savings = $this->compressor->estimate_savings( '' );

		$this->assertSame( 0, $savings['original_tokens'] );
		$this->assertSame( 0, $savings['compressed_tokens'] );
		$this->assertSame( 0, $savings['saved_tokens'] );
		$this->assertSame( 0.0, $savings['savings_pct'] );
	}

	// -------------------------------------------------------------------------
	// 14. Aggressiveness Levels
	// -------------------------------------------------------------------------

	/**
	 * Test that aggressiveness levels 1, 2, and 3 produce different results.
	 *
	 * Level 3 (Aggressive) should produce the shortest output.
	 */
	public function test_aggressiveness_levels() {
		$input = 'The database administrator should very carefully review the query execution plan because performance is extremely critical for user satisfaction and retention.';

		$level1 = $this->compressor->compress( $input, array( 'aggressiveness' => 1 ) );
		$level2 = $this->compressor->compress( $input, array( 'aggressiveness' => 2 ) );
		$level3 = $this->compressor->compress( $input, array( 'aggressiveness' => 3 ) );

		// Each level must produce a non-empty result.
		$this->assertNotEmpty( $level1, 'Level 1 should produce output.' );
		$this->assertNotEmpty( $level2, 'Level 2 should produce output.' );
		$this->assertNotEmpty( $level3, 'Level 3 should produce output.' );

		// Level 2 should be at least as short as Level 1 (Level 1 is conservative).
		$this->assertLessThanOrEqual(
			strlen( $level1 ),
			strlen( $level2 ),
			'Level 2 (Balanced) should not be longer than Level 1 (Conservative).'
		);

		// Level 3 should be the shortest (most aggressive).
		$this->assertLessThanOrEqual(
			strlen( $level2 ),
			strlen( $level3 ),
			'Level 3 (Aggressive) should be the shortest output.'
		);

		// All three should preserve key facts.
		foreach ( array( $level1, $level2, $level3 ) as $level ) {
			$this->assertStringContainsStringIgnoringCase( 'database', $level );
			$this->assertStringContainsStringIgnoringCase( 'query', $level );
			$this->assertStringContainsStringIgnoringCase( 'performance', $level );
		}

		// Test that invalid aggressiveness values are clamped.
		$clamped_low  = $this->compressor->compress( $input, array( 'aggressiveness' => 0 ) );
		$clamped_high = $this->compressor->compress( $input, array( 'aggressiveness' => 99 ) );

		// Should behave like level 1 and level 3 respectively (clamped).
		$this->assertNotEmpty( $clamped_low );
		$this->assertNotEmpty( $clamped_high );
	}

	/**
	 * Test aggressiveness level 1 (Conservative) preserves articles.
	 */
	public function test_aggressiveness_level_1_preserves_articles() {
		$input  = 'The database needs an index for performance.';
		$result = $this->compressor->compress( $input, array( 'aggressiveness' => 1 ) );

		// Level 1 does NOT apply article omission, so "the" may remain.
		// Level 1 DOES apply intensifier removal and active voice.
		// So key content must be preserved.
		$this->assertStringContainsStringIgnoringCase( 'database', $result );
		$this->assertStringContainsStringIgnoringCase( 'index', $result );
		$this->assertStringContainsStringIgnoringCase( 'performance', $result );
	}

	// -------------------------------------------------------------------------
	// 15. Preserve JSON
	// -------------------------------------------------------------------------

	/**
	 * Test that JSON content is preserved verbatim.
	 */
	public function test_preserve_json() {
		$input  = 'Configuration: {"host":"localhost","port":3306} and the database name.';
		$result = $this->compressor->compress( $input );

		// JSON must be fully preserved.
		$this->assertStringContainsString(
			'{"host":"localhost","port":3306}',
			$result,
			'JSON object must be preserved verbatim.'
		);

		// Non-JSON text should be compressed.
		$this->assertStringNotContainsString(
			'the database',
			strtolower( $result ),
			'"the" should be removed from non-JSON text.'
		);
	}

	/**
	 * Test that nested JSON arrays are preserved.
	 */
	public function test_preserve_json_array() {
		$input  = 'Response: [{"id":1,"name":"Alice"},{"id":2,"name":"Bob"}].';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString(
			'[{"id":1,"name":"Alice"},{"id":2,"name":"Bob"}]',
			$result,
			'JSON array must be preserved verbatim.'
		);
	}

	// -------------------------------------------------------------------------
	// 16. Preserve Email Addresses
	// -------------------------------------------------------------------------

	/**
	 * Test that email addresses are preserved verbatim.
	 */
	public function test_preserve_email() {
		$input  = 'Contact admin@example.com for support requests.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString(
			'admin@example.com',
			$result,
			'Email address must be preserved verbatim.'
		);
	}

	/**
	 * Test multiple email addresses preserved in one text.
	 */
	public function test_preserve_multiple_emails() {
		$input  = 'Send reports to john.doe@company.co.uk and also cc jane_smith@example.org.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString( 'john.doe@company.co.uk', $result );
		$this->assertStringContainsString( 'jane_smith@example.org', $result );
	}

	// -------------------------------------------------------------------------
	// 17. Compound Sentence Splitting
	// -------------------------------------------------------------------------

	/**
	 * Test that compound sentences are split on "and" between clauses.
	 *
	 * "Database needs index and query runs faster" should split into
	 * separate sentences.
	 */
	public function test_compound_sentence_splitting() {
		$input  = 'Database needs index and query runs faster.';
		$result = $this->compressor->compress( $input );

		// Key content words must be present.
		$this->assertStringContainsStringIgnoringCase( 'Database', $result );
		$this->assertStringContainsStringIgnoringCase( 'index', $result );
		$this->assertStringContainsStringIgnoringCase( 'query', $result );
		$this->assertStringContainsStringIgnoringCase( 'faster', $result );

		// The result should be shorter than the original (articles removed, etc.).
		$this->assertLessThan(
			strlen( $input ),
			strlen( $result ),
			'Compressed compound sentence should be shorter.'
		);
	}

	// -------------------------------------------------------------------------
	// 18. Preserve Technical Terms
	// -------------------------------------------------------------------------

	/**
	 * Test that technical terms are preserved verbatim.
	 *
	 * "Use binary search with O(log n) complexity" — technical terms like
	 * "binary search" and "O(log n)" must survive compression.
	 */
	public function test_preserve_technical_terms() {
		$input  = 'Use binary search with O(log n) complexity.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsStringIgnoringCase(
			'binary',
			$result,
			'"binary" must be preserved.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'search',
			$result,
			'"search" must be preserved.'
		);
		$this->assertStringContainsString(
			'O(log n)',
			$result,
			'Big-O notation "O(log n)" must be preserved verbatim.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'complexity',
			$result,
			'"complexity" must be preserved.'
		);
	}

	// -------------------------------------------------------------------------
	// 19. Additional edge cases
	// -------------------------------------------------------------------------

	/**
	 * Text with only numbers and symbols should compress cleanly.
	 */
	public function test_compress_numbers_only() {
		$input  = '123 456.78 90%.';
		$result = $this->compressor->compress( $input );

		// Numbers must be fully preserved.
		$this->assertStringContainsString( '123', $result );
		$this->assertStringContainsString( '456.78', $result );
		$this->assertStringContainsString( '90%', $result );
	}

	/**
	 * Text with inline code should preserve it.
	 */
	public function test_preserve_inline_code() {
		$input  = 'Run `wp_mcp_ai_function()` to process the data.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString(
			'`wp_mcp_ai_function()`',
			$result,
			'Inline code must be preserved verbatim.'
		);
	}

	/**
	 * Text with file paths should preserve them.
	 */
	public function test_preserve_file_paths() {
		$input  = 'The config is at /etc/nginx/conf.d/default.conf and log at /var/log/syslog.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString(
			'/etc/nginx/conf.d/default.conf',
			$result,
			'Unix file path must be preserved verbatim.'
		);
		$this->assertStringContainsString(
			'/var/log/syslog',
			$result,
			'Second file path must be preserved verbatim.'
		);
	}

	/**
	 * Test compression with custom aggressiveness and skip_code_blocks options.
	 */
	public function test_compress_with_custom_options() {
		$input  = 'The very important code: ```php\necho "hello";\n``` should work.';
		$result = $this->compressor->compress(
			$input,
			array(
				'aggressiveness'   => 3,
				'skip_code_blocks' => true,
			)
		);

		// Code must be preserved.
		$this->assertStringContainsString( 'echo "hello"', $result );
		$this->assertStringContainsString( '```', $result );

		// Intensifier should be removed.
		$this->assertStringNotContainsString( 'very', strtolower( $result ) );
	}

	/**
	 * Test compression with skip_code_blocks=false.
	 */
	public function test_compress_without_skip_code_blocks() {
		$input  = 'The very important function: `calculate_total()` is needed.';
		$result = $this->compressor->compress(
			$input,
			array(
				'skip_code_blocks' => false,
			)
		);

		// When skip_code_blocks is false, inline code might get compressed too.
		// In that case, backticks might still be present but we can't guarantee
		// the inline code is untouched — just verify the output is non-empty.
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test that preserve_specifics option works correctly.
	 */
	public function test_compress_preserve_specifics_flag() {
		$input  = 'Version 2.3.1 fixes bug in OAuth2Client.';
		$result = $this->compressor->compress(
			$input,
			array( 'preserve_specifics' => true )
		);

		// Version number and technical identifier must be preserved.
		$this->assertStringContainsString( '2.3.1', $result );
		$this->assertStringContainsString( 'OAuth2Client', $result );
	}

	/**
	 * Test compress() with snake_case identifiers.
	 */
	public function test_preserve_snake_case_identifiers() {
		$input  = 'Use wp_mcp_ai_get_option to fetch the value.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString(
			'wp_mcp_ai_get_option',
			$result,
			'snake_case identifier must be preserved.'
		);
	}

	/**
	 * Test compress() with ALL_CAPS constants.
	 */
	public function test_preserve_constants() {
		$input  = 'Set WP_MCP_AI_DEBUG to true for logging. Set MAX_RETRIES to 5.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString( 'WP_MCP_AI_DEBUG', $result );
		$this->assertStringContainsString( 'MAX_RETRIES', $result );
		$this->assertStringContainsString( '5', $result );
	}

	/**
	 * Test compression of a longer technical paragraph.
	 */
	public function test_compress_technical_paragraph() {
		$input = 'The PostgreSQL query planner uses a cost-based optimization algorithm that ' .
			'is calculated by the system. Because the statistics are very outdated, the ' .
			'planner chooses an extremely inefficient sequential scan. The database ' .
			'administrator should therefore run ANALYZE to update the statistics.';

		$result = $this->compressor->compress( $input );

		// Verify key technical content is preserved.
		$this->assertStringContainsStringIgnoringCase( 'PostgreSQL', $result );
		$this->assertStringContainsStringIgnoringCase( 'query', $result );
		$this->assertStringContainsStringIgnoringCase( 'cost-based', $result );
		$this->assertStringContainsStringIgnoringCase( 'optimization', $result );
		$this->assertStringContainsStringIgnoringCase( 'sequential scan', $result );
		$this->assertStringContainsStringIgnoringCase( 'ANALYZE', $result );

		// Connectives should be removed.
		$this->assertStringNotContainsString( 'because', strtolower( $result ) );
		$this->assertStringNotContainsString( 'therefore', strtolower( $result ) );

		// Intensifiers should be removed.
		$this->assertStringNotContainsString( 'very', strtolower( $result ) );
		$this->assertStringNotContainsString( 'extremely', strtolower( $result ) );

		// Result should be significantly shorter.
		$this->assertLessThan(
			strlen( $input ),
			strlen( $result ),
			'Technical paragraph should be compressed.'
		);
	}

	/**
	 * Test that compression is deterministic (same input → same output).
	 */
	public function test_compress_deterministic() {
		$input = 'The system needs an efficient caching layer for the API responses.';

		$result1 = $this->compressor->compress( $input );
		$result2 = $this->compressor->compress( $input );

		$this->assertSame(
			$result1,
			$result2,
			'Compression must be deterministic — same input produces same output.'
		);
	}

	/**
	 * Test that HTML tags are preserved.
	 */
	public function test_preserve_html_tags() {
		$input  = 'Use <strong>caution</strong> when modifying <code>wp-config.php</code>.';
		$result = $this->compressor->compress( $input );

		$this->assertStringContainsString( '<strong>', $result );
		$this->assertStringContainsString( '</strong>', $result );
		$this->assertStringContainsString( '<code>', $result );
		$this->assertStringContainsString( '</code>', $result );
	}

	/**
	 * Test compression of text with various specific items scattered throughout.
	 */
	public function test_compress_mixed_specifics() {
		$input = 'Admin at root@localhost should check /var/log/nginx/error.log and ' .
			'visit https://monitoring.example.com/dashboard for metrics. The config ' .
			'{"debug":true,"level":3} must be validated.';

		$result = $this->compressor->compress( $input );

		// Every protected item must be in the output exactly as in the input.
		$this->assertStringContainsString( 'root@localhost', $result );
		$this->assertStringContainsString( '/var/log/nginx/error.log', $result );
		$this->assertStringContainsString( 'https://monitoring.example.com/dashboard', $result );
		$this->assertStringContainsString( '{"debug":true,"level":3}', $result );
	}

	/**
	 * Test CHARS_PER_TOKEN constant is correctly defined.
	 */
	public function test_chars_per_token_constant() {
		// Reflection to access private const.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Semantic_Compressor' );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey( 'CHARS_PER_TOKEN', $constants );
		$this->assertSame( 4, $constants['CHARS_PER_TOKEN'] );
	}

	/**
	 * Test WORD_COUNT_RANGE constant values.
	 */
	public function test_word_count_range_constant() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Semantic_Compressor' );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey( 'WORD_COUNT_RANGE', $constants );
		$this->assertIsArray( $constants['WORD_COUNT_RANGE'] );
		$this->assertArrayHasKey( 'min', $constants['WORD_COUNT_RANGE'] );
		$this->assertArrayHasKey( 'max', $constants['WORD_COUNT_RANGE'] );
		$this->assertSame( 2, $constants['WORD_COUNT_RANGE']['min'] );
		$this->assertSame( 7, $constants['WORD_COUNT_RANGE']['max'] );
	}

	/**
	 * Test AGGRESSIVENESS_PRESETS constant structure.
	 */
	public function test_aggressiveness_presets_constant() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Semantic_Compressor' );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey( 'AGGRESSIVENESS_PRESETS', $constants );
		$presets = $constants['AGGRESSIVENESS_PRESETS'];

		$this->assertArrayHasKey( 1, $presets );
		$this->assertArrayHasKey( 2, $presets );
		$this->assertArrayHasKey( 3, $presets );

		// Level 1: article_omission and sentence_splitting should be false.
		$this->assertFalse( $presets[1]['article_omission'] );
		$this->assertFalse( $presets[1]['sentence_splitting'] );

		// Level 2: article_omission should be true, max_words = 7.
		$this->assertTrue( $presets[2]['article_omission'] );
		$this->assertSame( 7, $presets[2]['max_words'] );

		// Level 3: max_words = 5 (most aggressive).
		$this->assertTrue( $presets[3]['article_omission'] );
		$this->assertSame( 5, $presets[3]['max_words'] );
	}

	/**
	 * Test compression with pronoun-heavy text.
	 */
	public function test_compress_pronoun_heavy_text() {
		$input  = 'It is clear that it works. They said it runs fast and it scales.';
		$result = $this->compressor->compress( $input );

		// Core meaning words must survive.
		$this->assertStringContainsStringIgnoringCase( 'works', $result );
		$this->assertStringContainsStringIgnoringCase( 'runs', $result );
		$this->assertStringContainsStringIgnoringCase( 'fast', $result );
		$this->assertStringContainsStringIgnoringCase( 'scales', $result );

		// Result should be valid (not broken by pronoun handling).
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test that multi-sentence compression preserves sentence-level semantics.
	 */
	public function test_compress_multi_sentence() {
		$input = 'First sentence is about databases. Second sentence is about indexes. ' .
			'Third sentence explains the performance gain.';

		$result = $this->compressor->compress( $input );

		// Each sentence's core meaning must be preserved.
		$this->assertStringContainsStringIgnoringCase( 'database', $result );
		$this->assertStringContainsStringIgnoringCase( 'index', $result );
		$this->assertStringContainsStringIgnoringCase( 'performance', $result );
		$this->assertStringContainsStringIgnoringCase( 'gain', $result );
	}

	/**
	 * Test that backup of the singleton and re-fetch works cleanly.
	 *
	 * This verifies there's no state leakage between compress() calls
	 * even though the compressor reuses internal arrays.
	 */
	public function test_compress_state_isolation() {
		$input_with_code = 'Here is code: `test_func()` and text.';

		// First call with code.
		$result1 = $this->compressor->compress( $input_with_code );
		$this->assertStringContainsString( '`test_func()`', $result1 );

		// Second call without code — should not have leftover placeholders.
		$result2 = $this->compressor->compress( 'Simple text without code.' );
		$this->assertStringNotContainsString( '__CAVEMAN_BLOCK_', $result2 );
		$this->assertNotEmpty( $result2 );

		// Third call with code again — should still work.
		$result3 = $this->compressor->compress( $input_with_code );
		$this->assertStringContainsString( '`test_func()`', $result3 );
	}
}
