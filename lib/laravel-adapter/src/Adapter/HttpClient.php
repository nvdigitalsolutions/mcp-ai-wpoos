<?php
/**
 * Laravel adapter: HttpClientInterface implementation.
 *
 * Wraps Laravel's HTTP client facade behind the framework-agnostic
 * HttpClientInterface. Provider clients and tools can make outbound
 * requests without importing Illuminate classes.
 *
 * @package Nvoos\Laravel
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;

class HttpClient implements HttpClientInterface {

	/**
	 * Send an HTTP request and return the normalized response.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Full request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Request body.
	 *
	 * @return HttpResponse
	 *
	 * @throws \RuntimeException When the request cannot be completed.
	 */
	public function send( string $method, string $url, array $headers = array(), ?string $body = null ): HttpResponse {
		$request = Http::withHeaders( $headers )
			->timeout( (int) config( 'oos.request_timeout', 60 ) );

		if ( null !== $body ) {
			$contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? 'application/json';
			$request     = $request->withBody( $body, $contentType );
		}

		try {
			$response = $request->send( strtoupper( $method ), $url );
		} catch ( ConnectionException $e ) {
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}

		$flatHeaders = array();
		foreach ( $response->headers() as $name => $values ) {
			$flatHeaders[ $name ] = is_array( $values )
				? implode( ', ', $values )
				: (string) $values;
		}

		return new HttpResponse(
			statusCode: $response->status(),
			body: $response->body(),
			headers: $flatHeaders,
		);
	}
}
