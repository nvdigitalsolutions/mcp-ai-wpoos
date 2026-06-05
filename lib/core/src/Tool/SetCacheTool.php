<?php
/**
 * Set Cache tool — stores a value in the cache with a TTL.
 *
 * Uses CacheStoreInterface — framework-agnostic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class SetCacheTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly CacheStoreInterface $cache,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'set_cache';
	}

	public function getName(): string {
		return 'Set Cache';
	}

	public function getDescription(): string {
		return 'Stores a value in the cache with an optional TTL (time-to-live in seconds). Returns true if stored successfully.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'   => array(
					'type'        => 'string',
					'description' => 'The cache key to set.',
				),
				'value' => array(
					'description' => 'The value to cache. Can be any type.',
				),
				'ttl'   => array(
					'type'        => 'integer',
					'description' => 'Time-to-live in seconds. Default: 3600 (1 hour).',
					'default'     => 3600,
					'minimum'     => 1,
					'maximum'     => 86400,
				),
			),
			'required'             => array( 'key', 'value' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$key   = $this->stringParam( $arguments, 'key' );
		$value = $arguments['value'] ?? null;
		$ttl   = $this->intParam( $arguments, 'ttl', 3600 );

		if ( '' === $key ) {
			return $this->errors->validationFailed(
				'key is required.',
				array( 'key' => array( 'A cache key is required.' ) ),
			);
		}

		$ok = $this->cache->setValue( $key, $value, $ttl );

		if ( ! $ok ) {
			return $this->errors->create( 'cache_write_failed', "Failed to set cache key: $key" );
		}

		return $this->success(
			sprintf( 'Cache key "%s" set (TTL: %ds).', $key, $ttl ),
			array(
				'key'   => $key,
				'ttl'   => $ttl,
				'stored' => true,
			),
		);
	}
}
