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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

		// The scoped-database toolkit is opt-in, and this is the environment that
		// opts in. Eleven readiness rows say `ready`, which means "this container can
		// build it" — so the port, the probe and the audit reader are bound by the
		// provider unconditionally, and this flag decides only whether the port sees
		// a connection set. Setting it here is sufficient because the port reads it
		// when the tools are constructed during `boot()`, which Testbench runs after
		// `defineEnvironment()`. No connection is declared: an unconfigured port is
		// still constructible and refuses every call, which is the state the wiring
		// test asserts.
		$app['config']->set( 'oos.database.enabled', true );
	}

	/**
	 * Give the in-memory schema a `users` table and one resolvable user.
	 *
	 * `ContentStore::userCanAccess()` resolves the acting user through the
	 * configured auth provider (Proposal 149 §149), so an access check against
	 * user id 1 queries `users`. The adapter's Testbench app loads only this
	 * package's migrations, which never created that table, so every project
	 * write failed with `no such table: users`.
	 *
	 * Runs on the `DatabaseRefreshed` event so it lands after `RefreshDatabase`
	 * has migrated and outside the per-test transaction.
	 */
	protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void {
		if ( ! Schema::hasTable( 'users' ) ) {
			Schema::create(
				'users',
				static function ( $table ): void {
					$table->id();
					$table->string( 'name' )->nullable();
					$table->string( 'email' )->unique();
					$table->string( 'password' )->nullable();
					$table->timestamps();
				}
			);
		}

		if ( ! DB::table( 'users' )->where( 'id', 1 )->exists() ) {
			DB::table( 'users' )->insert(
				array(
					'id'    => 1,
					'name'  => 'Adapter Test User',
					'email' => 'adapter-test@example.com',
				)
			);
		}
	}
}
