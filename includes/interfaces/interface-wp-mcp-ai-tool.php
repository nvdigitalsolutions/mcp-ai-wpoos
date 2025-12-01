<?php
/**
 * Interface that all WP MCP AI tools must implement.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared interface for tool providers.
 */
interface WP_MCP_AI_Tool_Interface {
	/**
	 * Unique slug for the tool.
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Human readable name for the tool.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Description of what the tool does.
	 *
	 * @return string
	 */
	public function get_description();

	/**
	 * JSON schema describing accepted parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema();

	/**
	 * Execute the tool with supplied arguments.
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() );
}

/**
 * Optional interface for tools that expose predefined shortcut tasks.
 */
interface WP_MCP_AI_Tool_Shortcuts_Interface {
	/**
	 * Provide shortcut task metadata for this tool.
	 *
	 * Returning `null` signals that the tool does not expose any predefined
	 * shortcuts and that the shortcode renderer should not add fallback
	 * buttons automatically.
	 *
	 * @return array[]|null Array of associative arrays containing task metadata
	 *                      or null to opt out of automatic shortcut creation.
	 */
	public function get_shortcut_tasks();
}

/**
 * Optional interface for tools that want to control automatic fallback shortcuts.
 */
interface WP_MCP_AI_Tool_Fallback_Shortcut_Interface {
	/**
	 * Decide whether a fallback shortcut should be registered automatically.
	 *
	 * Returning false opts the tool out of the generic fallback entry that
	 * mirrors the tool slug, while still allowing the global "What can you do?"
	 * shortcut to be appended later in the process.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return bool
	 */
	public function should_register_fallback_shortcut( $assistant_id );
}

/**
 * Optional interface for tools that expose capability flags for orchestration.
 *
 * Capability flags provide metadata beyond grouping to help orchestrate
 * agentic workflows without errors by identifying tool requirements and
 * characteristics.
 */
interface WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Retrieve capability flags for this tool.
	 *
	 * Capability flags help orchestrate agentic workflows by providing
	 * metadata about tool requirements and characteristics.
	 *
	 * Standard flags (Requirement Flags):
	 * - 'requires-credentials': Tool requires external API credentials
	 * - 'requires-plugin': Tool requires a specific WordPress plugin
	 * - 'requires-capability': Tool requires specific WordPress user capabilities
	 * - 'requires-model': Tool requires AI model specification
	 * - 'requires-vision-model': Tool requires vision-capable AI model
	 * - 'requires-multimodal-model': Tool requires multimodal AI model
	 * - 'requires-video-model': Tool requires video-capable AI model
	 *
	 * Standard flags (Operational Characteristics):
	 * - 'read-only': Tool only reads data, does not modify state
	 * - 'write': Tool creates or modifies data
	 * - 'state-changing': Tool modifies database or site state
	 * - 'reversible': Changes can be undone (e.g., via revisions)
	 * - 'idempotent': Tool can be called multiple times safely with same result
	 * - 'performance-impact': Tool may temporarily affect site performance
	 * - 'consumes-tokens': Tool uses AI model tokens/credits
	 * - 'model-dependent': Tool behavior varies by AI model selected
	 *
	 * Standard flags (Network & Performance):
	 * - 'local-only': Tool works entirely locally (no external API calls)
	 * - 'external-api': Tool makes external HTTP requests
	 * - 'network-dependent': Tool requires internet connectivity
	 * - 'async': Tool may take significant time to complete
	 * - 'rate-limited': Tool is subject to rate limiting
	 * - 'deferred-result': Result available later, not immediately
	 * - 'requires-polling': May need to poll for completion status
	 * - 'supports-webhook': Can notify via webhook when complete
	 * - 'requires-callback': Needs callback URL for result delivery
	 * - 'long-running': Execution may take minutes or hours
	 * - 'may-timeout': May exceed typical HTTP request timeout
	 * - 'background-only': Must run in background to avoid timeouts
	 * - 'streaming-capable': Supports streaming responses
	 *
	 * Standard flags (Data Characteristics):
	 * - 'cacheable': Tool results can be cached
	 * - 'non-deterministic': Results may vary over time for same inputs
	 * - 'pii-data': Tool returns personally identifiable information
	 * - 'large-response': May return large data sets (>1MB)
	 * - 'paginated': Supports pagination to manage response size
	 * - 'supports-compression': Can compress output to reduce size
	 *
	 * @return array<string> Array of capability flag strings.
	 */
	public function get_capability_flags();
}

/**
 * Optional interface for tools that require specific model capabilities.
 *
 * Model capability requirements specify what AI model features are needed
 * for the tool to function correctly (e.g., vision, image generation, multimodal).
 *
 * @since 1.0.0
 */
interface WP_MCP_AI_Tool_Model_Requirements_Interface {
	/**
	 * Get required model capabilities for this tool.
	 *
	 * Returns an array of model capability flags that determine which
	 * AI models are compatible with this tool. These flags are used
	 * to filter available models in dropdowns and enforce compatibility.
	 *
	 * Standard model capability flags:
	 * - 'vision': Model can process and understand images
	 * - 'multimodal': Model can handle text, images, audio, video
	 * - 'image-generation': Model can generate images from text
	 * - 'image-editing': Model can edit/modify existing images
	 * - 'audio': Model can process audio input
	 * - 'video': Model can process video input
	 * - 'function-calling': Model supports native function/tool calling
	 * - 'code-execution': Model can execute code
	 * - 'web-search': Model has web search capabilities
	 *
	 * @return array<string> Array of required model capability flags.
	 */
	public function get_model_requirements();
}

/**
 * Optional interface for tools that define specific execution rules.
 *
 * Tool-specific rules provide detailed constraints and requirements
 * that go beyond capability flags, enabling precise orchestration control.
 */
interface WP_MCP_AI_Tool_Rules_Interface {
	/**
	 * Retrieve tool-specific execution rules.
	 *
	 * Rules define constraints, requirements, and behaviors that the
	 * orchestrator should enforce before and during tool execution.
	 *
	 * Example rule structure:
	 * array(
	 *     'model_requirements' => array(
	 *         'providers' => array( 'openai', 'anthropic' ),  // Allowed providers
	 *         'models' => array( 'gpt-4', 'claude-3-opus' ),  // Specific models
	 *         'min_context_window' => 8000,                   // Minimum context
	 *         'capabilities' => array( 'vision', 'tools' ),   // Required capabilities
	 *     ),
	 *     'parameter_constraints' => array(
	 *         'max_items' => 100,              // Maximum items to process
	 *         'required_fields' => array( 'prompt', 'model' ),
	 *         'optional_fields' => array( 'temperature', 'max_tokens' ),
	 *     ),
	 *     'rate_limits' => array(
	 *         'requests_per_minute' => 20,
	 *         'requests_per_hour' => 500,
	 *         'concurrent_requests' => 5,
	 *     ),
	 *     'timeout_constraints' => array(
	 *         'max_execution_time' => 120,     // seconds
	 *         'recommended_timeout' => 60,
	 *         'must_use_background' => true,
	 *     ),
	 *     'response_constraints' => array(
	 *         'max_size' => 5242880,           // 5MB
	 *         'supports_streaming' => true,
	 *         'supports_pagination' => true,
	 *         'default_page_size' => 20,
	 *     ),
	 *     'dependencies' => array(
	 *         'required_plugins' => array( 'woocommerce' ),
	 *         'required_extensions' => array( 'gd', 'imagick' ),
	 *         'required_settings' => array( 'api_key' => 'wp_mcp_ai_openai_api_key' ),
	 *     ),
	 *     'orchestration_hints' => array(
	 *         'can_run_parallel' => false,     // Can multiple instances run concurrently?
	 *         'requires_lock' => true,         // Needs exclusive execution lock?
	 *         'cache_ttl' => 300,              // Cache time-to-live in seconds
	 *         'retry_strategy' => 'exponential_backoff',
	 *         'max_retries' => 3,
	 *     ),
	 * )
	 *
	 * @return array Associative array of tool-specific rules.
	 */
	public function get_tool_rules();
}

/**
 * Optional interface for tools that declare flow stage eligibility.
 *
 * Flow stage eligibility controls when a tool can be invoked during
 * an agentic workflow based on the current stage of execution.
 *
 * @since 1.0.0
 */
interface WP_MCP_AI_Tool_Flow_Stage_Interface {
	/**
	 * Retrieve the eligible flow stages for this tool.
	 *
	 * Tools can be restricted to specific stages of an agentic workflow:
	 * - 'anytime': Tool can be used at any stage (default)
	 * - 'start': Tool can only be used in the first iteration (iteration 0)
	 * - 'middle': Tool can only be used in middle iterations (1 to n-1)
	 * - 'end': Tool can only be used in the final iteration
	 *
	 * Multiple stages can be specified, e.g., array('start', 'middle')
	 *
	 * @return array<string> Array of eligible stage identifiers.
	 */
	public function get_flow_stages();
}

/**
 * Optional interface for tools that restrict access from certain contexts.
 *
 * Context restrictions control which endpoints or interfaces can invoke a tool.
 * This is useful for preventing sensitive operations from public-facing interfaces.
 *
 * @since 1.0.0
 */
interface WP_MCP_AI_Tool_Context_Restrictions_Interface {
	/**
	 * Determine if the tool can be used in the given context.
	 *
	 * Common contexts include:
	 * - 'chat-client': Browser-based public chat interface
	 * - 'chat': MCP protocol endpoint (more controlled)
	 * - 'direct': Direct tool invocation via REST API
	 * - 'shortcode': Shortcode-based invocation
	 *
	 * @param array $context Execution context with 'endpoint' or 'source' keys.
	 * @return true|WP_Error True if allowed, WP_Error if restricted.
	 */
	public function is_allowed_in_context( $context );
}
