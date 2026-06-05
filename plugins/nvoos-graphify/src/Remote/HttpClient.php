<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote;

/**
 * SSRF-safe HTTP client stub.
 *
 * Full implementation deferred to the `nvoos-graphify-remote` addon.
 * The core plugin does not make outbound HTTP requests.
 *
 * @since 1.0.0
 */
class HttpClient {

	/**
	 * Perform a GET request.
	 *
	 * @param string $url     Target URL.
	 * @param array  $headers Optional headers.
	 * @return array|\WP_Error
	 */
	public function get( string $url, array $headers = array() ) {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new \WP_Error( 'http_unavailable', 'WordPress HTTP API not loaded.' );
		}
		return wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string $url  Target URL.
	 * @param mixed  $body Request body.
	 * @param array  $headers Optional headers.
	 * @return array|\WP_Error
	 */
	public function post( string $url, $body, array $headers = array() ) {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return new \WP_Error( 'http_unavailable', 'WordPress HTTP API not loaded.' );
		}
		return wp_remote_post(
			$url,
			array(
				'body'    => $body,
				'headers' => $headers,
				'timeout' => 30,
			)
		);
	}
}
