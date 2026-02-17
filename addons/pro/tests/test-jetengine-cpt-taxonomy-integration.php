<?php
/**
 * Test JetEngine CPT and Taxonomy Integration
 *
 * Tests for JetEngine custom post types and taxonomies AI integration.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for JetEngine CPT and Taxonomy integration.
 *
 * @group jetengine
 * @group pro
 */
class Test_JetEngine_CPT_Taxonomy_Integration extends WP_UnitTestCase {

	/**
	 * Test that JetEngine CPT detection works.
	 */
	public function test_jetengine_cpt_detection() {
		// Skip if JetEngine is not active.
		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
			$this->markTestSkipped( 'JetEngine is not active' );
		}

		// Get the AI CPT Integration instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();
		$this->assertInstanceOf( 'WP_MCP_AI_Pro_CPT_AI_Integration', $integration );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_jetengine_cpts' );
		$method->setAccessible( true );

		$cpts = $method->invoke( $integration );

		// Should return an array.
		$this->assertIsArray( $cpts );

		// Each CPT should have a slug.
		foreach ( $cpts as $cpt ) {
			$this->assertArrayHasKey( 'slug', $cpt );
		}
	}

	/**
	 * Test that JetEngine taxonomy detection works.
	 */
	public function test_jetengine_taxonomy_detection() {
		// Skip if JetEngine is not active.
		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
			$this->markTestSkipped( 'JetEngine is not active' );
		}

		// Get the AI CPT Integration instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_jetengine_taxonomies' );
		$method->setAccessible( true );

		$taxonomies = $method->invoke( $integration );

		// Should return an array.
		$this->assertIsArray( $taxonomies );

		// Each taxonomy should have a slug.
		foreach ( $taxonomies as $taxonomy ) {
			$this->assertArrayHasKey( 'slug', $taxonomy );
		}
	}

	/**
	 * Test that settings control feature availability.
	 */
	public function test_jetengine_cpt_setting_control() {
		// Skip if JetEngine is not active.
		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
			$this->markTestSkipped( 'JetEngine is not active' );
		}

		// Test with setting enabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_jetengine_cpt_ai' => true,
			)
		);

		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();
		$reflection  = new ReflectionClass( $integration );
		$method      = $reflection->getMethod( 'is_jetengine_cpt_support_enabled' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $integration ) );

		// Test with setting disabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_jetengine_cpt_ai' => false,
			)
		);

		// Create new instance to reflect updated settings.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();
		$reflection  = new ReflectionClass( $integration );
		$method      = $reflection->getMethod( 'is_jetengine_cpt_support_enabled' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $integration ) );
	}

	/**
	 * Test that JetEngine CPTs are added to supported post types.
	 */
	public function test_jetengine_cpts_added_to_supported() {
		// Skip if JetEngine is not active.
		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
			$this->markTestSkipped( 'JetEngine is not active' );
		}

		// Enable JetEngine CPT AI support.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_jetengine_cpt_ai' => true,
			)
		);

		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();
		$reflection  = new ReflectionClass( $integration );

		// Get JetEngine CPTs.
		$cpts_method = $reflection->getMethod( 'get_jetengine_cpts' );
		$cpts_method->setAccessible( true );
		$jetengine_cpts = $cpts_method->invoke( $integration );

		// Get supported post types.
		$supported_method = $reflection->getMethod( 'get_supported_post_types' );
		$supported_method->setAccessible( true );
		$supported_post_types = $supported_method->invoke( $integration );

		// If there are JetEngine CPTs, they should be in supported post types.
		if ( ! empty( $jetengine_cpts ) ) {
			$jetengine_cpt_slugs = wp_list_pluck( $jetengine_cpts, 'slug' );
			foreach ( $jetengine_cpt_slugs as $slug ) {
				$this->assertContains( $slug, $supported_post_types );
			}
		}
	}

	/**
	 * Test that JetEngine taxonomies are added to supported taxonomies.
	 */
	public function test_jetengine_taxonomies_added_to_supported() {
		// Skip if JetEngine is not active.
		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
			$this->markTestSkipped( 'JetEngine is not active' );
		}

		// Enable JetEngine CPT AI support.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_jetengine_cpt_ai' => true,
			)
		);

		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();
		$reflection  = new ReflectionClass( $integration );

		// Get JetEngine taxonomies.
		$taxonomies_method = $reflection->getMethod( 'get_jetengine_taxonomies' );
		$taxonomies_method->setAccessible( true );
		$jetengine_taxonomies = $taxonomies_method->invoke( $integration );

		// Get supported taxonomies.
		$supported_method = $reflection->getMethod( 'get_supported_taxonomies' );
		$supported_method->setAccessible( true );
		$supported_taxonomies = $supported_method->invoke( $integration );

		// If there are JetEngine taxonomies, they should be in supported taxonomies.
		if ( ! empty( $jetengine_taxonomies ) ) {
			$jetengine_taxonomy_slugs = wp_list_pluck( $jetengine_taxonomies, 'slug' );
			foreach ( $jetengine_taxonomy_slugs as $slug ) {
				$this->assertContains( $slug, $supported_taxonomies );
			}
		}
	}

	/**
	 * Test JetEngine settings section displays correctly.
	 */
	public function test_jetengine_settings_section() {
		$section = new WP_MCP_AI_Section_JetEngine_Integration();

		// Test section ID.
		$this->assertEquals( 'integration_jetengine', $section->get_id() );

		// Test section title.
		$this->assertNotEmpty( $section->get_title() );

		// Test section tab.
		$this->assertEquals( 'tools', $section->get_tab() );

		// Test fields.
		$fields = $section->get_fields();
		$this->assertIsArray( $fields );
		$this->assertArrayHasKey( 'jetengine_status', $fields );
	}

	/**
	 * Test Research & Add initializer.
	 */
	public function test_research_add_initializer() {
		// Skip if JetEngine is not active.
		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
			$this->markTestSkipped( 'JetEngine is not active' );
		}

		// Enable Research & Add.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_jetengine_cpt_ai'          => true,
				'enable_jetengine_cpt_research_add' => true,
			)
		);

		// Get initializer instance.
		$initializer = WP_MCP_AI_JetEngine_CPT_Research_Init::get_instance();
		$this->assertInstanceOf( 'WP_MCP_AI_JetEngine_CPT_Research_Init', $initializer );
	}

	/**
	 * Test that meta fields are retrieved correctly for CPTs.
	 */
	public function test_cpt_meta_fields_retrieval() {
		// Skip if JetEngine is not active.
		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) || ! isset( jet_engine()->meta_boxes ) ) {
			$this->markTestSkipped( 'JetEngine or meta_boxes not available' );
		}

		// Get a JetEngine CPT slug (if any).
		$module = jet_engine()->modules->get_module( 'post-type' );
		if ( ! $module || ! $module->instance ) {
			$this->markTestSkipped( 'JetEngine post-type module not available' );
		}

		$post_types = $module->instance->get_items();
		if ( empty( $post_types ) ) {
			$this->markTestSkipped( 'No JetEngine CPTs to test' );
		}

		$test_cpt = reset( $post_types );
		$cpt_slug = $test_cpt['slug'];

		// Try to get meta fields for this CPT.
		$meta_fields = jet_engine()->meta_boxes->get_fields_for_context( 'post_type', $cpt_slug );

		// Should return an array (even if empty).
		$this->assertIsArray( $meta_fields );
	}

	/**
	 * Test that meta fields are retrieved correctly for taxonomies.
	 */
	public function test_taxonomy_meta_fields_retrieval() {
		// Skip if JetEngine is not active.
		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) || ! isset( jet_engine()->meta_boxes ) ) {
			$this->markTestSkipped( 'JetEngine or meta_boxes not available' );
		}

		// Get a JetEngine taxonomy slug (if any).
		$module = jet_engine()->modules->get_module( 'taxonomy' );
		if ( ! $module || ! $module->instance ) {
			$this->markTestSkipped( 'JetEngine taxonomy module not available' );
		}

		$taxonomies = $module->instance->get_items();
		if ( empty( $taxonomies ) ) {
			$this->markTestSkipped( 'No JetEngine taxonomies to test' );
		}

		$test_taxonomy = reset( $taxonomies );
		$taxonomy_slug = $test_taxonomy['slug'];

		// Try to get meta fields for this taxonomy.
		$meta_fields = jet_engine()->meta_boxes->get_fields_for_context( 'taxonomy', $taxonomy_slug );

		// Should return an array (even if empty).
		$this->assertIsArray( $meta_fields );
	}
}
