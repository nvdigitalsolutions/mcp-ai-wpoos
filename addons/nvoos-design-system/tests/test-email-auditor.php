<?php
/**
 * Test: Email Auditor.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for the Email_Auditor class.
 *
 * @covers NV_oOS_Design_System_Email_Auditor
 */
class Test_Email_Auditor extends WP_UnitTestCase {

	/**
	 * Auditor instance.
	 *
	 * @var NV_oOS_Design_System_Email_Auditor
	 */
	private $auditor;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->auditor = new NV_oOS_Design_System_Email_Auditor();
	}

	/**
	 * WCAG contrast ratio math.
	 *
	 * @return void
	 */
	public function test_contrast_ratio_math() {
		$this->assertEqualsWithDelta( 21.0, NV_oOS_Design_System_Email_Auditor::contrast_ratio( '#000000', '#ffffff' ), 0.01 );
		$this->assertEqualsWithDelta( 1.0, NV_oOS_Design_System_Email_Auditor::contrast_ratio( '#ffffff', '#ffffff' ), 0.01 );
		$this->assertNull( NV_oOS_Design_System_Email_Auditor::contrast_ratio( 'var(--x)', '#ffffff' ), 'Non-hex values must be skipped.' );
	}

	/**
	 * Relative luminance of pure white is 1.
	 *
	 * @return void
	 */
	public function test_relative_luminance_white() {
		$this->assertEqualsWithDelta( 1.0, NV_oOS_Design_System_Email_Auditor::relative_luminance( '#ffffff' ), 0.001 );
	}

	/**
	 * Short hex (#rgb) values are supported.
	 *
	 * @return void
	 */
	public function test_relative_luminance_short_hex() {
		$this->assertEqualsWithDelta( 0.0, NV_oOS_Design_System_Email_Auditor::relative_luminance( '#000' ), 0.001 );
	}

	/**
	 * A script tag is a BLOCK-gate error.
	 *
	 * @return void
	 */
	public function test_script_tag_is_error() {
		$audit = $this->auditor->audit( '<!-- nds-email-wrapper:x --><!DOCTYPE html><html lang="en"><body><script>alert(1)</script></body></html>' );

		$this->assertFalse( $audit['passes_required'], 'A script tag must fail the required gates.' );

		$script_result = $this->find_result( $audit, 'code_security_script' );
		$this->assertSame( 'error', $script_result['severity'] );
	}

	/**
	 * A missing doctype is a BLOCK-gate error for full-scope templates.
	 *
	 * @return void
	 */
	public function test_missing_doctype_is_error() {
		$audit = $this->auditor->audit( '<body><p>no doctype</p></body>' );

		$this->assertFalse( $audit['passes_required'] );

		$result = $this->find_result( $audit, 'structure_doctype' );
		$this->assertSame( 'error', $result['severity'] );
	}

	/**
	 * An accessibility-correct template passes required gates.
	 *
	 * @return void
	 */
	public function test_compliant_template_passes() {
		$html  = '<!-- nds-email-wrapper:test --><!DOCTYPE html><html lang="en"><body style="font-size:16px;">';
		$html .= '<h1>Hello</h1>';
		$html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td>';
		$html .= '<img src="https://example.com/logo.png" alt="Logo">';
		$html .= '<p style="font-size:16px;">Body</p>';
		$html .= '</td></tr></table></body></html>';

		$audit = $this->auditor->audit( $html );

		$this->assertTrue( $audit['passes_required'], 'A compliant template must pass the required gates.' );
	}

	/**
	 * Images missing alt text raise a warning.
	 *
	 * @return void
	 */
	public function test_missing_alt_text_warns() {
		$html  = '<!-- nds-email-wrapper:test --><!DOCTYPE html><html lang="en"><body>';
		$html .= '<img src="https://example.com/x.png">';
		$html .= '</body></html>';

		$audit = $this->auditor->audit( $html );

		$result = $this->find_result( $audit, 'a11y_images_alt' );
		$this->assertSame( 'warning', $result['severity'] );
	}

	/**
	 * Missing dark-mode overrides raise a warning.
	 *
	 * @return void
	 */
	public function test_missing_dark_mode_warns() {
		$audit = $this->auditor->audit( '<!-- nds-email-wrapper:test --><!DOCTYPE html><html lang="en"><body><p>x</p></body></html>' );

		$result = $this->find_result( $audit, 'dark_mode' );
		$this->assertSame( 'warning', $result['severity'] );
	}

	/**
	 * The size budget blocks templates over 100 KB.
	 *
	 * @return void
	 */
	public function test_size_budget_blocks_oversized_templates() {
		$html = '<!-- nds-email-wrapper:test --><!DOCTYPE html><html lang="en"><body><p>' . str_repeat( 'a', 103000 ) . '</p></body></html>';

		$audit = $this->auditor->audit( $html );

		$this->assertFalse( $audit['passes_required'], 'Oversized templates must fail the required gates.' );
		$result = $this->find_result( $audit, 'size_budget' );
		$this->assertSame( 'error', $result['severity'] );
	}

	/**
	 * Contrast pairs below 4.5:1 raise a warning.
	 *
	 * @return void
	 */
	public function test_low_contrast_pairs_warn() {
		$audit = $this->auditor->audit(
			'<!-- nds-email-wrapper:test --><!DOCTYPE html><html lang="en"><body><p>x</p></body></html>',
			array( array( '#ffffff', '#ffffff' ) )
		);

		$result = $this->find_result( $audit, 'a11y_contrast' );
		$this->assertSame( 'warning', $result['severity'] );
	}

	/**
	 * Helper: find an audit result by code.
	 *
	 * @param array  $audit Audit payload.
	 * @param string $code  Result code.
	 * @return array{code: string, severity: string, message: string}
	 */
	private function find_result( $audit, $code ) {
		foreach ( $audit['results'] as $result ) {
			if ( $code === $result['code'] ) {
				return $result;
			}
		}

		$this->fail( 'Audit result not found: ' . $code );
	}
}
