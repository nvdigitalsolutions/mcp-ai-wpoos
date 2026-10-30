<?php
/**
 * Test Profession Reseeding.
 *
 * Tests for the profession reseeding functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class Test_Profession_Reseeding.
 */
class Test_Profession_Reseeding extends WP_UnitTestCase {
	/**
	 * Test setup.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear any existing professions.
		$professions = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);

		foreach ( $professions as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Clear seeded option.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );
	}

	/**
	 * Test teardown.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that profession repository exists and works.
	 */
	public function test_profession_repository_exists() {
		$repository = new WP_MCP_AI_Profession_Repository();
		$this->assertInstanceOf( WP_MCP_AI_Profession_Repository::class, $repository );
	}

	/**
	 * Test that profession loader can load professions.
	 */
	public function test_profession_loader_can_load() {
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_all();

		$this->assertIsArray( $professions, 'Loader should return an array' );
		$this->assertNotEmpty( $professions, 'Loader should return professions' );
	}

	/**
	 * Test saving a profession via repository.
	 */
	public function test_save_profession() {
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Test Profession',
			'slug'             => 'test_profession',
			'description'      => 'A test profession for unit testing.',
			'category'         => 'technical',
			'role_description' => 'Test role description',
			'expertise'        => array( 'Testing', 'Quality Assurance' ),
			'warnings'         => array( 'This is a test warning' ),
			'knowledge_base'   => 'Test knowledge base content',
			'default_tools'    => array( 'web_search', 'search_content' ),
		);

		$post_id = $repository->save( $profession_data );

		$this->assertIsInt( $post_id, 'Save should return a post ID' );
		$this->assertGreaterThan( 0, $post_id, 'Post ID should be positive' );

		// Verify the profession was saved correctly.
		$post = get_post( $post_id );
		$this->assertEquals( 'Test Profession', $post->post_title );
		$this->assertEquals( 'test_profession', $post->post_name );

		// Verify metadata.
		$category = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
		$this->assertEquals( 'technical', $category );

		$expertise = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
		$this->assertIsArray( $expertise );
		$this->assertContains( 'Testing', $expertise );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test updating an existing profession.
	 */
	public function test_update_profession() {
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Original Title',
			'slug'             => 'test_update_profession',
			'description'      => 'Original description',
			'category'         => 'technical',
			'role_description' => 'Original role',
			'expertise'        => array( 'Skill 1' ),
			'warnings'         => array(),
			'knowledge_base'   => 'Original KB',
			'default_tools'    => array(),
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Update the profession.
		$updated_data = array(
			'id'               => $post_id,
			'title'            => 'Updated Title',
			'slug'             => 'test_update_profession',
			'description'      => 'Updated description',
			'category'         => 'creative',
			'role_description' => 'Updated role',
			'expertise'        => array( 'Skill 1', 'Skill 2' ),
			'warnings'         => array( 'Warning 1' ),
			'knowledge_base'   => 'Updated KB',
			'default_tools'    => array( 'web_search' ),
		);

		$result = $repository->save( $updated_data );
		$this->assertEquals( $post_id, $result, 'Update should return the same post ID' );

		// Verify the update.
		$post = get_post( $post_id );
		$this->assertEquals( 'Updated Title', $post->post_title );

		$category = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
		$this->assertEquals( 'creative', $category );

		$expertise = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
		$this->assertCount( 2, $expertise );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test finding a profession by slug.
	 */
	public function test_find_profession_by_slug() {
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Find Me',
			'slug'             => 'test_find_profession',
			'description'      => 'Test',
			'category'         => 'technical',
			'role_description' => 'Test',
			'expertise'        => array(),
			'warnings'         => array(),
			'knowledge_base'   => 'Test',
			'default_tools'    => array(),
		);

		$post_id = $repository->save( $profession_data );

		// Find by slug.
		$found = $repository->find_one( 'test_find_profession' );
		$this->assertInstanceOf( WP_Post::class, $found );
		$this->assertEquals( $post_id, $found->ID );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test seeding option is set after initial seed.
	 */
	public function test_seeding_option_is_set() {
		// Clear option first.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );

		// Load and save professions manually (simulating what seeder does).
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_all();
		$repository  = new WP_MCP_AI_Profession_Repository();

		// Save at least one profession.
		if ( ! empty( $professions ) ) {
			$repository->save( $professions[0] );
		}

		// Set the option as seeder would.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Verify option is set.
		$is_seeded = get_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, false );
		$this->assertTrue( $is_seeded );
	}

	/**
	 * Test clearing seeded option allows re-seeding.
	 */
	public function test_clearing_seeded_option() {
		// Set option.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );
		$this->assertTrue( get_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, false ) );

		// Clear option.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );
		$this->assertFalse( get_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, false ) );
	}

	/**
	 * Test that re-seeding with the seeded option missing does NOT
	 * duplicate professions that already exist in the database.
	 *
	 * The classic ballooning bug: the seeder runs on admin_init whenever
	 * the seeded option is absent and blindly inserted the whole catalog,
	 * so a database that already had professions (option lost via a
	 * settings reset or staging sync) gained a full duplicate set on
	 * every admin page load.
	 */
	public function test_seeder_updates_existing_instead_of_duplicating() {
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_all();
		$this->assertNotEmpty( $professions );

		$repository = new WP_MCP_AI_Profession_Repository();

		// Pre-seed the first two KB professions so the DB already contains
		// professions when the seeder runs.
		$pre_seeded_slugs = array();
		foreach ( array_slice( $professions, 0, 2 ) as $profession_data ) {
			$repository->save( $profession_data );
			$pre_seeded_slugs[] = sanitize_title( $profession_data['slug'] );
		}

		// Ensure the seeded option is absent, then run the seeder.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );
		WP_MCP_AI_Profession_Seeder::seed_professions();

		// Each pre-seeded profession must still exist exactly once.
		foreach ( $pre_seeded_slugs as $slug ) {
			$matches = get_posts(
				array(
					'post_type'      => 'mcp_ai_profession',
					'name'           => $slug,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'fields'         => 'ids',
				)
			);
			$this->assertCount( 1, $matches, 'Seeding must not duplicate existing profession: ' . $slug );
		}

		// The total published count must equal the KB count — no duplicates.
		$counts = wp_count_posts( 'mcp_ai_profession' );
		$this->assertEquals( count( $professions ), isset( $counts->publish ) ? $counts->publish : 0, 'Seeding must not balloon the published profession count' );

		// Clean up the seeded option so later tests don't skip seeding.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );
	}

	/**
	 * Test that clear_cache() invalidates the wp_count_posts() counts cache.
	 *
	 * Core only invalidates the post-type counts cache on status
	 * transitions; force-deletes (what the reseed handler uses to remove
	 * duplicate copies) leave it stale. With a persistent object cache the
	 * admin Data Management panel would keep showing the ballooned count
	 * forever. The plugin's delete_post hook routes through clear_cache(),
	 * so this pins the end-to-end healing path.
	 */
	public function test_clear_cache_invalidates_count_posts() {
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Counts Cache Test',
			'slug'             => 'test_counts_cache',
			'description'      => 'Test',
			'category'         => 'technical',
			'role_description' => 'Test',
			'expertise'        => array(),
			'warnings'         => array(),
			'knowledge_base'   => 'Test',
			'default_tools'    => array(),
		);

		$post_id_1 = $repository->save( $profession_data );
		$post_id_2 = $repository->save(
			array_merge(
				$profession_data,
				array(
					'title' => 'Counts Cache Test 2',
					'slug'  => 'test_counts_cache_2',
				)
			)
		);

		// Prime the counts cache with the current total.
		$counts = wp_count_posts( 'mcp_ai_profession' );
		$before = isset( $counts->publish ) ? (int) $counts->publish : 0;
		$this->assertGreaterThanOrEqual( 2, $before );

		// Force-delete one post directly — the plugin's delete_post hook
		// routes through clear_cache(), which must invalidate the counts
		// cache so wp_count_posts() never serves a stale total.
		wp_delete_post( $post_id_2, true );

		$fresh = wp_count_posts( 'mcp_ai_profession' );
		$this->assertEquals( $before - 1, isset( $fresh->publish ) ? (int) $fresh->publish : 0, 'Deleting a profession must invalidate the post-type counts cache' );

		// Clean up.
		wp_delete_post( $post_id_1, true );
	}

	/**
	 * Test profession count after seeding.
	 */
	public function test_profession_count_after_seeding() {
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_all();
		$repository  = new WP_MCP_AI_Profession_Repository();

		$saved_count = 0;
		foreach ( $professions as $profession_data ) {
			$result = $repository->save( $profession_data );
			if ( ! is_wp_error( $result ) ) {
				++$saved_count;
			}
		}

		// Should have saved some professions.
		$this->assertGreaterThan( 0, $saved_count, 'Should have saved at least one profession' );

		// Verify count in database.
		$profession_posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);

		$this->assertEquals( $saved_count, count( $profession_posts ), 'Database count should match saved count' );
	}

	/**
	 * Test repository cache is cleared after save.
	 */
	public function test_cache_is_cleared_after_save() {
		$repository = new WP_MCP_AI_Profession_Repository();

		// Save a profession.
		$profession_data = array(
			'title'            => 'Cache Test',
			'slug'             => 'test_cache',
			'description'      => 'Test',
			'category'         => 'technical',
			'role_description' => 'Test',
			'expertise'        => array(),
			'warnings'         => array(),
			'knowledge_base'   => 'Test',
			'default_tools'    => array(),
		);

		$post_id = $repository->save( $profession_data );

		// The save method should have cleared cache.
		// We can't directly test cache clearing, but we can verify the profession is findable.
		$found = $repository->find_one( 'test_cache' );
		$this->assertInstanceOf( WP_Post::class, $found );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test default_tools are properly saved and retrieved.
	 */
	public function test_default_tools_persistence() {
		$repository = new WP_MCP_AI_Profession_Repository();

		// Create profession with default_tools.
		$profession_data = array(
			'title'            => 'Tools Test Profession',
			'slug'             => 'test_tools_profession',
			'description'      => 'Test',
			'category'         => 'technical',
			'role_description' => 'Test',
			'expertise'        => array(),
			'warnings'         => array(),
			'knowledge_base'   => 'Test',
			'default_tools'    => array( 'web_search', 'search_content', 'save_post' ),
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Retrieve and verify default_tools.
		$saved_tools = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );
		$this->assertIsArray( $saved_tools, 'default_tools should be an array' );
		$this->assertCount( 3, $saved_tools, 'Should have 3 tools' );
		$this->assertContains( 'web_search', $saved_tools );
		$this->assertContains( 'search_content', $saved_tools );
		$this->assertContains( 'save_post', $saved_tools );

		// Verify array has sequential keys.
		$this->assertEquals( array_values( $saved_tools ), $saved_tools, 'Array should have sequential keys' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test default_tools update preserves data correctly.
	 */
	public function test_default_tools_update() {
		$repository = new WP_MCP_AI_Profession_Repository();

		// Create profession with initial tools.
		$profession_data = array(
			'title'            => 'Update Tools Test',
			'slug'             => 'test_update_tools',
			'description'      => 'Test',
			'category'         => 'technical',
			'role_description' => 'Test',
			'expertise'        => array(),
			'warnings'         => array(),
			'knowledge_base'   => 'Test',
			'default_tools'    => array( 'web_search', 'search_content' ),
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Update with new tools.
		$updated_data = array(
			'id'               => $post_id,
			'title'            => 'Update Tools Test',
			'slug'             => 'test_update_tools',
			'description'      => 'Test',
			'category'         => 'technical',
			'role_description' => 'Test',
			'expertise'        => array(),
			'warnings'         => array(),
			'knowledge_base'   => 'Test',
			'default_tools'    => array( 'save_post', 'create_chart', 'send_group_email' ),
		);

		$result = $repository->save( $updated_data );
		$this->assertEquals( $post_id, $result, 'Update should return same post ID' );

		// Verify tools were updated.
		$saved_tools = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );
		$this->assertIsArray( $saved_tools );
		$this->assertCount( 3, $saved_tools, 'Should have 3 tools after update' );
		$this->assertContains( 'save_post', $saved_tools );
		$this->assertContains( 'create_chart', $saved_tools );
		$this->assertContains( 'send_group_email', $saved_tools );
		$this->assertNotContains( 'web_search', $saved_tools, 'Old tool should not be present' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test default_tools with empty values are filtered out.
	 */
	public function test_default_tools_filter_empty() {
		$repository = new WP_MCP_AI_Profession_Repository();

		// Create profession with some empty tool slugs.
		$profession_data = array(
			'title'            => 'Filter Empty Tools Test',
			'slug'             => 'test_filter_empty_tools',
			'description'      => 'Test',
			'category'         => 'technical',
			'role_description' => 'Test',
			'expertise'        => array(),
			'warnings'         => array(),
			'knowledge_base'   => 'Test',
			'default_tools'    => array( 'web_search', '', 'search_content', null, 'save_post', '' ),
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Verify empty values were filtered out.
		$saved_tools = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );
		$this->assertIsArray( $saved_tools );
		$this->assertCount( 3, $saved_tools, 'Should only have 3 non-empty tools' );
		$this->assertContains( 'web_search', $saved_tools );
		$this->assertContains( 'search_content', $saved_tools );
		$this->assertContains( 'save_post', $saved_tools );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test profession data sanitization.
	 */
	public function test_profession_data_sanitization() {
		$repository = new WP_MCP_AI_Profession_Repository();

		// Create profession with potentially unsafe data.
		$profession_data = array(
			'title'            => 'Test <script>alert("xss")</script>',
			'slug'             => 'Test Slug With Spaces',
			'description'      => '<p>Safe HTML</p><script>alert("xss")</script>',
			'category'         => 'TECHNICAL',
			'role_description' => '<b>Bold text</b><script>alert("xss")</script>',
			'expertise'        => array( 'Skill <script>xss</script>' ),
			'warnings'         => array( 'Warning <b>text</b>' ),
			'knowledge_base'   => '<p>KB</p><script>alert("xss")</script>',
			'default_tools'    => array( 'invalid-tool-name!@#' ),
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Verify sanitization.
		$post = get_post( $post_id );

		// Title should have scripts stripped.
		$this->assertStringNotContainsString( '<script>', $post->post_title );

		// Slug should be sanitized to URL-safe format.
		$this->assertEquals( 'test-slug-with-spaces', $post->post_name );

		// Category should be lowercase.
		$category = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
		$this->assertEquals( 'technical', $category );

		// Clean up.
		wp_delete_post( $post_id, true );
	}
}
