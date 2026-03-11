<?php
/**
 * Security Tests for NV oOS: SSRF Protection
 *
 * Verifies Server-Side Request Forgery (SSRF) protections across the plugin:
 * - Webhook registration blocks private/loopback IP addresses.
 * - Webhook registration enforces allowed URL schemes (http/https only).
 * - Proxy utils reject untrusted forwarded hosts.
 * - Invalid or malformed URLs are rejected.
 *
 * @package WP_MCP_AI
 * @group security
 * @group ssrf
 */

/**
 * SSRF protection test suite.
 */
class WP_MCP_AI_SSRF_Protection_Test extends WP_UnitTestCase {

	/**
	 * Test that registering a webhook to localhost (127.0.0.1) is blocked.
	 */
	public function test_webhook_blocks_localhost_ip() {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$notifier_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';
			if ( file_exists( $notifier_file ) ) {
		require_once $notifier_file;
			} else {
				$this->markTestSkipped( 'WP_MCP_AI_Job_Notifier class not available.' );
				return;
			}
		}

		$result = WP_MCP_AI_Job_Notifier::register_webhook(
			'test-job-123',
			'http://127.0.0.1/steal-data'
		);

		$this->assertWPError( $result, 'Webhook to 127.0.0.1 should be blocked.' );
		$this->assertContains(
			$result->get_error_code(),
			array( 'private_ip_blocked', 'webhook_validation_failed' ),
			'Error code should indicate blocked private/loopback address.'
		);
	}

	/**
	 * Test that registering a webhook to localhost hostname is blocked.
	 */
	public function test_webhook_blocks_localhost_hostname() {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Job_Notifier class not available.' );
			return;
		}

		$result = WP_MCP_AI_Job_Notifier::register_webhook(
			'test-job-456',
			'http://localhost/internal-endpoint'
		);

		$this->assertWPError( $result, 'Webhook to localhost should be blocked.' );
	}

	/**
	 * Test that registering a webhook to a private RFC-1918 address is blocked.
	 */
	public function test_webhook_blocks_private_rfc1918_address() {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Job_Notifier class not available.' );
			return;
		}

		// 10.0.0.1 is in the 10.0.0.0/8 RFC 1918 private range.
		$result = WP_MCP_AI_Job_Notifier::register_webhook(
			'test-job-789',
			'http://10.0.0.1/internal-api'
		);

		$this->assertWPError( $result, 'Webhook to 10.0.0.1 (RFC 1918) should be blocked.' );
		$this->assertContains(
			$result->get_error_code(),
			array( 'private_ip_blocked', 'webhook_validation_failed' ),
			'Error code should indicate blocked private address.'
		);
	}

	/**
	 * Test that registering a webhook to a 192.168.x.x address is blocked.
	 */
	public function test_webhook_blocks_192168_range() {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Job_Notifier class not available.' );
			return;
		}

		$result = WP_MCP_AI_Job_Notifier::register_webhook(
			'test-job-101',
			'http://192.168.1.100/api/secret'
		);

		$this->assertWPError( $result, 'Webhook to 192.168.1.100 should be blocked.' );
		$this->assertContains(
			$result->get_error_code(),
			array( 'private_ip_blocked', 'webhook_validation_failed' ),
			'Error code should indicate blocked private address.'
		);
	}

	/**
	 * Test that file:// protocol is blocked.
	 */
	public function test_webhook_blocks_file_protocol() {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Job_Notifier class not available.' );
			return;
		}

		$result = WP_MCP_AI_Job_Notifier::register_webhook(
			'test-job-102',
			'file:///etc/passwd'
		);

		$this->assertWPError( $result, 'Webhook with file:// should be blocked.' );
		$this->assertContains(
			$result->get_error_code(),
			array( 'invalid_webhook_url', 'invalid_webhook_scheme', 'webhook_validation_failed' ),
			'Error code should indicate blocked protocol.'
		);
	}

	/**
	 * Test that ftp:// protocol is blocked.
	 */
	public function test_webhook_blocks_ftp_protocol() {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Job_Notifier class not available.' );
			return;
		}

		$result = WP_MCP_AI_Job_Notifier::register_webhook(
			'test-job-103',
			'ftp://attacker.example.com/exfil'
		);

		$this->assertWPError( $result, 'Webhook with ftp:// should be blocked.' );
		$this->assertContains(
			$result->get_error_code(),
			array( 'invalid_webhook_url', 'invalid_webhook_scheme', 'webhook_validation_failed' ),
			'Error code should indicate blocked protocol.'
		);
	}

	/**
	 * Test that a malformed/invalid URL is rejected.
	 */
	public function test_webhook_rejects_invalid_url() {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Job_Notifier class not available.' );
			return;
		}

		$result = WP_MCP_AI_Job_Notifier::register_webhook(
			'test-job-104',
			'not-a-url-at-all'
		);

		$this->assertWPError( $result, 'Malformed URL should be rejected.' );
		$this->assertEquals(
			'invalid_webhook_url',
			$result->get_error_code(),
			'Error code should be invalid_webhook_url.'
		);
	}

	/**
	 * Test that an empty URL is rejected.
	 */
	public function test_webhook_rejects_empty_url() {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Job_Notifier class not available.' );
			return;
		}

		$result = WP_MCP_AI_Job_Notifier::register_webhook( 'test-job-105', '' );

		$this->assertWPError( $result, 'Empty URL should be rejected.' );
	}

	/**
	 * Test proxy utils: internal hosts (loopback) are identified correctly.
	 */
	public function test_proxy_utils_identifies_loopback_as_internal() {
		if ( ! class_exists( 'WP_MCP_AI_Proxy_Utils' ) ) {
			$proxy_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-proxy-utils.php';
			if ( file_exists( $proxy_file ) ) {
		require_once $proxy_file;
			} else {
				$this->markTestSkipped( 'WP_MCP_AI_Proxy_Utils class not available.' );
				return;
			}
		}

		// build_rest_url should not substitute a loopback host with an untrusted
		// forwarded host when there is no X-Forwarded-Host header present.
		$_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.example.com';
		$_SERVER['HTTP_HOST']             = 'legitimate.example.com';

		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'mcp-ai/v1', 'assistants' );

		// The forwarded host must NOT be injected because it is not in the allowed list.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$this->assertNotEquals(
			'attacker.example.com',
			$host,
			'Untrusted forwarded host must not replace the URL host.'
		);

		unset( $_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['HTTP_HOST'] );
	}

	/**
	 * Test that a valid public HTTPS URL is accepted (sanity check).
	 *
	 * NOTE: This test uses a publicly routable address; the actual HTTP request
	 * is never dispatched. We only test that the validation logic does not
	 * return a WP_Error for a legitimate external URL.
	 */
	public function test_webhook_accepts_valid_public_https_url() {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Job_Notifier class not available.' );
			return;
		}

		// Use wp_http_validate_url to confirm this URL passes basic validation
		// without triggering a real network request.
		$url    = 'https://hooks.example.com/webhook/abc123';
		$result = WP_MCP_AI_Job_Notifier::register_webhook( 'sanity-job-999', $url );

		// The result may be true (option updated) or false (option unchanged),
		// but it must NOT be a WP_Error for a valid public URL.
		$this->assertNotWPError(
			$result,
			'A valid public HTTPS URL should not be blocked by SSRF protection.'
		);
	}
}
