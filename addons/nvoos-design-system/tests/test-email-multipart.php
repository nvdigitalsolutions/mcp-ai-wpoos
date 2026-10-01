<?php
/**
 * Test: Email Wrapper — Multipart plain-text pairing.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for the plain-text pairing pipeline (PHPMailer AltBody).
 *
 * @covers NV_oOS_Design_System_Email_Wrapper
 */
class Test_Email_Multipart extends WP_UnitTestCase {

	/**
	 * Clean up hooks and pending state between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'nds_email_plain_text' );
		remove_action( 'phpmailer_init', array( 'NV_oOS_Design_System_Email_Wrapper', 'attach_alt_body' ), 20 );
	}

	/**
	 * A minimal PHPMailer stand-in.
	 *
	 * @param string $content_type Content type.
	 * @param string $alt_body     Existing AltBody.
	 * @return object
	 */
	private function mock_phpmailer( $content_type = 'text/html', $alt_body = '' ) {
		$mailer               = new stdClass();
		$mailer->ContentType  = $content_type;
		$mailer->AltBody      = $alt_body;
		return $mailer;
	}

	/**
	 * The conversion pipeline strips style blocks and maps structure to newlines.
	 *
	 * @return void
	 */
	public function test_plain_text_from_html_pipeline() {
		$html  = '<style>body{color:#000}</style><h1>Hello</h1>';
		$html .= '<p>First paragraph.<br>Second line.</p>';
		$html .= '<table><tr><td>Cell A</td><td>Cell B</td></tr></table>';
		$html .= '<p>Tom &amp; Jerry &mdash; finale.</p>';

		$text = NV_oOS_Design_System_Email_Wrapper::plain_text_from_html( $html );

		$this->assertStringNotContainsString( 'body{color', $text, 'Style blocks must be removed.' );
		$this->assertStringContainsString( "First paragraph.\nSecond line.", $text, '<br> and </p> should become newlines.' );
		$this->assertStringContainsString( "Cell A\tCell B", $text, '</td> should become a tab separator.' );
		$this->assertStringContainsString( 'Tom & Jerry — finale.', $text, 'Entities should be decoded.' );
		$this->assertStringNotContainsString( '<h1>', $text );
	}

	/**
	 * Multiple blank lines are collapsed to at most one.
	 *
	 * @return void
	 */
	public function test_plain_text_collapses_blank_lines() {
		$html = '<p>A</p><p></p><p></p><p></p><p>B</p>';
		$text = NV_oOS_Design_System_Email_Wrapper::plain_text_from_html( $html );

		$this->assertStringNotContainsString( "\n\n\n", $text, 'Runs of blank lines must be collapsed.' );
	}

	/**
	 * The one-shot phpmailer_init callback attaches AltBody for HTML sends.
	 *
	 * @return void
	 */
	public function test_attach_alt_body_sets_alt_body_for_html() {
		// Simulate the pending state wrap() would create.
		$reflection = new ReflectionProperty( 'NV_oOS_Design_System_Email_Wrapper', 'pending_alt_body' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, "Plain version\nLine two" );

		$mailer = $this->mock_phpmailer( 'text/html', '' );
		NV_oOS_Design_System_Email_Wrapper::attach_alt_body( $mailer );

		$this->assertSame( "Plain version\nLine two", $mailer->AltBody );
	}

	/**
	 * The AltBody must never be attached to non-HTML sends.
	 *
	 * @return void
	 */
	public function test_attach_alt_body_skips_non_html() {
		$reflection = new ReflectionProperty( 'NV_oOS_Design_System_Email_Wrapper', 'pending_alt_body' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, 'Plain version' );

		$mailer = $this->mock_phpmailer( 'text/plain', '' );
		NV_oOS_Design_System_Email_Wrapper::attach_alt_body( $mailer );

		$this->assertSame( '', $mailer->AltBody, 'text/plain sends must not receive an AltBody.' );
	}

	/**
	 * A pre-existing AltBody from another plugin is never overwritten.
	 *
	 * @return void
	 */
	public function test_attach_alt_body_respects_existing_alt_body() {
		$reflection = new ReflectionProperty( 'NV_oOS_Design_System_Email_Wrapper', 'pending_alt_body' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, 'Our version' );

		$mailer = $this->mock_phpmailer( 'text/html', 'Existing version' );
		NV_oOS_Design_System_Email_Wrapper::attach_alt_body( $mailer );

		$this->assertSame( 'Existing version', $mailer->AltBody );
	}

	/**
	 * The callback consumes the pending slot (one-shot behaviour).
	 *
	 * @return void
	 */
	public function test_attach_alt_body_is_one_shot() {
		$reflection = new ReflectionProperty( 'NV_oOS_Design_System_Email_Wrapper', 'pending_alt_body' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, 'Once only' );

		$mailer = $this->mock_phpmailer( 'text/html', '' );
		NV_oOS_Design_System_Email_Wrapper::attach_alt_body( $mailer );
		NV_oOS_Design_System_Email_Wrapper::attach_alt_body( $this->mock_phpmailer( 'text/html', '' ) );

		$remaining = $reflection->getValue();
		$this->assertNull( $remaining, 'The pending slot must be consumed on first use.' );
	}

	/**
	 * The nds_email_plain_text filter can override the plain-text part for a
	 * wrapped send.
	 *
	 * @return void
	 */
	public function test_plain_text_filter_overrides() {
		add_filter(
			'nds_email_plain_text',
			function ( $plain, $atts ) {
				return 'FILTERED-' . $plain;
			},
			10,
			2
		);

		$atts = array(
			'to'      => 'test@example.com',
			'subject' => 'Multipart test',
			'message' => 'Plain body for multipart test.',
		);

		NV_oOS_Design_System_Email_Wrapper::wrap( $atts );

		$reflection = new ReflectionProperty( 'NV_oOS_Design_System_Email_Wrapper', 'pending_alt_body' );
		$reflection->setAccessible( true );
		$pending = $reflection->getValue();

		$this->assertNotNull( $pending, 'wrap() should stage a plain-text part.' );
		$this->assertStringStartsWith( 'FILTERED-', $pending, 'The filter should be applied.' );

		// Consume so later tests start clean.
		NV_oOS_Design_System_Email_Wrapper::attach_alt_body( $this->mock_phpmailer( 'text/html', '' ) );
	}
}
