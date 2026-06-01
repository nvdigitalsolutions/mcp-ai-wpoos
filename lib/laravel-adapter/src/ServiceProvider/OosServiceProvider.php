<?php
/**
 * Laravel Service Provider for oOS Core.
 *
 * Binds all 9 domain interfaces to their Laravel adapter implementations
 * and registers the ChatOrchestrator as a singleton. Published config
 * and migrations allow projects to customise the integration.
 *
 * @package Oos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Laravel\ServiceProvider;

use Illuminate\Support\ServiceProvider;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\CacheStoreInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Oos\Core\Domain\Contract\EventDispatcherInterface;
use Oos\Core\Domain\Contract\FileStoreInterface;
use Oos\Core\Domain\Contract\QueueClientInterface;
use Oos\Core\Domain\Contract\AuthProviderInterface;
use Oos\Core\Domain\Contract\ContentStoreInterface;
use Oos\Core\Application\Chat\ChatOrchestrator;
use Oos\Core\Application\Tool\ToolRegistry;
use Oos\Core\Application\Provider\ProviderRouter;
use Oos\Laravel\Adapter\ErrorFactory;
use Oos\Laravel\Adapter\CacheStore;
use Oos\Laravel\Adapter\SettingsStore;
use Oos\Laravel\Adapter\EventDispatcher;
use Oos\Laravel\Adapter\FileStore;
use Oos\Laravel\Adapter\QueueClient;
use Oos\Laravel\Adapter\AuthProvider;
use Oos\Laravel\Adapter\ContentStore;

class OosServiceProvider extends ServiceProvider {

	/**
	 * Register oOS services into the Laravel container.
	 */
	public function register(): void {
		// Merge default configuration.
		$this->mergeConfigFrom(
			__DIR__ . '/../../config/oos.php',
			'oos',
		);

		// Bind domain interfaces → adapter implementations.
		$this->app->bind( ErrorFactoryInterface::class, ErrorFactory::class );
		$this->app->bind( CacheStoreInterface::class, CacheStore::class );
		$this->app->bind( SettingsStoreInterface::class, SettingsStore::class );
		$this->app->bind( EventDispatcherInterface::class, EventDispatcher::class );
		$this->app->bind( FileStoreInterface::class, FileStore::class );
		$this->app->bind( QueueClientInterface::class, QueueClient::class );
		$this->app->bind( AuthProviderInterface::class, AuthProvider::class );
		$this->app->bind( ContentStoreInterface::class, ContentStore::class );

		// ChatOrchestrator — singleton so all requests share the same instance.
		$this->app->singleton( ChatOrchestrator::class, function ( $app ) {
			return new ChatOrchestrator(
				tools: $app->make( ToolRegistry::class ),
				providers: $app->make( ProviderRouter::class ),
				events: $app->make( EventDispatcherInterface::class ),
				errors: $app->make( ErrorFactoryInterface::class ),
			);
		} );
	}

	/**
	 * Bootstrap oOS — publish config, migrations, and routes.
	 */
	public function boot(): void {
		// Publish the config file.
		$this->publishes(
			array(
				__DIR__ . '/../../config/oos.php' => config_path( 'oos.php' ),
			),
			'oos-config',
		);

		// Publish database migrations.
		$this->publishes(
			array(
				__DIR__ . '/../../database/migrations/' => database_path( 'migrations' ),
			),
			'oos-migrations',
		);

		// Register console commands.
		if ( $this->app->runningInConsole() ) {
			$this->commands( array(
				// Future: OosToolList, OosProviderPing, etc.
			) );
		}
	}
}
