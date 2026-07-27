<?php
/**
 * OOS Bridge — wires the framework-agnostic extraction core into WordPress.
 *
 * This file is loaded during wp_mcp_ai_bootstrapped (priority 12, after the
 * main plugin bootstrap but before addon registration). It:
 *
 *  1. Registers PSR-4 autoloading for lib/core and lib/wordpress-adapter
 *  2. Wires all 8 WordPress adapter implementations to domain interfaces
 *  3. Constructs the ChatOrchestrator with injected adapters
 *  4. Exposes the orchestrator via a global function
 *
 * The existing plugin path is completely unaffected — this is additive only.
 * The new engine is activated via query parameter ?engine=oos or header
 * X-WP-MCP-AI-Engine: oos. Without the flag, existing behavior is unchanged.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only activate on PHP 8.1+ (the core package requires readonly/enums/fibers).
if ( PHP_VERSION_ID < 80100 ) {
	return;
}

// Guard: the lib/ directory is excluded from WordPress.org base builds.
// Only the full (complete) build ships with the cross-platform extraction.
// Bail early when lib/ is absent so we don't crash on partial deployments.
$lib_core_dir    = WP_MCP_AI_PATH . 'lib/core/src/';
$lib_adapter_dir = WP_MCP_AI_PATH . 'lib/wordpress-adapter/src/';
if ( ! is_dir( $lib_core_dir ) || ! is_dir( $lib_adapter_dir ) ) {
	return;
}

// ─── PSR-4 Autoloader ─────────────────────────────────────────────────

// Register PSR-4 autoloading for the extraction packages if not already
// handled by Composer. This is a no-op if composer autoload is present.
if ( ! class_exists( 'Nvoos\Core\Domain\Contract\ErrorFactoryInterface' ) ) {
	spl_autoload_register(
		function ( string $class_name ): void {
			$prefixes = array(
				'Nvoos\\Core\\'      => WP_MCP_AI_PATH . 'lib/core/src/',
				'Nvoos\\WordPress\\' => WP_MCP_AI_PATH . 'lib/wordpress-adapter/src/',
			);

			foreach ( $prefixes as $prefix => $base_dir ) {
				$len = strlen( $prefix );
				if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
					continue;
				}

				$relative_class = substr( $class_name, $len );
				$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

				if ( file_exists( $file ) ) {
					require $file;
					return;
				}
			}
		}
	);
}

// ─── Adapter Wiring ───────────────────────────────────────────────────

/**
 * Build the framework-agnostic ChatOrchestrator using WordPress adapters.
 *
 * This factory wires all 8 domain interfaces to their WordPress
 * implementations, constructs the 12 provider clients, registers them
 * with the ProviderRouter, and returns a fully functional orchestrator.
 *
 * @return Nvoos\Core\Application\Chat\ChatOrchestrator
 */
function wp_mcp_ai_oos_orchestrator() {
	static $orchestrator = null;

	if ( null !== $orchestrator ) {
		return $orchestrator;
	}

	// ─── Adapters ──────────────────────────────────────────────────

	$error_factory = new Nvoos\WordPress\Adapter\ErrorFactory();
	$settings      = new Nvoos\WordPress\Adapter\SettingsStore();
	$content       = new Nvoos\WordPress\Adapter\ContentStore();
	$auth          = new Nvoos\WordPress\Adapter\AuthProvider();
	$files         = new Nvoos\WordPress\Adapter\FileStore();
	$cache         = new Nvoos\WordPress\Adapter\CacheStore( (bool) wp_using_ext_object_cache() );
	$queue         = new Nvoos\WordPress\Adapter\QueueClient();
	$events        = new Nvoos\WordPress\Adapter\EventDispatcher();
	$schema        = new Nvoos\WordPress\Adapter\SchemaStore();
	$image_proc    = new Nvoos\WordPress\Adapter\ImageProcessing();
	$memory        = new Nvoos\WordPress\Adapter\MemoryStore();
	$transcripts   = new Nvoos\WordPress\Adapter\TranscriptStore();

	// Map existing wp_mcp_ai_* hooks to the event dispatcher for backward compat.
	$events->mapEventToHook(
		'Nvoos\\Core\\Domain\\Event\\BeforeToolExecution',
		'wp_mcp_ai_before_tool_execution'
	);
	$events->mapEventToHook(
		'Nvoos\\Core\\Domain\\Event\\AfterToolExecution',
		'wp_mcp_ai_after_tool_execution'
	);
	$events->mapEventToHook(
		'Nvoos\\Core\\Domain\\Event\\BeforeChatRequest',
		'wp_mcp_ai_before_chat_request'
	);
	$events->mapEventToHook(
		'Nvoos\\Core\\Domain\\Event\\AfterChatResponse',
		'wp_mcp_ai_after_chat_response'
	);

	// ─── HTTP Client (PSR-18) ──────────────────────────────────────

	// Use WordPress-native HTTP adapter that implements HttpClientInterface.
	$http_client = new Nvoos\WordPress\Adapter\HttpClient();

	// ─── Provider Clients ──────────────────────────────────────────

	$router = new Nvoos\Core\Application\Provider\ProviderRouter( $settings, $error_factory );

	// Register all 12 providers.
	$router->register( new Nvoos\Core\Infrastructure\Provider\OpenAiClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\GeminiClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\AnthropicClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\DeepSeekClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\OpenRouterClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\KimiClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\OllamaClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\LmStudioClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\DigitalOceanClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\NvidiaNimClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\CloudflareClient( $settings, $http_client, $error_factory ) );
	$router->register( new Nvoos\Core\Infrastructure\Provider\HuggingFaceClient( $settings, $http_client, $error_factory ) );

	// Streaming/realtime providers (separate interface — not registered with text router).
	// Available for direct instantiation:
	//   new Nvoos\Core\Infrastructure\Provider\OpenAIRealtimeProvider($settings, $http_client, $error_factory)
	//   new Nvoos\Core\Infrastructure\Provider\GeminiLiveProvider($settings, $http_client, $error_factory)

	// ─── Core Services ─────────────────────────────────────────────

	$tool_registry = new Nvoos\Core\Application\Tool\ToolRegistry( $events, $error_factory );

	// ─── Register migrated framework-agnostic tools ────────────────
	// Tier 1: External API / public data tools.
	$tool_registry->register( new Nvoos\Core\Tool\WebSearchTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetGdacsEventsTool( $error_factory, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetNhcActiveStormsTool( $error_factory, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetOpenMeteoForecastTool( $error_factory, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\ReliefwebReportsTool( $error_factory, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetModelInformationTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\ListAvailableModelsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\ModerateContentTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\CreateTextEmbeddingsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\SuggestBestModelTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\CountTokensTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetPostTaxonomiesTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\CountPostsTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\TruncateTextTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\MathEvalTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ColorConvertTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetSettingTool( $error_factory, $settings ) );
	$tool_registry->register( new Nvoos\Core\Tool\UpdateSettingTool( $error_factory, $settings ) );
	$tool_registry->register( new Nvoos\Core\Tool\ListSettingsTool( $error_factory, $settings ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateSlugTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\FormatBytesTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\StripHtmlTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\CheckCapabilityTool( $error_factory, $auth ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetCurrentUserTool( $error_factory, $auth ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateUuidTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\HashStringTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ValidateJsonTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\EnqueueJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetJobStatusTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\UploadFileTool( $error_factory, $files ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetFileInfoTool( $error_factory, $files ) );
	$tool_registry->register( new Nvoos\Core\Tool\DeleteFileTool( $error_factory, $files ) );
	$tool_registry->register( new Nvoos\Core\Tool\Base64Tool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ExtractDomainTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\DeleteSettingTool( $error_factory, $settings ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetCacheTool( $error_factory, $cache ) );
	$tool_registry->register( new Nvoos\Core\Tool\SetCacheTool( $error_factory, $cache ) );
	$tool_registry->register( new Nvoos\Core\Tool\DeleteCacheTool( $error_factory, $cache ) );
	$tool_registry->register( new Nvoos\Core\Tool\IncrementCacheTool( $error_factory, $cache ) );
	$tool_registry->register( new Nvoos\Core\Tool\DispatchEventTool( $error_factory, $events ) );
	$tool_registry->register( new Nvoos\Core\Tool\CancelJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\ScheduleJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\UnscheduleJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\ListJobsTool( $error_factory, $queue ) );

	// Cron job management tools.
	$tool_registry->register( new Nvoos\Core\Tool\CreateCronJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\CreateCronJobValidatedTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\DeleteCronJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\ListCronJobsTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetCronJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetPostMetaTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\FormatDateTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\TimeAgoTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\MergeArraysTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ParseCsvTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\DeepResearchTool( $error_factory, $settings ) );
	$tool_registry->register( new Nvoos\Core\Tool\ProbeRemoteMcpTool( $error_factory, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\RunCrawl4AiJobTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\Crawl4AiPriceLookupTool( $error_factory, $settings, $http_client ) );

	// HuggingFace dataset tools.
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetSearchTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetGetInfoTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetGetRowsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetGetSizeTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetGetStatisticsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetIsValidTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetListSplitsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetFilterTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetGetParquetTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceDatasetPreviewRowsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\HuggingFaceRecommendedDatasetsTool( $error_factory, $settings, $http_client ) );

	// Client-side tools.
	$tool_registry->register( new Nvoos\Core\Tool\ClientAnalyzeSentimentTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ClientSummarizeTextTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ClientTranslateTextTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ClientExtractEntitiesTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ClientQuestionAnsweringTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ClientSemanticSearchTool( $error_factory ) );

	// Content tools (use WordPress ContentStore adapter).
	$tool_registry->register( new Nvoos\Core\Tool\GetPostTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetRecentPostsTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\SearchContentTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\CreatePostTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\UpdatePostTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\DeletePostTool( $error_factory, $content ) );

	// Content variant tools (validated / upsert).
	$tool_registry->register( new Nvoos\Core\Tool\CreatePostValidatedTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetRecentPostsValidatedTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\SavePostTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\SavePostValidatedTool( $error_factory, $content ) );
	$tool_registry->register( new Nvoos\Core\Tool\SearchContentValidatedTool( $error_factory, $content ) );

	// User tools (use WordPress AuthProvider adapter).
	$tool_registry->register( new Nvoos\Core\Tool\GetUserInfoTool( $error_factory, $auth ) );

	// Skill tools.
	$skill_registry = new Nvoos\Core\Application\Skill\SkillRegistry();
	$tool_registry->register( new Nvoos\Core\Tool\LoadSkillTool( $error_factory, $skill_registry ) );
	$tool_registry->register( new Nvoos\Core\Tool\ListSkillsTool( $error_factory, $skill_registry ) );

	// File tools (use WordPress FileStore adapter).
	$tool_registry->register( new Nvoos\Core\Tool\SearchAttachmentsTool( $error_factory, $files ) );

	// Geo tools.
	$tool_registry->register( new Nvoos\Core\Tool\GeocodeAddressTool( $error_factory, $settings, $http_client ) );

	// Audio generation and transcription tools.
	$tool_registry->register( new Nvoos\Core\Tool\GenerateOpenAISpeechTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateOpenAISpeechValidatedTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateMusicTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateMusicValidatedTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\TranscribeOpenAIAudioTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\TranscribeOpenAIAudioValidatedTool( $error_factory, $settings, $http_client ) );

	// Video generation and analysis tools.
	$tool_registry->register( new Nvoos\Core\Tool\CheckVideoStatusTool( $error_factory, $queue ) );
	$tool_registry->register( new Nvoos\Core\Tool\AnalyzeVideoTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateVideoCaptionTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateSoraVideoTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateVeoVideoTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateOmniVideoTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\EditOmniVideoTool( $error_factory, $settings, $http_client ) );

	// Image generation and analysis tools.
	$tool_registry->register( new Nvoos\Core\Tool\GenerateOpenAIImageTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateOpenAIImageValidatedTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateGeminiImageTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateGeminiImageValidatedTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\EditOpenAIImageTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\EditGeminiImageTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\CreateImageVariationTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\AnalyzeImageTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateImageAltTextTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateImageCaptionTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ExtractImageTextTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\VisionObjectLocalizationTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\VisionProductSearchTool( $error_factory ) );

	// Batch processing tools (OpenAI Batch API).
	$tool_registry->register( new Nvoos\Core\Tool\CreateBatchTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\ListBatchesTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetBatchStatusTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\MonitorBatchTool( $error_factory, $settings, $http_client ) );

	// Chart, diagram, and misc visualization tools.
	$tool_registry->register( new Nvoos\Core\Tool\GenerateChartTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\CreateChartTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\CreateChartValidatedTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateMermaidTool( $error_factory ) );

	// Cloudflare AI image generation.
	$tool_registry->register( new Nvoos\Core\Tool\GenerateCloudflareAIImageTool( $error_factory, $settings, $http_client ) );

	// Validated wrappers.
	$tool_registry->register( new Nvoos\Core\Tool\WebSearchValidatedTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateImageAltTextValidatedTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateImageCaptionValidatedTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\EditGeminiImageValidatedTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateSoraVideoValidatedTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateVeoVideoValidatedTool( $error_factory, $settings, $http_client ) );

	// Image manipulation tools (use WordPress ImageProcessing adapter).
	$tool_registry->register( new Nvoos\Core\Tool\ConvertImageFormatTool( $error_factory, $image_proc ) );
	$tool_registry->register( new Nvoos\Core\Tool\CropImageTool( $error_factory, $image_proc ) );
	$tool_registry->register( new Nvoos\Core\Tool\ResizeImageTool( $error_factory, $image_proc ) );
	$tool_registry->register( new Nvoos\Core\Tool\RotateImageTool( $error_factory, $image_proc ) );

	// Content analysis and prompt-builder tools.
	$tool_registry->register( new Nvoos\Core\Tool\GeneratePostExcerptTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\AutoCategorizeContentTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ContentFreshnessCheckerTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\SuggestInternalLinksTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\AnalyzeCodeSequenceTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\AnalyzeCommentContentTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\AnalyzeFileSuitabilityTool( $error_factory ) );

	// External search and API tools.
	$tool_registry->register( new Nvoos\Core\Tool\SearchDriveTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\SearchGmailTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\SearchPlacesTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GeminiGeospatialQueryTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\QueryRemoteSiteTool( $error_factory, $settings, $http_client ) );

	// Memory and context tools (use WordPress MemoryStore adapter).
	$tool_registry->register( new Nvoos\Core\Tool\RecallMemoryTool( $error_factory, $memory ) );
	$tool_registry->register( new Nvoos\Core\Tool\StoreAgentContextTool( $error_factory, $memory ) );
	$tool_registry->register( new Nvoos\Core\Tool\RetrieveAgentMemoryTool( $error_factory, $memory ) );
	$tool_registry->register( new Nvoos\Core\Tool\MineAgentMemoryTool( $error_factory, $memory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ManageContextLifecycleTool( $error_factory, $memory ) );
	$tool_registry->register( new Nvoos\Core\Tool\SemanticContextSearchTool( $error_factory, $memory ) );
	$tool_registry->register( new Nvoos\Core\Tool\SemanticContentSearchTool( $error_factory, $memory ) );

	// Agent orchestration tools.
	$orchestration = new Nvoos\WordPress\Adapter\AgentOrchestration();
	$tool_registry->register( new Nvoos\Core\Tool\CreateAgentTeamTool( $error_factory, $orchestration ) );
	$tool_registry->register( new Nvoos\Core\Tool\DelegateToAgentTool( $error_factory, $orchestration ) );
	$tool_registry->register( new Nvoos\Core\Tool\ExecuteWorkflowTool( $error_factory, $orchestration ) );
	$tool_registry->register( new Nvoos\Core\Tool\CheckWorkflowHealthTool( $error_factory, $orchestration ) );
	$tool_registry->register( new Nvoos\Core\Tool\ValidateWorkflowTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\ValidateReasoningChainTool( $error_factory ) );

	// Specialized tools.
	$tool_registry->register( new Nvoos\Core\Tool\WaitForUserTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\OpenOpenAILogsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\ListOpenAIFilesTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetOpenAIFileDetailsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\RunOpenAIExternalActionTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateAuth0TokenTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GenerateSimpleJWTTokenTool( $error_factory ) );

	// Profession tools.
	$professions = new Nvoos\WordPress\Adapter\ProfessionRepository();
	$tool_registry->register( new Nvoos\Core\Tool\GetProfessionTool( $error_factory, $professions ) );
	$tool_registry->register( new Nvoos\Core\Tool\ListProfessionsTool( $error_factory, $professions ) );
	$tool_registry->register( new Nvoos\Core\Tool\ProfessionStatsTool( $error_factory, $professions ) );
	$tool_registry->register( new Nvoos\Core\Tool\SaveProfessionTool( $error_factory, $professions ) );

	// Email tools.
	$email = new Nvoos\WordPress\Adapter\EmailService();
	$tool_registry->register( new Nvoos\Core\Tool\SendGroupEmailTool( $error_factory, $email ) );
	$tool_registry->register( new Nvoos\Core\Tool\SendGroupEmailValidatedTool( $error_factory, $email ) );

	// Content recommendation and SEO tools.
	$tool_registry->register( new Nvoos\Core\Tool\ContentRecommendationEngineTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\BatchEmbedContentTool( $error_factory ) );
	$tool_registry->register( new Nvoos\Core\Tool\SEOMetaOptimizerTool( $error_factory ) );

	// OpenAI Vector Store tools.
	$tool_registry->register( new Nvoos\Core\Tool\CreateVectorStoreTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\GetVectorStoreTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\ListVectorStoresTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\ManageVectorStoreFilesTool( $error_factory, $settings, $http_client ) );

	// Cache purge tools.
	$tool_registry->register( new Nvoos\Core\Tool\PurgeCacheTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\PurgeCloudflareCacheTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\PurgeVarnishCacheTool( $error_factory, $settings, $http_client ) );

	// Erlang-C queuing theory tools.
	$erlang = wp_mcp_ai_oos_erlang_c();
	$tool_registry->register( new Nvoos\Core\Tool\CalculateErlangCTool( $error_factory, $erlang ) );
	$tool_registry->register( new Nvoos\Core\Tool\ErlangCConcurrencyAdvisorTool( $error_factory, $erlang, $settings ) );
	$tool_registry->register( new Nvoos\Core\Tool\ErlangCQueueHealthTool( $error_factory, $erlang, $settings, $http_client ) );
	$tool_registry->register( new Nvoos\Core\Tool\ErlangCStaffingAdvisorTool( $error_factory, $erlang, $settings, $http_client ) );

	// Site admin tools.
	$tool_registry->register( new Nvoos\Core\Tool\GetSiteSummaryTool( $error_factory, $settings ) );

	// Schema tools (use WordPress SchemaStore adapter).
	$tool_registry->register( new Nvoos\Core\Tool\GetPostTypeSchemaTool( $error_factory, $schema ) );

	// ─── WordPress adapter tools (platform-specific, call WP APIs directly) ───

	$tool_registry->register( new Nvoos\WordPress\Tool\ProbeChatTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\QueryMeshIntelligentTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\AggregateAgentResultsTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\VisualizeWorkflowMetricsTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\DelegateToA2aAgentTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\RunGeminiManagedAgentTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\ImageAltTextOptimizerTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\ImageFormatBatchConverterTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\MediaLibraryOptimizerTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\EvolveHarnessTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\VectorizeImageTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\CreateAssistantTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\CreateAssistantValidatedTool( $error_factory ) );
	$tool_registry->register( new Nvoos\WordPress\Tool\PerformanceOptimizerAssistantTool( $error_factory ) );

	$tool_registry->notifyRegistered();

	$sse   = new Nvoos\Core\Infrastructure\Streaming\SseHandler();
	$costs = new Nvoos\Core\Infrastructure\Cost\CostCalculator();

	$orchestrator = new Nvoos\Core\Application\Chat\ChatOrchestrator(
		$tool_registry,
		$router,
		$events,
		$error_factory,
		$costs,
		$sse,
	);

	return $orchestrator;
}

// ─── Feature Flag Detection ───────────────────────────────────────────

/**
 * Determine whether the OOS engine should handle the current request.
 *
 * Checks in order:
 *  1. X-WP-MCP-AI-Engine: oos header
 *  2. ?engine=oos query parameter
 *  3. WP_MCP_AI_OOS_ENGINE constant
 *
 * @return bool
 */
function wp_mcp_ai_oos_engine_enabled(): bool {
	// Check the admin setting first (Chat Client > Behavior > OOS Engine).
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( ! empty( $settings['enable_oos_engine'] ) ) {
		return true;
	}

	if ( defined( 'WP_MCP_AI_OOS_ENGINE' ) && WP_MCP_AI_OOS_ENGINE ) {
		return true;
	}

	if ( isset( $_SERVER['HTTP_X_WP_MCP_AI_ENGINE'] )
		&& 'oos' === $_SERVER['HTTP_X_WP_MCP_AI_ENGINE']
	) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['engine'] ) && 'oos' === $_GET['engine'] ) {
		return true;
	}

	return false;
}

// ─── Wave 1 Service Bridges ────────────────────────────────────────────

/**
 * Get the Semantic Compressor — framework-agnostic when engine=oos, legacy otherwise.
 *
 * @since 2.0.0
 *
 * @return Nvoos\Core\Domain\Contract\SemanticCompressorInterface
 */
function wp_mcp_ai_oos_semantic_compressor(): Nvoos\Core\Domain\Contract\SemanticCompressorInterface {
	static $instance = null;

	if ( null !== $instance ) {
		return $instance;
	}

	if ( wp_mcp_ai_oos_engine_enabled() ) {
		$instance = new Nvoos\WordPress\Adapter\SemanticCompressor();
	} else {
		// Legacy fallback — wraps the old class in the new interface.
		$instance = new class implements Nvoos\Core\Domain\Contract\SemanticCompressorInterface {
			public function compress( string $text, int $aggressiveness = 2, int $maxTokens = 0 ): array {
				$legacy = \WP_MCP_AI_Semantic_Compressor::get_instance();
				$compressed = $legacy->compress( $text, [
					'aggressiveness'     => max( 1, min( 3, $aggressiveness ) ),
					'skip_code_blocks'   => true,
					'preserve_specifics' => true,
				] );
				$originalBytes = strlen( $text );
				$compressedBytes = strlen( (string) $compressed );
				return [
					'compressed'        => (string) $compressed,
					'original_bytes'    => $originalBytes,
					'compressed_bytes'  => $compressedBytes,
					'compression_ratio' => $originalBytes > 0 ? round( $compressedBytes / $originalBytes, 4 ) : 1.0,
					'tokens_estimate'   => $this->estimateTokens( (string) $compressed ),
				];
			}

			public function estimateTokens( string $text ): int {
				return \WP_MCP_AI_Semantic_Compressor::get_instance()->estimate_tokens( $text );
			}

			public function isValidAggressiveness( int $level ): bool {
				return $level >= 1 && $level <= 3;
			}
		};
	}

	return $instance;
}

/**
 * Get the Data Budget Tracker — framework-agnostic when engine=oos, legacy otherwise.
 *
 * @since 2.0.0
 *
 * @param string $request_id Optional request identifier.
 * @return Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface
 */
function wp_mcp_ai_oos_data_budget_tracker( string $request_id = '' ): Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface {
	if ( wp_mcp_ai_oos_engine_enabled() ) {
		$instance = new Nvoos\WordPress\Adapter\DataBudgetTracker( $request_id );
	}

	// Legacy fallback — wraps the old class in the new interface.
	return new class( $request_id ) implements Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface {
		private \WP_MCP_AI_Data_Budget_Tracker $legacy;

		public function __construct( string $request_id ) {
			$this->legacy = new \WP_MCP_AI_Data_Budget_Tracker( $request_id );
		}

		public function getRequestBudget(): int { return $this->legacy->get_request_budget(); }
		public function getPerMessageBudget(): int { return $this->legacy->get_per_message_budget(); }
		public function record( int $bytes ): void { $this->legacy->record( $bytes ); }
		public function consumed(): int { return $this->legacy->consumed(); }
		public function remaining(): int { return $this->legacy->remaining(); }
		public function isExhausted(): bool { return $this->legacy->is_exhausted(); }
		public function shouldSpill( int $bytes ): bool { return $this->legacy->should_spill( $bytes ); }
		public function noteSpill(): void { $this->legacy->note_spill(); }
		public function spillCount(): int { return $this->legacy->spill_count(); }
		public function reset( string $requestId = '' ): void { $this->legacy->reset( $requestId ); }
	};
}

/**
 * Get the Erlang C calculator.
 *
 * @since 2.0.0
 * @return Nvoos\Core\Domain\Contract\ErlangCInterface
 */
function wp_mcp_ai_oos_erlang_c(): Nvoos\Core\Domain\Contract\ErlangCInterface {
	static $instance = null;
	if ( null !== $instance ) { return $instance; }
	if ( wp_mcp_ai_oos_engine_enabled() ) {
		$instance = new Nvoos\Core\Domain\Service\Optimization\ErlangC();
	} else {
		$instance = new class implements Nvoos\Core\Domain\Contract\ErlangCInterface {
			public function probabilityWait( float $ti, int $n ): float { return \WP_MCP_AI_Erlang_C::probability_wait( $ti, $n ); }
			public function serviceLevel( float $ti, int $n, float $aht, float $t ): float { return \WP_MCP_AI_Erlang_C::service_level( $ti, $n, $aht, $t ); }
			public function averageWaitTime( float $ti, int $n, float $aht ): float { return \WP_MCP_AI_Erlang_C::avg_wait_time( $ti, $n, $aht ); }
			public function minAgentsForServiceLevel( float $ti, float $aht, float $sl, float $t ): int { return \WP_MCP_AI_Erlang_C::min_agents_for_sl( $ti, $aht, $sl, $t ); }
			public function toErlangs( float $rate, float $aht ): float { return \WP_MCP_AI_Erlang_C::to_erlangs( $rate, $aht ); }
			public function utilisation( float $ti, int $n ): float { return \WP_MCP_AI_Erlang_C::utilisation( $ti, $n ); }
		};
	}
	return $instance;
}

/**
 * Get the Error Tracking Service.
 *
 * @since 2.0.0
 * @return Nvoos\Core\Domain\Contract\ErrorTrackingServiceInterface
 */
function wp_mcp_ai_oos_error_tracking(): Nvoos\Core\Domain\Contract\ErrorTrackingServiceInterface {
	static $instance = null;
	if ( null !== $instance ) { return $instance; }
	if ( wp_mcp_ai_oos_engine_enabled() ) {
		$instance = new Nvoos\WordPress\Adapter\ErrorTrackingService();
	} else {
		$instance = new class implements Nvoos\Core\Domain\Contract\ErrorTrackingServiceInterface {
			public function track( string $c, string $m, array $ctx = [] ): string {
				return (string) \WP_MCP_AI_Error_Tracking_Service::get_instance()->track_error( $c, $m, $ctx );
			}
			public function getRecent( int $limit = 50 ): array { return []; }
			public function getRate( string $c = '', int $w = 3600 ): float { return 0.0; }
			public function clear(): void {}
			public function isEnabled(): bool { return \class_exists( 'WP_MCP_AI_Error_Tracking_Service' ); }
		};
	}
	return $instance;
}

/**
 * Get the Cost Tracking Service.
 *
 * @since 2.0.0
 * @return Nvoos\Core\Domain\Contract\CostTrackingServiceInterface
 */
function wp_mcp_ai_oos_cost_tracking(): Nvoos\Core\Domain\Contract\CostTrackingServiceInterface {
	static $instance = null;
	if ( null !== $instance ) { return $instance; }
	if ( wp_mcp_ai_oos_engine_enabled() ) {
		$instance = new Nvoos\WordPress\Adapter\CostTrackingService();
	} else {
		$instance = new class implements Nvoos\Core\Domain\Contract\CostTrackingServiceInterface {
			public function getUserCostBreakdown( int $uid, string $s, string $e ): array {
				return \class_exists( 'WP_MCP_AI_Cost_Tracking_Service' )
					? \WP_MCP_AI_Cost_Tracking_Service::get_user_cost_breakdown( $uid, $s, $e )
					: [ 'total_cost' => 0.0, 'total_tokens' => 0, 'by_provider' => [], 'by_model' => [], 'by_tool' => [], 'by_date' => [] ];
			}
			public function getSiteCostBreakdown( string $s, string $e ): array {
				return \class_exists( 'WP_MCP_AI_Cost_Tracking_Service' )
					? \WP_MCP_AI_Cost_Tracking_Service::get_site_cost_breakdown( $s, $e )
					: [ 'total_cost' => 0.0, 'total_tokens' => 0, 'by_provider' => [], 'by_model' => [], 'by_tool' => [], 'by_date' => [], 'by_user' => [] ];
			}
		};
	}
	return $instance;
}

// Load Wave 2+ service bridges (separate file for maintainability).
$wave2_bridge = WP_MCP_AI_PATH . 'includes/bootstrap/oos-bridge-wave2.php';
if ( file_exists( $wave2_bridge ) ) {
	require_once $wave2_bridge;
}
// ─── Bootstrap Hook ───────────────────────────────────────────────────

add_action(
	'wp_mcp_ai_bootstrapped',
	function () {
		// Pre-warm the orchestrator so it's ready when a chat request arrives.
		// Wrap in a try-catch so a broken lib/ or missing dependency doesn't
		// crash the entire WordPress request.
		try {
			wp_mcp_ai_oos_orchestrator();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[WP_MCP_AI] OOS orchestrator pre-warm failed: %s in %s:%d',
						$e->getMessage(),
						$e->getFile(),
						$e->getLine()
					)
				);
			}
		}
	},
	12
);
