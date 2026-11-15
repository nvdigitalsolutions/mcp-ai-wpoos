<?php
/**
 * Base Testbench test case for the Laravel adapter.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Laravel\ServiceProvider\NvoosServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

	/**
	 * Register the package service provider.
	 *
	 * @param mixed $app Application instance.
	 * @return array<int, class-string>
	 */
	protected function getPackageProviders( $app ): array {
		return array(
			NvoosServiceProvider::class,
		);
	}

	/**
	 * Configure the test environment.
	 *
	 * @param mixed $app Application instance.
	 */
	protected function defineEnvironment( $app ): void {
		$app['config']->set( 'database.default', 'testing' );
		$app['config']->set( 'database.connections.testing', array(
			'driver'   => 'sqlite',
			'database' => ':memory:',
			'prefix'   => '',
		) );

		$app['config']->set( 'cache.default', 'array' );
		$app['config']->set( 'queue.default', 'sync' );
		$app['config']->set( 'oos.queue_connection', 'sync' );
		$app['config']->set( 'oos.storage_disk', 'local' );
	}
}
