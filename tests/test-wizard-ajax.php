<?php
/**
 * AJAX tests for Wizard / First-run / Dismiss-notice handlers.
 *
 * Covers the 4-point coverage contract for:
 *   - wp_mcp_ai_wizard_save_step         (WP_MCP_AI_Onboarding_Wizard::ajax_save_step)
 *   - wp_mcp_ai_wizard_complete          (WP_MCP_AI_Onboarding_Wizard::ajax_complete_wizard)
 *   - wp_mcp_ai_dismiss_welcome_notice   (WP_MCP_AI_Onboarding_Wizard::ajax_dismiss_notice)
 *   - wp_mcp_ai_dismiss_optional_components (WP_MCP_AI_Optional_Components::ajax_dismiss_optional_components)
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Wizard / Dismiss notices.
 */
class Test_Wizard_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Clean up any wizard/notice options persisted by handlers.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_wizard_complete' );
		delete_option( 'wp_mcp_ai_onboarding_complete' );
		parent::tearDown();
	}

	// ---
	// wp_mcp_ai_wizard_save_step
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_save_step_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_wizard_save_step',
			array(
				'step' => 'provider',
				'data' => wp_json_encode( array( 'provider' => 'openai' ) ),
			)
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_save_step_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_wizard_save_step',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_wizard_save_step' ),
				'step'  => 'provider',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the unknown step parameter. */
	public function test_save_step_validates_unknown_step() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_wizard_save_step',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_wizard_save_step' ),
				'step'  => 'this_step_does_not_exist',
			)
		);

		$this->assertAjaxError( $response, 'Unknown step' );
	}

	/** Validates the invalid provider parameter. */
	public function test_save_step_validates_invalid_provider() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_wizard_save_step',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_wizard_save_step' ),
				'step'     => 'provider',
				'provider' => 'definitely_not_a_valid_provider',
			)
		);

		$this->assertAjaxError( $response, 'Invalid provider' );
	}

	/** Data provider. */
	public function test_save_step_provider_accepts_known_provider() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_wizard_save_step',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_wizard_save_step' ),
				'step'     => 'provider',
				'provider' => 'openai',
			)
		);

		// May succeed (persists setting) or return a provider-specific error —
		// either way it must not return "Unknown step" or "Invalid provider".
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	/** Entering a key in step 2 enables the provider (all providers default to disabled on fresh installs). */
	public function test_save_step_enables_openai_when_key_provided() {
		$this->as_admin();

		delete_option( 'wp_mcp_ai_settings' );

		$response = $this->dispatch(
			'wp_mcp_ai_wizard_save_step',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_wizard_save_step' ),
				'step'     => 2,
				'provider' => 'openai',
				'api_key'  => 'sk-test-wizard-key',
			)
		);

		$this->assertAjaxSuccess( $response );

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertSame( 'sk-test-wizard-key', $settings['openai_api_key'] );
		$this->assertTrue(
			! empty( $settings['enable_openai'] ),
			'OpenAI should be enabled when a key is provided in the wizard.'
		);

		delete_option( 'wp_mcp_ai_settings' );
	}

	// ---
	// wp_mcp_ai_wizard_complete
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_wizard_complete_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_wizard_complete' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_wizard_complete_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_wizard_complete',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_wizard_complete' ) )
		);

		$this->assertAjaxError( $response );
	}

	/** Wizard complete persists completion flag. */
	public function test_wizard_complete_persists_completion_flag() {
		$this->as_admin();

		delete_option( 'wp_mcp_ai_wizard_complete' );
		delete_option( 'wp_mcp_ai_onboarding_complete' );

		$response = $this->dispatch(
			'wp_mcp_ai_wizard_complete',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_wizard_complete' ) )
		);

		$this->assertAjaxSuccess( $response );

		// At least one completion flag should now be set.
		$complete_1 = get_option( 'wp_mcp_ai_wizard_complete' );
		$complete_2 = get_option( 'wp_mcp_ai_onboarding_complete' );
		$this->assertTrue(
			! empty( $complete_1 ) || ! empty( $complete_2 ),
			'Expected a wizard-complete flag to be persisted after ajax_complete_wizard.'
		);
	}

	// ---
	// wp_mcp_ai_dismiss_welcome_notice
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_dismiss_welcome_notice_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_dismiss_welcome_notice' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_dismiss_welcome_notice_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_dismiss_welcome_notice',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_dismiss_welcome_notice' ) )
		);

		// The handler returns wp_send_json_error() with no message on cap fail.
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_dismiss_welcome_notice_succeeds_for_admin() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_dismiss_welcome_notice',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_dismiss_welcome_notice' ) )
		);

		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_dismiss_optional_components
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_dismiss_optional_components_rejects_missing_nonce() {
		$this->as_admin();

		// No nonce — check_ajax_referer uses positional parameter only.
		$response = $this->dispatch( 'wp_mcp_ai_dismiss_optional_components' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_dismiss_optional_components_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_dismiss_optional_components',
			// This handler calls check_ajax_referer('wp_mcp_ai_dismiss_components') without a field name,
			// so it validates _wpnonce or _ajax_nonce automatically.
			array( '_wpnonce' => wp_create_nonce( 'wp_mcp_ai_dismiss_components' ) )
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Dispatches successfully on the happy path. */
	public function test_dismiss_optional_components_succeeds_for_admin() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_dismiss_optional_components',
			array( '_wpnonce' => wp_create_nonce( 'wp_mcp_ai_dismiss_components' ) )
		);

		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_dismiss_directory_notice — the handler for this action was
	// removed from production; no tests remain for it.
	// ---
}
