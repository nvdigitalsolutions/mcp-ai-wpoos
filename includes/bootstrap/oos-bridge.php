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
if ( ! class_exists( 'Oos\Core\Domain\Contract\ErrorFactoryInterface' ) ) {
	spl_autoload_register(
		function ( string $class_name ): void {
			$prefixes = array(
				'Oos\\Core\\'      => WP_MCP_AI_PATH . 'lib/core/src/',
				'Oos\\WordPress\\' => WP_MCP_AI_PATH . 'lib/wordpress-adapter/src/',
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
 * @return Oos\Core\Application\Chat\ChatOrchestrator
 */
function wp_mcp_ai_oos_orchestrator() {
	static $orchestrator = null;

	if ( null !== $orchestrator ) {
		return $orchestrator;
	}

	// ─── Adapters ──────────────────────────────────────────────────

	$error_factory = new Oos\WordPress\Adapter\ErrorFactory();
	$settings      = new Oos\WordPress\Adapter\SettingsStore();
	$content       = new Oos\WordPress\Adapter\ContentStore();
	$auth          = new Oos\WordPress\Adapter\AuthProvider();
	$files         = new Oos\WordPress\Adapter\FileStore();
	$cache         = new Oos\WordPress\Adapter\CacheStore( (bool) wp_using_ext_object_cache() );
	$queue         = new Oos\WordPress\Adapter\QueueClient();
	$events        = new Oos\WordPress\Adapter\EventDispatcher();

	// Map existing wp_mcp_ai_* hooks to the event dispatcher for backward compat.
	$events->mapEventToHook(
		'Oos\\Core\\Domain\\Event\\BeforeToolExecution',
		'wp_mcp_ai_before_tool_execution'
	);
	$events->mapEventToHook(
		'Oos\\Core\\Domain\\Event\\AfterToolExecution',
		'wp_mcp_ai_after_tool_execution'
	);
	$events->mapEventToHook(
		'Oos\\Core\\Domain\\Event\\BeforeChatRequest',
		'wp_mcp_ai_before_chat_request'
	);
	$events->mapEventToHook(
		'Oos\\Core\\Domain\\Event\\AfterChatResponse',
		'wp_mcp_ai_after_chat_response'
	);

	// ─── HTTP Client (PSR-18) ──────────────────────────────────────

	// Use Symfony HttpClient with PSR-18 adapter since it's already a dependency.
	$http_client = new \Symfony\Component\HttpClient\Psr18Client();

	// ─── Provider Clients ──────────────────────────────────────────

	$router = new Oos\Core\Application\Provider\ProviderRouter( $settings, $error_factory );

	// Register all 12 providers.
	$router->register( new Oos\Core\Infrastructure\Provider\OpenAiClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\GeminiClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\AnthropicClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\DeepSeekClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\OpenRouterClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\KimiClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\OllamaClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\LmStudioClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\DigitalOceanClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\NvidiaNimClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\CloudflareClient( $settings, $http_client, $error_factory ) );
	$router->register( new Oos\Core\Infrastructure\Provider\HuggingFaceClient( $settings, $http_client, $error_factory ) );

	// ─── Core Services ─────────────────────────────────────────────

	$tool_registry = new Oos\Core\Application\Tool\ToolRegistry( $events, $error_factory );

	// ─── Register migrated framework-agnostic tools ────────────────
	// Tier 1: External API / public data tools.
	$tool_registry->register( new Oos\Core\Tool\WebSearchTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\GetGdacsEventsTool( $error_factory, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\GetNhcActiveStormsTool( $error_factory, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\GetOpenMeteoForecastTool( $error_factory, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\ReliefwebReportsTool( $error_factory, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\GetModelInformationTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\ListAvailableModelsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\ModerateContentTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\CreateTextEmbeddingsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\SuggestBestModelTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\CountTokensTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\GetSettingTool( $error_factory, $settings ) );
	$tool_registry->register( new Oos\Core\Tool\UpdateSettingTool( $error_factory, $settings ) );
	$tool_registry->register( new Oos\Core\Tool\ListSettingsTool( $error_factory, $settings ) );
	$tool_registry->register( new Oos\Core\Tool\GenerateSlugTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\FormatBytesTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\StripHtmlTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\CheckCapabilityTool( $error_factory, $auth ) );
	$tool_registry->register( new Oos\Core\Tool\GetCurrentUserTool( $error_factory, $auth ) );
	$tool_registry->register( new Oos\Core\Tool\GenerateUuidTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\HashStringTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\ValidateJsonTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\EnqueueJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Oos\Core\Tool\GetJobStatusTool( $error_factory, $queue ) );
	$tool_registry->register( new Oos\Core\Tool\UploadFileTool( $error_factory, $files ) );
	$tool_registry->register( new Oos\Core\Tool\GetFileInfoTool( $error_factory, $files ) );
	$tool_registry->register( new Oos\Core\Tool\DeleteFileTool( $error_factory, $files ) );
	$tool_registry->register( new Oos\Core\Tool\Base64Tool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\ExtractDomainTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\DeleteSettingTool( $error_factory, $settings ) );
	$tool_registry->register( new Oos\Core\Tool\GetCacheTool( $error_factory, $cache ) );
	$tool_registry->register( new Oos\Core\Tool\SetCacheTool( $error_factory, $cache ) );
	$tool_registry->register( new Oos\Core\Tool\DeleteCacheTool( $error_factory, $cache ) );
	$tool_registry->register( new Oos\Core\Tool\IncrementCacheTool( $error_factory, $cache ) );
	$tool_registry->register( new Oos\Core\Tool\DispatchEventTool( $error_factory, $events ) );
	$tool_registry->register( new Oos\Core\Tool\CancelJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Oos\Core\Tool\ScheduleJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Oos\Core\Tool\UnscheduleJobTool( $error_factory, $queue ) );
	$tool_registry->register( new Oos\Core\Tool\ListJobsTool( $error_factory, $queue ) );
	$tool_registry->register( new Oos\Core\Tool\GetPostMetaTool( $error_factory, $content ) );
	$tool_registry->register( new Oos\Core\Tool\FormatDateTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\TimeAgoTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\MergeArraysTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\ParseCsvTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\DeepResearchTool( $error_factory, $settings ) );
	$tool_registry->register( new Oos\Core\Tool\ProbeRemoteMcpTool( $error_factory, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\RunCrawl4AiJobTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\Crawl4AiPriceLookupTool( $error_factory, $settings, $http_client ) );

	// HuggingFace dataset tools.
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetSearchTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetGetInfoTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetGetRowsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetGetSizeTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetGetStatisticsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetIsValidTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetListSplitsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetFilterTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetGetParquetTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceDatasetPreviewRowsTool( $error_factory, $settings, $http_client ) );
	$tool_registry->register( new Oos\Core\Tool\HuggingFaceRecommendedDatasetsTool( $error_factory, $settings, $http_client ) );

	// Client-side tools.
	$tool_registry->register( new Oos\Core\Tool\ClientAnalyzeSentimentTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\ClientSummarizeTextTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\ClientTranslateTextTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\ClientExtractEntitiesTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\ClientQuestionAnsweringTool( $error_factory ) );
	$tool_registry->register( new Oos\Core\Tool\ClientSemanticSearchTool( $error_factory ) );

	// Content tools (use WordPress ContentStore adapter).
	$tool_registry->register( new Oos\Core\Tool\GetPostTool( $error_factory, $content ) );
	$tool_registry->register( new Oos\Core\Tool\GetRecentPostsTool( $error_factory, $content ) );
	$tool_registry->register( new Oos\Core\Tool\SearchContentTool( $error_factory, $content ) );
	$tool_registry->register( new Oos\Core\Tool\CreatePostTool( $error_factory, $content ) );
	$tool_registry->register( new Oos\Core\Tool\UpdatePostTool( $error_factory, $content ) );
	$tool_registry->register( new Oos\Core\Tool\DeletePostTool( $error_factory, $content ) );

	// User tools (use WordPress AuthProvider adapter).
	$tool_registry->register( new Oos\Core\Tool\GetUserInfoTool( $error_factory, $auth ) );

	// Skill tools.
	$skill_registry = new Oos\Core\Application\Skill\SkillRegistry();
	$tool_registry->register( new Oos\Core\Tool\LoadSkillTool( $error_factory, $skill_registry ) );
	$tool_registry->register( new Oos\Core\Tool\ListSkillsTool( $error_factory, $skill_registry ) );

	// File tools (use WordPress FileStore adapter).
	$tool_registry->register( new Oos\Core\Tool\SearchAttachmentsTool( $error_factory, $files ) );

	// Geo tools.
	$tool_registry->register( new Oos\Core\Tool\GeocodeAddressTool( $error_factory, $settings, $http_client ) );

	// Site admin tools.
	$tool_registry->register( new Oos\Core\Tool\GetSiteSummaryTool( $error_factory, $settings ) );

	$tool_registry->notifyRegistered();

	$sse   = new Oos\Core\Infrastructure\Streaming\SseHandler();
	$costs = new Oos\Core\Infrastructure\Cost\CostCalculator();

	$orchestrator = new Oos\Core\Application\Chat\ChatOrchestrator(
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
