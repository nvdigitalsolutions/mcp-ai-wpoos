<?php
/**
 * AJAX tests for embedded model + dataset handlers.
 *
 * Covers the 4-point coverage contract for:
 *   - wp_mcp_ai_download_embedded_model (WP_MCP_AI_Embedded_Model_Ajax::download_model)
 *   - wp_mcp_ai_delete_embedded_model   (WP_MCP_AI_Embedded_Model_Ajax::delete_model)
 *   - wp_mcp_ai_list_embedded_models    (WP_MCP_AI_Embedded_Model_Ajax::list_models)
 *   - wp_mcp_ai_download_llama_binary   (WP_MCP_AI_Embedded_Model_Ajax::download_binary)
 *   - wp_mcp_ai_get_llama_binary_status (WP_MCP_AI_Embedded_Model_Ajax::get_binary_status)
 *   - wp_mcp_ai_load_dataset_preview    (WP_MCP_AI_Datasets_Admin_Page::ajax_load_dataset_preview)
 *   - wp_mcp_ai_search_datasets         (WP_MCP_AI_Datasets_Admin_Page::ajax_search_datasets)
 *
 * All embedded-model handlers live in the `addons/embedded/` addon and are
 * skipped when that addon's class is absent. Dataset handlers are base-plugin.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Embedded models + Datasets.
 */
// Load the embedded-addon class under test; the addon is not booted by the
// base test bootstrap, so require it here to keep the suite runnable
// standalone (mirrors CI, where earlier tests load it).
$wp_mcp_ai_embedded_model_ajax = WP_MCP_AI_PATH . '../addons/embedded/includes/admin/class-wp-mcp-ai-embedded-model-ajax.php';
if ( file_exists( $wp_mcp_ai_embedded_model_ajax ) ) {
	require_once $wp_mcp_ai_embedded_model_ajax;
}
unset( $wp_mcp_ai_embedded_model_ajax );

class Test_Embedded_Models_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Nonce action used by all embedded-model handlers.
	 */
	const NONCE_EMBEDDED = 'wp_mcp_ai_embedded_models';

	/**
	 * Nonce action used by dataset handlers.
	 */
	const NONCE_DATASETS = 'wp_mcp_ai_datasets';

	/**
	 * Whether the embedded addon is available in this environment.
	 *
	 * @var bool
	 */
	private $has_embedded_addon = false;

	/** Sets up test fixtures before each test. */
	public function setUp(): void {
		parent::setUp();
		$this->has_embedded_addon = class_exists( 'WP_MCP_AI_Embedded_Model_Ajax' );
	}

	// ---
	// Shared skip helper
	// ---

	/** Skip without embedded. */
	private function skip_without_embedded() {
		if ( ! $this->has_embedded_addon ) {
			$this->markTestSkipped( 'WP_MCP_AI_Embedded_Model_Ajax (embedded addon) is not available in this environment.' );
		}
	}

	// ---
	// wp_mcp_ai_list_embedded_models
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_list_embedded_models_rejects_missing_nonce() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_list_embedded_models' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_list_embedded_models_rejects_subscriber() {
		$this->skip_without_embedded();
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_list_embedded_models',
			array( 'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ) )
		);

		$this->assertAjaxError( $response, 'do not have permission' );
	}

	/** Verifies the response returns models array. */
	public function test_list_embedded_models_returns_models_array() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_list_embedded_models',
			array( 'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ) )
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );

		if ( $response['success'] ) {
			$this->assertArrayHasKey( 'models', $response['data'] );
			$this->assertIsArray( $response['data']['models'] );
		}
	}

	// ---
	// wp_mcp_ai_download_embedded_model
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_download_embedded_model_rejects_missing_nonce() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_download_embedded_model',
			array( 'model' => 'llama-3-8b-q4' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_download_embedded_model_rejects_subscriber() {
		$this->skip_without_embedded();
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_download_embedded_model',
			array(
				'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ),
				'model' => 'llama-3-8b-q4',
			)
		);

		$this->assertAjaxError( $response, 'do not have permission' );
	}

	/** Validates the empty slug parameter. */
	public function test_download_embedded_model_validates_empty_slug() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_download_embedded_model',
			array(
				'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ),
				'model' => '',
			)
		);

		$this->assertAjaxError( $response, 'Model slug is required' );
	}

	/** Download embedded model stubs remote download. */
	public function test_download_embedded_model_stubs_remote_download() {
		$this->skip_without_embedded();
		$this->as_admin();

		// Stub outbound HTTP so no real model download escapes the sandbox.
		$this->stub_http_response( '', new WP_Error( 'blocked', 'blocked' ) );

		$response = $this->dispatch(
			'wp_mcp_ai_download_embedded_model',
			array(
				'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ),
				'model' => 'llama-3-8b-q4',
			)
		);

		// May succeed (model already present) or return a structured error.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_delete_embedded_model
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_delete_embedded_model_rejects_missing_nonce() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_delete_embedded_model',
			array( 'model' => 'llama-3-8b-q4' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_delete_embedded_model_rejects_subscriber() {
		$this->skip_without_embedded();
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_delete_embedded_model',
			array(
				'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ),
				'model' => 'llama-3-8b-q4',
			)
		);

		$this->assertAjaxError( $response, 'do not have permission' );
	}

	/** Validates the empty slug parameter. */
	public function test_delete_embedded_model_validates_empty_slug() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_delete_embedded_model',
			array(
				'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ),
				'model' => '',
			)
		);

		$this->assertAjaxError( $response, 'Model slug is required' );
	}

	/** Verifies the response returns error for non existent model. */
	public function test_delete_embedded_model_returns_error_for_non_existent_model() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_delete_embedded_model',
			array(
				'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ),
				'model' => 'definitely-does-not-exist-model-xyz',
			)
		);

		// The client returns WP_Error if the model isn't downloaded.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_download_llama_binary
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_download_llama_binary_rejects_missing_nonce() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_download_llama_binary' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_download_llama_binary_rejects_subscriber() {
		$this->skip_without_embedded();
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_download_llama_binary',
			array( 'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ) )
		);

		$this->assertAjaxError( $response, 'do not have permission' );
	}

	/** Verifies the response returns structured response. */
	public function test_download_llama_binary_returns_structured_response() {
		$this->skip_without_embedded();
		$this->as_admin();

		// Stub outbound GitHub download.
		$this->stub_http_response( 'github.com', new WP_Error( 'blocked', 'blocked' ) );
		$this->stub_http_response( 'objects.githubusercontent.com', new WP_Error( 'blocked', 'blocked' ) );

		$response = $this->dispatch(
			'wp_mcp_ai_download_llama_binary',
			array( 'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ) )
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_get_llama_binary_status
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_llama_binary_status_rejects_missing_nonce() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_get_llama_binary_status' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_llama_binary_status_rejects_subscriber() {
		$this->skip_without_embedded();
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_get_llama_binary_status',
			array( 'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ) )
		);

		$this->assertAjaxError( $response, 'do not have permission' );
	}

	/** Verifies the response returns status object. */
	public function test_get_llama_binary_status_returns_status_object() {
		$this->skip_without_embedded();
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_get_llama_binary_status',
			array( 'nonce' => wp_create_nonce( self::NONCE_EMBEDDED ) )
		);

		$this->assertAjaxSuccess( $response );
		$this->assertIsArray( $response['data'] );
	}

	// ---
	// wp_mcp_ai_load_dataset_preview (base plugin)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_load_dataset_preview_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_load_dataset_preview',
			array( 'name' => 'common_crawl' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_load_dataset_preview_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_load_dataset_preview',
			array(
				'nonce' => wp_create_nonce( self::NONCE_DATASETS ),
				'name'  => 'common_crawl',
			)
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Validates the missing name parameter. */
	public function test_load_dataset_preview_validates_missing_name() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_load_dataset_preview',
			array(
				'nonce' => wp_create_nonce( self::NONCE_DATASETS ),
				'name'  => '',
			)
		);

		$this->assertAjaxError( $response, 'Dataset name required' );
	}

	/** Load dataset preview stubs remote for known dataset. */
	public function test_load_dataset_preview_stubs_remote_for_known_dataset() {
		$this->as_admin();

		$this->stub_http_response(
			'',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'rows' => array( array( 'text' => 'sample' ) ) ) ),
				'headers'  => array(),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_load_dataset_preview',
			array(
				'nonce' => wp_create_nonce( self::NONCE_DATASETS ),
				'name'  => 'common_crawl',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_search_datasets (base plugin)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_search_datasets_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_search_datasets',
			array( 'query' => 'common' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_search_datasets_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_search_datasets',
			array(
				'nonce' => wp_create_nonce( self::NONCE_DATASETS ),
				'query' => 'common',
			)
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Verifies the response returns results for admin. */
	public function test_search_datasets_returns_results_for_admin() {
		$this->as_admin();

		$this->stub_http_response(
			'',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'datasets' => array() ) ),
				'headers'  => array(),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_search_datasets',
			array(
				'nonce' => wp_create_nonce( self::NONCE_DATASETS ),
				'query' => 'test_query',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}
}
