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
use Nvoos\Core\Domain\Service\Harness\PromptInjectionDetector;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ChatProfileGuard;
use Nvoos\Core\Application\Tool\NecessityGuard;
use Nvoos\Core\Application\Tool\ReadOnlyToolGuard;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Infrastructure\Cloudways\CloudwaysClient;
use Nvoos\Core\Infrastructure\Okf\FilesystemBundleLocator;
use Nvoos\Core\Infrastructure\PaperStore\JsonPaperStore;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;
use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface;
use Nvoos\Core\Domain\Contract\AgentRunnerInterface;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\AudioTranscriptionInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\CapabilityFlagOverridesInterface;
use Nvoos\Core\Domain\Contract\ChatProfileResolverInterface;
use Nvoos\Core\Domain\Contract\ChunkedUploadInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErlangCInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\ValueObject\TurnBudget;
use Nvoos\Core\Domain\Contract\ApprovalTicketInterface;
use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;
use Nvoos\Core\Domain\Contract\ControlledDocumentInterface;
use Nvoos\Core\Domain\Contract\DocsCorpusInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HarnessProfileProviderInterface;
use Nvoos\Core\Domain\Contract\HealthReportInterface;
use Nvoos\Laravel\Health\PackageHealthReport;
use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Domain\Contract\BrowserCaptureInterface;
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;
use Nvoos\Core\Domain\Contract\ImageProductionInterface;
use Nvoos\Core\Domain\Contract\MediaWorkerClientInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\OfficeWriterInterface;
use Nvoos\Core\Domain\Contract\PdfMutatorInterface;
use Nvoos\Core\Domain\Contract\PdfOcrInterface;
use Nvoos\Core\Domain\Contract\PdfPageRendererInterface;
use Nvoos\Core\Domain\Contract\PdfTextExtractorInterface;
use Nvoos\Core\Domain\Contract\ModelLimitCatalogueInterface;
use Nvoos\Core\Domain\Contract\TiktokenServiceInterface;
use Nvoos\Core\Domain\Contract\OkfBundleLocatorInterface;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\ProfessionOverlayInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\ProfessionWriteInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\RateLimiterInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Service\Harness\OutputGuardrail;
use Nvoos\Core\Domain\Service\Harness\RetrievalHarness;
use Nvoos\Core\Domain\Contract\SessionLogStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\SocialComposerInterface;
use Nvoos\Core\Domain\Contract\SocialPublisherInterface;
use Nvoos\Core\Domain\Contract\TabularReaderInterface;
use Nvoos\Core\Domain\Contract\TenantResolverInterface;
use Nvoos\Core\Domain\Contract\TokenUsageInterface;
use Nvoos\Core\Domain\Contract\ToolInvokerInterface;
use Nvoos\Core\Domain\Contract\VideoCompositionInterface;
use Nvoos\Core\Domain\Contract\VideoFrameExtractionInterface;
use Nvoos\Core\Domain\Contract\VideoProcessingInterface;
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
use Nvoos\Laravel\Adapter\ChunkedUploadService;
use Nvoos\Laravel\Adapter\ChatProfileResolver;
use Nvoos\Laravel\Adapter\ContentStore;
use Nvoos\Laravel\Adapter\ErrorFactory;
use Nvoos\Laravel\Adapter\EmailService;
use Nvoos\Laravel\Adapter\EventDispatcher;
use Nvoos\Laravel\Adapter\FileStore;
use Nvoos\Laravel\Adapter\FilesystemControlledDocuments;
use Nvoos\Laravel\Adapter\FilesystemDocsCorpus;
use Nvoos\Laravel\Adapter\FileUploadService;
use Nvoos\Laravel\Adapter\DatabaseTraceStore;
use Nvoos\Laravel\Adapter\HarnessProfileProvider;
use Nvoos\Laravel\Adapter\HttpClient;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Laravel\Adapter\MediaWorkerCapabilityProbe;
use Nvoos\Laravel\Adapter\MediaWorkerClient;
use Nvoos\Laravel\Adapter\MediaWorkerHtmlToPdf;
use Nvoos\Laravel\Adapter\MediaWorkerImageProcessing;
use Nvoos\Laravel\Adapter\MediaWorkerImageProduction;
use Nvoos\Laravel\Adapter\MediaWorkerOfficeWriter;
use Nvoos\Laravel\Adapter\MediaWorkerBrowserCapture;
use Nvoos\Laravel\Adapter\MediaWorkerPdfMutator;
use Nvoos\Laravel\Adapter\MediaWorkerPdfOcr;
use Nvoos\Laravel\Adapter\MediaWorkerPdfPageRenderer;
use Nvoos\Laravel\Adapter\MediaWorkerPdfTextExtractor;
use Nvoos\Laravel\Adapter\MediaWorkerSocialComposer;
use Nvoos\Laravel\Adapter\MediaWorkerSocialPublisher;
use Nvoos\Laravel\Adapter\MediaWorkerTabularReader;
use Nvoos\Laravel\Adapter\MediaWorkerVideo;
use Nvoos\Laravel\Adapter\MediaWorkerVideoComposition;
use Nvoos\Laravel\Adapter\MediaWorkerVideoFrames;
use Nvoos\Laravel\Adapter\MemoryStore;
use Nvoos\Laravel\Adapter\OpenAiTranscription;
use Nvoos\Laravel\Adapter\PlatformFlush;
use Nvoos\Laravel\Adapter\ProEntitlementFactory;
use Nvoos\Laravel\Adapter\ProfessionRepository;
use Nvoos\Laravel\Profession\JsonProfessionOverlay;
use Nvoos\Laravel\Profession\OverlayProfessionRepository;
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
		// Shared, not per-resolution. The registry captures the dispatcher it is
		// constructed with, so a host that resolves the interface again would get a
		// second instance and any listener registered there would land on an object
		// nobody dispatches through. `bind` here was exactly that bug (149 §179.4).
		// A dispatcher is a shared hub, so it is a singleton.
		$this->app->singleton( EventDispatcherInterface::class, EventDispatcher::class );

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

		// The pipeline half of the image family (Proposal 164 section 164.6
		// phase 2). The port has no GD and no local Sharp, so re-encoding,
		// sharpening, upscaling, batches and model-backed edits all ride the
		// worker. Each tool asks `supports(operation)` first, which reads the
		// capability matrix, so a worker older than the route answers a named
		// refusal instead of a 404 mid-run.
		$this->app->bind( ImageProductionInterface::class, MediaWorkerImageProduction::class );

		// Social read-only capability (Proposal 164 section 164.4.6). The worker
		// lists channels and composes copy; publishing stays out of this contract
		// because it is an irreversible send that belongs behind the necessity
		// guard, not one method call away in an adapter.
		$this->app->bind( SocialComposerInterface::class, MediaWorkerSocialComposer::class );

		// Publishing is a separate contract from composing because it is the
		// irreversible half: `publish_to_social` declares `irreversible` +
		// `external-communication`, so the necessity guard refuses it without an
		// approval ticket and spends an approved one exactly once.
		$this->app->bind( SocialPublisherInterface::class, MediaWorkerSocialPublisher::class );

		// Video (Proposal 164 section 164.4.5). The worker's `/process` writes to
		// its own disk and answers a name, so the adapter performs the download as
		// part of `process()` and returns a local path. `video_processing` needs
		// ffmpeg and ffprobe, which a managed Node host often lacks — so each tool
		// asks and refuses with `no_video_engine` rather than failing mid-upload.
		$this->app->bind( VideoProcessingInterface::class, MediaWorkerVideo::class );

		// Multi-input video (a video plus a mark) is its own contract on its own
		// endpoint: `/api/video/compose` takes an ordered `files` array, so the
		// single-input route above keeps the meaning every other operation
		// depends on. `merge` will join it rather than widen this one.
		$this->app->bind( VideoCompositionInterface::class, MediaWorkerVideoComposition::class );

		// Frames are many files out of one video, which is a return shape
		// `process()` deliberately does not have. Its own contract keeps that
		// meaning intact for the four tools that read it (§164.25).
		$this->app->bind( VideoFrameExtractionInterface::class, MediaWorkerVideoFrames::class );

		// Speech-to-text with timings (Proposal 164 §164.27). Separate from
		// `transcribe_openai_audio` because a transcript is text and a caption
		// needs segments; the adapter asks for `verbose_json` and normalises, so
		// the formatter never speaks a provider dialect. It needs no worker —
		// only a key — which is why an audio file can be captioned on a host that
		// has no ffmpeg.
		$this->app->bind( AudioTranscriptionInterface::class, function ( $app ) {
			return new OpenAiTranscription( $app->make( SettingsStoreInterface::class ) );
		} );

		// Documents follow the same ladder (Proposal 164 §164.4.2): the worker
		// renders with a real browser at `/api/pdf/generate`, so `html_to_pdf`
		// is servable without adding a PHP PDF library. `isAvailable()` answers
		// false when no worker is configured, and the tool then refuses with
		// `no_pdf_engine` rather than producing an empty document.
		$this->app->bind( HtmlToPdfInterface::class, MediaWorkerHtmlToPdf::class );

		// The rest of the document family rides the same worker (Proposal 164
		// section 164.4.3): extract, merge and watermark are `/api/pdf/*`
		// multipart routes, Word and Excel are `/api/document/*` JSON routes.
		// Each tool asks `isAvailable()` and refuses with a named capability
		// error rather than discovering the absence mid-render.
		$this->app->bind( PdfTextExtractorInterface::class, MediaWorkerPdfTextExtractor::class );
		$this->app->bind( PdfMutatorInterface::class, MediaWorkerPdfMutator::class );
		$this->app->bind( OfficeWriterInterface::class, MediaWorkerOfficeWriter::class );

		// Page capture (§164.53). The browser route answers the rendered bytes so
		// the tool can store them; a worker that predates the `b64` field is
		// refused at call time rather than handed a path this host cannot read.
		$this->app->bind( BrowserCaptureInterface::class, MediaWorkerBrowserCapture::class );

		// OCR is newer than the PDF family, so its adapter asks the capability
		// matrix before claiming availability: a configured worker may predate
		// the `/api/pdf/ocr` route, and a named refusal beats a 404 mid-run.
		$this->app->bind( PdfOcrInterface::class, MediaWorkerPdfOcr::class );

		// Page images for something that is not this host — a vision model, most
		// often. The route is older than the `inline` argument, so the adapter asks
		// for the narrower capability key: a worker that answers with paths this
		// host cannot read would otherwise look like success.
		$this->app->bind( PdfPageRendererInterface::class, MediaWorkerPdfPageRenderer::class );

		// The docs corpus is a directory, not a service (Proposal 164 section
		// 164.34). `oos.docs.corpus.root` empty means the five `docs_hub_*` tools
		// answer `no_docs_corpus`; a configured root makes the adapter the only
		// thing that decides whether a path is inside it.
		$this->app->singleton(
			DocsCorpusInterface::class,
			static fn ( $app ) => new FilesystemDocsCorpus(
				(string) $app['config']->get( 'oos.docs.corpus.root', '' ),
				(int) $app['config']->get( 'oos.docs.corpus.max_bytes', 2097152 ),
				(array) $app['config']->get( 'oos.docs.corpus.extensions', array( 'md', 'txt' ) ),
			)
		);

		// The controlled-document register composes the corpus rather than
		// re-deriving its path guard (Proposal 164 section 164.37): the corpus owns
		// sanitisation and containment, this owns the lifecycle and the
		// append-only trail. Both roots empty disables the ten qms_* tools with
		// `no_qms_register`.
		$this->app->singleton(
			ControlledDocumentInterface::class,
			static fn ( $app ) => new FilesystemControlledDocuments(
				$app->make( DocsCorpusInterface::class ),
				(string) $app['config']->get( 'oos.qms.audit_root', '' ),
			)
		);

		// Spreadsheet reading: the worker already parses xlsx/csv/json with
		// ExcelJS, so `excel_data_import` needs an adapter, not a PHP library.
		$this->app->bind( TabularReaderInterface::class, MediaWorkerTabularReader::class );

		// The capability matrix, probed once and cached (Proposal 164 section
		// 164.4.2). Every later domain adapter and both diagnostic surfaces read
		// this one answer, so a managed Node host with no `ffmpeg` reports
		// `video: unavailable` instead of discovering it by failing a request.
		$this->app->bind( MediaWorkerClientInterface::class, MediaWorkerClient::class );
		$this->app->singleton( MediaWorkerCapabilityProbe::class );

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
		$this->app->bind( FileStoreInterface::class, function ( $app ) {
			return new FileStore(
				publicDisk: (string) $app['config']->get( 'oos.files.disks.public', 'public' ),
				privateDisk: (string) $app['config']->get( 'oos.files.disks.private', 'local' ),
				limits: (array) $app['config']->get( 'oos.files.limits', array() ),
			);
		} );
		// Proposal 162 — the same store, reached through the optional visibility
		// capability. A second binding rather than a second class: the object model
		// is one writer, and a host that only knows `FileStoreInterface` keeps working.
		$this->app->bind(
			FileVisibilityStoreInterface::class,
			static fn ( $app ) => $app->make( FileStoreInterface::class ),
		);
		// Proposal 164 section 164.46 — the same store once more, through the optional
		// derivative capability. A file produced from another records the relationship
		// in `nvoos_files.metadata`, which is what lets an inventory find a video from
		// its poster instead of guessing from a filename.
		$this->app->bind(
			FileDerivativeStoreInterface::class,
			static fn ( $app ) => $app->make( FileStoreInterface::class ),
		);
		$this->app->bind( FileUploadServiceInterface::class, function ( $app ) {
			return new FileUploadService(
				disk: (string) $app['config']->get( 'oos.media_disk', 'public' ),
				maxBytes: (int) $app['config']->get( 'oos.upload_max_bytes', FileUploadServiceInterface::DEFAULT_MAX_FILE_SIZE ),
				// Proposal 162 §162.7 — the media library's writer delegates to the one
				// file registry, so an upload and a toolkit artifact share an id scheme,
				// a path convention and a checksum.
				store: $app->make( FileVisibilityStoreInterface::class ),
			);
		} );

		// 164.30 — resumable uploads. `upload_max_filesize` is 2 MB in the shipped
		// image, so the single-request path cannot carry a real file whatever
		// `upload_max_bytes` says; this keeps every request small and moves the
		// per-file ceiling to the registry's group caps (video 512 MiB).
		$this->app->bind( ChunkedUploadInterface::class, function ( $app ) {
			return new ChunkedUploadService(
				disk: (string) $app['config']->get( 'oos.chunked_uploads.disk', 'local' ),
				maxTotalBytes: (int) $app['config']->get( 'oos.chunked_uploads.max_bytes', 536870912 ),
				maxChunkBytes: (int) $app['config']->get( 'oos.chunked_uploads.chunk_bytes', 4194304 ),
				expiresHours: (int) $app['config']->get( 'oos.chunked_uploads.expires_hours', 24 ),
				store: $app->make( FileVisibilityStoreInterface::class ),
				uploads: $app->make( FileUploadServiceInterface::class ),
			);
		} );
		$this->app->bind( QueueClientInterface::class, QueueClient::class );
		$this->app->bind( AuthProviderInterface::class, AuthProvider::class );
		$this->app->bind( ContentStoreInterface::class, ContentStore::class );
		$this->app->bind( SchemaStoreInterface::class, SchemaStore::class );
		// The profession catalogue's tenant tier (Proposal 149 §170). The base is
		// code-owned reference data; this is the half `save_profession` writes.
		//
		// The JSON default exists because the readiness manifest is shared: its
		// `save_profession` row is `ready`, so every host reading the manifest
		// must be able to build `SaveProfessionTool`, and that tool needs a
		// writer. The alternative — a readiness model that tolerates
		// "servable only where X is bound" — would weaken the gate that makes a
		// hollow tool impossible to ship.
		//
		// A host with real storage rebinds `ProfessionOverlayInterface` and both
		// halves follow, because the read closure below resolves it lazily: the
		// app points it at `nvoos_professions` without touching this file.
		$this->app->bind( ProfessionOverlayInterface::class, function ( $app ) {
			return new JsonProfessionOverlay( new ProfessionRepository( $app['config'] ), $app['config'] );
		} );

		$this->app->bind( ProfessionWriteInterface::class, function ( $app ) {
			return $app->make( ProfessionOverlayInterface::class );
		} );

		$this->app->bind( ProfessionRepositoryInterface::class, function ( $app ) {
			return new CachedProfessionRepository(
				new OverlayProfessionRepository(
					new ProfessionRepository( $app['config'] ),
					$app->make( ProfessionOverlayInterface::class ),
				),
				$app->make( CacheStoreInterface::class ),
				(int) $app['config']->get( 'oos.professions.cache_ttl', 86400 ),
			);
		} );
		$this->app->bind( HttpClientInterface::class, HttpClient::class );

		// The Cloudways toolkit ports read their own paths through this contract.
		// Singleton on purpose: the client caches the OAuth token in settings, so a
		// per-resolution instance would exchange credentials again for every tool
		// call in one turn. Credentials and the token cache use the same keys as the
		// WordPress client, so an install connected through either runtime is
		// connected for both.
		$this->app->singleton(
			CloudwaysClientInterface::class,
			static function ( $app ) {
				return new CloudwaysClient(
					$app->make( SettingsStoreInterface::class ),
					$app->make( HttpClientInterface::class ),
				);
			}
		);
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

		// OKF bundles — one directory per bundle under a configured base (Proposal 164
		// section 164.50). The locator maps a bundle name to a root; the engine's
		// reader and writer take it from there, so this binding is only about where
		// the bundles live.
		$this->app->singleton( OkfBundleLocatorInterface::class, function ( $app ) {
			$base = (string) ( $app['config']->get( 'oos.okf_root' ) ?? '' );

			if ( '' === trim( $base ) ) {
				$base = $app->storagePath( 'okf' );
			}

			return new FilesystemBundleLocator( $base );
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

		// Health probes for `get_site_health`. Health checks are host facts, so
		// the package answers the two questions it can and the app replaces this
		// with its full Site Health probe set in `AppServiceProvider`.
		$this->app->singleton(
			HealthReportInterface::class,
			static fn ( $app ): PackageHealthReport => new PackageHealthReport(
				$app->make( ToolRegistry::class ),
				$app->make( ToolReadiness::class ),
				$app->make( SettingsStoreInterface::class ),
			)
		);

		// Per-user chat profiles (Proposal 015 port). The resolver is the only
		// thing that knows where a preference is stored; the guard asks it, and
		// the API and any panel ask the same resolver.
		$this->app->singleton( ChatProfileResolverInterface::class, static function ( $app ): ChatProfileResolver {
			$profiles = (array) $app['config']->get( 'oos.chat_profiles', array() );

			return new ChatProfileResolver(
				(bool) ( $profiles['enabled'] ?? true ),
				(string) ( $profiles['default'] ?? 'write' ),
			);
		} );

		// Chat-profile enforcement. On by default, because a user with no
		// selection resolves to `write`: the guard only bites once someone
		// chooses a restrictive profile. Registered as a monotonic guard, so a
		// denial here is not a confirmation prompt and no listener can re-allow
		// it.
		$this->app->afterResolving( ToolRegistry::class, function ( ToolRegistry $registry, $app ): void {
			$profiles = (array) $app['config']->get( 'oos.chat_profiles', array() );

			if ( ! (bool) ( $profiles['enabled'] ?? true ) ) {
				return;
			}

			$registry->addGuard(
				new ChatProfileGuard(
					$registry,
					$app->make( ChatProfileResolverInterface::class ),
					(bool) ( $profiles['block_undeclared'] ?? true ),
					$this->flagOverrides( $app ),
				)
			);
		} );

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
					$this->flagOverrides( $app ),
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

			$registry->addGuard( new NecessityGuard( $registry, true, $approvals, $preApproved, $this->flagOverrides( $app ) ) );
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

			$orchestrator->setInputGuard( self::inputGuardFromSettings( $app ) );
			$orchestrator->setOutputGuard( self::outputGuardFromConfig() );
			$orchestrator->setHarnessProfileProvider( $app->make( HarnessProfileProviderInterface::class ) );

			// The read-only mode hint. Advisory only — the guard in ToolRegistry
			// is the control — but without it the model discovers the boundary by
			// having a write refused, and spends iterations retrying.
			$orchestrator->setChatProfileResolver( $app->make( ChatProfileResolverInterface::class ) );

			// The turn's token ceilings (149 §179). Optional: a host that binds
			// nothing runs with both dimensions off, which is the behaviour every
			// deployment had before the ceilings existed. The check runs before
			// each provider call, so a spent ceiling stops the loop rather than
			// reporting after the bill.
			if ( $app->bound( TurnBudget::class ) ) {
				$orchestrator->setTurnBudget( $app->make( TurnBudget::class ) );
			}

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
	 * Build the AI-safety input guard from the estate's settings, falling back to
	 * config.
	 *
	 * The four controls are writable on `/admin/settings`. Reading them from
	 * `config( 'oos.security' )` alone — which this method used to do — meant an
	 * operator could enable the PII filter in the admin and get no filter: the
	 * admin writes `nvoos_tenant_settings`, and the config file is never overlaid
	 * from that table. A security toggle that saves and enforces nothing is worse
	 * than an absent one, because it reports a posture the runtime does not have
	 * (the same reasoning §183 applies to the coverage report).
	 *
	 * Precedence is explicit, a stored row first: an estate that has set the key
	 * wins over the deployment default, and an unset key falls through to
	 * `config/oos.php` so an existing deployment keeps its behaviour. `all()` is
	 * consulted rather than `get()` because `get()` cannot distinguish "unset"
	 * from "set to the default" — and `false` is a meaningful answer here.
	 *
	 * Safe at construction because `ChatOrchestrator` is bound `scoped`, so this
	 * runs per request and the store's tenant resolver is current. A `singleton`
	 * binding would pin the guard to whichever estate booted first.
	 */
	private static function inputGuardFromSettings( $app ): ?InputGuard {
		$config = (array) config( 'oos.security', array() );
		$stored = $app->bound( SettingsStoreInterface::class )
			? (array) $app->make( SettingsStoreInterface::class )->all()
			: array();

		$injection = self::flag( $stored, $config, 'enable_prompt_injection_detector', 'prompt_injection_detector' );
		$pii       = self::flag( $stored, $config, 'enable_pii_filter', 'pii_filter' );

		if ( ! $injection && ! $pii ) {
			return null;
		}

		$strictness = (string) ( $stored['prompt_injection_sensitivity'] ?? $config['prompt_injection_sensitivity'] ?? PromptInjectionDetector::STRICTNESS_MEDIUM );

		return new InputGuard(
			mode: self::mode( $stored, $config ),
			strictness: in_array( $strictness, array( PromptInjectionDetector::STRICTNESS_LOW, PromptInjectionDetector::STRICTNESS_MEDIUM, PromptInjectionDetector::STRICTNESS_HIGH ), true )
				? $strictness
				: PromptInjectionDetector::STRICTNESS_MEDIUM,
			piiEnabled: $pii,
			injectionEnabled: $injection,
		);
	}

	/**
	 * A stored flag, else the legacy config flag, else off.
	 *
	 * `filter_var` semantics rather than a cast: the settings table holds "0" and
	 * "1" for booleans, and `(bool) 'false'` is `true` — a cast would read a
	 * disabled control as enabled in any deployment that stored the word.
	 *
	 * @param array<string, mixed> $stored
	 * @param array<string, mixed> $config
	 */
	private static function flag( array $stored, array $config, string $key, string $legacy ): bool {
		if ( array_key_exists( $key, $stored ) ) {
			return filter_var( $stored[ $key ], FILTER_VALIDATE_BOOLEAN );
		}

		return filter_var( $config[ $legacy ] ?? false, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * The guard mode, validated against the three the guard understands.
	 *
	 * An unrecognised mode must not fall through to the guard's own default in a
	 * way that changes strength unpredictably: `InputGuard::inspect()` treats
	 * anything that is not `log` as blocking-capable, so a typo in a stored value
	 * would silently escalate. Unknown means `log`, which never blocks.
	 *
	 * @param array<string, mixed> $stored
	 * @param array<string, mixed> $config
	 */
	private static function mode( array $stored, array $config ): string {
		$mode = (string) ( $stored['prompt_injection_mode'] ?? $config['prompt_injection_mode'] ?? InputGuard::MODE_LOG );

		return in_array( $mode, array( InputGuard::MODE_LOG, InputGuard::MODE_WARN, InputGuard::MODE_BLOCK ), true )
			? $mode
			: InputGuard::MODE_LOG;
	}

	/**
	 * Build the AI-safety output guard from config.
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

	/**
	 * The host's per-tool capability-flag additions, when a host binds them.
	 *
	 * The three flag-reading guards (read-only, chat profile, necessity) take this
	 * as an optional dependency. Absent binding means "no overrides", which is the
	 * same behaviour every runtime had before the port, so a host that does not
	 * implement the contract is unaffected.
	 *
	 * The contract is **addition-only** (see
	 * {@see CapabilityFlagOverridesInterface}): a host can make a tool stricter,
	 * never looser, and an undeclared tool stays undeclared. That is what keeps a
	 * stored override from becoming an authority grant.
	 *
	 * @param \Illuminate\Contracts\Foundation\Application $app Container.
	 * @return CapabilityFlagOverridesInterface|null
	 */
	private function flagOverrides( $app ): ?CapabilityFlagOverridesInterface {
		return $app->bound( CapabilityFlagOverridesInterface::class )
			? $app->make( CapabilityFlagOverridesInterface::class )
			: null;
	}
}
