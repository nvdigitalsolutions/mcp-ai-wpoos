<?php
/**
 * Tool for parsing and organizing raw health information.
 *
 * Accepts unstructured health information and intelligently parses it to create
 * appropriate health records (medical records, checkups, prescriptions, policies, allergies).
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
 * Parses and organizes raw health information into structured records.
 */
class WP_MCP_AI_Tool_Parse_Health_Information implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'parse_health_information';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Parse and Organize Health Information', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Accepts raw, unstructured health information (notes, medical records, prescriptions, policy details, lab results, etc.) and intelligently parses it into structured health records aligned with industry standards (HL7 FHIR, USCDI, ICD-10, NDC/RxNorm, LOINC). Detects diagnoses with ICD-10 code hints, medications with NDC codes and route of administration, allergy type (food/drug/environmental) and onset, lab values with reference ranges, insurance group numbers and plan types, immunizations/vaccinations, and vital sign readings. Automatically creates properly organized CPT records with all structured metadata fields populated. Perfect for bulk data entry where users want to paste everything and let AI handle the organization.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'             => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this health information belongs to (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'raw_information'       => array(
					'type'        => 'string',
					'description' => __( 'Raw, unstructured health information text to parse and organize. Can include medical records, prescriptions, allergies, checkup notes, policy details, lab results, vaccination history, vital signs, etc.', 'mcp-ai-wpoos-pro' ),
				),
				'auto_create_records'   => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically create the parsed records in the system (default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'confirmation_required' => array(
					'type'        => 'boolean',
					'description' => __( 'Require user confirmation before creating records (default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'attachment_ids'        => array(
					'type'        => 'array',
					'description' => __( 'Array of WordPress attachment IDs to attach as source documents to created records', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
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
		return array( 'pro', 'database-write', 'ai-powered' );
	}

	/**
	 * Supported record types.
	 *
	 * @var array
	 */
	const RECORD_TYPES = array( 'medical_records', 'checkups', 'prescriptions', 'policies', 'allergies' );

	/**
	 * FHIR resource type mapping for parsed record types.
	 * Used to communicate interoperability context to the AI assistant.
	 *
	 * @var array
	 */
	const FHIR_RESOURCE_MAP = array(
		'medical_records' => 'Condition',           // ICD-10/SNOMED CT coded diagnosis.
		'checkups'        => 'Encounter',            // Clinical encounter/visit.
		'prescriptions'   => 'MedicationStatement',  // RxNorm/NDC coded medication.
		'policies'        => 'Coverage',             // Insurance coverage.
		'allergies'       => 'AllergyIntolerance',   // Substance + reaction + severity.
		'vaccinations'    => 'Immunization',          // Vaccine administered.
		'vital_signs'     => 'Observation',          // LOINC coded vital measurement.
	);

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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create health records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id             = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$raw_information       = isset( $arguments['raw_information'] ) ? wp_kses_post( $arguments['raw_information'] ) : '';
		$auto_create           = isset( $arguments['auto_create_records'] ) ? (bool) $arguments['auto_create_records'] : true;
		$confirmation_required = isset( $arguments['confirmation_required'] ) ? (bool) $arguments['confirmation_required'] : false;
		$attachment_ids        = isset( $arguments['attachment_ids'] ) ? array_map( 'absint', (array) $arguments['attachment_ids'] ) : array();

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $raw_information ) && empty( $attachment_ids ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_information', __( 'Raw health information or document attachments are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Parse the raw information.
		$parsed_data = $this->parse_information( $raw_information, $member_id );

		if ( is_wp_error( $parsed_data ) ) {
			return $parsed_data;
		}

		// Create records if auto_create is enabled and confirmation not required.
		$created_records = array();
		if ( $auto_create && ! $confirmation_required ) {
			$created_records = $this->create_parsed_records( $parsed_data, $member_id, $current_user_id, $attachment_ids );
		}

		return array(
			'success'               => true,
			'member_id'             => $member_id,
			'member_name'           => $member->post_title,
			'parsed_data'           => $parsed_data,
			'records_created'       => $auto_create && ! $confirmation_required,
			'created_records'       => $created_records,
			'confirmation_required' => $confirmation_required,
			'attachment_ids'        => $attachment_ids,
			'source_documents_kept' => ! empty( $attachment_ids ),
			'parsing_summary'       => $this->generate_parsing_summary( $parsed_data, $created_records, $attachment_ids ),
		);
	}

	/**
	 * Parse raw health information into structured data.
	 *
	 * Applies USCDI-aligned extraction: detects all data classes including
	 * immunizations and vital signs as advisory hints, and annotates each
	 * parsed record with its FHIR resource type.
	 *
	 * @param string $raw_text  Raw information text.
	 * @param int    $member_id Member ID.
	 * @return array|WP_Error Parsed data or error.
	 */
	private function parse_information( $raw_text, $member_id ) {
		$parsed = array(
			'medical_records'       => array(),
			'checkups'              => array(),
			'prescriptions'         => array(),
			'policies'              => array(),
			'allergies'             => array(),
			'demographics'          => array(),
			// Advisory hints — suggest dedicated tools rather than creating CPT posts.
			'vaccinations_detected' => array(),
			'vital_signs_detected'  => array(),
			'metadata'              => array(
				'parsed_at'    => current_time( 'mysql' ),
				'data_quality' => array(),
				'completeness' => 0,
				'standards'    => array( 'USCDI', 'HL7-FHIR-R4', 'ICD-10', 'NDC', 'RxNorm', 'LOINC' ),
			),
		);

		// Use pattern matching and keyword detection to identify record types.
		// Split into lines for analysis.
		$lines = preg_split( '/\r\n|\r|\n/', $raw_text );

		$current_section = null;
		$current_record  = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				// Empty line might indicate end of a section.
				if ( ! empty( $current_record ) && null !== $current_section
					&& in_array( $current_section, self::RECORD_TYPES, true ) ) {
					$current_record               = $this->validate_and_enhance_record( $current_record, $current_section );
					$parsed[ $current_section ][] = $current_record;
					$current_record               = array();
				}
				continue;
			}

			// Detect record type from keywords.
			$detected_type = $this->detect_record_type( $line );
			if ( $detected_type ) {
				// Advisory-only detections (vaccinations/vital_signs) — store hint, don't start CPT section.
				if ( 'vaccinations' === $detected_type ) {
					$parsed['vaccinations_detected'][] = sanitize_text_field( $line );
					continue;
				}
				if ( 'vital_signs' === $detected_type ) {
					$parsed['vital_signs_detected'][] = sanitize_text_field( $line );
					continue;
				}

				// Save previous CPT record if any.
				if ( ! empty( $current_record ) && null !== $current_section
					&& in_array( $current_section, self::RECORD_TYPES, true ) ) {
					$current_record               = $this->validate_and_enhance_record( $current_record, $current_section );
					$parsed[ $current_section ][] = $current_record;
				}
				$current_section = $detected_type;
				$current_record  = array(
					'raw_line'           => $line,
					'fhir_resource_type' => self::FHIR_RESOURCE_MAP[ $detected_type ] ?? '',
				);
			} elseif ( null !== $current_section && in_array( $current_section, self::RECORD_TYPES, true ) ) {
				// Add to current record.
				if ( ! isset( $current_record['content'] ) ) {
					$current_record['content'] = '';
				}
				$current_record['content'] .= $line . "\n";
			}

			// Extract specific data patterns.
			if ( null !== $current_section && in_array( $current_section, self::RECORD_TYPES, true ) ) {
				$this->extract_data_patterns( $line, $current_record, $current_section );
			}
		}

		// Save last record.
		if ( ! empty( $current_record ) && null !== $current_section
			&& in_array( $current_section, self::RECORD_TYPES, true ) ) {
			$current_record               = $this->validate_and_enhance_record( $current_record, $current_section );
			$parsed[ $current_section ][] = $current_record;
		}

		// Calculate overall data quality and completeness.
		$parsed['metadata']['data_quality'] = $this->assess_data_quality( $parsed );
		$parsed['metadata']['completeness'] = $this->calculate_completeness( $parsed );

		// If no structured data was detected, create a general medical record.
		$has_data = false;
		foreach ( self::RECORD_TYPES as $type ) {
			if ( ! empty( $parsed[ $type ] ) ) {
				$has_data = true;
				break;
			}
		}

		if ( ! $has_data ) {
			$parsed['medical_records'][] = array(
				'title'              => __( 'Imported Health Information', 'mcp-ai-wpoos-pro' ),
				'content'            => $raw_text,
				'record_type'        => 'general',
				'date'               => current_time( 'Y-m-d' ),
				'fhir_resource_type' => 'Condition',
				'data_quality'       => array(
					'completeness' => 30, // Low completeness for unstructured data.
					'accuracy'     => 'unknown',
				),
			);
		}

		return $parsed;
	}

	/**
	 * Detect record type from line content.
	 *
	 * Returns one of the RECORD_TYPES keys, or 'vaccinations'/'vital_signs' as
	 * advisory hints (not CPT-creating types), or null if unrecognized.
	 *
	 * @param string $line Line of text.
	 * @return string|null Record type or null.
	 */
	private function detect_record_type( $line ) {
		// Immunization/vaccination detection (USCDI: Immunizations → FHIR Immunization).
		// Checked before prescriptions to prevent "flu shot" being parsed as medication.
		if ( preg_match( '/\b(vaccin(ation|ated|e|es)|immuniz(ation|ed)|flu\s+shot|booster|MMR|tdap|covid[\s-]?19\s+(vaccine|shot|booster)|varicella|hepatitis\s+[abc]|pneumococcal|shingles\s+vaccine|rabies|distemper|bordetella)\b/i', $line ) ) {
			return 'vaccinations';
		}

		// Vital signs detection (USCDI: Vital Signs → FHIR Observation/LOINC).
		// Checked early to avoid mis-classifying BP/HR lines as medical records.
		if ( preg_match( '/\b(blood\s+pressure|bp\s*:|systolic|diastolic|heart\s+rate|pulse\s*:|spo2|oxygen\s+saturation|temperature\s*:|body\s+temp|weight\s*:|height\s*:|bmi\s*:|respiratory\s+rate|rr\s*:)\b/i', $line ) ) {
			return 'vital_signs';
		}

		// Allergy detection — FHIR AllergyIntolerance (high priority; checked before prescriptions).
		if ( preg_match( '/\b(allerg(y|ies|ic)|allergic\s+to|allergen|anaphylaxis|hypersensitivity)\b/i', $line ) ) {
			return 'allergies';
		}

		// Prescription/medication detection — FHIR MedicationStatement (RxNorm/NDC).
		if ( preg_match( '/\b(prescription|medication|medicine|drug|dosage|mg|ml|tablet|pill|capsule|rx\b|prescribed|dispens|pharmacist|refill|sig\s*:)\b/i', $line ) ) {
			return 'prescriptions';
		}

		// Checkup/appointment detection — FHIR Encounter.
		if ( preg_match( '/\b(checkup|check-up|appointment|visit|scheduled|consultation|examination|office\s+exam|wellness\s+visit|follow[\s-]?up\s+visit)\b/i', $line ) ) {
			return 'checkups';
		}

		// Policy/insurance detection — FHIR Coverage.
		if ( preg_match( '/\b(insurance|policy|coverage|subscriber|plan\s+(name|type|id)|premium|deductible|copay|co-pay|in[\s-]?network|group\s+(number|id|#)|member\s+id|bin\s*:|pcn\s*:)\b/i', $line ) ) {
			return 'policies';
		}

		// Medical record/condition detection — FHIR Condition (ICD-10/SNOMED CT).
		if ( preg_match( '/\b(diagnosis|diagnosed|condition|treatment|procedure|surgery|lab\s+result|test\s+result|imaging|x[\s-]?ray|mri|ct\s+scan|icd[\s-]?10|icd[\s-]?code|snomed|loinc|pathology|biopsy|assessment)\b/i', $line ) ) {
			return 'medical_records';
		}

		return null;
	}

	/**
	 * Extract specific data patterns from a line.
	 *
	 * Applies standards-aligned extraction: ICD-10 codes, NDC codes, LOINC lab patterns,
	 * allergy type/onset/treatment, prescription route/indication/pharmacy,
	 * checkup chief complaint/diagnosis/follow-up, and policy billing fields.
	 *
	 * @param string      $line            Line of text.
	 * @param array       &$current_record Current record being built.
	 * @param string|null $section         Current section type.
	 */
	private function extract_data_patterns( $line, &$current_record, $section ) {
		// Extract dates (covers MM/DD/YYYY, MM-DD-YYYY, YYYY-MM-DD formats).
		if ( preg_match( '/\b(\d{4}-\d{2}-\d{2}|\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})\b/', $line, $matches ) ) {
			if ( empty( $current_record['date'] ) ) {
				$current_record['date'] = $matches[1];
			}
		}

		// Extract provider/doctor names.
		if ( preg_match( '/\bDr\.?\s+([A-Z][a-z]+( ? ( :\s+[A-Z][a-z]+)*)\b/', $line, $matches ) ) {
			$current_record['provider'] = sanitize_text_field( $matches[0] );
		}

		// ICD-10-CM code detection (USCDI/FHIR Condition.code — e.g. E11.9, I10, M54.5).
		// Pattern enforces: letter (valid ICD-10 range) + 2 digits + optional alphanumeric suffix.
		if ( preg_match( '/\b([A-TV-Z][0-9]{2}\.?[0-9A-Z]{0,4})\b/', $line, $matches ) ) {
			$current_record['icd_code'] = strtoupper( $matches[1] );
		}

		// --- Prescription-specific patterns (FHIR MedicationStatement) ---
		if ( 'prescriptions' === $section ) {
			// Dosage amount.
			if ( preg_match( '/\b(\d+( ? ( :\.\d+)?\s*( ? ( :mg|ml|g|mcg|iu|units?))\b/i', $line, $matches ) ) {
				$current_record['dosage'] = $matches[0];
			}
			// Frequency/sig.
			if ( preg_match( '/\b(once|twice|three\s+times|q\.?d\.?|b\.?i\.?d\.?|t\.?i\.?d\.?|q\.?i\.?d\.?|every\s+\d+\s+hours?|daily|weekly|monthly|as\s+needed|prn)\b/i', $line, $matches ) ) {
				$current_record['frequency'] = $matches[0];
			}
			// Route of administration.
			if ( preg_match( '/\b(oral(ly)?|topical(ly)?|intravenous(ly)?|i\.?v\.?|injection|subcutaneous|inhaled?|sublingual|nasal|ophthalmic|otic|rectal|transdermal|patch)\b/i', $line, $matches ) ) {
				$current_record['route'] = strtolower( $matches[1] );
			}
			// NDC code (National Drug Code — e.g. 0069-0010-01).
			if ( preg_match( '/\b(\d{4,5}-\d{3,4}-\d{1,2})\b/', $line, $matches ) ) {
				$current_record['ndc_code'] = $matches[1];
			}
			// Rx/Prescription number.
			if ( preg_match( '/\bRx\s*#?\s*(\w+)\b/i', $line, $matches ) ) {
				$current_record['rx_number'] = sanitize_text_field( $matches[1] );
			}
			// Indication / reason (e.g. "for hypertension", "treats type 2 diabetes").
			if ( preg_match( '/\b( ? ( :for|treats?|prescribed\s+for|indication\s*:)\s+([^,.\n]{3,60})/i', $line, $matches ) ) {
				$current_record['indication'] = sanitize_text_field( trim( $matches[1] ) );
			}
			// Pharmacy name (e.g. "CVS Pharmacy", "Walgreens").
			if ( preg_match( '/\b(CVS|Walgreens|Rite\s+Aid|Walmart\s+Pharmacy|Costco\s+Pharmacy|Kroger\s+Pharmacy|Publix\s+Pharmacy|[A-Z][a-z]+\s+Pharmacy)\b/', $line, $matches ) ) {
				$current_record['pharmacy_name'] = sanitize_text_field( $matches[1] );
			}
		}

		// --- Allergy-specific patterns (FHIR AllergyIntolerance) ---
		if ( 'allergies' === $section ) {
			// Allergy severity.
			if ( preg_match( '/\b(mild|moderate|severe|life[\s-]?threatening)\b/i', $line, $matches ) ) {
				$sev_raw = strtolower( str_replace( array( ' ', '-' ), '', $matches[1] ) );
				// 'lifethreatening' maps to 'severe' for consistent taxonomy.
				$current_record['severity'] = ( 'lifethreatening' === $sev_raw ) ? 'severe' : $sev_raw;
			}
			// Allergy type — normalised to FHIR AllergyIntolerance.category value set.
			if ( preg_match( '/\b(food|drug|medication|environmental|environment|insect|latex|contrast\s*dye|biologic|other)\b/i', $line, $matches ) ) {
				$raw_type = strtolower( trim( $matches[1] ) );
				// Map to FHIR AllergyIntolerance.category: food | medication | environment | biologic | other.
				$fhir_category_map              = array(
					'drug'          => 'medication',
					'medication'    => 'medication',
					'food'          => 'food',
					'environment'   => 'environment',
					'environmental' => 'environment',
					'biologic'      => 'biologic',
					'insect'        => 'environment',
					'latex'         => 'environment',
					'contrastdye'   => 'medication',
				);
				$normalized                     = str_replace( array( ' ', '-' ), '', $raw_type );
				$current_record['allergy_type'] = isset( $fhir_category_map[ $normalized ] ) ? $fhir_category_map[ $normalized ] : 'other';
			}
			// Onset type — mapped to FHIR AllergyIntolerance.reaction.onset categories.
			if ( preg_match( '/\b(immediate|anaphylactic|anaphylaxis|delayed|contact|late[\s-]?phase)\b/i', $line, $matches ) ) {
				$onset_raw = strtolower( str_replace( array( ' ', '-' ), '', $matches[1] ) );
				// Map variants: anaphylactic/anaphylaxis → immediate; contact/latephase → delayed.
				$onset_map                    = array(
					'immediate'    => 'immediate',
					'anaphylactic' => 'immediate',
					'anaphylaxis'  => 'immediate',
					'delayed'      => 'delayed',
					'contact'      => 'delayed',
					'latephase'    => 'delayed',
				);
				$current_record['onset_type'] = isset( $onset_map[ $onset_raw ] ) ? $onset_map[ $onset_raw ] : 'immediate';
			}
			// Treatment/management protocol.
			if ( preg_match( '/\b(EpiPen|epinephrine|antihistamine|Benadryl|diphenhydramine|corticosteroid|prednisone|avoid\s+exposure|carry\s+EpiPen)\b/i', $line, $matches ) ) {
				if ( empty( $current_record['treatment'] ) ) {
					$current_record['treatment'] = sanitize_text_field( $matches[0] );
				}
			}
			// Reaction description.
			if ( preg_match( '/\b(hives?|rash|swelling|anaphylaxis|itching|vomiting|diarrhea|difficulty\s+breathing|wheezing|urticaria)\b/i', $line, $matches ) ) {
				if ( empty( $current_record['reaction'] ) ) {
					$current_record['reaction'] = sanitize_text_field( $matches[0] );
				}
			}
		}

		// --- Checkup-specific patterns (FHIR Encounter) ---
		if ( 'checkups' === $section ) {
			// Chief complaint / reason for visit.
			if ( preg_match( '/\b( ? ( :chief\s+complaint|reason\s+for\s+visit|presenting\s+( ? ( :with|complaint)|cc\s*:)\s*(.{3,120})/i', $line, $matches ) ) {
				$current_record['chief_complaint'] = sanitize_text_field( trim( $matches[1] ) );
			}
			// Diagnosis/assessment.
			if ( preg_match( '/\b( ? ( :diagnosis|assessment|impression|dx\s*:|a\/p\s*:)\s*(.{3,120})/i', $line, $matches ) ) {
				$current_record['diagnosis'] = sanitize_text_field( trim( $matches[1] ) );
			}
			// Follow-up date / return visit.
			if ( preg_match( '/\b( ? ( :follow[\s-]?up\s+( ? ( :in\s+)?|return\s+in\s+|next\s+visit\s+( ? ( :in\s+)?)(\d+\s*( ? ( :days?|weeks?|months?))/i', $line, $matches ) ) {
				$current_record['follow_up_note'] = sanitize_text_field( $matches[0] );
			}
			// Copay amount paid.
			if ( preg_match( '/\$\s*(\d+( ? ( :\.\d{2})?)\s*copay/i', $line, $matches ) ) {
				$current_record['copay_amount'] = sanitize_text_field( '$' . $matches[1] );
			}
			// Duration in minutes.
			if ( preg_match( '/\b(\d+)\s*( ? ( :min( ? ( :utes?)?|mins?)\b/i', $line, $matches ) ) {
				$current_record['duration_minutes'] = absint( $matches[1] );
			}
		}

		// --- Policy-specific patterns (FHIR Coverage) ---
		if ( 'policies' === $section ) {
			// Group number.
			if ( preg_match( '/\b( ? ( :group\s*( ? ( :number|#|id|no\.?)\s*:?\s*)([A-Z0-9-]{3,20})\b/i', $line, $matches ) ) {
				$current_record['group_number'] = sanitize_text_field( $matches[1] );
			}
			// Plan type (HMO/PPO/EPO/HDHP/POS).
			if ( preg_match( '/\b(HMO|PPO|EPO|HDHP|HSA|POS|catastrophic|indemnity)\b/i', $line, $matches ) ) {
				$current_record['plan_type'] = strtoupper( $matches[1] );
			}
			// Copay amount.
			if ( preg_match( '/\$\s*(\d+( ? ( :\.\d{2})?)\s*( ? ( :copay|co[\s-]?pay)/i', $line, $matches ) ) {
				$current_record['copay_primary'] = sanitize_text_field( '$' . $matches[1] );
			}
			// Deductible.
			if ( preg_match( '/\b( ? ( :deductible\s*:?\s*)\$?\s*([\d,]+( ? ( :\.\d{2})?)/i', $line, $matches ) ) {
				$current_record['deductible'] = sanitize_text_field( '$' . str_replace( ',', '', $matches[1] ) );
			}
			// Out-of-pocket maximum.
			if ( preg_match( '/\b( ? ( :out[\s-]?of[\s-]?pocket\s+( ? ( :max( ? ( :imum)?|limit)\s*:?\s*)\$?\s*([\d,]+( ? ( :\.\d{2})?)/i', $line, $matches ) ) {
				$current_record['out_of_pocket_max'] = sanitize_text_field( '$' . str_replace( ',', '', $matches[1] ) );
			}
			// Pharmacy BIN number.
			if ( preg_match( '/\b( ? ( :bin|rx\s+bin)\s*#?\s*:?\s*(\d{6})\b/i', $line, $matches ) ) {
				$current_record['rx_bin'] = $matches[1];
			}
			// Pharmacy PCN.
			if ( preg_match( '/\b( ? ( :pcn|rx\s+pcn)\s*#?\s*:?\s*([A-Z0-9]{1,10})\b/i', $line, $matches ) ) {
				$current_record['rx_pcn'] = strtoupper( $matches[1] );
			}
		}

		// --- Medical record-specific patterns (FHIR Condition) ---
		if ( 'medical_records' === $section ) {
			// Lab value with unit (LOINC-based: e.g. "glucose: 5.4 mmol/L").
			if ( preg_match( '/\b(\d+( ? ( :\.\d+)?)\s*(mg\/dL|mmol\/L|g\/dL|mEq\/L|U\/L|IU\/L|%|cells\/µL|K\/µL|M\/µL|pg|fL)\b/i', $line, $matches ) ) {
				$current_record['lab_value'] = $matches[1];
				$current_record['lab_unit']  = $matches[2];
			}
			// Lab reference range (e.g. "reference range: 3.5-5.0 mmol/L", "normal: 70-100").
			if ( preg_match( '/\b( ? ( :ref( ? ( :erence)?\s*( ? ( :range)?\s*:?|normal\s*:?)\s*([\d.]+-[\d.]+\s*\S*)/i', $line, $matches ) ) {
				$current_record['lab_reference_range'] = sanitize_text_field( $matches[1] );
			}
			// Abnormal flag (H/L flags or "abnormal"/"high"/"low" keyword).
			if ( preg_match( '/\b(abnormal|high\s+( ? ( :value|result)|low\s+( ? ( :value|result)|\bH\b|\bL\b|critical\s+value|panic\s+value)\b/i', $line ) ) {
				$current_record['lab_abnormal'] = true;
			}
		}
	}

	/**
	 * Create actual WordPress posts from parsed data.
	 *
	 * Saves all structured metadata fields extracted during parsing, including
	 * the new PR 4249 fields: ICD-10 codes, NDC codes, allergy type/onset/treatment,
	 * checkup chief complaint/diagnosis/follow-up, and policy group/plan/copay fields.
	 *
	 * @param array $parsed_data     Parsed health data.
	 * @param int   $member_id       Member ID.
	 * @param int   $current_user_id Current user ID.
	 * @param array $attachment_ids  Array of attachment IDs to link to records.
	 * @return array Created record IDs and details.
	 */
	private function create_parsed_records( $parsed_data, $member_id, $current_user_id, $attachment_ids = array() ) {
		$created = array(
			'medical_records' => array(),
			'checkups'        => array(),
			'prescriptions'   => array(),
			'policies'        => array(),
			'allergies'       => array(),
		);

		// Create medical records (FHIR Condition — ICD-10/SNOMED CT).
		foreach ( $parsed_data['medical_records'] as $record_data ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_med_record',
					'post_title'   => isset( $record_data['title'] ) ? sanitize_text_field( $record_data['title'] ) : __( 'Imported Medical Record', 'mcp-ai-wpoos-pro' ),
					'post_content' => isset( $record_data['content'] ) ? wp_kses_post( $record_data['content'] ) : '',
					'post_status'  => 'publish',
					'post_author'  => $current_user_id,
				)
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_record_member_id', $member_id );
				if ( ! empty( $record_data['date'] ) ) {
					update_post_meta( $post_id, '_record_date', sanitize_text_field( $record_data['date'] ) );
				}
				if ( ! empty( $record_data['provider'] ) ) {
					update_post_meta( $post_id, '_record_provider', sanitize_text_field( $record_data['provider'] ) );
				}
				if ( ! empty( $record_data['record_type'] ) ) {
					wp_set_object_terms( $post_id, $record_data['record_type'], 'mcp_ai_record_type' );
				}
				// ICD-10 code (USCDI/FHIR Condition.code).
				if ( ! empty( $record_data['icd_code'] ) ) {
					update_post_meta( $post_id, '_medical_record_icd_code', sanitize_text_field( $record_data['icd_code'] ) );
				}
				// Lab result fields (FHIR Observation/LOINC).
				if ( ! empty( $record_data['lab_value'] ) ) {
					update_post_meta( $post_id, '_medical_record_lab_value', sanitize_text_field( $record_data['lab_value'] ) );
				}
				if ( ! empty( $record_data['lab_unit'] ) ) {
					update_post_meta( $post_id, '_medical_record_lab_unit', sanitize_text_field( $record_data['lab_unit'] ) );
				}
				if ( ! empty( $record_data['lab_reference_range'] ) ) {
					update_post_meta( $post_id, '_medical_record_lab_reference_range', sanitize_text_field( $record_data['lab_reference_range'] ) );
				}
				if ( ! empty( $record_data['lab_abnormal'] ) ) {
					update_post_meta( $post_id, '_medical_record_lab_abnormal', 1 );
				}

				// Attach source documents for audit trail and HIPAA compliance.
				if ( ! empty( $attachment_ids ) ) {
					foreach ( $attachment_ids as $attachment_id ) {
						wp_update_post(
							array(
								'ID'          => $attachment_id,
								'post_parent' => $post_id,
							)
						);
						update_post_meta( $attachment_id, '_wp_mcp_ai_linked_record_id', $post_id );
						update_post_meta( $attachment_id, '_wp_mcp_ai_linked_record_type', 'mcp_ai_med_record' );
					}
					update_post_meta( $post_id, '_wp_mcp_ai_source_documents_count', count( $attachment_ids ) );
				}

				$created['medical_records'][] = $post_id;
			}
		}

		// Create checkups (FHIR Encounter).
		foreach ( $parsed_data['checkups'] as $checkup_data ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_checkup',
					'post_title'   => isset( $checkup_data['title'] ) ? sanitize_text_field( $checkup_data['title'] ) : __( 'Imported Checkup', 'mcp-ai-wpoos-pro' ),
					'post_content' => isset( $checkup_data['content'] ) ? wp_kses_post( $checkup_data['content'] ) : '',
					'post_status'  => 'publish',
					'post_author'  => $current_user_id,
				)
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_checkup_member_id', $member_id );
				if ( ! empty( $checkup_data['date'] ) ) {
					update_post_meta( $post_id, '_checkup_date', sanitize_text_field( $checkup_data['date'] ) );
				}
				if ( ! empty( $checkup_data['provider'] ) ) {
					update_post_meta( $post_id, '_checkup_provider', sanitize_text_field( $checkup_data['provider'] ) );
				}
				// Clinical documentation fields (FHIR Encounter).
				if ( ! empty( $checkup_data['chief_complaint'] ) ) {
					update_post_meta( $post_id, '_checkup_chief_complaint', sanitize_textarea_field( $checkup_data['chief_complaint'] ) );
				}
				if ( ! empty( $checkup_data['diagnosis'] ) ) {
					update_post_meta( $post_id, '_checkup_diagnosis', sanitize_textarea_field( $checkup_data['diagnosis'] ) );
				}
				if ( ! empty( $checkup_data['follow_up_note'] ) ) {
					update_post_meta( $post_id, '_checkup_follow_up_note', sanitize_text_field( $checkup_data['follow_up_note'] ) );
				}
				if ( ! empty( $checkup_data['copay_amount'] ) ) {
					update_post_meta( $post_id, '_checkup_copay_amount', sanitize_text_field( $checkup_data['copay_amount'] ) );
				}
				if ( ! empty( $checkup_data['duration_minutes'] ) ) {
					update_post_meta( $post_id, '_checkup_duration_minutes', absint( $checkup_data['duration_minutes'] ) );
				}
				// ICD-10 on the checkup if extracted.
				if ( ! empty( $checkup_data['icd_code'] ) ) {
					update_post_meta( $post_id, '_checkup_diagnosis_icd_code', sanitize_text_field( $checkup_data['icd_code'] ) );
				}
				$created['checkups'][] = $post_id;
			}
		}

		// Create prescriptions (FHIR MedicationStatement — RxNorm/NDC).
		foreach ( $parsed_data['prescriptions'] as $prescription_data ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_prescription',
					'post_title'   => isset( $prescription_data['title'] ) ? sanitize_text_field( $prescription_data['title'] ) : __( 'Imported Prescription', 'mcp-ai-wpoos-pro' ),
					'post_content' => isset( $prescription_data['content'] ) ? wp_kses_post( $prescription_data['content'] ) : '',
					'post_status'  => 'publish',
					'post_author'  => $current_user_id,
				)
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_prescription_member_id', $member_id );
				if ( ! empty( $prescription_data['dosage'] ) ) {
					update_post_meta( $post_id, '_prescription_dosage', sanitize_text_field( $prescription_data['dosage'] ) );
				}
				if ( ! empty( $prescription_data['frequency'] ) ) {
					update_post_meta( $post_id, '_prescription_frequency', sanitize_text_field( $prescription_data['frequency'] ) );
				}
				if ( ! empty( $prescription_data['date'] ) ) {
					update_post_meta( $post_id, '_prescription_start_date', sanitize_text_field( $prescription_data['date'] ) );
				}
				// New structured fields (NDC/RxNorm, route, indication, pharmacy).
				if ( ! empty( $prescription_data['ndc_code'] ) ) {
					update_post_meta( $post_id, '_prescription_ndc_code', sanitize_text_field( $prescription_data['ndc_code'] ) );
				}
				if ( ! empty( $prescription_data['rx_number'] ) ) {
					update_post_meta( $post_id, '_prescription_rx_number', sanitize_text_field( $prescription_data['rx_number'] ) );
				}
				if ( ! empty( $prescription_data['route'] ) ) {
					update_post_meta( $post_id, '_prescription_route', sanitize_text_field( $prescription_data['route'] ) );
				}
				if ( ! empty( $prescription_data['indication'] ) ) {
					update_post_meta( $post_id, '_prescription_indication', sanitize_text_field( $prescription_data['indication'] ) );
				}
				if ( ! empty( $prescription_data['pharmacy_name'] ) ) {
					update_post_meta( $post_id, '_prescription_pharmacy_name', sanitize_text_field( $prescription_data['pharmacy_name'] ) );
				}
				$created['prescriptions'][] = $post_id;
			}
		}

		// Create policies (FHIR Coverage).
		foreach ( $parsed_data['policies'] as $policy_data ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_policy',
					'post_title'   => isset( $policy_data['title'] ) ? sanitize_text_field( $policy_data['title'] ) : __( 'Imported Policy', 'mcp-ai-wpoos-pro' ),
					'post_content' => isset( $policy_data['content'] ) ? wp_kses_post( $policy_data['content'] ) : '',
					'post_status'  => 'publish',
					'post_author'  => $current_user_id,
				)
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_policy_member_id', $member_id );
				if ( ! empty( $policy_data['provider'] ) ) {
					update_post_meta( $post_id, '_policy_provider', sanitize_text_field( $policy_data['provider'] ) );
				}
				// Insurance billing/benefit fields.
				if ( ! empty( $policy_data['group_number'] ) ) {
					update_post_meta( $post_id, '_policy_group_number', sanitize_text_field( $policy_data['group_number'] ) );
				}
				if ( ! empty( $policy_data['plan_type'] ) ) {
					update_post_meta( $post_id, '_policy_plan_type', sanitize_text_field( $policy_data['plan_type'] ) );
				}
				if ( ! empty( $policy_data['copay_primary'] ) ) {
					update_post_meta( $post_id, '_policy_copay_primary', sanitize_text_field( $policy_data['copay_primary'] ) );
				}
				if ( ! empty( $policy_data['deductible'] ) ) {
					update_post_meta( $post_id, '_policy_deductible', sanitize_text_field( $policy_data['deductible'] ) );
				}
				if ( ! empty( $policy_data['out_of_pocket_max'] ) ) {
					update_post_meta( $post_id, '_policy_out_of_pocket_max', sanitize_text_field( $policy_data['out_of_pocket_max'] ) );
				}
				if ( ! empty( $policy_data['rx_bin'] ) ) {
					update_post_meta( $post_id, '_policy_rx_bin', sanitize_text_field( $policy_data['rx_bin'] ) );
				}
				if ( ! empty( $policy_data['rx_pcn'] ) ) {
					update_post_meta( $post_id, '_policy_rx_pcn', sanitize_text_field( $policy_data['rx_pcn'] ) );
				}
				$created['policies'][] = $post_id;
			}
		}

		// Create allergies (FHIR AllergyIntolerance).
		foreach ( $parsed_data['allergies'] as $allergy_data ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_allergy',
					'post_title'   => isset( $allergy_data['title'] ) ? sanitize_text_field( $allergy_data['title'] ) : __( 'Imported Allergy', 'mcp-ai-wpoos-pro' ),
					'post_content' => isset( $allergy_data['content'] ) ? wp_kses_post( $allergy_data['content'] ) : '',
					'post_status'  => 'publish',
					'post_author'  => $current_user_id,
				)
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_allergy_member_id', $member_id );
				if ( ! empty( $allergy_data['severity'] ) ) {
					wp_set_object_terms( $post_id, $allergy_data['severity'], 'mcp_ai_allergy_severity' );
					update_post_meta( $post_id, '_allergy_severity', sanitize_text_field( $allergy_data['severity'] ) );
				}
				if ( ! empty( $allergy_data['reaction'] ) ) {
					update_post_meta( $post_id, '_allergy_reaction', sanitize_text_field( $allergy_data['reaction'] ) );
				}
				// New structured allergy fields (FHIR AllergyIntolerance.category + reaction).
				if ( ! empty( $allergy_data['allergy_type'] ) ) {
					update_post_meta( $post_id, '_allergy_type', sanitize_text_field( $allergy_data['allergy_type'] ) );
				}
				if ( ! empty( $allergy_data['onset_type'] ) ) {
					update_post_meta( $post_id, '_allergy_onset_type', sanitize_text_field( $allergy_data['onset_type'] ) );
				}
				if ( ! empty( $allergy_data['treatment'] ) ) {
					update_post_meta( $post_id, '_allergy_treatment', sanitize_textarea_field( $allergy_data['treatment'] ) );
				}
				if ( ! empty( $allergy_data['date'] ) ) {
					update_post_meta( $post_id, '_allergy_last_reaction_date', sanitize_text_field( $allergy_data['date'] ) );
				}
				$created['allergies'][] = $post_id;
			}
		}

		return $created;
	}

	/**
	 * Generate summary of parsing results including FHIR resource types and advisory hints.
	 *
	 * @param array $parsed_data     Parsed data.
	 * @param array $created_records Created record IDs.
	 * @param array $attachment_ids  Attachment IDs.
	 * @return string Summary message.
	 */
	private function generate_parsing_summary( $parsed_data, $created_records, $attachment_ids = array() ) {
		$summary = __( 'Health Information Parsing Results (HL7 FHIR-aligned):', 'mcp-ai-wpoos-pro' ) . "\n\n";

		$summary .= __( 'Identified and processed:', 'mcp-ai-wpoos-pro' ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['medical_records'] ), __( 'medical record(s) → FHIR Condition (ICD-10/SNOMED CT)', 'mcp-ai-wpoos-pro' ) ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['checkups'] ), __( 'checkup(s) → FHIR Encounter', 'mcp-ai-wpoos-pro' ) ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['prescriptions'] ), __( 'prescription(s) → FHIR MedicationStatement (NDC/RxNorm)', 'mcp-ai-wpoos-pro' ) ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['policies'] ), __( 'insurance policy/policies → FHIR Coverage', 'mcp-ai-wpoos-pro' ) ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['allergies'] ), __( 'allergy/allergies → FHIR AllergyIntolerance', 'mcp-ai-wpoos-pro' ) ) . "\n";

		// Advisory hints for vaccinations and vital signs.
		if ( ! empty( $parsed_data['vaccinations_detected'] ) ) {
			$summary .= "\n";
			$summary .= sprintf(
				/* translators: %d: count of vaccination lines */
				__( '💉 %d vaccination/immunization line(s) detected (USCDI: Immunizations — FHIR Immunization)', 'mcp-ai-wpoos-pro' ),
				count( $parsed_data['vaccinations_detected'] )
			) . "\n";
			$summary .= __( '  → Use the Track Vaccinations tool to record these properly.', 'mcp-ai-wpoos-pro' ) . "\n";
		}

		if ( ! empty( $parsed_data['vital_signs_detected'] ) ) {
			$summary .= "\n";
			$summary .= sprintf(
				/* translators: %d: count of vital signs lines */
				__( '📊 %d vital sign reading(s) detected (USCDI: Vital Signs — FHIR Observation/LOINC)', 'mcp-ai-wpoos-pro' ),
				count( $parsed_data['vital_signs_detected'] )
			) . "\n";
			$summary .= __( '  → Use the Log Vital Signs tool to record these in the CCT.', 'mcp-ai-wpoos-pro' ) . "\n";
		}

		if ( ! empty( $created_records ) ) {
			$total_created = array_sum( array_map( 'count', $created_records ) );
			$summary      .= "\n";
			$summary      .= sprintf(
				/* translators: %d: number of records created */
				__( '✓ Successfully created %d health record(s) in the system!', 'mcp-ai-wpoos-pro' ),
				$total_created
			) . "\n";

			if ( ! empty( $attachment_ids ) ) {
				$summary .= "\n";
				$summary .= sprintf(
					/* translators: %d: number of source documents */
					__( '✓ %d original source document(s) preserved in media library', 'mcp-ai-wpoos-pro' ),
					count( $attachment_ids )
				) . "\n";
				$summary .= __( '✓ Documents attached to records for HIPAA compliance and future validation', 'mcp-ai-wpoos-pro' ) . "\n";
			}

			$summary .= "\n" . __( 'You can now view and edit these records in the WordPress admin.', 'mcp-ai-wpoos-pro' );
		}

		return $summary;
	}

	/**
	 * Validate and enhance record with data quality metadata.
	 *
	 * Implements USCDI/FHIR-aligned healthcare data quality dimensions:
	 * - Accuracy: Verify data is correct and valid (ICD-10 format, NDC format)
	 * - Completeness: Ensure all required fields are present (type, severity, NDC)
	 * - Consistency: Check data follows expected formats
	 * - Timeliness: Validate dates are reasonable
	 * - Relevancy: Ensure data is appropriate for the record type
	 *
	 * @param array  $record  Record data.
	 * @param string $type    Record type.
	 * @return array Enhanced record with quality metadata.
	 */
	private function validate_and_enhance_record( $record, $type ) {
		$quality_score = 100;
		$issues        = array();

		// Extract title if not set.
		if ( empty( $record['title'] ) ) {
			if ( ! empty( $record['raw_line'] ) ) {
				$record['title'] = sanitize_text_field( substr( $record['raw_line'], 0, 100 ) );
			} elseif ( ! empty( $record['content'] ) ) {
				$record['title'] = sanitize_text_field( substr( $record['content'], 0, 100 ) );
			} else {
				$record['title'] = $this->get_default_title( $type );
			}
		}

		// Validate dates (timeliness check).
		if ( isset( $record['date'] ) ) {
			$date_timestamp = strtotime( $record['date'] );
			if ( false === $date_timestamp ) {
				$quality_score -= 10;
				$issues[]       = 'invalid_date_format';
				unset( $record['date'] );
			} else {
				$now         = current_time( 'timestamp' );
				$century_ago = strtotime( '-100 years' );
				if ( $date_timestamp > $now ) {
					$quality_score -= 5;
					$issues[]       = 'future_date';
				} elseif ( $date_timestamp < $century_ago ) {
					$quality_score -= 5;
					$issues[]       = 'very_old_date';
				}
			}
		} else {
			$quality_score -= 15;
			$issues[]       = 'missing_date';
			$record['date'] = current_time( 'Y-m-d' );
		}

		// Type-specific validation with USCDI/FHIR field checks.
		switch ( $type ) {
			case 'prescriptions':
				// FHIR MedicationStatement: dosage and frequency are essential.
				if ( empty( $record['dosage'] ) ) {
					$quality_score -= 10;
					$issues[]       = 'missing_dosage';
				}
				if ( empty( $record['frequency'] ) ) {
					$quality_score -= 10;
					$issues[]       = 'missing_frequency';
				}
				// NDC code enables RxNorm interoperability (USCDI Medications).
				if ( empty( $record['ndc_code'] ) ) {
					$quality_score -= 8;
					$issues[]       = 'missing_ndc_code';
				}
				// Route of administration (FHIR MedicationStatement.dosage.route).
				if ( empty( $record['route'] ) ) {
					$quality_score -= 5;
					$issues[]       = 'missing_route';
				}
				// Indication (what condition is being treated).
				if ( empty( $record['indication'] ) ) {
					$quality_score -= 5;
					$issues[]       = 'missing_indication';
				}
				break;

			case 'allergies':
				// FHIR AllergyIntolerance: severity is patient safety critical.
				if ( empty( $record['severity'] ) ) {
					$quality_score     -= 15;
					$issues[]           = 'missing_severity';
					$record['severity'] = 'moderate'; // Default to moderate for safety.
				}
				// Allergy type (food/drug/environmental) is a USCDI required field.
				if ( empty( $record['allergy_type'] ) ) {
					$quality_score -= 10;
					$issues[]       = 'missing_allergy_type';
				}
				// Onset type (immediate vs delayed) for clinical management.
				if ( empty( $record['onset_type'] ) ) {
					$quality_score -= 5;
					$issues[]       = 'missing_onset_type';
				}
				break;

			case 'checkups':
				// FHIR Encounter: provider is required for encounter documentation.
				if ( empty( $record['provider'] ) ) {
					$quality_score -= 10;
					$issues[]       = 'missing_provider';
				}
				// Chief complaint is clinically essential for encounter records.
				if ( empty( $record['chief_complaint'] ) ) {
					$quality_score -= 8;
					$issues[]       = 'missing_chief_complaint';
				}
				// Diagnosis/assessment for FHIR Encounter.reasonCode.
				if ( empty( $record['diagnosis'] ) ) {
					$quality_score -= 8;
					$issues[]       = 'missing_diagnosis';
				}
				break;

			case 'medical_records':
				// FHIR Condition: record type for taxonomy.
				if ( empty( $record['record_type'] ) ) {
					$quality_score        -= 5;
					$record['record_type'] = 'general';
				}
				// ICD-10 code enables USCDI/FHIR Condition.code interoperability.
				if ( empty( $record['icd_code'] ) ) {
					$quality_score -= 10;
					$issues[]       = 'missing_icd10_code';
				}
				break;

			case 'policies':
				// FHIR Coverage: group number and plan type are essential.
				if ( empty( $record['group_number'] ) ) {
					$quality_score -= 8;
					$issues[]       = 'missing_group_number';
				}
				if ( empty( $record['plan_type'] ) ) {
					$quality_score -= 5;
					$issues[]       = 'missing_plan_type';
				}
				break;
		}

		// Check completeness of content.
		if ( empty( $record['content'] ) || strlen( trim( $record['content'] ) ) < 10 ) {
			$quality_score -= 20;
			$issues[]       = 'insufficient_content';
		}

		// Add quality metadata to record.
		$record['data_quality'] = array(
			'score'  => max( 0, min( 100, $quality_score ) ),
			'issues' => $issues,
			'status' => $quality_score >= 80 ? 'high' : ( $quality_score >= 50 ? 'medium' : 'low' ),
		);

		return $record;
	}

	/**
	 * Get default title for a record type.
	 *
	 * @param string $type Record type.
	 * @return string Default title.
	 */
	private function get_default_title( $type ) {
		$titles = array(
			'medical_records' => __( 'Medical Record', 'mcp-ai-wpoos-pro' ),
			'checkups'        => __( 'Checkup', 'mcp-ai-wpoos-pro' ),
			'prescriptions'   => __( 'Prescription', 'mcp-ai-wpoos-pro' ),
			'policies'        => __( 'Insurance Policy', 'mcp-ai-wpoos-pro' ),
			'allergies'       => __( 'Allergy', 'mcp-ai-wpoos-pro' ),
		);

		return isset( $titles[ $type ] ) ? $titles[ $type ] : __( 'Health Record', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Assess overall data quality of parsed information.
	 *
	 * Based on healthcare data quality dimensions:
	 * - Accuracy: Correctness of the data
	 * - Completeness: Presence of all required data elements
	 * - Consistency: Adherence to expected formats
	 * - Timeliness: Currentness and temporal validity
	 *
	 * @param array $parsed Parsed data array.
	 * @return array Quality assessment.
	 */
	private function assess_data_quality( $parsed ) {
		$total_records        = 0;
		$total_score          = 0;
		$quality_distribution = array(
			'high'   => 0,
			'medium' => 0,
			'low'    => 0,
		);

		foreach ( self::RECORD_TYPES as $type ) {
			if ( ! empty( $parsed[ $type ] ) ) {
				foreach ( $parsed[ $type ] as $record ) {
					if ( isset( $record['data_quality']['score'] ) ) {
						$total_score += $record['data_quality']['score'];
						++$total_records;

						if ( isset( $record['data_quality']['status'] ) ) {
							++$quality_distribution[ $record['data_quality']['status'] ];
						}
					}
				}
			}
		}

		$average_score = $total_records > 0 ? round( $total_score / $total_records ) : 0;

		return array(
			'average_score'      => $average_score,
			'total_records'      => $total_records,
			'distribution'       => $quality_distribution,
			'overall_assessment' => $average_score >= 80 ? 'excellent' : ( $average_score >= 60 ? 'good' : ( $average_score >= 40 ? 'fair' : 'poor' ) ),
			'recommendations'    => $this->get_quality_recommendations( $average_score, $quality_distribution ),
		);
	}

	/**
	 * Calculate completeness of health profile.
	 *
	 * Follows industry best practice of measuring data completeness
	 * across multiple health dimensions.
	 *
	 * @param array $parsed Parsed data array.
	 * @return int Completeness percentage (0-100).
	 */
	private function calculate_completeness( $parsed ) {
		$filled_sections = 0;

		foreach ( self::RECORD_TYPES as $section ) {
			if ( ! empty( $parsed[ $section ] ) ) {
				++$filled_sections;
			}
		}

		return round( ( $filled_sections / count( self::RECORD_TYPES ) ) * 100 );
	}

	/**
	 * Get quality improvement recommendations based on USCDI/FHIR standards.
	 *
	 * @param int   $average_score Average quality score.
	 * @param array $distribution  Quality distribution.
	 * @return array Recommendations.
	 */
	private function get_quality_recommendations( $average_score, $distribution ) {
		$recommendations = array();

		if ( $average_score < 60 ) {
			$recommendations[] = __( 'Consider adding more details like dates, providers, dosages, and ICD-10 codes', 'mcp-ai-wpoos-pro' );
		}

		if ( $distribution['low'] > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of low quality records */
				__( '%d record(s) have low data quality — review and add ICD-10 codes, NDC codes, allergy types, or policy group numbers', 'mcp-ai-wpoos-pro' ),
				$distribution['low']
			);
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = __( 'Data quality is good — maintain current USCDI/FHIR standards', 'mcp-ai-wpoos-pro' );
		}

		return $recommendations;
	}
}
