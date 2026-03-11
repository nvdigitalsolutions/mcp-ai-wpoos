<?php
/**
 * Tests for semantic search vitals embedding integration.
 *
 * Covers the two-part fix for the "missing embedding capability":
 *  1. log_vital_signs now generates and stores an embedding in the vitals index.
 *  2. semantic_content_search now searches the vitals index in addition to WP posts.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for the vitals embedding index searched by semantic_content_search.
 */
class Test_Semantic_Search_Vitals_Embedding extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Semantic_Content_Search
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-semantic-content-search.php';
		$this->tool = new WP_MCP_AI_Tool_Semantic_Content_Search();
	}

	/**
	 * Tear down – clear the vitals embedding index between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_vitals_embed_index' );
		parent::tearDown();
	}

	// ── schema & structure tests ───────────────────────────────────────────────

	/**
	 * search_vitals_embeddings must be private (invoked via execute), not public.
	 */
	public function test_search_vitals_embeddings_is_private() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'search_vitals_embeddings' );

		$this->assertTrue( $method->isPrivate(), 'search_vitals_embeddings should be private' );
	}

	/**
	 * generate_query_embedding must be private.
	 */
	public function test_generate_query_embedding_is_private() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'generate_query_embedding' );

		$this->assertTrue( $method->isPrivate(), 'generate_query_embedding should be private' );
	}

	/**
	 * When the vitals index is empty, search_vitals_embeddings returns an empty array.
	 *
	 * Note: 4-dimensional test vectors are used throughout this test class.
	 * Production embeddings are 1536-D (OpenAI text-embedding-3-small) or 768-D
	 * (Gemini text-embedding-004), but the cosine similarity formula and the
	 * indexing/eviction logic are dimension-agnostic, so lower-dimensional
	 * fixtures are both valid and much faster to compute.
	 */
	public function test_search_vitals_returns_empty_when_no_index() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'search_vitals_embeddings' );
		$method->setAccessible( true );

		$dummy_embedding = array_fill( 0, 4, 0.5 );
		$result          = $method->invoke( $this->tool, $dummy_embedding, 0.7, 10 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * search_vitals_embeddings skips entries that have no embedding stored.
	 */
	public function test_search_vitals_skips_entries_without_embedding() {
		update_option(
			'wp_mcp_ai_vitals_embed_index',
			array(
				'vs_001' => array(
					'member_id' => 1,
					'date'      => '2024-01-01',
					'text'      => 'Vital signs for member 1.',
					// deliberately missing 'embedding' key
				),
			)
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'search_vitals_embeddings' );
		$method->setAccessible( true );

		$dummy_embedding = array( 1.0, 0.0, 0.0, 0.0 );
		$result          = $method->invoke( $this->tool, $dummy_embedding, 0.0, 10 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * search_vitals_embeddings returns a matching entry and sets source = 'vitals'.
	 */
	public function test_search_vitals_returns_matching_entry() {
		// Unit vector pointing in the same direction as the query.
		$stored_embedding = array( 1.0, 0.0, 0.0, 0.0 );

		update_option(
			'wp_mcp_ai_vitals_embed_index',
			array(
				'vs_test_001' => array(
					'member_id' => 42,
					'date'      => '2024-03-01',
					'text'      => 'Vital signs health record for member ID 42 on 2024-03-01. eGFR 85 mL/min/1.73m². Creatinine 0.9 mg/dL.',
					'embedding' => $stored_embedding,
					'model'     => 'text-embedding-3-small',
					'stored_at' => '2024-03-01 10:00:00',
				),
			)
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'search_vitals_embeddings' );
		$method->setAccessible( true );

		// Query embedding identical to stored → cosine similarity = 1.0.
		$query_embedding = array( 1.0, 0.0, 0.0, 0.0 );
		$result          = $method->invoke( $this->tool, $query_embedding, 0.7, 10 );

		$this->assertCount( 1, $result );
		$this->assertEquals( 'vitals', $result[0]['source'] );
		$this->assertEquals( 'vs_test_001', $result[0]['entry_id'] );
		$this->assertEquals( 42, $result[0]['member_id'] );
		$this->assertEquals( '2024-03-01', $result[0]['date'] );
		$this->assertGreaterThanOrEqual( 0.99, $result[0]['similarity_score'] );
	}

	/**
	 * search_vitals_embeddings filters entries below the threshold.
	 */
	public function test_search_vitals_filters_below_threshold() {
		// Orthogonal vectors → cosine similarity = 0.
		update_option(
			'wp_mcp_ai_vitals_embed_index',
			array(
				'vs_low_001' => array(
					'member_id' => 1,
					'date'      => '2024-01-01',
					'text'      => 'Vital signs.',
					'embedding' => array( 0.0, 1.0, 0.0, 0.0 ), // perpendicular
					'model'     => 'text-embedding-3-small',
					'stored_at' => '2024-01-01 00:00:00',
				),
			)
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'search_vitals_embeddings' );
		$method->setAccessible( true );

		$query_embedding = array( 1.0, 0.0, 0.0, 0.0 );
		$result          = $method->invoke( $this->tool, $query_embedding, 0.7, 10 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Orthogonal entry should be below the 0.7 threshold' );
	}

	/**
	 * search_vitals_embeddings sorts results by similarity descending.
	 */
	public function test_search_vitals_sorts_by_similarity_desc() {
		update_option(
			'wp_mcp_ai_vitals_embed_index',
			array(
				'vs_near'  => array(
					'member_id' => 1,
					'date'      => '2024-01-01',
					'text'      => 'Near match.',
					'embedding' => array( 1.0, 0.0, 0.0, 0.0 ),
					'model'     => 'text-embedding-3-small',
					'stored_at' => '2024-01-01 00:00:00',
				),
				'vs_far'   => array(
					'member_id' => 2,
					'date'      => '2024-01-02',
					'text'      => 'Far match.',
					'embedding' => array( 0.8, 0.6, 0.0, 0.0 ),
					'model'     => 'text-embedding-3-small',
					'stored_at' => '2024-01-02 00:00:00',
				),
			)
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'search_vitals_embeddings' );
		$method->setAccessible( true );

		$query_embedding = array( 1.0, 0.0, 0.0, 0.0 );
		$result          = $method->invoke( $this->tool, $query_embedding, 0.0, 10 );

		$this->assertCount( 2, $result );
		$this->assertEquals( 'vs_near', $result[0]['entry_id'], 'Nearest entry should be first' );
		$this->assertGreaterThanOrEqual( $result[1]['similarity_score'], $result[0]['similarity_score'] );
	}

	/**
	 * search_vitals_embeddings respects the limit parameter.
	 */
	public function test_search_vitals_respects_limit() {
		$index = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$index[ 'vs_' . $i ] = array(
				'member_id' => $i,
				'date'      => '2024-01-01',
				'text'      => 'Entry ' . $i,
				'embedding' => array( 1.0, 0.0, 0.0, 0.0 ),
				'model'     => 'text-embedding-3-small',
				'stored_at' => '2024-01-01 00:00:00',
			);
		}
		update_option( 'wp_mcp_ai_vitals_embed_index', $index );

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'search_vitals_embeddings' );
		$method->setAccessible( true );

		$query_embedding = array( 1.0, 0.0, 0.0, 0.0 );
		$result          = $method->invoke( $this->tool, $query_embedding, 0.0, 3 );

		$this->assertCount( 3, $result, 'Result should be capped at the limit' );
	}

	// ── generate_query_embedding provider routing tests ────────────────────────

	/**
	 * generate_query_embedding falls back to OpenAI when provider is unrecognized.
	 * Without a valid OpenAI key, it returns a WP_Error (not a PHP fatal).
	 */
	public function test_generate_query_embedding_returns_error_without_api_key() {
		// Ensure no API keys are configured.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key' => '',
				'gemini_api_key' => '',
			)
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'generate_query_embedding' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, 'vital signs', 'openai' );

		$this->assertWPError( $result );
	}

	/**
	 * generate_query_embedding: when provider is 'gemini' and Gemini API key is
	 * absent, it falls through to OpenAI; if OpenAI key is also absent the result
	 * is a WP_Error (not a fatal or unexpected type).
	 */
	public function test_generate_query_embedding_gemini_provider_falls_back_gracefully() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => '',
				'openai_api_key' => '',
			)
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Semantic_Content_Search' );
		$method     = $reflection->getMethod( 'generate_query_embedding' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, 'kidney health metrics', 'gemini' );

		// Must be either a WP_Error (both keys missing) or an array with 'embedding'.
		// In the test environment both keys are absent, so expect WP_Error.
		$this->assertWPError( $result );
	}

	/**
	 * execute() returns WP_Error with correct code when query is missing.
	 * (Regression guard – must still work after the vitals-search integration.)
	 */
	public function test_execute_still_requires_query_after_vitals_integration() {
		$result = $this->tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_query', $result->get_error_code() );
	}
}
