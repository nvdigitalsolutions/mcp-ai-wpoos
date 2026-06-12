<?php
/**
 * Test Pro Integrations Subtab Redirect Fix
 *
 * Verifies that WP_MCP_AI_Section_Pro_Integrations::render_wrapper() only
 * emits a generic 'subtab' hidden field when its own subtab is active in
 * the URL, preventing it from overwriting the correct subtab value set by
 * an earlier-rendered section (e.g. WP_MCP_AI_Section_Tools).
 *
 * Bug: Saving settings on Tools → Connections subtab redirected to Mailjet
 * subtab because Pro Integrations overwrote the generic 'subtab' POST field.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Pro Integrations subtab redirect fix.
 */
class WP_MCP_AI_Pro_Integrations_Subtab_Redirect_Test extends WP_UnitTestCase {

	/**
	 * The Pro Integrations section instance.
	 *
	 * @var WP_MCP_AI_Section_Pro_Integrations
	 */
	private $section;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Skip if Pro addon is not active.
		if ( ! class_exists( 'WP_MCP_AI_Section_Pro_Integrations' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		$this->section = new WP_MCP_AI_Section_Pro_Integrations();

		// Clear any leftover GET parameters.
		unset( $_GET['subtab'] );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		unset( $_GET['subtab'] );
		parent::tearDown();
	}

	/**
	 * Get rendered output of the section wrapper.
	 *
	 * @return string
	 */
	private function get_rendered_output() {
		ob_start();
		$this->section->render_wrapper();
		return ob_get_clean();
	}

	/**
	 * Test that the section-specific hidden field is always emitted.
	 *
	 * The subtab_pro_integrations field is needed so that get_active_subtab()
	 * can determine which subtab is active during POST processing.
	 */
	public function test_section_specific_hidden_field_always_emitted() {
		$output = $this->get_rendered_output();

		$this->assertStringContainsString(
			'name="subtab_pro_integrations"',
			$output,
			'Section-specific subtab_pro_integrations field should always be present'
		);
	}

	/**
	 * Test that generic subtab field is NOT emitted when on connections subtab.
	 *
	 * The connections subtab belongs to WP_MCP_AI_Section_Tools, not to
	 * Pro Integrations. Emitting a generic 'subtab' field here would
	 * overwrite the correct 'connections' value from the Tools section.
	 */
	public function test_generic_subtab_not_emitted_for_connections_subtab() {
		$_GET['subtab'] = 'connections';

		$output = $this->get_rendered_output();

		// The generic subtab field should NOT be present.
		// Use 'name="subtab" ' (with trailing space) to avoid matching
		// the section-specific 'name="subtab_pro_integrations"' field.
		$has_generic = (bool) preg_match( '/name="subtab"\s/', $output );
		$this->assertFalse(
			$has_generic,
			'Generic subtab hidden field should NOT be emitted when on connections subtab'
		);
	}

	/**
	 * Test that generic subtab field IS emitted when on mailjet subtab.
	 *
	 * When the user navigates directly to a Pro Integrations subtab,
	 * the generic 'subtab' field must be emitted so the post-save
	 * redirect lands on the correct subtab.
	 */
	public function test_generic_subtab_emitted_for_mailjet_subtab() {
		$_GET['subtab'] = 'mailjet';

		$output = $this->get_rendered_output();

		$this->assertStringContainsString(
			'name="subtab" value="mailjet"',
			$output,
			'Generic subtab hidden field should be emitted when on mailjet subtab'
		);
	}

	/**
	 * Test that generic subtab field IS emitted when on brevo subtab.
	 */
	public function test_generic_subtab_emitted_for_brevo_subtab() {
		$_GET['subtab'] = 'brevo';

		$output = $this->get_rendered_output();

		$this->assertStringContainsString(
			'name="subtab" value="brevo"',
			$output,
			'Generic subtab hidden field should be emitted when on brevo subtab'
		);
	}

	/**
	 * Test that generic subtab field IS emitted when on mailgun subtab.
	 */
	public function test_generic_subtab_emitted_for_mailgun_subtab() {
		$_GET['subtab'] = 'mailgun';

		$output = $this->get_rendered_output();

		$this->assertStringContainsString(
			'name="subtab" value="mailgun"',
			$output,
			'Generic subtab hidden field should be emitted when on mailgun subtab'
		);
	}

	/**
	 * Test that generic subtab field IS emitted when on analytics subtab.
	 */
	public function test_generic_subtab_emitted_for_analytics_subtab() {
		$_GET['subtab'] = 'analytics';

		$output = $this->get_rendered_output();

		$this->assertStringContainsString(
			'name="subtab" value="analytics"',
			$output,
			'Generic subtab hidden field should be emitted when on analytics subtab'
		);
	}

	/**
	 * Test that generic subtab field is NOT emitted when no subtab is set.
	 *
	 * When visiting the tools tab without any subtab parameter, the Pro
	 * Integrations section should not emit a generic subtab field — the
	 * Tools section handles the default.
	 */
	public function test_generic_subtab_not_emitted_when_no_subtab() {
		$output = $this->get_rendered_output();

		// The generic subtab field should NOT be present.
		$has_generic = (bool) preg_match( '/name="subtab"\s/', $output );
		$this->assertFalse(
			$has_generic,
			'Generic subtab hidden field should NOT be emitted when no subtab is set'
		);
	}

	/**
	 * Test that generic subtab field is NOT emitted for an arbitrary subtab.
	 *
	 * Any subtab value that does not belong to this section should not
	 * trigger the generic subtab field.
	 */
	public function test_generic_subtab_not_emitted_for_features_subtab() {
		$_GET['subtab'] = 'features';

		$output = $this->get_rendered_output();

		$has_generic = (bool) preg_match( '/name="subtab"\s/', $output );
		$this->assertFalse(
			$has_generic,
			'Generic subtab hidden field should NOT be emitted for unrelated subtab'
		);
	}
}
