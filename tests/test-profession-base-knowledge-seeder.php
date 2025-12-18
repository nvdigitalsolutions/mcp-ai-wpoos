<?php
/**
 * Test Profession Base Knowledge Seeder.
 *
 * Tests for the profession base knowledge seeding functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Profession_Base_Knowledge_Seeder.
 */
class Test_Profession_Base_Knowledge_Seeder extends WP_UnitTestCase {
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

		// Clear any existing attachments from previous tests.
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_wp_mcp_ai_seeded_profession_doc_type',
						'value'   => 'base_knowledge',
						'compare' => '=',
					),
				),
			)
		);

		foreach ( $attachments as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		// Clear seeded options.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );
		delete_option( WP_MCP_AI_Profession_Base_Knowledge_Seeder::SEEDED_OPTION );
	}

	/**
	 * Test teardown.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that base knowledge seeder class exists.
	 */
	public function test_base_knowledge_seeder_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Profession_Base_Knowledge_Seeder' ) );
	}

	/**
	 * Test creating a profession and seeding base knowledge.
	 */
	public function test_seed_base_knowledge_creates_attachment() {
		// Create a test profession.
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
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Mark professions as seeded so base knowledge seeder can run.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run base knowledge seeder.
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		// Check that META_MEMORY_FILES is set.
		$memory_files = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertIsArray( $memory_files, 'META_MEMORY_FILES should be an array' );
		$this->assertNotEmpty( $memory_files, 'META_MEMORY_FILES should not be empty' );
		$this->assertCount( 1, $memory_files, 'Should have exactly one attachment' );

		// Check that the attachment exists.
		$attachment_id = $memory_files[0];
		$attachment    = get_post( $attachment_id );
		$this->assertNotNull( $attachment, 'Attachment should exist' );
		$this->assertEquals( 'attachment', $attachment->post_type, 'Should be an attachment' );
		$this->assertEquals( $post_id, $attachment->post_parent, 'Attachment should be attached to profession' );

		// Check idempotency markers.
		$slug_marker = get_post_meta( $attachment_id, '_wp_mcp_ai_seeded_profession_slug', true );
		$this->assertEquals( 'test_profession', $slug_marker, 'Slug marker should match profession slug' );

		$doc_type_marker = get_post_meta( $attachment_id, '_wp_mcp_ai_seeded_profession_doc_type', true );
		$this->assertEquals( 'base_knowledge', $doc_type_marker, 'Doc type marker should be base_knowledge' );

		// Clean up.
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test that META_SUPPORTED_MIME_TYPES is set correctly.
	 */
	public function test_supported_mime_types_are_set() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Test Technical Profession',
			'slug'             => 'test_technical',
			'description'      => 'A technical test profession.',
			'category'         => 'technical',
			'role_description' => 'Test role',
			'expertise'        => array( 'Testing' ),
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run base knowledge seeder.
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		// Check META_SUPPORTED_MIME_TYPES.
		$mime_types = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );
		$this->assertIsArray( $mime_types, 'Supported MIME types should be an array' );
		$this->assertNotEmpty( $mime_types, 'Supported MIME types should not be empty' );
		$this->assertContains( 'text/plain', $mime_types, 'Should include text/plain' );

		// Technical category should have PDF and CSV.
		$this->assertContains( 'application/pdf', $mime_types, 'Technical should include PDF' );
		$this->assertContains( 'text/csv', $mime_types, 'Technical should include CSV' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test idempotency - running seed_base_knowledge twice should not create duplicates.
	 */
	public function test_idempotency_no_duplicate_attachments() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Test Idempotency',
			'slug'             => 'test_idempotency',
			'description'      => 'Test idempotency.',
			'category'         => 'advisory',
			'role_description' => 'Test role',
			'expertise'        => array( 'Testing' ),
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run base knowledge seeder first time.
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		$memory_files_1 = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertIsArray( $memory_files_1 );
		$this->assertCount( 1, $memory_files_1, 'Should have one attachment after first seed' );
		$attachment_id_1 = $memory_files_1[0];

		// Run base knowledge seeder second time (should be idempotent).
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		$memory_files_2 = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertIsArray( $memory_files_2 );
		$this->assertCount( 1, $memory_files_2, 'Should still have only one attachment after second seed' );
		$attachment_id_2 = $memory_files_2[0];

		$this->assertEquals( $attachment_id_1, $attachment_id_2, 'Attachment ID should be the same' );

		// Verify no duplicate attachments were created.
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_wp_mcp_ai_seeded_profession_slug',
						'value'   => 'test_idempotency',
						'compare' => '=',
					),
					array(
						'key'     => '_wp_mcp_ai_seeded_profession_doc_type',
						'value'   => 'base_knowledge',
						'compare' => '=',
					),
				),
			)
		);

		$this->assertCount( 1, $attachments, 'Should have exactly one attachment in database' );

		// Clean up.
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $attachment_id_1, true );
	}

	/**
	 * Test force mode can refresh MIME types.
	 */
	public function test_force_mode_refreshes_mime_types() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Test Force Refresh',
			'slug'             => 'test_force_refresh',
			'description'      => 'Test force refresh.',
			'category'         => 'creative',
			'role_description' => 'Test role',
			'expertise'        => array( 'Testing' ),
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Set some initial MIME types manually.
		$initial_mimes = array( 'text/plain', 'application/old' );
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, $initial_mimes );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run base knowledge seeder with force=true.
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( true );

		// Check that MIME types were refreshed.
		$mime_types = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );
		$this->assertIsArray( $mime_types );

		// Creative category should have image types.
		$this->assertContains( 'image/jpeg', $mime_types, 'Creative should include JPEG' );
		$this->assertContains( 'image/png', $mime_types, 'Creative should include PNG' );
		$this->assertContains( 'image/webp', $mime_types, 'Creative should include WebP' );

		// Should not have the old manual MIME type.
		$this->assertNotContains( 'application/old', $mime_types, 'Should not contain old MIME type' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test that different categories get appropriate MIME types.
	 */
	public function test_category_specific_mime_types() {
		$repository = new WP_MCP_AI_Profession_Repository();

		// Test financial category.
		$financial_data = array(
			'title'       => 'Test Financial',
			'slug'        => 'test_financial',
			'description' => 'Test',
			'category'    => 'financial',
		);
		$financial_id   = $repository->save( $financial_data );

		// Test healthcare category.
		$healthcare_data = array(
			'title'       => 'Test Healthcare',
			'slug'        => 'test_healthcare',
			'description' => 'Test',
			'category'    => 'healthcare',
		);
		$healthcare_id   = $repository->save( $healthcare_data );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Seed base knowledge.
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		// Check financial MIME types.
		$financial_mimes = get_post_meta( $financial_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );
		$this->assertIsArray( $financial_mimes );
		$this->assertContains( 'text/plain', $financial_mimes );
		$this->assertContains( 'application/pdf', $financial_mimes );
		$this->assertContains( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $financial_mimes );

		// Check healthcare MIME types.
		$healthcare_mimes = get_post_meta( $healthcare_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );
		$this->assertIsArray( $healthcare_mimes );
		$this->assertContains( 'text/plain', $healthcare_mimes );
		$this->assertContains( 'application/pdf', $healthcare_mimes );
		$this->assertContains( 'image/jpeg', $healthcare_mimes );
		$this->assertContains( 'image/png', $healthcare_mimes );

		// Clean up.
		wp_delete_post( $financial_id, true );
		wp_delete_post( $healthcare_id, true );
	}

	/**
	 * Test that seeder doesn't run if professions aren't seeded yet.
	 */
	public function test_seeder_waits_for_professions() {
		// Make sure professions seeded option is false.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );

		// Try to run base knowledge seeder.
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		// Option should NOT be set because professions aren't seeded yet.
		$this->assertFalse( get_option( WP_MCP_AI_Profession_Base_Knowledge_Seeder::SEEDED_OPTION, false ) );
	}

	/**
	 * Test that attachment file actually exists on disk.
	 */
	public function test_attachment_file_exists() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test File Exists',
			'slug'        => 'test_file_exists',
			'description' => 'Test',
			'category'    => 'technical',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run base knowledge seeder.
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		// Get attachment ID.
		$memory_files = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertIsArray( $memory_files );
		$this->assertNotEmpty( $memory_files );
		$attachment_id = $memory_files[0];

		// Get attached file path.
		$file_path = get_attached_file( $attachment_id );
		$this->assertNotEmpty( $file_path, 'File path should not be empty' );
		$this->assertFileExists( $file_path, 'Attachment file should exist on disk' );

		// Verify it's in the correct subdirectory.
		$this->assertStringContainsString( 'wp-mcp-ai/profession-knowledge', $file_path, 'File should be in correct subdirectory' );

		// Verify filename format.
		$this->assertStringContainsString( 'profession-test_file_exists-base-knowledge.txt', $file_path, 'Filename should match expected format' );

		// Clean up.
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $attachment_id, true );
	}
}
