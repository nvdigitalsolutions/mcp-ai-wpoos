<?php
/**
 * Purge Cache — master cache-purging orchestrator.
 *
 * Coordinates purging across all configured cache layers (Cloudflare,
 * Varnish). Framework-agnostic equivalent of WP_MCP_AI_Tool_Purge_Cache.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;

class PurgeCacheTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'purge_cache';
	}

	public function getName(): string {
		return 'Purge Cache';
	}

	public function getDescription(): string {
		return 'Purges all configured caching layers (Cloudflare, Varnish) to ensure content updates are properly reflected.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'purge_everything' => array(
					'type'        => 'boolean',
					'description' => 'Whether to purge the entire cache for all configured layers.',
					'default'     => false,
				),
				'urls'             => array(
					'type'        => 'array',
					'description' => 'Specific absolute URLs to purge from all configured cache layers.',
					'items'       => array( 'type' => 'string', 'format' => 'uri' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$userId = $context['user_id'] ?? 0;

		if ( $userId <= 0 ) {
			return $this->errors->forbidden( 'You must be logged in to purge the cache.' );
		}

		$purgeEverything = ! empty( $arguments['purge_everything'] );
		$urls            = $this->arrayParam( $arguments, 'urls' );

		if ( ! $purgeEverything && array() === $urls ) {
			return $this->errors->validationFailed(
				'Provide purge_everything or at least one URL to purge.',
				array( 'urls' => array( 'At least one URL is required when purge_everything is false.' ) ),
			);
		}

		$layersPurged = array();
		$errors       = array();

		// Varnish first (local cache before CDN).
		if ( $this->isVarnishEnabled() ) {
			$result = $this->purgeVarnish( $purgeEverything, $urls );
			if ( isset( $result['error'] ) ) {
				$errors['varnish'] = $result['error'];
			} else {
				$layersPurged['varnish'] = $result;
			}
		}

		// Cloudflare second (CDN cache).
		if ( $this->isCloudflareEnabled() ) {
			$result = $this->purgeCloudflare( $purgeEverything, $urls );
			if ( isset( $result['error'] ) ) {
				$errors['cloudflare'] = $result['error'];
			} else {
				$layersPurged['cloudflare'] = $result;
			}
		}

		if ( array() === $layersPurged && array() === $errors ) {
			return $this->errors->create(
				'no_cache_layers',
				'No cache layers are currently configured.',
			);
		}

		$message = 'Cache purge operation completed.';
		if ( array() !== $errors && array() === $layersPurged ) {
			$message = 'Cache purge operation failed for all layers.';
		} elseif ( array() !== $errors ) {
			$message = 'Cache purge operation completed with some errors.';
		}

		return $this->success(
			$message,
			array(
				'layers_purged' => \array_keys( $layersPurged ),
				'results'       => $layersPurged,
				'errors'        => $errors,
			),
		);
	}

	private function isVarnishEnabled(): bool {
		return (bool) $this->settings->get( 'enable_varnish_purge', false );
	}

	private function isCloudflareEnabled(): bool {
		$token  = $this->settings->get( 'cloudflare_api_token', '' );
		$zoneId = $this->settings->get( 'cloudflare_zone_id', '' );

		return '' !== (string) $token && '' !== (string) $zoneId;
	}

	private function purgeVarnish( bool $everything, array $urls ): array {
		$varnishHost = (string) $this->settings->get( 'varnish_host', '127.0.0.1' );
		$results     = array();

		if ( $everything ) {
			try {
				$response = $this->http->send(
					'PURGE',
					"http://{$varnishHost}/",
					array( 'Host' => (string) $this->settings->get( 'site_host', 'localhost' ), 'X-Ban-Regex' => '.*' ),
				);

				$results[] = array(
					'type'   => 'ban',
					'status' => $response->statusCode,
				);
			} catch ( \Throwable $e ) {
				return array( 'error' => array( 'layer' => 'Varnish', 'message' => $e->getMessage() ) );
			}
		}

		foreach ( $urls as $url ) {
			if ( ! \is_string( $url ) || '' === \trim( $url ) ) {
				continue;
			}

			try {
				$parsed = \parse_url( $url );
				$path   = $parsed['path'] ?? '/';
				$query  = isset( $parsed['query'] ) ? "?{$parsed['query']}" : '';
				$host   = $parsed['host'] ?? 'localhost';

				$response = $this->http->send(
					'PURGE',
					"http://{$varnishHost}{$path}{$query}",
					array( 'Host' => $host ),
				);

				$results[] = array(
					'type'   => 'url',
					'url'    => $url,
					'status' => $response->statusCode,
				);
			} catch ( \Throwable $e ) {
				return array( 'error' => array( 'layer' => 'Varnish', 'message' => $e->getMessage() ) );
			}
		}

		return $results;
	}

	private function purgeCloudflare( bool $everything, array $urls ): array {
		$apiToken = (string) $this->settings->get( 'cloudflare_api_token', '' );
		$zoneId   = (string) $this->settings->get( 'cloudflare_zone_id', '' );

		if ( '' === $apiToken || '' === $zoneId ) {
			return array( 'error' => array( 'layer' => 'Cloudflare', 'message' => 'Missing API token or zone ID.' ) );
		}

		$payload = array();

		if ( $everything ) {
			$payload['purge_everything'] = true;
		} else {
			$files = array();
			foreach ( $urls as $url ) {
				if ( \is_string( $url ) && '' !== \trim( $url ) ) {
					$files[] = $url;
				}
			}

			if ( array() === $files ) {
				return array( 'error' => array( 'layer' => 'Cloudflare', 'message' => 'No valid URLs provided.' ) );
			}

			$payload['files'] = \array_values( \array_unique( $files ) );
		}

		$endpoint = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";

		try {
			$response = $this->http->send(
				'POST',
				$endpoint,
				array(
					'Authorization' => "Bearer {$apiToken}",
					'Content-Type'  => 'application/json',
				),
				\json_encode( $payload ),
			);

			$data = \json_decode( $response->body, true );

			if ( $response->statusCode >= 400 || empty( $data['success'] ) ) {
				$errMsg = 'Cloudflare rejected the purge request.';
				if ( \is_array( $data ) && ! empty( $data['errors'] ) ) {
					$first  = \reset( $data['errors'] );
					$errMsg = \is_array( $first ) ? ( $first['message'] ?? $errMsg ) : ( \is_string( $first ) ? $first : $errMsg );
				}

				return array( 'error' => array( 'layer' => 'Cloudflare', 'message' => $errMsg ) );
			}

			return array(
				'purge_everything' => $everything,
				'urls'             => $payload['files'] ?? array(),
				'result'           => $data['result'] ?? null,
			);

		} catch ( \Throwable $e ) {
			return array( 'error' => array( 'layer' => 'Cloudflare', 'message' => $e->getMessage() ) );
		}
	}
}
