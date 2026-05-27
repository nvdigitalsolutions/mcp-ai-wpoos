<?php
/**
 * Test: paper_store_search tool.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Tool_Paper_Store_Search
 *
 * @covers WP_MCP_AI_Tool_Paper_Store_Search
 */
class Test_WP_MCP_AI_Tool_Paper_Store_Search extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Paper_Store_Search
	 */
	private $tool;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();
		$this->tool = new WP_MCP_AI_Tool_Paper_Store_Search();

		$this->seed_records(
			'search-test',
			array(
				array(
					'id'          => 'dior',
					'title'       => 'Dior Sauvage',
					'description' => 'Fresh spicy fragrance by Dior',
					'tags'        => array( 'perfume' ),
					'status'      => 'published',
				),
				array(
					'id'          => 'creed',
					'title'       => 'Creed Aventus',
					'description' => 'Fruity chypre fragrance',
					'tags'        => array( 'perfume' ),
					'status'      => 'published',
				),
				array(
					'id'          => 'article',
					'title'       => 'How to Write',
					'description' => 'A guide to writing.',
					'tags'        => array( 'writing' ),
					'status'      => 'draft',
				),
			)
		);

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->tool = null;
		$this->tear_down_paper_store();
		parent::tearDown();
	}

	/**
	 * Search should find records by title.
	 */
	public function test_search_by_title() {
		$result = $this->tool->execute(
			array(
				'query'      => 'Dior',
				'collection' => 'search-test',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
	}

	/**
	 * Search should find records by description.
	 */
	public function test_search_by_description() {
		$result = $this->tool->execute(
			array(
				'query'      => 'chypre',
				'collection' => 'search-test',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
	}

	/**
	 * Search should return empty for no matches.
	 */
	public function test_search_no_matches() {
		$result = $this->tool->execute(
			array(
				'query'      => 'zzz_nonexistent_zzz',
				'collection' => 'search-test',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['count'] );
	}

	/**
	 * Search should require query parameter.
	 */
	public function test_search_requires_query() {
		$result = $this->tool->execute( array() );
		$this->assertWPError( $result );
	}

	/**
	 * Search should deny without capability.
	 */
	public function test_search_denies_no_capability() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute( array( 'query' => 'test' ) );
		$this->assertWPError( $result );
	}
}
