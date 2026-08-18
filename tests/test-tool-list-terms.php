<?php
/**
 * Tests for list_terms tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test list_terms tool functionality.
 */
class Test_Tool_List_Terms extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_List_Terms
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_List_Terms();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'list_terms', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Unauthenticated call returns forbidden error.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'taxonomy' => 'category' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Missing taxonomy returns missing_taxonomy error.
	 */
	public function test_missing_taxonomy_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_taxonomy', $result->get_error_code() );
	}

	/**
	 * Invalid taxonomy returns invalid_taxonomy error with valid taxonomies listed.
	 */
	public function test_invalid_taxonomy_returns_error() {
		$result = $this->tool->execute(
			array( 'taxonomy' => 'nonexistent_taxonomy_xyz' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_taxonomy', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'valid_taxonomies', $data );
		$this->assertContains( 'category', $data['valid_taxonomies'] );
	}

	/**
	 * Listing categories returns terms with the expected shape.
	 */
	public function test_lists_categories() {
		$term_result = wp_insert_term( 'Garden Terms ' . uniqid(), 'category' );
		$this->assertIsArray( $term_result );

		$result = $this->tool->execute(
			array( 'taxonomy' => 'category' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'category', $result['taxonomy'] );
		$this->assertGreaterThanOrEqual( 1, $result['total_found'] );
		$this->assertNotEmpty( $result['terms'] );

		$first = $result['terms'][0];
		$this->assertArrayHasKey( 'term_id', $first );
		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'slug', $first );
		$this->assertArrayHasKey( 'parent', $first );
		$this->assertArrayHasKey( 'count', $first );
		$this->assertGreaterThan( 0, $first['term_id'] );
	}

	/**
	 * Search filter narrows results to matching terms.
	 */
	public function test_search_filter_matches_name() {
		$needle = 'NeedleTerm' . uniqid();
		wp_insert_term( $needle, 'category' );
		wp_insert_term( 'Unrelated Term ' . uniqid(), 'category' );

		$result = $this->tool->execute(
			array(
				'taxonomy' => 'category',
				'search'   => $needle,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total_found'] );
		$this->assertSame( $needle, $result['terms'][0]['name'] );
	}

	/**
	 * Limit caps the returned term count.
	 */
	public function test_limit_is_respected() {
		$result = $this->tool->execute(
			array(
				'taxonomy' => 'category',
				'limit'    => 2,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 2, count( $result['terms'] ) );
	}

	/**
	 * Hide empty returns only terms with posts.
	 */
	public function test_hide_empty_filters_terms() {
		wp_insert_term( 'Empty Term ' . uniqid(), 'category' );

		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$result  = wp_set_object_terms( $post_id, 'Populated Term ' . uniqid(), 'category' );
		$this->assertIsArray( $result );

		$result = $this->tool->execute(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );

		$names = wp_list_pluck( $result['terms'], 'name' );
		foreach ( $names as $name ) {
			$this->assertStringNotContainsString( 'Empty Term ', $name );
		}
	}

	/**
	 * Capability flags include 'read-only' and 'local-only'.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'local-only', $flags );
	}
}
