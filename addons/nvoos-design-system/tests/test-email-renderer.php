<?php
/**
 * Test: Email Renderer.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for the Email_Renderer class.
 *
 * @covers NV_oOS_Design_System_Email_Renderer
 */
class Test_Email_Renderer extends WP_UnitTestCase {

	/**
	 * Renderer instance.
	 *
	 * @var NV_oOS_Design_System_Email_Renderer
	 */
	private $renderer;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new NV_oOS_Design_System_Email_Renderer();
	}

	/**
	 * Scalar merge tags are substituted with escaped values.
	 *
	 * @return void
	 */
	public function test_merge_tags_are_substituted() {
		$template = '<html lang="en"><body><p>Hello {{to_name}} from {{site_name}}</p><p>{{subject}}</p><p>{{body}}</p></body></html>';

		$html = $this->renderer->render(
			$template,
			array(
				'to_name'   => 'Alice <b>Bold</b>',
				'site_name' => 'ACME & Co',
				'subject'   => 'Subject <script>alert(1)</script>',
				'body'      => '<p>Body content</p>',
			)
		);

		$this->assertStringContainsString( 'Alice &lt;b&gt;Bold&lt;/b&gt;', $html, 'to_name must be escaped.' );
		$this->assertStringContainsString( 'ACME &amp; Co', $html, 'site_name must be escaped.' );
		$this->assertStringContainsString( '&lt;script&gt;', $html, 'subject must be escaped.' );
		$this->assertStringContainsString( '<p>Body content</p>', $html, 'body is pre-sanitised HTML and inserted raw.' );
	}

	/**
	 * Merge-tag-looking text inside the body is never re-processed.
	 *
	 * @return void
	 */
	public function test_body_merge_tags_are_not_reprocessed() {
		$template = '<html><body>{{body}}</body></html>';

		$html = $this->renderer->render(
			$template,
			array(
				'body' => 'Template syntax example: {{site_name}} stays literal.',
			)
		);

		$this->assertStringContainsString( '{{site_name}} stays literal', $html, 'Merge tags inside the body must not be substituted.' );
	}

	/**
	 * Email tokens are resolved to concrete values (no CSS variables in
	 * the output, since email clients do not support them).
	 *
	 * @return void
	 */
	public function test_token_references_are_resolved() {
		$template = '<html><body><table bgcolor="var(--nds-email-page-bg)"><tr><td>{{body}}</td></tr></table></body></html>';

		$html = $this->renderer->render( $template, array( 'body' => 'x' ) );

		$this->assertStringNotContainsString( 'var(--nds-email-page-bg)', $html );
		$this->assertStringContainsString( '#f0ede8', $html, 'The email page background token should be inlined.' );
	}

	/**
	 * The button part renders a bulletproof CTA.
	 *
	 * @return void
	 */
	public function test_button_part_renders_bulletproof_cta() {
		$template = '<html><body>{{button "Shop Now" "https://example.com/shop"}}{{body}}</body></html>';

		$html = $this->renderer->render( $template, array( 'body' => '' ) );

		$this->assertStringContainsString( 'role="presentation"', $html );
		$this->assertStringContainsString( 'Shop Now', $html );
		$this->assertStringContainsString( 'https://example.com/shop', $html );
		$this->assertStringContainsString( 'v:roundrect', $html, 'The VML fallback for Outlook should be present.' );
		$this->assertStringNotContainsString( '{{button', $html );
	}

	/**
	 * {{#to_name}} conditional blocks render only when a recipient name is
	 * known, so greetings never emit "Dear ,".
	 *
	 * @return void
	 */
	public function test_to_name_conditional_block() {
		$template = '<html><body>{{#to_name}}<p>Dear {{to_name}},</p>{{/to_name}}{{body}}</body></html>';

		$with_name = $this->renderer->render(
			$template,
			array(
				'to_name' => 'Test User',
				'body'    => 'Body',
			)
		);

		$this->assertStringContainsString( 'Dear Test User,', $with_name );
		$this->assertStringNotContainsString( '{{#to_name}}', $with_name );
		$this->assertStringNotContainsString( '{{/to_name}}', $with_name );

		$without_name = $this->renderer->render(
			$template,
			array( 'body' => 'Body' )
		);

		$this->assertStringNotContainsString( 'Dear', $without_name, 'Empty recipient names must not render a greeting.' );
		$this->assertStringContainsString( 'Body', $without_name );
	}

	/**
	 * The sentinel is injected when missing.
	 *
	 * @return void
	 */
	public function test_sentinel_is_injected_when_missing() {
		$html = $this->renderer->render( '<html><body>{{body}}</body></html>', array( 'body' => 'x' ), 'my-template' );

		$this->assertStringContainsString( '<!-- nds-email-wrapper:my-template -->', $html );
	}

	/**
	 * Body-scope content is wrapped in a complete skeleton.
	 *
	 * @return void
	 */
	public function test_body_skeleton_wraps_content() {
		$html = $this->renderer->render_body_skeleton( '<p>Fragment</p>', 'Subject', 'custom' );

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'role="presentation"', $html );
		$this->assertStringContainsString( '<p>Fragment</p>', $html );
		$this->assertStringContainsString( 'prefers-color-scheme', $html, 'The skeleton ships dark-mode overrides.' );
		$this->assertStringContainsString( '<!-- nds-email-wrapper:custom -->', $html );
	}
}
