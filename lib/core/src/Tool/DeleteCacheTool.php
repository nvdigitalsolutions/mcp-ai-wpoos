<?php
/**
 * Delete Cache tool — removes a value from the cache.
 *
 * Uses CacheStoreInterface — framework-agnostic.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\CacheStoreInterface;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;

class DeleteCacheTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly CacheStoreInterface $cache,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'delete_cache';
	}

	public function getName(): string {
		return 'Delete Cache';
	}

	public function getDescription(): string {
		return 'Removes a cached value by key. Returns true if the key existed and was deleted.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key' => array(
					'type'        => 'string',
					'description' => 'The cache key to delete.',
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

		$deleted = $this->cache->deleteValue( $key );

		return $this->success(
			$deleted ? sprintf( 'Cache key "%s" deleted.', $key ) : sprintf( 'Cache key "%s" not found.', $key ),
			array(
				'key'     => $key,
				'deleted' => $deleted,
			),
		);
	}
}
