<?php
/**
 * includes/tools/healthcare/class-wp-mcp-ai-openmed-client.php (ecosystem port — Wave F4, healthcare toolkit data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/class-wp-mcp-ai-openmed-client.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in monolith installs — the
 * addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
		 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`
		 * (incl. the source's two `nvoos-embedded` strings — the monolith file already carried the
		 * embedded addon's domain; the ported copy normalizes it);
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps where the source references the Pro addon path
 * (the `defined( 'WP_MCP_AI_PRO_VERSION' )` base-mode gates stay byte-identical).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenMed HTTP client.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_OpenMed_Client {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION = 'wp_mcp_ai_openmed_settings';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * OpenMed service base URL.
	 *
	 * @var string
	 */
	private $base_url = '';

	/**
	 * API key for OpenMed service authentication.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 30;

	/**
	 * Whether to verify SSL certificates.
	 *
	 * @var bool
	 */
	private $verify_ssl = true;

	/**
	 * Whether the client has been configured.
	 *
	 * @var bool
	 */
	private $configured = false;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.4.0
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->load_settings();
		}
		return self::$instance;
	}

	/**
	 * Load configuration from WordPress settings.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	private function load_settings() {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		$this->base_url   = isset( $settings['service_url'] ) ? esc_url_raw( $settings['service_url'] ) : '';
		$this->api_key    = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$this->timeout    = isset( $settings['timeout'] ) ? absint( $settings['timeout'] ) : 30;
		$this->verify_ssl = isset( $settings['verify_ssl'] ) ? (bool) $settings['verify_ssl'] : true;
		$this->configured = ! empty( $this->base_url );
	}

	/**
	 * Check whether the client has a configured service URL.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$instance = self::get_instance();
		return $instance->configured;
	}

	// ── Health & Discovery ──────────────────────────────────────────────

	/**
	 * Check the OpenMed service health endpoint.
	 *
	 * @since 1.4.0
	 *
	 * @return array|WP_Error Health status or error.
	 */
	public function health() {
		return $this->request( 'GET', '/health' );
	}

	/**
	 * Get models currently loaded in the OpenMed service.
	 *
	 * @since 1.4.0
	 *
	 * @return array|WP_Error List of loaded models or error.
	 */
	public function get_loaded_models() {
		return $this->request( 'GET', '/models' );
	}

	/**
	 * Unload a model from the OpenMed service.
	 *
	 * @since 1.4.0
	 *
	 * @param string|null $model_name Model name to unload, or null for all.
	 * @param bool        $all        Whether to unload all models.
	 * @return array|WP_Error Result or error.
	 */
	public function unload_model( $model_name = null, $all = false ) {
		$body = array();
		if ( $all ) {
			$body['all'] = true;
		} elseif ( null !== $model_name ) {
			$body['model_name'] = $model_name;
		}

		return $this->request( 'POST', '/models/unload', $body );
	}

	// ── PII / PHI Operations ────────────────────────────────────────────

	/**
	 * Extract PII entities from text.
	 *
	 * Detects all 18 HIPAA Safe Harbor identifiers using the configured
	 * PII detection model.
	 *
	 * @since 1.4.0
	 *
	 * @param string $text  Clinical text to scan.
	 * @param array  $opts  Optional overrides: model, language, min_confidence.
	 * @return array|WP_Error Detected entities or error.
	 */
	public function extract_pii( $text, $opts = array() ) {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		$body = array(
			'text'  => $text,
			'model' => isset( $opts['model'] ) ? $opts['model'] : ( isset( $settings['default_pii_model'] ) ? $settings['default_pii_model'] : 'OpenMed/OpenMed-PII-SuperClinical-Small-44M-v1' ),
			'lang'  => isset( $opts['language'] ) ? $opts['language'] : ( isset( $settings['default_lang'] ) ? $settings['default_lang'] : 'en' ),
		);

		if ( isset( $opts['min_confidence'] ) ) {
			$body['min_confidence'] = (float) $opts['min_confidence'];
		}

		return $this->request( 'POST', '/pii/extract', $body );
	}

	/**
	 * De-identify clinical text.
	 *
	 * Removes or masks PHI using the configured de-identification method.
	 * Supports: mask, remove, replace, hash, shift_dates.
	 *
	 * @since 1.4.0
	 *
	 * @param string $text    Clinical text to de-identify.
	 * @param string $method  De-identification method (mask|remove|replace|hash|shift_dates).
	 * @param array  $opts    Optional overrides: model, language, replacement_text.
	 * @return array|WP_Error De-identified result or error.
	 */
	public function deidentify( $text, $method = 'mask', $opts = array() ) {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		$body = array(
			'text'   => $text,
			'method' => $method,
			'model'  => isset( $opts['model'] ) ? $opts['model'] : ( isset( $settings['default_pii_model'] ) ? $settings['default_pii_model'] : 'OpenMed/OpenMed-PII-SuperClinical-Small-44M-v1' ),
			'lang'   => isset( $opts['language'] ) ? $opts['language'] : ( isset( $settings['default_lang'] ) ? $settings['default_lang'] : 'en' ),
		);

		if ( isset( $opts['replacement_text'] ) ) {
			$body['replacement_text'] = $opts['replacement_text'];
		}

		return $this->request( 'POST', '/pii/deidentify', $body );
	}

	// ── Clinical NER ────────────────────────────────────────────────────

	/**
	 * Analyze clinical text with a named entity recognition model.
	 *
	 * Available models cover diseases, medications, procedures, anatomy,
	 * lab values, and more (1,500+ specialised NER models).
	 *
	 * @since 1.4.0
	 *
	 * @param string $text       Clinical text to analyze.
	 * @param string $model_name NER model identifier (e.g. 'disease_detection_superclinical').
	 * @param array  $opts       Optional overrides: min_confidence, entity_types.
	 * @return array|WP_Error Analysis result or error.
	 */
	public function analyze_text( $text, $model_name, $opts = array() ) {
		$body = array(
			'text'  => $text,
			'model' => $model_name,
		);

		if ( isset( $opts['min_confidence'] ) ) {
			$body['min_confidence'] = (float) $opts['min_confidence'];
		}

		if ( isset( $opts['entity_types'] ) && is_array( $opts['entity_types'] ) ) {
			$body['entity_types'] = $opts['entity_types'];
		}

		return $this->request( 'POST', '/analyze', $body );
	}

	// ── Internal HTTP ───────────────────────────────────────────────────

	/**
	 * Execute an HTTP request against the OpenMed service.
	 *
	 * @since 1.4.0
	 *
	 * @param string     $method HTTP method (GET, POST).
	 * @param string     $path   API path (e.g. '/health', '/pii/deidentify').
	 * @param array|null $body   Optional request body (JSON-encoded for POST).
	 * @return array|WP_Error    Decoded response or error.
	 */
	private function request( $method, $path, $body = null ) {
		if ( ! $this->configured ) {
			return new WP_Error(
				'openmed_not_configured',
				__( 'OpenMed service URL is not configured.', 'nvoos-content-graph-pro' ),
				array( 'status' => 500 )
			);
		}

		$url  = trailingslashit( $this->base_url ) . ltrim( $path, '/' );
		$args = array(
			'timeout'   => $this->timeout,
			'sslverify' => $this->verify_ssl,
			'headers'   => array(
				'Content-Type' => 'application/json',
			),
		);

		// Add API key if configured.
		if ( ! empty( $this->api_key ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
		}

		if ( 'POST' === strtoupper( $method ) && null !== $body ) {
			$args['method'] = 'POST';
			$args['body']   = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'openmed_request_failed',
				$response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$body_data   = json_decode( $body_raw, true );

		if ( 200 !== $status_code && 201 !== $status_code ) {
			$error_message = isset( $body_data['detail'] )
				? $body_data['detail']
				: ( isset( $body_data['error'] ) ? $body_data['error'] : sprintf( 'HTTP %d', $status_code ) );

			return new WP_Error(
				'openmed_api_error',
				$error_message,
				array( 'status' => $status_code )
			);
		}

		if ( ! is_array( $body_data ) ) {
			return new WP_Error(
				'openmed_invalid_response',
				__( 'OpenMed returned an invalid response.', 'nvoos-content-graph-pro' ),
				array( 'status' => 502 )
			);
		}

		return $body_data;
	}
}
