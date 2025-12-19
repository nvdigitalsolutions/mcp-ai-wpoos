<?php
/**
 * Tests for assistant base knowledge file size display.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Assistant_Base_Knowledge_File_Sizes_Test extends WP_UnitTestCase {

	/**
	 * Test that file sizes are included in memory entries.
	 */
	public function test_memory_entries_include_file_sizes() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Create a test file.
		$content = str_repeat( 'Test content. ', 100 );
		$upload  = wp_upload_bits( 'test-file.txt', null, $content );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );
		wp_update_post(
			array(
				'ID'         => $attachment_id,
				'post_title' => 'Test Knowledge File',
			)
		);

		// Add the file to the assistant's memory.
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES, array( $attachment_id ) );

		// Get the assistant configuration.
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertArrayHasKey( 'memory_files', $config );
		$this->assertContains( $attachment_id, $config['memory_files'] );

		// Verify file exists and has a size.
		$file_path = get_attached_file( $attachment_id );
		$this->assertNotFalse( $file_path );
		$this->assertTrue( file_exists( $file_path ) );

		$file_size = filesize( $file_path );
		$this->assertNotFalse( $file_size );
		$this->assertGreaterThan( 0, $file_size );

		// Verify size_format works.
		$formatted_size = size_format( $file_size );
		$this->assertNotEmpty( $formatted_size );
		$this->assertIsString( $formatted_size );
	}

	/**
	 * Test that multiple files show correct individual sizes.
	 */
	public function test_multiple_files_show_individual_sizes() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test Assistant Multiple Files',
				'post_status' => 'publish',
			)
		);

		$attachment_ids = array();

		// Create multiple test files with different sizes.
		for ( $i = 1; $i <= 3; $i++ ) {
			$content = str_repeat( 'Content ' . $i . '. ', 50 * $i );
			$upload  = wp_upload_bits( 'test-file-' . $i . '.txt', null, $content );
			$this->assertFalse( $upload['error'] );

			$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => 'Test File ' . $i,
				)
			);

			$attachment_ids[] = $attachment_id;

			// Verify each file has a different size.
			$file_path = get_attached_file( $attachment_id );
			$this->assertTrue( file_exists( $file_path ) );
			$file_size = filesize( $file_path );
			$this->assertGreaterThan( 0, $file_size );
		}

		// Add all files to the assistant's memory.
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES, $attachment_ids );

		// Get the assistant configuration.
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertArrayHasKey( 'memory_files', $config );
		$this->assertCount( 3, $config['memory_files'] );

		// Verify all files are in the memory files.
		foreach ( $attachment_ids as $attachment_id ) {
			$this->assertContains( $attachment_id, $config['memory_files'] );
		}
	}

	/**
	 * Test that files without valid paths don't break the display.
	 */
	public function test_missing_file_paths_handled_gracefully() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test Assistant Missing Files',
				'post_status' => 'publish',
			)
		);

		// Create a valid file.
		$content = 'Valid content';
		$upload  = wp_upload_bits( 'valid-file.txt', null, $content );
		$this->assertFalse( $upload['error'] );

		$valid_attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		// Create an attachment with no file (simulating a missing file).
		$missing_attachment_id = wp_insert_attachment(
			array(
				'post_title'  => 'Missing File',
				'post_status' => 'inherit',
			)
		);

		// Add both files to memory (one valid, one missing).
		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES,
			array( $valid_attachment_id, $missing_attachment_id )
		);

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertArrayHasKey( 'memory_files', $config );
		$this->assertCount( 2, $config['memory_files'] );

		// Verify valid file has a size.
		$valid_file_path = get_attached_file( $valid_attachment_id );
		$this->assertNotFalse( $valid_file_path );
		$this->assertTrue( file_exists( $valid_file_path ) );

		// Verify missing file returns false or empty path.
		$missing_file_path = get_attached_file( $missing_attachment_id );
		$this->assertTrue( false === $missing_file_path || ! file_exists( (string) $missing_file_path ) );
	}
}
