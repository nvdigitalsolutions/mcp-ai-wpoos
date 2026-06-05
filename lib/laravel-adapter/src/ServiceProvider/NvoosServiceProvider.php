<?php
/**
 * Laravel Service Provider for oOS Core.
 *
 * Binds all 9 domain interfaces to their Laravel adapter implementations
 * and registers the ChatOrchestrator as a singleton. Published config
 * and migrations allow projects to customise the integration.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\ServiceProvider;

use Illuminate\Support\ServiceProvider;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Laravel\Adapter\ErrorFactory;
use Nvoos\Laravel\Adapter\CacheStore;
use Nvoos\Laravel\Adapter\SettingsStore;
use Nvoos\Laravel\Adapter\EventDispatcher;
use Nvoos\Laravel\Adapter\FileStore;
use Nvoos\Laravel\Adapter\QueueClient;
use Nvoos\Laravel\Adapter\AuthProvider;
use Nvoos\Laravel\Adapter\ContentStore;

class NvoosServiceProvider extends ServiceProvider {

	/**
	 * Register oOS services into the Laravel container.
	 */
	public function register(): void {
		// Merge default configuration.
		$this->mergeConfigFrom(
			__DIR__ . '/../../config/nvoos.php',
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
				__DIR__ . '/../../config/nvoos.php' => config_path( 'nvoos.php' ),
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
				// Future: NvoosToolList, NvoosProviderPing, etc.
			) );
		}
	}
}
