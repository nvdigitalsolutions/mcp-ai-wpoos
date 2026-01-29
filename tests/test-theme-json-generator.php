<?php
/**
 * Tests for Theme JSON Generator
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test Theme JSON Generator Helper
 */
class Test_Theme_JSON_Generator extends WP_UnitTestCase {

	/**
	 * Test basic theme.json generation.
	 */
	public function test_basic_generation() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate();

		// Check required fields.
		$this->assertIsArray( $theme_json );
		$this->assertArrayHasKey( '$schema', $theme_json );
		$this->assertArrayHasKey( 'version', $theme_json );
		$this->assertArrayHasKey( 'settings', $theme_json );
		$this->assertArrayHasKey( 'styles', $theme_json );

		// Check schema version.
		$this->assertEquals( 2, $theme_json['version'] );
		$this->assertEquals( 'https://schemas.wp.org/trunk/theme.json', $theme_json['$schema'] );
	}

	/**
	 * Test settings section generation.
	 */
	public function test_settings_generation() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate();
		$settings   = $theme_json['settings'];

		// Check key settings sections.
		$this->assertArrayHasKey( 'color', $settings );
		$this->assertArrayHasKey( 'typography', $settings );
		$this->assertArrayHasKey( 'spacing', $settings );
		$this->assertArrayHasKey( 'layout', $settings );
		$this->assertArrayHasKey( 'border', $settings );
		$this->assertArrayHasKey( 'dimensions', $settings );
		$this->assertArrayHasKey( 'shadow', $settings );

		// Check color palette structure.
		$this->assertArrayHasKey( 'palette', $settings['color'] );
		$this->assertIsArray( $settings['color']['palette'] );
		$this->assertNotEmpty( $settings['color']['palette'] );

		// Check typography structure.
		$this->assertArrayHasKey( 'fontFamilies', $settings['typography'] );
		$this->assertArrayHasKey( 'fontSizes', $settings['typography'] );
		$this->assertTrue( $settings['typography']['fluid'] );
	}

	/**
	 * Test styles section generation.
	 */
	public function test_styles_generation() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate();
		$styles     = $theme_json['styles'];

		// Check basic styles.
		$this->assertArrayHasKey( 'color', $styles );
		$this->assertArrayHasKey( 'typography', $styles );
		$this->assertArrayHasKey( 'spacing', $styles );

		// Check element styles.
		$this->assertArrayHasKey( 'elements', $styles );
		$this->assertArrayHasKey( 'link', $styles['elements'] );
		$this->assertArrayHasKey( 'heading', $styles['elements'] );
		$this->assertArrayHasKey( 'button', $styles['elements'] );

		// Check block-specific styles.
		$this->assertArrayHasKey( 'blocks', $styles );
		$this->assertArrayHasKey( 'core/paragraph', $styles['blocks'] );
		$this->assertArrayHasKey( 'core/heading', $styles['blocks'] );
		$this->assertArrayHasKey( 'core/image', $styles['blocks'] );
	}

	/**
	 * Test custom templates generation.
	 */
	public function test_custom_templates() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$custom_templates = array(
			array(
				'name'       => 'custom-about',
				'title'      => 'About Template',
				'post_types' => array( 'page' ),
			),
		);

		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate(
			array(
				'custom_templates' => $custom_templates,
			)
		);

		$this->assertArrayHasKey( 'customTemplates', $theme_json );
		$this->assertIsArray( $theme_json['customTemplates'] );
		$this->assertCount( 1, $theme_json['customTemplates'] );
		$this->assertEquals( 'custom-about', $theme_json['customTemplates'][0]['name'] );
	}

	/**
	 * Test template parts generation.
	 */
	public function test_template_parts() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$template_parts = array(
			array(
				'name'  => 'header',
				'title' => 'Header',
				'area'  => 'header',
			),
		);

		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate(
			array(
				'template_parts' => $template_parts,
			)
		);

		$this->assertArrayHasKey( 'templateParts', $theme_json );
		$this->assertIsArray( $theme_json['templateParts'] );
		$this->assertGreaterThanOrEqual( 1, count( $theme_json['templateParts'] ) );
	}

	/**
	 * Test industry-specific color palettes.
	 */
	public function test_industry_color_palettes() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$industries = array( 'technology', 'healthcare', 'finance', 'ecommerce' );

		foreach ( $industries as $industry ) {
			$palette = WP_MCP_AI_Theme_JSON_Generator::get_industry_color_palette( $industry );

			$this->assertIsArray( $palette );
			$this->assertNotEmpty( $palette );

			// Check palette structure.
			foreach ( $palette as $color ) {
				$this->assertArrayHasKey( 'name', $color );
				$this->assertArrayHasKey( 'slug', $color );
				$this->assertArrayHasKey( 'color', $color );
			}
		}
	}

	/**
	 * Test theme.json validation.
	 */
	public function test_validation() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		// Valid theme.json.
		$valid_json = array(
			'version'  => 2,
			'settings' => array(),
			'styles'   => array(),
		);

		$result = WP_MCP_AI_Theme_JSON_Generator::validate( $valid_json );
		$this->assertTrue( $result );

		// Missing version.
		$invalid_json = array(
			'settings' => array(),
		);

		$result = WP_MCP_AI_Theme_JSON_Generator::validate( $invalid_json );
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_version', $result->get_error_code() );

		// Invalid version.
		$invalid_json = array(
			'version' => 'not-an-integer',
		);

		$result = WP_MCP_AI_Theme_JSON_Generator::validate( $invalid_json );
		$this->assertWPError( $result );
	}

	/**
	 * Test JSON conversion.
	 */
	public function test_json_conversion() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate();
		$json       = WP_MCP_AI_Theme_JSON_Generator::to_json( $theme_json, true );

		$this->assertIsString( $json );
		$this->assertNotEmpty( $json );

		// Verify it's valid JSON.
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertEquals( 2, $decoded['version'] );
	}

	/**
	 * Test default color palette.
	 */
	public function test_default_color_palette() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate();
		$palette    = $theme_json['settings']['color']['palette'];

		// Check for semantic colors.
		$slugs = wp_list_pluck( $palette, 'slug' );
		$this->assertContains( 'base', $slugs );
		$this->assertContains( 'contrast', $slugs );
		$this->assertContains( 'primary', $slugs );
		$this->assertContains( 'primary-hover', $slugs );
	}

	/**
	 * Test fluid typography.
	 */
	public function test_fluid_typography() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate();
		$font_sizes = $theme_json['settings']['typography']['fontSizes'];

		// Check for fluid sizing.
		$has_fluid = false;
		foreach ( $font_sizes as $size ) {
			if ( isset( $size['fluid'] ) && is_array( $size['fluid'] ) ) {
				$has_fluid = true;
				$this->assertArrayHasKey( 'min', $size['fluid'] );
				$this->assertArrayHasKey( 'max', $size['fluid'] );
			}
		}

		$this->assertTrue( $has_fluid, 'At least one font size should have fluid settings.' );
	}

	/**
	 * Test spacing scale generation.
	 */
	public function test_spacing_scale() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$theme_json    = WP_MCP_AI_Theme_JSON_Generator::generate();
		$spacing_scale = $theme_json['settings']['spacing']['spacingScale'];

		$this->assertIsArray( $spacing_scale );
		$this->assertArrayHasKey( 'operator', $spacing_scale );
		$this->assertArrayHasKey( 'increment', $spacing_scale );
		$this->assertArrayHasKey( 'steps', $spacing_scale );
		$this->assertArrayHasKey( 'unit', $spacing_scale );
	}

	/**
	 * Test shadow presets.
	 */
	public function test_shadow_presets() {
		require_once dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/helpers/class-wp-mcp-ai-theme-json-generator.php';

		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate();
		$shadows    = $theme_json['settings']['shadow']['presets'];

		$this->assertIsArray( $shadows );
		$this->assertNotEmpty( $shadows );

		// Check shadow structure.
		foreach ( $shadows as $shadow ) {
			$this->assertArrayHasKey( 'name', $shadow );
			$this->assertArrayHasKey( 'slug', $shadow );
			$this->assertArrayHasKey( 'shadow', $shadow );
		}
	}
}
