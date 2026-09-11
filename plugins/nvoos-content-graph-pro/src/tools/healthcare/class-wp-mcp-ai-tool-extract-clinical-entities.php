<?php
/**
 * class-wp-mcp-ai-tool-extract-clinical-entities.php (ecosystem port — Wave F4, healthcare interop + OpenMed batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/class-wp-mcp-ai-tool-extract-clinical-entities.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` (incl. the source's `nvoos-embedded` strings — the monolith file already carried the embedded addon's domain; the ported copy normalizes it);
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the blueprint installer resolves from the addon's
 * already-ported `src/tools/orchestration/` copy).
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

// Standalone seam (documented deviation): the base interface eagerly requires
// the base copy of the default-capability trait monolith, and the monorepo
// test matrices serve the base interface via the root classmap even in
// standalone mode. Load the base copy there so the class compiles against the
// same symbol (the addon D8 copy would otherwise double-declare — PHP
// resolves class traits before the implements clause). Real standalone
// installs load the addon copy.
if ( defined( 'WP_MCP_AI_TESTS_RUNNING' ) && WP_MCP_AI_TESTS_RUNNING ) {
	$nvoos_content_graph_pro_base_default_cap = dirname( NVOOS_CONTENT_GRAPH_PRO_PATH, 2 ) . '/includes/tools/trait-wp-mcp-ai-tool-default-capability.php';
	if ( file_exists( $nvoos_content_graph_pro_base_default_cap ) && ! trait_exists( 'WP_MCP_AI_Tool_Default_Capability', false ) ) {
		require_once $nvoos_content_graph_pro_base_default_cap;
	}
	unset( $nvoos_content_graph_pro_base_default_cap );
} elseif ( ! trait_exists( 'WP_MCP_AI_Tool_Default_Capability', false ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/trait-wp-mcp-ai-tool-default-capability.php';
}

/**
 * Clinical entity extraction tool.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Tool_Extract_Clinical_Entities implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

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
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'extract_clinical_entities';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Extract Clinical Entities', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __(
			'Extracts clinical entities (diseases, medications, procedures, anatomy, '
			. 'lab values, symptoms) from unstructured medical text using specialised '
			. 'clinical NLP models. Runs locally — no patient data leaves the network.',
			'nvoos-content-graph-pro'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$model_names = array_keys( self::CLINICAL_MODELS );

		return array(
			'type'       => 'object',
			'properties' => array(
				'text'           => array(
					'type'        => 'string',
					'description' => __( 'Unstructured clinical text to analyze (encounter note, lab report, discharge summary, etc.).', 'nvoos-content-graph-pro' ),
				),
				'model'          => array(
					'type'        => 'string',
					'description' => __( 'Clinical NER model to use.', 'nvoos-content-graph-pro' ),
					'enum'        => $model_names,
					'default'     => 'general_clinical_ner',
				),
				'min_confidence' => array(
					'type'        => 'number',
					'description' => __( 'Minimum confidence threshold for returned entities (0.0–1.0).', 'nvoos-content-graph-pro' ),
					'default'     => 0.5,
				),
				'entity_types'   => array(
					'type'        => 'array',
					'description' => __( 'Filter to specific entity types. Empty = all types.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
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
			'external-api',
			'network-dependent',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Sanitized input arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error  Result envelope or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// ── PHI gate ────────────────────────────────────────────────────
		if ( ! class_exists( 'WP_MCP_AI_Healthcare_Engine' ) || ! WP_MCP_AI_Healthcare_Engine::phi_acknowledged() ) {
			return new WP_Error(
				'phi_not_acknowledged',
				__( 'PHI access has not been acknowledged.', 'nvoos-content-graph-pro' ),
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
				__( 'Clinical text is required for entity extraction.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( ! array_key_exists( $model, self::CLINICAL_MODELS ) ) {
			return new WP_Error(
				'invalid_model',
				sprintf(
					/* translators: %s: comma-separated list of valid models */
					__( 'Unknown clinical NER model. Available models: %s.', 'nvoos-content-graph-pro' ),
					implode( ', ', array_keys( self::CLINICAL_MODELS ) )
				),
				array( 'status' => 400 )
			);
		}

		// ── Check OpenMed availability ──────────────────────────────────
		if ( ! class_exists( 'WP_MCP_AI_OpenMed_Client' ) || ! WP_MCP_AI_OpenMed_Client::is_configured() ) {
			return new WP_Error(
				'openmed_not_configured',
				__( 'OpenMed service is not configured.', 'nvoos-content-graph-pro' ),
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
