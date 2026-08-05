<?php
/**
 * De-identify Health Record Tool
 *
 * Removes PHI/PII from clinical text using OpenMed's de-identification
 * engine. Supports all 18 HIPAA Safe Harbor identifiers across 17 languages.
 *
 * Security model (defense-in-depth):
 *   L1: Network isolation — OpenMed runs on Docker internal network.
 *   L2: API authentication — Bearer token via OpenMed v1.8+ API key.
 *   L3: PHI Acknowledgement gate — admin must accept legal agreement.
 *   L4: Capability enforcement — user must have 'deidentify_phi' capability.
 *   L5: Audit trail — every call records an immutable audit entry.
 *
 * No raw PHI is ever persisted in WordPress. The de-identified text is
 * returned to the caller; the original PHI exists only in transit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * De-identify health record tool.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Tool_Deidentify_Health_Record implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * De-identification methods supported by OpenMed.
	 *
	 * @var array
	 */
	const DEIDENTIFY_METHODS = array( 'mask', 'remove', 'replace', 'hash', 'shift_dates' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'deidentify_health_record';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'De-identify Health Record', 'nvoos-embedded' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __(
			'Removes personally identifiable health information from clinical text '
			. 'using HIPAA Safe Harbor de-identification. Supports all 18 PHI '
			. 'identifier types. No patient data leaves your network.',
			'nvoos-embedded'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text'             => array(
					'type'        => 'string',
					'description' => __( 'Clinical text containing PHI to be de-identified.', 'nvoos-embedded' ),
				),
				'method'           => array(
					'type'        => 'string',
					'description' => __( 'De-identification method.', 'nvoos-embedded' ),
					'enum'        => self::DEIDENTIFY_METHODS,
					'default'     => 'mask',
				),
				'replacement_text' => array(
					'type'        => 'string',
					'description' => __( 'Replacement text when method is "replace".', 'nvoos-embedded' ),
					'default'     => '[REDACTED]',
				),
			),
			'required'   => array( 'text' ),
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'pii-data',
			'hipaa-relevant',
			'requires-capability',
			'external-api',
			'network-dependent',
		);
	}

	/**
	 * Override default capability for PHI operations.
	 *
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'deidentify_phi';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Sanitized input arguments.
	 * @param array $context   Execution context (user_id, assistant_id, etc.).
	 * @return array|WP_Error  Result envelope or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// ── L3: PHI Acknowledgement gate ────────────────────────────────
		if ( ! class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
			return new WP_Error(
				'healthcare_engine_unavailable',
				__( 'Healthcare engine is not available.', 'nvoos-embedded' ),
				array( 'status' => 500 )
			);
		}

		if ( ! WP_MCP_AI_Healthcare_Engine::phi_acknowledged() ) {
			return new WP_Error(
				'phi_not_acknowledged',
				__( 'PHI access has not been acknowledged. An administrator must accept the PHI agreement in Healthcare Settings.', 'nvoos-embedded' ),
				array( 'status' => 403 )
			);
		}

		// ── L4: Capability check ────────────────────────────────────────
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered via WP_MCP_AI_Healthcare_Capabilities.
		if ( ! user_can( $user_id, 'deidentify_phi' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to de-identify health records.', 'nvoos-embedded' ),
				array( 'status' => 403 )
			);
		}

		// ── Sanitize input ──────────────────────────────────────────────
		$text             = isset( $arguments['text'] ) ? wp_kses_post( $arguments['text'] ) : '';
		$method           = isset( $arguments['method'] ) ? sanitize_key( $arguments['method'] ) : 'mask';
		$replacement_text = isset( $arguments['replacement_text'] ) ? sanitize_text_field( $arguments['replacement_text'] ) : '[REDACTED]';

		if ( empty( $text ) ) {
			return new WP_Error(
				'missing_text',
				__( 'Clinical text is required for de-identification.', 'nvoos-embedded' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $method, self::DEIDENTIFY_METHODS, true ) ) {
			return new WP_Error(
				'invalid_method',
				sprintf(
					/* translators: %s: comma-separated list of valid methods */
					__( 'Invalid de-identification method. Valid methods: %s.', 'nvoos-embedded' ),
					implode( ', ', self::DEIDENTIFY_METHODS )
				),
				array( 'status' => 400 )
			);
		}

		// ── Check OpenMed availability ──────────────────────────────────
		if ( ! class_exists( 'WP_MCP_AI_OpenMed_Client' ) ) {
			return new WP_Error(
				'openmed_unavailable',
				__( 'OpenMed client is not available.', 'nvoos-embedded' ),
				array( 'status' => 500 )
			);
		}

		$client = WP_MCP_AI_OpenMed_Client::get_instance();
		if ( ! WP_MCP_AI_OpenMed_Client::is_configured() ) {
			return new WP_Error(
				'openmed_not_configured',
				__( 'OpenMed service is not configured. Contact your administrator.', 'nvoos-embedded' ),
				array( 'status' => 500 )
			);
		}

		// ── Execute de-identification ───────────────────────────────────
		$start_time = microtime( true );

		$opts = array();
		if ( 'replace' === $method && ! empty( $replacement_text ) ) {
			$opts['replacement_text'] = $replacement_text;
		}

		$result = $client->deidentify( $text, $method, $opts );

		$processing_time_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// ── L5: Audit trail ─────────────────────────────────────────────
		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'phi_deidentified',
				'health_record',
				isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0,
				$user_id,
				array(
					'method'              => $method,
					'original_length'     => strlen( $text ),
					'deidentified_length' => isset( $result['deidentified_text'] ) ? strlen( $result['deidentified_text'] ) : 0,
					'processing_time_ms'  => $processing_time_ms,
					'model_used'          => isset( $result['model_used'] ) ? sanitize_text_field( $result['model_used'] ) : '',
				)
			);
		}

		/**
		 * Fires after a health record has been de-identified.
		 *
		 * @since 1.4.0
		 *
		 * @param string $deidentified_text The de-identified output.
		 * @param string $method            The de-identification method used.
		 * @param int    $user_id           The user who performed the operation.
		 */
		do_action( 'wp_mcp_ai_after_health_record_deidentified', $result['deidentified_text'], $method, $user_id );

		return array(
			'success' => true,
			'data'    => array(
				'deidentified_text'  => isset( $result['deidentified_text'] ) ? $result['deidentified_text'] : '',
				'entities_found'     => isset( $result['entities_found'] ) ? absint( $result['entities_found'] ) : 0,
				'entities'           => isset( $result['entities'] ) ? $result['entities'] : array(),
				'method_used'        => $method,
				'model_used'         => isset( $result['model_used'] ) ? sanitize_text_field( $result['model_used'] ) : '',
				'processing_time_ms' => $processing_time_ms,
			),
		);
	}
}
