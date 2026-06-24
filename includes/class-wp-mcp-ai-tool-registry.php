<?php
/**
 * Tool registry singleton.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-envelope.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-product-card.php';

if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
	/**
	 * Maintains a list of available tool providers.
	 */
	class WP_MCP_AI_Tool_Registry {
		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_Tool_Registry
		 */
		protected static $instance = null;

		/**
		 * Registered tools keyed by slug.
		 *
		 * @var WP_MCP_AI_Tool_Interface[]
		 */
		protected $tools = array();

		/**
		 * Whether the registry has been initialised.
		 *
		 * @var bool
		 */
		protected $bootstrapped = false;

		/**
		 * Human readable messages describing tools that were skipped.
		 *
		 * @var string[]
		 */
		protected $unavailable_tool_messages = array();

		/**
		 * Deprecated tool aliases keyed by the old slug.
		 *
		 * Used by Phase P5 Part 2 (tool decomposition) so that splitting a tool
		 * into focused sub-tools does not break assistants that still reference
		 * the old slug. Aliases are *not* exposed to the LLM payload assembler —
		 * the model only sees the new sub-tools — but they resolve transparently
		 * when an old slug is requested via {@see self::get_tool()}.
		 *
		 * Each entry is an array of the shape:
		 *   array(
		 *     'new_slug' => string,  // Required. Slug of the replacement tool.
		 *     'since'    => string,  // Optional. Version where the alias was introduced.
		 *     'remove'   => string,  // Optional. Version where the alias will be removed.
		 *     'message'  => string,  // Optional. Human-readable migration note.
		 *   )
		 *
		 * @since 1.2.2
		 * @var array<string, array{new_slug:string, since:string, remove:string, message:string}>
		 */
		protected $deprecated_aliases = array();

		/**
		 * Set of deprecated slugs whose invocation has already fired the
		 * {@see 'wp_mcp_ai_tool_deprecated_alias_invoked'} action during the
		 * current request, used to throttle the hook to once-per-(request, slug).
		 *
		 * @since 1.2.2
		 * @var array<string, bool>
		 */
		protected $deprecated_alias_invocations = array();

		/**
		 * Retrieve the singleton instance.
		 *
		 * @return WP_MCP_AI_Tool_Registry
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Prevent direct construction.
		 */
		protected function __construct() {}

		/**
		 * Prevent cloning.
		 */
		protected function __clone() {}

		/**
		 * Prevent unserialisation.
		 */
		public function __wakeup() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Double-underscore magic method (__wakeup/__clone) required by PHP serialization interface; PSR-2 exception for magic methods.

		/**
		 * Initialise the registry by loading default tools and triggering hooks.
		 */
		public function init() {
			if ( $this->bootstrapped ) {
				return;
			}

			$this->bootstrapped = true;

			$this->load_default_tools();

			if ( is_admin() && ! empty( $this->unavailable_tool_messages ) ) {
				// Register admin_notices on init to avoid early translation loading (WordPress 6.7.0+).
				add_action( 'init', array( $this, 'register_admin_notices' ) );
			}

			/**
			 * Allow third parties to register additional tools.
			 *
			 * @param WP_MCP_AI_Tool_Registry $registry Registry instance.
			 */
			do_action( 'wp_mcp_ai_register_tools', $this );

			// Auto-disable tools marked with "bug" status.
			$this->auto_disable_bug_tools();
		}

		/**
		 * Register admin notices on init action.
		 *
		 * WordPress 6.7.0+ requires translations to be loaded at init or later.
		 */
		public function register_admin_notices() {
			add_action( 'admin_notices', array( $this, 'render_unavailable_tool_notices' ) );
		}

		/**
		 * Render admin notices for tools that were skipped during registration.
		 */
		public function render_unavailable_tool_notices() {
			if ( empty( $this->unavailable_tool_messages ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! function_exists( 'get_current_screen' ) ) {
				return;
			}

			$screen = get_current_screen();
			if ( ! $screen ) {
				return;
			}

			$allowed_screens = array(
				'plugins',
				'plugins-network',
				'plugin-install',
				'plugin-install-network',
			);

			if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
				return;
			}

			foreach ( $this->unavailable_tool_messages as $message ) {
				if ( empty( $message ) ) {
					continue;
				}

				printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
			}
		}

		/**
		 * Register a tool implementation.
		 *
		 * @param string|WP_MCP_AI_Tool_Interface $tool Tool class name or instance.
		 * @return bool Whether the tool was registered.
		 */
		public function register_tool( $tool ) {
			if ( is_string( $tool ) ) {
				if ( ! class_exists( $tool ) ) {
					return false;
				}

				$tool = new $tool();
			}

			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				return false;
			}

			$slug = sanitize_key( $tool->get_slug() );

			if ( empty( $slug ) ) {
				return false;
			}

			$this->tools[ $slug ] = $tool;

			/**
			 * Fires after a tool is successfully registered.
			 *
			 * Used by the attention router to trigger async embedding
			 * pre-computation so the semantic head can score tools immediately
			 * on the next request.
			 *
			 * @since 1.8.0
			 *
			 * @param string $slug Tool slug.
			 * @param WP_MCP_AI_Tool_Interface $tool Tool instance.
			 */
			do_action( 'wp_mcp_ai_tool_registered', $slug, $tool );

			return true;
		}

		/**
		 * Unregister a tool by slug.
		 *
		 * @param string $slug Tool slug.
		 */
		public function unregister_tool( $slug ) {
			$slug = sanitize_key( $slug );
			unset( $this->tools[ $slug ] );
		}

		/**
		 * Clear all registered tools and reset bootstrap state.
		 *
		 * Used primarily in test tear-down to prevent tool leakage between
		 * tests that share the registry singleton. Also clears unavailable-tool
		 * messages and resets the bootstrapped flag so init() can safely be
		 * called again.
		 *
		 * @return void
		 */
		public function clear_tools() {
			$this->tools                     = array();
			$this->bootstrapped              = false;
			$this->unavailable_tool_messages = array();
		}

		/**
		 * Register a deprecated tool alias.
		 *
		 * When Phase P5 Part 2 decomposes a multi-action tool into focused
		 * sub-tools, the old slug is registered as an alias pointing at the most
		 * appropriate replacement so any assistant or saved tool-call referencing
		 * the old slug continues to function for one release cycle. Aliases are
		 * invisible to the LLM payload assembler (the model only sees the new
		 * sub-tools), so re-trained assistants will naturally migrate.
		 *
		 * The action {@see 'wp_mcp_ai_tool_deprecated_alias_invoked'} fires the
		 * first time per request that each alias is resolved, allowing OTel /
		 * activity-log subscribers to count and surface usage.
		 *
		 * @since 1.2.2
		 *
		 * @param string $old_slug Slug of the deprecated tool.
		 * @param string $new_slug Slug of the replacement tool.
		 * @param array  $args     Optional metadata.
		 *     @type string $since   Version where the alias was introduced (e.g. '1.3.0').
		 *     @type string $remove  Version where the alias will be removed (e.g. '1.4.0').
		 *     @type string $message Human-readable migration note.
		 * @return bool True on success, false if either slug is empty / identical.
		 */
		public function register_deprecated_alias( $old_slug, $new_slug, $args = array() ) {
			$old = sanitize_key( $old_slug );
			$new = sanitize_key( $new_slug );

			if ( '' === $old || '' === $new || $old === $new ) {
				return false;
			}

			// Refuse to overwrite an existing real tool — aliases must never
			// shadow a registered slug.
			if ( isset( $this->tools[ $old ] ) ) {
				return false;
			}

			$args = is_array( $args ) ? $args : array();

			$this->deprecated_aliases[ $old ] = array(
				'new_slug' => $new,
				'since'    => isset( $args['since'] ) ? (string) $args['since'] : '',
				'remove'   => isset( $args['remove'] ) ? (string) $args['remove'] : '',
				'message'  => isset( $args['message'] ) ? (string) $args['message'] : '',
			);

			return true;
		}

		/**
		 * Retrieve all registered deprecated aliases.
		 *
		 * @since 1.2.2
		 *
		 * @return array<string, array{new_slug:string, since:string, remove:string, message:string}>
		 */
		public function get_deprecated_aliases() {
			return $this->deprecated_aliases;
		}

		/**
		 * Resolve a slug to its replacement if it is a deprecated alias.
		 *
		 * Fires {@see 'wp_mcp_ai_tool_deprecated_alias_invoked'} exactly once per
		 * (request, $slug) pair so OTel and activity-log subscribers can surface
		 * usage without spamming.
		 *
		 * @since 1.2.2
		 *
		 * @param string $slug Tool slug (sanitized by caller).
		 * @return string Replacement slug if `$slug` is a deprecated alias, otherwise `$slug`.
		 */
		public function resolve_deprecated_alias( $slug ) {
			if ( ! isset( $this->deprecated_aliases[ $slug ] ) ) {
				return $slug;
			}

			$entry = $this->deprecated_aliases[ $slug ];
			$new   = $entry['new_slug'];

			if ( empty( $this->deprecated_alias_invocations[ $slug ] ) ) {
				$this->deprecated_alias_invocations[ $slug ] = true;

				/**
				 * Fires the first time per request that a deprecated tool alias
				 * is resolved to its replacement. Subscribers may log usage,
				 * emit OTel spans, or surface admin notices.
				 *
				 * @since 1.2.2
				 *
				 * @param string $old_slug Deprecated slug that was invoked.
				 * @param string $new_slug Replacement slug the call was rerouted to.
				 * @param array  $entry    Alias metadata: { new_slug, since, remove, message }.
				 */
				do_action( 'wp_mcp_ai_tool_deprecated_alias_invoked', $slug, $new, $entry );
			}

			return $new;
		}

		/**
		 * Clear the once-per-request invocation throttle.
		 *
		 * Intended for test isolation only.
		 *
		 * @since 1.2.2
		 *
		 * @return void
		 */
		public function reset_deprecated_alias_invocations() {
			$this->deprecated_alias_invocations = array();
		}

		/**
		 * Retrieve a tool instance.
		 *
		 * Resolves deprecated aliases registered via {@see self::register_deprecated_alias()}:
		 * if `$slug` is a known alias, the call is transparently rerouted to the
		 * replacement tool and the
		 * {@see 'wp_mcp_ai_tool_deprecated_alias_invoked'} action is fired (once
		 * per request per alias).
		 *
		 * @param string $slug Tool slug. May be a deprecated alias.
		 * @return WP_MCP_AI_Tool_Interface|null
		 */
		public function get_tool( $slug ) {
			// Ensure registry is initialized before retrieving tools.
			$this->init();

			$slug = sanitize_key( $slug );

			$resolved = $this->resolve_deprecated_alias( $slug );

			return isset( $this->tools[ $resolved ] ) ? $this->tools[ $resolved ] : null;
		}

		/**
		 * Retrieve all registered tools.
		 *
		 * @return WP_MCP_AI_Tool_Interface[]
		 */
		public function get_tools() {
			// Ensure registry is initialized before retrieving tools.
			$this->init();

			return array_values( $this->tools );
		}

		/**
		 * Retrieve all registered tools as an associative array keyed by slug.
		 *
		 * Use this method when you need to:
		 * - Look up tools by slug using array key access
		 * - Iterate over tools with slug keys (foreach $tools as $slug => $tool)
		 * - Get tool slugs using array_keys()
		 *
		 * Use get_tools() instead when you only need to:
		 * - Count the number of tools
		 * - Iterate over tool objects without needing slugs
		 *
		 * @return WP_MCP_AI_Tool_Interface[] Associative array with slugs as keys.
		 */
		public function get_all_tools() {
			// Ensure registry is initialized before retrieving tools.
			$this->init();

			return $this->tools;
		}

		/**
		 * Execute a tool.
		 *
		 * @param string $slug      Tool slug.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 * @return mixed|WP_Error Tool result or error.
		 */
		public function execute_tool( $slug, $arguments = array(), $context = array() ) {
			$slug = sanitize_key( $slug );
			$tool = $this->get_tool( $slug );

			if ( ! $tool ) {
				return new WP_Error(
					'wp_mcp_ai_tool_not_found',
					sprintf(
						/* translators: %s: tool slug */
						__( 'Tool "%s" not found.', 'mcp-ai-wpoos' ),
						$slug
					)
				);
			}

			// Check if tool is globally enabled.
			if ( ! $this->is_tool_enabled( $slug ) ) {
				return new WP_Error(
					'wp_mcp_ai_tool_disabled',
					sprintf(
						/* translators: %s: tool slug */
						__( 'Tool "%s" is disabled and cannot be executed.', 'mcp-ai-wpoos' ),
						$slug
					)
				);
			}

			// Attempt provider compensation if there's a mismatch.
			$compensation_result = $this->compensate_for_provider_mismatch( $slug, $arguments, $context );
			if ( ! is_wp_error( $compensation_result ) && is_array( $compensation_result ) ) {
				// Provider compensation succeeded - update slug and arguments.
				$slug      = $compensation_result['slug'];
				$arguments = $compensation_result['arguments'];
				$tool      = $this->get_tool( $slug );

				if ( ! $tool ) {
					return new WP_Error(
						'wp_mcp_ai_tool_not_found',
						sprintf(
							/* translators: %s: compensated tool slug */
							__( 'Compensated tool "%s" not found.', 'mcp-ai-wpoos' ),
							$slug
						)
					);
				}
			}

			// Validate tool execution requirements (model, parameters, dependencies).
			$validation_result = $this->validate_tool_execution( $slug, $arguments, $context );
			if ( is_wp_error( $validation_result ) ) {
				return $validation_result;
			}

			// Auto-async dispatch (Phase 2): if the tool implements the
			// bulk-operation interface and the estimated workload exceeds
			// `wp_mcp_ai_bulk_async_threshold`, queue the call via the async
			// job queue instead of executing inline. Gated behind
			// `WP_MCP_AI_BULK_AUTO_ASYNC` so existing behaviour is preserved
			// until the Phase 4 Action Scheduler integration lands.
			$auto_async = $this->maybe_dispatch_async_bulk( $slug, $tool, $arguments, $context );
			if ( null !== $auto_async ) {
				return $auto_async;
			}

			// Execute the tool.
			return $tool->execute( $arguments, $context );
		}

		/**
		 * If the tool is a registered bulk-operation handler and its estimated
		 * workload exceeds the configured threshold, dispatch the call to the
		 * async job queue and return a job-handle envelope to the caller.
		 *
		 * Returns `null` to indicate "no async dispatch — execute inline".
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug      Tool slug.
		 * @param object $tool      Resolved tool instance.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 * @return array|null
		 */
		protected function maybe_dispatch_async_bulk( $slug, $tool, $arguments, $context ) {
			// Phase 4: when the Action Scheduler bridge is available, default
			// auto-async dispatch ON so bulk jobs run on the next AS tick
			// instead of polling WP-Cron once per minute. Sites without AS
			// keep the legacy default (off) until they explicitly opt in via
			// the `WP_MCP_AI_BULK_AUTO_ASYNC` constant or the filter below.
			if ( defined( 'WP_MCP_AI_BULK_AUTO_ASYNC' ) ) {
				$enabled = (bool) WP_MCP_AI_BULK_AUTO_ASYNC;
			} elseif (
				class_exists( 'WP_MCP_AI_Async_Scheduler_Bridge' ) &&
				WP_MCP_AI_Async_Scheduler_Bridge::is_available()
			) {
				$enabled = true;
			} else {
				$enabled = false;
			}

			/**
			 * Filters whether auto-async bulk dispatch is enabled.
			 *
			 * @since 1.2.0
			 *
			 * @param bool   $enabled Whether to attempt async dispatch.
			 * @param string $slug    Tool slug.
			 */
			$enabled = (bool) apply_filters( 'wp_mcp_ai_bulk_auto_async_enabled', $enabled, $slug );

			if ( ! $enabled ) {
				return null;
			}

			if ( ! ( $tool instanceof WP_MCP_AI_Tool_Bulk_Operation_Interface ) ) {
				return null;
			}

			if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
				return null;
			}

			// Avoid recursive dispatch when the queue worker re-enters.
			if ( ! empty( $context['async_worker'] ) ) {
				return null;
			}

			$estimate = (int) $tool->estimate_total( $arguments );
			if ( $estimate <= 0 ) {
				return null;
			}

			/**
			 * Filters the row threshold above which bulk tools are auto-dispatched
			 * to the async job queue.
			 *
			 * @since 1.2.0
			 *
			 * @param int    $threshold Default 1000.
			 * @param string $slug      Tool slug.
			 */
			$threshold = (int) apply_filters( 'wp_mcp_ai_bulk_async_threshold', 1000, $slug );

			if ( $estimate < $threshold ) {
				return null;
			}

			$job_id = WP_MCP_AI_Async_Job_Queue::queue_job(
				array(
					'job_type'     => 'tool_execution',
					'job_data'     => array(
						'tool_slug'      => $slug,
						'arguments'      => $arguments,
						'checkpoint_key' => $tool->get_checkpoint_key( $arguments ),
						'estimated_rows' => $estimate,
					),
					'chat_session' => isset( $context['chat_session'] ) ? (string) $context['chat_session'] : '',
					'assistant_id' => isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0,
				)
			);

			if ( is_wp_error( $job_id ) ) {
				// Fall back to inline execution rather than blocking the call.
				return null;
			}

			return array(
				'success'        => true,
				'async'          => true,
				'job_id'         => (int) $job_id,
				'tool_slug'      => $slug,
				'estimated_rows' => $estimate,
				'message'        => sprintf(
					/* translators: 1: tool slug, 2: estimated row count, 3: job ID */
					__( '%1$s call (~%2$d rows) queued as async job #%3$d.', 'mcp-ai-wpoos' ),
					$slug,
					$estimate,
					(int) $job_id
				),
			);
		}

		/**
		 * Compensate for provider mismatch by routing to equivalent provider-specific tool.
		 *
		 * When a tool is requested that requires a different provider than the current one,
		 * this method attempts to route to an equivalent tool for the active provider
		 * and translates parameters as needed.
		 *
		 * @param string $slug Tool slug.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context Execution context.
		 * @return array|WP_Error Array with 'slug' and 'arguments' on success, WP_Error or null if no compensation needed.
		 */
		protected function compensate_for_provider_mismatch( $slug, $arguments, $context ) {
			// Extract current provider from context.
			$current_provider = '';
			if ( ! empty( $context['assistant_config']['provider'] ) ) {
				$current_provider = $context['assistant_config']['provider'];
			} elseif ( ! empty( $context['model'] ) ) {
				$parts            = explode( ':', $context['model'] );
				$current_provider = count( $parts ) > 1 ? $parts[0] : '';
			}

			// If no provider context, can't compensate.
			if ( empty( $current_provider ) ) {
				return null;
			}

			// Get tool's required providers.
			$tool_rules = $this->get_tool_rules( $slug );
			if ( empty( $tool_rules['model_requirements']['providers'] ) ) {
				return null;
			}

			$required_providers = $tool_rules['model_requirements']['providers'];

			// If current provider is acceptable, no compensation needed.
			if ( in_array( $current_provider, $required_providers, true ) ) {
				return null;
			}

			// Define provider-specific tool mappings.
			$tool_mapping = $this->get_provider_tool_mapping();

			// Check if this tool has a mapping.
			if ( ! isset( $tool_mapping[ $slug ] ) ) {
				return null; // No mapping available, will fall through to validation error.
			}

			$mapping = $tool_mapping[ $slug ];

			// Check if there's a tool for the current provider.
			if ( ! isset( $mapping[ $current_provider ] ) ) {
				return null; // No equivalent tool for this provider.
			}

			$target_slug = $mapping[ $current_provider ];

			// Check if the target tool exists and is enabled.
			if ( ! $this->is_tool_registered( $target_slug ) || ! $this->is_tool_enabled( $target_slug ) ) {
				return null;
			}

			// Translate parameters between providers.
			$translated_arguments = $this->translate_tool_parameters( $slug, $target_slug, $arguments, $current_provider );

			// Log the provider compensation for debugging.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'provider_compensation',
					sprintf( 'Provider compensation: routing "%s" to "%s" for provider "%s"', $slug, $target_slug, $current_provider ),
					array(
						'original_tool'   => $slug,
						'target_tool'     => $target_slug,
						'provider'        => $current_provider,
						'original_args'   => $arguments,
						'translated_args' => $translated_arguments,
					)
				);
			}

			return array(
				'slug'      => $target_slug,
				'arguments' => $translated_arguments,
			);
		}

		/**
		 * Get provider-specific tool mappings.
		 *
		 * Defines which tools are equivalent across different providers.
		 *
		 * @return array Tool mapping structure.
		 */
		protected function get_provider_tool_mapping() {
			$mapping = array(
				// Image generation tools.
				'generate_openai_image' => array(
					'gemini'    => 'generate_gemini_image',
					'anthropic' => 'generate_openai_image', // Anthropic doesn't have image generation (fallback).
				),
				'generate_gemini_image' => array(
					'openai'    => 'generate_openai_image',
					'anthropic' => 'generate_openai_image', // Use OpenAI as fallback.
				),
				// Image editing tools.
				'edit_openai_image'     => array(
					'gemini' => 'edit_gemini_image',
				),
				'edit_gemini_image'     => array(
					'openai' => 'edit_openai_image',
				),
			);

			/**
			 * Filter provider tool mapping.
			 *
			 * Allows plugins to add or modify provider-specific tool mappings.
			 *
			 * @param array $mapping Tool mapping structure.
			 */
			return apply_filters( 'wp_mcp_ai_provider_tool_mapping', $mapping );
		}

		/**
		 * Translate tool parameters between providers.
		 *
		 * Converts provider-specific parameters to equivalent parameters for the target provider.
		 *
		 * @param string $source_slug Source tool slug.
		 * @param string $target_slug Target tool slug.
		 * @param array  $arguments Original arguments.
		 * @param string $target_provider Target provider.
		 * @return array Translated arguments.
		 */
		protected function translate_tool_parameters( $source_slug, $target_slug, $arguments, $target_provider ) {
			$translated = array();

			// Common parameters that don't need translation.
			$common_params = array( 'prompt', 'file_name', 'timeout', 'output_format' );
			foreach ( $common_params as $param ) {
				if ( isset( $arguments[ $param ] ) ) {
					$translated[ $param ] = $arguments[ $param ];
				}
			}

			// Translate OpenAI -> Gemini image parameters.
			if ( 'generate_openai_image' === $source_slug && 'generate_gemini_image' === $target_slug ) {
				// Translate size to aspect_ratio.
				if ( ! empty( $arguments['size'] ) ) {
					$translated['aspect_ratio'] = $this->convert_size_to_aspect_ratio( $arguments['size'] );
				}

				// Quality and style are OpenAI-specific, drop them for Gemini.
				// Gemini uses model selection for quality control.

				// Default mime_type for Gemini.
				if ( empty( $translated['mime_type'] ) ) {
					$translated['mime_type'] = 'image/png';
				}
			}

			// Translate Gemini -> OpenAI image parameters.
			if ( 'generate_gemini_image' === $source_slug && 'generate_openai_image' === $target_slug ) {
				// Translate aspect_ratio to size.
				if ( ! empty( $arguments['aspect_ratio'] ) ) {
					$translated['size'] = $this->convert_aspect_ratio_to_size( $arguments['aspect_ratio'] );
				}

				// Default quality for OpenAI.
				if ( empty( $translated['quality'] ) ) {
					$translated['quality'] = 'standard';
				}
			}

			/**
			 * Filter translated tool parameters.
			 *
			 * Allows plugins to customize parameter translation.
			 *
			 * @param array  $translated Translated arguments.
			 * @param string $source_slug Source tool slug.
			 * @param string $target_slug Target tool slug.
			 * @param array  $arguments Original arguments.
			 * @param string $target_provider Target provider.
			 */
			return apply_filters( 'wp_mcp_ai_translate_tool_parameters', $translated, $source_slug, $target_slug, $arguments, $target_provider );
		}

		/**
		 * Convert OpenAI size to Gemini aspect ratio.
		 *
		 * @param string $size OpenAI size (e.g., "1024x1024", "1792x1024").
		 * @return string Gemini aspect ratio (e.g., "1:1", "16:9").
		 */
		protected function convert_size_to_aspect_ratio( $size ) {
			$size_to_aspect = array(
				'1024x1024' => '1:1',
				'1792x1024' => '16:9',
				'1024x1792' => '9:16',
				'1536x1024' => '3:2',
				'1024x1536' => '2:3',
			);

			if ( isset( $size_to_aspect[ $size ] ) ) {
				return $size_to_aspect[ $size ];
			}

			// Try to compute aspect ratio from dimensions.
			if ( preg_match( '/^(\d+)x(\d+)$/', $size, $matches ) ) {
				$width  = (int) $matches[1];
				$height = (int) $matches[2];

				if ( $width === $height ) {
					return '1:1';
				} elseif ( $width > $height ) {
					$ratio = round( $width / $height, 1 );
					if ( abs( $ratio - 1.78 ) < 0.1 ) {
						return '16:9';
					} elseif ( abs( $ratio - 1.5 ) < 0.1 ) {
						return '3:2';
					}
					return '16:9'; // Default to 16:9 for landscape.
				} else {
					return '9:16'; // Default to 9:16 for portrait.
				}
			}

			// Default fallback.
			return '4:3';
		}

		/**
		 * Convert Gemini aspect ratio to OpenAI size.
		 *
		 * @param string $aspect_ratio Gemini aspect ratio (e.g., "1:1", "16:9").
		 * @return string OpenAI size (e.g., "1024x1024", "1792x1024").
		 */
		protected function convert_aspect_ratio_to_size( $aspect_ratio ) {
			$aspect_to_size = array(
				'1:1'  => '1024x1024',
				'16:9' => '1792x1024',
				'9:16' => '1024x1792',
				'4:3'  => '1024x768',
				'3:4'  => '768x1024',
				'3:2'  => '1536x1024',
				'2:3'  => '1024x1536',
			);

			if ( isset( $aspect_to_size[ $aspect_ratio ] ) ) {
				return $aspect_to_size[ $aspect_ratio ];
			}

			// Default fallback.
			return '1024x1024';
		}

				/**
				 * Check if a tool is registered.
				 *
				 * @param string $slug Tool slug.
				 * @return bool Whether the tool is registered.
				 */
		public function is_tool_registered( $slug ) {
			// Ensure registry is initialized before retrieving tools.
			$this->init();

			$slug = sanitize_key( $slug );

			// Resolve deprecated aliases without firing the deprecation hook —
			// callers that only want to test for registration should not pay the
			// log/observability cost of a real invocation.
			if ( isset( $this->deprecated_aliases[ $slug ] ) ) {
				$slug = $this->deprecated_aliases[ $slug ]['new_slug'];
			}

			return isset( $this->tools[ $slug ] );
		}

		/**
		 * Get tool capability requirement.
		 *
		 * @param string $slug Tool slug.
		 * @return string|null Required capability or null.
		 */
		public function get_tool_capability( $slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
			// This method is referenced but not yet implemented.
			// For now, return null to maintain compatibility.
			return null;
		}

		/**
		 * Get tool definition for LLM payload.
		 *
		 * @param string $slug Tool slug.
		 * @return array|null Tool definition or null.
		 */
		public function get_tool_definition( $slug ) {
			$tool = $this->get_tool( $slug );
			if ( ! $tool ) {
				return null;
			}

			$definition = array(
				'name'        => $tool->get_slug(),
				'description' => $tool->get_description(),
				'parameters'  => $tool->get_parameters_schema(),
			);

			$data_contract = $this->get_tool_data_contract( $slug );
			if ( ! empty( $data_contract ) ) {
				$definition['data_contract'] = $data_contract;
			}

			return $definition;
		}

		/**
		 * Retrieve the data contract for a specific tool.
		 *
		 * Tools that implement {@see WP_MCP_AI_Tool_Data_Contract_Interface}
		 * may declare named `produces` and/or `consumes` payload contracts so
		 * the orchestrator can hint at chaining opportunities to the model.
		 *
		 * Returns an empty array when the tool does not implement the interface
		 * or when both keys are null/empty.
		 *
		 * @since 1.2.1
		 *
		 * @param string $slug Tool slug.
		 * @return array{produces?: string, consumes?: string|string[]} Normalised contract.
		 */
		public function get_tool_data_contract( $slug ) {
			$tool = $this->get_tool( $slug );
			if ( ! $tool ) {
				return array();
			}

			if ( ! ( $tool instanceof WP_MCP_AI_Tool_Data_Contract_Interface ) ) {
				return array();
			}

			$contract = $tool->get_data_contract();
			if ( ! is_array( $contract ) ) {
				return array();
			}

			$normalised = array();

			if ( isset( $contract['produces'] ) && is_string( $contract['produces'] ) && '' !== $contract['produces'] ) {
				$normalised['produces'] = sanitize_key( $contract['produces'] );
			}

			if ( isset( $contract['consumes'] ) && ! empty( $contract['consumes'] ) ) {
				if ( is_string( $contract['consumes'] ) ) {
					$normalised['consumes'] = sanitize_key( $contract['consumes'] );
				} elseif ( is_array( $contract['consumes'] ) ) {
					$consumes_list = array();
					foreach ( $contract['consumes'] as $value ) {
						if ( is_string( $value ) && '' !== $value ) {
							$consumes_list[] = sanitize_key( $value );
						}
					}
					if ( ! empty( $consumes_list ) ) {
						$normalised['consumes'] = array_values( array_unique( $consumes_list ) );
					}
				}
			}

			return $normalised;
		}

		/**
		 * Get capability flags for a specific tool.
		 *
		 * Retrieves the capability flags from a tool if it implements
		 * the WP_MCP_AI_Tool_Capability_Flags_Interface.
		 *
		 * @param string $slug Tool slug.
		 * @return array<string> Array of capability flag strings, empty array if tool not found or no flags.
		 */
		public function get_tool_capability_flags( $slug ) {
			$tool = $this->get_tool( $slug );

			if ( ! $tool ) {
				return array();
			}

			// Check if tool implements capability flags interface.
			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = $tool->get_capability_flags();
				return is_array( $flags ) ? $flags : array();
			}

			return array();
		}

		/**
		 * Get capability flags for all registered tools.
		 *
		 * Returns a map of tool slugs to their capability flags arrays.
		 * Only includes tools that implement the capability flags interface.
		 *
		 * @return array<string, array<string>> Map of tool slugs to capability flag arrays.
		 */
		public function get_all_tool_capability_flags() {
			// Ensure registry is initialized before retrieving tools.
			$this->init();

			$flags_map = array();

			foreach ( $this->tools as $slug => $tool ) {
				$flags = $this->get_tool_capability_flags( $slug );
				if ( ! empty( $flags ) ) {
					$flags_map[ $slug ] = $flags;
				}
			}

			return $flags_map;
		}

		/**
		 * Get tools that have a specific capability flag.
		 *
		 * Filters all registered tools to find those that declare the specified
		 * capability flag.
		 *
		 * @param string $flag Capability flag to filter by (e.g., 'read-only', 'external-api').
		 * @return array<WP_MCP_AI_Tool_Interface> Array of tool instances that have the flag.
		 */
		public function get_tools_by_capability_flag( $flag ) {
			// Ensure registry is initialized before retrieving tools.
			$this->init();

			$matching_tools = array();

			foreach ( $this->tools as $slug => $tool ) {
				$flags = $this->get_tool_capability_flags( $slug );
				if ( in_array( $flag, $flags, true ) ) {
					$matching_tools[] = $tool;
				}
			}

			return $matching_tools;
		}

		/**
		 * Retrieve the default tool grouping map keyed by tool slug.
		 *
		 * @return array<string, string>
		 */
		public function get_tool_group_map() {
			// Ensure registry is initialized so that filters are applied (including pro tools).
			$this->init();

			$default_map = array(
				// WordPress Core - Tools that work with base WordPress, no external dependencies.
				'submit_document_prompt'             => 'wordpress-core',
				'search_content'                     => 'wordpress-core',
				'search_attachments'                 => 'wordpress-core',
				'get_recent_posts'                   => 'wordpress-core',
				'save_post'                          => 'wordpress-core',
				'create_post'                        => 'wordpress-core',
				'create_term'                        => 'wordpress-core',
				'update_term'                        => 'wordpress-core',
				'get_user_info'                      => 'wordpress-core',
				'get_site_summary'                   => 'wordpress-core',
				'get_system_logs'                    => 'wordpress-core',
				'get_update_status'                  => 'wordpress-core',
				'get_site_health'                    => 'wordpress-core',
				'get_environment_status'             => 'wordpress-core',
				'create_cron_job'                    => 'wordpress-core',
				'list_cron_jobs'                     => 'wordpress-core',
				'get_cron_job'                       => 'wordpress-core',
				'delete_cron_job'                    => 'wordpress-core',
				'send_group_email'                   => 'wordpress-core',
				'purge_cache'                        => 'wordpress-core',
				'probe_chat'                         => 'wordpress-core',
				'probe_remote_mcp'                   => 'wordpress-core',
				'query_remote_site'                  => 'wordpress-core',
				'query_mesh_intelligent'             => 'wordpress-core',
				'check_site_security'                => 'wordpress-core',
				'count_tokens'                       => 'wordpress-core',

				// Media and content moderation (AI-powered).
				'generate_image_alt_text'            => 'wordpress-core',
				'generate_image_caption'             => 'wordpress-core',
				'analyze_image'                      => 'wordpress-core',
				'extract_image_text'                 => 'wordpress-core',
				'analyze_video'                      => 'wordpress-core',
				'generate_video_caption'             => 'wordpress-core',
				'analyze_comment_content'            => 'wordpress-core',

				// Image manipulation (Graphic Editor Suite).
				'resize_image'                       => 'wordpress-core',
				'crop_image'                         => 'wordpress-core',
				'rotate_image'                       => 'wordpress-core',
				'convert_image_format'               => 'wordpress-core',
				'vectorize_image'                    => 'wordpress-core',
				'graphic_editor_plus'                => 'wordpress-plugins',

				// Data Visualization.
				'create_chart'                       => 'wordpress-core',
				'visualize_workflow_metrics'         => 'wordpress-core',
				'validate_workflow'                  => 'wordpress-core',

				// Excel and Spreadsheet Tools - Pro features.
				'pro_excel'                          => 'external-tools',

				// Assistant management.
				'create_assistant'                   => 'wordpress-core',

				// Profession management.
				'list_professions'                   => 'wordpress-core',
				'get_profession'                     => 'wordpress-core',
				'save_profession'                    => 'wordpress-core',
				'get_profession_stats'               => 'wordpress-core',

				// Multi-agent orchestration (DeepSeek V4 Phase 1 & 5).
				'create_agent_team'                  => 'wordpress-core',
				'delegate_to_agent'                  => 'wordpress-core',
				'aggregate_agent_results'            => 'wordpress-core',
				'store_agent_context'                => 'wordpress-core',
				'retrieve_agent_memory'              => 'wordpress-core',
				'prioritize_context'                 => 'wordpress-core',
				'semantic_context_search'            => 'external-tools',

				// Project Management - Pro feature tools for managing projects, tasks, and events.
				'create_project'                     => 'project-management',
				'update_project'                     => 'project-management',
				'delete_project'                     => 'project-management',
				'list_projects'                      => 'project-management',
				'create_task'                        => 'project-management',
				'update_task'                        => 'project-management',
				'delete_task'                        => 'project-management',
				'list_tasks'                         => 'project-management',
				'create_event'                       => 'project-management',
				'update_event'                       => 'project-management',
				'delete_event'                       => 'project-management',
				'list_events'                        => 'project-management',
				'get_calendar_view'                  => 'project-management',

				// WordPress Plugins - Tools requiring specific WordPress plugins.
				'get_elementor_templates'            => 'wordpress-plugins',
				'get_elementor_form_submissions'     => 'wordpress-plugins',
				'get_all_form_submissions'           => 'wordpress-plugins',
				'get_woo_recent_orders'              => 'wordpress-plugins',
				'get_woo_products'                   => 'wordpress-plugins',
				'create_woo_product'                 => 'wordpress-plugins',
				'get_jetengine_items'                => 'wordpress-plugins',
				'list_jetengine_rest_routes'         => 'wordpress-plugins',
				'invoke_jetengine_route'             => 'wordpress-plugins',
				'get_jetformbuilder_forms'           => 'wordpress-plugins',
				'get_jetformbuilder_submissions'     => 'wordpress-plugins',
				'get_rankmath_seo'                   => 'wordpress-plugins',
				'create_wpcode_snippet'              => 'wordpress-plugins',
				'generate_simple_jwt_token'          => 'wordpress-plugins',
				'newsletter_add_subscriber'          => 'wordpress-plugins',
				'newsletter_get_subscribers'         => 'wordpress-plugins',
				'newsletter_unsubscribe'             => 'wordpress-plugins',
				'newsletter_get_subscriber_stats'    => 'wordpress-plugins',
				'newsletter_create_email'            => 'wordpress-plugins',
				'newsletter_get_emails'              => 'wordpress-plugins',

				// External Tools - Tools requiring external APIs or credentials.
				'generate_openai_image'              => 'external-tools',
				'generate_sora_video'                => 'external-tools',
				'generate_gemini_image'              => 'external-tools',
				'cloudflareai_text_to_image'         => 'external-tools',
				'edit_gemini_image'                  => 'external-tools',
				'generate_veo_video'                 => 'external-tools',
				'check_video_status'                 => 'external-tools',
				'generate_music'                     => 'external-tools',
				'generate_openai_speech'             => 'external-tools',
				'transcribe_openai_audio'            => 'external-tools',
				'moderate_content'                   => 'external-tools',
				'open_openai_usage'                  => 'external-tools',
				'open_openai_logs'                   => 'external-tools',
				'run_openai_external_action'         => 'external-tools',
				// OpenAI API Integration - Phase 1 Tools.
				'list_openai_files'                  => 'external-tools',
				'get_openai_file_details'            => 'external-tools',
				'list_available_models'              => 'external-tools',
				'get_model_information'              => 'external-tools',
				'research_model'                     => 'external-tools',
				'add_model_config'                   => 'external-tools',
				'discover_new_models'                => 'external-tools',
				'deep_research'                      => 'external-tools',
				'create_text_embeddings'             => 'external-tools',
				// OpenAI API Integration - Phase 2 Tools.
				'semantic_content_search'            => 'external-tools',
				'suggest_best_model'                 => 'external-tools',
				'batch_embed_content'                => 'external-tools',
				// OpenAI API Integration - Phase 3 Tools (Vector Stores).
				'create_vector_store'                => 'external-tools',
				'list_vector_stores'                 => 'external-tools',
				'get_vector_store'                   => 'external-tools',
				'manage_vector_store_files'          => 'external-tools',
				// OpenAI API Integration - Phase 4 Tools.
				'edit_openai_image'                  => 'external-tools',
				'create_image_variation'             => 'external-tools',
				'analyze_file_suitability'           => 'external-tools',
				'openai_usage_analytics'             => 'external-tools',
				'vision_product_search'              => 'external-tools',
				'vision_object_localization'         => 'external-tools',
				'schedule_notify_sms'                => 'external-tools',
				'web_search'                         => 'external-tools',
				'web_browser'                        => 'external-tools',
				'capture_webpage_screenshot'         => 'external-tools',
				'search_gmail'                       => 'external-tools',
				'search_drive'                       => 'external-tools',
				'crawl4ai_price_lookup'              => 'external-tools',
				'run_crawl4ai_job'                   => 'external-tools',
				'scrape_product'                     => 'external-tools',
				'get_gdacs_events'                   => 'external-tools',
				'get_open_meteo_forecast'            => 'external-tools',
				'get_nhc_active_storms'              => 'external-tools',
				'reliefweb_reports'                  => 'external-tools',
				'get_import_duty'                    => 'external-tools',
				'generate_auth0_token'               => 'external-tools',
				'purge_cloudflare_cache'             => 'external-tools',
				'purge_varnish_cache'                => 'external-tools',
				'geocode_address'                    => 'external-tools',
				'search_places'                      => 'external-tools',
				'gemini_geospatial_query'            => 'external-tools',
				'payhere_get_payment'                => 'external-tools',
				// Flowhub cannabis dispensary integration.
				'flowhub_get_inventory'              => 'external-tools',
				'flowhub_get_orders'                 => 'external-tools',
				'flowhub_create_order'               => 'external-tools',
				'flowhub_get_customers'              => 'external-tools',
				'flowhub_manage_customer'            => 'external-tools',
				'flowhub_get_products'               => 'external-tools',
				'flowhub_manage_product'             => 'external-tools',
				'list_github_repositories'           => 'external-tools',
				'github_repository_operations'       => 'external-tools',
				'manage_github_codespace'            => 'external-tools',
				'generic_rest'                       => 'external-tools',
				// HuggingFace Datasets API tools.
				'huggingface_dataset_search'         => 'external-tools',
				'huggingface_dataset_get_info'       => 'external-tools',
				'huggingface_dataset_get_size'       => 'external-tools',
				'huggingface_dataset_get_rows'       => 'external-tools',
				'huggingface_dataset_preview_rows'   => 'external-tools',
				'huggingface_dataset_list_splits'    => 'external-tools',
				'huggingface_dataset_get_statistics' => 'external-tools',
				'huggingface_dataset_get_parquet'    => 'external-tools',
				'huggingface_dataset_is_valid'       => 'external-tools',
				'huggingface_dataset_filter'         => 'external-tools',
				'huggingface_recommended_datasets'   => 'external-tools',
				// Erlang C queuing-theory and workforce tools.
				'calculate_erlang_c'                 => 'wordpress-core',
				'erlang_c_concurrency_advisor'       => 'wordpress-core',
				'erlang_c_staffing_advisor'          => 'external-tools',
				'erlang_c_queue_health'              => 'external-tools',
			);

			// Fantasy Football tool mappings are now handled by the Pro addon.
			// Quiz tool mappings are now handled by the Pro addon.

			/**
			 * Filter the tool grouping map used throughout the admin UI.
			 *
			 * @param array<string, string> $default_map Associative array of tool slugs to group identifiers.
			 */
			return apply_filters( 'wp_mcp_ai_tool_group_map', $default_map );
		}

		/**
		 * Retrieve the default labels for tool groups.
		 *
		 * @return array<string, string>
		 */
		public function get_tool_group_labels() {
			// Ensure registry is initialized so that filters are applied.
			$this->init();

			$default_labels = array(
				'wordpress-core'     => __( 'WordPress Core', 'mcp-ai-wpoos' ),
				'wordpress-plugins'  => __( 'WordPress Plugins', 'mcp-ai-wpoos' ),
				'project-management' => __( 'Project Management', 'mcp-ai-wpoos' ),
				'external-tools'     => __( 'External Tools', 'mcp-ai-wpoos' ),
				'other'              => __( 'Other tools', 'mcp-ai-wpoos' ),
			);

			/**
			 * Filter the tool group labels used throughout the admin UI.
			 *
			 * @param array<string, string> $default_labels Associative array of group identifiers to labels.
			 */
			return apply_filters( 'wp_mcp_ai_tool_group_labels', $default_labels );
		}

																		/**
																		 * Determine the current flow stage from execution context.
																		 *
																		 * @param array $context Execution context.
																		 * @return string Current flow stage: 'start', 'middle', 'end', or 'anytime'.
																		 */
		protected function determine_flow_stage( $context ) {
			// Check for explicit flow_stage in context.
			if ( ! empty( $context['flow_stage'] ) ) {
				$stage = sanitize_key( $context['flow_stage'] );
				if ( in_array( $stage, array( 'start', 'middle', 'end', 'anytime' ), true ) ) {
					return $stage;
				}
			}

			// Determine stage from iteration context.
			if ( isset( $context['iteration'] ) && isset( $context['max_iterations'] ) ) {
				$iteration      = absint( $context['iteration'] );
				$max_iterations = absint( $context['max_iterations'] );

				if ( $max_iterations <= 1 ) {
					// Single iteration workflow - consider it both start and end.
					return 'start';
				}

				if ( 0 === $iteration ) {
					return 'start';
				} elseif ( $iteration >= $max_iterations - 1 ) {
					return 'end';
				} else {
					return 'middle';
				}
			}

			// Default to anytime if stage cannot be determined.
			return 'anytime';
		}

		/**
		 * Get tool-specific execution rules.
		 *
		 * Retrieves rules from the tool if it implements the WP_MCP_AI_Tool_Rules_Interface.
		 *
		 * @param string $slug Tool slug.
		 * @return array Tool rules or empty array if tool doesn't define rules.
		 */
		protected function get_tool_rules( $slug ) {
			$tool = $this->get_tool( $slug );

			if ( ! $tool ) {
				return array();
			}

			// Check if tool implements the rules interface.
			if ( $tool instanceof WP_MCP_AI_Tool_Rules_Interface ) {
				$rules = $tool->get_tool_rules();
				return is_array( $rules ) ? $rules : array();
			}

			return array();
		}

		/**
		 * Validate tool execution against its rules.
		 *
		 * Checks if the current environment and parameters meet the tool's requirements.
		 *
		 * @param string $slug Tool slug.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context Execution context.
		 * @return true|WP_Error True if valid, WP_Error if validation fails.
		 */
		public function validate_tool_execution( $slug, $arguments = array(), $context = array() ) {
			$rules = $this->get_tool_rules( $slug );

			if ( empty( $rules ) ) {
				return true; // No rules to validate.
			}

			$errors = array();

			// Validate model requirements.
			if ( ! empty( $rules['model_requirements'] ) ) {
				$model_error = $this->validate_model_requirements( $rules['model_requirements'], $arguments, $context );
				if ( is_wp_error( $model_error ) ) {
					$errors[] = $model_error->get_error_message();
				}
			}

			// Validate parameter constraints.
			if ( ! empty( $rules['parameter_constraints'] ) ) {
				$param_error = $this->validate_parameter_constraints( $rules['parameter_constraints'], $arguments );
				if ( is_wp_error( $param_error ) ) {
					$errors[] = $param_error->get_error_message();
				}
			}

			// Validate dependencies.
			if ( ! empty( $rules['dependencies'] ) ) {
				$dep_error = $this->validate_dependencies( $rules['dependencies'] );
				if ( is_wp_error( $dep_error ) ) {
					$errors[] = $dep_error->get_error_message();
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'tool_validation_failed', implode( '; ', $errors ), array( 'errors' => $errors ) );
			}

			return true;
		}

		/**
		 * Validate model requirements.
		 *
		 * @param array $requirements Model requirements.
		 * @param array $arguments Tool arguments.
		 * @param array $context Execution context.
		 * @return true|WP_Error
		 */
		protected function validate_model_requirements( $requirements, $arguments, $context ) {
			// Extract model from arguments, context, or assistant_config.
			$model                 = $arguments['model'] ?? $context['model'] ?? '';
			$from_assistant_config = false;

			// If model not directly available, check assistant_config.
			if ( empty( $model ) && ! empty( $context['assistant_config'] ) ) {
				$assistant_config = $context['assistant_config'];

				// Extract provider and model from assistant_config.
				$provider   = $assistant_config['provider'] ?? '';
				$model_name = $assistant_config['model'] ?? '';

				// Construct model in format "provider:model" if both are present.
				if ( ! empty( $provider ) && ! empty( $model_name ) ) {
					$model                 = $provider . ':' . $model_name;
					$from_assistant_config = true;
				}
			}

			// Check if model is specified when required.
			if ( ! empty( $requirements['required'] ) && empty( $model ) ) {
				return new WP_Error( 'model_required', 'This tool requires a model to be specified' );
			}

			// Validate allowed providers.
			if ( ! empty( $requirements['providers'] ) && ! empty( $model ) ) {
				// Extract provider from model string (format: "provider:model" or just "model").
				$parts    = explode( ':', $model );
				$provider = count( $parts ) > 1 ? $parts[0] : '';

				// If provider is explicitly specified, validate it.
				if ( ! empty( $provider ) && ! in_array( $provider, $requirements['providers'], true ) ) {
					return new WP_Error(
						'invalid_provider',
						sprintf( 'Model provider must be one of: %s', implode( ', ', $requirements['providers'] ) )
					);
				}
			}

			// Validate specific models (only if model is explicitly provided, not from assistant_config).
			// Models from assistant_config are already validated by provider check above.
			if ( ! empty( $requirements['models'] ) && ! empty( $model ) && ! $from_assistant_config ) {
				// Extract just the model name without provider prefix.
				$model_name = strpos( $model, ':' ) !== false ? explode( ':', $model )[1] : $model;

				if ( ! in_array( $model_name, $requirements['models'], true ) ) {
					return new WP_Error(
						'invalid_model',
						sprintf( 'Model must be one of: %s', implode( ', ', $requirements['models'] ) )
					);
				}
			}

			return true;
		}

		/**
		 * Validate parameter constraints.
		 *
		 * @param array $constraints Parameter constraints.
		 * @param array $arguments Tool arguments.
		 * @return true|WP_Error
		 */
		protected function validate_parameter_constraints( $constraints, $arguments ) {
			// Check required fields.
			if ( ! empty( $constraints['required_fields'] ) ) {
				foreach ( $constraints['required_fields'] as $field ) {
					if ( ! isset( $arguments[ $field ] ) || '' === $arguments[ $field ] ) {
						return new WP_Error( 'missing_parameter', "Required parameter '{$field}' is missing" );
					}
				}
			}

			// Check max items constraint.
			if ( ! empty( $constraints['max_items'] ) && isset( $arguments['items'] ) ) {
				if ( is_array( $arguments['items'] ) && count( $arguments['items'] ) > $constraints['max_items'] ) {
					return new WP_Error(
						'too_many_items',
						sprintf( 'Maximum %d items allowed, %d provided', $constraints['max_items'], count( $arguments['items'] ) )
					);
				}
			}

			return true;
		}

		/**
		 * Validate dependencies.
		 *
		 * @param array $dependencies Dependency requirements.
		 * @return true|WP_Error
		 */
		public function validate_dependencies( $dependencies ) {
			// Check required plugins.
			if ( ! empty( $dependencies['required_plugins'] ) ) {
				foreach ( $dependencies['required_plugins'] as $plugin ) {
					if ( 'woocommerce' === $plugin && ! class_exists( 'WooCommerce' ) ) {
						return new WP_Error( 'missing_plugin', 'WooCommerce plugin is required' );
					}
					// Add more plugin checks as needed.
				}
			}

			// Check required PHP extensions.
			if ( ! empty( $dependencies['required_extensions'] ) ) {
				foreach ( $dependencies['required_extensions'] as $extension ) {
					if ( ! extension_loaded( $extension ) ) {
						return new WP_Error( 'missing_extension', "PHP extension '{$extension}' is required" );
					}
				}
			}

			// Check required settings.
			if ( ! empty( $dependencies['required_settings'] ) ) {
				$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
				foreach ( $dependencies['required_settings'] as $setting => $setting_key ) {
					// Support both old option names (for backward compatibility) and new settings array keys.
					$value = '';
					if ( strpos( $setting_key, 'wp_mcp_ai_' ) === 0 ) {
						// Old format: wp_mcp_ai_openai_api_key -> check get_option first, then settings array.
						$value = get_option( $setting_key );
						if ( empty( $value ) ) {
							// Try to map old option name to new settings key.
							$mapped_key = str_replace( 'wp_mcp_ai_', '', $setting_key );
							$value      = isset( $settings[ $mapped_key ] ) ? $settings[ $mapped_key ] : '';
						}
					} else {
						// New format: just the key name (e.g., 'openai_api_key').
						$value = isset( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : '';
					}

					if ( empty( $value ) ) {
						return new WP_Error( 'missing_setting', "Required setting '{$setting}' is not configured" );
					}
				}
			}

			return true;
		}

		/**
		 * Check whether the private/custom base-mode entry point is active.
		 *
		 * Returns true only when mcp-ai-wpoos-base.php has been used as the entry
		 * point (which sets WP_MCP_AI_BASE_VERSION = true). That file is excluded
		 * from the WordPress.org distribution ZIP (.distignore).
		 *
		 * This method is NOT used to gate any tools in this registry — all tools
		 * in includes/tools/ are always registered regardless of this flag.
		 * The $is_base_version value is passed to the wp_mcp_ai_default_tools
		 * filter solely for backward compatibility with any third-party code
		 * that reads it.
		 *
		 * @return bool True only when the private base-mode entry point is active.
		 */
		protected function is_base_version() {
			/**
			 * Filter whether to enable base version mode.
			 *
			 * When true, only tools that work with a base WordPress installation are loaded.
			 * Tools requiring WooCommerce, JetEngine, JetFormBuilder, Elementor, RankMath,
			 * WPCode, or external API credentials are still registered but will report
			 * themselves as unavailable via their own is_available() checks.
			 *
			 * @param bool $is_base_version Whether base version mode is enabled.
			 */
			return apply_filters( 'wp_mcp_ai_base_version', function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() );
		}

		/**
		 * Load the plugin's default tool providers.
		 */
		protected function load_default_tools() {
			$is_base_version = $this->is_base_version();

			// Tools that work with base WordPress (no third-party plugins or external APIs).
			$base_tools = array(
				'WP_MCP_AI_Tool_Get_Recent_Posts'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php',
				'WP_MCP_AI_Tool_Search_Content'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-content.php',
				'WP_MCP_AI_Tool_Get_User_Info'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-user-info.php',
				'WP_MCP_AI_Tool_Get_Site_Summary'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-summary.php',
				'WP_MCP_AI_Tool_Count_Tokens'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-count-tokens.php',
				'WP_MCP_AI_Tool_Load_Skill'                => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-load-skill.php',
				// OpenAI API Integration - Phase 1 Tools.
				'WP_MCP_AI_Tool_List_OpenAI_Files'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-openai-files.php',
				'WP_MCP_AI_Tool_Get_OpenAI_File_Details'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-openai-file-details.php',
				'WP_MCP_AI_Tool_List_Available_Models'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-available-models.php',
				'WP_MCP_AI_Tool_Get_Model_Information'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-model-information.php',
				'WP_MCP_AI_Tool_Research_Model'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-research-model.php',
				'WP_MCP_AI_Tool_Add_Model_Config'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-add-model-config.php',
				'WP_MCP_AI_Tool_Discover_New_Models'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-discover-new-models.php',
				'WP_MCP_AI_Tool_Create_Text_Embeddings'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-text-embeddings.php',
				// OpenAI API Integration - Phase 2 Tools.
				'WP_MCP_AI_Tool_Semantic_Content_Search'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-semantic-content-search.php',
				'WP_MCP_AI_Tool_Suggest_Best_Model'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-suggest-best-model.php',
				'WP_MCP_AI_Tool_Batch_Embed_Content'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-batch-embed-content.php',
				// OpenAI API Integration - Phase 3 Tools (Vector Stores).
				'WP_MCP_AI_Tool_Create_Vector_Store'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-vector-store.php',
				'WP_MCP_AI_Tool_List_Vector_Stores'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-vector-stores.php',
				'WP_MCP_AI_Tool_Get_Vector_Store'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-vector-store.php',
				'WP_MCP_AI_Tool_Manage_Vector_Store_Files' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-manage-vector-store-files.php',
				// OpenAI API Integration - Phase 4 Tools.
				'WP_MCP_AI_Tool_Edit_OpenAI_Image'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-openai-image.php',
				'WP_MCP_AI_Tool_Create_Image_Variation'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-image-variation.php',
				'WP_MCP_AI_Tool_Analyze_File_Suitability'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-analyze-file-suitability.php',
				'WP_MCP_AI_Tool_OpenAI_Usage_Analytics'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-openai-usage-analytics.php',
				'WP_MCP_AI_Tool_Get_Site_Health'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-health.php',
				'WP_MCP_AI_Tool_Get_Environment_Status'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-environment-status.php',
				'WP_MCP_AI_Tool_Generate_Auth0_Token'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-auth0-token.php',
				'WP_MCP_AI_Tool_Get_NHC_Active_Storms'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-nhc-active-storms.php',
				'WP_MCP_AI_Tool_Search_Attachments'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-attachments.php',
				'WP_MCP_AI_Tool_Search_Gmail'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-gmail.php',
				'WP_MCP_AI_Tool_Search_Drive'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-drive.php',
				'WP_MCP_AI_Tool_Web_Search'                => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-search.php',
				'WP_MCP_AI_Tool_Crawl4AI_Price_Lookup'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-crawl4ai-price-lookup.php',
				'WP_MCP_AI_Tool_Get_GDACS_Events'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php',
				'WP_MCP_AI_Tool_Get_Open_Meteo_Forecast'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php',
				'WP_MCP_AI_Tool_Run_OpenAI_External_Action' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php',
				'WP_MCP_AI_Tool_Probe_Chat'                => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-probe-chat.php',
				'WP_MCP_AI_Tool_Probe_Remote_MCP'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-probe-remote-mcp.php',
				'WP_MCP_AI_Tool_Generate_OpenAI_Speech'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php',
				'WP_MCP_AI_Tool_Transcribe_OpenAI_Audio'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php',
				'WP_MCP_AI_Tool_Moderate_Content'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-moderate-content.php',
				// Browser-Native AI Tools (Phase 2: Transformers.js Integration).
				'WP_MCP_AI_Tool_Client_Summarize_Text'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-client-summarize-text.php',
				'WP_MCP_AI_Tool_Client_Analyze_Sentiment'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-client-analyze-sentiment.php',
				'WP_MCP_AI_Tool_Client_Extract_Entities'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-client-extract-entities.php',
				'WP_MCP_AI_Tool_Client_Translate_Text'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-client-translate-text.php',
				'WP_MCP_AI_Tool_Client_Question_Answering' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-client-question-answering.php',
				'WP_MCP_AI_Tool_Client_Semantic_Search'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-client-semantic-search.php',
				'WP_MCP_AI_Tool_Generate_OpenAI_Image'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php',
				'WP_MCP_AI_Tool_Generate_Sora_Video'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php',
				'WP_MCP_AI_Tool_Generate_Gemini_Image'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php',
				'WP_MCP_AI_Tool_Generate_CloudflareAI_Image' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-cloudflareai-image.php',
				'WP_MCP_AI_Tool_Generate_Veo_Video'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php',
				'WP_MCP_AI_Tool_Check_Video_Status'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-video-status.php',
				'WP_MCP_AI_Tool_Generate_Music'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-music.php',
				'WP_MCP_AI_Tool_Submit_Document_Prompt'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-submit-document-prompt.php',
				'WP_MCP_AI_Tool_Save_Post'                 => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-save-post.php',
				'WP_MCP_AI_Tool_Create_Post'               => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-post.php',
				'WP_MCP_AI_Tool_Get_Post'                  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-post.php',
				'WP_MCP_AI_Tool_Delete_Post'               => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-post.php',
				'WP_MCP_AI_Tool_Get_Post_Type_Schema'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-post-type-schema.php',
				'WP_MCP_AI_Tool_Create_Term'               => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-term.php',
				'WP_MCP_AI_Tool_Update_Term'               => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-term.php',
				'WP_MCP_AI_Tool_Create_Assistant'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-assistant.php',
				'WP_MCP_AI_Tool_Run_Crawl4AI_Job'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php',
				'WP_MCP_AI_Tool_Open_OpenAI_Logs'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-open-openai-logs.php',
				'WP_MCP_AI_Tool_Open_OpenAI_Usage'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-open-openai-usage.php',
				'WP_MCP_AI_Tool_Get_System_Logs'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-system-logs.php',
				'WP_MCP_AI_Tool_Get_Update_Status'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-update-status.php',
				'WP_MCP_AI_Tool_Create_Cron_Job'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php',
				'WP_MCP_AI_Tool_List_Cron_Jobs'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php',
				'WP_MCP_AI_Tool_Get_Cron_Job'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-cron-job.php',
				'WP_MCP_AI_Tool_Delete_Cron_Job'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php',
				'WP_MCP_AI_Tool_Send_Group_Email'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-group-email.php',
				'WP_MCP_AI_Tool_Purge_Cloudflare_Cache'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-purge-cloudflare-cache.php',
				'WP_MCP_AI_Tool_Purge_Varnish_Cache'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-purge-varnish-cache.php',
				'WP_MCP_AI_Tool_Purge_Cache'               => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-purge-cache.php',
				'WP_MCP_AI_Tool_ReliefWeb_Reports'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-reliefweb-reports.php',
				'WP_MCP_AI_Tool_Query_Remote_Site'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-query-remote-site.php',
				'WP_MCP_AI_Tool_Query_Mesh_Intelligent'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-query-mesh-intelligent.php',
				'WP_MCP_AI_Tool_Check_Site_Security'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-site-security.php',
				'WP_MCP_AI_Tool_Generate_Image_Alt_Text'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-image-alt-text.php',
				'WP_MCP_AI_Tool_Generate_Image_Caption'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-image-caption.php',
				'WP_MCP_AI_Tool_Analyze_Image'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-analyze-image.php',
				'WP_MCP_AI_Tool_Extract_Image_Text'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-extract-image-text.php',
				'WP_MCP_AI_Tool_Analyze_Video'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-analyze-video.php',
				'WP_MCP_AI_Tool_Generate_Video_Caption'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-video-caption.php',
				'WP_MCP_AI_Tool_Analyze_Comment_Content'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-analyze-comment-content.php',
				'WP_MCP_AI_Tool_Create_Chart'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-chart.php',
				// Profession management tools.
				'WP_MCP_AI_Tool_List_Professions'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-professions.php',
				'WP_MCP_AI_Tool_Get_Profession'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-profession.php',
				'WP_MCP_AI_Tool_Save_Profession'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-save-profession.php',
				'WP_MCP_AI_Tool_Profession_Stats'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-profession-stats.php',
				// Agent coordination tools (DeepSeek V4 multi-agent orchestration).
				'WP_MCP_AI_Tool_Create_Agent_Team'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-agent-team.php',
				'WP_MCP_AI_Tool_Delegate_To_Agent'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-delegate-to-agent.php',
				'WP_MCP_AI_Tool_Delegate_To_A2A_Agent'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-delegate-to-a2a-agent.php',
				'WP_MCP_AI_Tool_Aggregate_Agent_Results'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-aggregate-agent-results.php',
				// Agent memory tools (DeepSeek V4 Phase 5: State Management & Memory).
				'WP_MCP_AI_Tool_Store_Agent_Context'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-store-agent-context.php',
				'WP_MCP_AI_Tool_Retrieve_Agent_Memory'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-retrieve-agent-memory.php',
				'WP_MCP_AI_Tool_Prioritize_Context'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-prioritize-context.php',
				'WP_MCP_AI_Tool_Semantic_Context_Search'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-semantic-context-search.php',
				'WP_MCP_AI_Tool_Manage_Context_Lifecycle'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-manage-context-lifecycle.php',
				'WP_MCP_AI_Tool_Batch_Manage_Memory'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-batch-manage-memory.php',
				'WP_MCP_AI_Tool_Memory_Audit_Trail'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-memory-audit-trail.php',
				// MemPalace-inspired Phase 2: bulk ingest + session wake-up loader.
				'WP_MCP_AI_Tool_Mine_Agent_Memory'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-mine-agent-memory.php',
				'WP_MCP_AI_Tool_Wake_Up_Context'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-wake-up-context.php',
				// MemPalace Capture Framework Phase A8: hierarchical recall.
				'WP_MCP_AI_Tool_Recall_Memory'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-recall-memory.php',
				'WP_MCP_AI_Tool_Execute_Workflow'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-execute-workflow.php',
				'WP_MCP_AI_Tool_Check_Workflow_Health'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-workflow-health.php',
				// Advanced reasoning tools (DeepSeek V4 Phase 3: Reasoning Support).
				'WP_MCP_AI_Tool_Enable_Reasoning_Mode'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-enable-reasoning-mode.php',
				'WP_MCP_AI_Tool_Analyze_Code_Sequence'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-analyze-code-sequence.php',
				'WP_MCP_AI_Tool_Validate_Reasoning_Chain'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-validate-reasoning-chain.php',
				// Google Maps Platform tools.
				'WP_MCP_AI_Tool_Geocode_Address'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-geocode-address.php',
				'WP_MCP_AI_Tool_Search_Places'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-places.php',
				// Gemini Geospatial API tools.
				'WP_MCP_AI_Tool_Gemini_Geospatial_Query'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-gemini-geospatial-query.php',
				// Gemini image editing tool.
				'WP_MCP_AI_Tool_Edit_Gemini_Image'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php',
				// Product scraping tool.
				'WP_MCP_AI_Tool_Scrape_Product'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-scrape-product.php',
				// Image manipulation tools (Graphic Editor Suite).
				'WP_MCP_AI_Tool_Resize_Image'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-resize-image.php',
				'WP_MCP_AI_Tool_Crop_Image'                => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-crop-image.php',
				'WP_MCP_AI_Tool_Rotate_Image'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-rotate-image.php',
				'WP_MCP_AI_Tool_Convert_Image_Format'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-convert-image-format.php',
				'WP_MCP_AI_Tool_Vectorize_Image'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-vectorize-image.php',
				// HuggingFace Datasets API tools.
				'WP_MCP_AI_Tool_Huggingface_Dataset_Search' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-search.php',
				'WP_MCP_AI_Tool_Huggingface_Dataset_Get_Info' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-get-info.php',
				'WP_MCP_AI_Tool_Huggingface_Dataset_Get_Size' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-get-size.php',
				'WP_MCP_AI_Tool_Huggingface_Dataset_Get_Rows' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-get-rows.php',
				'WP_MCP_AI_Tool_Huggingface_Dataset_Preview_Rows' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-preview-rows.php',
				'WP_MCP_AI_Tool_Huggingface_Dataset_List_Splits' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-list-splits.php',
				'WP_MCP_AI_Tool_Huggingface_Dataset_Get_Statistics' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-get-statistics.php',
				'WP_MCP_AI_Tool_Huggingface_Dataset_Get_Parquet' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-get-parquet.php',
				'WP_MCP_AI_Tool_Huggingface_Dataset_Is_Valid' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-is-valid.php',
				'WP_MCP_AI_Tool_Huggingface_Dataset_Filter' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-filter.php',
				'WP_MCP_AI_Tool_Huggingface_Recommended_Datasets' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-huggingface-recommended-datasets.php',
				// Deep Research tool.
				'WP_MCP_AI_Tool_Deep_Research'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-deep-research.php',
				// Excel and Spreadsheet Tools - AI-powered formula generation.
				'WP_MCP_AI_Tool_Pro_Excel'                 => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-pro-excel.php',
				// Erlang C queuing-theory tools (Base).
				'WP_MCP_AI_Tool_Calculate_Erlang_C'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-calculate-erlang-c.php',
				'WP_MCP_AI_Tool_Erlang_C_Concurrency_Advisor' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-erlang-c-concurrency-advisor.php',
			);

			// Additional tools that require third-party plugins or external API credentials.
			$extended_tools = array(
				// Yahoo Fantasy Football Toolkit.
				'WP_MCP_AI_Tool_Yahoo_FF_Auth'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-auth.php',
				'WP_MCP_AI_Tool_Yahoo_FF_Get_Leagues'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-get-leagues.php',
				'WP_MCP_AI_Tool_Yahoo_FF_Get_Roster'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-get-roster.php',
				'WP_MCP_AI_Tool_Yahoo_FF_Get_Player_Stats' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-get-player-stats.php',
				'WP_MCP_AI_Tool_Yahoo_FF_Trade_Analyzer'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-trade-analyzer.php',
				'WP_MCP_AI_Tool_Yahoo_FF_League_Standings' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-league-standings.php',
				'WP_MCP_AI_Tool_FF_Generate_Team_Logo'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-ff-generate-team-logo.php',
				'WP_MCP_AI_Tool_FF_Create_League_Report'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-ff-create-league-report.php',
				'WP_MCP_AI_Tool_FF_Player_Research'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-ff-player-research.php',
				'WP_MCP_AI_Tool_Get_Elementor_Templates'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-elementor-templates.php',
				'WP_MCP_AI_Tool_Import_Elementor_Template_Kit' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php',
				'WP_MCP_AI_Tool_Get_Elementor_Form_Submissions' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-elementor-form-submissions.php',
				'WP_MCP_AI_Tool_Get_All_Form_Submissions'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-all-form-submissions.php',
				'WP_MCP_AI_Tool_Get_Woo_Orders'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php',
				'WP_MCP_AI_Tool_Get_Woo_Products'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-woo-products.php',
				'WP_MCP_AI_Tool_Create_Woo_Product'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-woo-product.php',
				// PayHere payment gateway integration.
				'WP_MCP_AI_Tool_PayHere_Get_Payment'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-payhere-get-payment.php',
				// Flowhub cannabis dispensary integration.
				'WP_MCP_AI_Tool_Flowhub_Get_Inventory'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-flowhub-get-inventory.php',
				'WP_MCP_AI_Tool_Flowhub_Get_Orders'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-flowhub-get-orders.php',
				'WP_MCP_AI_Tool_Flowhub_Create_Order'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-flowhub-create-order.php',
				'WP_MCP_AI_Tool_Flowhub_Get_Customers'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-flowhub-get-customers.php',
				'WP_MCP_AI_Tool_Flowhub_Manage_Customer'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-flowhub-manage-customer.php',
				'WP_MCP_AI_Tool_Flowhub_Get_Products'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-flowhub-get-products.php',
				'WP_MCP_AI_Tool_Flowhub_Manage_Product'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-flowhub-manage-product.php',
				'WP_MCP_AI_Tool_Get_JetEngine_Items'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php',
				'WP_MCP_AI_Tool_Get_JetFormBuilder_Forms'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-forms.php',
				'WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-submissions.php',
				'WP_MCP_AI_Tool_List_JetEngine_Routes'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php',
				'WP_MCP_AI_Tool_Invoke_JetEngine_Route'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php',
				'WP_MCP_AI_Tool_Get_RankMath_SEO'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-rankmath-seo.php',
				'WP_MCP_AI_Tool_Generate_Simple_JWT_Token' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-simple-jwt-token.php',
				'WP_MCP_AI_Tool_Vision_Product_Search'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-vision-product-search.php',
				'WP_MCP_AI_Tool_Vision_Object_Localization' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-vision-object-localization.php',
				// Newsletter plugin tools.
				'WP_MCP_AI_Tool_Newsletter_Add_Subscriber' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-newsletter-add-subscriber.php',
				'WP_MCP_AI_Tool_Newsletter_Get_Subscribers' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-newsletter-get-subscribers.php',
				'WP_MCP_AI_Tool_Newsletter_Unsubscribe'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-newsletter-unsubscribe.php',
				'WP_MCP_AI_Tool_Newsletter_Get_Subscriber_Stats' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-newsletter-get-subscriber-stats.php',
				'WP_MCP_AI_Tool_Newsletter_Create_Email'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-newsletter-create-email.php',
				'WP_MCP_AI_Tool_Newsletter_Get_Emails'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-newsletter-get-emails.php',
				// WP All Import/Export plugin tools.
				'WP_MCP_AI_Tool_List_All_Export_Templates' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-all-export-templates.php',
				'WP_MCP_AI_Tool_Trigger_All_Export'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-trigger-all-export.php',
				'WP_MCP_AI_Tool_List_All_Import_Templates' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-all-import-templates.php',
				'WP_MCP_AI_Tool_Trigger_All_Import'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-trigger-all-import.php',
				'WP_MCP_AI_Tool_Get_All_Import_Status'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-all-import-status.php',
				// Advanced graphic editing tool.
				'WP_MCP_AI_Tool_Graphic_Editor_Plus'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-graphic-editor-plus.php',
				// Erlang C contact-centre tools (Extended — optional WFM endpoint integration).
				'WP_MCP_AI_Tool_Erlang_C_Staffing_Advisor' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-erlang-c-staffing-advisor.php',
				'WP_MCP_AI_Tool_Erlang_C_Queue_Health'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-erlang-c-queue-health.php',
				// Project Management tools moved to Pro addon.
			);

			// Pro addon tools are loaded exclusively by the Pro addon (addons/pro/).
			// The Pro addon adds genuinely new tools that require PHP 8.1+ features.
			$pro_tools = array();

			// All tools shipped in this plugin are always attempted.
			// Extended tools self-report unavailability via is_available() when their
			// required third-party plugins (WooCommerce, JetEngine, etc.) are not active.
			// The Pro addon (addons/pro/) contributes entirely NEW tools — it does not
			// gate or unlock any tool already present in includes/tools/.
			$default_tools = array_merge( $base_tools, $extended_tools, $pro_tools );

			/**
			 * Filter the list of default tools to load.
			 *
			 * @param array $default_tools    Array of tool class names and file paths.
			 * @param bool  $is_base_version  Whether the private base-mode entry point is
			 *                                active (always false on WordPress.org installs).
			 */
			$default_tools = apply_filters( 'wp_mcp_ai_default_tools', $default_tools, $is_base_version );

			// Quiz tools are now loaded by the Pro addon.

			foreach ( $default_tools as $class => $file ) {
				if ( file_exists( $file ) ) {
					require_once $file;
				}

				if ( class_exists( $class ) ) {
					$should_register = true;

					if ( method_exists( $class, 'is_available' ) ) {
						$should_register = (bool) call_user_func( array( $class, 'is_available' ) );

						if ( ! $should_register && method_exists( $class, 'get_unavailable_reason' ) ) {
							$message = (string) call_user_func( array( $class, 'get_unavailable_reason' ) );
							if ( $message && ! in_array( $message, $this->unavailable_tool_messages, true ) ) {
								$this->unavailable_tool_messages[] = $message;
							}
						}
					}

					if ( $should_register ) {
						$this->register_tool( new $class() );
					}
				}
			}
		}

		/**
		 * Get the list of globally disabled tools.
		 *
		 * @return array Array of disabled tool slugs.
		 */
		public function get_disabled_tools() {
			$disabled = get_option( 'wp_mcp_ai_disabled_tools', array() );
			return is_array( $disabled ) ? $disabled : array();
		}

		/**
		 * Automatically disable tools marked with "bug" status.
		 *
		 * Reads the docs/tool-status.txt file and disables any tools with "bug" status
		 * to prevent them from being used until the bugs are resolved.
		 */
		protected function auto_disable_bug_tools() {
			$status_labels = $this->load_tool_status_labels();

			foreach ( $status_labels as $tool_slug => $status ) {
				if ( 'bug' === $status ) {
					// Check if the tool is registered.
					if ( isset( $this->tools[ $tool_slug ] ) ) {
						// Disable the tool if it's currently enabled.
						if ( $this->is_tool_enabled( $tool_slug ) ) {
							$this->disable_tool( $tool_slug );
						}
					}
				}
			}
		}

		/**
		 * Load tool status labels from docs/tool-status.txt file.
		 *
		 * @return array Associative array of tool slug => status label.
		 */
		protected function load_tool_status_labels() {
			static $status_labels = null;

			// Use static cache to avoid reading file multiple times.
			if ( null !== $status_labels ) {
				return $status_labels;
			}

			$status_labels = array();
			$status_file   = WP_MCP_AI_PATH . 'docs/tool-status.txt';

			// Check if file exists and is readable.
			if ( ! is_readable( $status_file ) ) {
				return $status_labels;
			}

			// Read file content. Use an explicit error handler instead of @
			// because the @ operator is not reliable across all PHP 8
			// configurations (e.g. xdebug.scream, custom error handlers).
			// A leaked warning corrupts MCP JSON-RPC HTTP responses when
			// this runs during a tools/list or tools/call request.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; failure handled below.
			set_error_handler( '__return_true' );
			$content = file_get_contents( $status_file );
			restore_error_handler();
			if ( false === $content ) {
				return $status_labels;
			}

			// Parse file line by line.
			$lines = explode( "\n", $content );
			foreach ( $lines as $line ) {
				// Trim whitespace.
				$line = trim( $line );

				// Skip empty lines and comments.
				if ( empty( $line ) || '#' === substr( $line, 0, 1 ) ) {
					continue;
				}

				// Parse line format: tool_slug = status_label.
				$parts = explode( '=', $line, 2 );
				if ( 2 !== count( $parts ) ) {
					continue;
				}

				$tool_slug    = trim( $parts[0] );
				$status_label = trim( $parts[1] );

				// Validate status label (only allow alphanumeric, hyphens, underscores).
				if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $status_label ) ) {
					continue;
				}

				$status_labels[ $tool_slug ] = $status_label;
			}

			return $status_labels;
		}

		/**
		 * Check if a tool is globally enabled.
		 *
		 * @param string $slug Tool slug.
		 * @return bool True if enabled, false if disabled.
		 */
		public function is_tool_enabled( $slug ) {
			$disabled_tools = $this->get_disabled_tools();
			return ! in_array( $slug, $disabled_tools, true );
		}

		/**
		 * Enable a tool globally.
		 *
		 * @param string $slug Tool slug.
		 * @return bool True on success, false on failure.
		 */
		public function enable_tool( $slug ) {
			$slug           = sanitize_key( $slug );
			$disabled_tools = $this->get_disabled_tools();

			$key = array_search( $slug, $disabled_tools, true );
			if ( false !== $key ) {
				unset( $disabled_tools[ $key ] );
				$disabled_tools = array_values( $disabled_tools ); // Re-index array.
				return update_option( 'wp_mcp_ai_disabled_tools', $disabled_tools );
			}

			return true; // Already enabled.
		}

		/**
		 * Disable a tool globally.
		 *
		 * @param string $slug Tool slug.
		 * @return bool True on success, false on failure.
		 */
		public function disable_tool( $slug ) {
			$slug           = sanitize_key( $slug );
			$disabled_tools = $this->get_disabled_tools();

			if ( ! in_array( $slug, $disabled_tools, true ) ) {
				$disabled_tools[] = $slug;
				return update_option( 'wp_mcp_ai_disabled_tools', $disabled_tools );
			}

			return true; // Already disabled.
		}

		/**
		 * Register a tool with execution context metadata.
		 *
		 * Enhanced registration that stores additional metadata about
		 * tool execution capabilities and requirements.
		 *
		 * @since 1.2.0
		 *
		 * @param string|WP_MCP_AI_Tool_Interface $tool     Tool class name or instance.
		 * @param array                           $contexts Execution contexts (e.g., 'client', 'server', 'worker').
		 * @return bool Whether the tool was registered.
		 */
		public function register_tool_with_context( $tool, $contexts = array( 'server' ) ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Parameter reserved for future context-aware registration.
			// For now, use legacy registration.
			return $this->register_tool( $tool );
		}

		/**
		 * Get tools that can execute in a specific context.
		 *
		 * @since 1.2.0
		 *
		 * @param string $context Execution context ('client', 'server', 'worker').
		 * @return array Array of tools that can execute in the specified context.
		 */
		public function get_tools_by_context( $context ) {
			$this->init();

			if ( 'client' === $context ) {
				return $this->get_client_executable_tools();
			}

			return $this->tools;
		}

		/**
		 * Get client-executable tools.
		 *
		 * Returns tools that are safe and capable of running client-side.
		 *
		 * @since 1.2.0
		 *
		 * @return array Array of client-executable tools.
		 */
		public function get_client_executable_tools() {
			$this->init();

			$client_safe_names = array(
				'client_summarize',
				'client_sentiment',
				'client_translate',
				'client_embed',
				'client_describe_image',
				'client_detect_objects',
				'client_transcribe_audio',
				'generate_chart',
				'generate_mermaid',
			);

			$client_tools = array();

			foreach ( $this->tools as $slug => $tool ) {
				if ( in_array( $slug, $client_safe_names, true ) ) {
					$client_tools[ $slug ] = $tool;
				}
			}

			return $client_tools;
		}

		/**
		 * Get tool metadata.
		 *
		 * Returns enhanced metadata for a tool if available.
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug Tool slug.
		 * @return array|null Tool metadata or null if not found.
		 */
		public function get_tool_metadata( $slug ) {
			$this->init();

			$slug = sanitize_key( $slug );

			if ( ! isset( $this->tools[ $slug ] ) ) {
				return null;
			}

			return array(
				'contexts'   => array( 'server' ),
				'complexity' => 'medium',
				'cacheable'  => false,
				'parallel'   => true,
			);
		}
	}
}
