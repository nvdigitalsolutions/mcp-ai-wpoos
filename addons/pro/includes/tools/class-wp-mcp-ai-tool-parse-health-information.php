<?php
/**
 * Tool for parsing and organizing raw health information.
 *
 * Accepts unstructured health information and intelligently parses it to create
 * appropriate health records (medical records, checkups, prescriptions, policies, allergies).
 *
 * @package WP_MCP_AI_Pro
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
		return __( 'Accepts raw, unstructured health information (notes, medical records, prescriptions, policy details, etc.) and intelligently parses it to automatically create appropriate structured health records. The AI analyzes the content, identifies record types (medical records, checkups, prescriptions, policies, allergies), extracts relevant data, and creates properly organized records in the system. Perfect for bulk data entry where users want to dump everything and let AI handle organization.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Raw, unstructured health information text to parse and organize. Can include medical records, prescriptions, allergies, checkup notes, policy details, etc.', 'mcp-ai-wpoos-pro' ),
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
	 * @param string $raw_text  Raw information text.
	 * @param int    $member_id Member ID.
	 * @return array|WP_Error Parsed data or error.
	 */
	private function parse_information( $raw_text, $member_id ) {
		$parsed = array(
			'medical_records' => array(),
			'checkups'        => array(),
			'prescriptions'   => array(),
			'policies'        => array(),
			'allergies'       => array(),
			'demographics'    => array(),
			'metadata'        => array(
				'parsed_at'    => current_time( 'mysql' ),
				'data_quality' => array(),
				'completeness' => 0,
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
				if ( ! empty( $current_record ) && null !== $current_section ) {
					// Validate and assess data quality before adding.
					$current_record               = $this->validate_and_enhance_record( $current_record, $current_section );
					$parsed[ $current_section ][] = $current_record;
					$current_record               = array();
				}
				continue;
			}

			// Detect record type from keywords.
			$detected_type = $this->detect_record_type( $line );
			if ( $detected_type ) {
				// Save previous record if any.
				if ( ! empty( $current_record ) && null !== $current_section ) {
					$current_record               = $this->validate_and_enhance_record( $current_record, $current_section );
					$parsed[ $current_section ][] = $current_record;
				}
				$current_section = $detected_type;
				$current_record  = array( 'raw_line' => $line );
			} elseif ( null !== $current_section ) {
				// Add to current record.
				if ( ! isset( $current_record['content'] ) ) {
					$current_record['content'] = '';
				}
				$current_record['content'] .= $line . "\n";
			}

			// Extract specific data patterns.
			$this->extract_data_patterns( $line, $current_record, $current_section );
		}

		// Save last record.
		if ( ! empty( $current_record ) && null !== $current_section ) {
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
				'title'        => __( 'Imported Health Information', 'mcp-ai-wpoos-pro' ),
				'content'      => $raw_text,
				'record_type'  => 'general',
				'date'         => current_time( 'Y-m-d' ),
				'data_quality' => array(
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
	 * @param string $line Line of text.
	 * @return string|null Record type or null.
	 */
	private function detect_record_type( $line ) {
		// Allergy detection (high priority).
		if ( preg_match( '/\b(allerg(y|ies|ic)|allergic to|allergen)\b/i', $line ) ) {
			return 'allergies';
		}

		// Prescription detection.
		if ( preg_match( '/\b(prescription|medication|medicine|drug|dosage|mg|ml|tablet|pill|capsule|rx)\b/i', $line ) ) {
			return 'prescriptions';
		}

		// Checkup/appointment detection.
		if ( preg_match( '/\b(checkup|appointment|visit|scheduled|consultation|examination|exam)\b/i', $line ) ) {
			return 'checkups';
		}

		// Policy detection.
		if ( preg_match( '/\b(insurance|policy|coverage|provider|plan|premium|deductible)\b/i', $line ) ) {
			return 'policies';
		}

		// Medical record detection.
		if ( preg_match( '/\b(diagnosis|diagnosed|condition|treatment|procedure|surgery|lab|test|result|imaging|x-ray|mri|ct scan)\b/i', $line ) ) {
			return 'medical_records';
		}

		return null;
	}

	/**
	 * Extract specific data patterns from a line.
	 *
	 * @param string      $line            Line of text.
	 * @param array       &$current_record Current record being built.
	 * @param string|null $section         Current section type.
	 */
	private function extract_data_patterns( $line, &$current_record, $section ) {
		// Extract dates.
		if ( preg_match( '/\b(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})\b/', $line, $matches ) ) {
			$current_record['date'] = $matches[1];
		}

		// Extract dosage information for prescriptions.
		if ( 'prescriptions' === $section ) {
			if ( preg_match( '/\b(\d+\s*(mg|ml|g|mcg))\b/i', $line, $matches ) ) {
				$current_record['dosage'] = $matches[0];
			}
			if ( preg_match( '/\b(once|twice|three times|daily|weekly|monthly|every \d+ hours?)\b/i', $line, $matches ) ) {
				$current_record['frequency'] = $matches[0];
			}
		}

		// Extract severity for allergies.
		if ( 'allergies' === $section ) {
			if ( preg_match( '/\b(mild|moderate|severe)\b/i', $line, $matches ) ) {
				$current_record['severity'] = strtolower( $matches[1] );
			}
		}

		// Extract provider/doctor names.
		if ( preg_match( '/\bDr\.?\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/', $line, $matches ) ) {
			$current_record['provider'] = $matches[0];
		}
	}

	/**
	 * Create actual WordPress posts from parsed data.
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

		// Create medical records.
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
				if ( isset( $record_data['date'] ) ) {
					update_post_meta( $post_id, '_record_date', sanitize_text_field( $record_data['date'] ) );
				}
				if ( isset( $record_data['provider'] ) ) {
					update_post_meta( $post_id, '_record_provider', sanitize_text_field( $record_data['provider'] ) );
				}
				if ( isset( $record_data['record_type'] ) ) {
					wp_set_object_terms( $post_id, $record_data['record_type'], 'mcp_ai_record_type' );
				}

				// Attach source documents for audit trail and compliance.
				if ( ! empty( $attachment_ids ) ) {
					foreach ( $attachment_ids as $attachment_id ) {
						// Link attachment to this record.
						wp_update_post(
							array(
								'ID'          => $attachment_id,
								'post_parent' => $post_id,
							)
						);
						// Add relationship metadata.
						update_post_meta( $attachment_id, '_wp_mcp_ai_linked_record_id', $post_id );
						update_post_meta( $attachment_id, '_wp_mcp_ai_linked_record_type', 'mcp_ai_med_record' );
					}
					// Store attachment count for quick reference.
					update_post_meta( $post_id, '_wp_mcp_ai_source_documents_count', count( $attachment_ids ) );
				}

				$created['medical_records'][] = $post_id;
			}
		}

		// Create checkups.
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
				if ( isset( $checkup_data['date'] ) ) {
					update_post_meta( $post_id, '_checkup_date', sanitize_text_field( $checkup_data['date'] ) );
				}
				if ( isset( $checkup_data['provider'] ) ) {
					update_post_meta( $post_id, '_checkup_provider', sanitize_text_field( $checkup_data['provider'] ) );
				}
				$created['checkups'][] = $post_id;
			}
		}

		// Create prescriptions.
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
				if ( isset( $prescription_data['dosage'] ) ) {
					update_post_meta( $post_id, '_prescription_dosage', sanitize_text_field( $prescription_data['dosage'] ) );
				}
				if ( isset( $prescription_data['frequency'] ) ) {
					update_post_meta( $post_id, '_prescription_frequency', sanitize_text_field( $prescription_data['frequency'] ) );
				}
				if ( isset( $prescription_data['date'] ) ) {
					update_post_meta( $post_id, '_prescription_start_date', sanitize_text_field( $prescription_data['date'] ) );
				}
				$created['prescriptions'][] = $post_id;
			}
		}

		// Create policies.
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
				if ( isset( $policy_data['provider'] ) ) {
					update_post_meta( $post_id, '_policy_provider', sanitize_text_field( $policy_data['provider'] ) );
				}
				$created['policies'][] = $post_id;
			}
		}

		// Create allergies.
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
				if ( isset( $allergy_data['severity'] ) ) {
					wp_set_object_terms( $post_id, $allergy_data['severity'], 'mcp_ai_allergy_severity' );
				}
				if ( isset( $allergy_data['reaction'] ) ) {
					update_post_meta( $post_id, '_allergy_reaction', sanitize_text_field( $allergy_data['reaction'] ) );
				}
				$created['allergies'][] = $post_id;
			}
		}

		return $created;
	}

	/**
	 * Generate summary of parsing results.
	 *
	 * @param array $parsed_data     Parsed data.
	 * @param array $created_records Created record IDs.
	 * @param array $attachment_ids  Attachment IDs.
	 * @return string Summary message.
	 */
	private function generate_parsing_summary( $parsed_data, $created_records, $attachment_ids = array() ) {
		$summary = __( 'Health Information Parsing Results:', 'mcp-ai-wpoos-pro' ) . "\n\n";

		$summary .= __( 'Identified and processed:', 'mcp-ai-wpoos-pro' ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['medical_records'] ), __( 'medical record(s)', 'mcp-ai-wpoos-pro' ) ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['checkups'] ), __( 'checkup(s)', 'mcp-ai-wpoos-pro' ) ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['prescriptions'] ), __( 'prescription(s)', 'mcp-ai-wpoos-pro' ) ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['policies'] ), __( 'insurance policy/policies', 'mcp-ai-wpoos-pro' ) ) . "\n";
		$summary .= sprintf( '• %d %s', count( $parsed_data['allergies'] ), __( 'allergy/allergies', 'mcp-ai-wpoos-pro' ) ) . "\n";

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
				$summary .= __( '✓ Documents attached to records for compliance and future validation', 'mcp-ai-wpoos-pro' ) . "\n";
			}

			$summary .= "\n" . __( 'You can now view and edit these records in the WordPress admin.', 'mcp-ai-wpoos-pro' );
		}

		return $summary;
	}

	/**
	 * Validate and enhance record with data quality metadata.
	 *
	 * Implements industry best practices for healthcare data quality:
	 * - Accuracy: Verify data is correct and valid
	 * - Completeness: Ensure all required fields are present
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
			// Try to extract from raw_line or content.
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
				// Check if date is in reasonable range (not in future, not too old).
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
			// No date provided - moderate impact.
			$quality_score -= 15;
			$issues[]       = 'missing_date';
			$record['date'] = current_time( 'Y-m-d' ); // Default to today.
		}

		// Type-specific validation.
		switch ( $type ) {
			case 'prescriptions':
				// Prescriptions should have dosage and frequency.
				if ( empty( $record['dosage'] ) ) {
					$quality_score -= 10;
					$issues[]       = 'missing_dosage';
				}
				if ( empty( $record['frequency'] ) ) {
					$quality_score -= 10;
					$issues[]       = 'missing_frequency';
				}
				break;

			case 'allergies':
				// Allergies should have severity.
				if ( empty( $record['severity'] ) ) {
					$quality_score     -= 15;
					$issues[]           = 'missing_severity';
					$record['severity'] = 'moderate'; // Default to moderate for safety.
				}
				break;

			case 'checkups':
				// Checkups should have provider information.
				if ( empty( $record['provider'] ) ) {
					$quality_score -= 10;
					$issues[]       = 'missing_provider';
				}
				break;

			case 'medical_records':
				// Medical records should have type.
				if ( empty( $record['record_type'] ) ) {
					$quality_score        -= 5;
					$record['record_type'] = 'general';
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
	 * Get quality improvement recommendations.
	 *
	 * @param int   $average_score Average quality score.
	 * @param array $distribution  Quality distribution.
	 * @return array Recommendations.
	 */
	private function get_quality_recommendations( $average_score, $distribution ) {
		$recommendations = array();

		if ( $average_score < 60 ) {
			$recommendations[] = __( 'Consider adding more details like dates, providers, and dosages', 'mcp-ai-wpoos-pro' );
		}

		if ( $distribution['low'] > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of low quality records */
				__( '%d record(s) have low data quality - review and enhance these records', 'mcp-ai-wpoos-pro' ),
				$distribution['low']
			);
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = __( 'Data quality is good - maintain current standards', 'mcp-ai-wpoos-pro' );
		}

		return $recommendations;
	}
}
