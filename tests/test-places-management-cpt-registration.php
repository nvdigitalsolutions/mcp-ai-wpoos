<?php
/**
 * Tests for Places Management CPT Registration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Places Management CPT registration functionality.
 */
class WP_MCP_AI_Places_Management_CPT_Registration_Test extends WP_UnitTestCase {
	/**
	 * Original settings value to restore after tests.
	 *
	 * @var array
	 */
	private $original_settings;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Store original settings.
		$this->original_settings = get_option( 'wp_mcp_ai_settings', array() );

		// Unregister any existing post types to start fresh.
		global $wp_post_types;
		if ( isset( $wp_post_types['mcp_ai_place'] ) ) {
			unset( $wp_post_types['mcp_ai_place'] );
		}

		// Unregister any existing taxonomies.
		global $wp_taxonomies;
		if ( isset( $wp_taxonomies['mcp_ai_place_type'] ) ) {
			unset( $wp_taxonomies['mcp_ai_place_type'] );
		}
		if ( isset( $wp_taxonomies['mcp_ai_place_tag'] ) ) {
			unset( $wp_taxonomies['mcp_ai_place_tag'] );
		}
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Restore original settings.
		update_option( 'wp_mcp_ai_settings', $this->original_settings );

		parent::tearDown();
	}

	/**
	 * Test that places CPT is registered when feature is enabled.
	 */
	public function test_cpt_registered_when_enabled() {
		// Enable places management.
		$settings                            = $this->original_settings;
		$settings['enable_places_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_places_management_post_type();

		// Verify post type is registered.
		$this->assertTrue( post_type_exists( 'mcp_ai_place' ), 'Place CPT should be registered' );
	}

	/**
	 * Test that places CPT is NOT registered when feature is disabled.
	 */
	public function test_cpt_not_registered_when_disabled() {
		// Disable places management.
		$settings                            = $this->original_settings;
		$settings['enable_places_management'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_places_management_post_type();

		// Verify post type is NOT registered.
		$this->assertFalse( post_type_exists( 'mcp_ai_place' ), 'Place CPT should NOT be registered when disabled' );
	}

	/**
	 * Test that place CPT has show_in_menu set to true.
	 */
	public function test_place_cpt_shows_in_menu() {
		// Enable places management.
		$settings                            = $this->original_settings;
		$settings['enable_places_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_places_management_post_type();

		// Get the post type object.
		$place_post_type = get_post_type_object( 'mcp_ai_place' );

		// Verify show_in_menu is set to true.
		$this->assertTrue( $place_post_type->show_in_menu, 'Place CPT should have show_in_menu set to true' );
		$this->assertEquals( 'dashicons-location-alt', $place_post_type->menu_icon, 'Place CPT should have location-alt icon' );
	}

	/**
	 * Test that taxonomies are registered.
	 */
	public function test_taxonomies_registered() {
		// Enable places management.
		$settings                            = $this->original_settings;
		$settings['enable_places_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_places_management_post_type();

		// Verify taxonomies are registered.
		$this->assertTrue( taxonomy_exists( 'mcp_ai_place_type' ), 'Place Type taxonomy should be registered' );
		$this->assertTrue( taxonomy_exists( 'mcp_ai_place_tag' ), 'Place Tag taxonomy should be registered' );
	}

	/**
	 * Test that default place types are created.
	 */
	public function test_default_place_types_created() {
		// Enable places management.
		$settings                            = $this->original_settings;
		$settings['enable_places_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_places_management_post_type();

		// Check for default types.
		$default_types = array( 'restaurant', 'cafe', 'hotel', 'attraction', 'museum', 'park' );

		foreach ( $default_types as $type_slug ) {
			$term = term_exists( $type_slug, 'mcp_ai_place_type' );
			$this->assertNotNull( $term, "Default place type '$type_slug' should exist" );
		}
	}

	/**
	 * Test that place type taxonomy is hierarchical.
	 */
	public function test_place_type_taxonomy_is_hierarchical() {
		// Enable places management.
		$settings                            = $this->original_settings;
		$settings['enable_places_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_places_management_post_type();

		$taxonomy = get_taxonomy( 'mcp_ai_place_type' );
		$this->assertTrue( $taxonomy->hierarchical, 'Place Type taxonomy should be hierarchical' );
	}

	/**
	 * Test that place tag taxonomy is NOT hierarchical.
	 */
	public function test_place_tag_taxonomy_not_hierarchical() {
		// Enable places management.
		$settings                            = $this->original_settings;
		$settings['enable_places_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_places_management_post_type();

		$taxonomy = get_taxonomy( 'mcp_ai_place_tag' );
		$this->assertFalse( $taxonomy->hierarchical, 'Place Tag taxonomy should NOT be hierarchical' );
	}

	/**
	 * Test CPT visibility settings.
	 */
	public function test_cpt_visibility_settings() {
		// Enable places management.
		$settings                            = $this->original_settings;
		$settings['enable_places_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_places_management_post_type();

		$post_type = get_post_type_object( 'mcp_ai_place' );

		$this->assertFalse( $post_type->public, 'Place CPT should not be public' );
		$this->assertTrue( $post_type->show_ui, 'Place CPT should show UI' );
		$this->assertTrue( $post_type->show_in_rest, 'Place CPT should show in REST API' );
		$this->assertFalse( $post_type->has_archive, 'Place CPT should not have archive' );
	}

	/**
	 * Test CPT supports correct features.
	 */
	public function test_cpt_supports() {
		// Enable places management.
		$settings                            = $this->original_settings;
		$settings['enable_places_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_places_management_post_type();

		$this->assertTrue( post_type_supports( 'mcp_ai_place', 'title' ), 'Place CPT should support title' );
		$this->assertTrue( post_type_supports( 'mcp_ai_place', 'editor' ), 'Place CPT should support editor' );
		$this->assertTrue( post_type_supports( 'mcp_ai_place', 'thumbnail' ), 'Place CPT should support thumbnail' );
		$this->assertTrue( post_type_supports( 'mcp_ai_place', 'author' ), 'Place CPT should support author' );
	}
}
