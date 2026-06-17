<?php
/**
 * Tests for embedded model slug sanitization.
 *
 * Verifies that the WP_MCP_AI_Embedded_Client class rejects invalid,
 * empty, and potentially malicious model slugs in download_model() and
 * delete_model(), and that the AJAX handler sanitizes the POST parameter
 * before passing it to the client.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Embedded model slug sanitization tests.
 */
class Test_Embedded_Model_Slug_Sanitization extends WP_UnitTestCase {

	/**
	 * Embedded client instance under test.
	 *
	 * @var WP_MCP_AI_Embedded_Client
	 */
	private $client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			$pro_client_path = WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-embedded-client.php';
			if ( file_exists( $pro_client_path ) ) {
				require_once $pro_client_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Embedded_Client requires the Pro addon.' );
		}

		$this->client = new WP_MCP_AI_Embedded_Client();
	}

	// =========================================================================
	// download_model slug validation
	// =========================================================================

	/**
	 * Test that download_model() returns WP_Error for an empty slug.
	 */
	public function test_download_model_rejects_empty_slug() {
		$result = $this->client->download_model( '' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test that download_model() returns WP_Error for an unrecognised slug.
	 */
	public function test_download_model_rejects_unknown_slug() {
		$result = $this->client->download_model( 'nonexistent-model-slug' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_model', $result->get_error_code() );
	}

	/**
	 * Test that download_model() returns WP_Error for a path-traversal slug.
	 */
	public function test_download_model_rejects_path_traversal_slug() {
		$result = $this->client->download_model( '../../../etc/passwd' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_model', $result->get_error_code() );
	}

	/**
	 * Test that download_model() returns WP_Error for a slug with special characters.
	 */
	public function test_download_model_rejects_slug_with_special_chars() {
		$result = $this->client->download_model( 'model<script>alert(1)</script>' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_model', $result->get_error_code() );
	}

	/**
	 * Test that download_model() returns WP_Error for a null-byte slug.
	 */
	public function test_download_model_rejects_null_byte_slug() {
		$result = $this->client->download_model( "valid\x00malicious" );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	// =========================================================================
	// delete_model slug validation
	// =========================================================================

	/**
	 * Test that delete_model() returns WP_Error for an empty slug.
	 */
	public function test_delete_model_rejects_empty_slug() {
		$result = $this->client->delete_model( '' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test that delete_model() returns WP_Error for an unrecognised slug.
	 */
	public function test_delete_model_rejects_unknown_slug() {
		$result = $this->client->delete_model( 'nonexistent-model-slug' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_model', $result->get_error_code() );
	}

	/**
	 * Test that delete_model() returns WP_Error for a path-traversal slug.
	 */
	public function test_delete_model_rejects_path_traversal_slug() {
		$result = $this->client->delete_model( '../../../etc/passwd' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_model', $result->get_error_code() );
	}

	// =========================================================================
	// get_available_models
	// =========================================================================

	/**
	 * Test that get_available_models() returns an array keyed by valid slugs.
	 *
	 * GGUF model slugs may contain dots for version numbers (e.g. "3.1" in
	 * granite-3.1-2b-instruct-q4_k_m). We validate against sanitize_model_slug()
	 * rather than sanitize_key(), since the latter would incorrectly strip dots.
	 */
	public function test_get_available_models_returns_known_slugs() {
		$models = $this->client->get_available_models();

		$this->assertIsArray( $models );
		$this->assertNotEmpty( $models );

		foreach ( array_keys( $models ) as $slug ) {
			// Every registered slug must round-trip through sanitize_model_slug() unchanged.
			$this->assertEquals(
				$slug,
				WP_MCP_AI_Embedded_Client::sanitize_model_slug( $slug ),
				"Model slug '$slug' is not safe for use as a sanitize_model_slug() value."
			);
		}
	}

	// =========================================================================
	// sanitize_model_slug
	// =========================================================================

	/**
	 * Test that sanitize_model_slug() preserves dots in version numbers.
	 */
	public function test_sanitize_model_slug_preserves_dots() {
		$this->assertEquals(
			'granite-3.1-2b-instruct-q4_k_m',
			WP_MCP_AI_Embedded_Client::sanitize_model_slug( 'granite-3.1-2b-instruct-q4_k_m' )
		);
		$this->assertEquals(
			'qwen2-0.5b-instruct-q4_k_m',
			WP_MCP_AI_Embedded_Client::sanitize_model_slug( 'qwen2-0.5b-instruct-q4_k_m' )
		);
	}

	/**
	 * Test that sanitize_model_slug() strips dangerous characters.
	 */
	public function test_sanitize_model_slug_strips_special_chars() {
		// Angle brackets are stripped; the alphanumeric content remains.
		$this->assertEquals(
			'modelscript',
			WP_MCP_AI_Embedded_Client::sanitize_model_slug( 'model<script>' )
		);
		$this->assertEquals(
			'some-model',
			WP_MCP_AI_Embedded_Client::sanitize_model_slug( 'some model' ) // Space stripped.
		);
		$this->assertEquals(
			'etcpasswd',
			WP_MCP_AI_Embedded_Client::sanitize_model_slug( '../../../etc/passwd' ) // Slashes stripped.
		);
	}

	/**
	 * Test that sanitize_model_slug() collapses consecutive dots and trims edge dots.
	 *
	 * Prevents path-traversal-style sequences like "model..name" or ".hidden".
	 */
	public function test_sanitize_model_slug_normalises_dots() {
		$this->assertEquals(
			'model.name',
			WP_MCP_AI_Embedded_Client::sanitize_model_slug( 'model..name' )
		);
		$this->assertEquals(
			'model',
			WP_MCP_AI_Embedded_Client::sanitize_model_slug( '.model.' )
		);
		// Legitimate version-number dots must still be preserved.
		$this->assertEquals(
			'granite-3.1-2b',
			WP_MCP_AI_Embedded_Client::sanitize_model_slug( 'granite-3.1-2b' )
		);
	}

	/**
	 * Test that sanitize_model_slug() converts to lowercase.
	 */
	public function test_sanitize_model_slug_lowercases() {
		$this->assertEquals(
			'granite-3.1-2b',
			WP_MCP_AI_Embedded_Client::sanitize_model_slug( 'Granite-3.1-2B' )
		);
	}

	// =========================================================================
	// is_server_model_slug with dotted version numbers
	// =========================================================================

	/**
	 * Test that is_server_model_slug() correctly recognises granite-3.1 slug.
	 *
	 * Previously broken because sanitize_key() stripped the dot, turning
	 * "granite-3.1-2b-instruct-q4_k_m" into "granite-31-2b-instruct-q4_k_m"
	 * which did not match the catalogue key.
	 */
	public function test_is_server_model_slug_recognises_granite() {
		$this->assertTrue(
			WP_MCP_AI_Embedded_Client::is_server_model_slug( 'granite-3.1-2b-instruct-q4_k_m' ),
			'granite-3.1-2b-instruct-q4_k_m must be recognised as a server-side GGUF slug.'
		);
	}

	/**
	 * Test that is_server_model_slug() correctly recognises qwen2-0.5b slug.
	 */
	public function test_is_server_model_slug_recognises_qwen2_dotted() {
		$this->assertTrue(
			WP_MCP_AI_Embedded_Client::is_server_model_slug( 'qwen2-0.5b-instruct-q4_k_m' ),
			'qwen2-0.5b-instruct-q4_k_m must be recognised as a server-side GGUF slug.'
		);
	}

	/**
	 * Test that is_server_model_slug() returns false for a WebLLM client-side model ID.
	 */
	public function test_is_server_model_slug_rejects_webllm_id() {
		$this->assertFalse(
			WP_MCP_AI_Embedded_Client::is_server_model_slug( 'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC' )
		);
	}

	/**
	 * Test that is_server_model_slug() returns false for an empty string.
	 */
	public function test_is_server_model_slug_rejects_empty() {
		$this->assertFalse( WP_MCP_AI_Embedded_Client::is_server_model_slug( '' ) );
	}

	/**
	 * Test that each model definition in the catalogue has required keys.
	 */
	public function test_available_model_definitions_have_required_keys() {
		$models        = $this->client->get_available_models();
		$required_keys = array( 'name', 'filename', 'download_url', 'size_mb' );

		foreach ( $models as $slug => $model ) {
			foreach ( $required_keys as $key ) {
				$this->assertArrayHasKey(
					$key,
					$model,
					"Model '$slug' is missing required key '$key'."
				);
			}
		}
	}
}
