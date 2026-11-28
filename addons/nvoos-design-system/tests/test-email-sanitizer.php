<?php
/**
 * Test: Email template HTML safety validator.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for NV_oOS_Design_System_Email_Sanitizer.
 *
 * The validator must reject active content while preserving the constructs a
 * real HTML email needs: `<style>` blocks, VML and conditional comments.
 *
 * @covers NV_oOS_Design_System_Email_Sanitizer
 */
class Test_Email_Sanitizer extends WP_UnitTestCase {

	/**
	 * A representative, safe email document.
	 *
	 * @return string
	 */
	private function safe_template() {
		return '<!DOCTYPE html>'
			. '<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>{{subject}}</title>'
			. '<style>@media (prefers-color-scheme: dark) { .card { background-color: #101418 !important; } }</style>'
			. '<!--[if mso]><v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false" style="width:600px;"><v:fill type="frame" color="#10233f" /></v:rect><![endif]-->'
			. '</head><body>'
			. '<table role="presentation" width="100%"><tr><td class="card" style="background-color:#ffffff;">'
			. '<img src="https://example.com/logo.png" alt="Logo" width="120">'
			. '<a href="https://example.com" style="color:#0b5fff;">Visit</a>'
			. '</td></tr></table>'
			. '</body></html>';
	}

	/**
	 * A complete email with a style block and VML passes.
	 *
	 * @return void
	 */
	public function test_safe_template_passes() {
		$this->assertTrue( NV_oOS_Design_System_Email_Sanitizer::validate( $this->safe_template() ) );
		$this->assertTrue( NV_oOS_Design_System_Email_Sanitizer::is_safe( $this->safe_template() ) );
	}

	/**
	 * A plain-string body is acceptable (body-scope templates).
	 *
	 * @return void
	 */
	public function test_body_fragment_passes() {
		$this->assertTrue( NV_oOS_Design_System_Email_Sanitizer::validate( '<p>Hello {{to_name}}, your order shipped.</p>' ) );
	}

	/**
	 * Non-string input is rejected.
	 *
	 * @return void
	 */
	public function test_non_string_is_rejected() {
		$result = NV_oOS_Design_System_Email_Sanitizer::validate( null );

		$this->assertWPError( $result );
		$this->assertSame( 'nds_email_invalid_html', $result->get_error_code() );
	}

	/**
	 * Oversized documents are rejected before pattern scanning.
	 *
	 * @return void
	 */
	public function test_oversized_document_is_rejected() {
		$result = NV_oOS_Design_System_Email_Sanitizer::validate( str_repeat( 'a', NV_oOS_Design_System_Email_Sanitizer::MAX_BYTES + 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'nds_email_html_too_large', $result->get_error_code() );
	}

	/**
	 * Active-content payloads are rejected.
	 *
	 * @dataProvider unsafe_payloads
	 *
	 * @param string $html Unsafe payload.
	 * @return void
	 */
	public function test_unsafe_payloads_are_rejected( $html ) {
		$result = NV_oOS_Design_System_Email_Sanitizer::validate( $html );

		$this->assertWPError( $result );
		$this->assertSame( 'nds_email_unsafe_html', $result->get_error_code() );
		$this->assertFalse( NV_oOS_Design_System_Email_Sanitizer::is_safe( $html ) );
	}

	/**
	 * Unsafe payload fixtures.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unsafe_payloads() {
		return array(
			'script tag'        => array( '<p>hi</p><script>alert(1)</script>' ),
			'script uppercase'  => array( '<SCRIPT>alert(1)</SCRIPT>' ),
			'iframe'            => array( '<iframe src="https://evil.example"></iframe>' ),
			'object'            => array( '<object data="x"></object>' ),
			'embed'             => array( '<embed src="x">' ),
			'form'              => array( '<form action="https://evil.example"><input name="x"></form>' ),
			'base'              => array( '<base href="https://evil.example/">' ),
			'link'              => array( '<link href="https://evil.example/x.css">' ),
			'meta refresh'      => array( '<meta http-equiv="refresh" content="0;url=https://evil.example">' ),
			'inline onclick'    => array( '<a href="#" onclick="steal()">x</a>' ),
			'inline onerror'    => array( '<img src="x" onerror="steal()">' ),
			'javascript href'   => array( '<a href="javascript:alert(1)">x</a>' ),
			'javascript spaced' => array( '<a href=" javascript:alert(1)">x</a>' ),
			'vbscript src'      => array( '<img src="vbscript:msgbox(1)">' ),
			'data html'         => array( '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>' ),
			'css expression'    => array( '<div style="width: expression(alert(1))">x</div>' ),
			'css behavior'      => array( '<div style="behavior: url(x.htc)">x</div>' ),
		);
	}

	/**
	 * `content=` must not be mistaken for an `on*` handler.
	 *
	 * @return void
	 */
	public function test_content_attribute_is_not_an_event_handler() {
		$html = '<meta name="viewport" content="width=device-width, initial-scale=1"><p content="note">x</p>';

		$this->assertTrue( NV_oOS_Design_System_Email_Sanitizer::validate( $html ) );
	}
}
