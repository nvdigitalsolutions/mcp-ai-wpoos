<?php
/**
 * wellness/medical-records/class-wp-mcp-ai-tool-create-medical-record.php (ecosystem port — Wave F4, healthcare wellness CRUD batch 2).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-create-medical-record.php` for the
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
 * Creates a new medical record.
 */
class WP_MCP_AI_Tool_Create_Medical_Record implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_medical_record';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Medical Record', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new medical record or updates an existing one if medical_record_id is provided. Includes lab results, diagnoses, treatments, vaccinations, imaging, or procedures.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'medical_record_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Optional medical record ID. If provided, updates the existing record instead of creating a new one.', 'nvoos-content-graph-pro' ),
				),
				'member_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this record belongs to (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'record_type'         => array(
					'type'        => 'string',
					'description' => __( 'Type of medical record (required)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'lab-result', 'diagnosis', 'treatment', 'vaccination', 'imaging', 'procedure', 'hospitalization' ),
				),
				'title'               => array(
					'type'        => 'string',
					'description' => __( 'Record title (required)', 'nvoos-content-graph-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'date'                => array(
					'type'        => 'string',
					'description' => __( 'Date of record (YYYY-MM-DD) (optional, defaults to today)', 'nvoos-content-graph-pro' ),
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
					'description' => __( 'ICD-10 / ICD-11 diagnosis code (optional, e.g. "J06.9")', 'nvoos-content-graph-pro' ),
					'maxLength'   => 20,
				),
				'lab_value'           => array(
					'type'        => 'string',
					'description' => __( 'Lab result value (optional, for lab-result records, e.g. "5.4")', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
				'lab_unit'            => array(
					'type'        => 'string',
					'description' => __( 'Unit for lab result value (optional, e.g. "mmol/L", "mg/dL")', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
				'lab_reference_range' => array(
					'type'        => 'string',
					'description' => __( 'Normal reference range for lab result (optional, e.g. "3.5–5.0 mmol/L")', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'lab_abnormal'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the lab result is outside the normal range (optional)', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'member_id', 'record_type', 'title' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create medical records.', 'nvoos-content-graph-pro' ) );
		}

		// Check if this is an update operation.
		$record_id = isset( $arguments['medical_record_id'] ) ? absint( $arguments['medical_record_id'] ) : 0;
		$is_update = false;

		if ( $record_id ) {
			// Verify record exists and user has permission to update it.
			$existing_record = get_post( $record_id );

			if ( ! $existing_record || 'mcp_ai_med_record' !== $existing_record->post_type ) {
				return new WP_Error( 'wp_mcp_ai_record_not_found', __( 'Medical record not found.', 'nvoos-content-graph-pro' ) );
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$is_author       = absint( $existing_record->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this medical record.', 'nvoos-content-graph-pro' ) );
			}

			$is_update = true;
		}

		// Validate required fields.
		$member_id   = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$record_type = isset( $arguments['record_type'] ) ? sanitize_key( $arguments['record_type'] ) : '';
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member', __( 'Member ID is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( ! $record_type ) {
			return new WP_Error( 'wp_mcp_ai_missing_type', __( 'Record type is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Title is required.', 'nvoos-content-graph-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize optional fields.
		$date         = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );
		$provider     = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$details      = isset( $arguments['details'] ) ? wp_kses_post( $arguments['details'] ) : '';
		$notes        = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';
		$icd_code     = isset( $arguments['icd_code'] ) ? sanitize_text_field( $arguments['icd_code'] ) : '';
		$lab_value    = isset( $arguments['lab_value'] ) ? sanitize_text_field( $arguments['lab_value'] ) : '';
		$lab_unit     = isset( $arguments['lab_unit'] ) ? sanitize_text_field( $arguments['lab_unit'] ) : '';
		$lab_ref      = isset( $arguments['lab_reference_range'] ) ? sanitize_text_field( $arguments['lab_reference_range'] ) : '';
		$lab_abnormal = ! empty( $arguments['lab_abnormal'] ) ? 1 : 0;
		// Validate date.
		if ( $date && ! $this->validate_date( $date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid date format. Use YYYY-MM-DD.', 'nvoos-content-graph-pro' ) );
		}

		if ( $is_update ) {
			// Update existing medical record.
			$post_data = array(
				'ID'           => $record_id,
				'post_title'   => $title,
				'post_content' => $details,
				'post_excerpt' => $notes,
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Set record type taxonomy.
			wp_set_object_terms( $record_id, $record_type, 'mcp_ai_record_type' );

			// Update record metadata.
			update_post_meta( $record_id, '_medical_record_member_id', $member_id );
			update_post_meta( $record_id, '_medical_record_date', $date );
			update_post_meta( $record_id, '_medical_record_icd_code', $icd_code );
			update_post_meta( $record_id, '_medical_record_lab_value', $lab_value );
			update_post_meta( $record_id, '_medical_record_lab_unit', $lab_unit );
			update_post_meta( $record_id, '_medical_record_lab_reference_range', $lab_ref );
			update_post_meta( $record_id, '_medical_record_lab_abnormal', $lab_abnormal );

			if ( $provider ) {
				update_post_meta( $record_id, '_medical_record_provider', $provider );
			}

			$record = get_post( $record_id );

			return array(
				'success'   => true,
				'message'   => __( 'Medical record updated successfully.', 'nvoos-content-graph-pro' ),
				'record_id' => $record_id,
				'record'    => array(
					'id'                  => $record_id,
					'member_id'           => $member_id,
					'record_type'         => $record_type,
					'title'               => $title,
					'date'                => $date,
					'provider'            => $provider,
					'details'             => $details,
					'notes'               => $notes,
					'icd_code'            => $icd_code,
					'lab_value'           => $lab_value,
					'lab_unit'            => $lab_unit,
					'lab_reference_range' => $lab_ref,
					'lab_abnormal'        => (bool) $lab_abnormal,
					'updated_at'          => $record->post_modified,
				),
				'updated'   => true,
			);
		} else {
			// Create medical record post.
			$post_data = array(
				'post_type'    => 'mcp_ai_med_record',
				'post_title'   => $title,
				'post_content' => $details,
				'post_excerpt' => $notes,
				'post_status'  => 'publish',
				'post_author'  => $current_user_id,
			);

			$record_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $record_id ) ) {
				return $record_id;
			}

			// Set record type taxonomy.
			wp_set_object_terms( $record_id, $record_type, 'mcp_ai_record_type' );

			// Save record metadata.
			update_post_meta( $record_id, '_medical_record_member_id', $member_id );
			update_post_meta( $record_id, '_medical_record_date', $date );
			update_post_meta( $record_id, '_medical_record_icd_code', $icd_code );
			update_post_meta( $record_id, '_medical_record_lab_value', $lab_value );
			update_post_meta( $record_id, '_medical_record_lab_unit', $lab_unit );
			update_post_meta( $record_id, '_medical_record_lab_reference_range', $lab_ref );
			update_post_meta( $record_id, '_medical_record_lab_abnormal', $lab_abnormal );

			if ( $provider ) {
				update_post_meta( $record_id, '_medical_record_provider', $provider );
			}

			$record = get_post( $record_id );

			return array(
				'success'   => true,
				'message'   => __( 'Medical record created successfully.', 'nvoos-content-graph-pro' ),
				'record_id' => $record_id,
				'record'    => array(
					'id'                  => $record_id,
					'member_id'           => $member_id,
					'record_type'         => $record_type,
					'title'               => $title,
					'date'                => $date,
					'provider'            => $provider,
					'details'             => $details,
					'notes'               => $notes,
					'icd_code'            => $icd_code,
					'lab_value'           => $lab_value,
					'lab_unit'            => $lab_unit,
					'lab_reference_range' => $lab_ref,
					'lab_abnormal'        => (bool) $lab_abnormal,
					'created_at'          => $record->post_date,
				),
				'updated'   => false,
			);
		}
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
