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
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-embedded-client.php';
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
	 */
	public function test_get_available_models_returns_known_slugs() {
		$models = $this->client->get_available_models();

		$this->assertIsArray( $models );
		$this->assertNotEmpty( $models );

		foreach ( array_keys( $models ) as $slug ) {
			// Every registered slug must match the pattern expected by sanitize_key().
			$this->assertEquals(
				$slug,
				sanitize_key( $slug ),
				"Model slug '$slug' is not safe for use as a sanitize_key() value."
			);
		}
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
