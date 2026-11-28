<?php
/**
 * Test: CSS Generator.
 *
 * @package NV_oOS_Design_System
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the CSS_Generator class.
 *
 * @covers NV_oOS_Design_System_CSS_Generator
 */
class Test_CSS_Generator extends TestCase {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Design_System_Token_Registry
	 */
	private $registry;

	/**
	 * CSS generator instance.
	 *
	 * @var NV_oOS_Design_System_CSS_Generator
	 */
	private $generator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry  = new NV_oOS_Design_System_Token_Registry();
		$this->generator = new NV_oOS_Design_System_CSS_Generator( $this->registry, false, false );
	}

	/**
	 * Generated output should be a :root CSS block.
	 *
	 * @return void
	 */
	public function test_generate_returns_css_block() {
		$css = $this->generator->generate();
		$this->assertStringStartsWith( ':root{', $css );
	}

	/**
	 * Generated CSS should contain a specific token variable.
	 *
	 * @return void
	 */
	public function test_generate_contains_token() {
		$css = $this->generator->generate();
		$this->assertStringContainsString( '--nds-color-surface', $css );
		$this->assertStringContainsString( '#1a1a1a', $css );
	}

	/**
	 * Generated CSS should include the email token group.
	 *
	 * @return void
	 */
	public function test_generate_contains_email_tokens() {
		$css = $this->generator->generate();
		$this->assertStringContainsString( '--nds-email-page-bg', $css );
		$this->assertStringContainsString( '--nds-email-card-bg-dark', $css );
	}

	/**
	 * Legacy `--cds-*` aliases were removed in 1.0.1. The constructor still
	 * accepts the flag for back-compat, but it must not emit the variables.
	 *
	 * @return void
	 */
	public function test_legacy_aliases_are_not_emitted() {
		$with_flag    = new NV_oOS_Design_System_CSS_Generator( $this->registry, false, true );
		$without_flag = new NV_oOS_Design_System_CSS_Generator( $this->registry, false, false );

		$css_with    = $with_flag->generate();
		$css_without = $without_flag->generate();

		$this->assertStringNotContainsString( '--cds-color-surface', $css_with, 'Legacy aliases were removed in 1.0.1 and must not be reintroduced.' );
		$this->assertStringNotContainsString( '--cds-color-surface', $css_without );
		$this->assertSame( $css_without, $css_with, 'The legacy flag must not change the output.' );
	}

	/**
	 * `generate()` emits raw CSS only — no `<style>` wrapper. Front-end output
	 * goes through `wp_add_inline_style()` so the WordPress.org guidelines on
	 * enqueued resources are respected.
	 *
	 * @return void
	 */
	public function test_generate_returns_raw_css_without_style_tag() {
		$css = $this->generator->generate();

		$this->assertStringContainsString( ':root{', $css );
		$this->assertStringNotContainsString( '<style', $css );
		$this->assertStringNotContainsString( '</style>', $css );
	}

	/**
	 * The front-end token CSS is attached to an enqueued handle.
	 *
	 * @return void
	 */
	public function test_frontend_tokens_are_enqueued_inline() {
		wp_set_current_user( 0 );

		NV_oOS_Design_System_Plugin::enqueue_frontend_styles();

		ob_start();
		wp_print_styles( 'nvoos-nds-tokens' );
		$printed = ob_get_clean();

		$this->assertStringContainsString( '--nds-color-accent', $printed, 'Token custom properties must be printed through the enqueued handle.' );
		$this->assertStringNotContainsString( '<style id="nds-tokens">', $printed, 'The plugin must not print a hand-rolled style tag.' );
	}

	/**
	 * Generated CSS should reflect token value changes.
	 *
	 * @return void
	 */
	public function test_generate_reflects_changed_tokens() {
		$this->registry->set( 'color_surface', '#ff0000' );

		$css = $this->generator->generate();
		$this->assertStringContainsString( '#ff0000', $css );
		$this->assertStringNotContainsString( '#1a1a1a', $css, 'Old default should not appear after change.' );
	}
}
