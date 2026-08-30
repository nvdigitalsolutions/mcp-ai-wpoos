<?php
/**
 * Test Profession Base Knowledge Seeder.
 *
 * Tests for the profession base knowledge seeding functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class Test_Profession_Base_Knowledge_Seeder.
 */
class Test_Profession_Base_Knowledge_Seeder extends WP_UnitTestCase {
	/**
	 * DOCX MIME type constant.
	 */
	const MIME_TYPE_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

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

		// Clear the repository cache so find_all() sees fresh data.
		( new WP_MCP_AI_Profession_Repository() )->clear_cache();
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
	 * Test that seeding populates knowledge base content from a document.
	 */
	public function test_seed_base_knowledge_populates_content() {
		// Create a profession with a slug that has a bundled knowledge document.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Test Accountant',
			'slug'             => 'accountant',
			'description'      => 'A test profession for unit testing.',
			'category'         => 'advisory',
			'role_description' => 'Test role description',
			'expertise'        => array( 'Testing', 'Quality Assurance' ),
			'warnings'         => array( 'This is a test warning' ),
			'default_tools'    => array( 'web_search', 'search_content' ),
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Mark professions as seeded so base knowledge seeder can run.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run base knowledge seeder.
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		// Check that META_KNOWLEDGE_BASE was populated from the bundled document.
		$knowledge_base = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true );
		$this->assertIsString( $knowledge_base, 'Knowledge base should be a string' );
		$this->assertNotEmpty( $knowledge_base, 'Knowledge base should not be empty' );

		// The base knowledge seeding flag should now be set.
		$this->assertTrue( get_option( WP_MCP_AI_Profession_Base_Knowledge_Seeder::SEEDED_OPTION, false ) );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test that META_SUPPORTED_MIME_TYPES is set correctly.
	 */
	public function test_supported_mime_types_are_set() {
		// Create a test profession with a slug that has a bundled document.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Test Architect',
			'slug'             => 'architect',
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
	 * Test idempotency - running seed_base_knowledge twice should not change content.
	 */
	public function test_idempotency_second_seed_keeps_content() {
		// Create a test profession with a slug that has a bundled document.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Test Idempotency',
			'slug'             => 'accountant',
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

		$knowledge_1 = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true );
		$this->assertNotEmpty( $knowledge_1, 'First seed should populate knowledge base' );

		// Run base knowledge seeder second time (should be a no-op).
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		$knowledge_2 = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true );
		$this->assertEquals( $knowledge_1, $knowledge_2, 'Second seed should not change content' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test force mode can refresh MIME types.
	 */
	public function test_force_mode_refreshes_mime_types() {
		// Create a test profession with a slug that has a bundled document.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'            => 'Test Force Refresh',
			'slug'             => 'animator',
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
			'slug'        => 'bookkeeper',
			'description' => 'Test',
			'category'    => 'financial',
		);
		$financial_id   = $repository->save( $financial_data );

		// Test healthcare category.
		$healthcare_data = array(
			'title'       => 'Test Healthcare',
			'slug'        => 'biologist',
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
		$this->assertContains( self::MIME_TYPE_DOCX, $financial_mimes );

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
	 * Test that the bundled knowledge document exists on disk and matches the seeded content.
	 */
	public function test_knowledge_document_file_populates_content() {
		// Create a test profession with a slug that has a bundled document.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test File Exists',
			'slug'        => 'accountant',
			'description' => 'Test',
			'category'    => 'advisory',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run base knowledge seeder.
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( false );

		// Seeded knowledge base should be populated from the document.
		$knowledge_base = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true );
		$this->assertNotEmpty( $knowledge_base, 'Knowledge base content should be populated' );

		// The bundled document must exist on disk.
		$doc_path = WP_MCP_AI_PATH . 'includes/knowledge-base/profession-documents/accountant.txt';
		$this->assertFileExists( $doc_path, 'Knowledge document should exist on disk' );

		// The seeded content should match the bundled document; the meta value
		// is sanitized through wp_kses_post on write.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Comparing seeded content against the bundled fixture file in a unit test.
		$this->assertEquals( wp_kses_post( file_get_contents( $doc_path ) ), $knowledge_base, 'Seeded content should match the sanitized document file' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}
}
