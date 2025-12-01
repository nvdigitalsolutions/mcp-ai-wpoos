<?php
/**
 * Tool Response Adapter.
 *
 * Transforms provider-specific responses into generic standardized responses.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-generic-tool-response.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-generic-tool-response-impl.php';

/**
 * Adapter class for transforming AI provider responses.
 *
 * This class provides static methods to convert provider-specific response
 * formats into a standardized GenericToolResponse object.
 */
class WP_MCP_AI_Tool_Response_Adapter {

	/**
	 * Create a GenericToolResponse from an OpenAI response.
	 *
	 * @param array|WP_Error $raw_response The raw response from OpenAI client.
	 * @return WP_MCP_AI_Generic_Tool_Response
	 */
	public static function from_openai( $raw_response ) {
		if ( is_wp_error( $raw_response ) ) {
			return self::from_wp_error( $raw_response, 'openai' );
		}

		// OpenAI responses are already in the normalized format.
		$normalized = $raw_response;

		if ( ! isset( $normalized['provider'] ) ) {
			$normalized['provider'] = 'openai';
		}

		return new WP_MCP_AI_Generic_Tool_Response_Impl( $normalized, $raw_response, true );
	}

	/**
	 * Create a GenericToolResponse from a Gemini response.
	 *
	 * @param array|WP_Error $raw_response The raw response from Gemini client.
	 * @return WP_MCP_AI_Generic_Tool_Response
	 */
	public static function from_gemini( $raw_response ) {
		if ( is_wp_error( $raw_response ) ) {
			return self::from_wp_error( $raw_response, 'gemini' );
		}

		// Gemini client already normalizes responses to OpenAI format.
		$normalized = $raw_response;

		if ( ! isset( $normalized['provider'] ) ) {
			$normalized['provider'] = 'gemini';
		}

		return new WP_MCP_AI_Generic_Tool_Response_Impl( $normalized, $raw_response, true );
	}

	/**
	 * Create a GenericToolResponse from an Anthropic response.
	 *
	 * @param array|WP_Error $raw_response The raw response from Anthropic client.
	 * @return WP_MCP_AI_Generic_Tool_Response
	 */
	public static function from_anthropic( $raw_response ) {
		if ( is_wp_error( $raw_response ) ) {
			return self::from_wp_error( $raw_response, 'anthropic' );
		}

		// Anthropic client already normalizes responses to OpenAI format.
		$normalized = $raw_response;

		if ( ! isset( $normalized['provider'] ) ) {
			$normalized['provider'] = 'anthropic';
		}

		return new WP_MCP_AI_Generic_Tool_Response_Impl( $normalized, $raw_response, true );
	}

	/**
	 * Create a GenericToolResponse from an Ollama response.
	 *
	 * @param array|WP_Error $raw_response The raw response from Ollama client.
	 * @return WP_MCP_AI_Generic_Tool_Response
	 */
	public static function from_ollama( $raw_response ) {
		if ( is_wp_error( $raw_response ) ) {
			return self::from_wp_error( $raw_response, 'ollama' );
		}

		// Ollama client already normalizes responses to OpenAI format.
		$normalized = $raw_response;

		if ( ! isset( $normalized['provider'] ) ) {
			$normalized['provider'] = 'ollama';
		}

		return new WP_MCP_AI_Generic_Tool_Response_Impl( $normalized, $raw_response, true );
	}

	/**
	 * Create a GenericToolResponse from an LM Studio response.
	 *
	 * @param array|WP_Error $raw_response The raw response from LM Studio client.
	 * @return WP_MCP_AI_Generic_Tool_Response
	 */
	public static function from_lm_studio( $raw_response ) {
		if ( is_wp_error( $raw_response ) ) {
			return self::from_wp_error( $raw_response, 'lm-studio' );
		}

		// LM Studio uses OpenAI-compatible format.
		$normalized = $raw_response;

		if ( ! isset( $normalized['provider'] ) ) {
			$normalized['provider'] = 'lm-studio';
		}

		return new WP_MCP_AI_Generic_Tool_Response_Impl( $normalized, $raw_response, true );
	}

	/**
	 * Create a GenericToolResponse from a WP_Error.
	 *
	 * @param WP_Error $error    The WP_Error object.
	 * @param string   $provider The provider identifier.
	 * @return WP_MCP_AI_Generic_Tool_Response
	 */
	protected static function from_wp_error( WP_Error $error, $provider = 'unknown' ) {
		$error_data = $error->get_error_data();
		$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? $error_data['status'] : 500;

		$normalized = array(
			'provider' => $provider,
			'error'    => array(
				'code'    => $status,
				'message' => $error->get_error_message(),
				'type'    => $error->get_error_code(),
			),
		);

		$original = array(
			'error' => array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error_data,
			),
		);

		return new WP_MCP_AI_Generic_Tool_Response_Impl( $normalized, $original, false );
	}

	/**
	 * Get the appropriate adapter method based on provider identifier.
	 *
	 * @param string $provider Provider identifier (e.g., 'openai', 'gemini').
	 * @return callable|null The adapter method, or null if provider not supported.
	 */
	public static function get_adapter_for_provider( $provider ) {
		$provider = sanitize_key( $provider );

		$adapters = array(
			'openai'    => array( __CLASS__, 'from_openai' ),
			'gemini'    => array( __CLASS__, 'from_gemini' ),
			'anthropic' => array( __CLASS__, 'from_anthropic' ),
			'ollama'    => array( __CLASS__, 'from_ollama' ),
			'lm-studio' => array( __CLASS__, 'from_lm_studio' ),
			'lm_studio' => array( __CLASS__, 'from_lm_studio' ),
		);

		return isset( $adapters[ $provider ] ) ? $adapters[ $provider ] : null;
	}
}
