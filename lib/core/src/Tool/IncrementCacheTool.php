<?php
/**
 * Increment Cache tool — atomically increments a numeric cache value.
 *
 * Uses CacheStoreInterface::increment(). Useful for counters, rate
 * limiting, and usage tracking.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\CacheStoreInterface;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;

class IncrementCacheTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly CacheStoreInterface $cache,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'increment_cache';
	}

	public function getName(): string {
		return 'Increment Cache';
	}

	public function getDescription(): string {
		return 'Atomically increments a numeric cache value. If the key does not exist, it is initialized to 0 before incrementing. Returns the new value.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key' => array(
					'type'        => 'string',
					'description' => 'The cache key to increment.',
				),
				'by'  => array(
					'type'        => 'integer',
					'description' => 'Amount to increment by. Default: 1.',
					'default'     => 1,
				),
				'ttl' => array(
					'type'        => 'integer',
					'description' => 'TTL in seconds for new keys. Default: 3600.',
					'default'     => 3600,
					'minimum'     => 1,
				),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$key = $this->stringParam( $arguments, 'key' );
		if ( '' === $key ) {
			return $this->errors->validationFailed(
				'key is required.',
				array( 'key' => array( 'A cache key is required.' ) ),
			);
		}

		$by  = $this->intParam( $arguments, 'by', 1 );
		$ttl = $this->intParam( $arguments, 'ttl', 3600 );

		$newValue = $this->cache->increment( $key, $by, $ttl );

		return $this->success(
			sprintf( 'Cache key "%s" incremented by %d (now: %d).', $key, $by, $newValue ),
			array(
				'key'       => $key,
				'by'        => $by,
				'new_value' => $newValue,
			),
		);
	}
}
