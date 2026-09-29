<?php
/**
 * Test: Email Wrapper.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for the Email_Wrapper class.
 *
 * @covers NV_oOS_Design_System_Email_Wrapper
 */
class Test_Email_Wrapper extends WP_UnitTestCase {

	/**
	 * Clean up filters left behind by wrapper tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'nds_email_skip' );
		remove_all_filters( 'nds_email_context' );
		remove_filter( 'wp_mail_content_type', array( 'NV_oOS_Design_System_Email_Wrapper', 'force_html_content_type_once' ), 99 );
	}

	/**
	 * Messages already carrying the sentinel are never double-wrapped.
	 *
	 * @return void
	 */
	public function test_sentinel_guard_prevents_double_wrap() {
		$message = '<!-- nds-email-wrapper:letterhead --><!DOCTYPE html><html><body><p>Already wrapped</p></body></html>';
		$atts    = array(
			'to'      => 'test@example.com',
			'subject' => 'Test',
			'message' => $message,
		);

		$result = NV_oOS_Design_System_Email_Wrapper::wrap( $atts );

		$this->assertSame( $message, $result['message'], 'Sentinel-carrying messages must pass through untouched.' );
	}

	/**
	 * Complete HTML documents from other plugins (WooCommerce, SMTP
	 * templating plugins) are never wrapped.
	 *
	 * @return void
	 */
	public function test_full_document_guard_skips() {
		$message = '<!DOCTYPE html><html><head><title>x</title></head><body><p>WooCommerce email</p></body></html>';
		$atts    = array(
			'to'      => 'test@example.com',
			'subject' => 'Test',
			'message' => $message,
		);

		$result = NV_oOS_Design_System_Email_Wrapper::wrap( $atts );

		$this->assertSame( $message, $result['message'], 'Full-document emails must not be wrapped.' );
	}

	/**
	 * Explicit text/plain sends are never wrapped.
	 *
	 * @return void
	 */
	public function test_text_plain_headers_skip() {
		$message = "Plain text message\nLine two";
		$atts    = array(
			'to'      => 'test@example.com',
			'subject' => 'Test',
			'message' => $message,
			'headers' => array( 'Content-Type: text/plain; charset=UTF-8' ),
		);

		$result = NV_oOS_Design_System_Email_Wrapper::wrap( $atts );

		$this->assertSame( $message, $result['message'], 'text/plain sends must not be wrapped.' );
	}

	/**
	 * The nds_email_skip filter short-circuits wrapping.
	 *
	 * @return void
	 */
	public function test_skip_filter_short_circuits() {
		add_filter( 'nds_email_skip', '__return_true' );

		$message = 'Hello plain body';
		$atts    = array(
			'to'      => 'test@example.com',
			'subject' => 'Test',
			'message' => $message,
		);

		$result = NV_oOS_Design_System_Email_Wrapper::wrap( $atts );

		$this->assertSame( $message, $result['message'] );
	}

	/**
	 * Empty messages pass through untouched.
	 *
	 * @return void
	 */
	public function test_empty_message_passes_through() {
		$atts = array(
			'to'      => 'test@example.com',
			'subject' => 'Test',
			'message' => '',
		);

		$result = NV_oOS_Design_System_Email_Wrapper::wrap( $atts );

		$this->assertSame( '', $result['message'] );
	}

	/**
	 * A plain-text body is wrapped in the active template.
	 *
	 * The built-in templates do not render a {{to_name}} greeting line, so
	 * the recipient-name extraction is asserted on the merge context the
	 * wrapper builds (the same context templates receive).
	 *
	 * @return void
	 */
	public function test_plain_body_is_wrapped() {
		$captured = null;
		$capture  = function ( $context ) use ( &$captured ) {
			$captured = $context;
			return $context;
		};
		add_filter( 'nds_email_context', $capture );

		$atts = array(
			'to'      => 'Test User <test@example.com>',
			'subject' => 'Hello subject',
			'message' => 'Hello there, this is the body.',
		);

		$result = NV_oOS_Design_System_Email_Wrapper::wrap( $atts );

		$this->assertStringContainsString( '<!-- nds-email-wrapper:', $result['message'] );
		$this->assertStringContainsString( '<!DOCTYPE html>', $result['message'] );
		$this->assertStringContainsString( 'Hello there, this is the body.', $result['message'] );
		$this->assertIsArray( $captured, 'The merge context should be passed through the nds_email_context filter.' );
		$this->assertSame( 'Test User', $captured['to_name'], 'Recipient name should be extracted from the To header.' );
		$this->assertStringContainsString( 'Hello subject', $result['message'] );

		// The one-shot content-type filter must be registered for this send.
		$this->assertTrue(
			has_filter( 'wp_mail_content_type', array( 'NV_oOS_Design_System_Email_Wrapper', 'force_html_content_type_once' ) ) !== false
		);
	}

	/**
	 * The one-shot content-type filter returns text/html and removes itself.
	 *
	 * @return void
	 */
	public function test_content_type_filter_is_one_shot() {
		add_filter( 'wp_mail_content_type', array( 'NV_oOS_Design_System_Email_Wrapper', 'force_html_content_type_once' ), 99 );

		$type = apply_filters( 'wp_mail_content_type', 'text/plain' );

		$this->assertSame( 'text/html', $type );
		$this->assertFalse(
			has_filter( 'wp_mail_content_type', array( 'NV_oOS_Design_System_Email_Wrapper', 'force_html_content_type_once' ) ),
			'The filter must remove itself after one use so later emails are unaffected.'
		);
	}

	/**
	 * Recipient names are extracted from all supported shapes.
	 *
	 * @return void
	 */
	public function test_get_recipient_name_shapes() {
		$this->assertSame( 'Test User', NV_oOS_Design_System_Email_Wrapper::get_recipient_name( 'Test User <test@example.com>' ) );
		$this->assertSame( '', NV_oOS_Design_System_Email_Wrapper::get_recipient_name( array() ) );
		$this->assertSame( '', NV_oOS_Design_System_Email_Wrapper::get_recipient_name( 'not-an-email' ) );
	}

	/**
	 * Non-array arguments pass through untouched.
	 *
	 * @return void
	 */
	public function test_non_array_passes_through() {
		$this->assertSame( 'string', NV_oOS_Design_System_Email_Wrapper::wrap( 'string' ) );
	}
}
