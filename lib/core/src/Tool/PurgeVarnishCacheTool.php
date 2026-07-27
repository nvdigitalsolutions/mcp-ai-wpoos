<?php
/**
 * Purge Varnish Cache — local Varnish cache invalidation.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Purge_Varnish_Cache.
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

class PurgeVarnishCacheTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'purge_varnish_cache';
	}

	public function getName(): string {
		return 'Purge Varnish Cache';
	}

	public function getDescription(): string {
		return 'Purges the local Varnish cache. Supports full-cache purges (bans) and specific URL purges via HTTP PURGE requests.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'purge_everything' => array(
					'type'        => 'boolean',
					'description' => 'Whether to purge the entire Varnish cache using a ban.',
					'default'     => false,
				),
				'urls'             => array(
					'type'        => 'array',
					'description' => 'Specific absolute URLs to purge from Varnish.',
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
			return $this->errors->forbidden( 'You must be logged in to purge the Varnish cache.' );
		}

		$enabled = (bool) $this->settings->get( 'enable_varnish_purge', false );

		if ( ! $enabled ) {
			return $this->errors->create(
				'varnish_disabled',
				'Varnish purge is not enabled.',
			);
		}

		$purgeEverything = ! empty( $arguments['purge_everything'] );
		$urls            = $this->arrayParam( $arguments, 'urls' );

		if ( ! $purgeEverything && array() === $urls ) {
			return $this->errors->validationFailed(
				'Provide purge_everything or at least one URL to purge.',
				array( 'urls' => array( 'At least one URL is required.' ) ),
			);
		}

		$varnishHost = (string) $this->settings->get( 'varnish_host', '127.0.0.1' );
		$siteHost    = (string) $this->settings->get( 'site_host', 'localhost' );
		$results     = array();

		if ( $purgeEverything ) {
			try {
				$response = $this->http->send(
					'PURGE',
					"http://{$varnishHost}/",
					array(
						'Host'        => $siteHost,
						'X-Ban-Regex' => '.*',
					),
				);

				$results[] = array(
					'type'   => 'ban',
					'status' => $response->statusCode,
				);
			} catch ( \Throwable $e ) {
				return $this->errors->create(
					'varnish_ban_failed',
					"Varnish ban request failed: {$e->getMessage()}",
				);
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
				$host   = $parsed['host'] ?? $siteHost;

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
				return $this->errors->create(
					'varnish_purge_failed',
					"Varnish URL purge failed: {$e->getMessage()}",
				);
			}
		}

		return $this->success(
			'Varnish cache purge completed successfully.',
			array(
				'purge_everything' => $purgeEverything,
				'urls_purged'      => \count( $urls ),
				'results'          => $results,
			),
		);
	}
}
