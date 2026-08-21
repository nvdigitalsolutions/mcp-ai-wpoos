<?php
/**
 * HTTP testing helper trait.
 *
 * Provides reusable methods for stubbing outbound HTTP requests in test classes
 * that exercise tools or services making wp_remote_* calls.
 *
 * ## Usage
 *
 * ```php
 * class My_Tool_Test extends WP_UnitTestCase {
 *     use WP_MCP_AI_HTTP_Test_Helper;
 *
 *     public function set_up() {
 *         parent::set_up();
 *         $this->init_http_stubs();
 *     }
 *
 *     public function tear_down() {
 *         $this->reset_http_stubs();
 *         parent::tear_down();
 *     }
 *
 *     public function test_execute_with_external_api() {
 *         $this->mock_http_response( 'api.example.com', 200, array( 'ok' => true ) );
 *         $result = ( new My_Tool() )->execute( $args, $context );
 *         $this->assertIsArray( $result );
 *     }
 * }
 * ```
 *
 * The trait installs a `pre_http_request` filter when `init_http_stubs()` is
 * called.  Any URL that does not match a registered stub returns a generic
 * 200 `{}` so tests never accidentally leak to a real network.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- Private properties prefixed with __ to avoid collision with consumer classes.

trait WP_MCP_AI_HTTP_Test_Helper {

	/**
	 * Stubbed HTTP responses keyed by a URL substring.
	 *
	 * Populated via {@see self::mock_http_response()} and consumed by the
	 * `pre_http_request` filter installed by {@see self::init_http_stubs()}.
	 *
	 * @var array<string,array|WP_Error>
	 */
	private $__http_stubs = array();

	/**
	 * Stubs installed by {@see self::mock_http_response_callable()}.
	 *
	 * Each entry is a callable with signature
	 * `function(string $url, array $args): array|WP_Error|null`.
	 * The first callable that returns non-null wins.
	 *
	 * @var array<callable>
	 */
	private $__http_stub_callables = array();

	/**
	 * Number of HTTP requests intercepted during the test.
	 *
	 * @var int
	 */
	private $__http_request_count = 0;

	/**
	 * URLs of intercepted HTTP requests.
	 *
	 * @var string[]
	 */
	private $__http_request_urls = array();

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Initialise HTTP stubbing.
	 *
	 * Must be called explicitly from the test class's `set_up()` method.
	 */
	protected function init_http_stubs() {
		$this->__http_stubs          = array();
		$this->__http_stub_callables = array();
		$this->__http_request_count  = 0;
		$this->__http_request_urls   = array();

		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Tear down HTTP stubbing.
	 *
	 * Must be called explicitly from the test class's `tear_down()` method.
	 */
	protected function reset_http_stubs() {
		remove_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), PHP_INT_MAX );
		$this->__http_stubs          = array();
		$this->__http_stub_callables = array();
		$this->__http_request_count  = 0;
		$this->__http_request_urls   = array();
	}

	// -------------------------------------------------------------------------
	// Public API — stubbing
	// -------------------------------------------------------------------------

	/**
	 * Register a stub HTTP response for any outbound request whose URL contains
	 * the given substring.
	 *
	 * @param string $url_substring Substring to match in the outbound URL.
	 * @param int    $status        HTTP status code.
	 * @param mixed  $body          Response body.  Arrays are JSON-encoded.
	 * @param array  $headers       Optional response headers.
	 */
	protected function mock_http_response( $url_substring, $status, $body, array $headers = array() ) {
		if ( is_array( $body ) || is_object( $body ) ) {
			$body = wp_json_encode( $body );
		}

		$this->__http_stubs[ $url_substring ] = array(
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'body'     => (string) $body,
			'headers'  => $headers,
		);
	}

	/**
	 * Register a stub that returns a WP_Error for any matching URL.
	 *
	 * @param string $url_substring Substring to match in the outbound URL.
	 * @param string $error_code    WP_Error code.
	 * @param string $error_message WP_Error message.
	 */
	protected function mock_http_error( $url_substring, $error_code, $error_message ) {
		$this->__http_stubs[ $url_substring ] = new WP_Error( $error_code, $error_message );
	}

	/**
	 * Register a callable stub that receives the URL and request args and
	 * returns a response array, WP_Error, or null (to fall through).
	 *
	 * This is useful when you need conditional logic (e.g. different responses
	 * for different URLs in a multi-step flow).
	 *
	 * @param callable $callable Signature: `function(string $url, array $args): array|WP_Error|null`.
	 */
	protected function mock_http_response_callable( $callable ) {
		$this->__http_stub_callables[] = $callable;
	}

	// -------------------------------------------------------------------------
	// Public API — fixtures
	// -------------------------------------------------------------------------

	/**
	 * Load a canned HTTP response fixture from the JSON fixtures directory.
	 *
	 * @param string $fixture_name Fixture filename without extension (e.g. 'brave-search-success').
	 * @return array Decoded fixture data.
	 *
	 * @throws \RuntimeException When the fixture file is not found or contains invalid JSON.
	 */
	protected function load_http_fixture( $fixture_name ) {
		$path = dirname( __DIR__ ) . '/fixtures/http/' . $fixture_name . '.json';

		if ( ! file_exists( $path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Static path, not user input.
			throw new \RuntimeException( 'HTTP fixture not found: ' . $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture read.
		$content = file_get_contents( $path );
		$data    = json_decode( $content, true );

		if ( ! is_array( $data ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Static path.
			throw new \RuntimeException( 'Invalid JSON in fixture: ' . $path );
		}

		return $data;
	}

	/**
	 * Load a fixture and register it as a stubbed response for the given URL pattern.
	 *
	 * The fixture is expected to have keys `status`, `body`, and optionally `headers`.
	 *
	 * @param string $url_substring Substring to match in the outbound URL.
	 * @param string $fixture_name  Fixture filename without extension.
	 */
	protected function mock_http_response_from_fixture( $url_substring, $fixture_name ) {
		$fixture = $this->load_http_fixture( $fixture_name );

		$status  = isset( $fixture['status'] ) ? (int) $fixture['status'] : 200;
		$body    = isset( $fixture['body'] ) ? $fixture['body'] : array();
		$headers = isset( $fixture['headers'] ) ? $fixture['headers'] : array();

		$this->mock_http_response( $url_substring, $status, $body, $headers );
	}

	// -------------------------------------------------------------------------
	// Public API — assertions
	// -------------------------------------------------------------------------

	/**
	 * Assert that exactly N HTTP requests were made during the test.
	 *
	 * @param int    $expected_count Expected number of requests.
	 * @param string $message        Optional failure message.
	 */
	protected function assert_http_request_count( $expected_count, $message = '' ) {
		if ( '' === $message ) {
			$message = sprintf(
				'Expected %d HTTP request(s), got %d.',
				$expected_count,
				$this->__http_request_count
			);
		}
		$this->assertSame( $expected_count, $this->__http_request_count, $message );
	}

	/**
	 * Assert that at least one HTTP request was made to a URL matching the
	 * given pattern.
	 *
	 * @param string $url_substring Substring to match in intercepted URLs.
	 * @param string $message       Optional failure message.
	 */
	protected function assert_http_request_made_to( $url_substring, $message = '' ) {
		foreach ( $this->__http_request_urls as $url ) {
			if ( false !== strpos( $url, $url_substring ) ) {
				$this->assertTrue( true );
				return;
			}
		}

		if ( '' === $message ) {
			$message = sprintf(
				"Expected an HTTP request to '%s' but none was made. Intercepted URLs: %s",
				$url_substring,
				implode( ', ', $this->__http_request_urls )
			);
		}
		$this->fail( $message );
	}

	/**
	 * Assert that no HTTP request was made to a URL matching the given pattern.
	 *
	 * @param string $url_substring Substring to match.
	 * @param string $message       Optional failure message.
	 */
	protected function assert_no_http_request_made_to( $url_substring, $message = '' ) {
		foreach ( $this->__http_request_urls as $url ) {
			if ( false !== strpos( $url, $url_substring ) ) {
				if ( '' === $message ) {
					$message = sprintf(
						"Expected no HTTP request to '%s' but one was made to: %s",
						$url_substring,
						$url
					);
				}
				$this->fail( $message );
			}
		}

		// Passed — no matching URL found.
		$this->assertTrue( true );
	}

	/**
	 * Get all intercepted request URLs.
	 *
	 * @return string[]
	 */
	protected function get_intercepted_http_urls() {
		return $this->__http_request_urls;
	}

	// -------------------------------------------------------------------------
	// Public API — utilities
	// -------------------------------------------------------------------------

	/**
	 * Check whether network access is available in the current test environment.
	 *
	 * Use this as a guard in tests that genuinely need to hit a real API:
	 *
	 * ```php
	 * if ( ! $this->is_network_available() ) {
	 *     $this->markTestSkipped( 'Network access not available.' );
	 * }
	 * ```
	 *
	 * @param string $host    Host to probe (default: 'httpbin.org').
	 * @param int    $port    Port to probe (default: 80).
	 * @param int    $timeout Timeout in seconds (default: 2).
	 * @return bool True if a TCP connection succeeds.
	 */
	protected function is_network_available( $host = 'httpbin.org', $port = 80, $timeout = 2 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fsockopen -- Intentional connectivity probe.
		$handle = @fsockopen( $host, $port, $errno, $errstr, $timeout );
		if ( $handle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			fclose( $handle );
			return true;
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Internal: pre_http_request filter
	// -------------------------------------------------------------------------

	/**
	 * Filter callback that intercepts outbound HTTP requests.
	 *
	 * @param false|array|WP_Error $preempt Pre-empt value.
	 * @param array                $args    Request args.
	 * @param string               $url     Request URL.
	 * @return array|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function filter_pre_http_request( $preempt, $args, $url ) {
		unset( $preempt );

		++$this->__http_request_count;
		$this->__http_request_urls[] = $url;

		// Callable stubs take priority (more flexible).
		foreach ( $this->__http_stub_callables as $callable ) {
			$result = call_user_func( $callable, $url, $args );
			if ( null !== $result ) {
				return $result;
			}
		}

		// Simple substring-match stubs.
		foreach ( $this->__http_stubs as $needle => $response ) {
			if ( '' === $needle || false !== strpos( (string) $url, $needle ) ) {
				return $response;
			}
		}

		// Default deny — return a generic 200 so handlers that hit unstubbed
		// URLs still get a deterministic response instead of failing the suite
		// because of network access.
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => '{}',
			'headers'  => array(),
			'cookies'  => array(),
		);
	}
}
