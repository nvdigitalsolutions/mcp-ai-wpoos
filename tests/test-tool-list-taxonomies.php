<?php
/**
 * Tests for list_taxonomies tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test list_taxonomies tool functionality.
 */
class Test_Tool_List_Taxonomies extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_List_Taxonomies
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

		$this->tool = new WP_MCP_AI_Tool_List_Taxonomies();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'list_taxonomies', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Unauthenticated call returns forbidden error.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute( array(), array( 'user_id' => 0 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Listing taxonomies includes built-in category and post_tag.
	 */
	public function test_lists_builtin_taxonomies() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 2, $result['total_found'] );

		$names = wp_list_pluck( $result['taxonomies'], 'name' );
		$this->assertContains( 'category', $names );
		$this->assertContains( 'post_tag', $names );

		$first = $result['taxonomies'][0];
		$this->assertArrayHasKey( 'label', $first );
		$this->assertArrayHasKey( 'hierarchical', $first );
		$this->assertArrayHasKey( 'public', $first );
		$this->assertArrayHasKey( 'object_types', $first );
	}

	/**
	 * Category taxonomy is reported as hierarchical.
	 */
	public function test_category_is_hierarchical() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );

		$categories = array_values(
			array_filter(
				$result['taxonomies'],
				function ( $item ) {
					return 'category' === $item['name'];
				}
			)
		);

		$this->assertNotEmpty( $categories );
		$this->assertTrue( $categories[0]['hierarchical'] );
	}

	/**
	 * Public filter narrows the list to public taxonomies.
	 */
	public function test_public_filter() {
		register_taxonomy(
			'private_tax_' . uniqid(),
			'post',
			array(
				'label'  => 'Private Tax',
				'public' => false,
			)
		);

		$result = $this->tool->execute(
			array( 'public' => true ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );

		foreach ( $result['taxonomies'] as $item ) {
			$this->assertTrue( $item['public'] );
		}
	}

	/**
	 * Object type filter lists only taxonomies attached to the given post type.
	 */
	public function test_object_type_filter() {
		$result = $this->tool->execute(
			array( 'object_type' => 'post' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );

		foreach ( $result['taxonomies'] as $item ) {
			$this->assertContains( 'post', $item['object_types'] );
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
