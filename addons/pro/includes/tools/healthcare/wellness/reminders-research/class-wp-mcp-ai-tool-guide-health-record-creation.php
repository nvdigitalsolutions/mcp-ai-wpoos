<?php
/**
 * Tool for providing guided health record creation flow.
 *
 * Analyzes a member's health profile and provides step-by-step guidance
 * on what records should be added next to maintain a comprehensive dataset.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides intelligent guidance for health record creation.
 */
class WP_MCP_AI_Tool_Guide_Health_Record_Creation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'guide_health_record_creation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Guide Health Record Creation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes a member\'s current health profile and provides intelligent, step-by-step guidance on what health records should be added or completed next. Covers all USCDI data classes: demographics, allergies, immunizations/vaccinations, vital signs, medications, medical records, checkups, and insurance policies. Identifies gaps in existing records (e.g. missing ICD-10 codes, NDC codes, allergy types, policy group numbers) and suggests priority actions aligned with HL7 FHIR and HIPAA best practices.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member ID to analyze (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'focus'     => array(
					'type'        => 'string',
					'description' => __( 'Optional focus area: "demographics", "policies", "medical_records", "checkups", "prescriptions", "allergies", "vaccinations", "vital_signs", or "all" (default: all)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'demographics', 'policies', 'medical_records', 'checkups', 'prescriptions', 'allergies', 'vaccinations', 'vital_signs', 'all' ),
					'default'     => 'all',
				),
			),
			'required'             => array( 'member_id' ),
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
			'post_type'             => 'mcp_ai_member',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'caregiver', 'patient' ),
			'risk_level'            => 'info',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Health and Wellness management is a Pro feature.
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to guide health record creation.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$focus     = isset( $arguments['focus'] ) ? sanitize_key( $arguments['focus'] ) : 'all';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get member type.
		$types       = wp_get_object_terms( $member_id, 'mcp_ai_member_type', array( 'fields' => 'slugs' ) );
		$member_type = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : 'person';

		// Analyze current profile completeness.
		$analysis = $this->analyze_profile( $member_id, $member_type, $focus );

		// Generate guidance based on analysis.
		$guidance = $this->generate_guidance( $member_id, $member->post_title, $member_type, $analysis, $focus );

		return array(
			'success'       => true,
			'member_id'     => $member_id,
			'member_name'   => $member->post_title,
			'member_type'   => $member_type,
			'focus'         => $focus,
			'analysis'      => $analysis,
			'guidance'      => $guidance,
			'next_steps'    => $analysis['next_steps'],
			'priority_gaps' => $analysis['priority_gaps'],
		);
	}

	/**
	 * Analyze member health profile completeness.
	 *
	 * Covers all USCDI v3 data classes: demographics, allergies, immunizations,
	 * vital signs, medications/prescriptions, conditions/medical records, encounters/checkups,
	 * and insurance coverage/policies.
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_type Member type (person/pet).
	 * @param string $focus       Focus area.
	 * @return array Analysis results.
	 */
	private function analyze_profile( $member_id, $member_type, $focus ) {
		$gaps          = array();
		$priority_gaps = array();
		$next_steps    = array();
		$completeness  = array();

		// Analyze demographics if not filtered out.
		if ( 'all' === $focus || 'demographics' === $focus ) {
			$demo_gaps = $this->analyze_demographics( $member_id, $member_type );
			if ( ! empty( $demo_gaps ) ) {
				$gaps                         = array_merge( $gaps, $demo_gaps );
				$completeness['demographics'] = false;
			} else {
				$completeness['demographics'] = true;
			}
		}

		// Analyze policies (USCDI: Insurance/Coverage).
		if ( 'all' === $focus || 'policies' === $focus ) {
			$policy_gaps = $this->analyze_policies( $member_id, $member_type );
			if ( ! empty( $policy_gaps ) ) {
				$gaps                     = array_merge( $gaps, $policy_gaps );
				$completeness['policies'] = false;
			} else {
				$completeness['policies'] = true;
			}
		}

		// Analyze allergies — USCDI Allergies and Intolerances (high priority/patient safety).
		if ( 'all' === $focus || 'allergies' === $focus ) {
			$allergy_gaps = $this->analyze_allergies( $member_id );
			if ( ! empty( $allergy_gaps ) ) {
				$priority_gaps             = array_merge( $priority_gaps, $allergy_gaps );
				$gaps                      = array_merge( $gaps, $allergy_gaps );
				$completeness['allergies'] = false;
			} else {
				$completeness['allergies'] = true;
			}
		}

		// Analyze immunizations/vaccinations (USCDI: Immunizations).
		if ( 'all' === $focus || 'vaccinations' === $focus ) {
			$vaccine_gaps = $this->analyze_vaccinations( $member_id, $member_type );
			if ( ! empty( $vaccine_gaps ) ) {
				$gaps                         = array_merge( $gaps, $vaccine_gaps );
				$completeness['vaccinations'] = false;
			} else {
				$completeness['vaccinations'] = true;
			}
		}

		// Analyze vital signs (USCDI: Vital Signs).
		if ( 'all' === $focus || 'vital_signs' === $focus ) {
			$vitals_gaps = $this->analyze_vital_signs( $member_id );
			if ( ! empty( $vitals_gaps ) ) {
				$gaps                        = array_merge( $gaps, $vitals_gaps );
				$completeness['vital_signs'] = false;
			} else {
				$completeness['vital_signs'] = true;
			}
		}

		// Analyze medical records (USCDI: Problems/Conditions).
		if ( 'all' === $focus || 'medical_records' === $focus ) {
			$record_gaps = $this->analyze_medical_records( $member_id );
			if ( ! empty( $record_gaps ) ) {
				$gaps                            = array_merge( $gaps, $record_gaps );
				$completeness['medical_records'] = false;
			} else {
				$completeness['medical_records'] = true;
			}
		}

		// Analyze checkups (USCDI: Encounters).
		if ( 'all' === $focus || 'checkups' === $focus ) {
			$checkup_gaps = $this->analyze_checkups( $member_id, $member_type );
			if ( ! empty( $checkup_gaps ) ) {
				$gaps                     = array_merge( $gaps, $checkup_gaps );
				$completeness['checkups'] = false;
			} else {
				$completeness['checkups'] = true;
			}
		}

		// Analyze prescriptions (USCDI: Medications).
		if ( 'all' === $focus || 'prescriptions' === $focus ) {
			$prescription_gaps = $this->analyze_prescriptions( $member_id );
			if ( ! empty( $prescription_gaps ) ) {
				$gaps                          = array_merge( $gaps, $prescription_gaps );
				$completeness['prescriptions'] = false;
			} else {
				$completeness['prescriptions'] = true;
			}
		}

		// Generate next steps prioritized by importance.
		if ( ! empty( $priority_gaps ) ) {
			$next_steps = array_slice( $priority_gaps, 0, 3 );
		} elseif ( ! empty( $gaps ) ) {
			$next_steps = array_slice( $gaps, 0, 3 );
		} else {
			$next_steps = array( __( 'Health profile is complete! Continue to keep records updated.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'gaps'          => $gaps,
			'priority_gaps' => $priority_gaps,
			'next_steps'    => $next_steps,
			'completeness'  => $completeness,
			'total_gaps'    => count( $gaps ),
		);
	}

	/**
	 * Analyze demographics completeness (USCDI: Patient Demographics).
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_type Member type.
	 * @return array Gaps found.
	 */
	private function analyze_demographics( $member_id, $member_type ) {
		$gaps = array();

		if ( empty( get_post_meta( $member_id, '_member_date_of_birth', true ) ) ) {
			$gaps[] = __( 'Add date of birth to member profile (USCDI: Patient Demographics)', 'mcp-ai-wpoos-pro' );
		}

		if ( empty( get_post_meta( $member_id, '_member_gender', true ) ) ) {
			$gaps[] = __( 'Add gender to member profile (USCDI: Patient Demographics)', 'mcp-ai-wpoos-pro' );
		}

		if ( 'person' === $member_type ) {
			if ( empty( get_post_meta( $member_id, '_member_blood_type', true ) ) ) {
				$gaps[] = __( 'Add blood type information (critical for emergency care)', 'mcp-ai-wpoos-pro' );
			}

			if ( empty( get_post_meta( $member_id, '_member_emergency_contact', true ) ) ) {
				$gaps[] = __( 'Add emergency contact information (HIPAA: patient safety)', 'mcp-ai-wpoos-pro' );
			}

			// USCDI-aligned: MRN for provider coordination.
			if ( empty( get_post_meta( $member_id, '_member_mrn', true ) ) ) {
				$gaps[] = __( 'Add Medical Record Number (MRN) for provider coordination', 'mcp-ai-wpoos-pro' );
			}

			// Preferred pharmacy supports medication management.
			if ( empty( get_post_meta( $member_id, '_member_preferred_pharmacy', true ) ) ) {
				$gaps[] = __( 'Add preferred pharmacy for prescription management', 'mcp-ai-wpoos-pro' );
			}
		}

		if ( 'pet' === $member_type ) {
			if ( empty( get_post_meta( $member_id, '_pet_species', true ) ) ) {
				$gaps[] = __( 'Add pet species information', 'mcp-ai-wpoos-pro' );
			}

			if ( empty( get_post_meta( $member_id, '_pet_breed', true ) ) ) {
				$gaps[] = __( 'Add pet breed information', 'mcp-ai-wpoos-pro' );
			}
		}

		return $gaps;
	}

	/**
	 * Analyze policies (USCDI: Insurance Coverage).
	 *
	 * Checks for existence of coverage AND completeness of key billing/benefit fields.
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_type Member type.
	 * @return array Gaps found.
	 */
	private function analyze_policies( $member_id, $member_type ) {
		$gaps = array();

		$policies = get_posts(
			array(
				'post_type'      => 'mcp_ai_policy',
				'meta_key'       => '_policy_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'guide_health_record_creation', 0, 1000 ) : 1000,
				'fields'         => 'ids',
			)
		);

		if ( empty( $policies ) ) {
			$gaps[] = 'person' === $member_type
				? __( 'Add health insurance policy information (USCDI: Insurance Coverage)', 'mcp-ai-wpoos-pro' )
				: __( 'Add pet insurance policy information', 'mcp-ai-wpoos-pro' );
			return $gaps;
		}

		// Check quality of existing policies: flag missing billing/benefit fields.
		foreach ( $policies as $policy_id ) {
			$title = get_the_title( $policy_id );
			if ( empty( get_post_meta( $policy_id, '_policy_group_number', true ) ) ) {
				$gaps[] = sprintf(
					/* translators: %s: policy title */
					__( 'Policy "%s": add group number for insurance coordination', 'mcp-ai-wpoos-pro' ),
					$title
				);
			}
			if ( empty( get_post_meta( $policy_id, '_policy_plan_type', true ) ) ) {
				$gaps[] = sprintf(
					/* translators: %s: policy title */
					__( 'Policy "%s": add plan type (HMO/PPO/EPO) for coverage verification', 'mcp-ai-wpoos-pro' ),
					$title
				);
			}
			if ( empty( get_post_meta( $policy_id, '_policy_copay_primary', true ) ) && empty( get_post_meta( $policy_id, '_policy_deductible', true ) ) ) {
				$gaps[] = sprintf(
					/* translators: %s: policy title */
					__( 'Policy "%s": add copay/deductible amounts for benefits reference', 'mcp-ai-wpoos-pro' ),
					$title
				);
			}
		}

		return $gaps;
	}

	/**
	 * Analyze allergies — USCDI: Allergies and Intolerances (patient safety, high priority).
	 *
	 * Checks both existence and completeness of allergy type, onset, and treatment fields.
	 *
	 * @param int $member_id Member ID.
	 * @return array Gaps found.
	 */
	private function analyze_allergies( $member_id ) {
		$gaps = array();

		$allergies = get_posts(
			array(
				'post_type'      => 'mcp_ai_allergy',
				'meta_key'       => '_allergy_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'guide_health_record_creation', 0, 1000 ) : 1000,
				'fields'         => 'ids',
			)
		);

		if ( empty( $allergies ) ) {
			$gaps[] = __( 'PRIORITY: Document any known allergies or explicitly note "None Known" (USCDI: Allergies and Intolerances — patient safety)', 'mcp-ai-wpoos-pro' );
			return $gaps;
		}

		// Check quality of existing allergy records.
		foreach ( $allergies as $allergy_id ) {
			$title = get_the_title( $allergy_id );
			if ( empty( get_post_meta( $allergy_id, '_allergy_type', true ) ) ) {
				$gaps[] = sprintf(
					/* translators: %s: allergy title */
					__( 'Allergy "%s": add allergy type (food/drug/environmental) for FHIR AllergyIntolerance mapping', 'mcp-ai-wpoos-pro' ),
					$title
				);
			}
			if ( empty( get_post_meta( $allergy_id, '_allergy_onset_type', true ) ) ) {
				$gaps[] = sprintf(
					/* translators: %s: allergy title */
					__( 'Allergy "%s": add onset type (immediate/delayed) for clinical documentation', 'mcp-ai-wpoos-pro' ),
					$title
				);
			}
			if ( empty( get_post_meta( $allergy_id, '_allergy_treatment', true ) ) ) {
				$gaps[] = sprintf(
					/* translators: %s: allergy title */
					__( 'Allergy "%s": add treatment/management protocol (e.g. EpiPen, antihistamine)', 'mcp-ai-wpoos-pro' ),
					$title
				);
			}
		}

		return $gaps;
	}

	/**
	 * Analyze immunizations/vaccinations (USCDI: Immunizations data class).
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_type Member type.
	 * @return array Gaps found.
	 */
	private function analyze_vaccinations( $member_id, $member_type ) {
		$gaps = array();

		// Check via track_vaccinations meta stored on the member post.
		$vaccinations = get_post_meta( $member_id, '_member_vaccinations', true );

		// Also check health_reminder CPT for vaccine-type reminders.
		$vaccine_reminders = get_posts(
			array(
				'post_type'      => 'mcp_ai_health_reminder',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_reminder_member_id',
						'value' => $member_id,
					),
					array(
						'key'     => '_reminder_type',
						'value'   => 'vaccination',
						'compare' => 'LIKE',
					),
				),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $vaccinations ) && empty( $vaccine_reminders ) ) {
			$gaps[] = 'person' === $member_type
				? __( 'Add vaccination/immunization records using the Track Vaccinations tool (USCDI: Immunizations)', 'mcp-ai-wpoos-pro' )
				: __( 'Add pet vaccination records using the Track Vaccinations tool (core veterinary requirement)', 'mcp-ai-wpoos-pro' );
		}

		return $gaps;
	}

	/**
	 * Analyze vital signs (USCDI: Vital Signs data class).
	 *
	 * Checks JetEngine CCT vitals_log table or log_health_metrics option store.
	 *
	 * @param int $member_id Member ID.
	 * @return array Gaps found.
	 */
	private function analyze_vital_signs( $member_id ) {
		$gaps = array();

		$has_vitals = false;

		// Check JetEngine CCT vitals_log if available.
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) && WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			global $wpdb;
			$table = WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe.
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE member_id = %d LIMIT 1", $member_id ) );
			if ( $exists ) {
				$has_vitals = true;
			}
		}

		// Also check the log_health_metrics option store.
		if ( ! $has_vitals ) {
			$metrics = get_option( 'wp_mcp_ai_health_metrics_' . $member_id, array() );
			if ( ! empty( $metrics ) ) {
				$has_vitals = true;
			}
		}

		if ( ! $has_vitals ) {
			$gaps[] = __( 'Log baseline vital signs (BP, heart rate, weight, SpO2) using Log Vital Signs tool (USCDI: Vital Signs)', 'mcp-ai-wpoos-pro' );
		}

		return $gaps;
	}

	/**
	 * Analyze medical records (USCDI: Problems/Conditions).
	 *
	 * Checks for existence of records and completeness of ICD-10 coding.
	 *
	 * @param int $member_id Member ID.
	 * @return array Gaps found.
	 */
	private function analyze_medical_records( $member_id ) {
		$gaps = array();

		$records = get_posts(
			array(
				'post_type'      => 'mcp_ai_med_record',
				'meta_key'       => '_record_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'guide_health_record_creation', 0, 1000 ) : 1000,
				'fields'         => 'ids',
			)
		);

		if ( empty( $records ) ) {
			$gaps[] = __( 'Add initial medical history and conditions (USCDI: Problems/Conditions — FHIR Condition resource)', 'mcp-ai-wpoos-pro' );
			return $gaps;
		}

		// Check for records missing ICD-10 codes (USCDI/FHIR: Condition.code).
		$missing_icd = 0;
		foreach ( $records as $record_id ) {
			if ( empty( get_post_meta( $record_id, '_medical_record_icd_code', true ) ) ) {
				++$missing_icd;
			}
		}
		if ( $missing_icd > 0 ) {
			$gaps[] = sprintf(
				/* translators: %d: number of records missing ICD-10 */
				__( '%d medical record(s) missing ICD-10 code — add diagnosis codes for FHIR Condition resource interoperability', 'mcp-ai-wpoos-pro' ),
				$missing_icd
			);
		}

		return $gaps;
	}

	/**
	 * Analyze checkups (USCDI: Encounters).
	 *
	 * Checks for upcoming appointments and completeness of clinical documentation fields.
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_type Member type.
	 * @return array Gaps found.
	 */
	private function analyze_checkups( $member_id, $member_type ) {
		$gaps = array();

		// Check for upcoming checkups.
		$upcoming_checkups = get_posts(
			array(
				'post_type'      => 'mcp_ai_checkup',
				'meta_query'     => array(
					array(
						'key'   => '_checkup_member_id',
						'value' => $member_id,
					),
					array(
						'key'     => '_checkup_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '>=',
						'type'    => 'DATE',
					),
				),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $upcoming_checkups ) ) {
			$gaps[] = 'person' === $member_type
				? __( 'Schedule upcoming health checkup/wellness visit (USCDI: Encounters)', 'mcp-ai-wpoos-pro' )
				: __( 'Schedule upcoming veterinary checkup (USCDI: Encounters)', 'mcp-ai-wpoos-pro' );
		}

		// Check completeness of recent past checkups.
		$recent_checkups = get_posts(
			array(
				'post_type'      => 'mcp_ai_checkup',
				'meta_query'     => array(
					array(
						'key'   => '_checkup_member_id',
						'value' => $member_id,
					),
				),
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'orderby'        => 'meta_value',
				'meta_key'       => '_checkup_date',
				'order'          => 'DESC',
			)
		);

		$missing_chief = 0;
		$missing_diag  = 0;
		foreach ( $recent_checkups as $checkup_id ) {
			if ( empty( get_post_meta( $checkup_id, '_checkup_chief_complaint', true ) ) ) {
				++$missing_chief;
			}
			if ( empty( get_post_meta( $checkup_id, '_checkup_diagnosis', true ) ) ) {
				++$missing_diag;
			}
		}
		if ( $missing_chief > 0 ) {
			$gaps[] = sprintf(
				/* translators: %d: count */
				__( '%d checkup(s) missing chief complaint — add reason for visit for FHIR Encounter documentation', 'mcp-ai-wpoos-pro' ),
				$missing_chief
			);
		}
		if ( $missing_diag > 0 ) {
			$gaps[] = sprintf(
				/* translators: %d: count */
				__( '%d checkup(s) missing diagnosis/assessment — add for complete clinical encounter record', 'mcp-ai-wpoos-pro' ),
				$missing_diag
			);
		}

		return $gaps;
	}

	/**
	 * Analyze prescriptions (USCDI: Medications).
	 *
	 * Checks for missing NDC codes, route, indication, and pharmacy — fields required
	 * for FHIR MedicationStatement interoperability.
	 *
	 * @param int $member_id Member ID.
	 * @return array Gaps found.
	 */
	private function analyze_prescriptions( $member_id ) {
		$gaps = array();

		$prescriptions = get_posts(
			array(
				'post_type'      => 'mcp_ai_prescription',
				'meta_key'       => '_prescription_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'guide_health_record_creation', 0, 1000 ) : 1000,
				'fields'         => 'ids',
			)
		);

		if ( empty( $prescriptions ) ) {
			// Not flagging absence as a gap — not everyone needs medications.
			return $gaps;
		}

		$missing_ndc        = 0;
		$missing_route      = 0;
		$missing_indication = 0;
		$missing_pharmacy   = 0;

		foreach ( $prescriptions as $rx_id ) {
			if ( empty( get_post_meta( $rx_id, '_prescription_ndc_code', true ) ) ) {
				++$missing_ndc;
			}
			if ( empty( get_post_meta( $rx_id, '_prescription_route', true ) ) ) {
				++$missing_route;
			}
			if ( empty( get_post_meta( $rx_id, '_prescription_indication', true ) ) ) {
				++$missing_indication;
			}
			if ( empty( get_post_meta( $rx_id, '_prescription_pharmacy_name', true ) ) ) {
				++$missing_pharmacy;
			}
		}

		if ( $missing_ndc > 0 ) {
			$gaps[] = sprintf(
				/* translators: %d: count */
				__( '%d prescription(s) missing NDC code — add for FHIR MedicationStatement/RxNorm interoperability', 'mcp-ai-wpoos-pro' ),
				$missing_ndc
			);
		}
		if ( $missing_route > 0 ) {
			$gaps[] = sprintf(
				/* translators: %d: count */
				__( '%d prescription(s) missing route of administration (oral/topical/etc.)', 'mcp-ai-wpoos-pro' ),
				$missing_route
			);
		}
		if ( $missing_indication > 0 ) {
			$gaps[] = sprintf(
				/* translators: %d: count */
				__( '%d prescription(s) missing indication — add condition being treated', 'mcp-ai-wpoos-pro' ),
				$missing_indication
			);
		}
		if ( $missing_pharmacy > 0 ) {
			$gaps[] = sprintf(
				/* translators: %d: count */
				__( '%d prescription(s) missing dispensing pharmacy name', 'mcp-ai-wpoos-pro' ),
				$missing_pharmacy
			);
		}

		return $gaps;
	}

	/**
	 * Generate comprehensive guidance message aligned with USCDI/FHIR standards.
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_name Member name.
	 * @param string $member_type Member type.
	 * @param array  $analysis    Analysis results.
	 * @param string $focus       Focus area.
	 * @return string Guidance message.
	 */
	private function generate_guidance( $member_id, $member_name, $member_type, $analysis, $focus ) {
		$guidance = sprintf(
			/* translators: 1: member name, 2: member type */
			__( 'Health Profile Analysis for %1$s (%2$s):', 'mcp-ai-wpoos-pro' ),
			$member_name,
			ucfirst( $member_type )
		);

		$guidance .= "\n\n";

		if ( empty( $analysis['gaps'] ) ) {
			$guidance .= __( '✓ Health profile is complete and comprehensive!', 'mcp-ai-wpoos-pro' );
			$guidance .= "\n\n";
			$guidance .= __( 'Recommendations:', 'mcp-ai-wpoos-pro' );
			$guidance .= "\n";
			$guidance .= __( '• Keep records updated as new medical events occur', 'mcp-ai-wpoos-pro' );
			$guidance .= "\n";
			$guidance .= __( '• Review and update prescriptions regularly', 'mcp-ai-wpoos-pro' );
			$guidance .= "\n";
			$guidance .= __( '• Schedule routine checkups and wellness visits', 'mcp-ai-wpoos-pro' );
		} else {
			$guidance .= sprintf(
				/* translators: %d: number of gaps */
				__( 'Found %d areas that need attention to complete the health profile.', 'mcp-ai-wpoos-pro' ),
				$analysis['total_gaps']
			);

			$guidance .= "\n\n";
			$guidance .= __( 'Priority Actions (Patient Safety — High Importance):', 'mcp-ai-wpoos-pro' );
			$guidance .= "\n";

			if ( ! empty( $analysis['priority_gaps'] ) ) {
				foreach ( $analysis['priority_gaps'] as $index => $gap ) {
					$guidance .= sprintf( '%d. %s', $index + 1, $gap ) . "\n";
				}
			} else {
				$guidance .= __( '• No high-priority gaps identified', 'mcp-ai-wpoos-pro' ) . "\n";
			}

			$guidance .= "\n";
			$guidance .= __( 'Recommended Next Steps:', 'mcp-ai-wpoos-pro' );
			$guidance .= "\n";

			foreach ( $analysis['next_steps'] as $index => $step ) {
				$guidance .= sprintf( '%d. %s', $index + 1, $step ) . "\n";
			}

			if ( 'all' === $focus ) {
				$guidance .= "\n";
				$guidance .= __( 'Additional Recommendations:', 'mcp-ai-wpoos-pro' );
				$guidance .= "\n";

				$remaining_gaps = array_diff( $analysis['gaps'], $analysis['next_steps'], $analysis['priority_gaps'] );
				if ( ! empty( $remaining_gaps ) ) {
					foreach ( array_slice( $remaining_gaps, 0, 5 ) as $gap ) {
						$guidance .= '• ' . $gap . "\n";
					}
				}
			}
		}

		// USCDI coverage summary — shows which interoperability data classes are addressed.
		if ( 'all' === $focus && ! empty( $analysis['completeness'] ) ) {
			$guidance .= "\n";
			$guidance .= __( 'USCDI Data Class Coverage:', 'mcp-ai-wpoos-pro' );
			$guidance .= "\n";
			$uscdi_map = array(
				'demographics'    => __( 'Patient Demographics (FHIR: Patient)', 'mcp-ai-wpoos-pro' ),
				'allergies'       => __( 'Allergies & Intolerances (FHIR: AllergyIntolerance)', 'mcp-ai-wpoos-pro' ),
				'vaccinations'    => __( 'Immunizations (FHIR: Immunization)', 'mcp-ai-wpoos-pro' ),
				'vital_signs'     => __( 'Vital Signs (FHIR: Observation)', 'mcp-ai-wpoos-pro' ),
				'medical_records' => __( 'Problems/Conditions (FHIR: Condition — ICD-10/SNOMED CT)', 'mcp-ai-wpoos-pro' ),
				'checkups'        => __( 'Encounters (FHIR: Encounter)', 'mcp-ai-wpoos-pro' ),
				'prescriptions'   => __( 'Medications (FHIR: MedicationStatement — RxNorm/NDC)', 'mcp-ai-wpoos-pro' ),
				'policies'        => __( 'Insurance Coverage (FHIR: Coverage)', 'mcp-ai-wpoos-pro' ),
			);
			foreach ( $uscdi_map as $key => $label ) {
				if ( isset( $analysis['completeness'][ $key ] ) ) {
					$status    = $analysis['completeness'][ $key ] ? '✓' : '✗';
					$guidance .= sprintf( '  %s %s', $status, $label ) . "\n";
				}
			}
		}

		$guidance .= "\n";
		$guidance .= __( 'How I Can Help:', 'mcp-ai-wpoos-pro' );
		$guidance .= "\n";
		$guidance .= __( '• I can help you create any of these records step-by-step', 'mcp-ai-wpoos-pro' );
		$guidance .= "\n";
		$guidance .= __( '• I can guide you through gathering necessary information', 'mcp-ai-wpoos-pro' );
		$guidance .= "\n";
		$guidance .= __( '• I can schedule checkups and manage prescriptions', 'mcp-ai-wpoos-pro' );
		$guidance .= "\n";
		$guidance .= __( '• I can add ICD-10 codes, NDC codes, and allergy types to existing records', 'mcp-ai-wpoos-pro' );
		$guidance .= "\n";
		$guidance .= __( '• Just let me know which record you\'d like to add or complete first!', 'mcp-ai-wpoos-pro' );

		return $guidance;
	}
}
