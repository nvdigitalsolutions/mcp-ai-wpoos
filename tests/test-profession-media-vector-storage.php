<?php
/**
 * Test Profession Media and Vector Storage
 *
 * Tests the new media (MIME) and vector ID storage functionality for professions.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for profession media and vector storage.
 */
class Test_Profession_Media_Vector_Storage extends WP_UnitTestCase {

	/**
	 * Test profession CPT constants exist.
	 */
	public function test_profession_meta_constants_exist() {
		$this->assertTrue( defined( 'WP_MCP_AI_Profession_CPT::META_MEMORY_FILES' ) );
		$this->assertTrue( defined( 'WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID' ) );
		$this->assertTrue( defined( 'WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES' ) );
	}

	/**
	 * Test memory files meta field registration.
	 */
	public function test_memory_files_meta_registered() {
		$registered = get_registered_meta_keys( 'post', WP_MCP_AI_Profession_CPT::POST_TYPE );
		$this->assertArrayHasKey( WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, $registered );
		$this->assertEquals( 'array', $registered[ WP_MCP_AI_Profession_CPT::META_MEMORY_FILES ]['type'] );
	}

	/**
	 * Test vector store ID meta field registration.
	 */
	public function test_vector_store_id_meta_registered() {
		$registered = get_registered_meta_keys( 'post', WP_MCP_AI_Profession_CPT::POST_TYPE );
		$this->assertArrayHasKey( WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, $registered );
		$this->assertEquals( 'string', $registered[ WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID ]['type'] );
	}

	/**
	 * Test supported MIME types meta field registration.
	 */
	public function test_supported_mime_types_meta_registered() {
		$registered = get_registered_meta_keys( 'post', WP_MCP_AI_Profession_CPT::POST_TYPE );
		$this->assertArrayHasKey( WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, $registered );
		$this->assertEquals( 'array', $registered[ WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES ]['type'] );
	}

	/**
	 * Test memory files sanitization.
	 */
	public function test_memory_files_sanitization() {
		$cpt = new WP_MCP_AI_Profession_CPT();

		// Test valid array of IDs.
		$input  = array( '123', '456', '789' );
		$output = $cpt->sanitize_memory_files( $input );
		$this->assertEquals( array( 123, 456, 789 ), $output );

		// Test with invalid values (zeros should be removed).
		$input  = array( '123', '0', '456', '' );
		$output = $cpt->sanitize_memory_files( $input );
		$this->assertEquals( array( 123, 456 ), array_values( $output ) );

		// Test non-array input.
		$output = $cpt->sanitize_memory_files( 'not-an-array' );
		$this->assertEquals( array(), $output );
	}

	/**
	 * Test vector store ID sanitization.
	 */
	public function test_vector_store_id_sanitization() {
		$cpt = new WP_MCP_AI_Profession_CPT();

		// Test valid string.
		$input  = 'vs_abc123xyz';
		$output = $cpt->sanitize_vector_store_id( $input );
		$this->assertEquals( 'vs_abc123xyz', $output );

		// Test string with HTML (should be sanitized).
		$input  = '<script>alert("xss")</script>vs_test';
		$output = $cpt->sanitize_vector_store_id( $input );
		$this->assertStringNotContainsString( '<script>', $output );

		// Test non-string input.
		$output = $cpt->sanitize_vector_store_id( 123 );
		$this->assertEquals( '', $output );
	}

	/**
	 * Test saving and retrieving memory files.
	 */
	public function test_save_and_retrieve_memory_files() {
		// Create a profession post.
		$post_id = $this->factory->post->create(
			array(
				'post_type' => WP_MCP_AI_Profession_CPT::POST_TYPE,
			)
		);

		// Create some attachment IDs to use.
		$attachment_ids = array( 123, 456, 789 );

		// Save memory files.
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, $attachment_ids );

		// Retrieve and verify.
		$retrieved = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertEquals( $attachment_ids, $retrieved );
	}

	/**
	 * Test saving and retrieving vector store ID.
	 */
	public function test_save_and_retrieve_vector_store_id() {
		// Create a profession post.
		$post_id = $this->factory->post->create(
			array(
				'post_type' => WP_MCP_AI_Profession_CPT::POST_TYPE,
			)
		);

		// Save vector store ID.
		$vector_id = 'vs_test123';
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, $vector_id );

		// Retrieve and verify.
		$retrieved = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, true );
		$this->assertEquals( $vector_id, $retrieved );
	}

	/**
	 * Test saving and retrieving supported MIME types.
	 */
	public function test_save_and_retrieve_mime_types() {
		// Create a profession post.
		$post_id = $this->factory->post->create(
			array(
				'post_type' => WP_MCP_AI_Profession_CPT::POST_TYPE,
			)
		);

		// Save MIME types.
		$mime_types = array( 'application/pdf', 'image/jpeg', 'text/plain' );
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, $mime_types );

		// Retrieve and verify.
		$retrieved = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );
		$this->assertEquals( $mime_types, $retrieved );
	}

	/**
	 * Test metabox is registered.
	 */
	public function test_base_knowledge_metabox_registered() {
		global $wp_meta_boxes;

		// Simulate WordPress loading meta boxes.
		do_action( 'add_meta_boxes', WP_MCP_AI_Profession_CPT::POST_TYPE, null );

		// Check if our metabox is registered.
		$this->assertArrayHasKey( WP_MCP_AI_Profession_CPT::POST_TYPE, $wp_meta_boxes );
	}

	/**
	 * Test that metabox class exists and has required methods.
	 */
	public function test_base_knowledge_metabox_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Profession_Metabox_Base_Knowledge' ) );

		$metabox = new WP_MCP_AI_Profession_Metabox_Base_Knowledge();
		$this->assertTrue( method_exists( $metabox, 'render' ) );
		$this->assertTrue( method_exists( $metabox, 'save' ) );
		$this->assertTrue( method_exists( $metabox, 'get_id' ) );
		$this->assertTrue( method_exists( $metabox, 'get_title' ) );
	}

	/**
	 * Test metabox save functionality.
	 */
	public function test_metabox_save_functionality() {
		// Create a profession post.
		$post_id = $this->factory->post->create(
			array(
				'post_type' => WP_MCP_AI_Profession_CPT::POST_TYPE,
			)
		);
		$post    = get_post( $post_id );

		// Simulate POST data.
		$_POST['wp_mcp_ai_profession_memory_files']    = array( '123', '456' );
		$_POST['wp_mcp_ai_profession_vector_store_id'] = 'vs_test123';
		$_POST['wp_mcp_ai_profession_mime_types']      = array( 'application/pdf', 'image/jpeg' );

		// Create metabox instance and save.
		$metabox = new WP_MCP_AI_Profession_Metabox_Base_Knowledge();

		// Mock current user as admin.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$metabox->save( $post_id, $post );

		// Verify saved data.
		$memory_files = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertEquals( array( 123, 456 ), $memory_files );

		$vector_id = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, true );
		$this->assertEquals( 'vs_test123', $vector_id );

		$mime_types = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );
		$this->assertEquals( array( 'application/pdf', 'image/jpeg' ), $mime_types );

		// Clean up.
		unset( $_POST['wp_mcp_ai_profession_memory_files'] );
		unset( $_POST['wp_mcp_ai_profession_vector_store_id'] );
		unset( $_POST['wp_mcp_ai_profession_mime_types'] );
	}

	/**
	 * Test that empty values are handled correctly.
	 */
	public function test_empty_values_handling() {
		// Create a profession post.
		$post_id = $this->factory->post->create(
			array(
				'post_type' => WP_MCP_AI_Profession_CPT::POST_TYPE,
			)
		);
		$post    = get_post( $post_id );

		// First, set some values.
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, array( 123 ) );
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, 'vs_test' );

		// Now save with empty POST data.
		$_POST = array(); // No data submitted.

		$metabox = new WP_MCP_AI_Profession_Metabox_Base_Knowledge();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$metabox->save( $post_id, $post );

		// Verify that fields were cleared.
		$memory_files = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertEmpty( $memory_files );

		$vector_id = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, true );
		$this->assertEmpty( $vector_id );
	}
}
