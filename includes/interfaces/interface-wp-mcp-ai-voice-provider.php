<?php
/**
 * Voice Provider Interface for NV oOS.
 *
 * Defines the contract that all realtime voice providers must implement.
 * Supports multiple transport modes: WebSocket S2S (OpenAI Realtime, Gemini Live),
 * chained pipeline (STT → LLM → TTS), and browser-native Web Speech API.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'WP_MCP_AI_Voice_Provider' ) ) {
	/**
	 * Voice provider contract.
	 */
	interface WP_MCP_AI_Voice_Provider {

		/**
		 * Get the unique provider slug.
		 *
		 * @return string Provider slug (e.g. 'openai_realtime', 'gemini_live').
		 */
		public function get_slug();

		/**
		 * Get the human-readable provider name.
		 *
		 * @return string Provider display name.
		 */
		public function get_name();

		/**
		 * Check if this voice provider is available given current configuration.
		 *
		 * @return bool True if the provider can be used.
		 */
		public function is_available();

		/**
		 * Get the transport mode for this provider.
		 *
		 * @return string 'realtime' | 'chained' | 'browser'.
		 */
		public function get_transport_mode();

		/**
		 * Create a session for the frontend to connect to.
		 *
		 * Returns configuration needed by the JavaScript client to establish
		 * a connection. For WebSocket providers this includes the endpoint URL
		 * and ephemeral credentials. For chained providers this includes the
		 * REST endpoints for STT and TTS.
		 *
		 * @param int   $assistant_id The assistant ID requesting the session.
		 * @param array $options      Optional overrides (model, voice, instructions, tools).
		 * @return array|WP_Error Session configuration or error.
		 */
		public function create_session( $assistant_id, $options = array() );

		/**
		 * Get the recommended voice model for this provider.
		 *
		 * @return string Model identifier.
		 */
		public function get_default_model();

		/**
		 * Get available voice names/presets for this provider.
		 *
		 * @return array List of voice name => label pairs.
		 */
		public function get_available_voices();

		/**
		 * Get the default voice name.
		 *
		 * @return string Voice name identifier.
		 */
		public function get_default_voice();
	}
}
