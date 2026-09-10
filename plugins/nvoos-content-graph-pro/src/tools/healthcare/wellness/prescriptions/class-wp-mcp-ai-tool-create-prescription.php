<?php
/**
 * wellness/prescriptions/class-wp-mcp-ai-tool-create-prescription.php (ecosystem port — Wave F4, healthcare wellness CRUD batch 2).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-create-prescription.php` for the
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
 * Creates a new prescription.
 */
class WP_MCP_AI_Tool_Create_Prescription implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_prescription';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Prescription', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new prescription or updates an existing one if prescription_id is provided. Includes medication details, dosage, and schedule information.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prescription_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Optional prescription ID. If provided, updates the existing prescription instead of creating a new one.', 'nvoos-content-graph-pro' ),
				),
				'member_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this prescription belongs to (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'medication_name'    => array(
					'type'        => 'string',
					'description' => __( 'Medication name (required)', 'nvoos-content-graph-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'dosage'             => array(
					'type'        => 'string',
					'description' => __( 'Dosage amount and unit (e.g., "10mg", "2 tablets") (required)', 'nvoos-content-graph-pro' ),
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'frequency'          => array(
					'type'        => 'string',
					'description' => __( 'Frequency of dosage (e.g., "twice daily", "every 6 hours") (required)', 'nvoos-content-graph-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'prescribing_doctor' => array(
					'type'        => 'string',
					'description' => __( 'Name of prescribing doctor (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'start_date'         => array(
					'type'        => 'string',
					'description' => __( 'Prescription start date (YYYY-MM-DD) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'           => array(
					'type'        => 'string',
					'description' => __( 'Prescription end date (YYYY-MM-DD) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'Prescription status (optional, defaults to active)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'active', 'completed', 'discontinued', 'expired' ),
					'default'     => 'active',
				),
				'notes'              => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or instructions (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 5000,
				),
				'refills_remaining'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of refills remaining (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'rx_number'          => array(
					'type'        => 'string',
					'description' => __( 'Pharmacy prescription (Rx) number (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'ndc_code'           => array(
					'type'        => 'string',
					'description' => __( 'National Drug Code (NDC) (optional, e.g. "0069-0010-01")', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
				'route'              => array(
					'type'        => 'string',
					'description' => __( 'Route of administration (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'oral', 'sublingual', 'topical', 'transdermal', 'injection', 'inhalation', 'ophthalmic', 'otic', 'nasal', 'rectal', 'vaginal', 'other', '' ),
				),
				'quantity'           => array(
					'type'        => 'integer',
					'description' => __( 'Quantity dispensed (optional, e.g. 30)', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'quantity_unit'      => array(
					'type'        => 'string',
					'description' => __( 'Unit for quantity (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'tablets', 'capsules', 'ml', 'mg', 'patches', 'drops', 'puffs', 'units', 'suppositories', 'other', '' ),
				),
				'indication'         => array(
					'type'        => 'string',
					'description' => __( 'Indication / reason for prescribing (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 500,
				),
				'pharmacy_name'      => array(
					'type'        => 'string',
					'description' => __( 'Dispensing pharmacy name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'pharmacy_phone'     => array(
					'type'        => 'string',
					'description' => __( 'Dispensing pharmacy phone number (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
			),
			'required'             => array( 'member_id', 'medication_name', 'dosage', 'frequency' ),
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
			'post_type'             => 'mcp_ai_prescription',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'pharmacist' ),
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create prescriptions.', 'nvoos-content-graph-pro' ) );
		}

		// Check if this is an update operation.
		$prescription_id = isset( $arguments['prescription_id'] ) ? absint( $arguments['prescription_id'] ) : 0;
		$is_update       = false;

		if ( $prescription_id ) {
			// Verify prescription exists and user has permission to update it.
			$existing_prescription = get_post( $prescription_id );

			if ( ! $existing_prescription || 'mcp_ai_prescription' !== $existing_prescription->post_type ) {
				return new WP_Error( 'wp_mcp_ai_prescription_not_found', __( 'Prescription not found.', 'nvoos-content-graph-pro' ) );
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$is_author       = absint( $existing_prescription->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this prescription.', 'nvoos-content-graph-pro' ) );
			}

			$is_update = true;
		}

		// Validate required fields.
		$member_id       = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$medication_name = isset( $arguments['medication_name'] ) ? sanitize_text_field( $arguments['medication_name'] ) : '';
		$dosage          = isset( $arguments['dosage'] ) ? sanitize_text_field( $arguments['dosage'] ) : '';
		$frequency       = isset( $arguments['frequency'] ) ? sanitize_text_field( $arguments['frequency'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member', __( 'Member ID is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( '' === $medication_name ) {
			return new WP_Error( 'wp_mcp_ai_missing_medication', __( 'Medication name is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( '' === $dosage ) {
			return new WP_Error( 'wp_mcp_ai_missing_dosage', __( 'Dosage is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( '' === $frequency ) {
			return new WP_Error( 'wp_mcp_ai_missing_frequency', __( 'Frequency is required.', 'nvoos-content-graph-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize optional fields.
		$prescribing_doctor = isset( $arguments['prescribing_doctor'] ) ? sanitize_text_field( $arguments['prescribing_doctor'] ) : '';
		$start_date         = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date           = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$status             = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'active';
		$notes              = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';
		$refills_remaining  = isset( $arguments['refills_remaining'] ) ? absint( $arguments['refills_remaining'] ) : 0;
		$rx_number          = isset( $arguments['rx_number'] ) ? sanitize_text_field( $arguments['rx_number'] ) : '';
		$ndc_code           = isset( $arguments['ndc_code'] ) ? sanitize_text_field( $arguments['ndc_code'] ) : '';
		$route              = isset( $arguments['route'] ) ? sanitize_key( $arguments['route'] ) : '';
		$quantity           = isset( $arguments['quantity'] ) ? absint( $arguments['quantity'] ) : 0;
		$quantity_unit      = isset( $arguments['quantity_unit'] ) ? sanitize_key( $arguments['quantity_unit'] ) : '';
		$indication         = isset( $arguments['indication'] ) ? sanitize_text_field( $arguments['indication'] ) : '';
		$pharmacy_name      = isset( $arguments['pharmacy_name'] ) ? sanitize_text_field( $arguments['pharmacy_name'] ) : '';
		$pharmacy_phone     = isset( $arguments['pharmacy_phone'] ) ? sanitize_text_field( $arguments['pharmacy_phone'] ) : '';

		// Validate dates.
		if ( $start_date && ! $this->validate_date( $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid start date format. Use YYYY-MM-DD.', 'nvoos-content-graph-pro' ) );
		}

		if ( $end_date && ! $this->validate_date( $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid end date format. Use YYYY-MM-DD.', 'nvoos-content-graph-pro' ) );
		}

		if ( $is_update ) {
			// Update existing prescription.
			$post_data = array(
				'ID'           => $prescription_id,
				'post_title'   => $medication_name,
				'post_content' => $notes,
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Update prescription metadata.
			update_post_meta( $prescription_id, '_prescription_member_id', $member_id );
			update_post_meta( $prescription_id, '_prescription_medication_name', $medication_name );
			update_post_meta( $prescription_id, '_prescription_dosage', $dosage );
			update_post_meta( $prescription_id, '_prescription_frequency', $frequency );
			update_post_meta( $prescription_id, '_prescription_status', $status );

			if ( $prescribing_doctor ) {
				update_post_meta( $prescription_id, '_prescription_doctor', $prescribing_doctor );
			}

			if ( $start_date ) {
				update_post_meta( $prescription_id, '_prescription_start_date', $start_date );
			}

			if ( $end_date ) {
				update_post_meta( $prescription_id, '_prescription_end_date', $end_date );
			}

			update_post_meta( $prescription_id, '_prescription_refills_remaining', $refills_remaining );
			update_post_meta( $prescription_id, '_prescription_rx_number', $rx_number );
			update_post_meta( $prescription_id, '_prescription_ndc_code', $ndc_code );
			update_post_meta( $prescription_id, '_prescription_route', $route );
			update_post_meta( $prescription_id, '_prescription_quantity', $quantity );
			update_post_meta( $prescription_id, '_prescription_quantity_unit', $quantity_unit );
			update_post_meta( $prescription_id, '_prescription_indication', $indication );
			update_post_meta( $prescription_id, '_prescription_pharmacy_name', $pharmacy_name );
			update_post_meta( $prescription_id, '_prescription_pharmacy_phone', $pharmacy_phone );

			$prescription = get_post( $prescription_id );

			return array(
				'success'         => true,
				'message'         => __( 'Prescription updated successfully.', 'nvoos-content-graph-pro' ),
				'prescription_id' => $prescription_id,
				'prescription'    => array(
					'id'                 => $prescription_id,
					'member_id'          => $member_id,
					'medication_name'    => $medication_name,
					'dosage'             => $dosage,
					'frequency'          => $frequency,
					'prescribing_doctor' => $prescribing_doctor,
					'start_date'         => $start_date,
					'end_date'           => $end_date,
					'status'             => $status,
					'notes'              => $notes,
					'refills_remaining'  => $refills_remaining,
					'rx_number'          => $rx_number,
					'ndc_code'           => $ndc_code,
					'route'              => $route,
					'quantity'           => $quantity,
					'quantity_unit'      => $quantity_unit,
					'indication'         => $indication,
					'pharmacy_name'      => $pharmacy_name,
					'pharmacy_phone'     => $pharmacy_phone,
					'updated_at'         => $prescription->post_modified,
				),
				'updated'         => true,
			);
		} else {
			// Create prescription post.
			$post_data = array(
				'post_type'    => 'mcp_ai_prescription',
				'post_title'   => $medication_name,
				'post_content' => $notes,
				'post_status'  => 'publish',
				'post_author'  => $current_user_id,
			);

			$prescription_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $prescription_id ) ) {
				return $prescription_id;
			}

			// Save prescription metadata.
			update_post_meta( $prescription_id, '_prescription_member_id', $member_id );
			update_post_meta( $prescription_id, '_prescription_medication_name', $medication_name );
			update_post_meta( $prescription_id, '_prescription_dosage', $dosage );
			update_post_meta( $prescription_id, '_prescription_frequency', $frequency );
			update_post_meta( $prescription_id, '_prescription_status', $status );

			if ( $prescribing_doctor ) {
				update_post_meta( $prescription_id, '_prescription_doctor', $prescribing_doctor );
			}

			if ( $start_date ) {
				update_post_meta( $prescription_id, '_prescription_start_date', $start_date );
			}

			if ( $end_date ) {
				update_post_meta( $prescription_id, '_prescription_end_date', $end_date );
			}

			update_post_meta( $prescription_id, '_prescription_refills_remaining', $refills_remaining );
			update_post_meta( $prescription_id, '_prescription_rx_number', $rx_number );
			update_post_meta( $prescription_id, '_prescription_ndc_code', $ndc_code );
			update_post_meta( $prescription_id, '_prescription_route', $route );
			update_post_meta( $prescription_id, '_prescription_quantity', $quantity );
			update_post_meta( $prescription_id, '_prescription_quantity_unit', $quantity_unit );
			update_post_meta( $prescription_id, '_prescription_indication', $indication );
			update_post_meta( $prescription_id, '_prescription_pharmacy_name', $pharmacy_name );
			update_post_meta( $prescription_id, '_prescription_pharmacy_phone', $pharmacy_phone );

			$prescription = get_post( $prescription_id );

			return array(
				'success'         => true,
				'message'         => __( 'Prescription created successfully.', 'nvoos-content-graph-pro' ),
				'prescription_id' => $prescription_id,
				'prescription'    => array(
					'id'                 => $prescription_id,
					'member_id'          => $member_id,
					'medication_name'    => $medication_name,
					'dosage'             => $dosage,
					'frequency'          => $frequency,
					'prescribing_doctor' => $prescribing_doctor,
					'start_date'         => $start_date,
					'end_date'           => $end_date,
					'status'             => $status,
					'notes'              => $notes,
					'refills_remaining'  => $refills_remaining,
					'rx_number'          => $rx_number,
					'ndc_code'           => $ndc_code,
					'route'              => $route,
					'quantity'           => $quantity,
					'quantity_unit'      => $quantity_unit,
					'indication'         => $indication,
					'pharmacy_name'      => $pharmacy_name,
					'pharmacy_phone'     => $pharmacy_phone,
					'created_at'         => $prescription->post_date,
				),
				'updated'         => false,
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
