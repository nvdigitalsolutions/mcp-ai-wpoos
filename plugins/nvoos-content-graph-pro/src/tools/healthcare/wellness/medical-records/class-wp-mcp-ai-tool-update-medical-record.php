<?php
/**
 * wellness/medical-records/class-wp-mcp-ai-tool-update-medical-record.php (ecosystem port — Wave F4, healthcare wellness CRUD batch 2).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-update-medical-record.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the relevance-search trait require resolves from the
 * addon's already-ported `src/traits/` copy).
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
 * Updates an existing medical record.
 */
class WP_MCP_AI_Tool_Update_Medical_Record implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_medical_record';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Medical Record', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing medical record with new information.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'record_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Record ID to update (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'title'               => array(
					'type'        => 'string',
					'description' => __( 'Record title (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'date'                => array(
					'type'        => 'string',
					'description' => __( 'Date of record (YYYY-MM-DD) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'provider'            => array(
					'type'        => 'string',
					'description' => __( 'Healthcare provider or facility name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'details'             => array(
					'type'        => 'string',
					'description' => __( 'Detailed information about the medical record (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 10000,
				),
				'notes'               => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 5000,
				),
				'icd_code'            => array(
					'type'        => 'string',
					'description' => __( 'ICD-10 / ICD-11 diagnosis code (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 20,
				),
				'lab_value'           => array(
					'type'        => 'string',
					'description' => __( 'Lab result value (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
				'lab_unit'            => array(
					'type'        => 'string',
					'description' => __( 'Lab result unit (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
				'lab_reference_range' => array(
					'type'        => 'string',
					'description' => __( 'Normal reference range (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'lab_abnormal'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the result is abnormal (optional)', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'record_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_med_record',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'medical_coder' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update medical records.', 'nvoos-content-graph-pro' ) );
		}

		// Validate record ID.
		$record_id = isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0;

		if ( ! $record_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Record ID is required.', 'nvoos-content-graph-pro' ) );
		}

		// Get record.
		$record = get_post( $record_id );

		if ( ! $record || 'mcp_ai_med_record' !== $record->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Medical record not found.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize optional fields.
		$title        = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$date         = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : '';
		$provider     = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$details      = isset( $arguments['details'] ) ? wp_kses_post( $arguments['details'] ) : '';
		$notes        = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';
		$icd_code     = isset( $arguments['icd_code'] ) ? sanitize_text_field( $arguments['icd_code'] ) : '';
		$lab_value    = isset( $arguments['lab_value'] ) ? sanitize_text_field( $arguments['lab_value'] ) : '';
		$lab_unit     = isset( $arguments['lab_unit'] ) ? sanitize_text_field( $arguments['lab_unit'] ) : '';
		$lab_ref      = isset( $arguments['lab_reference_range'] ) ? sanitize_text_field( $arguments['lab_reference_range'] ) : '';
		$lab_abnormal = isset( $arguments['lab_abnormal'] ) ? ( $arguments['lab_abnormal'] ? 1 : 0 ) : null;

		// Validate date if provided.
		if ( $date && ! $this->validate_date( $date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid date format. Use YYYY-MM-DD.', 'nvoos-content-graph-pro' ) );
		}

		// Update post if title, details, or notes changed.
		if ( $title || $details || $notes ) {
			$post_data = array(
				'ID' => $record_id,
			);

			if ( $title ) {
				$post_data['post_title'] = $title;
			}

			if ( $details ) {
				$post_data['post_content'] = $details;
			}

			if ( $notes ) {
				$post_data['post_excerpt'] = $notes;
			}

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update metadata.
		if ( $date ) {
			update_post_meta( $record_id, '_medical_record_date', $date );
		}

		if ( $provider ) {
			update_post_meta( $record_id, '_medical_record_provider', $provider );
		}

		if ( $icd_code ) {
			update_post_meta( $record_id, '_medical_record_icd_code', $icd_code );
		}

		if ( $lab_value ) {
			update_post_meta( $record_id, '_medical_record_lab_value', $lab_value );
		}

		if ( $lab_unit ) {
			update_post_meta( $record_id, '_medical_record_lab_unit', $lab_unit );
		}

		if ( $lab_ref ) {
			update_post_meta( $record_id, '_medical_record_lab_reference_range', $lab_ref );
		}

		if ( null !== $lab_abnormal ) {
			update_post_meta( $record_id, '_medical_record_lab_abnormal', $lab_abnormal );
		}

		return array(
			'success' => true,
			'message' => __( 'Medical record updated successfully.', 'nvoos-content-graph-pro' ),
			'record'  => array(
				'id'                  => $record_id,
				'title'               => $title ? $title : get_post_field( 'post_title', $record_id ),
				'date'                => $date ? $date : get_post_meta( $record_id, '_medical_record_date', true ),
				'provider'            => $provider ? $provider : get_post_meta( $record_id, '_medical_record_provider', true ),
				'icd_code'            => $icd_code ? $icd_code : get_post_meta( $record_id, '_medical_record_icd_code', true ),
				'lab_value'           => $lab_value ? $lab_value : get_post_meta( $record_id, '_medical_record_lab_value', true ),
				'lab_unit'            => $lab_unit ? $lab_unit : get_post_meta( $record_id, '_medical_record_lab_unit', true ),
				'lab_reference_range' => $lab_ref ? $lab_ref : get_post_meta( $record_id, '_medical_record_lab_reference_range', true ),
				'lab_abnormal'        => (bool) get_post_meta( $record_id, '_medical_record_lab_abnormal', true ),
				'updated_at'          => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Validate date format (YYYY-MM-DD).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
