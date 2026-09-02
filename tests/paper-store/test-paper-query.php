<?php
/**
 * Test: Paper Query — Fluent query builder.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Paper_Query
 *
 * @covers WP_MCP_AI_Paper_Query
 */
class Test_WP_MCP_AI_Paper_Query extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * Repository for query tests.
	 *
	 * @var WP_MCP_AI_Paper_Repository
	 */
	private $repo;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();
		$this->repo = $this->manager->get_repository( 'query-test' );

		// Seed records.
		$this->seed_records(
			'query-test',
			array(
				array(
					'id'          => 'perfume-dior',
					'title'       => 'Dior Sauvage',
					'description' => 'Fresh spicy fragrance',
					'tags'        => array( 'perfume', 'dior', 'men' ),
					'status'      => 'published',
					'type'        => 'knowledge',
				),
				array(
					'id'          => 'perfume-creed',
					'title'       => 'Creed Aventus',
					'description' => 'Fruity chypre fragrance',
					'tags'        => array( 'perfume', 'creed', 'men' ),
					'status'      => 'published',
					'type'        => 'knowledge',
				),
				array(
					'id'          => 'draft-post',
					'title'       => 'Draft Article',
					'description' => 'Not yet published',
					'tags'        => array( 'writing' ),
					'status'      => 'draft',
					'type'        => 'article',
				),
			)
		);
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->repo = null;
		$this->tear_down_paper_store();
		parent::tearDown();
	}

	/**
	 * Query with no filters should return all records.
	 */
	public function test_get_all_records() {
		$results = $this->repo->query()->get();
		$this->assertCount( 3, $results );
	}

	/**
	 * Query with where tag filter.
	 */
	public function test_where_tag_filter() {
		$results = $this->repo->where( 'tags', '=', 'perfume' )->get();
		$this->assertCount( 2, $results );
	}

	/**
	 * Query with a negative tag filter.
	 */
	public function test_where_tag_not_equal_filter() {
		$results = $this->repo->where( 'tags', '!=', 'perfume' )->get();
		$this->assertCount( 1, $results );
		$this->assertSame( 'draft-post', $results[0]['id'] );
	}

	/**
	 * Query with where status filter.
	 */
	public function test_where_status_filter() {
		$results = $this->repo->where( 'status', '=', 'draft' )->get();
		$this->assertCount( 1, $results );
		$this->assertSame( 'draft-post', $results[0]['id'] );
	}

	/**
	 * Query with where type filter.
	 */
	public function test_where_type_filter() {
		$results = $this->repo->where( 'type', '=', 'article' )->get();
		$this->assertCount( 1, $results );
	}

	/**
	 * Query with where LIKE filter (post-filter).
	 */
	public function test_where_like_filter() {
		$results = $this->repo->where( 'title', 'LIKE', 'Dior' )->get();
		$this->assertCount( 1, $results );
		$this->assertSame( 'perfume-dior', $results[0]['id'] );
	}

	/**
	 * Query with limit.
	 */
	public function test_limit() {
		$results = $this->repo->query()->limit( 1 )->get();
		$this->assertCount( 1, $results );
	}

	/**
	 * Query with offset.
	 */
	public function test_offset() {
		$results = $this->repo->query()->limit( 1 )->offset( 1 )->get();
		$this->assertCount( 1, $results );
	}

	/**
	 * First should return single record.
	 */
	public function test_first() {
		$record = $this->repo->query()->first();
		$this->assertIsArray( $record );
	}

	/**
	 * Count should return correct count.
	 */
	public function test_count() {
		$count = $this->repo->where( 'tags', '=', 'perfume' )->count();
		$this->assertSame( 2, $count );
	}

	/**
	 * Chained where conditions use AND logic.
	 */
	public function test_chained_where_and_logic() {
		$results = $this->repo->where( 'tags', '=', 'perfume' )
			->where( 'status', '=', 'published' )
			->get();
		$this->assertCount( 2, $results );
	}

	/**
	 * Empty where result should return empty array.
	 */
	public function test_no_results_returns_empty_array() {
		$results = $this->repo->where( 'tags', '=', 'nonexistent' )->get();
		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}

	/**
	 * Pagination results should not overlap.
	 */
	public function test_pagination_no_overlap() {
		$page1 = $this->repo->query()->limit( 2 )->offset( 0 )->get();
		$page2 = $this->repo->query()->limit( 2 )->offset( 2 )->get();

		$ids_page1 = wp_list_pluck( $page1, 'id' );
		$ids_page2 = wp_list_pluck( $page2, 'id' );

		$overlap = array_intersect( $ids_page1, $ids_page2 );
		$this->assertEmpty( $overlap );
	}
}
