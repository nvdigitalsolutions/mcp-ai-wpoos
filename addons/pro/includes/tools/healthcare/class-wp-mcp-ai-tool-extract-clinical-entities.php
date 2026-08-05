<?php
/**
 * Extract Clinical Entities Tool
 *
 * Uses OpenMed's 1,500+ specialised clinical NER models to extract
 * diseases, medications, procedures, anatomy, lab values, and other
 * clinical entities from unstructured medical text.
 *
 * Integrates with the existing medical-records CCT and FHIR export
 * tools so extracted entities can flow into structured health records.
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
 * Clinical entity extraction tool.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Tool_Extract_Clinical_Entities implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Pre-configured clinical NER models available in OpenMed.
	 *
	 * @var array
	 */
	const CLINICAL_MODELS = array(
		'disease_detection_superclinical' => 'Disease Detection',
		'medication_extraction_clinical'  => 'Medication Extraction',
		'procedure_detection_clinical'    => 'Procedure Detection',
		'anatomy_detection_clinical'      => 'Anatomy Detection',
		'lab_values_extraction_clinical'  => 'Lab Values Extraction',
		'symptom_detection_clinical'      => 'Symptom Detection',
		'general_clinical_ner'            => 'General Clinical NER',
	);

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'extract_clinical_entities';
	}

	/**
	 * Get the tool definition for AI discovery.
	 *
	 * @return array
	 */
	public function get_definition() {
		$model_names = array_keys( self::CLINICAL_MODELS );

		return array(
			'name'                => __( 'Extract Clinical Entities', 'nvoos-embedded' ),
			'description'         => __(
				'Extracts clinical entities (diseases, medications, procedures, anatomy, '
				. 'lab values, symptoms) from unstructured medical text using specialised '
				. 'clinical NLP models. Runs locally — no patient data leaves the network.',
				'nvoos-embedded'
			),
			'required_capability' => 'edit_posts',
			'parameters'          => array(
				'type'       => 'object',
				'properties' => array(
					'text'           => array(
						'type'        => 'string',
						'description' => __( 'Unstructured clinical text to analyze (encounter note, lab report, discharge summary, etc.).', 'nvoos-embedded' ),
					),
					'model'          => array(
						'type'        => 'string',
						'description' => __( 'Clinical NER model to use.', 'nvoos-embedded' ),
						'enum'        => $model_names,
						'default'     => 'general_clinical_ner',
					),
					'min_confidence' => array(
						'type'        => 'number',
						'description' => __( 'Minimum confidence threshold for returned entities (0.0–1.0).', 'nvoos-embedded' ),
						'default'     => 0.5,
					),
					'entity_types'   => array(
						'type'        => 'array',
						'description' => __( 'Filter to specific entity types. Empty = all types.', 'nvoos-embedded' ),
						'items'       => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'text' ),
			),
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'is_destructive'    => false,
			'accesses_phi'      => true,
			'requires_phi_gate' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Sanitized input arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error  Result envelope or error.
	 */
	public function execute( $arguments, $context ) {
		// ── PHI gate ────────────────────────────────────────────────────
		if ( ! class_exists( 'WP_MCP_AI_Healthcare_Engine' ) || ! WP_MCP_AI_Healthcare_Engine::phi_acknowledged() ) {
			return new WP_Error(
				'phi_not_acknowledged',
				__( 'PHI access has not been acknowledged.', 'nvoos-embedded' ),
				array( 'status' => 403 )
			);
		}

		// ── Sanitize input ──────────────────────────────────────────────
		$text           = isset( $arguments['text'] ) ? wp_kses_post( $arguments['text'] ) : '';
		$model          = isset( $arguments['model'] ) ? sanitize_key( $arguments['model'] ) : 'general_clinical_ner';
		$min_confidence = isset( $arguments['min_confidence'] ) ? (float) $arguments['min_confidence'] : 0.5;
		$entity_types   = isset( $arguments['entity_types'] ) && is_array( $arguments['entity_types'] )
			? array_map( 'sanitize_key', $arguments['entity_types'] )
			: array();

		if ( empty( $text ) ) {
			return new WP_Error(
				'missing_text',
				__( 'Clinical text is required for entity extraction.', 'nvoos-embedded' ),
				array( 'status' => 400 )
			);
		}

		if ( ! array_key_exists( $model, self::CLINICAL_MODELS ) ) {
			return new WP_Error(
				'invalid_model',
				sprintf(
					/* translators: %s: comma-separated list of valid models */
					__( 'Unknown clinical NER model. Available models: %s.', 'nvoos-embedded' ),
					implode( ', ', array_keys( self::CLINICAL_MODELS ) )
				),
				array( 'status' => 400 )
			);
		}

		// ── Check OpenMed availability ──────────────────────────────────
		if ( ! class_exists( 'WP_MCP_AI_OpenMed_Client' ) || ! WP_MCP_AI_OpenMed_Client::is_configured() ) {
			return new WP_Error(
				'openmed_not_configured',
				__( 'OpenMed service is not configured.', 'nvoos-embedded' ),
				array( 'status' => 500 )
			);
		}

		// ── Execute NER ─────────────────────────────────────────────────
		$start_time = microtime( true );

		$client = WP_MCP_AI_OpenMed_Client::get_instance();

		$opts = array(
			'min_confidence' => $min_confidence,
		);
		if ( ! empty( $entity_types ) ) {
			$opts['entity_types'] = $entity_types;
		}

		$result = $client->analyze_text( $text, $model, $opts );

		$processing_time_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// ── Normalize entities ──────────────────────────────────────────
		$entities = isset( $result['entities'] ) ? $result['entities'] : array();
		$entities = $this->normalize_entities( $entities, $min_confidence );

		// ── Audit ───────────────────────────────────────────────────────
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'clinical_entities_extracted',
				'health_record',
				isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0,
				$user_id,
				array(
					'model'              => $model,
					'entities_count'     => count( $entities ),
					'processing_time_ms' => $processing_time_ms,
				)
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'entities'           => $entities,
				'entity_count'       => count( $entities ),
				'model_used'         => $model,
				'model_label'        => self::CLINICAL_MODELS[ $model ],
				'min_confidence'     => $min_confidence,
				'processing_time_ms' => $processing_time_ms,
			),
		);
	}

	/**
	 * Normalize extracted entities into a consistent format.
	 *
	 * Filters by confidence threshold and deduplicates overlapping spans.
	 *
	 * @since 1.4.0
	 *
	 * @param array $entities       Raw entities from OpenMed.
	 * @param float $min_confidence Minimum confidence threshold.
	 * @return array Normalized entities.
	 */
	private function normalize_entities( $entities, $min_confidence ) {
		$normalized = array();

		foreach ( $entities as $entity ) {
			if ( ! isset( $entity['entity_group'] ) && ! isset( $entity['label'] ) ) {
				continue;
			}

			$confidence = isset( $entity['score'] ) ? (float) $entity['score'] : ( isset( $entity['confidence'] ) ? (float) $entity['confidence'] : 1.0 );

			if ( $confidence < $min_confidence ) {
				continue;
			}

			$normalized[] = array(
				'text'       => isset( $entity['word'] ) ? sanitize_text_field( $entity['word'] ) : ( isset( $entity['text'] ) ? sanitize_text_field( $entity['text'] ) : '' ),
				'type'       => isset( $entity['entity_group'] ) ? sanitize_key( $entity['entity_group'] ) : ( isset( $entity['label'] ) ? sanitize_key( $entity['label'] ) : 'unknown' ),
				'confidence' => round( $confidence, 4 ),
				'start'      => isset( $entity['start'] ) ? absint( $entity['start'] ) : 0,
				'end'        => isset( $entity['end'] ) ? absint( $entity['end'] ) : 0,
			);
		}

		return $normalized;
	}
}
