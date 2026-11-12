<?php
/**
 * Laravel Service Provider for the oOS Core integration.
 *
 * Binds the domain contracts to their Laravel adapters, registers the
 * core services with Octane-safe lifetimes, and seeds the tool registry
 * with the framework-agnostic tools shipped by nvoos/core.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\ServiceProvider;

use Illuminate\Support\ServiceProvider;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\RateLimiterInterface;
use Nvoos\Core\Domain\Contract\SessionLogStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\TokenUsageInterface;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Nvoos\Core\Tool\ColorConvertTool;
use Nvoos\Laravel\Adapter\AuthProvider;
use Nvoos\Laravel\Adapter\CacheStore;
use Nvoos\Laravel\Adapter\ContentStore;
use Nvoos\Laravel\Adapter\ErrorFactory;
use Nvoos\Laravel\Adapter\EventDispatcher;
use Nvoos\Laravel\Adapter\FileStore;
use Nvoos\Laravel\Adapter\FileUploadService;
use Nvoos\Laravel\Adapter\HttpClient;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Laravel\Adapter\MemoryStore;
use Nvoos\Laravel\Adapter\PlatformFlush;
use Nvoos\Laravel\Adapter\ProEntitlement;
use Nvoos\Laravel\Adapter\QueueClient;
use Nvoos\Laravel\Adapter\RateLimiter;
use Nvoos\Laravel\Adapter\SessionLogStore;
use Nvoos\Laravel\Adapter\SettingsStore;
use Nvoos\Laravel\Adapter\TokenUsage;
use Nvoos\Laravel\Adapter\TranscriptStore;
use Nvoos\Laravel\Provider\ProviderRegistrar;

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
		$this->app->bind( FileUploadServiceInterface::class, function ( $app ) {
			return new FileUploadService(
				disk: (string) $app['config']->get( 'oos.media_disk', 'public' ),
				maxBytes: (int) $app['config']->get( 'oos.upload_max_bytes', FileUploadServiceInterface::DEFAULT_MAX_FILE_SIZE ),
			);
		} );
		$this->app->bind( QueueClientInterface::class, QueueClient::class );
		$this->app->bind( AuthProviderInterface::class, AuthProvider::class );
		$this->app->bind( ContentStoreInterface::class, ContentStore::class );
		$this->app->bind( HttpClientInterface::class, HttpClient::class );
		$this->app->bind( TranscriptStoreInterface::class, TranscriptStore::class );
		$this->app->bind( MemoryStoreInterface::class, MemoryStore::class );
		$this->app->bind( SessionLogStoreInterface::class, SessionLogStore::class );
		$this->app->bind( TokenUsageInterface::class, TokenUsage::class );
		$this->app->bind( RateLimiterInterface::class, RateLimiter::class );

		// Pro entitlement — configuration-driven until the commercial source
		// is chosen; unknown drivers fail closed (Proposal 090-B, ADR-7).
		$this->app->singleton( ProEntitlementInterface::class, function ( $app ) {
			$config = $app['config']->get( 'oos.pro_entitlement', array() );

			return new ProEntitlement(
				driver: (string) ( $config['driver'] ?? 'allow' ),
				plan: (string) ( $config['plan'] ?? 'compliance' ),
				featureMap: (array) ( $config['features'] ?? array() ),
			);
		} );

		// Durable job status rows (driver-agnostic).
		$this->app->singleton( JobStatusStore::class, fn () => new JobStatusStore() );

		// Tool registry — stateless registry, seeded once at boot.
		$this->app->singleton( ToolRegistry::class, fn ( $app ) => new ToolRegistry(
			$app->make( EventDispatcherInterface::class ),
			$app->make( ErrorFactoryInterface::class ),
		) );

		// Provider router — configured once; holds mutable health/fallback
		// state, not request/user state.
		$this->app->singleton( ProviderRouter::class, function ( $app ) {
			$router = new ProviderRouter(
				$app->make( SettingsStoreInterface::class ),
				$app->make( ErrorFactoryInterface::class ),
			);

			( new ProviderRegistrar() )->register(
				$router,
				$app->make( SettingsStoreInterface::class ),
				$app->make( HttpClientInterface::class ),
				$app->make( ErrorFactoryInterface::class ),
			);

			return $router;
		} );

		// SSE and the orchestrator are request-bound.
		$this->app->scoped( PlatformFlushInterface::class, PlatformFlush::class );
		$this->app->scoped( SseHandler::class, fn ( $app ) => new SseHandler(
			$app->make( PlatformFlushInterface::class ),
		) );

		$this->app->singleton( CostCalculator::class, fn () => new CostCalculator() );

		$this->app->scoped( ChatOrchestrator::class, fn ( $app ) => new ChatOrchestrator(
			tools: $app->make( ToolRegistry::class ),
			providers: $app->make( ProviderRouter::class ),
			events: $app->make( EventDispatcherInterface::class ),
			errors: $app->make( ErrorFactoryInterface::class ),
			costs: $app->make( CostCalculator::class ),
			sse: $app->make( SseHandler::class ),
		) );
	}

	/**
	 * Bootstrap oOS — publish config, migrations, and register tools.
	 */
	public function boot(): void {
		// Load the package migrations so a fresh app can migrate in one step.
		$this->loadMigrationsFrom( __DIR__ . '/../../database/migrations' );

		// Publish the config file.
		$this->publishes(
			array(
				__DIR__ . '/../../config/nvoos.php' => config_path( 'oos.php' ),
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

		$this->registerTools();
	}

	/**
	 * Seed the tool registry with the self-contained core tools.
	 *
	 * The spike starts with a small, key-free set. Additional tools are
	 * registered incrementally as their constructor dependencies are
	 * wired into the Laravel container.
	 */
	private function registerTools(): void {
		$registry = $this->app->make( ToolRegistry::class );
		$errors   = $this->app->make( ErrorFactoryInterface::class );

		if ( ! $registry->has( 'color_convert' ) ) {
			$registry->register( new ColorConvertTool( $errors ) );
		}

		$registry->notifyRegistered();
	}
}
