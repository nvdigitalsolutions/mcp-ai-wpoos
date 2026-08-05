<?php
/**
 * Embedded LLM Backend Interface
 *
 * Every inference backend (client-side WebLLM, server-side llama.cpp, future
 * providers) implements this contract. Follows the same multi-backend pattern
 * established by the STT system (STTServiceAPI in JS, STT backends in PHP).
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for embedded LLM inference backends.
 *
 * @since 0.2.0
 */
interface NV_oOS_Embedded_LLM_Backend {

	/**
	 * Unique machine-readable identifier.
	 *
	 * @since 0.2.0
	 *
	 * @return string e.g. 'client_side', 'server_side'
	 */
	public function get_slug();

	/**
	 * Human-readable display name.
	 *
	 * @since 0.2.0
	 *
	 * @return string e.g. 'Client-Side WebLLM (Browser)'
	 */
	public function get_label();

	/**
	 * One-paragraph description for settings UI.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function get_description();

	/**
	 * Whether this backend can operate in the current environment.
	 *
	 * Client-side always returns true (no server requirements).
	 * Server-side checks shell_exec, binary path, PHP functions.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Human-readable requirements list for diagnostics and Site Health.
	 *
	 * @since 0.2.0
	 *
	 * @return array Array of requirement arrays, each with keys:
	 *               label (string), status (bool), note (string).
	 */
	public function get_requirements();

	/**
	 * Execute a chat completion request.
	 *
	 * Client-side backends return configuration for browser JS.
	 * Server-side backends execute inference directly.
	 *
	 * @since 0.2.0
	 *
	 * @param array $messages Chat messages in OpenAI format.
	 * @param array $options  Model, temperature, max_tokens, stream, etc.
	 * @return array|WP_Error Result array or error.
	 */
	public function create_chat_completion( array $messages, array $options );

	/**
	 * List models available through this backend.
	 *
	 * @since 0.2.0
	 *
	 * @return array Array of model definitions with slug, label, size_mb,
	 *               context_window, recommended (optional).
	 */
	public function get_available_models();

	/**
	 * Health status for WordPress Site Health integration.
	 *
	 * @since 0.2.0
	 *
	 * @return array Associative array with keys: status (good|recommended|critical),
	 *               label, description, actions (optional), test (Site Health test data).
	 */
	public function get_health_status();
}
