<?php
/**
 * Voice Provider Interface for the Content Graph AI addon.
 *
 * Ported from the base plugin's
 * `includes/interfaces/interface-wp-mcp-ai-voice-provider.php`
 * (behaviour-preserving; base copy is retained permanently — ecosystem
 * port plan D-NOBASE). Defines the contract that all realtime voice
 * providers must implement.
 *
 * @package NvoosContentGraphAi\Provider
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Voice provider contract.
 *
 * @since 1.1.0
 */
interface VoiceProviderInterface {

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
	 * @param int   $assistant_id The assistant ID requesting the session.
	 * @param array $options      Optional overrides (model, voice, instructions, tools).
	 * @return array|\WP_Error Session configuration or error.
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
