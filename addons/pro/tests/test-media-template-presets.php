<?php
/**
 * Tests for Media Template Presets.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Media Template Presets functionality.
 */
class Test_Media_Template_Presets extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the Media Template Presets class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_Presets' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-media-template-presets.php';
		}

		// Ensure Media Template CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-media-template-cpt.php';
		}

		// Register the post type and taxonomy for testing.
		WP_MCP_AI_Media_Template_CPT::register_post_type();
		WP_MCP_AI_Media_Template_CPT::register_taxonomy();

		// Enable media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);

		// Reset seeded version to allow re-seeding.
		delete_option( WP_MCP_AI_Media_Template_Presets::SEEDED_VERSION_KEY );

		// Clean up any existing preset templates.
		$existing = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		foreach ( $existing as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( WP_MCP_AI_Media_Template_Presets::SEEDED_VERSION_KEY );

		parent::tearDown();
	}

	/**
	 * Test that preset definitions are available.
	 */
	public function test_get_presets() {
		$presets = WP_MCP_AI_Media_Template_Presets::get_presets();

		$this->assertIsArray( $presets );
		$this->assertGreaterThan( 10, count( $presets ) );

		// Check some expected presets exist.
		$this->assertArrayHasKey( 'instagram_square', $presets );
		$this->assertArrayHasKey( 'facebook_cover', $presets );
		$this->assertArrayHasKey( 'product_thumbnail', $presets );
		$this->assertArrayHasKey( 'logo_watermark', $presets );
		$this->assertArrayHasKey( 'blog_featured_image', $presets );
	}

	/**
	 * Test that preset categories are available.
	 */
	public function test_get_preset_categories() {
		$categories = WP_MCP_AI_Media_Template_Presets::get_preset_categories();

		$this->assertIsArray( $categories );
		$this->assertArrayHasKey( 'social-media', $categories );
		$this->assertArrayHasKey( 'e-commerce', $categories );
		$this->assertArrayHasKey( 'branding', $categories );
		$this->assertArrayHasKey( 'content', $categories );
		$this->assertArrayHasKey( 'marketing', $categories );
	}

	/**
	 * Test seeding preset templates.
	 */
	public function test_seed_presets() {
		// Seed presets.
		WP_MCP_AI_Media_Template_Presets::seed_presets();

		// Check that templates were created.
		$templates = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
				'numberposts' => -1,
			)
		);

		$this->assertGreaterThan( 10, count( $templates ) );

		// Check seeded version was set.
		$seeded_version = get_option( WP_MCP_AI_Media_Template_Presets::SEEDED_VERSION_KEY );
		$this->assertEquals( WP_MCP_AI_Media_Template_Presets::PRESET_VERSION, $seeded_version );
	}

	/**
	 * Test seeding only happens once per version.
	 */
	public function test_seed_presets_once_per_version() {
		// First seed.
		WP_MCP_AI_Media_Template_Presets::seed_presets();
		$count_first = wp_count_posts( WP_MCP_AI_Media_Template_CPT::POST_TYPE )->publish;

		// Second seed (should not create duplicates).
		WP_MCP_AI_Media_Template_Presets::seed_presets();
		$count_second = wp_count_posts( WP_MCP_AI_Media_Template_CPT::POST_TYPE )->publish;

		$this->assertEquals( $count_first, $count_second );
	}

	/**
	 * Test preset template has correct metadata.
	 */
	public function test_preset_template_metadata() {
		WP_MCP_AI_Media_Template_Presets::seed_presets();

		// Get Instagram Square preset.
		$preset = WP_MCP_AI_Media_Template_Presets::get_preset_by_slug( 'instagram_square' );

		$this->assertInstanceOf( 'WP_Post', $preset );
		$this->assertEquals( 'Instagram Square Post', $preset->post_title );

		// Check operation meta.
		$operation = get_post_meta( $preset->ID, '_mcp_ai_template_operation', true );
		$this->assertEquals( 'resize_graphic', $operation );

		// Check parameters meta.
		$parameters = get_post_meta( $preset->ID, '_mcp_ai_template_parameters', true );
		$params     = json_decode( $parameters, true );
		$this->assertIsArray( $params );
		$this->assertEquals( 1080, $params['target_width'] );
		$this->assertEquals( 1080, $params['target_height'] );

		// Check preset meta.
		$is_preset = get_post_meta( $preset->ID, '_mcp_ai_template_is_preset', true );
		$this->assertTrue( (bool) $is_preset );

		$preset_id = get_post_meta( $preset->ID, '_mcp_ai_template_preset_id', true );
		$this->assertEquals( 'instagram_square', $preset_id );
	}

	/**
	 * Test preset is assigned correct category.
	 */
	public function test_preset_template_category() {
		WP_MCP_AI_Media_Template_Presets::seed_presets();

		// Get Instagram Square preset.
		$preset = WP_MCP_AI_Media_Template_Presets::get_preset_by_slug( 'instagram_square' );

		// Check category.
		$terms = wp_get_object_terms( $preset->ID, WP_MCP_AI_Media_Template_CPT::TAXONOMY_CATEGORY );
		$this->assertNotEmpty( $terms );
		$this->assertEquals( 'social-media', $terms[0]->slug );
	}

	/**
	 * Test is_preset method.
	 */
	public function test_is_preset() {
		WP_MCP_AI_Media_Template_Presets::seed_presets();

		// Get preset template.
		$preset = WP_MCP_AI_Media_Template_Presets::get_preset_by_slug( 'instagram_square' );
		$this->assertTrue( WP_MCP_AI_Media_Template_Presets::is_preset( $preset->ID ) );

		// Create a non-preset template.
		$custom_template = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
				'post_title'  => 'Custom Template',
				'post_status' => 'publish',
			)
		);
		$this->assertFalse( WP_MCP_AI_Media_Template_Presets::is_preset( $custom_template ) );
	}

	/**
	 * Test seeding doesn't happen when media toolkit is disabled.
	 */
	public function test_seed_presets_disabled_toolkit() {
		// Disable media toolkit.
		update_option( 'wp_mcp_ai_settings', array() );

		// Try to seed.
		WP_MCP_AI_Media_Template_Presets::seed_presets();

		// Check that no templates were created.
		$templates = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
				'numberposts' => -1,
			)
		);

		$this->assertEmpty( $templates );

		// Check seeded version was not set.
		$seeded_version = get_option( WP_MCP_AI_Media_Template_Presets::SEEDED_VERSION_KEY );
		$this->assertEmpty( $seeded_version );
	}
}
