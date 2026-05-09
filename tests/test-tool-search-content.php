<?php
/**
 * Tests for search_content tool (non-validated variant).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test search_content tool functionality.
 */
class Test_Tool_Search_Content extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Search_Content
	 */
	private $tool;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool      = new WP_MCP_AI_Tool_Search_Content();
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'search_content', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Unauthenticated call returns forbidden.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'search_term' => 'hello' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Empty search criteria returns missing_criteria error.
	 */
	public function test_empty_criteria_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_criteria', $result->get_error_code() );
	}

	/**
	 * Search returns array with items key for a keyword match.
	 */
	public function test_keyword_search_returns_results() {
		$unique = 'phpunit-unique-keyword-' . uniqid();
		$this->factory->post->create( array(
			'post_title'   => $unique,
			'post_content' => $unique,
			'post_status'  => 'publish',
		) );

		$result = $this->tool->execute(
			array( 'search_term' => $unique ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertNotEmpty( $result['items'] );
	}

	/**
	 * Search for a non-existent term returns empty items array.
	 */
	public function test_no_results_returns_empty_posts() {
		$result = $this->tool->execute(
			array( 'search_term' => 'zzz-no-such-content-' . uniqid() ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertEmpty( $result['items'] );
	}

	/**
	 * Limit parameter caps results.
	 */
	public function test_limit_caps_results() {
		$prefix = 'limit-test-' . uniqid();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->factory->post->create( array(
				'post_title'  => $prefix . '-' . $i,
				'post_status' => 'publish',
			) );
		}

		$result = $this->tool->execute(
			array( 'search_term' => $prefix, 'limit' => 2 ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertLessThanOrEqual( 2, count( $result['items'] ) );
	}
}
