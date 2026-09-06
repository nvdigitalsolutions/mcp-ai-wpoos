<?php
/**
 * Model Integrity Verifier — Validates AI model artifacts before use
 * to prevent supply-chain attacks (OWASP LLM03: Supply Chain).
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-model-integrity-verifier.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Option keys, blocked-model semantics, TLS checks,
 * vulnerability filter, and integrity logging are byte-identical.
 *
 * Decoupling (documented, additive): `get_provider_endpoint()` reads the
 * base `wp_mcp_ai_{provider}_endpoint` option in monolith installs and
 * the content-graph settings store endpoints (`ollama_base_url`,
 * `lmstudio_base_url`) in standalone installs.
 *
 * @package NvoosContentGraphAi\Model
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Model;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model supply-chain integrity verifier.
 *
 * @since 1.1.0
 */
class ModelIntegrityVerifier {

	const OPTION_MODEL_HASHES   = 'wp_mcp_ai_model_hashes';
	const OPTION_BLOCKED_MODELS = 'wp_mcp_ai_blocked_models';
	const OPTION_INTEGRITY_LOG  = 'wp_mcp_ai_integrity_log';

	/**
	 * Verify a model-provider combination before use.
	 *
	 * @param string $model    Model identifier (e.g., 'gpt-4o').
	 * @param string $provider Provider identifier (e.g., 'openai').
	 * @return true|\WP_Error True if verified, WP_Error on failure.
	 */
	public static function verify_model( $model, $provider ) {
		// Check 1: Blocked model list.
		$blocked = get_option( self::OPTION_BLOCKED_MODELS, array() );
		$key     = $provider . '/' . $model;
		if ( isset( $blocked[ $key ] ) && ! empty( $blocked[ $key ] ) ) {
			$reason = $blocked[ $key ];
			self::log_integrity_check( $model, $provider, 'blocked', $reason );
			return new \WP_Error(
				'wp_mcp_ai_model_blocked',
				sprintf(
					/* translators: 1: model name, 2: provider, 3: reason */
					__( 'Model %1$s from %2$s is blocked: %3$s', 'nvoos-content-graph-ai' ),
					$model,
					$provider,
					$reason
				)
			);
		}

		// Check 2: Provider endpoint TLS verification.
		$endpoint_valid = self::verify_endpoint_tls( $provider );
		if ( is_wp_error( $endpoint_valid ) ) {
			self::log_integrity_check( $model, $provider, 'tls_failure', $endpoint_valid->get_error_message() );
			return $endpoint_valid;
		}

		// Check 3: Known-vulnerable model version.
		$vulnerability = self::check_known_vulnerabilities( $model, $provider );
		if ( is_wp_error( $vulnerability ) ) {
			self::log_integrity_check( $model, $provider, 'vulnerable', $vulnerability->get_error_message() );
			return $vulnerability;
		}

		self::log_integrity_check( $model, $provider, 'passed', '' );
		return true;
	}

	/**
	 * Block a specific model-provider combination.
	 *
	 * @param string $model    Model identifier.
	 * @param string $provider Provider identifier.
	 * @param string $reason   Reason for blocking.
	 * @return bool True on success.
	 */
	public static function block_model( $model, $provider, $reason ) {
		$blocked          = get_option( self::OPTION_BLOCKED_MODELS, array() );
		$key              = $provider . '/' . $model;
		$blocked[ $key ]  = sanitize_text_field( $reason );
		return update_option( self::OPTION_BLOCKED_MODELS, $blocked, false );
	}

	/**
	 * Unblock a previously blocked model.
	 *
	 * @param string $model    Model identifier.
	 * @param string $provider Provider identifier.
	 * @return bool True on success.
	 */
	public static function unblock_model( $model, $provider ) {
		$blocked = get_option( self::OPTION_BLOCKED_MODELS, array() );
		$key     = $provider . '/' . $model;
		unset( $blocked[ $key ] );
		return update_option( self::OPTION_BLOCKED_MODELS, $blocked, false );
	}

	/**
	 * Get all currently blocked models.
	 *
	 * @return array Array of model_key => reason.
	 */
	public static function get_blocked_models() {
		return get_option( self::OPTION_BLOCKED_MODELS, array() );
	}

	/**
	 * Verify the provider endpoint uses valid TLS.
	 *
	 * @param string $provider Provider identifier.
	 * @return true|\WP_Error
	 */
	private static function verify_endpoint_tls( $provider ) {
		// For hosted providers, we trust their endpoints.
		// For self-hosted/Ollama/LM Studio, verify the endpoint is HTTPS
		// when not on localhost (privacy concern for API key transmission).

		$self_hosted = array( 'ollama', 'lm_studio' );

		if ( ! in_array( $provider, $self_hosted, true ) ) {
			return true; // Trusted hosted provider.
		}

		// For self-hosted providers, check that non-localhost connections use HTTPS.
		$endpoint = self::get_provider_endpoint( $provider );
		if ( empty( $endpoint ) ) {
			return true; // No endpoint configured — defer to connection-time check.
		}

		$parsed = wp_parse_url( $endpoint );
		$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';

		// Allow localhost/127.0.0.1 without HTTPS.
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		// Non-localhost must use HTTPS.
		if ( empty( $parsed['scheme'] ) || 'https' !== $parsed['scheme'] ) {
			return new \WP_Error(
				'wp_mcp_ai_endpoint_not_https',
				sprintf(
					/* translators: %s: provider name */
					__( 'Self-hosted provider %s must use HTTPS for non-localhost connections to protect API keys in transit.', 'nvoos-content-graph-ai' ),
					$provider
				)
			);
		}

		return true;
	}

	/**
	 * Check if a model version has known vulnerabilities.
	 *
	 * @param string $model    Model identifier.
	 * @param string $provider Provider identifier.
	 * @return true|\WP_Error
	 */
	private static function check_known_vulnerabilities( $model, $provider ) {
		// Known-vulnerable model versions (maintained as a local list).
		$known_vulnerabilities = array(
			// Format: 'provider/model' => 'vulnerability description'
			// Example entries — update from threat intelligence feeds.
		);

		/**
		 * Filter known model vulnerabilities.
		 *
		 * @since 1.1.0
		 *
		 * @param array $vulnerabilities Array of provider/model => description.
		 */
		$known_vulnerabilities = apply_filters( 'wp_mcp_ai_known_model_vulnerabilities', $known_vulnerabilities );

		$key = $provider . '/' . $model;
		if ( isset( $known_vulnerabilities[ $key ] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_model_vulnerable',
				sprintf(
					/* translators: 1: model, 2: provider, 3: vulnerability description */
					__( 'Model %1$s from %2$s has a known vulnerability: %3$s', 'nvoos-content-graph-ai' ),
					$model,
					$provider,
					$known_vulnerabilities[ $key ]
				)
			);
		}

		return true;
	}

	/**
	 * Get the configured endpoint for a provider.
	 *
	 * @param string $provider Provider identifier.
	 * @return string Endpoint URL or empty string.
	 */
	private static function get_provider_endpoint( $provider ) {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$option_key = 'wp_mcp_ai_' . $provider . '_endpoint';
			$endpoint   = get_option( $option_key, '' );
		} else {
			$map = array(
				'ollama'    => 'ollama_base_url',
				'lm_studio' => 'lmstudio_base_url',
			);

			$key      = isset( $map[ $provider ] ) ? $map[ $provider ] : '';
			$endpoint = '' !== $key ? (string) CoreBridge::instance()->settings->get( $key, '' ) : '';
		}

		/**
		 * Filter the provider endpoint for integrity verification.
		 *
		 * @since 1.1.0
		 *
		 * @param string $endpoint The configured endpoint.
		 * @param string $provider The provider identifier.
		 */
		return apply_filters( 'wp_mcp_ai_provider_endpoint', $endpoint, $provider );
	}

	/**
	 * Log an integrity check result.
	 *
	 * @param string $model    Model identifier.
	 * @param string $provider Provider identifier.
	 * @param string $status   Check status (passed, blocked, tls_failure, vulnerable).
	 * @param string $detail   Additional detail.
	 * @return void
	 */
	private static function log_integrity_check( $model, $provider, $status, $detail ) {
		$log = get_option( self::OPTION_INTEGRITY_LOG, array() );

		$entry = array(
			'timestamp' => current_time( 'mysql' ),
			'model'     => $model,
			'provider'  => $provider,
			'status'    => $status,
			'detail'    => $detail,
		);

		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 500 );
		update_option( self::OPTION_INTEGRITY_LOG, $log, false );
	}

	/**
	 * Get integrity check logs.
	 *
	 * @param int $limit Maximum entries to return.
	 * @return array Log entries.
	 */
	public static function get_integrity_log( $limit = 50 ) {
		$log = get_option( self::OPTION_INTEGRITY_LOG, array() );
		return array_slice( $log, 0, absint( $limit ) );
	}
}
