<?php
/**
 * Tests for MCP Diagnostic Internal Service Calls
 *
 * Verifies that the MCP diagnostic page uses internal REST API calls
 * instead of making HTTP requests to itself.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test MCP Diagnostic Internal Service Usage
 */
class Test_MCP_Diagnostic_Internal_Calls extends WP_UnitTestCase {

	/**
	 * Test that MCP diagnostic file doesn't use wp_remote_post to self.
	 */
	public function test_diagnostic_file_does_not_use_self_referencing_http_calls() {
		$file_path = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php';
		$this->assertFileExists( $file_path, 'MCP diagnostic file should exist' );

		$file_content = file_get_contents( $file_path );

		// Check that the file doesn't use wp_remote_post with rest_url.
		$this->assertStringNotContainsString(
			'wp_remote_post',
			$file_content,
			'MCP diagnostic should not use wp_remote_post for self-referencing calls'
		);

		// Verify it uses rest_do_request instead.
		$this->assertStringContainsString(
			'rest_do_request',
			$file_content,
			'MCP diagnostic should use rest_do_request for internal API calls'
		);

		// Verify it creates WP_REST_Request objects.
		$this->assertStringContainsString(
			'new WP_REST_Request',
			$file_content,
			'MCP diagnostic should create WP_REST_Request objects for internal calls'
		);
	}

	/**
	 * Test that the diagnostic methods use proper internal REST handling.
	 */
	public function test_diagnostic_uses_proper_rest_response_methods() {
		$file_path    = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php';
		$file_content = file_get_contents( $file_path );

		// Should use $response->get_status() instead of wp_remote_retrieve_response_code.
		$this->assertStringContainsString(
			'->get_status()',
			$file_content,
			'Should use WP_REST_Response->get_status() method'
		);

		// Should use $response->get_data() instead of wp_remote_retrieve_body.
		$this->assertStringContainsString(
			'->get_data()',
			$file_content,
			'Should use WP_REST_Response->get_data() method'
		);

		// Should NOT use wp_remote_retrieve functions.
		$this->assertStringNotContainsString(
			'wp_remote_retrieve_response_code',
			$file_content,
			'Should not use wp_remote_retrieve_response_code for internal calls'
		);

		$this->assertStringNotContainsString(
			'wp_remote_retrieve_body',
			$file_content,
			'Should not use wp_remote_retrieve_body for internal calls'
		);
	}

	/**
	 * Test that external API calls are still preserved.
	 */
	public function test_external_api_calls_are_preserved() {
		// Check that legitimate external API calls still use wp_remote_*.
		$files_to_check = array(
			'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php', // Ollama, LM Studio, Cloudways, Cloudflare.
			'includes/class-wp-mcp-ai-mesh-router.php',               // Mesh peer networking.
		);

		foreach ( $files_to_check as $file ) {
			$file_path = WP_MCP_AI_PATH . $file;
			if ( file_exists( $file_path ) ) {
				$file_content = file_get_contents( $file_path );

				// These files should still use wp_remote_* for external API calls.
				$has_remote_calls = strpos( $file_content, 'wp_remote_post' ) !== false ||
									strpos( $file_content, 'wp_remote_get' ) !== false;

				$this->assertTrue(
					$has_remote_calls,
					sprintf( '%s should contain wp_remote_* calls for external APIs', $file )
				);
			}
		}
	}

	/**
	 * Test that mesh router calls external peers (not self).
	 */
	public function test_mesh_router_calls_external_peers() {
		$file_path = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-router.php';
		if ( ! file_exists( $file_path ) ) {
			$this->markTestSkipped( 'Mesh router file not found' );
		}

		$file_content = file_get_contents( $file_path );

		// Should use wp_remote_post for peer communication.
		$this->assertStringContainsString(
			'wp_remote_post',
			$file_content,
			'Mesh router should use wp_remote_post for external peer communication'
		);

		// Should build peer URLs from configuration.
		$this->assertStringContainsString(
			'$peer_url',
			$file_content,
			'Mesh router should use configurable peer URLs'
		);

		// Should have mesh-specific authentication.
		$this->assertStringContainsString(
			'X-WP-MCP-AI-Mesh-Key',
			$file_content,
			'Mesh router should use mesh-specific authentication headers'
		);
	}

	/**
	 * Test that no other files make self-referencing HTTP calls.
	 */
	public function test_no_other_self_referencing_http_calls() {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( WP_MCP_AI_PATH . 'includes' )
		);

		$self_referencing_files = array();

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				$file_path = $file->getPathname();
				$content   = file_get_contents( $file_path );

				// Skip files that are allowed to make external HTTP calls.
				$allowed_files = array(
					'class-wp-mcp-ai-admin-ajax-handlers.php', // External services.
					'class-wp-mcp-ai-mesh-router.php',         // Mesh networking.
					'class-wp-mcp-ai-provider-diagnostics.php', // Provider testing.
				);

				$is_allowed = false;
				foreach ( $allowed_files as $allowed ) {
					if ( strpos( $file_path, $allowed ) !== false ) {
						$is_allowed = true;
						break;
					}
				}

				if ( $is_allowed ) {
					continue;
				}

				// Check for patterns that suggest self-referencing HTTP calls.
				if ( preg_match( '/wp_remote_(?:post|get)\s*\(\s*rest_url\s*\(/i', $content ) ) {
					$self_referencing_files[] = str_replace( WP_MCP_AI_PATH, '', $file_path );
				}

				if ( preg_match( '/wp_remote_(?:post|get)\s*\(\s*(?:home_url|site_url|admin_url)\s*\(/i', $content ) ) {
					$self_referencing_files[] = str_replace( WP_MCP_AI_PATH, '', $file_path );
				}
			}
		}

		$this->assertEmpty(
			$self_referencing_files,
			sprintf(
				'Found files making self-referencing HTTP calls: %s',
				implode( ', ', $self_referencing_files )
			)
		);
	}
}
