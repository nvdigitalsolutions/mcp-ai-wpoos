<?php
/**
 * Test Profession Playbook Seeder.
 *
 * Tests for the profession playbook seeding functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Profession_Playbook_Seeder.
 */
class Test_Profession_Playbook_Seeder extends WP_UnitTestCase {
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

		// Clear any existing playbook attachments.
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_wp_mcp_ai_playbook_profession_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $attachments as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		// Clear seeded options.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );
		delete_option( WP_MCP_AI_Profession_Playbook_Seeder::SEEDED_OPTION );
		delete_option( WP_MCP_AI_Profession_Playbook_Seeder::OFFSET_OPTION );
	}

	/**
	 * Test teardown.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that playbook seeder class exists.
	 */
	public function test_playbook_seeder_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) );
	}

	/**
	 * Test creating playbook attachment for a profession.
	 */
	public function test_sync_profession_playbook_creates_attachment() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test Profession',
			'slug'        => 'test_profession',
			'description' => 'A test profession for unit testing.',
			'category'    => 'technical',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Mark professions as seeded so playbook seeder can run.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run sync_all to create playbooks.
		WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

		// Check that META_MEMORY_FILES is set.
		$memory_files = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertIsArray( $memory_files, 'META_MEMORY_FILES should be an array' );
		$this->assertNotEmpty( $memory_files, 'META_MEMORY_FILES should not be empty' );

		// Get the attachment ID.
		$attachment_id = end( $memory_files );
		$attachment    = get_post( $attachment_id );
		$this->assertNotNull( $attachment, 'Attachment should exist' );
		$this->assertEquals( 'attachment', $attachment->post_type, 'Should be an attachment' );
		$this->assertEquals( 'text/plain', $attachment->post_mime_type, 'Should be text/plain' );

		// Check playbook metadata.
		$profession_id = get_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', true );
		$this->assertEquals( $post_id, absint( $profession_id ), 'Profession ID should match' );

		$hash = get_post_meta( $attachment_id, '_wp_mcp_ai_playbook_hash', true );
		$this->assertNotEmpty( $hash, 'Hash should be set' );

		// Check file exists on disk.
		$file_path = get_attached_file( $attachment_id );
		$this->assertFileExists( $file_path, 'Playbook file should exist on disk' );
		$this->assertStringContainsString( 'wp-mcp-ai/profession-playbooks', $file_path, 'File should be in correct subdirectory' );

		// Clean up.
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test idempotency - running sync twice should not create duplicates.
	 */
	public function test_idempotency_no_duplicate_attachments() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test Idempotency',
			'slug'        => 'test_idempotency',
			'description' => 'Test idempotency.',
			'category'    => 'advisory',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run sync first time.
		WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

		$memory_files_1 = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertIsArray( $memory_files_1 );
		$attachment_id_1 = end( $memory_files_1 );

		// Run sync second time (should be idempotent).
		WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

		$memory_files_2 = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertIsArray( $memory_files_2 );
		$this->assertCount( count( $memory_files_1 ), $memory_files_2, 'Should not add duplicate attachments' );

		$attachment_id_2 = end( $memory_files_2 );
		$this->assertEquals( $attachment_id_1, $attachment_id_2, 'Attachment ID should be the same' );

		// Verify no duplicate attachments were created.
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_wp_mcp_ai_playbook_profession_id',
						'value'   => $post_id,
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
	 * Test content update behavior - changing content creates new attachment.
	 */
	public function test_content_update_creates_new_attachment() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test Update',
			'slug'        => 'test_update',
			'description' => 'Test update.',
			'category'    => 'creative',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run sync first time.
		WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

		$memory_files       = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$initial_attachment_id = end( $memory_files );
		$initial_hash       = get_post_meta( $initial_attachment_id, '_wp_mcp_ai_playbook_hash', true );

		// Get initial file content.
		$initial_file_path    = get_attached_file( $initial_attachment_id );
		$initial_content      = file_get_contents( $initial_file_path );

		// Modify profession to trigger content change (change title).
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Test Update Modified',
			)
		);

		// Run sync with force=true to trigger update.
		WP_MCP_AI_Profession_Playbook_Seeder::sync_all( true );

		// Get new attachment ID from memory files.
		$new_memory_files = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$new_attachment_id = end( $new_memory_files );

		// Check that a new attachment was created.
		$this->assertNotEquals( $initial_attachment_id, $new_attachment_id, 'A new attachment should be created when content changes' );

		// Check that new attachment has different hash.
		$new_hash = get_post_meta( $new_attachment_id, '_wp_mcp_ai_playbook_hash', true );
		$this->assertNotEquals( $initial_hash, $new_hash, 'Hash should change when content changes' );

		// Check that new file content has updated title.
		$new_file_path = get_attached_file( $new_attachment_id );
		$new_content   = file_get_contents( $new_file_path );
		$this->assertStringContainsString( 'Test Update Modified', $new_content, 'New content should have updated title' );

		// Check that old attachment is orphaned (no profession_id meta).
		$old_profession_id = get_post_meta( $initial_attachment_id, '_wp_mcp_ai_playbook_profession_id', true );
		$this->assertEmpty( $old_profession_id, 'Old attachment should be orphaned (no profession_id meta)' );

		// Check that old attachment still exists in media library.
		$old_attachment = get_post( $initial_attachment_id );
		$this->assertNotNull( $old_attachment, 'Old attachment should still exist in media library' );
		$this->assertEquals( 'attachment', $old_attachment->post_type, 'Old attachment should still be an attachment' );

		// Clean up.
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $initial_attachment_id, true );
		wp_delete_attachment( $new_attachment_id, true );
	}

	/**
	 * Test that text/plain is added to supported MIME types.
	 */
	public function test_supported_mime_types_includes_text_plain() {
		// Create a test profession without text/plain in MIME types.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test MIME Types',
			'slug'        => 'test_mime_types',
			'description' => 'Test MIME types.',
			'category'    => 'technical',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Set some initial MIME types without text/plain.
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, array( 'application/pdf' ) );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run sync.
		WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

		// Check META_SUPPORTED_MIME_TYPES now includes text/plain.
		$mime_types = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );
		$this->assertIsArray( $mime_types );
		$this->assertContains( 'text/plain', $mime_types, 'Should include text/plain' );
		$this->assertContains( 'application/pdf', $mime_types, 'Should keep existing MIME types' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test batch processing with offset tracking.
	 */
	public function test_batch_processing_tracks_offset() {
		// Create multiple test professions.
		$repository = new WP_MCP_AI_Profession_Repository();
		$post_ids   = array();

		for ( $i = 1; $i <= 5; $i++ ) {
			$profession_data = array(
				'title'       => "Test Profession {$i}",
				'slug'        => "test_profession_{$i}",
				'description' => "Test profession {$i}.",
				'category'    => 'advisory',
			);

			$post_ids[] = $repository->save( $profession_data );
		}

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Simulate incremental seeding by calling seed_playbooks_incremental.
		// This should process professions in batches.

		// First batch.
		WP_MCP_AI_Profession_Playbook_Seeder::seed_playbooks_incremental();

		// Check if offset option is set or seeded option is set (if all processed).
		$seeded = get_option( WP_MCP_AI_Profession_Playbook_Seeder::SEEDED_OPTION, false );
		$offset = get_option( WP_MCP_AI_Profession_Playbook_Seeder::OFFSET_OPTION, 0 );

		// With 5 professions and batch size 20, should complete in one run.
		$this->assertTrue( $seeded, 'Should mark as seeded after processing all professions' );
		$this->assertFalse( get_option( WP_MCP_AI_Profession_Playbook_Seeder::OFFSET_OPTION ), 'Offset option should be deleted when complete' );

		// Verify all professions have playbooks.
		foreach ( $post_ids as $post_id ) {
			$memory_files = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
			$this->assertIsArray( $memory_files, "Profession {$post_id} should have memory files" );
			$this->assertNotEmpty( $memory_files, "Profession {$post_id} should have at least one memory file" );
		}

		// Clean up.
		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

	/**
	 * Test seeder waits for professions to be seeded first.
	 */
	public function test_seeder_waits_for_professions() {
		// Make sure professions seeded option is false.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );

		// Try to run playbook seeder.
		WP_MCP_AI_Profession_Playbook_Seeder::seed_playbooks_incremental();

		// Option should NOT be set because professions aren't seeded yet.
		$this->assertFalse( get_option( WP_MCP_AI_Profession_Playbook_Seeder::SEEDED_OPTION, false ) );
	}

	/**
	 * Test attachment filename format.
	 */
	public function test_attachment_filename_format() {
		// Create a test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test Filename',
			'slug'        => 'test_filename',
			'description' => 'Test',
			'category'    => 'technical',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Run sync.
		WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

		// Get attachment ID.
		$memory_files  = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$attachment_id = end( $memory_files );

		// Get attached file path.
		$file_path = get_attached_file( $attachment_id );
		$this->assertNotEmpty( $file_path, 'File path should not be empty' );

		// Verify filename format includes profession ID and slug.
		$filename = basename( $file_path );
		$this->assertStringContainsString( "profession-{$post_id}-test_filename-playbook.txt", $filename, 'Filename should match expected format' );

		// Clean up.
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test removal of duplicate playbook attachments.
	 */
	public function test_remove_duplicate_playbooks() {
		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
		}

		// Create test profession.
		$repository = new WP_MCP_AI_Profession_Repository();

		$profession_data = array(
			'post_title'  => 'Test Duplicate Removal',
			'post_name'   => 'test_duplicate_removal',
			'post_status' => 'publish',
			'category'    => 'technical',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Create multiple duplicate playbook attachments manually.
		$attachment_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$attachment_id = $this->factory->post->create(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'post_title'  => 'Test Playbook ' . $i,
				)
			);
			update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', $post_id );
			$attachment_ids[] = $attachment_id;

			// Small delay to ensure different IDs.
			usleep( 1000 );
		}

		// Verify we have 3 attachments.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Profession_Playbook_Seeder' );
		$method     = $reflection->getMethod( 'find_all_playbook_attachments' );
		$method->setAccessible( true );

		$all_attachments = $method->invoke( null, $post_id );
		$this->assertCount( 3, $all_attachments, 'Should have 3 duplicate attachments' );

		// Remove duplicates.
		$remove_method = $reflection->getMethod( 'remove_duplicate_playbooks' );
		$remove_method->setAccessible( true );
		$removed_count = $remove_method->invoke( null, $post_id );

		// Verify 2 duplicates were removed from profession.
		$this->assertEquals( 2, $removed_count, 'Should have removed 2 duplicate attachments from profession' );

		// Verify only 1 attachment remains associated with profession.
		$remaining_attachments = $method->invoke( null, $post_id );
		$this->assertCount( 1, $remaining_attachments, 'Should have only 1 attachment associated with profession' );

		// Verify the remaining attachment is the most recent (highest ID).
		$this->assertEquals( max( $attachment_ids ), $remaining_attachments[0]->ID, 'Should keep the most recent attachment' );

		// Verify all 3 attachments still exist in media library.
		foreach ( $attachment_ids as $attachment_id ) {
			$attachment = get_post( $attachment_id );
			$this->assertInstanceOf( 'WP_Post', $attachment, 'Attachment should still exist in media library' );
			$this->assertEquals( 'attachment', $attachment->post_type, 'Should still be an attachment' );
		}

		// Verify only the most recent attachment has the profession association meta.
		$most_recent_id = max( $attachment_ids );
		foreach ( $attachment_ids as $attachment_id ) {
			$profession_meta = get_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', true );
			if ( $attachment_id === $most_recent_id ) {
				$this->assertEquals( $post_id, $profession_meta, 'Most recent attachment should have profession meta' );
			} else {
				$this->assertEmpty( $profession_meta, 'Older attachments should not have profession meta' );
			}
		}

		// Clean up.
		wp_delete_post( $post_id, true );
		foreach ( $attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Test cleanup_all_duplicates method.
	 */
	public function test_cleanup_all_duplicates() {
		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
		}

		// Create 2 test professions with duplicates.
		$repository = new WP_MCP_AI_Profession_Repository();
		$profession_ids = array();

		for ( $p = 0; $p < 2; $p++ ) {
			$profession_data = array(
				'post_title'  => 'Test Profession ' . $p,
				'post_name'   => 'test_profession_' . $p,
				'post_status' => 'publish',
				'category'    => 'technical',
			);

			$post_id = $repository->save( $profession_data );
			$profession_ids[] = $post_id;

			// Create 2 duplicate attachments for each profession.
			for ( $i = 0; $i < 2; $i++ ) {
				$attachment_id = $this->factory->post->create(
					array(
						'post_type'   => 'attachment',
						'post_status' => 'inherit',
						'post_title'  => 'Test Playbook ' . $p . '_' . $i,
					)
				);
				update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', $post_id );
				usleep( 1000 );
			}
		}

		// Run cleanup.
		$result = WP_MCP_AI_Profession_Playbook_Seeder::cleanup_all_duplicates();

		// Verify results.
		$this->assertEquals( 2, $result['professions_processed'], 'Should have processed 2 professions' );
		$this->assertEquals( 2, $result['duplicates_removed'], 'Should have removed 2 duplicates (1 per profession)' );

		// Verify each profession has only 1 attachment.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Profession_Playbook_Seeder' );
		$method     = $reflection->getMethod( 'find_all_playbook_attachments' );
		$method->setAccessible( true );

		foreach ( $profession_ids as $profession_id ) {
			$attachments = $method->invoke( null, $profession_id );
			$this->assertCount( 1, $attachments, 'Each profession should have only 1 attachment' );
		}

		// Clean up.
		foreach ( $profession_ids as $profession_id ) {
			wp_delete_post( $profession_id, true );
			$attachments = $method->invoke( null, $profession_id );
			foreach ( $attachments as $attachment ) {
				wp_delete_attachment( $attachment->ID, true );
			}
		}
	}

	/**
	 * Test deduplication on profession save_post.
	 */
	public function test_save_post_deduplication() {
		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		}

		// Create test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'post_title'  => 'Test Profession Save',
			'post_name'   => 'test_profession_save',
			'post_status' => 'publish',
			'category'    => 'technical',
		);

		$post_id = $repository->save( $profession_data );

		// Create 3 duplicate attachments.
		$attachment_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$attachment_id = $this->factory->post->create(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'post_title'  => 'Test Playbook Save ' . $i,
				)
			);
			update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', $post_id );

			// Add to memory files.
			$memory_files   = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
			$memory_files   = is_array( $memory_files ) ? $memory_files : array();
			$memory_files[] = $attachment_id;
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, $memory_files );

			$attachment_ids[] = $attachment_id;
			usleep( 1000 ); // Ensure different timestamps.
		}

		// Verify we have 3 attachments before save.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Profession_Playbook_Seeder' );
		$method     = $reflection->getMethod( 'find_all_playbook_attachments' );
		$method->setAccessible( true );
		$attachments = $method->invoke( null, $post_id );
		$this->assertCount( 3, $attachments, 'Should have 3 attachments before save' );

		// Trigger save_post hook (simulating admin save).
		$_POST['wp_mcp_ai_profession_nonce'] = wp_create_nonce( 'wp_mcp_ai_save_profession' );
		$cpt_instance                        = new WP_MCP_AI_Profession_CPT();
		$profession                          = get_post( $post_id );
		$cpt_instance->save_post( $post_id, $profession );
		unset( $_POST['wp_mcp_ai_profession_nonce'] );

		// Verify only 1 attachment remains associated with profession.
		$attachments = $method->invoke( null, $post_id );
		$this->assertCount( 1, $attachments, 'Should have only 1 attachment after save' );

		// Verify memory files only contains 1 attachment.
		$memory_files = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertIsArray( $memory_files );
		$this->assertCount( 1, $memory_files, 'Memory files should contain only 1 attachment' );

		// Clean up.
		wp_delete_post( $post_id, true );
		foreach ( $attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Test that orphaned playbooks can be identified and deleted.
	 */
	public function test_orphaned_playbooks_can_be_deleted() {
		global $wpdb;

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
		}

		// Create test profession.
		$repository      = new WP_MCP_AI_Profession_Repository();
		$profession_data = array(
			'title'       => 'Test Orphaned',
			'slug'        => 'test_orphaned',
			'description' => 'Test orphaned playbooks.',
			'category'    => 'technical',
		);

		$post_id = $repository->save( $profession_data );
		$this->assertIsInt( $post_id );

		// Mark professions as seeded.
		update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

		// Create first playbook.
		WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

		$memory_files        = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$first_attachment_id = end( $memory_files );

		// Modify profession to trigger content change.
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Test Orphaned Modified',
			)
		);

		// Sync again to create a new attachment and orphan the old one.
		WP_MCP_AI_Profession_Playbook_Seeder::sync_all( true );

		$new_memory_files    = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$second_attachment_id = end( $new_memory_files );

		// Verify a new attachment was created.
		$this->assertNotEquals( $first_attachment_id, $second_attachment_id, 'Should create new attachment' );

		// Verify first attachment is orphaned (no profession_id meta).
		$first_profession_id = get_post_meta( $first_attachment_id, '_wp_mcp_ai_playbook_profession_id', true );
		$this->assertEmpty( $first_profession_id, 'First attachment should be orphaned' );

		// Verify second attachment has profession_id meta.
		$second_profession_id = get_post_meta( $second_attachment_id, '_wp_mcp_ai_playbook_profession_id', true );
		$this->assertEquals( $post_id, absint( $second_profession_id ), 'Second attachment should have profession_id' );

		// Query for orphaned playbook attachments (same logic as AJAX handler).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphaned_attachments = $wpdb->get_col(
			"SELECT p.ID 
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type = 'text/plain'
			AND p.post_title LIKE '%playbook%'
			AND NOT EXISTS (
				SELECT 1 
				FROM {$wpdb->postmeta} pm 
				WHERE pm.post_id = p.ID 
				AND pm.meta_key = '_wp_mcp_ai_playbook_profession_id'
			)"
		);

		// Verify first attachment is in orphaned list.
		$this->assertContains( $first_attachment_id, array_map( 'intval', $orphaned_attachments ), 'First attachment should be in orphaned list' );

		// Verify second attachment is NOT in orphaned list.
		$this->assertNotContains( $second_attachment_id, array_map( 'intval', $orphaned_attachments ), 'Second attachment should not be in orphaned list' );

		// Delete orphaned attachments.
		foreach ( $orphaned_attachments as $orphaned_id ) {
			wp_delete_attachment( $orphaned_id, true );
		}

		// Verify first attachment was deleted.
		$this->assertNull( get_post( $first_attachment_id ), 'First attachment should be deleted' );

		// Verify second attachment still exists.
		$this->assertNotNull( get_post( $second_attachment_id ), 'Second attachment should still exist' );

		// Clean up.
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $second_attachment_id, true );
	}

	/**
	 * Test delete_orphaned_system_playbooks method.
	 *
	 * Verifies that only orphaned system-created playbooks are deleted.
	 */
	public function test_delete_orphaned_system_playbooks() {
		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		}

		// Create a profession.
		$profession_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
				'post_title'  => 'Test Profession',
			)
		);

		// Create an active system playbook (has profession meta and in memory_files).
		$active_attachment_id = $this->factory->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'Active Playbook',
			)
		);
		update_post_meta( $active_attachment_id, '_wp_mcp_ai_playbook_profession_id', $profession_id );
		update_post_meta( $active_attachment_id, '_wp_mcp_ai_playbook_hash', 'test_hash_active' );
		update_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, array( $active_attachment_id ) );

		// Create an orphaned system playbook (has hash but no profession meta).
		$orphaned_attachment_id = $this->factory->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'Orphaned Playbook',
			)
		);
		update_post_meta( $orphaned_attachment_id, '_wp_mcp_ai_playbook_hash', 'test_hash_orphaned' );
		// Intentionally NOT setting profession meta to simulate orphaned state.

		// Create a user-uploaded attachment (no hash, no profession meta).
		$user_attachment_id = $this->factory->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'User Upload',
			)
		);

		// Use reflection to call the public method.
		$method = new ReflectionMethod( 'WP_MCP_AI_Profession_Playbook_Seeder', 'delete_orphaned_system_playbooks' );
		$method->setAccessible( true );

		// Call the deletion method.
		$result = $method->invoke( null, 50 );

		// Verify results.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'deleted_count', $result );
		$this->assertArrayHasKey( 'deleted_ids', $result );
		$this->assertArrayHasKey( 'skipped_ids', $result );

		// Orphaned system playbook should be deleted.
		$this->assertEquals( 1, $result['deleted_count'], 'Should delete 1 orphaned playbook' );
		$this->assertContains( $orphaned_attachment_id, $result['deleted_ids'], 'Orphaned attachment should be in deleted list' );

		// Verify orphaned attachment was actually deleted.
		$this->assertNull( get_post( $orphaned_attachment_id ), 'Orphaned attachment should be deleted' );

		// Active playbook should still exist.
		$this->assertNotNull( get_post( $active_attachment_id ), 'Active attachment should still exist' );

		// User upload should still exist.
		$this->assertNotNull( get_post( $user_attachment_id ), 'User attachment should still exist' );

		// Clean up.
		wp_delete_post( $profession_id, true );
		wp_delete_attachment( $active_attachment_id, true );
		wp_delete_attachment( $user_attachment_id, true );
	}

	/**
	 * Test remove_duplicate_playbooks with delete parameter.
	 *
	 * Verifies that duplicates can be deleted instead of just orphaned.
	 */
	public function test_remove_duplicate_playbooks_with_delete() {
		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		}

		// Create a profession.
		$profession_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
				'post_title'  => 'Test Profession',
			)
		);

		// Create multiple playbook attachments.
		$attachment_ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$attachment_id = $this->factory->post->create(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'post_title'  => "Playbook Version $i",
				)
			);
			update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', $profession_id );
			update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_hash', "test_hash_$i" );
			$attachment_ids[] = $attachment_id;
		}

		// Use reflection to call the protected method with delete=true.
		$method = new ReflectionMethod( 'WP_MCP_AI_Profession_Playbook_Seeder', 'remove_duplicate_playbooks' );
		$method->setAccessible( true );

		// Call with delete=true.
		$removed_count = $method->invoke( null, $profession_id, true );

		// Should have removed 2 duplicates (keeping the most recent).
		$this->assertEquals( 2, $removed_count, 'Should remove 2 duplicate playbooks' );

		// First attachment (most recent) should still exist.
		$this->assertNotNull( get_post( $attachment_ids[0] ), 'Most recent attachment should still exist' );

		// Older attachments should be deleted.
		$this->assertNull( get_post( $attachment_ids[1] ), 'Second attachment should be deleted' );
		$this->assertNull( get_post( $attachment_ids[2] ), 'Third attachment should be deleted' );

		// Clean up.
		wp_delete_post( $profession_id, true );
		wp_delete_attachment( $attachment_ids[0], true );
	}
}
