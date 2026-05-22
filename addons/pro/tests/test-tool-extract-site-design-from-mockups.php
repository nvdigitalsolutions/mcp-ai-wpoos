<?php
/**
 * Tests for the extract_site_design_from_mockups tool, the Design Extractor
 * Service and the Design Snippet Renderer.
 *
 * Covers:
 *  - Renderer pure-function golden checks for each skin variant.
 *  - Token routing from explicit `:root` blocks (the highest-weight source).
 *  - Vision tokens take effect only when no explicit token already wins.
 *  - WCAG contrast validation flags failures and the snippet header is marked DRAFT.
 *  - Tool capability gate returns WP_Error for non-admins.
 *  - dry_run skips persistence even when persist flags are set.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Tool_Extract_Site_Design_From_Mockups
 */
class Test_Tool_Extract_Site_Design_From_Mockups extends WP_UnitTestCase {

	/**
	 * Skip the entire class when the Pro tool isn't loaded.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$base = dirname( __DIR__ ) . '/includes';
		if ( ! class_exists( 'WP_MCP_AI_Design_Snippet_Renderer' ) ) {
			$path = $base . '/site-creator-toolkit/class-wp-mcp-ai-design-snippet-renderer.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
		if ( ! class_exists( 'WP_MCP_AI_Design_Extractor_Service' ) ) {
			$path = $base . '/site-creator-toolkit/class-wp-mcp-ai-design-extractor-service.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups' )
			&& interface_exists( 'WP_MCP_AI_Tool_Interface' )
			&& interface_exists( 'WP_MCP_AI_Tool_Capability_Flags_Interface' ) ) {
			$path = $base . '/tools/site-creator-toolkit/class-wp-mcp-ai-tool-extract-site-design-from-mockups.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Design_Snippet_Renderer' ) ) {
			self::markTestSkipped( 'Renderer not available.' );
		}
	}

	/**
	 * Helper: a stable, fully-populated Design System for golden tests.
	 */
	private function golden_design_system(): array {
		return array(
			'palette'    => array(
				'bg'            => '#0f110e',
				'surface'       => '#181b13',
				'border'        => 'rgba(255,255,255,0.07)',
				'border-accent' => 'rgba(52,107,62,0.35)',
				'accent'        => '#2d6a4f',
				'accent-light'  => '#52b788',
				'accent-pale'   => 'rgba(45,106,79,0.09)',
				'text'          => '#ffffff',
				'dim'           => 'rgba(255,255,255,0.65)',
				'muted'         => 'rgba(255,255,255,0.35)',
				'danger'        => '#d68080',
			),
			'typography' => array(
				'serif'        => 'Playfair Display, Georgia, serif',
				'display'      => 'Cormorant, Georgia, serif',
				'sans'         => 'Tenor Sans, Arial, sans-serif',
				'base_size_px' => 16,
			),
			'radii'      => array(
				'sm' => '8px',
				'md' => '18px',
				'lg' => '34px',
			),
			'shadows'    => array(
				'md' => '0 32px 80px rgba(0,0,0,0.30)',
			),
			'spacing'    => array(
				'scale' => array(
					'sm' => '8px',
					'md' => '16px',
					'lg' => '32px',
				),
			),
			'motion'     => array(
				'easing'      => 'cubic-bezier(.16,1,.3,1)',
				'duration_ms' => 900,
			),
		);
	}

	/**
	 * Renderer emits a complete PHP file with ABSPATH guard, head + footer
	 * actions, and the chosen JFB skin scoped under .jet-form-builder.
	 */
	public function test_render_emits_full_php_file_with_jfb_skin(): void {
		$snippet = WP_MCP_AI_Design_Snippet_Renderer::render(
			$this->golden_design_system(),
			array(
				'features'     => array( 'custom_cursor', 'scroll_reveal', 'header_scroll_state', 'hover_link_underline' ),
				'targets'      => array( 'wordpress', 'elementor', 'jet-form-builder' ),
				'skin_variant' => 'luxury',
				'generated_at' => '2026-01-01T00:00:00+00:00',
				'fingerprint'  => 'abc123def456',
			)
		);

		$this->assertStringStartsWith( "<?php\n", $snippet );
		$this->assertStringContainsString( "if ( ! defined( 'ABSPATH' ) ) {", $snippet );
		$this->assertStringContainsString( "add_action( 'wp_head'", $snippet );
		$this->assertStringContainsString( "add_action( 'wp_footer'", $snippet );
		$this->assertStringContainsString( '<style id="nv-aerlinn-effects-css">', $snippet );
		$this->assertStringContainsString( '<script id="nv-aerlinn-effects-js">', $snippet );
		$this->assertStringContainsString( '<div id="nv-cursor"', $snippet );
		$this->assertStringContainsString( '@media (hover: hover) and (pointer: fine)', $snippet );
		$this->assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $snippet );
		$this->assertStringContainsString( '.jet-form-builder', $snippet );
		$this->assertStringContainsString( '--nv-accent: #2d6a4f', $snippet );
		// JFB selectors must be scoped under .jet-form-builder, never bare.
		$this->assertStringNotContainsString( "\nbody { background:", $snippet );
	}

	/**
	 * The skin_variant chosen drives which CSS variant block appears.
	 */
	public function test_skin_variant_selection(): void {
		$ds = $this->golden_design_system();

		$lux = WP_MCP_AI_Design_Snippet_Renderer::render_jfb_skin_css( 'luxury' );
		$this->assertStringContainsString( 'border-radius: 999px', $lux );
		$this->assertStringContainsString( 'border-radius: 18px', $lux );

		$pan = WP_MCP_AI_Design_Snippet_Renderer::render_jfb_skin_css( 'panel' );
		$this->assertStringContainsString( 'max-width: 720px', $pan );
		$this->assertStringContainsString( 'border-radius: 0 !important', $pan );

		$min = WP_MCP_AI_Design_Snippet_Renderer::render_jfb_skin_css( 'minimal' );
		$this->assertStringContainsString( 'max-width: 640px', $min );

		// auto picker: small radius -> panel, medium -> minimal, large -> luxury.
		$ds['radii']['md'] = '4px';
		$this->assertSame( 'panel', WP_MCP_AI_Design_Snippet_Renderer::pick_skin_variant( $ds, 'auto' ) );
		$ds['radii']['md'] = '12px';
		$this->assertSame( 'minimal', WP_MCP_AI_Design_Snippet_Renderer::pick_skin_variant( $ds, 'auto' ) );
		$ds['radii']['md'] = '34px';
		$this->assertSame( 'luxury', WP_MCP_AI_Design_Snippet_Renderer::pick_skin_variant( $ds, 'auto' ) );
	}

	/**
	 * Targets without 'jet-form-builder' must skip the JFB block entirely.
	 */
	public function test_targets_without_jfb_skip_jfb_block(): void {
		$snippet = WP_MCP_AI_Design_Snippet_Renderer::render(
			$this->golden_design_system(),
			array(
				'features'     => array( 'scroll_reveal' ),
				'targets'      => array( 'wordpress', 'elementor' ),
				'skin_variant' => 'luxury',
				'generated_at' => '2026-01-01T00:00:00+00:00',
				'fingerprint'  => 'aaa',
			)
		);

		$this->assertStringNotContainsString( '/* JFB skin', $snippet );
		$this->assertStringNotContainsString( '.jet-form-builder input', $snippet );
	}

	/**
	 * Renderer header is marked DRAFT when is_draft is true (failed contrast path).
	 */
	public function test_draft_header_marker(): void {
		$snippet = WP_MCP_AI_Design_Snippet_Renderer::render(
			$this->golden_design_system(),
			array(
				'features'     => array( 'scroll_reveal' ),
				'targets'      => array( 'wordpress' ),
				'is_draft'     => true,
				'generated_at' => '2026-01-01T00:00:00+00:00',
				'fingerprint'  => 'def',
			)
		);
		$this->assertStringContainsString( 'STATUS: DRAFT', $snippet );

		$snippet_ok = WP_MCP_AI_Design_Snippet_Renderer::render(
			$this->golden_design_system(),
			array(
				'features'     => array( 'scroll_reveal' ),
				'targets'      => array( 'wordpress' ),
				'is_draft'     => false,
				'generated_at' => '2026-01-01T00:00:00+00:00',
				'fingerprint'  => 'def',
			)
		);
		$this->assertStringContainsString( 'STATUS: READY', $snippet_ok );
	}

	/**
	 * The :root CSS tokenizer extracts custom properties and routes them by role.
	 */
	public function test_parse_css_tokens_extracts_root_custom_properties(): void {
		$css = ':root { --nv-bg: #112233; --nv-accent: #2d6a4f; --nv-radius-md: 18px; --nv-shadow-md: 0 32px 80px rgba(0,0,0,0.3); --nv-easing: cubic-bezier(.16,1,.3,1); }';

		$out = WP_MCP_AI_Design_Extractor_Service::parse_css_tokens( $css );

		$this->assertSame( '#112233', $out['palette']['bg'] );
		$this->assertSame( '#2d6a4f', $out['palette']['accent'] );
		$this->assertSame( '18px', $out['radii']['md'] );
		$this->assertSame( '0 32px 80px rgba(0,0,0,0.3)', $out['shadows']['md'] );
		$this->assertSame( 'cubic-bezier(.16,1,.3,1)', $out['motion']['easing'] );
	}

	/**
	 * Explicit :root tokens beat vision tokens (merge-weight rule).
	 */
	public function test_explicit_root_beats_vision(): void {
		$service = new WP_MCP_AI_Design_Extractor_Service();

		add_filter(
			'wp_mcp_ai_design_extractor_vision',
			function () {
				return array(
					'palette' => array( 'accent' => '#aa0000' ),
				);
			}
		);

		$result = $service->extract(
			array(
				'images'     => array(
					array(
						'media_id' => 1,
						'role'     => 'mockup',
					),
				),
				'html_files' => array(
					array( 'content' => ':root { --nv-accent: #2d6a4f; }' ),
				),
			)
		);

		remove_all_filters( 'wp_mcp_ai_design_extractor_vision' );

		$this->assertSame( '#2d6a4f', $result['design_system']['palette']['accent'], 'Explicit token must win over vision.' );
		$this->assertArrayHasKey( 'palette.accent', $result['_provenance'] );
		$this->assertStringStartsWith( 'explicit:', $result['_provenance']['palette.accent'] );
	}

	/**
	 * WCAG contrast: a known-bad palette (yellow accent on white bg)
	 * fails the non-text 3:1 floor and marks the result as draft.
	 */
	public function test_contrast_validation_flags_low_contrast_palette(): void {
		$service = new WP_MCP_AI_Design_Extractor_Service();

		$result = $service->extract(
			array(
				'html_files' => array(
					array(
						'content' => ':root { --nv-bg: #ffffff; --nv-text: #ffffff; --nv-accent: #ffff00; }',
					),
				),
			)
		);

		$this->assertTrue( $result['is_draft'], 'White-on-white text must trigger draft.' );
		$this->assertNotEmpty( $result['contrast_report'] );
		$found_text_failure = false;
		foreach ( $result['contrast_report'] as $row ) {
			if ( 'text on bg' === $row['pair'] && empty( $row['wcag_aa'] ) ) {
				$found_text_failure = true;
			}
		}
		$this->assertTrue( $found_text_failure, 'Expected text-on-bg failure row.' );
		$this->assertNotEmpty( $result['warnings'] );
	}

	/**
	 * Contrast ratio math: WCAG guarantees pure-black on pure-white = 21.
	 */
	public function test_contrast_ratio_math(): void {
		$ratio = WP_MCP_AI_Design_Extractor_Service::contrast_ratio( '#000000', '#ffffff' );
		$this->assertEqualsWithDelta( 21.0, $ratio, 0.01 );

		$ratio_same = WP_MCP_AI_Design_Extractor_Service::contrast_ratio( '#777777', '#777777' );
		$this->assertEqualsWithDelta( 1.0, $ratio_same, 0.01 );
	}

	/**
	 * Tool gate: non-admin user must get back a forbidden WP_Error.
	 */
	public function test_tool_capability_gate_blocks_non_admins(): void {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups' ) ) {
			$this->markTestSkipped( 'Tool class not loadable in this test env.' );
		}

		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$tool   = new WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups();
		$result = $tool->execute( array(), array( 'user_id' => $subscriber ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Tool gate: settings checkbox must be enabled or the tool refuses to run
	 * even for admin users.
	 */
	public function test_tool_settings_gate_blocks_when_disabled(): void {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups' ) ) {
			$this->markTestSkipped( 'Tool class not loadable in this test env.' );
		}

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		// Enable toolkit but NOT the extractor sub-feature.
		update_option( 'wp_mcp_ai_settings', array( 'enable_site_creator_toolkit' => 1 ) );

		$tool   = new WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups();
		$result = $tool->execute( array(), array( 'user_id' => $admin ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'design_extractor_disabled', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Happy-path: with both gates open, the tool returns a snippet, fingerprint,
	 * features, and (with dry_run) does not persist.
	 */
	public function test_tool_dry_run_returns_snippet_without_persistence(): void {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups' ) ) {
			$this->markTestSkipped( 'Tool class not loadable in this test env.' );
		}

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_site_creator_toolkit' => 1,
				'enable_design_extractor'     => 1,
			)
		);

		$tool   = new WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups();
		$result = $tool->execute(
			array(
				'inputs'       => array(
					'html_files' => array(
						array( 'content' => ':root { --nv-bg: #0f110e; --nv-text: #ffffff; --nv-accent: #2d6a4f; }' ),
					),
					'brief'      => 'Luxury serene hospitality brand.',
				),
				'features'     => array( 'scroll_reveal', 'header_scroll_state' ),
				'targets'      => array( 'wordpress', 'elementor', 'jet-form-builder' ),
				'skin_variant' => 'luxury',
				'output'       => array(
					'persist_as_wpcode'        => true,
					'persist_as_site_template' => true,
				),
				'dry_run'      => true,
			),
			array( 'user_id' => $admin )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['snippet'] );
		$this->assertNotEmpty( $result['fingerprint'] );
		$this->assertSame( 'luxury', $result['skin_variant'] );
		$this->assertArrayHasKey( 'dry_run', $result['persisted'] );
		$this->assertArrayNotHasKey( 'wpcode_snippet_id', $result['persisted'] );
		$this->assertArrayNotHasKey( 'site_template_post_id', $result['persisted'] );

		// Snippet must contain the example-1 cursor IIFE marker (when feature enabled).
		// (This run does NOT enable custom_cursor; ensure absence too.).
		$this->assertStringNotContainsString( '<div id="nv-cursor"', $result['snippet'] );
		$this->assertStringContainsString( "add_action( 'wp_head'", $result['snippet'] );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
