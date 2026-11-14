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
use Nvoos\Core\Application\Tool\ReadOnlyToolGuard;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Infrastructure\PaperStore\JsonPaperStore;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\RateLimiterInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Contract\SessionLogStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\TokenUsageInterface;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Profession\CachedProfessionRepository;
use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Nvoos\Core\Tool\BulkImportTool;
use Nvoos\Core\Tool\Classroom\EnrollStudentEcaTool;
use Nvoos\Core\Tool\Classroom\GetEcaTool;
use Nvoos\Core\Tool\Classroom\GetStudentTool;
use Nvoos\Core\Tool\Classroom\ListEcasTool;
use Nvoos\Core\Tool\Classroom\ListStudentsTool;
use Nvoos\Core\Tool\Classroom\MarkEcaAttendanceTool;
use Nvoos\Core\Tool\CreateProjectTool;
use Nvoos\Core\Tool\CreateTaskTool;
use Nvoos\Core\Tool\DeleteProjectTool;
use Nvoos\Core\Tool\DeleteTaskTool;
use Nvoos\Core\Tool\UpdateProjectTool;
use Nvoos\Core\Tool\UpdateTaskTool;
use Nvoos\Core\Tool\GetProfessionTool;
use Nvoos\Core\Tool\GetTaskDependenciesTool;
use Nvoos\Core\Tool\ListProfessionsTool;
use Nvoos\Core\Tool\ListProjectsTool;
use Nvoos\Core\Tool\ListTasksTool;
use Nvoos\Core\Tool\ProfessionStatsTool;
use Nvoos\Laravel\Tool\CoreToolCatalogue;
use Nvoos\Laravel\Adapter\AuthProvider;
use Nvoos\Laravel\Adapter\CacheStore;
use Nvoos\Laravel\Adapter\ContentStore;
use Nvoos\Laravel\Adapter\ErrorFactory;
use Nvoos\Laravel\Adapter\EventDispatcher;
use Nvoos\Laravel\Adapter\FileStore;
use Nvoos\Laravel\Adapter\FileUploadService;
use Nvoos\Laravel\Adapter\HttpClient;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Laravel\Adapter\LicensedProEntitlement;
use Nvoos\Laravel\Adapter\MemoryStore;
use Nvoos\Laravel\Adapter\PlatformFlush;
use Nvoos\Laravel\Adapter\ProEntitlement;
use Nvoos\Laravel\Adapter\ProfessionRepository;
use Nvoos\Laravel\Adapter\QueueClient;
use Nvoos\Laravel\Adapter\RateLimiter;
use Nvoos\Laravel\Adapter\SchemaStore;
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
		$this->app->bind( SchemaStoreInterface::class, SchemaStore::class );
		$this->app->bind( ProfessionRepositoryInterface::class, function ( $app ) {
			return new CachedProfessionRepository(
				new ProfessionRepository( $app['config'] ),
				$app->make( CacheStoreInterface::class ),
				(int) $app['config']->get( 'oos.professions.cache_ttl', 86400 ),
			);
		} );
		$this->app->bind( HttpClientInterface::class, HttpClient::class );
		$this->app->bind( TranscriptStoreInterface::class, TranscriptStore::class );
		$this->app->bind( MemoryStoreInterface::class, MemoryStore::class );
		$this->app->bind( SessionLogStoreInterface::class, SessionLogStore::class );
		$this->app->bind( TokenUsageInterface::class, TokenUsage::class );
		$this->app->bind( RateLimiterInterface::class, RateLimiter::class );

		// Pro entitlement. Three driver families share one contract:
		// `license` resolves against the commercial licence server, and the
		// configuration drivers (allow|deny|config) cover development, CI and
		// fail-closed deployments. Unknown drivers fail closed
		// (Proposal 090-B, ADR-7, Phase 5.1).
		$this->app->singleton( ProEntitlementInterface::class, function ( $app ) {
			$config = $app['config']->get( 'oos.pro_entitlement', array() );
			$driver = (string) ( $config['driver'] ?? 'allow' );

			if ( 'license' === $driver ) {
				return new LicensedProEntitlement(
					licenseKey: (string) ( $config['license_key'] ?? '' ),
					serverUrl: (string) ( $config['server_url'] ?? '' ),
					instanceId: (string) ( $config['instance_id'] ?? $app['config']->get( 'app.url', '' ) ),
					product: (string) ( $config['product'] ?? 'mcp-ai-wpoos-pro' ),
					featureMap: (array) ( $config['features'] ?? array() ),
					ttl: (int) ( $config['ttl'] ?? 43200 ),
					deniedTtl: (int) ( $config['denied_ttl'] ?? 300 ),
					graceTtl: (int) ( $config['grace_ttl'] ?? 604800 ),
					timeout: (int) ( $config['timeout'] ?? 10 ),
				);
			}

			return new ProEntitlement(
				driver: $driver,
				plan: (string) ( $config['plan'] ?? 'compliance' ),
				featureMap: (array) ( $config['features'] ?? array() ),
			);
		} );

		// Paper Store — flat-file JSON, one directory per collection, the same
		// layout WordPress writes (090-f Phase 3). Framework-agnostic store, so
		// this binding is only about where the root lives.
		$this->app->singleton( PaperStoreInterface::class, function ( $app ) {
			$root = (string) ( $app['config']->get( 'oos.paper_store_root' ) ?? '' );

			if ( '' === trim( $root ) ) {
				$root = $app->storagePath( 'paper-store' );
			}

			return new JsonPaperStore( $root );
		} );

		// Durable job status rows (driver-agnostic).
		$this->app->singleton( JobStatusStore::class, fn () => new JobStatusStore() );

		// Tool registry — stateless registry, seeded once at boot.
		$this->app->singleton( ToolRegistry::class, fn ( $app ) => new ToolRegistry(
			$app->make( EventDispatcherInterface::class ),
			$app->make( ErrorFactoryInterface::class ),
		) );

		// Read-only deployment profile (Phase 5.4). Registered as a monotonic
		// guard, so it runs after every pre-execute listener and cannot be
		// undone by one. Off unless `oos.read_only.enabled` is set.
		$this->app->afterResolving( ToolRegistry::class, function ( ToolRegistry $registry, $app ): void {
			$readOnly = (array) $app['config']->get( 'oos.read_only', array() );

			if ( empty( $readOnly['enabled'] ) ) {
				return;
			}

			$registry->addGuard(
				new ReadOnlyToolGuard(
					$registry,
					(array) ( $readOnly['gated_flags'] ?? ReadOnlyToolGuard::DEFAULT_GATED_FLAGS ),
					(array) ( $readOnly['allowed_slugs'] ?? array() ),
					(bool) ( $readOnly['block_undeclared'] ?? true ),
				)
			);
		} );

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
	 * Seed the tool registry.
	 *
	 * Everything in {@see CoreToolCatalogue} needs only the error factory, so it
	 * is safe to register without keys or provider configuration. Tools that
	 * need a service are registered here explicitly, one at a time, as their
	 * dependencies are wired into the container.
	 */
	private function registerTools(): void {
		$registry = $this->app->make( ToolRegistry::class );
		$errors   = $this->app->make( ErrorFactoryInterface::class );

		foreach ( CoreToolCatalogue::build( $errors ) as $tool ) {
			if ( ! $registry->has( $tool->getSlug() ) ) {
				$registry->register( $tool );
			}
		}

		// Tools that need a service rather than just the error factory.
		$content     = $this->app->make( ContentStoreInterface::class );
		$professions = $this->app->make( ProfessionRepositoryInterface::class );

		$service_tools = array(
			new ListProjectsTool( $errors, $content ),
			new ListEcasTool( $errors, $content ),
			new GetEcaTool( $errors, $content ),
			new EnrollStudentEcaTool( $errors, $content ),
			new GetStudentTool( $errors, $content ),
			new ListStudentsTool( $errors, $content ),
			new MarkEcaAttendanceTool( $errors, $content ),
			new ListTasksTool( $errors, $content ),
			new GetTaskDependenciesTool( $errors, $content ),
			new CreateTaskTool( $errors, $content ),
			new DeleteTaskTool( $errors, $content ),
			new CreateProjectTool( $errors, $content ),
			new DeleteProjectTool( $errors, $content ),
			new UpdateTaskTool( $errors, $content ),
			new UpdateProjectTool( $errors, $content ),
			new BulkImportTool( $errors, $content ),
			new ListProfessionsTool( $errors, $professions ),
			new GetProfessionTool( $errors, $professions ),
			new ProfessionStatsTool( $errors, $professions ),
		);

		foreach ( $service_tools as $tool ) {
			if ( ! $registry->has( $tool->getSlug() ) ) {
				$registry->register( $tool );
			}
		}

		$registry->notifyRegistered();
	}
}
