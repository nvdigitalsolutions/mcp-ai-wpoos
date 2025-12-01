<?php
/**
 * Comprehensive tests for Profession and Team CPT sanitization methods.
 *
 * This test file provides complete coverage of all custom sanitization methods
 * used by the Profession and Team custom post types, ensuring data integrity
 * and security when creating assistants from professions/teams.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Profession and Team CPT sanitization.
 */
class WP_MCP_AI_Profession_Team_CPT_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Profession CPT instance.
	 *
	 * @var WP_MCP_AI_Profession_CPT
	 */
	protected $profession_cpt;

	/**
	 * Team CPT instance.
	 *
	 * @var WP_MCP_AI_Team_CPT
	 */
	protected $team_cpt;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize CPT instances.
		$this->profession_cpt = new WP_MCP_AI_Profession_CPT();
		$this->team_cpt       = new WP_MCP_AI_Team_CPT();

		// Register post types and meta for testing.
		$this->profession_cpt->register_post_type();
		$this->profession_cpt->register_meta();
		$this->team_cpt->register_post_type();
		$this->team_cpt->register_meta();
	}

	/**
	 * Test sanitize_array_field with empty arrays.
	 */
	public function test_profession_sanitize_array_field_empty_array() {
		$result = $this->profession_cpt->sanitize_array_field( array() );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test sanitize_array_field with valid string array.
	 */
	public function test_profession_sanitize_array_field_valid_strings() {
		$input  = array( 'WordPress', 'PHP', 'JavaScript', 'MySQL' );
		$result = $this->profession_cpt->sanitize_array_field( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 4, $result );
		$this->assertSame( $input, $result );
	}

	/**
	 * Test sanitize_array_field removes HTML tags.
	 */
	public function test_profession_sanitize_array_field_removes_html() {
		$input = array(
			'<strong>Bold Text</strong>',
			'<script>alert("XSS")</script>',
			'<p>Paragraph</p>',
		);

		$result = $this->profession_cpt->sanitize_array_field( $input );

		$this->assertIsArray( $result );
		$this->assertSame( 'Bold Text', $result[0] );
		$this->assertSame( 'alert("XSS")', $result[1] );
		$this->assertSame( 'Paragraph', $result[2] );
	}

	/**
	 * Test sanitize_array_field with mixed data types.
	 */
	public function test_profession_sanitize_array_field_mixed_types() {
		$input = array(
			'string',
			123,
			45.67,
			true,
			false,
		);

		$result = $this->profession_cpt->sanitize_array_field( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 5, $result );
		// All values should be converted to strings.
		$this->assertSame( 'string', $result[0] );
		$this->assertSame( '123', $result[1] );
		$this->assertSame( '45.67', $result[2] );
		$this->assertSame( '1', $result[3] );
		$this->assertSame( '', $result[4] );
	}

	/**
	 * Test sanitize_memory_files with valid attachment IDs.
	 */
	public function test_profession_sanitize_memory_files_valid_ids() {
		$input  = array( 123, 456, 789 );
		$result = $this->profession_cpt->sanitize_memory_files( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertContains( 123, $result );
		$this->assertContains( 456, $result );
		$this->assertContains( 789, $result );
	}

	/**
	 * Test sanitize_memory_files converts string IDs to integers.
	 */
	public function test_profession_sanitize_memory_files_string_ids() {
		$input  = array( '123', '456', '789' );
		$result = $this->profession_cpt->sanitize_memory_files( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertContains( 123, $result );
		$this->assertContains( 456, $result );
		$this->assertContains( 789, $result );
	}

	/**
	 * Test sanitize_memory_files filters zero values.
	 */
	public function test_profession_sanitize_memory_files_filters_zero() {
		$input  = array( 0, '0', 123, 456 );
		$result = $this->profession_cpt->sanitize_memory_files( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertNotContains( 0, $result );
		$this->assertContains( 123, $result );
		$this->assertContains( 456, $result );
	}

	/**
	 * Test sanitize_memory_files converts negative to zero and filters.
	 */
	public function test_profession_sanitize_memory_files_negative_values() {
		$input  = array( -1, -999, 123 );
		$result = $this->profession_cpt->sanitize_memory_files( $input );

		$this->assertIsArray( $result );
		// Negative values get converted to 0 by absint, then filtered out.
		$this->assertCount( 1, $result );
		$this->assertContains( 123, $result );
		$this->assertNotContains( -1, $result );
	}

	/**
	 * Test sanitize_memory_files with all invalid values.
	 */
	public function test_profession_sanitize_memory_files_all_invalid() {
		$input  = array( 'invalid', null, false, '', array(), 0 );
		$result = $this->profession_cpt->sanitize_memory_files( $input );

		$this->assertSame( array(), $result );
	}

	/**
	 * Test sanitize_vector_store_id with valid ID.
	 */
	public function test_profession_sanitize_vector_store_id_valid() {
		$input  = 'vs_1234567890abcdef';
		$result = $this->profession_cpt->sanitize_vector_store_id( $input );

		$this->assertSame( $input, $result );
	}

	/**
	 * Test sanitize_vector_store_id removes HTML and JavaScript.
	 */
	public function test_profession_sanitize_vector_store_id_removes_tags() {
		$input  = '<script>alert("XSS")</script>vs_test';
		$result = $this->profession_cpt->sanitize_vector_store_id( $input );

		$this->assertSame( 'alert("XSS")vs_test', $result );
	}

	/**
	 * Test sanitize_vector_store_id with empty string.
	 */
	public function test_profession_sanitize_vector_store_id_empty() {
		$this->assertSame( '', $this->profession_cpt->sanitize_vector_store_id( '' ) );
	}

	/**
	 * Test sanitize_team_members with valid profession IDs.
	 */
	public function test_team_sanitize_team_members_valid() {
		// Create profession posts.
		$prof1 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$prof2 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$input  = array( $prof1, $prof2 );
		$result = $this->team_cpt->sanitize_team_members( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertContains( $prof1, $result );
		$this->assertContains( $prof2, $result );
	}

	/**
	 * Test sanitize_team_members filters non-profession posts.
	 */
	public function test_team_sanitize_team_members_filters_wrong_type() {
		// Create a profession and a regular post.
		$prof = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$post = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$input  = array( $prof, $post );
		$result = $this->team_cpt->sanitize_team_members( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertContains( $prof, $result );
		$this->assertNotContains( $post, $result );
	}

	/**
	 * Test sanitize_team_members removes duplicate IDs.
	 */
	public function test_team_sanitize_team_members_removes_duplicates() {
		// Create profession posts.
		$prof1 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$prof2 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$input  = array( $prof1, $prof2, $prof1, $prof2, $prof1 );
		$result = $this->team_cpt->sanitize_team_members( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test sanitize_team_members with string IDs.
	 */
	public function test_team_sanitize_team_members_string_ids() {
		// Create profession post.
		$prof = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		// Pass as string.
		$input  = array( (string) $prof );
		$result = $this->team_cpt->sanitize_team_members( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertContains( $prof, $result );
	}

	/**
	 * Test sanitize_temperature with valid values.
	 */
	public function test_team_sanitize_temperature_valid_range() {
		$this->assertSame( 0.0, $this->team_cpt->sanitize_temperature( 0 ) );
		$this->assertSame( 0.5, $this->team_cpt->sanitize_temperature( 0.5 ) );
		$this->assertSame( 1.0, $this->team_cpt->sanitize_temperature( 1.0 ) );
		$this->assertSame( 1.5, $this->team_cpt->sanitize_temperature( 1.5 ) );
		$this->assertSame( 2.0, $this->team_cpt->sanitize_temperature( 2.0 ) );
	}

	/**
	 * Test sanitize_temperature with string numeric values.
	 */
	public function test_team_sanitize_temperature_string_numbers() {
		$this->assertSame( 0.7, $this->team_cpt->sanitize_temperature( '0.7' ) );
		$this->assertSame( 1.0, $this->team_cpt->sanitize_temperature( '1' ) );
		$this->assertSame( 1.8, $this->team_cpt->sanitize_temperature( '1.8' ) );
	}

	/**
	 * Test sanitize_temperature rejects values below 0.
	 */
	public function test_team_sanitize_temperature_below_min() {
		$this->assertNull( $this->team_cpt->sanitize_temperature( -0.1 ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( -1 ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( -999 ) );
	}

	/**
	 * Test sanitize_temperature rejects values above 2.
	 */
	public function test_team_sanitize_temperature_above_max() {
		$this->assertNull( $this->team_cpt->sanitize_temperature( 2.1 ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( 3 ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( 100 ) );
	}

	/**
	 * Test sanitize_temperature with whitespace strings.
	 */
	public function test_team_sanitize_temperature_whitespace() {
		$this->assertNull( $this->team_cpt->sanitize_temperature( '  ' ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( "\t" ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( "\n" ) );
	}

	/**
	 * Test sanitize_temperature with trimmed numeric strings.
	 */
	public function test_team_sanitize_temperature_trimmed() {
		$this->assertSame( 0.7, $this->team_cpt->sanitize_temperature( '  0.7  ' ) );
		$this->assertSame( 1.5, $this->team_cpt->sanitize_temperature( "\t1.5\n" ) );
	}

	/**
	 * Test sanitize_temperature with non-numeric strings.
	 */
	public function test_team_sanitize_temperature_non_numeric() {
		$this->assertNull( $this->team_cpt->sanitize_temperature( 'abc' ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( 'high' ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( '1.5x' ) );
	}

	/**
	 * Test metadata registration for profession CPT.
	 */
	public function test_profession_meta_registered() {
		// Verify meta keys are registered.
		$this->assertTrue( registered_meta_key_exists( 'post', WP_MCP_AI_Profession_CPT::META_CATEGORY, 'mcp_ai_profession' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', WP_MCP_AI_Profession_CPT::META_EXPERTISE, 'mcp_ai_profession' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, 'mcp_ai_profession' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, 'mcp_ai_profession' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, 'mcp_ai_profession' ) );
	}

	/**
	 * Test metadata registration for team CPT.
	 */
	public function test_team_meta_registered() {
		// Verify meta keys are registered.
		$this->assertTrue( registered_meta_key_exists( 'post', WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, 'mcp_ai_team' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', WP_MCP_AI_Team_CPT::META_DEFAULT_PROVIDER, 'mcp_ai_team' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', WP_MCP_AI_Team_CPT::META_DEFAULT_MODEL, 'mcp_ai_team' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', WP_MCP_AI_Team_CPT::META_DEFAULT_TEMPERATURE, 'mcp_ai_team' ) );
	}

	/**
	 * Test sanitization is applied when updating meta.
	 */
	public function test_profession_meta_sanitization_on_update() {
		// Create a profession.
		$prof_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		// Update with unsanitized data.
		update_post_meta( $prof_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, array( '<script>alert("XSS")</script>', 'PHP' ) );

		// Retrieve and verify sanitization.
		$expertise = get_post_meta( $prof_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
		$this->assertIsArray( $expertise );
		$this->assertSame( 'alert("XSS")', $expertise[0] );
		$this->assertSame( 'PHP', $expertise[1] );
	}

	/**
	 * Test sanitization is applied when updating team meta.
	 */
	public function test_team_meta_sanitization_on_update() {
		// Create a team.
		$team_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_team',
				'post_status' => 'publish',
			)
		);

		// Try to set invalid temperature.
		update_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_TEMPERATURE, 999 );

		// Retrieve and verify sanitization (should be null).
		$temp = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_TEMPERATURE, true );
		$this->assertEmpty( $temp );

		// Try with valid temperature.
		update_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_TEMPERATURE, 0.7 );
		$temp = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_TEMPERATURE, true );
		$this->assertSame( 0.7, $temp );
	}
}
