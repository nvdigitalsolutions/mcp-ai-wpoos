<?php
/**
 * Data Privacy Compliance Checker Tool
 *
 * Checks data privacy regulation compliance based on data types and client location.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks data privacy regulation compliance for law firm client data handling.
 */
class WP_MCP_AI_Tool_LF_Data_Privacy_Compliance_Checker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Regulation database mapping data types to applicable regulations.
	 *
	 * @var array
	 */
	private static $regulation_map = array(
		'personal_info' => array(
			array(
				'regulation'   => 'GDPR',
				'regions'      => array( 'EU', 'EEA', 'UK' ),
				'requirements' => array(
					'Lawful basis for processing required (Article 6)',
					'Privacy notice must be provided (Articles 13-14)',
					'Data subject access rights must be facilitated (Articles 15-22)',
					'Data protection impact assessment may be required (Article 35)',
					'Records of processing activities required (Article 30)',
				),
			),
			array(
				'regulation'   => 'CCPA/CPRA',
				'regions'      => array( 'California', 'CA' ),
				'requirements' => array(
					'Notice at collection required',
					'Right to know, delete, and opt-out must be supported',
					'Do not sell/share personal information provisions',
					'Service provider agreements required for third-party sharing',
				),
			),
			array(
				'regulation'   => 'ABA Model Rule 1.6',
				'regions'      => array(),
				'requirements' => array(
					'Reasonable measures to prevent unauthorized access to client information',
					'Competent technology safeguards required (Comment 18)',
					'Duty extends to electronic communications and storage',
				),
			),
		),
		'financial'     => array(
			array(
				'regulation'   => 'GLBA',
				'regions'      => array( 'US' ),
				'requirements' => array(
					'Safeguards Rule compliance for financial information',
					'Privacy notice requirements for financial data',
					'Restrictions on sharing with non-affiliated third parties',
				),
			),
			array(
				'regulation'   => 'PCI-DSS',
				'regions'      => array(),
				'requirements' => array(
					'Payment card data must be encrypted in transit and at rest',
					'Access controls for cardholder data',
					'Regular security assessments required',
				),
			),
		),
		'health'        => array(
			array(
				'regulation'   => 'HIPAA',
				'regions'      => array( 'US' ),
				'requirements' => array(
					'Business Associate Agreement may be required',
					'Minimum necessary standard for PHI access',
					'Breach notification requirements (60-day rule)',
					'Administrative, physical, and technical safeguards required',
					'Patient authorization for non-treatment disclosures',
				),
			),
			array(
				'regulation'   => 'GDPR Article 9',
				'regions'      => array( 'EU', 'EEA', 'UK' ),
				'requirements' => array(
					'Special category data — explicit consent or legal obligation basis required',
					'Enhanced data protection measures mandatory',
					'Data Protection Impact Assessment required',
				),
			),
		),
		'minor'         => array(
			array(
				'regulation'   => 'COPPA',
				'regions'      => array( 'US' ),
				'requirements' => array(
					'Verifiable parental consent required for children under 13',
					'Clear privacy policy addressing children\'s data',
					'Parental review and deletion rights',
					'Data minimization for children\'s information',
				),
			),
			array(
				'regulation'   => 'GDPR Article 8',
				'regions'      => array( 'EU', 'EEA', 'UK' ),
				'requirements' => array(
					'Parental consent required for children under 16 (may vary by member state)',
					'Age verification measures required',
					'Child-friendly privacy notices',
				),
			),
			array(
				'regulation'   => 'CIPA',
				'regions'      => array( 'US' ),
				'requirements' => array(
					'Internet safety policies for minors',
					'Content filtering considerations',
				),
			),
		),
		'biometric'     => array(
			array(
				'regulation'   => 'BIPA',
				'regions'      => array( 'Illinois', 'IL' ),
				'requirements' => array(
					'Written informed consent before collection',
					'Published retention and destruction schedule',
					'Prohibition on selling or profiting from biometric data',
					'Private right of action for violations',
				),
			),
			array(
				'regulation'   => 'GDPR Article 9',
				'regions'      => array( 'EU', 'EEA', 'UK' ),
				'requirements' => array(
					'Biometric data classified as special category data',
					'Explicit consent or substantial public interest basis required',
					'Data Protection Impact Assessment required',
				),
			),
			array(
				'regulation'   => 'CCPA/CPRA',
				'regions'      => array( 'California', 'CA' ),
				'requirements' => array(
					'Biometric data classified as sensitive personal information',
					'Right to limit use of sensitive personal information',
					'Enhanced notice requirements',
				),
			),
		),
	);

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_data_privacy_compliance_checker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Data Privacy Compliance Checker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Analyzes data types being processed against applicable privacy regulations based on client location and data categories, returning compliance requirements and risk areas.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'data_types'         => array(
					'type'        => 'array',
					'description' => __( 'Types of data being processed or stored.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'personal_info', 'financial', 'health', 'minor', 'biometric' ),
					),
				),
				'client_location'    => array(
					'type'        => 'string',
					'description' => __( 'Client or data subject location (e.g., "California", "EU", "New York").', 'mcp-ai-wpoos-pro' ),
				),
				'processing_purpose' => array(
					'type'        => 'string',
					'description' => __( 'Purpose for processing the data (e.g., "litigation support", "contract review").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'data_types' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$data_types = array();
		if ( ! empty( $arguments['data_types'] ) && is_array( $arguments['data_types'] ) ) {
			$valid_types = array( 'personal_info', 'financial', 'health', 'minor', 'biometric' );
			foreach ( $arguments['data_types'] as $type ) {
				$sanitized = sanitize_text_field( $type );
				if ( in_array( $sanitized, $valid_types, true ) ) {
					$data_types[] = $sanitized;
				}
			}
		}

		if ( empty( $data_types ) ) {
			return new WP_Error( 'missing_required', __( 'At least one valid data type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$client_location    = isset( $arguments['client_location'] ) ? sanitize_text_field( $arguments['client_location'] ) : '';
		$processing_purpose = isset( $arguments['processing_purpose'] ) ? sanitize_text_field( $arguments['processing_purpose'] ) : '';
		$location_upper     = strtoupper( $client_location );

		$applicable_regulations  = array();
		$compliance_requirements = array();
		$risk_areas              = array();
		$seen_regulations        = array();

		foreach ( $data_types as $data_type ) {
			if ( ! isset( self::$regulation_map[ $data_type ] ) ) {
				continue;
			}

			foreach ( self::$regulation_map[ $data_type ] as $reg ) {
				$applies = false;

				// Universal regulations (empty regions) always apply.
				if ( empty( $reg['regions'] ) ) {
					$applies = true;
				} elseif ( ! empty( $client_location ) ) {
					foreach ( $reg['regions'] as $region ) {
						if ( strtoupper( $region ) === $location_upper || false !== stripos( $client_location, $region ) ) {
							$applies = true;
							break;
						}
					}
				} else {
					// No location specified — include all for safety.
					$applies = true;
				}

				if ( ! $applies ) {
					continue;
				}

				$reg_key = $reg['regulation'] . '_' . $data_type;
				if ( isset( $seen_regulations[ $reg_key ] ) ) {
					continue;
				}
				$seen_regulations[ $reg_key ] = true;

				$applicable_regulations[] = array(
					'regulation'   => $reg['regulation'],
					'data_type'    => $data_type,
					'requirements' => $reg['requirements'],
				);

				foreach ( $reg['requirements'] as $req ) {
					$compliance_requirements[] = array(
						'regulation'  => $reg['regulation'],
						'data_type'   => $data_type,
						'requirement' => $req,
					);
				}
			}
		}

		// Assess risk areas.
		$sensitive_types = array( 'health', 'minor', 'biometric' );
		foreach ( $data_types as $type ) {
			if ( in_array( $type, $sensitive_types, true ) ) {
				$risk_areas[] = array(
					'area'        => sprintf(
						/* translators: %s: data type */
						__( 'Sensitive data handling: %s', 'mcp-ai-wpoos-pro' ),
						$type
					),
					'risk_level'  => 'high',
					'description' => __( 'This data type requires enhanced protection measures and may trigger additional regulatory obligations.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		if ( count( $data_types ) > 2 ) {
			$risk_areas[] = array(
				'area'        => __( 'Multiple data type processing', 'mcp-ai-wpoos-pro' ),
				'risk_level'  => 'medium',
				'description' => __( 'Processing multiple data types increases compliance complexity and the number of applicable regulations.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( empty( $client_location ) ) {
			$risk_areas[] = array(
				'area'        => __( 'Unknown client location', 'mcp-ai-wpoos-pro' ),
				'risk_level'  => 'medium',
				'description' => __( 'Without a known location, all potentially applicable regulations have been included. Determine location to narrow requirements.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: regulation count, 2: requirement count */
				__( 'Found %1$d applicable regulations with %2$d compliance requirements.', 'mcp-ai-wpoos-pro' ),
				count( $applicable_regulations ),
				count( $compliance_requirements )
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'data_types_analyzed'     => $data_types,
				'client_location'         => $client_location,
				'processing_purpose'      => $processing_purpose,
				'applicable_regulations'  => $applicable_regulations,
				'compliance_requirements' => $compliance_requirements,
				'risk_areas'              => $risk_areas,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
