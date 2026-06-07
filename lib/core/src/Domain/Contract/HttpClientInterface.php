<?php
/**
 * HTTP client contract for the oOS AI orchestration core.
 *
 * Abstracts outbound HTTP requests so that provider clients, tools, and
 * services never depend on PSR-18, Symfony HTTP Client, Guzzle, or any
 * other HTTP library. Each platform adapter implements this interface
 * with its native HTTP stack.
 *
 *  - WordPress: wraps wp_remote_get / wp_remote_post
 *  - Laravel:   wraps Http facade
 *  - Standalone: wraps Symfony HttpClient or Guzzle
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Entity\HttpResponse;

interface HttpClientInterface {

	/**
	 * Send an HTTP request and return the response.
	 *
	 * @param string      $method  HTTP method (GET, POST, PUT, DELETE, etc.).
	 * @param string      $url     Full request URL including query string.
	 * @param array       $headers Request headers as key-value pairs.
	 * @param string|null $body    Request body (null for GET/HEAD requests).
	 *
	 * @return HttpResponse  The response with status code, body, and headers.
	 *
	 * @throws \RuntimeException  When the request cannot be completed
	 *                            (DNS failure, timeout, connection refused, etc.).
	 */
	public function send( string $method, string $url, array $headers = array(), ?string $body = null ): HttpResponse;
}
