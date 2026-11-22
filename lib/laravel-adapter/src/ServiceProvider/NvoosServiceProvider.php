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
use Nvoos\Core\Application\Security\InputGuard;
use Nvoos\Core\Application\Security\OutputGuard;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\NecessityGuard;
use Nvoos\Core\Application\Tool\ReadOnlyToolGuard;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Infrastructure\PaperStore\JsonPaperStore;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;
use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface;
use Nvoos\Core\Domain\Contract\AgentRunnerInterface;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErlangCInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\ApprovalTicketInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;
use Nvoos\Core\Domain\Contract\HarnessProfileProviderInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\ModelLimitCatalogueInterface;
use Nvoos\Core\Domain\Contract\TiktokenServiceInterface;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\RateLimiterInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Service\Harness\OutputGuardrail;
use Nvoos\Core\Domain\Service\Harness\RetrievalHarness;
use Nvoos\Core\Domain\Contract\SessionLogStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\TenantResolverInterface;
use Nvoos\Core\Domain\Contract\TokenUsageInterface;
use Nvoos\Core\Domain\Contract\ToolInvokerInterface;
use Nvoos\Core\Domain\Contract\TraceStoreInterface;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Profession\CachedProfessionRepository;
use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Nvoos\Core\Tool\BulkImportTool;
use Nvoos\Core\Tool\Classroom\BulkEnrollStudentsTool;
use Nvoos\Core\Tool\Classroom\CreateEcaTool;
use Nvoos\Core\Tool\Classroom\CreateStudentTool;
use Nvoos\Core\Tool\Classroom\EnrollStudentEcaTool;
use Nvoos\Core\Tool\Classroom\GetEcaTool;
use Nvoos\Core\Tool\Classroom\GetStudentTool;
use Nvoos\Core\Tool\Classroom\ListEcasTool;
use Nvoos\Core\Tool\Classroom\ListStudentsTool;
use Nvoos\Core\Tool\Classroom\ManageEcaWaitlistTool;
use Nvoos\Core\Tool\Classroom\MarkEcaAttendanceTool;
use Nvoos\Core\Tool\Classroom\UpdateEcaTool;
use Nvoos\Core\Tool\Classroom\UpdateStudentTool;
use Nvoos\Core\Tool\Classroom\WithdrawStudentEcaTool;
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
use Nvoos\Laravel\Tool\ManifestToolRegistrar;
use Nvoos\Laravel\Adapter\AgentOrchestration;
use Nvoos\Laravel\Adapter\AuthProvider;
use Nvoos\Laravel\Adapter\CacheStore;
use Nvoos\Laravel\Adapter\ContentStore;
use Nvoos\Laravel\Adapter\ErrorFactory;
use Nvoos\Laravel\Adapter\EmailService;
use Nvoos\Laravel\Adapter\EventDispatcher;
use Nvoos\Laravel\Adapter\FileStore;
use Nvoos\Laravel\Adapter\FileUploadService;
use Nvoos\Laravel\Adapter\DatabaseTraceStore;
use Nvoos\Laravel\Adapter\HarnessProfileProvider;
use Nvoos\Laravel\Adapter\HttpClient;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Laravel\Adapter\MediaWorkerImageProcessing;
use Nvoos\Laravel\Adapter\MemoryStore;
use Nvoos\Laravel\Adapter\PlatformFlush;
use Nvoos\Laravel\Adapter\ProEntitlementFactory;
use Nvoos\Laravel\Adapter\ProfessionRepository;
use Nvoos\Laravel\Adapter\QueueClient;
use Nvoos\Laravel\Adapter\RegistryToolInvoker;
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
		$this->app->bind( HarnessProfileProviderInterface::class, HarnessProfileProvider::class );
		$this->app->bind( TraceStoreInterface::class, DatabaseTraceStore::class );
		$this->app->bind( CacheStoreInterface::class, CacheStore::class );
		$this->app->bind( SettingsStoreInterface::class, SettingsStore::class );
		$this->app->bind( ToolInvokerInterface::class, RegistryToolInvoker::class );
		$this->app->bind(
			RetrievalHarness::class,
			fn ( $app ) => new RetrievalHarness( $app->make( ToolInvokerInterface::class ) ),
		);
		$this->app->bind( EventDispatcherInterface::class, EventDispatcher::class );

		// The readiness manifest is shared with the WordPress runtime, so a tool
		// marked servable must be constructible here: send_group_email needs this
		// contract and ToolReadinessRegistrationTest enforces it. Hosts may bind
		// their own implementation over it for a different transport.
		$this->app->bind( EmailServiceInterface::class, EmailService::class );

		// The Erlang C maths ships in nvoos/core, but nothing bound the contract,
		// so erlang_c_queue_health could not be constructed. Binding the shipped
		// service is the whole fix; it has no dependencies of its own.
		$this->app->bind( ErlangCInterface::class, \Nvoos\Core\Domain\Service\Optimization\ErlangC::class );

		// The port has no PHP image library, so the four image tools need this
		// contract to be constructible. The media worker is the platform's Sharp
		// pipeline; `isAvailable()` answers false when no worker is configured,
		// which is the same shape WordPress has without GD/Imagick
		// (Proposal 149 §160).
		$this->app->bind( ImageProcessingInterface::class, MediaWorkerImageProcessing::class );

		// Agent orchestration needs assistants (a host contract) and a runner (the
		// host's chat pipeline). Both are optional so the package can construct
		// the four orchestration tools without them — the readiness manifest
		// promises constructibility — while an unconfigured host gets an honest
		// `success: false` rather than a hollow result (Proposal 149 §163).
		$this->app->bind( AgentOrchestrationInterface::class, function ( $app ) {
			return new AgentOrchestration(
				$app->make( CacheStoreInterface::class ),
				$app->bound( AssistantServiceInterface::class ) ? $app->make( AssistantServiceInterface::class ) : null,
				$app->bound( AgentRunnerInterface::class ) ? $app->make( AgentRunnerInterface::class ) : null,
				$app->bound( TenantResolverInterface::class ) ? $app->make( TenantResolverInterface::class ) : null,
			);
		} );
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
		//
		// SCOPED, not singleton (Proposal 149 T-0.8). Entitlement is per-estate
		// state: a host that resolves a licence per request (the Laravel admin
		// reads the tenant's key) must not have the first resolved instance
		// pinned for the life of an Octane worker. Octane flushes scoped
		// bindings between operations via FlushTemporaryContainerInstances, so
		// scoped() is the seam that actually bounds it.
		$this->app->scoped( ProEntitlementInterface::class, function ( $app ) {
			return ProEntitlementFactory::make(
				(array) $app['config']->get( 'oos.pro_entitlement', array() ),
				(string) $app['config']->get( 'app.url', '' )
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

		// Readiness decisions — the manifest is the registration policy.
		$this->app->singleton( ToolReadiness::class, static fn (): ToolReadiness => new ToolReadiness() );

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

		// Necessity gate — refuses overeager tool calls: a delete with no
		// target, mail with no recipient, a search with no query. Registered as
		// a monotonic guard so an earlier listener cannot re-allow what it
		// denies. Off unless `oos.necessity_gate.enabled` is set.
		$this->app->afterResolving( ToolRegistry::class, function ( ToolRegistry $registry, $app ): void {
			$gate = (array) $app['config']->get( 'oos.necessity_gate', array() );

			if ( empty( $gate['enabled'] ) ) {
				return;
			}

			$approvals = $app->bound( ApprovalTicketInterface::class )
				? $app->make( ApprovalTicketInterface::class )
				: null;

			// `null` keeps the shared corpus default; an explicit list replaces it,
			// and `array()` asks for the prompt back on every tool.
			$preApproved = isset( $gate['pre_approved'] ) && \is_array( $gate['pre_approved'] )
				? \array_values( \array_map( 'strval', $gate['pre_approved'] ) )
				: null;

			$registry->addGuard( new NecessityGuard( $registry, true, $approvals, $preApproved ) );
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
				$app->make( TokenBudgetManager::class ),
			);

			return $router;
		} );

		// Token budget — one manager shared by every provider client, so the
		// pre-flight context-window check uses one set of limits. The platform
		// may attach a live catalogue (Proposal 149 §70); without one the
		// manager keeps its hardcoded snapshot.
		$this->app->singleton( TokenBudgetManager::class, function ( $app ) {
			$budget = new TokenBudgetManager();

			if ( $app->bound( ModelLimitCatalogueInterface::class ) ) {
				$budget->setCatalogue( $app->make( ModelLimitCatalogueInterface::class ) );
			}

			if ( $app->bound( TiktokenServiceInterface::class ) ) {
				$budget->setTokenizer( $app->make( TiktokenServiceInterface::class ) );
			}

			return $budget;
		} );

		// SSE and the orchestrator are request-bound.
		$this->app->scoped( PlatformFlushInterface::class, PlatformFlush::class );
		$this->app->scoped( SseHandler::class, fn ( $app ) => new SseHandler(
			$app->make( PlatformFlushInterface::class ),
		) );

		$this->app->singleton( CostCalculator::class, fn () => new CostCalculator() );

		$this->app->scoped( ChatOrchestrator::class, function ( $app ) {
			$orchestrator = new ChatOrchestrator(
				tools: $app->make( ToolRegistry::class ),
				providers: $app->make( ProviderRouter::class ),
				events: $app->make( EventDispatcherInterface::class ),
				errors: $app->make( ErrorFactoryInterface::class ),
				costs: $app->make( CostCalculator::class ),
				sse: $app->make( SseHandler::class ),
			);

			$orchestrator->setInputGuard( self::inputGuardFromConfig() );
			$orchestrator->setOutputGuard( self::outputGuardFromConfig() );
			$orchestrator->setHarnessProfileProvider( $app->make( HarnessProfileProviderInterface::class ) );

			// Trace capture is a master kill switch on top of the per-assistant
			// profile: off here means nothing is written, whatever a profile says.
			$orchestrator->setTraceStore(
				(bool) config( 'oos.security.trace_capture', true )
					? $app->make( TraceStoreInterface::class )
					: null
			);

			return $orchestrator;
		} );
	}

	/**
	 * Build the AI-safety input guard from config.
	 *
	 * Returns null when both controls are off, so an unconfigured install pays
	 * nothing for a guard that would never block anyway.
	 */
	private static function inputGuardFromConfig(): ?InputGuard {
		$security = (array) config( 'oos.security', array() );
		$injection = (bool) ( $security['prompt_injection_detector'] ?? false );
		$pii = (bool) ( $security['pii_filter'] ?? false );

		if ( ! $injection && ! $pii ) {
			return null;
		}

		return new InputGuard(
			mode: (string) ( $security['prompt_injection_mode'] ?? InputGuard::MODE_LOG ),
			strictness: (string) ( $security['prompt_injection_sensitivity'] ?? 'medium' ),
			piiEnabled: $pii,
			injectionEnabled: $injection,
		);
	}

	/**
	 * Build the AI-safety output guard from config.
	 *
	 * Returns null when the layer is off, so an unconfigured install pays
	 * nothing — and, importantly, keeps live token streaming.
	 */
	private static function outputGuardFromConfig(): ?OutputGuard {
		$security = (array) config( 'oos.security', array() );

		if ( ! (bool) ( $security['output_guard'] ?? false ) ) {
			return null;
		}

		return new OutputGuard(
			mode: (string) ( $security['output_guard_mode'] ?? OutputGuardrail::MODE_WARN ),
			strictness: (string) ( $security['output_guard_strictness'] ?? OutputGuardrail::STRICTNESS_MEDIUM ),
			enabled: true,
		);
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

		// Manifest first: it is the policy, and the catalogue below is now only
		// a fallback for tools the manifest has not classified.
		$registrar = new ManifestToolRegistrar( $this->app, $this->app->make( ToolReadiness::class ) );
		$result    = $registrar->registerInto( $registry );

		if ( array() !== $result['skipped'] ) {
			// Skipped tools mean the manifest claims `ready` for something the
			// container cannot build. Surface it in diagnostics, do not fail boot.
			$this->app->instance( 'nvoos.tools.skipped', $result['skipped'] );
		}

		foreach ( CoreToolCatalogue::build( $errors, $this->app->make( HarnessProfileProviderInterface::class ) ) as $tool ) {
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
			new WithdrawStudentEcaTool( $errors, $content ),
			new BulkEnrollStudentsTool( $errors, $content ),
			new ManageEcaWaitlistTool( $errors, $content ),
			new CreateEcaTool( $errors, $content ),
			new UpdateEcaTool( $errors, $content ),
			new CreateStudentTool( $errors, $content ),
			new UpdateStudentTool( $errors, $content ),
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
