<?php
/**
 * Get Cache tool — reads a value from the cache.
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

class GetCacheTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly CacheStoreInterface $cache,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_cache';
	}

	public function getName(): string {
		return 'Get Cache';
	}

	public function getDescription(): string {
		return 'Reads a value from the cache by key. Returns null and found=false if the key does not exist or has expired.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'     => array(
					'type'        => 'string',
					'description' => 'The cache key to retrieve.',
				),
				'default' => array(
					'description' => 'Default value returned when the key is not found.',
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

		$default = $arguments['default'] ?? null;
		$value   = $this->cache->getValue( $key, $default );

		$found = null !== $value;
		if ( $found && '' === $value ) {
			$found = false; // getValue returns default (null) on miss.
		}

		return $this->success(
			$found ? sprintf( 'Cache key "%s" retrieved.', $key ) : sprintf( 'Cache key "%s" not found.', $key ),
			array(
				'key'   => $key,
				'value' => $value,
				'found' => null !== $value,
			),
		);
	}
}
