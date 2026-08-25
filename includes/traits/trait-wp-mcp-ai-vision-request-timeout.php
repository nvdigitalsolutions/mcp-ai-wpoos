<?php
/**
 * Trait for resolving HTTP timeouts on vision (image analysis) requests.
 *
 * The image *generation* and *edit* tools have always exposed a per-call
 * `timeout` argument (5-300s) and the provider clients honour the global
 * `request_timeout` setting. The vision *analysis* tools historically
 * hardcoded `'timeout' => 30`, which silently ignored the site's configured
 * `request_timeout` (default 200s) and made large images fail with
 * "cURL error 28: Operation timed out after 30001 milliseconds".
 *
 * This trait gives the analysis tools the same dial.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for resolving HTTP timeouts on vision requests.
 *
 * Resolution order, highest priority first:
 * 1. The per-call `timeout` tool argument (clamped to 5-300 seconds).
 * 2. The global `request_timeout` plugin setting.
 * 3. `WP_MCP_AI_Resource_Manager::get_request_timeout()` (workload-tier aware).
 * 4. A hard floor of 5 seconds.
 *
 * Mirrors `WP_MCP_AI_Anthropic_Client::resolve_timeout()` so every provider
 * path in the plugin agrees on where the timeout comes from.
 *
 * @since 1.0.0
 */
trait WP_MCP_AI_Vision_Request_Timeout {

	/**
	 * Default timeout used when no setting and no resource manager are available.
	 *
	 * @var int
	 */
	protected $vision_timeout_fallback = 30;

	/**
	 * Get the `timeout` schema definition for tool parameters.
	 *
	 * Matches the schema already published by the image generation and edit
	 * tools so agents see one consistent contract across the image toolkit.
	 *
	 * @param string $provider_label Optional provider name for the description. Default 'vision'.
	 * @return array Parameter schema definition.
	 */
	protected function get_timeout_parameter_schema( $provider_label = 'vision' ) {
		return array(
			'type'        => 'integer',
			'description' => sprintf(
				/* translators: %s: provider or request label (e.g. "Gemini", "OpenAI", "vision") */
				__( 'Override the %s request timeout in seconds. Defaults to the global request timeout setting. Raise this for large images.', 'mcp-ai-wpoos' ),
				$provider_label
			),
			'minimum'     => 5,
			'maximum'     => 300,
		);
	}

	/**
	 * Resolve the HTTP timeout to use for a vision request.
	 *
	 * @param array $arguments Tool arguments that may contain a `timeout` key.
	 * @return int Timeout in seconds, never below 5.
	 */
	protected function resolve_vision_timeout( array $arguments = array() ) {
		$timeout = 0;

		// Global setting first, so an explicit per-call override can win below.
		$settings = $this->get_vision_timeout_settings();

		if ( isset( $settings['request_timeout'] ) ) {
			$timeout = absint( $settings['request_timeout'] );
		}

		// Workload-tier aware default when the setting is absent.
		if ( ! $timeout && class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
			$timeout = absint( WP_MCP_AI_Resource_Manager::instance()->get_request_timeout() );
		}

		if ( ! $timeout ) {
			$timeout = $this->vision_timeout_fallback;
		}

		// An explicit per-call argument overrides everything, clamped to the
		// 5-300 range advertised in get_timeout_parameter_schema().
		if ( isset( $arguments['timeout'] ) && absint( $arguments['timeout'] ) > 0 ) {
			$timeout = max( 5, min( 300, absint( $arguments['timeout'] ) ) );
		}

		$timeout = max( 5, $timeout );

		/**
		 * Filter the timeout used for vision (image analysis) HTTP requests.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $timeout   Resolved timeout in seconds.
		 * @param array  $arguments The tool arguments that produced this timeout.
		 * @param string $tool_slug Slug of the tool making the request.
		 */
		return (int) apply_filters(
			'wp_mcp_ai_vision_request_timeout',
			$timeout,
			$arguments,
			method_exists( $this, 'get_slug' ) ? $this->get_slug() : ''
		);
	}

	/**
	 * Read plugin settings for timeout resolution.
	 *
	 * Prefers the settings class (which applies defaults) and falls back to the
	 * raw option so the trait stays usable in isolation, e.g. under unit tests.
	 *
	 * @return array Plugin settings.
	 */
	private function get_vision_timeout_settings() {
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'get_settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( is_array( $settings ) ) {
				return $settings;
			}
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		return is_array( $settings ) ? $settings : array();
	}
}
