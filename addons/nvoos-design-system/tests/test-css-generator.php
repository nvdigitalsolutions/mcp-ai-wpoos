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
	 * Legacy aliases should be emitted only when enabled.
	 *
	 * @return void
	 */
	public function test_legacy_alias_toggle() {
		$with_aliases    = new NV_oOS_Design_System_CSS_Generator( $this->registry, false, true );
		$without_aliases = new NV_oOS_Design_System_CSS_Generator( $this->registry, false, false );

		$css_with    = $with_aliases->generate();
		$css_without = $without_aliases->generate();

		$this->assertStringContainsString( '--cds-color-surface', $css_with );
		$this->assertStringContainsString( 'var(--nds-color-surface)', $css_with, 'Legacy alias should reference the NDS variable.' );
		$this->assertStringNotContainsString( '--cds-color-surface', $css_without );
	}

	/**
	 * Style tag output should wrap the CSS in a <style> element.
	 *
	 * @return void
	 */
	public function test_generate_style_tag() {
		$tag = $this->generator->generate_style_tag();
		$this->assertStringStartsWith( '<style id="nds-tokens">', $tag );
		$this->assertStringEndsWith( '</style>', $tag );
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
