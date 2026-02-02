<?php
/**
 * Tool for providing guided health record creation flow.
 *
 * Analyzes a member's health profile and provides step-by-step guidance
 * on what records should be added next to maintain a comprehensive dataset.
 *
 * @package WP_MCP_AI_Pro
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
		return __( 'Analyzes a member\'s current health profile and provides intelligent, step-by-step guidance on what health records should be added next. Helps users maintain comprehensive and complete health datasets for each member through an agentic flow that identifies gaps and suggests necessary records (medical records, checkups, prescriptions, policies, allergies).', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Optional focus area: "demographics", "policies", "medical_records", "checkups", "prescriptions", "allergies", or "all" (default: all)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'demographics', 'policies', 'medical_records', 'checkups', 'prescriptions', 'allergies', 'all' ),
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

		// Analyze policies.
		if ( 'all' === $focus || 'policies' === $focus ) {
			$policy_gaps = $this->analyze_policies( $member_id, $member_type );
			if ( ! empty( $policy_gaps ) ) {
				$gaps                     = array_merge( $gaps, $policy_gaps );
				$completeness['policies'] = false;
			} else {
				$completeness['policies'] = true;
			}
		}

		// Analyze allergies (high priority).
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

		// Analyze medical records.
		if ( 'all' === $focus || 'medical_records' === $focus ) {
			$record_gaps = $this->analyze_medical_records( $member_id );
			if ( ! empty( $record_gaps ) ) {
				$gaps                            = array_merge( $gaps, $record_gaps );
				$completeness['medical_records'] = false;
			} else {
				$completeness['medical_records'] = true;
			}
		}

		// Analyze checkups.
		if ( 'all' === $focus || 'checkups' === $focus ) {
			$checkup_gaps = $this->analyze_checkups( $member_id, $member_type );
			if ( ! empty( $checkup_gaps ) ) {
				$gaps                     = array_merge( $gaps, $checkup_gaps );
				$completeness['checkups'] = false;
			} else {
				$completeness['checkups'] = true;
			}
		}

		// Analyze prescriptions.
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
	 * Analyze demographics completeness.
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_type Member type.
	 * @return array Gaps found.
	 */
	private function analyze_demographics( $member_id, $member_type ) {
		$gaps = array();

		if ( empty( get_post_meta( $member_id, '_member_date_of_birth', true ) ) ) {
			$gaps[] = __( 'Add date of birth to member profile', 'mcp-ai-wpoos-pro' );
		}

		if ( empty( get_post_meta( $member_id, '_member_gender', true ) ) ) {
			$gaps[] = __( 'Add gender to member profile', 'mcp-ai-wpoos-pro' );
		}

		if ( 'person' === $member_type ) {
			if ( empty( get_post_meta( $member_id, '_member_blood_type', true ) ) ) {
				$gaps[] = __( 'Add blood type information', 'mcp-ai-wpoos-pro' );
			}

			if ( empty( get_post_meta( $member_id, '_member_emergency_contact', true ) ) ) {
				$gaps[] = __( 'Add emergency contact information', 'mcp-ai-wpoos-pro' );
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
	 * Analyze policies.
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
				'posts_per_page' => -1,
			)
		);

		if ( empty( $policies ) ) {
			if ( 'person' === $member_type ) {
				$gaps[] = __( 'Add health insurance policy information', 'mcp-ai-wpoos-pro' );
			} else {
				$gaps[] = __( 'Add pet insurance policy information', 'mcp-ai-wpoos-pro' );
			}
		}

		return $gaps;
	}

	/**
	 * Analyze allergies (high priority).
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
				'posts_per_page' => 1,
			)
		);

		if ( empty( $allergies ) ) {
			$gaps[] = __( 'PRIORITY: Document any known allergies (or explicitly note "None known")', 'mcp-ai-wpoos-pro' );
		}

		return $gaps;
	}

	/**
	 * Analyze medical records.
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
				'posts_per_page' => 1,
			)
		);

		if ( empty( $records ) ) {
			$gaps[] = __( 'Add initial medical history and records', 'mcp-ai-wpoos-pro' );
		}

		return $gaps;
	}

	/**
	 * Analyze checkups.
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
			)
		);

		if ( empty( $upcoming_checkups ) ) {
			$gaps[] = 'person' === $member_type
				? __( 'Schedule upcoming health checkup/wellness visit', 'mcp-ai-wpoos-pro' )
				: __( 'Schedule upcoming veterinary checkup', 'mcp-ai-wpoos-pro' );
		}

		return $gaps;
	}

	/**
	 * Analyze prescriptions.
	 *
	 * Note: We don't flag missing prescriptions as a gap since not everyone needs them.
	 * This method is reserved for future enhancements such as checking for
	 * medication adherence, refill reminders, or interaction warnings.
	 *
	 * @param int $member_id Member ID.
	 * @return array Gaps found (currently always empty).
	 */
	private function analyze_prescriptions( $member_id ) {
		// Future enhancements could include:
		// - Checking for expired prescriptions that need renewal.
		// - Identifying prescriptions without dosage information.
		// - Flagging potential drug interactions.
		return array();
	}

	/**
	 * Generate comprehensive guidance message.
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
			$guidance .= __( 'Priority Actions (High Importance):', 'mcp-ai-wpoos-pro' );
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

		$guidance .= "\n";
		$guidance .= __( 'How I Can Help:', 'mcp-ai-wpoos-pro' );
		$guidance .= "\n";
		$guidance .= __( '• I can help you create any of these records step-by-step', 'mcp-ai-wpoos-pro' );
		$guidance .= "\n";
		$guidance .= __( '• I can guide you through gathering necessary information', 'mcp-ai-wpoos-pro' );
		$guidance .= "\n";
		$guidance .= __( '• I can schedule checkups and manage prescriptions', 'mcp-ai-wpoos-pro' );
		$guidance .= "\n";
		$guidance .= __( '• Just let me know which record you\'d like to add first!', 'mcp-ai-wpoos-pro' );

		return $guidance;
	}
}
