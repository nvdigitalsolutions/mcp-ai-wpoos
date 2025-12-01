<?php
/**
 * Tests covering team post type registrations and sanitization.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Team_Tools_Test extends WP_UnitTestCase {

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
		$this->team_cpt = new WP_MCP_AI_Team_CPT();
	}

	/**
	 * Ensure team members sanitization handles non-array values.
	 */
	public function test_sanitize_team_members_handles_non_array_values() {
		$this->assertSame(
			array(),
			$this->team_cpt->sanitize_team_members( null )
		);

		$this->assertSame(
			array(),
			$this->team_cpt->sanitize_team_members( 'string-value' )
		);

		$this->assertSame(
			array(),
			$this->team_cpt->sanitize_team_members( 123 )
		);
	}

	/**
	 * Ensure team members sanitization filters out non-profession post IDs.
	 */
	public function test_sanitize_team_members_filters_non_profession_posts() {
		// Create a profession post.
		$profession_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		// Create a regular post.
		$regular_post_id = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$input = array(
			$profession_id,
			$regular_post_id,
			'invalid',
			0,
			-1,
		);

		$sanitized = $this->team_cpt->sanitize_team_members( $input );

		$this->assertIsArray( $sanitized );
		$this->assertContains( $profession_id, $sanitized );
		$this->assertNotContains( $regular_post_id, $sanitized ); // Regular post filtered out.
		$this->assertNotContains( 0, $sanitized );
	}

	/**
	 * Ensure team members sanitization removes duplicates.
	 */
	public function test_sanitize_team_members_removes_duplicates() {
		// Create profession posts.
		$profession_1 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$profession_2 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$input = array(
			$profession_1,
			$profession_2,
			$profession_1, // Duplicate.
			$profession_2, // Duplicate.
		);

		$sanitized = $this->team_cpt->sanitize_team_members( $input );

		$this->assertIsArray( $sanitized );
		$this->assertCount( 2, $sanitized );
		$this->assertContains( $profession_1, $sanitized );
		$this->assertContains( $profession_2, $sanitized );
	}

	/**
	 * Ensure temperature sanitization handles empty values.
	 */
	public function test_sanitize_temperature_handles_empty_values() {
		$this->assertNull( $this->team_cpt->sanitize_temperature( '' ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( null ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( '  ' ) );
	}

	/**
	 * Ensure temperature sanitization accepts valid numeric values.
	 */
	public function test_sanitize_temperature_accepts_valid_values() {
		$this->assertSame( 0.0, $this->team_cpt->sanitize_temperature( 0 ) );
		$this->assertSame( 0.7, $this->team_cpt->sanitize_temperature( 0.7 ) );
		$this->assertSame( 1.0, $this->team_cpt->sanitize_temperature( 1 ) );
		$this->assertSame( 2.0, $this->team_cpt->sanitize_temperature( 2 ) );
		$this->assertSame( 1.5, $this->team_cpt->sanitize_temperature( '1.5' ) );
	}

	/**
	 * Ensure temperature sanitization rejects out-of-range values.
	 */
	public function test_sanitize_temperature_rejects_out_of_range_values() {
		$this->assertNull( $this->team_cpt->sanitize_temperature( -0.1 ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( -1 ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( 2.1 ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( 3 ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( 100 ) );
	}

	/**
	 * Ensure temperature sanitization handles non-numeric values.
	 */
	public function test_sanitize_temperature_handles_non_numeric_values() {
		$this->assertNull( $this->team_cpt->sanitize_temperature( 'abc' ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( 'invalid' ) );
		$this->assertNull( $this->team_cpt->sanitize_temperature( array( 1.0 ) ) );
	}

	/**
	 * Ensure the team post type is registered correctly.
	 */
	public function test_team_post_type_is_registered() {
		$this->team_cpt->register_post_type();

		$post_type = get_post_type_object( WP_MCP_AI_Team_CPT::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertSame( 'mcp_ai_team', $post_type->name );
		$this->assertFalse( $post_type->public );
		$this->assertTrue( $post_type->show_ui );
	}

	/**
	 * Ensure saving a team persists meta data correctly.
	 */
	public function test_save_post_persists_team_meta() {
		// Create profession posts to use as team members.
		$profession_1 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$profession_2 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		// Create team post.
		$team_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Team_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up POST data.
		$_POST['wp_mcp_ai_team_members_meta_nonce'] = wp_create_nonce( 'wp_mcp_ai_team_members_meta' );
		$_POST['wp_mcp_ai_team_members']            = array( $profession_1, $profession_2, $profession_1 ); // Include duplicate.

		$_POST['wp_mcp_ai_team_defaults_meta_nonce'] = wp_create_nonce( 'wp_mcp_ai_team_defaults_meta' );
		$_POST['wp_mcp_ai_default_provider']         = 'openai';
		$_POST['wp_mcp_ai_default_model']            = 'gpt-4';
		$_POST['wp_mcp_ai_default_temperature']      = '0.7';

		$this->team_cpt->save_post( $team_id, get_post( $team_id ) );

		// Verify team members meta was saved and deduplicated.
		$team_members = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
		$this->assertIsArray( $team_members );
		$this->assertCount( 2, $team_members ); // Duplicates removed.
		$this->assertContains( $profession_1, $team_members );
		$this->assertContains( $profession_2, $team_members );

		// Verify default settings meta was saved.
		$provider = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_PROVIDER, true );
		$this->assertSame( 'openai', $provider );

		$model = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_MODEL, true );
		$this->assertSame( 'gpt-4', $model );

		$temperature = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_TEMPERATURE, true );
		$this->assertSame( 0.7, $temperature );

		// Clean up.
		$_POST = array();
		wp_set_current_user( 0 );
	}
}
