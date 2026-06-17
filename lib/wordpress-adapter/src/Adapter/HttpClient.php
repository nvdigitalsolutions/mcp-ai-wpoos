<?php
/**
 * WordPress adapter: HttpClientInterface implementation.
 *
 * Wraps WordPress HTTP functions (wp_remote_get, wp_remote_post, etc.)
 * behind the domain-owned HttpClientInterface. Respects WordPress HTTP
 * filters, proxy settings, SSL verification, and timeout configuration.
 *
 * Unlike the legacy WP_MCP_AI_Http_Client_Service (which uses Symfony
 * HttpClient for advanced streaming/retry), this adapter is a thin
 * WordPress-native wrapper suitable for all provider client requests.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;

class HttpClient implements HttpClientInterface {

	/**
	 * Default request timeout in seconds.
	 */
	private const DEFAULT_TIMEOUT = 30;

	/**
	 * Send an HTTP request via WordPress HTTP API.
	 *
	 * Delegates to wp_remote_request() which respects:
	 *  - WP_HTTP_PROXY / WP_PROXY_* constants
	 *  - WP_HTTP_BLOCK_EXTERNAL / WP_ACCESSIBLE_HOSTS
	 *  - SSL verification overrides (WP_DEBUG, local env)
	 *  - WordPress HTTP filters (pre_http_request, http_response)
	 *
	 * @return HttpResponse  The response with status code, body, and headers.
	 *
	 * @throws \RuntimeException  When the request cannot be completed
	 *                            (DNS failure, timeout, connection refused, etc.).
	 */
	public function send( string $method, string $url, array $headers = array(), ?string $body = null ): HttpResponse {
		$args = array(
			'method'  => $method,
			'timeout' => self::DEFAULT_TIMEOUT,
			'headers' => $headers,
		);

		if ( null !== $body && '' !== $body ) {
			$args['body'] = $body;
		}

		$response = \wp_remote_request( $url, $args );

		if ( $response instanceof \WP_Error ) {
			throw new \RuntimeException(
				\sprintf(
					'HTTP request failed: %s (%s)',
					$response->get_error_message(),
					$response->get_error_code(),
				),
			);
		}

		$statusCode = (int) \wp_remote_retrieve_response_code( $response );
		$respBody   = (string) \wp_remote_retrieve_body( $response );
		$respHeaders = (array) \wp_remote_retrieve_headers( $response );

		// Normalize headers to string-keyed array for consistency.
		$normalizedHeaders = array();
		foreach ( $respHeaders as $key => $value ) {
			if ( \is_string( $key ) ) {
				$normalizedHeaders[ \strtolower( $key ) ] = $value;
			}
		}

		return new HttpResponse( $statusCode, $respBody, $normalizedHeaders );
	}
}
