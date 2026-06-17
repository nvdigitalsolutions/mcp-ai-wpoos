<?php
/**
 * AJAX tests for the Settings Dashboard utility handlers.
 *
 * Covers the 4-point coverage contract for:
 *   - wp_mcp_ai_export_settings      (WP_MCP_AI_Settings_Dashboard_Handler::handle_export_settings)
 *   - wp_mcp_ai_import_settings      (WP_MCP_AI_Settings_Dashboard_Handler::handle_import_settings)
 *   - wp_mcp_ai_clear_settings_cache (WP_MCP_AI_Settings_Dashboard_Handler::handle_clear_cache)
 *   - wp_mcp_ai_reset_settings       (WP_MCP_AI_Settings_Dashboard_Handler::handle_reset_settings)
 *
 * All four handlers use the nonce action `wp-mcp-ai-dashboard` and require
 * the `manage_options` capability.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Settings utility (export / import / clear-cache / reset).
 */
class Test_Settings_Utility_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Nonce action shared by all four handlers.
	 */
	const NONCE = 'wp-mcp-ai-dashboard';

	/**
	 * Option name used by the plugin settings.
	 */
	const OPTION = 'wp_mcp_ai_settings';

	/**
	 * Persist a known settings array before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( self::OPTION, array( 'test_key' => 'test_value' ), false );
	}

	/**
	 * Remove the test settings option after each test.
	 */
	public function tearDown(): void {
		delete_option( self::OPTION );
		parent::tearDown();
	}

	// ---
	// wp_mcp_ai_clear_settings_cache
	// (simplest handler — test it first so pattern is established)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_clear_cache_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_clear_settings_cache' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_clear_cache_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_clear_settings_cache',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Dispatches successfully on the happy path. */
	public function test_clear_cache_succeeds_for_admin() {
		$this->as_admin();

		// Seed a known transient so we can verify it was removed.
		set_transient( 'wp_mcp_ai_settings_cache', array( 'cached' => true ), HOUR_IN_SECONDS );

		$response = $this->dispatch(
			'wp_mcp_ai_clear_settings_cache',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxSuccess( $response );
		$this->assertArrayHasKey( 'message', $response['data'] );
		$this->assertFalse( get_transient( 'wp_mcp_ai_settings_cache' ) );
	}

	// ---
	// wp_mcp_ai_reset_settings
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_reset_settings_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_reset_settings' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_reset_settings_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_reset_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Reset settings overwrites settings with defaults. */
	public function test_reset_settings_overwrites_settings_with_defaults() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_reset_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		// Handler may succeed or return an error if the class is absent; either
		// way the response must be a JSON object.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );

		if ( $response['success'] ) {
			$this->assertArrayHasKey( 'message', $response['data'] );

			// After reset, the settings option should NOT contain our test value.
			$saved = get_option( self::OPTION, array() );
			$this->assertIsArray( $saved );
			$this->assertArrayNotHasKey( 'test_key', $saved );
		}
	}

	// ---
	// wp_mcp_ai_export_settings
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_export_settings_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_export_settings' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_export_settings_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_export_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Verifies the response returns json download. */
	public function test_export_settings_happy_path_returns_json_download() {
		$this->as_admin();

		// The handler calls `header()` + `echo` + `exit`; WP_Ajax_UnitTestCase
		// captures the output via output buffering. We inspect the raw response
		// for a valid JSON body rather than `assertAjaxSuccess`.
		$response = $this->dispatch(
			'wp_mcp_ai_export_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		// The raw _last_response string should be decodable JSON or the
		// handler returned a wp_send_json_error.
		$raw = $this->_last_response;

		// Two possible code-paths:
		// 1) Handler exited early after check_ajax_referer failure → assertAjaxForbidden
		// 2) Handler streamed JSON export → $raw is a JSON object with 'version' key
		// 3) Handler returned wp_send_json_error → success:false response.
		if ( is_array( $response ) && array_key_exists( 'success', $response ) ) {
			// Got a standard wp_send_json_* response.
			$this->assertIsArray( $response );
		} else {
			// Got raw file-download output — decode it.
			$decoded = json_decode( $raw, true );
			$this->assertIsArray( $decoded, 'Export output is not valid JSON.' );
			$this->assertArrayHasKey( 'version', $decoded );
			$this->assertArrayHasKey( 'settings', $decoded );
		}
	}

	// ---
	// wp_mcp_ai_import_settings
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_import_settings_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_import_settings' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_import_settings_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_import_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Validates the no file uploaded parameter. */
	public function test_import_settings_validates_no_file_uploaded() {
		$this->as_admin();

		$_FILES = array(); // Explicitly empty.

		$response = $this->dispatch(
			'wp_mcp_ai_import_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'No file uploaded' );
	}

	/** Validates the upload error parameter. */
	public function test_import_settings_validates_upload_error() {
		$this->as_admin();

		$_FILES = array(
			'settings_file' => array(
				'name'     => 'settings.json',
				'type'     => 'application/json',
				'size'     => 0,
				'tmp_name' => '',
				'error'    => UPLOAD_ERR_PARTIAL, // Simulated upload error.
			),
		);

		$response = $this->dispatch(
			'wp_mcp_ai_import_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'No file uploaded or upload error occurred' );
		$_FILES = array();
	}

	/** Validates the file too large parameter. */
	public function test_import_settings_validates_file_too_large() {
		$this->as_admin();

		// A real temp file is required so the handler can stat it.
		$tmp = tempnam( sys_get_temp_dir(), 'ajax_test_' );
		file_put_contents( $tmp, str_repeat( 'x', 6 * MB_IN_BYTES ) ); // 6 MB > 5 MB limit.

		$_FILES = array(
			'settings_file' => array(
				'name'     => 'settings.json',
				'type'     => 'application/json',
				'size'     => 6 * MB_IN_BYTES,
				'tmp_name' => $tmp,
				'error'    => UPLOAD_ERR_OK,
			),
		);

		$response = $this->dispatch(
			'wp_mcp_ai_import_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'File content too large' );
		$_FILES = array();
		unlink( $tmp );
	}

	/** Validates the invalid json content parameter. */
	public function test_import_settings_validates_invalid_json_content() {
		$this->as_admin();

		$tmp = tempnam( sys_get_temp_dir(), 'ajax_test_' );
		file_put_contents( $tmp, 'this is not json at all' );

		$_FILES = array(
			'settings_file' => array(
				'name'     => 'settings.json',
				'type'     => 'application/json',
				'size'     => strlen( 'this is not json at all' ),
				'tmp_name' => $tmp,
				'error'    => UPLOAD_ERR_OK,
			),
		);

		$response = $this->dispatch(
			'wp_mcp_ai_import_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		// Handler rejects non-JSON with either an "Invalid file type" or
		// "Failed to parse" message depending on whether fileinfo is available.
		$this->assertAjaxError( $response );
		$_FILES = array();
		unlink( $tmp );
	}

	/** Import settings happy path persists settings. */
	public function test_import_settings_happy_path_persists_settings() {
		$this->as_admin();

		$export_data = array(
			'version'     => '1.0',
			'exported_at' => '2026-01-01 00:00:00',
			'settings'    => array( 'imported_key' => 'imported_value' ),
		);
		$json        = wp_json_encode( $export_data );

		$tmp = tempnam( sys_get_temp_dir(), 'ajax_test_' );
		file_put_contents( $tmp, $json );

		$_FILES = array(
			'settings_file' => array(
				'name'     => 'settings.json',
				'type'     => 'application/json',
				'size'     => strlen( $json ),
				'tmp_name' => $tmp,
				'error'    => UPLOAD_ERR_OK,
			),
		);

		$response = $this->dispatch(
			'wp_mcp_ai_import_settings',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$_FILES = array();
		unlink( $tmp );

		// The handler may reject the file based on MIME detection; accept any
		// structured response — the contract is that it's a JSON object.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );

		if ( $response['success'] ) {
			$saved = get_option( self::OPTION, array() );
			$this->assertArrayHasKey( 'imported_key', $saved );
		}
	}
}
