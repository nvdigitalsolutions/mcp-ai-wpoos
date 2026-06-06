<?php
/**
 * WordPress PSR-18 HTTP Client adapter.
 *
 * Bridges wp_remote_request() to Psr\Http\Client\ClientInterface
 * so the nvoos/core provider infrastructure can issue HTTP calls
 * through WordPress' HTTP API.
 *
 * @package NvoosGraphifyAi
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosGraphifyAi\Adapter;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;

class WordPressHttpClient implements ClientInterface {

	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$headers = array();
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[ $name ] = implode( ', ', $values );
		}

		$args = array(
			'method'  => $request->getMethod(),
			'headers' => $headers,
			'body'    => (string) $request->getBody(),
			'timeout' => 120,
		);

		$wpResponse = \wp_remote_request( (string) $request->getUri(), $args );

		if ( \is_wp_error( $wpResponse ) ) {
			throw new class ( $wpResponse->get_error_message(), $request ) extends \RuntimeException implements NetworkExceptionInterface {
				private RequestInterface $req;

				public function __construct( string $message, RequestInterface $request ) {
					parent::__construct( $message );
					$this->req = $request;
				}

				public function getRequest(): RequestInterface {
					return $this->req;
				}
			};
		}

		$statusCode = (int) \wp_remote_retrieve_response_code( $wpResponse );
		$body       = \wp_remote_retrieve_body( $wpResponse );
		$rawHeaders = \wp_remote_retrieve_headers( $wpResponse );

		if ( $rawHeaders instanceof \Requests_Utility_CaseInsensitiveDictionary ) {
			$rawHeaders = $rawHeaders->getAll();
		}

		return new Response( $statusCode, (array) $rawHeaders, $body );
	}
}
