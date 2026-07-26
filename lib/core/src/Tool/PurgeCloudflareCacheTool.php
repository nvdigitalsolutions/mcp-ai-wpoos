<?php
/**
 * Purge Cloudflare Cache — Cloudflare API cache invalidation.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Purge_Cloudflare_Cache.
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

class PurgeCloudflareCacheTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'purge_cloudflare_cache';
	}

	public function getName(): string {
		return 'Purge Cloudflare Cache';
	}

	public function getDescription(): string {
		return 'Requests a cache purge for the configured Cloudflare zone. Supports full purge, URL-based, host-based, and tag-based purges.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'purge_everything' => array(
					'type'        => 'boolean',
					'description' => 'Whether to purge the entire Cloudflare cache for the zone.',
					'default'     => false,
				),
				'urls'             => array(
					'type'        => 'array',
					'description' => 'Specific asset URLs to purge.',
					'items'       => array( 'type' => 'string', 'format' => 'uri' ),
				),
				'hosts'            => array(
					'type'        => 'array',
					'description' => 'Hostnames to purge from cache.',
					'items'       => array( 'type' => 'string' ),
				),
				'tags'             => array(
					'type'        => 'array',
					'description' => 'Cache tags to purge.',
					'items'       => array( 'type' => 'string' ),
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
			return $this->errors->forbidden( 'You must be logged in to purge the Cloudflare cache.' );
		}

		$apiToken = (string) $this->settings->get( 'cloudflare_api_token', '' );
		$zoneId   = (string) $this->settings->get( 'cloudflare_zone_id', '' );

		if ( '' === $apiToken ) {
			return $this->errors->create(
				'missing_token',
				'No Cloudflare API token has been configured.',
			);
		}

		if ( '' === $zoneId ) {
			return $this->errors->create(
				'missing_zone',
				'No Cloudflare zone ID has been configured.',
			);
		}

		$payload = $this->buildPayload( $arguments );

		if ( null === $payload ) {
			return $this->errors->validationFailed(
				'Provide purge_everything or at least one URL, host, or tag.',
				array( 'purge_everything' => array( 'At least one purge target is required.' ) ),
			);
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

			if ( $response->statusCode >= 400 || ( \is_array( $data ) && empty( $data['success'] ) ) ) {
				$errMsg = 'Cloudflare rejected the purge request.';
				if ( \is_array( $data ) && ! empty( $data['errors'] ) ) {
					$first  = \reset( $data['errors'] );
					$errMsg = \is_array( $first ) ? ( $first['message'] ?? $errMsg ) : ( \is_string( $first ) ? $first : $errMsg );
				}

				return $this->errors->create( 'cloudflare_error', $errMsg, array( 'status' => $response->statusCode ) );
			}

			return $this->success(
				'Cloudflare accepted the purge request.',
				array(
					'purge_everything' => ! empty( $payload['purge_everything'] ),
					'files'            => $payload['files'] ?? array(),
					'hosts'            => $payload['hosts'] ?? array(),
					'tags'             => $payload['tags'] ?? array(),
					'result'           => $data['result'] ?? null,
				),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create(
				'cloudflare_http_error',
				"Cloudflare API request failed: {$e->getMessage()}",
			);
		}
	}

	private function buildPayload( array $arguments ): ?array {
		$payload = array();

		if ( ! empty( $arguments['purge_everything'] ) ) {
			$payload['purge_everything'] = true;
		}

		$urls  = $this->arrayParam( $arguments, 'urls' );
		$hosts = $this->arrayParam( $arguments, 'hosts' );
		$tags  = $this->arrayParam( $arguments, 'tags' );

		if ( array() !== $urls ) {
			$files = array();
			foreach ( $urls as $url ) {
				if ( \is_string( $url ) && '' !== \trim( $url ) ) {
					$files[] = \trim( $url );
				}
			}

			if ( array() !== $files ) {
				$payload['files'] = \array_values( \array_unique( $files ) );
			}
		}

		if ( array() !== $hosts ) {
			$clean = array();
			foreach ( $hosts as $host ) {
				if ( \is_string( $host ) && '' !== \trim( $host ) ) {
					$clean[] = \trim( $host );
				}
			}

			if ( array() !== $clean ) {
				$payload['hosts'] = \array_values( \array_unique( $clean ) );
			}
		}

		if ( array() !== $tags ) {
			$clean = array();
			foreach ( $tags as $tag ) {
				if ( \is_string( $tag ) && '' !== \trim( $tag ) ) {
					$clean[] = \trim( $tag );
				}
			}

			if ( array() !== $clean ) {
				$payload['tags'] = \array_values( \array_unique( $clean ) );
			}
		}

		if ( array() === $payload ) {
			return null;
		}

		return $payload;
	}
}
