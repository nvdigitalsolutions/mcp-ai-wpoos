<?php
/**
 * Tests covering profession post type registrations and sanitization.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Professional_Tools_Test extends WP_UnitTestCase {

	/**
	 * Profession CPT instance.
	 *
	 * @var WP_MCP_AI_Profession_CPT
	 */
	protected $profession_cpt;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->profession_cpt = new WP_MCP_AI_Profession_CPT();
	}

	/**
	 * Ensure array field sanitization handles non-array values.
	 */
	public function test_sanitize_array_field_handles_non_array_values() {
		$this->assertSame(
			array(),
			$this->profession_cpt->sanitize_array_field( null )
		);

		$this->assertSame(
			array(),
			$this->profession_cpt->sanitize_array_field( 'string-value' )
		);

		$this->assertSame(
			array(),
			$this->profession_cpt->sanitize_array_field( 123 )
		);
	}

	/**
	 * Ensure array field sanitization properly sanitizes array values.
	 */
	public function test_sanitize_array_field_sanitizes_array_values() {
		$input = array(
			'valid-value',
			'<script>alert("xss")</script>',
			'another-valid-value',
		);

		$sanitized = $this->profession_cpt->sanitize_array_field( $input );

		$this->assertIsArray( $sanitized );
		$this->assertCount( 3, $sanitized );
		$this->assertSame( 'valid-value', $sanitized[0] );
		$this->assertSame( 'alert("xss")', $sanitized[1] ); // Script tags removed.
		$this->assertSame( 'another-valid-value', $sanitized[2] );
	}

	/**
	 * Ensure memory files sanitization handles non-array values.
	 */
	public function test_sanitize_memory_files_handles_non_array_values() {
		$this->assertSame(
			array(),
			$this->profession_cpt->sanitize_memory_files( null )
		);

		$this->assertSame(
			array(),
			$this->profession_cpt->sanitize_memory_files( '123' )
		);
	}

	/**
	 * Ensure memory files sanitization converts values to positive integers.
	 */
	public function test_sanitize_memory_files_converts_to_positive_integers() {
		$input = array( '123', 456, '789', 0, -1, 'invalid' );

		$sanitized = $this->profession_cpt->sanitize_memory_files( $input );

		$this->assertIsArray( $sanitized );
		$this->assertContains( 123, $sanitized );
		$this->assertContains( 456, $sanitized );
		$this->assertContains( 789, $sanitized );
		$this->assertNotContains( 0, $sanitized ); // Zero values filtered out.
		$this->assertNotContains( -1, $sanitized ); // Negative values converted to 0 and filtered.
	}

	/**
	 * Ensure memory files sanitization removes zero and invalid values.
	 */
	public function test_sanitize_memory_files_filters_invalid_values() {
		$input = array( 0, '', null, false, 'abc', '0' );

		$sanitized = $this->profession_cpt->sanitize_memory_files( $input );

		$this->assertSame( array(), $sanitized );
	}

	/**
	 * Ensure vector store ID sanitization handles non-string values.
	 */
	public function test_sanitize_vector_store_id_handles_non_string_values() {
		$this->assertSame(
			'',
			$this->profession_cpt->sanitize_vector_store_id( null )
		);

		$this->assertSame(
			'',
			$this->profession_cpt->sanitize_vector_store_id( 123 )
		);

		$this->assertSame(
			'',
			$this->profession_cpt->sanitize_vector_store_id( array( 'value' ) )
		);
	}

	/**
	 * Ensure vector store ID sanitization properly sanitizes string values.
	 */
	public function test_sanitize_vector_store_id_sanitizes_string_values() {
		$this->assertSame(
			'vs_1234567890',
			$this->profession_cpt->sanitize_vector_store_id( 'vs_1234567890' )
		);

		$this->assertSame(
			'alert("xss")',
			$this->profession_cpt->sanitize_vector_store_id( '<script>alert("xss")</script>' )
		);
	}

	/**
	 * Ensure the profession post type is registered correctly.
	 */
	public function test_profession_post_type_is_registered() {
		$this->profession_cpt->register_post_type();

		$post_type = get_post_type_object( WP_MCP_AI_Profession_CPT::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertSame( 'mcp_ai_profession', $post_type->name );
		$this->assertFalse( $post_type->public );
		$this->assertTrue( $post_type->show_ui );
	}

	/**
	 * Ensure saving a profession persists meta data correctly.
	 */
	public function test_save_post_persists_profession_meta() {
		$profession_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Profession_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up POST data.
		$_POST['wp_mcp_ai_profession_nonce'] = wp_create_nonce( 'wp_mcp_ai_save_profession' );
		$_POST['profession_category']        = 'technical';
		$_POST['profession_expertise']       = array( 'PHP', 'WordPress', 'JavaScript' );
		$_POST['profession_memory_files']    = array( '123', 'invalid', '456' );
		$_POST['profession_vector_store_id'] = 'vs_test_123';

		$this->profession_cpt->save_post( $profession_id, get_post( $profession_id ) );

		// Verify meta was saved.
		$category = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
		$this->assertSame( 'technical', $category );

		$expertise = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
		$this->assertIsArray( $expertise );
		$this->assertContains( 'PHP', $expertise );

		$memory_files = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$this->assertIsArray( $memory_files );
		$this->assertContains( 123, $memory_files );
		$this->assertContains( 456, $memory_files );
		$this->assertNotContains( 'invalid', $memory_files );

		$vector_store_id = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, true );
		$this->assertSame( 'vs_test_123', $vector_store_id );

		// Clean up.
		$_POST = array();
		wp_set_current_user( 0 );
	}
}
