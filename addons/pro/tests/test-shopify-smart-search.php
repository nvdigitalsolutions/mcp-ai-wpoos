<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Tests for Shopify Smart Search progressive query relaxation.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Concrete class that uses the trait so we can unit-test its methods directly.
 *
 * @phpcs:ignore Universal.Files.OneObjectStructurePerFile.MultipleFound
 */
class Smart_Search_Test_Helper {
	use WP_MCP_AI_Shopify_Smart_Search;

	/**
	 * Expose the protected extract_search_tokens method for testing.
	 *
	 * @param string $query Search query.
	 * @return array
	 */
	public function test_extract_tokens( $query ) {
		return $this->extract_search_tokens( $query );
	}

	/**
	 * Expose the protected generate_sub_queries method for testing.
	 *
	 * @param array  $tokens         Meaningful tokens.
	 * @param string $original_query Original query string.
	 * @return array
	 */
	public function test_generate_sub_queries( array $tokens, $original_query = '' ) {
		return $this->generate_sub_queries( $tokens, $original_query );
	}

	/**
	 * Expose the protected should_decompose_query method for testing.
	 *
	 * @param string $query Search query.
	 * @return bool
	 */
	public function test_should_decompose( $query ) {
		return $this->should_decompose_query( $query );
	}

	/**
	 * Expose the protected merge_and_rank_products method for testing.
	 *
	 * @param array    $result_sets     Array of product arrays.
	 * @param callable $get_id_callback ID extraction callback.
	 * @param int      $limit           Max results.
	 * @return array
	 */
	public function test_merge_and_rank( array $result_sets, callable $get_id_callback, $limit = 50 ) {
		return $this->merge_and_rank_products( $result_sets, $get_id_callback, $limit );
	}
}

/**
 * Test case for Shopify Smart Search trait.
 */
class Test_Shopify_Smart_Search extends WP_UnitTestCase {

	/**
	 * Smart search helper instance.
	 *
	 * @var Smart_Search_Test_Helper
	 */
	protected $helper;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-smart-search.php';

		$this->helper = new Smart_Search_Test_Helper();
	}

	// ─── Token extraction tests ──────────────────────────────────────────

	/**
	 * Test that stop words are removed from query.
	 */
	public function test_extract_tokens_removes_stop_words() {
		$tokens = $this->helper->test_extract_tokens( 'the best diamond ring in the world' );

		$this->assertNotContains( 'the', $tokens );
		$this->assertNotContains( 'in', $tokens );
		$this->assertContains( 'best', $tokens );
		$this->assertContains( 'diamond', $tokens );
		$this->assertContains( 'ring', $tokens );
		$this->assertContains( 'world', $tokens );
	}

	/**
	 * Test that numeric values are preserved.
	 */
	public function test_extract_tokens_preserves_numbers() {
		$tokens = $this->helper->test_extract_tokens( '2 carat diamond ring 14k gold' );

		$this->assertContains( '2', $tokens );
		$this->assertContains( 'carat', $tokens );
		$this->assertContains( '14k', $tokens );
	}

	/**
	 * Test that tokens are lowercased.
	 */
	public function test_extract_tokens_lowercases() {
		$tokens = $this->helper->test_extract_tokens( 'Diamond RING Gold' );

		$this->assertContains( 'diamond', $tokens );
		$this->assertContains( 'ring', $tokens );
		$this->assertContains( 'gold', $tokens );
	}

	/**
	 * Test that duplicate tokens are removed.
	 */
	public function test_extract_tokens_deduplicates() {
		$tokens = $this->helper->test_extract_tokens( 'ring ring diamond diamond' );

		$this->assertCount( 2, $tokens );
	}

	/**
	 * Test empty query returns empty array.
	 */
	public function test_extract_tokens_empty_query() {
		$tokens = $this->helper->test_extract_tokens( '' );
		$this->assertEmpty( $tokens );
	}

	/**
	 * Test that purely stop-word queries return empty.
	 */
	public function test_extract_tokens_all_stop_words() {
		$tokens = $this->helper->test_extract_tokens( 'the and a or for' );
		$this->assertEmpty( $tokens );
	}

	/**
	 * Test the real-world failing query from the issue.
	 */
	public function test_extract_tokens_real_world_query() {
		$tokens = $this->helper->test_extract_tokens( 'solitaire round diamond engagement ring 2 carat' );

		$this->assertContains( 'solitaire', $tokens );
		$this->assertContains( 'round', $tokens );
		$this->assertContains( 'diamond', $tokens );
		$this->assertContains( 'engagement', $tokens );
		$this->assertContains( 'ring', $tokens );
		$this->assertContains( '2', $tokens );
		$this->assertContains( 'carat', $tokens );
		$this->assertCount( 7, $tokens );
	}

	// ─── Decomposition eligibility tests ────────────────────────────────

	/**
	 * Test that long queries qualify for decomposition.
	 */
	public function test_should_decompose_long_query() {
		$this->assertTrue( $this->helper->test_should_decompose( 'solitaire round diamond engagement ring 2 carat' ) );
	}

	/**
	 * Test that short queries do not qualify for decomposition.
	 */
	public function test_should_not_decompose_short_query() {
		$this->assertFalse( $this->helper->test_should_decompose( 'diamond ring' ) );
	}

	/**
	 * Test that single-word query does not qualify.
	 */
	public function test_should_not_decompose_single_word() {
		$this->assertFalse( $this->helper->test_should_decompose( 'diamond' ) );
	}

	/**
	 * Test that query with only stop words plus 2 real words does not qualify.
	 */
	public function test_should_not_decompose_mostly_stop_words() {
		$this->assertFalse( $this->helper->test_should_decompose( 'the best ring and the diamond' ) );
	}

	/**
	 * Test that exactly 3 meaningful tokens qualifies.
	 */
	public function test_should_decompose_exactly_three_tokens() {
		$this->assertTrue( $this->helper->test_should_decompose( 'diamond engagement ring' ) );
	}

	// ─── Sub-query generation tests ─────────────────────────────────────

	/**
	 * Test sub-query generation for the real-world failing query.
	 */
	public function test_generate_sub_queries_real_world() {
		$tokens      = $this->helper->test_extract_tokens( 'solitaire round diamond engagement ring 2 carat' );
		$sub_queries = $this->helper->test_generate_sub_queries( $tokens, 'solitaire round diamond engagement ring 2 carat' );

		$this->assertNotEmpty( $sub_queries );
		$this->assertLessThanOrEqual( 4, count( $sub_queries ) );

		// The cleaned query without numbers should be present.
		$has_without_numbers = false;
		foreach ( $sub_queries as $sq ) {
			if ( false === strpos( $sq, '2' ) && false !== strpos( $sq, 'diamond' ) ) {
				$has_without_numbers = true;
				break;
			}
		}
		$this->assertTrue( $has_without_numbers, 'Should generate a sub-query without numeric tokens.' );
	}

	/**
	 * Test that sub-queries are capped at max_sub_queries.
	 */
	public function test_generate_sub_queries_capped() {
		$tokens      = $this->helper->test_extract_tokens( 'vintage round brilliant cut diamond solitaire engagement ring' );
		$sub_queries = $this->helper->test_generate_sub_queries( $tokens, 'vintage round brilliant cut diamond solitaire engagement ring' );

		$this->assertLessThanOrEqual( 4, count( $sub_queries ) );
	}

	/**
	 * Test that sub-queries are unique.
	 */
	public function test_generate_sub_queries_no_duplicates() {
		$tokens      = $this->helper->test_extract_tokens( 'red blue green yellow purple' );
		$sub_queries = $this->helper->test_generate_sub_queries( $tokens, 'red blue green yellow purple' );

		$this->assertEquals( count( $sub_queries ), count( array_unique( $sub_queries ) ) );
	}

	/**
	 * Test that 3-token query generates appropriate sub-queries.
	 */
	public function test_generate_sub_queries_three_tokens() {
		$tokens      = array( 'diamond', 'engagement', 'ring' );
		$sub_queries = $this->helper->test_generate_sub_queries( $tokens, 'diamond engagement ring' );

		// With 3 tokens, there's no full-cleaned difference, no numeric tokens,
		// no trigrams (need >3), so we should get bigrams.
		$this->assertNotEmpty( $sub_queries );
	}

	// ─── Merge and rank tests ────────────────────────────────────────────

	/**
	 * Test that products appearing in multiple result sets rank higher.
	 */
	public function test_merge_ranks_by_frequency() {
		$set1 = array(
			array(
				'id'    => 'A',
				'title' => 'Product A',
			),
			array(
				'id'    => 'B',
				'title' => 'Product B',
			),
		);
		$set2 = array(
			array(
				'id'    => 'B',
				'title' => 'Product B',
			),
			array(
				'id'    => 'C',
				'title' => 'Product C',
			),
		);
		$set3 = array(
			array(
				'id'    => 'B',
				'title' => 'Product B',
			),
			array(
				'id'    => 'A',
				'title' => 'Product A',
			),
		);

		$merged = $this->helper->test_merge_and_rank(
			array( $set1, $set2, $set3 ),
			function ( $p ) {
				return $p['id'];
			}
		);

		// B appears in all 3 sets, should be first.
		$this->assertEquals( 'B', $merged[0]['id'] );
		// A appears in 2 sets, should be second.
		$this->assertEquals( 'A', $merged[1]['id'] );
		// C appears in 1 set, should be third.
		$this->assertEquals( 'C', $merged[2]['id'] );
	}

	/**
	 * Test that limit caps the number of returned products.
	 */
	public function test_merge_respects_limit() {
		$set1 = array(
			array(
				'id'    => 'A',
				'title' => 'Product A',
			),
			array(
				'id'    => 'B',
				'title' => 'Product B',
			),
			array(
				'id'    => 'C',
				'title' => 'Product C',
			),
		);

		$merged = $this->helper->test_merge_and_rank(
			array( $set1 ),
			function ( $p ) {
				return $p['id'];
			},
			2
		);

		$this->assertCount( 2, $merged );
	}

	/**
	 * Test that products with null/empty IDs are skipped.
	 */
	public function test_merge_skips_empty_ids() {
		$set1 = array(
			array(
				'id'    => '',
				'title' => 'No ID',
			),
			array(
				'id'    => 'A',
				'title' => 'Product A',
			),
		);

		$merged = $this->helper->test_merge_and_rank(
			array( $set1 ),
			function ( $p ) {
				return $p['id'];
			}
		);

		$this->assertCount( 1, $merged );
		$this->assertEquals( 'A', $merged[0]['id'] );
	}

	/**
	 * Test merge with empty result sets.
	 */
	public function test_merge_empty_sets() {
		$merged = $this->helper->test_merge_and_rank(
			array(),
			function ( $p ) {
				return $p['id'];
			}
		);

		$this->assertEmpty( $merged );
	}

	/**
	 * Test merge preserves order for same-score products.
	 */
	public function test_merge_preserves_order_for_ties() {
		$set1 = array(
			array(
				'id'    => 'A',
				'title' => 'Product A',
			),
			array(
				'id'    => 'B',
				'title' => 'Product B',
			),
			array(
				'id'    => 'C',
				'title' => 'Product C',
			),
		);

		$merged = $this->helper->test_merge_and_rank(
			array( $set1 ),
			function ( $p ) {
				return $p['id'];
			}
		);

		// All have score 1, should maintain original order.
		$this->assertEquals( 'A', $merged[0]['id'] );
		$this->assertEquals( 'B', $merged[1]['id'] );
		$this->assertEquals( 'C', $merged[2]['id'] );
	}

	// ─── Integration-level tests (schema) ────────────────────────────────

	/**
	 * Test that the Catalog tool schema includes smart_search parameter.
	 */
	public function test_catalog_schema_has_smart_search() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-connection-resolver.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-catalog.php';

		$tool   = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'smart_search', $schema['properties'] );
		$this->assertEquals( 'boolean', $schema['properties']['smart_search']['type'] );
		$this->assertTrue( $schema['properties']['smart_search']['default'] );
	}

	/**
	 * Test that the Products tool schema includes smart_search parameter.
	 */
	public function test_products_schema_has_smart_search() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-connection-resolver.php';

		if ( ! trait_exists( 'WP_MCP_AI_Tool_Product_Card' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-product-card.php';
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-products.php';

		$tool   = new WP_MCP_AI_Pro_Tool_Shopify_Products();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'smart_search', $schema['properties'] );
		$this->assertEquals( 'boolean', $schema['properties']['smart_search']['type'] );
		$this->assertTrue( $schema['properties']['smart_search']['default'] );
	}
}
