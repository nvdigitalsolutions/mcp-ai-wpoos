<?php
/**
 * Web Search tool — performs internet searches via external APIs.
 *
 * Demonstrates the external-API tool pattern: inject HttpClient (PSR-18)
 * and SettingsStore for API keys, make HTTP calls, return results.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Web_Search.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class WebSearchTool extends AbstractTool {

	private const MAX_RESULTS = 5;

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'web_search';
	}

	public function getName(): string {
		return 'Web Search';
	}

	public function getDescription(): string {
		return 'Performs a web search and returns the top results.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'The search query.',
				),
				'count' => array(
					'type'        => 'integer',
					'description' => 'Number of results (1-10). Default: 5.',
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 5,
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$query = $this->stringParam( $arguments, 'query' );
		if ( '' === $query ) {
			return $this->errors->validationFailed(
				'The query parameter is required.',
				array( 'query' => array( 'A search query is required.' ) ),
			);
		}

		$count = $this->intParam( $arguments, 'count', self::MAX_RESULTS );
		$count = \max( 1, \min( 10, $count ) );

		// Use the Brave Search API if a key is configured.
		$braveKey = $this->settings->getApiKey( 'brave' );

		if ( null !== $braveKey && '' !== $braveKey ) {
			return $this->searchWithBrave( $query, $count, $braveKey );
		}

		// Fallback: DuckDuckGo Instant Answer API (free, no key).
		return $this->searchWithDuckDuckGo( $query, $count );
	}

	/**
	 * Search using the Brave Search API.
	 */
	private function searchWithBrave( string $query, int $count, string $apiKey ): mixed {
		$url = 'https://api.search.brave.com/res/v1/web/search?' . \http_build_query(
			array(
				'q'     => $query,
				'count' => $count,
			)
		);

		try {
			$request  = new \Nyholm\Psr7\Request(
				'GET',
				$url,
				array(
					'Accept'               => 'application/json',
					'X-Subscription-Token' => $apiKey,
				),
			);
			$response = $this->http->sendRequest( $request );

			if ( $response->getStatusCode() >= 400 ) {
				return $this->errors->create(
					'search_failed',
					"Search returned HTTP {$response->getStatusCode()}.",
					array( 'status' => $response->getStatusCode() ),
				);
			}

			$data = \json_decode( (string) $response->getBody(), true );

			$results = array();
			foreach ( $data['web']['results'] ?? array() as $r ) {
				$results[] = array(
					'title'       => $r['title'] ?? '',
					'url'         => $r['url'] ?? '',
					'description' => $r['description'] ?? '',
				);
			}

			return $this->collection(
				"Found {$data['web']['total_results']} results.",
				$results,
				(int) ( $data['web']['total_results'] ?? 0 ),
			);

		} catch ( \Exception $e ) {
			return $this->errors->create(
				'search_request_failed',
				"Search request failed: {$e->getMessage()}",
			);
		}
	}

	/**
	 * Search using DuckDuckGo Instant Answer API (free tier, no auth).
	 */
	private function searchWithDuckDuckGo( string $query, int $count ): mixed {
		$url = 'https://api.duckduckgo.com/?' . \http_build_query(
			array(
				'q'       => $query,
				'format'  => 'json',
				'no_html' => 1,
			)
		);

		try {
			$request  = new \Nyholm\Psr7\Request( 'GET', $url );
			$response = $this->http->sendRequest( $request );
			$data     = \json_decode( (string) $response->getBody(), true );

			if ( ! is_array( $data ) ) {
				return $this->emptyResult( 'No search results found.' );
			}

			$results = array();

			// DuckDuckGo returns a single abstract + related topics.
			if ( ! empty( $data['AbstractText'] ) ) {
				$results[] = array(
					'title'       => $data['Heading'] ?? $query,
					'url'         => $data['AbstractURL'] ?? '',
					'description' => $data['AbstractText'],
				);
			}

			foreach ( ( $data['RelatedTopics'] ?? array() ) as $topic ) {
				if ( \count( $results ) >= $count ) {
					break;
				}
				if ( is_array( $topic ) && ! empty( $topic['Text'] ) ) {
					$results[] = array(
						'title'       => $topic['FirstURL'] ?? '',
						'url'         => $topic['FirstURL'] ?? '',
						'description' => $topic['Text'],
					);
				}
			}

			if ( array() === $results ) {
				return $this->emptyResult( 'No search results found for: ' . $query );
			}

			return $this->collection(
				"Found results for: {$query}",
				$results,
				\count( $results ),
			);

		} catch ( \Exception $e ) {
			return $this->errors->create(
				'search_request_failed',
				"Search request failed: {$e->getMessage()}",
			);
		}
	}
}
