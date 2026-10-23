<?php
/**
 * Tests the schema-driven settings surface (2.4.0, Proposal 084 Phase 2):
 * GET /mcp-ai/v1/settings/schema and GET/POST /mcp-ai/v1/settings/values.
 *
 * Covers: the schema catalogue over registered sections, masked secret
 * reads, per-type sanitized writes (checkbox/number/select/text), the
 * masked-placeholder keep-existing contract, and unknown-key rejection.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Settings_Schema_Test extends WP_UnitTestCase {

	/**
	 * Boot-time section instances captured in set_up and restored in
	 * tear_down. The registry is a process-wide static, so tearing down the
	 * test sections without restoring the originals would leave every
	 * downstream suite (settings saves, provider subtabs, documentation
	 * links) with an empty registry — the 26-failure full-suite cascade
	 * caught by the CI run of 2026-10-21.
	 *
	 * @var array<string, WP_MCP_AI_Settings_Section|null>
	 */
	private static $previous_sections = array();

	/**
	 * Register two representative sections so the schema has content, and
	 * start from a clean settings option.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_General' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-general.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_Providers' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-providers.php';
		}

		// Snapshot the boot-time instances before overwriting them, so
		// tear_down can put the registry back exactly as it found it.
		self::$previous_sections['general']   = WP_MCP_AI_Settings_Registry::get_section( 'general' );
		self::$previous_sections['providers'] = WP_MCP_AI_Settings_Registry::get_section( 'providers' );

		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_General() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Providers() );

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}

	/**
	 * Drop the test sections + option, then restore the boot-time sections.
	 */
	public function tear_down() {
		WP_MCP_AI_Settings_Registry::unregister_section( 'general' );
		WP_MCP_AI_Settings_Registry::unregister_section( 'providers' );
		foreach ( self::$previous_sections as $section ) {
			if ( $section instanceof WP_MCP_AI_Settings_Section ) {
				WP_MCP_AI_Settings_Registry::register_section( $section );
			}
		}
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		parent::tear_down();
	}

	/**
	 * The schema exposes the registered sections with supported field types.
	 */
	public function test_schema_exposes_sections_and_supported_fields() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_REST_Settings_Controller() )->get_schema();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'tabs', $data['data'] );
		$this->assertNotEmpty( $data['data']['fields'] );

		$keys = wp_list_pluck( $data['data']['fields'], 'key' );
		$this->assertContains( 'default_provider', $keys, 'General section fields must appear in the schema.' );
		$this->assertContains( 'enable_logging', $keys, 'General section fields must appear in the schema.' );

		$by_key = array();
		foreach ( $data['data']['fields'] as $field ) {
			$by_key[ $field['key'] ] = $field;
			$this->assertContains( $field['type'], WP_MCP_AI_REST_Settings_Controller::SUPPORTED_FIELD_TYPES );
		}
		$this->assertSame( 'select', $by_key['default_provider']['type'] );
		$this->assertSame( 'checkbox', $by_key['enable_logging']['type'] );

		$has_password = false;
		foreach ( $data['data']['fields'] as $field ) {
			if ( 'password' === $field['type'] ) {
				$has_password = true;
				$this->assertTrue( $field['sensitive'] );
			}
		}
		$this->assertTrue( $has_password, 'Providers section must expose password-typed (sensitive) fields.' );
	}

	/**
	 * Values reads mask secrets and coerce checkboxes.
	 */
	public function test_values_mask_secrets_and_coerce_checkboxes() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'openai_api_key' => 'sk-secret-123',
				'enable_logging' => '1',
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$response = ( new WP_MCP_AI_REST_Settings_Controller() )->get_values();
		$values   = $response->get_data()['data']['values'];

		$this->assertNotSame( 'sk-secret-123', $values['openai_api_key'] );
		$this->assertSame( '**************', $values['openai_api_key'] );
		$this->assertTrue( $values['enable_logging'] );
	}

	/**
	 * Writes sanitize per type, ignore unknown keys, and never clobber a
	 * masked secret. The select value is derived from the schema's own
	 * options (the provider list is environment-dependent).
	 */
	public function test_update_sanitizes_and_keeps_masked_secrets() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'openai_api_key' => 'sk-existing' )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Derive a valid select value from the schema — never hardcode a
		// provider slug that the environment's dynamic list may not offer.
		$schema   = ( new WP_MCP_AI_REST_Settings_Controller() )->get_schema()->get_data();
		$provider = 'openai';
		foreach ( $schema['data']['fields'] as $field ) {
			if ( 'default_provider' === $field['key'] && ! empty( $field['options'] ) ) {
				$provider = (string) array_key_first( $field['options'] );
				break;
			}
		}

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/settings/values' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'enable_logging'   => true,
					'default_provider' => $provider,
					'openai_api_key'   => '**************',
					'not_a_setting'    => 'ignored',
				)
			)
		);

		( new WP_MCP_AI_REST_Settings_Controller() )->update_values( $request );

		$stored = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertTrue( (bool) $stored['enable_logging'] );
		$this->assertSame( $provider, $stored['default_provider'] );
		$this->assertSame( 'sk-existing', $stored['openai_api_key'] );
		$this->assertArrayNotHasKey( 'not_a_setting', $stored );
	}

	/**
	 * Out-of-enum select values are rejected back to the stored value.
	 */
	public function test_invalid_select_value_keeps_current() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'default_provider' => 'gemini' )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/settings/values' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'default_provider' => 'not-a-provider' ) ) );

		( new WP_MCP_AI_REST_Settings_Controller() )->update_values( $request );

		$stored = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertSame( 'gemini', $stored['default_provider'] );
	}
}
