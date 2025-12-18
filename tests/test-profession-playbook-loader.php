<?php
/**
 * Test Profession Playbook Loader.
 *
 * Tests for the profession playbook loader service.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Profession_Playbook_Loader.
 */
class Test_Profession_Playbook_Loader extends WP_UnitTestCase {
	/**
	 * Test setup.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Test teardown.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that playbook loader class exists.
	 */
	public function test_playbook_loader_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Profession_Playbook_Loader' ) );
	}

	/**
	 * Test get_global_text() returns content.
	 */
	public function test_get_global_text_returns_content() {
		$loader = new WP_MCP_AI_Profession_Playbook_Loader();
		$text   = $loader->get_global_text();

		$this->assertNotEmpty( $text, 'Global text should not be empty' );
		$this->assertStringContainsString( 'Global Professional Assistant Guidelines', $text, 'Should contain expected header' );
		$this->assertStringContainsString( 'Professional Conduct', $text, 'Should contain professional conduct section' );
	}

	/**
	 * Test get_category_text() returns content for valid category.
	 */
	public function test_get_category_text_returns_content_for_valid_category() {
		$loader = new WP_MCP_AI_Profession_Playbook_Loader();
		$text   = $loader->get_category_text( 'technical' );

		$this->assertNotEmpty( $text, 'Technical category text should not be empty' );
		$this->assertStringContainsString( 'Technical Category Guidelines', $text, 'Should contain category header' );
	}

	/**
	 * Test get_category_text() returns empty for invalid category.
	 */
	public function test_get_category_text_returns_empty_for_invalid_category() {
		$loader = new WP_MCP_AI_Profession_Playbook_Loader();
		$text   = $loader->get_category_text( 'nonexistent_category' );

		$this->assertEmpty( $text, 'Nonexistent category should return empty string' );
	}

	/**
	 * Test get_profession_text() returns content for valid profession.
	 */
	public function test_get_profession_text_returns_content_for_valid_profession() {
		$loader = new WP_MCP_AI_Profession_Playbook_Loader();
		$text   = $loader->get_profession_text( 'accountant' );

		$this->assertNotEmpty( $text, 'Accountant profession text should not be empty' );
		$this->assertStringContainsString( 'Profession-Specific Guidelines', $text, 'Should contain profession header' );
	}

	/**
	 * Test get_profession_text() returns empty for invalid profession.
	 */
	public function test_get_profession_text_returns_empty_for_invalid_profession() {
		$loader = new WP_MCP_AI_Profession_Playbook_Loader();
		$text   = $loader->get_profession_text( 'nonexistent_profession' );

		$this->assertEmpty( $text, 'Nonexistent profession should return empty string' );
	}

	/**
	 * Test build_playbook() assembles complete playbook.
	 */
	public function test_build_playbook_assembles_complete_playbook() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test Accountant',
			'slug'        => 'test_accountant',
			'description' => 'Test accounting professional',
			'category'    => 'financial',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Build playbook.
		$loader   = new WP_MCP_AI_Profession_Playbook_Loader();
		$playbook = $loader->build_playbook( $post_id );

		$this->assertNotEmpty( $playbook, 'Playbook should not be empty' );

		// Check for expected sections.
		$this->assertStringContainsString( 'Test Accountant - Professional Playbook', $playbook, 'Should have title header' );
		$this->assertStringContainsString( 'Global Guidelines', $playbook, 'Should have global section' );
		$this->assertStringContainsString( 'Financial Category Guidelines', $playbook, 'Should have category section' );

		// Check for separators.
		$this->assertStringContainsString( '---', $playbook, 'Should have section separators' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test build_playbook() handles missing profession gracefully.
	 */
	public function test_build_playbook_handles_missing_profession() {
		$loader   = new WP_MCP_AI_Profession_Playbook_Loader();
		$playbook = $loader->build_playbook( 999999 );

		$this->assertEmpty( $playbook, 'Should return empty string for nonexistent profession' );
	}

	/**
	 * Test build_playbook() includes profession-specific content when available.
	 */
	public function test_build_playbook_includes_profession_specific_content() {
		// Create a test profession with a slug that has a txt file.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Accountant',
			'slug'        => 'accountant',
			'description' => 'Accounting professional',
			'category'    => 'financial',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Build playbook.
		$loader   = new WP_MCP_AI_Profession_Playbook_Loader();
		$playbook = $loader->build_playbook( $post_id );

		$this->assertNotEmpty( $playbook, 'Playbook should not be empty' );

		// Check that profession-specific section exists (accountant.txt exists).
		$this->assertStringContainsString( 'Specific Guidelines', $playbook, 'Should have profession-specific section' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test playbook content is UTF-8 encoded.
	 */
	public function test_playbook_content_is_utf8() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test Professional',
			'slug'        => 'test_professional',
			'description' => 'Test professional',
			'category'    => 'advisory',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Build playbook.
		$loader   = new WP_MCP_AI_Profession_Playbook_Loader();
		$playbook = $loader->build_playbook( $post_id );

		$this->assertNotEmpty( $playbook, 'Playbook should not be empty' );

		// Verify UTF-8 encoding.
		$this->assertTrue( mb_check_encoding( $playbook, 'UTF-8' ), 'Playbook should be valid UTF-8' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test all category files exist.
	 */
	public function test_all_category_files_exist() {
		$loader     = new WP_MCP_AI_Profession_Playbook_Loader();
		$categories = array( 'advisory', 'creative', 'technical', 'healthcare', 'legal', 'financial', 'other' );

		foreach ( $categories as $category ) {
			$text = $loader->get_category_text( $category );
			$this->assertNotEmpty( $text, "Category {$category} should have content" );
		}
	}
}
