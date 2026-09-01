<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Rest;

use NvoosContentGraphAi\Chat\ChatResponseCache;
use NvoosContentGraphAi\Chat\ChatTranscriptRecorder;
use NvoosContentGraphAi\Chat\PromptOptimizer;
use NvoosContentGraphAi\Chat\SseRateLimiter;
use NvoosContentGraphAi\CoreBridge;

/**
 * REST API chat controller for the AI addon.
 *
 * Registers chat endpoints under the core's REST namespace
 * (`nvoos-content-graph/v1`) so they can be discovered alongside
 * the graph endpoints. Delegates all chat handling to
 * nvoos/core's ChatOrchestrator via CoreBridge.
 *
 * Routes:
 *  - POST /ai/chat          — chat (streaming or not)
 *  - GET  /ai/chat/config   — tester configuration (providers, defaults, tool presets)
 *  - GET  /ai/tools         — available tool slugs for the agentic loop
 *  - GET  /ai/providers     — registered provider slugs (backward compat)
 *
 * @since 1.0.0
 */
class ChatController {

	/**
	 * Register the chat routes.
	 *
	 * Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// POST /nvoos-content-graph/v1/ai/chat
		register_rest_route(
			'nvoos-content-graph/v1',
			'/ai/chat',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handleChat' ),
				// Guest tokens (X-WP-MCP-AI-Guest) grant access to the
				// public chat widget; logged-in users keep edit_posts
				// (D-UI-1a — additive).
				'permission_callback' => function ( \WP_REST_Request $request ): bool {
					if ( false !== \NvoosContentGraphAi\Chat\GuestToken::validate_request_guest_access( $request ) ) {
						return true;
					}

					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'messages'        => array(
						'required'          => true,
						'type'              => 'array',
						'sanitize_callback' => array( $this, 'sanitizeMessages' ),
					),
					'provider'        => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'model'           => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'temperature'     => array(
						'required'          => false,
						'type'              => 'number',
						'default'           => null,
						'validate_callback' => function ( $value ): bool {
							return null === $value || ( is_numeric( $value ) && (float) $value >= 0.0 && (float) $value <= 2.0 );
						},
					),
					'max_tokens'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => null,
						'validate_callback' => function ( $value ): bool {
							return null === $value || ( is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 128000 );
						},
					),
					'tools'           => array(
						'required'          => false,
						'type'              => 'array',
						'default'           => array(),
						'sanitize_callback' => array( $this, 'sanitizeTools' ),
					),
					'stream'          => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'system_prompt'   => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => true,
					),
					'include_context' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'cache_system_prompt' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		// GET /nvoos-content-graph/v1/ai/chat/config
		register_rest_route(
			'nvoos-content-graph/v1',
			'/ai/chat/config',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getChatConfig' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// GET /nvoos-content-graph/v1/ai/tools
		register_rest_route(
			'nvoos-content-graph/v1',
			'/ai/tools',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listTools' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// GET /nvoos-content-graph/v1/ai/providers (backward compat).
		register_rest_route(
			'nvoos-content-graph/v1',
			'/ai/providers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listProviders' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// GET /nvoos-content-graph/v1/ai/models
		register_rest_route(
			'nvoos-content-graph/v1',
			'/ai/models',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getModels' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'provider' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);
	}

	/**
	 * Handle a chat request via the core ChatOrchestrator.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handleChat( \WP_REST_Request $request ) {
		$bridge         = CoreBridge::instance();
		$includeContext = (bool) $request->get_param( 'include_context' );
		$messages       = $this->buildMessages(
			$request->get_param( 'messages' ),
			(bool) $request->get_param( 'system_prompt' ),
			$includeContext
		);

		// Prefix-cache optimisation (parity with the base plugin's
		// `cache_system_prompt` request option): reorder so the stable
		// system prompt leads and dynamic turns follow. Skipped when graph
		// context is merged into the system message — merged context is
		// dynamic content and must stay attached to its turn.
		if ( $request->get_param( 'cache_system_prompt' ) && ! $includeContext ) {
			$messages = PromptOptimizer::order_for_cache_hit(
				$messages,
				array(
					'system_prompt' => (string) $bridge->settings->get( 'ai_system_prompt', '' ),
				)
			);
		}

		$stream = (bool) $request->get_param( 'stream' );

		$options = $this->buildOptions( $request );

		// Tools are resolved by the orchestrator from the assistant config.
		$assistantConfig = array();
		$tools           = $this->filterAllowedTools( $request->get_param( 'tools' ) );
		if ( array() !== $tools ) {
			$assistantConfig['tools'] = $tools;
		}

		$userId      = \get_current_user_id();
		$assistantId = 0; // Not tied to a specific assistant post.

		// Cache options mirror the base plugin's `cache_system_prompt`
		// request option: caching only applies when the client opts in,
		// the temperature is unset or 0 (deterministic), and the request
		// is not streaming. The tool set is hashed in the cache key so
		// different tool sets never share a cached response.
		$toolSlugs = array();
		foreach ( $tools as $slug ) {
			$toolSlugs[] = array(
				'function' => array( 'name' => $slug ),
			);
		}
		$cacheOptions = array(
			'cache_system_prompt' => (bool) $request->get_param( 'cache_system_prompt' ),
			'assistant_id'        => $assistantId,
			'stream'              => false,
			'tools'               => $toolSlugs,
		);
		$temperature = $request->get_param( 'temperature' );
		if ( null !== $temperature ) {
			$cacheOptions['temperature'] = (float) $temperature;
		}

		if ( $stream ) {
			// Enforce SSE rate limits before opening a streaming connection
			// (parity with the base plugin's streaming entry point).
			$sseLimiter      = new SseRateLimiter();
			$rateLimitResult = $sseLimiter->check_connection_allowed();
			if ( \is_wp_error( $rateLimitResult ) ) {
				return $rateLimitResult; // WP_Error carries HTTP 429 data.
			}
			$sseToken = $sseLimiter->register_connection( $userId );

			// Delegate all streaming to ChatOrchestrator which uses
			// SseHandler for header setup, event dispatch, and DONE signal.
			$bridge->chat->handleChatStreaming(
				$messages,
				$assistantConfig,
				$userId,
				$assistantId,
				$options,
			);

			$sseLimiter->release_connection( $sseToken );

			exit;
		}

		// Check the response cache before making the LLM call
		// (parity with the base plugin's non-streaming path).
		$responseCache  = new ChatResponseCache();
		$cachedResponse = $responseCache->get_cached_response( $messages, $cacheOptions );
		if ( false !== $cachedResponse && is_array( $cachedResponse ) ) {
			return new \WP_REST_Response(
				array(
					'success'        => true,
					'data'           => $cachedResponse['response'] ?? array(),
					'tool_results'   => $cachedResponse['tool_results'] ?? array(),
					'iterations'     => $cachedResponse['iterations'] ?? 0,
					'cost'           => $cachedResponse['cost'] ?? null,
					'cache_metadata' => $cachedResponse['cache_metadata'] ?? null,
				),
				200
			);
		}

		// Non-streaming chat.
		$requestStart = \microtime( true );
		$result       = $bridge->chat->handleChat(
			$messages,
			$assistantConfig,
			$userId,
			$assistantId,
			$options,
		);

		$response = $result['response'] ?? array();

		if ( $bridge->errors->isError( $response ) ) {
			$normalized = $bridge->errors->normalize( $response );
			return new \WP_Error(
				$normalized['code'],
				$normalized['message'],
				array( 'status' => $normalized['data']['status'] ?? 500 )
			);
		}

		// Record the chat transcript when storage is available (the base
		// plugin's JetEngine CCT handler in monolith installs; a graceful
		// no-op in standalone installs until transcript storage lands).
		ChatTranscriptRecorder::record(
			$assistantId,
			$messages,
			$options,
			is_array( $response ) ? $response : array(),
			$request,
			$userId,
			array(
				'request_started_at'    => $requestStart,
				'response_completed_at' => \microtime( true ),
			)
		);

		// Store the result when the request is cache-eligible (no-op
		// otherwise; `cache_metadata` is only attached on a store).
		$responseCache->set_cached_response( $messages, $cacheOptions, $result );

		return new \WP_REST_Response(
			array(
				'success'        => true,
				'data'           => $response,
				'tool_results'   => $result['tool_results'] ?? array(),
				'iterations'     => $result['iterations'] ?? 0,
				'cost'           => $result['cost'] ?? null,
				'cache_metadata' => $result['cache_metadata'] ?? null,
			),
			200
		);
	}

	/**
	 * Tester configuration — providers (with credential state), defaults,
	 * and tool presets.
	 *
	 * @return \WP_REST_Response
	 */
	public function getChatConfig(): \WP_REST_Response {
		$bridge = CoreBridge::instance();

		$providers = array();
		foreach ( $bridge->providers->getRegisteredSlugs() as $slug ) {
			$providers[] = array(
				'slug'       => $slug,
				'label'      => $this->getProviderLabel( $slug ),
				'configured' => $bridge->settings->hasCredentials( $slug ),
			);
		}

		$contextMode = $this->graphContextMode();

		return new \WP_REST_Response(
			array(
				'success'                  => true,
				'providers'                => $providers,
				'default_provider'         => $bridge->settings->getDefaultProvider(),
				'default_model'            => $bridge->settings->getDefaultModel(),
				'temperature'              => (float) $bridge->settings->get( 'ai_temperature', 0.7 ),
				'max_tokens'               => (int) $bridge->settings->get( 'ai_max_tokens', 4096 ),
				'system_prompt_configured' => '' !== (string) $bridge->settings->get( 'ai_system_prompt', '' ),
				'graph_context_available'  => 'none' !== $contextMode,
				'graph_context_mode'       => $contextMode,
				'tool_presets'             => $this->getToolPresets(),
				'tools'                    => $this->getToolList(),
			),
			200
		);
	}

	/**
	 * List all tools available to the agentic loop (graph + AI).
	 *
	 * @return \WP_REST_Response
	 */
	public function listTools(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'tools'   => $this->getToolList(),
			),
			200
		);
	}

	/**
	 * List available AI providers.
	 *
	 * @return \WP_REST_Response
	 */
	public function listProviders(): \WP_REST_Response {
		$slugs = CoreBridge::instance()->getProviderSlugs();

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'providers' => $slugs,
			),
			200
		);
	}

	/**
	 * List models available for a provider, transient-cached for an hour.
	 *
	 * The `nvoos_content_graph_ai_models_list` filter can supply the list
	 * without an upstream call (used by tests and custom providers).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function getModels( \WP_REST_Request $request ) {
		$bridge = CoreBridge::instance();

		$provider = (string) $request->get_param( 'provider' );
		if ( '' === $provider ) {
			$provider = $bridge->settings->getDefaultProvider();
		}

		if ( null === $bridge->providers->get( $provider ) ) {
			return new \WP_Error(
				'provider_not_found',
				"AI provider '{$provider}' is not registered.",
				array( 'status' => 404 )
			);
		}

		$cacheKey = 'nvoos_content_graph_ai_models_' . \sanitize_key( $provider );
		$cached   = \get_transient( $cacheKey );
		if ( is_array( $cached ) ) {
			return $this->modelsResponse( $provider, $cached, true );
		}

		$models = \apply_filters( 'nvoos_content_graph_ai_models_list', null, $provider );
		if ( null === $models ) {
			$models = $bridge->providers->get( $provider )->listModels();
		}

		if ( $bridge->errors->isError( $models ) ) {
			$normalized = $bridge->errors->normalize( $models );
			return new \WP_Error(
				$normalized['code'],
				$normalized['message'],
				array( 'status' => $normalized['data']['status'] ?? 502 )
			);
		}

		if ( ! is_array( $models ) ) {
			$models = array();
		}

		$models = \array_values( \array_filter( \array_map( 'strval', $models ) ) );
		\set_transient( $cacheKey, $models, \HOUR_IN_SECONDS );

		return $this->modelsResponse( $provider, $models, false );
	}

	/**
	 * Build a models-list REST response.
	 *
	 * @param string   $provider Provider slug.
	 * @param string[] $models   Model ids.
	 * @param bool     $cached   Whether the list came from the transient cache.
	 * @return \WP_REST_Response
	 */
	private function modelsResponse( string $provider, array $models, bool $cached ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success'  => true,
				'provider' => $provider,
				'models'   => $models,
				'cached'   => $cached,
			),
			200
		);
	}

	/**
	 * Clear all cached model lists (called when plugin settings change).
	 *
	 * @return void
	 */
	public static function clearModelCache(): void {
		$bridge = CoreBridge::instance();
		foreach ( $bridge->providers->getRegisteredSlugs() as $slug ) {
			\delete_transient( 'nvoos_content_graph_ai_models_' . \sanitize_key( $slug ) );
		}
	}

	/**
	 * Build the orchestrator options array from the request.
	 *
	 * Temperature and max_tokens fall back to the Chat Behavior settings
	 * so the settings tab genuinely drives the tester.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array{provider?: string, model?: string, temperature?: float, max_tokens?: int}
	 */
	private function buildOptions( \WP_REST_Request $request ): array {
		$bridge  = CoreBridge::instance();
		$options = array();

		$provider = (string) $request->get_param( 'provider' );
		if ( '' !== $provider ) {
			$options['provider'] = $provider;
		}

		$model = (string) $request->get_param( 'model' );
		if ( '' !== $model ) {
			$options['model'] = $model;
		}

		$temperature = $request->get_param( 'temperature' );
		if ( null === $temperature ) {
			$temperature = $bridge->settings->get( 'ai_temperature', 0.7 );
		}
		if ( null !== $temperature ) {
			$options['temperature'] = (float) $temperature;
		}

		$maxTokens = $request->get_param( 'max_tokens' );
		if ( null === $maxTokens ) {
			$maxTokens = $bridge->settings->get( 'ai_max_tokens', 4096 );
		}
		if ( null !== $maxTokens ) {
			$options['max_tokens'] = (int) $maxTokens;
		}

		return $options;
	}

	/**
	 * Prepend the configured system prompt when enabled and not already
	 * present in the request. When graph context is requested, semantic
	 * search results for the latest user message are merged into the
	 * system content.
	 *
	 * Public so the integration tests can exercise it directly.
	 *
	 * @param array $messages        Sanitized OpenAI-format messages.
	 * @param bool  $useSystemPrompt Whether system prompt injection is enabled.
	 * @param bool  $includeContext  Whether to merge knowledge-graph context.
	 * @return array
	 */
	public function buildMessages( array $messages, bool $useSystemPrompt, bool $includeContext = false ): array {
		$context = $includeContext
			? $this->retrieveGraphContext( $this->lastUserText( $messages ) )
			: '';

		// An existing system message wins for the prompt; context is
		// appended to it when present.
		if ( $useSystemPrompt ) {
			foreach ( $messages as $i => $msg ) {
				if ( ! isset( $msg['role'] ) || 'system' !== $msg['role'] ) {
					continue;
				}

				if ( '' !== $context ) {
					$content                   = is_string( $msg['content'] ?? null ) ? $msg['content'] : '';
					$messages[ $i ]['content'] = rtrim( $content ) . "\n\n" . $context;
				}

				return $messages;
			}
		}

		$prompt = '';
		if ( $useSystemPrompt ) {
			$prompt = (string) CoreBridge::instance()->settings->get( 'ai_system_prompt', '' );
		}

		$systemContent = trim( $prompt . ( '' !== $context ? "\n\n" . $context : '' ) );
		if ( '' === $systemContent ) {
			return $messages;
		}

		\array_unshift(
			$messages,
			array(
				'role'    => 'system',
				'content' => $systemContent,
			)
		);

		return $messages;
	}

	/**
	 * Retrieve relevant knowledge-graph context for a query.
	 *
	 * Two tiers:
	 *  1. Semantic retrieval via the embeddings index (when enabled).
	 *  2. Keyword search over node labels — works without embeddings.
	 *
	 * The `nvoos_content_graph_ai_chat_context` filter can short-circuit
	 * retrieval (return a string to use it, return null to proceed).
	 *
	 * @param string $query Latest user query.
	 * @return string Context block, or '' when unavailable.
	 */
	private function retrieveGraphContext( string $query ): string {
		$override = \apply_filters( 'nvoos_content_graph_ai_chat_context', null, $query );
		if ( is_string( $override ) ) {
			return $override;
		}

		if ( '' === $query ) {
			return '';
		}

		// Tier 1 — semantic retrieval (only when an index actually exists,
		// so we never pay for an embedding call against an empty index).
		if ( $this->embeddingsEnabled() && $this->embeddingsIndexHasRows() ) {
			try {
				$context = CoreBridge::instance()->rag->buildContextPrompt( $query, 5 );
			} catch ( \Throwable $e ) {
				$context = '';
			}

			if ( is_string( $context ) && '' !== $context ) {
				return $context;
			}
		}

		// Tier 2 — keyword fallback.
		return $this->keywordGraphContext( $query );
	}

	/**
	 * Build a context block from a keyword search over node labels.
	 *
	 * @param string $query Latest user query.
	 * @return string Context block, or '' when nothing matches.
	 */
	private function keywordGraphContext( string $query ): string {
		if ( ! \class_exists( 'NvoosContentGraph\Graph\Db' ) ) {
			return '';
		}

		$maxNodes = 8;
		$nodes    = array();
		$seen     = array();

		// Whole query first — exact-ish phrase hits are the most relevant.
		foreach ( \NvoosContentGraph\Graph\Db::searchNodes( $query, '', $maxNodes ) as $row ) {
			if ( ! isset( $row->node_id ) || isset( $seen[ $row->node_id ] ) ) {
				continue;
			}
			$seen[ $row->node_id ] = true;
			$nodes[]               = $row;
		}

		// Then per-keyword, in case the full phrase missed.
		if ( \count( $nodes ) < $maxNodes ) {
			foreach ( $this->extractKeywords( $query ) as $keyword ) {
				foreach ( \NvoosContentGraph\Graph\Db::searchNodes( $keyword, '', 4 ) as $row ) {
					if ( \count( $nodes ) >= $maxNodes ) {
						break 2;
					}
					if ( ! isset( $row->node_id ) || isset( $seen[ $row->node_id ] ) ) {
						continue;
					}
					$seen[ $row->node_id ] = true;
					$nodes[]               = $row;
				}
			}
		}

		$context = "The following content from the website's knowledge graph may be relevant to the user's query:\n\n";
		$count   = 0;
		foreach ( $nodes as $row ) {
			$label = (string) ( $row->label ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$type     = (string) ( $row->type ?? '' );
			$context .= ( ++$count ) . '. [' . $type . '] ' . $label . "\n";
		}

		if ( 0 === $count ) {
			return '';
		}

		$context .= "\nUse this context to inform your response when it is relevant to the user's question.";

		return $context;
	}

	/**
	 * Split a query into significant lowercase keywords.
	 *
	 * @param string $query Raw query text.
	 * @return string[]
	 */
	private function extractKeywords( string $query ): array {
		$lower = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $query ) : \strtolower( $query );
		$words = \preg_split( '/[\s,;:.!?()\[\]{}"\']+/', $lower, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words ) {
			return array();
		}

		$stop = \array_flip(
			array(
				'the',
				'a',
				'an',
				'and',
				'or',
				'but',
				'of',
				'to',
				'in',
				'on',
				'for',
				'with',
				'is',
				'are',
				'was',
				'what',
				'how',
				'why',
				'who',
				'when',
				'where',
				'can',
				'you',
				'do',
				'does',
				'about',
				'my',
				'your',
				'our',
				'their',
				'this',
				'that',
				'it',
				'its',
				'me',
				'we',
				'he',
				'she',
				'they',
				'please',
				'show',
				'tell',
				'give',
				'find',
				'get',
				'from',
				'have',
				'has',
				'any',
				'some',
				'there',
				'here',
				'then',
				'than',
			)
		);

		$keywords = array();
		foreach ( $words as $word ) {
			$word   = \trim( $word );
			$length = \function_exists( 'mb_strlen' ) ? \mb_strlen( $word ) : \strlen( $word );
			if ( '' === $word || $length < 3 || isset( $stop[ $word ] ) ) {
				continue;
			}
			$keywords[] = $word;
		}

		return \array_values( \array_unique( $keywords ) );
	}

	/**
	 * How graph context will be retrieved for the tester.
	 *
	 * @return string One of 'none', 'keyword', or 'rag'.
	 */
	private function graphContextMode(): string {
		if ( ! \class_exists( 'NvoosContentGraph\Graph\Db' ) ) {
			return 'none';
		}

		try {
			$nodes = \NvoosContentGraph\Graph\Db::countNodes();
		} catch ( \Throwable $e ) {
			return 'none';
		}

		if ( $nodes <= 0 ) {
			return 'none';
		}

		return $this->embeddingsEnabled() ? 'rag' : 'keyword';
	}

	/**
	 * Whether embeddings are enabled in the parent plugin settings.
	 *
	 * @return bool
	 */
	private function embeddingsEnabled(): bool {
		if ( ! \class_exists( 'NvoosContentGraph\Settings' ) ) {
			return false;
		}

		$settings = \NvoosContentGraph\Settings::all();

		return ! empty( $settings['embeddings_enabled'] );
	}

	/**
	 * Whether the embeddings index contains any rows.
	 *
	 * @return bool
	 */
	private function embeddingsIndexHasRows(): bool {
		if ( ! \class_exists( 'NvoosContentGraph\Graph\Db' ) ) {
			return false;
		}

		global $wpdb;
		$table = \NvoosContentGraph\Graph\Db::embeddingsTable();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $count > 0;
	}

	/**
	 * Text of the most recent user message.
	 *
	 * @param array $messages Conversation messages.
	 * @return string
	 */
	private function lastUserText( array $messages ): string {
		for ( $i = \count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( isset( $messages[ $i ]['role'] ) && 'user' === $messages[ $i ]['role'] ) {
				$content = $messages[ $i ]['content'] ?? '';
				return is_string( $content ) ? \trim( $content ) : '';
			}
		}

		return '';
	}

	/**
	 * Filter requested tool slugs down to registered, enabled tools.
	 *
	 * @param array $slugs Raw slugs from the request.
	 * @return array
	 */
	private function filterAllowedTools( array $slugs ): array {
		$bridge  = CoreBridge::instance();
		$allowed = array();

		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			if ( $bridge->tools->has( $slug ) ) {
				$allowed[] = $slug;
			}
		}

		return \array_values( \array_unique( $allowed ) );
	}

	/**
	 * Tool presets exposed to the tester toolbar.
	 *
	 * @return array<int, array{slug: string, label: string, tools: string[]}>
	 */
	private function getToolPresets(): array {
		$graphTools = array();
		foreach ( $this->getToolList() as $tool ) {
			if ( 'graph' === $tool['group'] && ! $tool['destructive'] ) {
				$graphTools[] = $tool['slug'];
			}
		}

		return array(
			array(
				'slug'  => 'none',
				'label' => __( 'No tools', 'nvoos-content-graph-ai' ),
				'tools' => array(),
			),
			array(
				'slug'  => 'graph',
				'label' => __( 'Graph tools (read-only)', 'nvoos-content-graph-ai' ),
				'tools' => $graphTools,
			),
		);
	}

	/**
	 * Build the tool list for /ai/tools and the config endpoint.
	 *
	 * @return array<int, array{slug: string, name: string, group: string, destructive: bool}>
	 */
	private function getToolList(): array {
		$bridge = CoreBridge::instance();
		$list   = array();

		foreach ( $bridge->tools->enabled() as $slug => $tool ) {
			$list[] = array(
				'slug'        => $slug,
				'name'        => $tool->getName(),
				'group'       => 0 === \strpos( $slug, 'nvoos_content_graph_' ) ? 'graph' : 'ai',
				'destructive' => \in_array(
					$slug,
					array(
						'nvoos_content_graph_build_graph',
						'nvoos_content_graph_sync_remote_source',
					),
					true
				),
			);
		}

		\usort(
			$list,
			static function ( array $a, array $b ): int {
				return \strcmp( $a['slug'], $b['slug'] );
			}
		);

		return $list;
	}

	/**
	 * Human-readable label for a provider slug.
	 *
	 * @param string $slug Provider slug.
	 * @return string
	 */
	private function getProviderLabel( string $slug ): string {
		$map = array(
			'openai'       => __( 'OpenAI', 'nvoos-content-graph-ai' ),
			'gemini'       => __( 'Google Gemini', 'nvoos-content-graph-ai' ),
			'anthropic'    => __( 'Anthropic Claude', 'nvoos-content-graph-ai' ),
			'ollama'       => __( 'Ollama (local)', 'nvoos-content-graph-ai' ),
			'deepseek'     => __( 'DeepSeek', 'nvoos-content-graph-ai' ),
			'openrouter'   => __( 'OpenRouter', 'nvoos-content-graph-ai' ),
			'huggingface'  => __( 'HuggingFace', 'nvoos-content-graph-ai' ),
			'cloudflare'   => __( 'Cloudflare Workers AI', 'nvoos-content-graph-ai' ),
			'lm_studio'    => __( 'LM Studio (local)', 'nvoos-content-graph-ai' ),
			'nvidia_nim'   => __( 'NVIDIA NIM', 'nvoos-content-graph-ai' ),
			'digitalocean' => __( 'DigitalOcean', 'nvoos-content-graph-ai' ),
			'kimi'         => __( 'Kimi (Moonshot)', 'nvoos-content-graph-ai' ),
			'baseten'      => __( 'Baseten', 'nvoos-content-graph-ai' ),
		);

		return $map[ $slug ] ?? $slug;
	}

	/**
	 * Sanitize the messages array.
	 *
	 * @param array $messages Raw messages from the request.
	 * @return array Sanitized messages.
	 */
	public function sanitizeMessages( array $messages ): array {
		$sanitized = array();

		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) ) {
				continue;
			}

			$sanitized[] = array(
				'role'    => sanitize_text_field( $msg['role'] ?? 'user' ),
				'content' => wp_kses_post( $msg['content'] ?? '' ),
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize the tools array (list of slugs).
	 *
	 * @param array $tools Raw tools from the request.
	 * @return string[]
	 */
	public function sanitizeTools( array $tools ): array {
		return \array_values(
			\array_filter(
				\array_map(
					'sanitize_key',
					\array_filter( $tools, 'is_string' )
				)
			)
		);
	}
}
