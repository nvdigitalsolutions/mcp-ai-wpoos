<?php
/**
 * Bar Deadline Monitor Tool
 *
 * Monitors bar association reporting deadlines and CLE requirements by state.
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
 * Monitors bar association reporting deadlines and CLE requirements.
 */
class WP_MCP_AI_Tool_LF_Bar_Deadline_Monitor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * State bar deadline data.
	 *
	 * @var array
	 */
	private static $state_bar_data = array(
		'CA' => array(
			'name'             => 'California',
			'bar_name'         => 'State Bar of California',
			'cle_hours'        => 25,
			'cle_period'       => '3 years',
			'ethics_hours'     => 4,
			'competence_hours' => 1,
			'renewal_month'    => 2,
			'deadlines'        => array(
				array(
					'type'        => 'cle_compliance',
					'description' => 'CLE compliance reporting',
					'frequency'   => 'Every 3 years on birth month anniversary',
				),
				array(
					'type'        => 'dues',
					'description' => 'Annual membership dues',
					'frequency'   => 'February 1',
				),
				array(
					'type'        => 'trust_account',
					'description' => 'Client trust account reporting (IOLTA)',
					'frequency'   => 'Annual with dues',
				),
			),
		),
		'NY' => array(
			'name'             => 'New York',
			'bar_name'         => 'New York State Bar Association',
			'cle_hours'        => 24,
			'cle_period'       => '2 years',
			'ethics_hours'     => 4,
			'competence_hours' => 1,
			'renewal_month'    => 7,
			'deadlines'        => array(
				array(
					'type'        => 'cle_compliance',
					'description' => 'Biennial CLE compliance',
					'frequency'   => 'Every 2 years based on birth month',
				),
				array(
					'type'        => 'registration',
					'description' => 'Biennial attorney registration',
					'frequency'   => 'Every 2 years by July 1',
				),
				array(
					'type'        => 'pro_bono',
					'description' => 'Pro bono reporting (voluntary)',
					'frequency'   => 'Annual',
				),
			),
		),
		'TX' => array(
			'name'             => 'Texas',
			'bar_name'         => 'State Bar of Texas',
			'cle_hours'        => 15,
			'cle_period'       => '1 year',
			'ethics_hours'     => 3,
			'competence_hours' => 0,
			'renewal_month'    => 6,
			'deadlines'        => array(
				array(
					'type'        => 'cle_compliance',
					'description' => 'Annual MCLE compliance',
					'frequency'   => 'Annually based on last name grouping',
				),
				array(
					'type'        => 'dues',
					'description' => 'Annual membership dues',
					'frequency'   => 'June 1',
				),
				array(
					'type'        => 'trust_account',
					'description' => 'IOLTA compliance certificate',
					'frequency'   => 'Annual with dues',
				),
			),
		),
		'FL' => array(
			'name'             => 'Florida',
			'bar_name'         => 'The Florida Bar',
			'cle_hours'        => 33,
			'cle_period'       => '3 years',
			'ethics_hours'     => 5,
			'competence_hours' => 3,
			'renewal_month'    => 1,
			'deadlines'        => array(
				array(
					'type'        => 'cle_compliance',
					'description' => 'CLE compliance reporting',
					'frequency'   => 'Every 3 years ending January 31',
				),
				array(
					'type'        => 'dues',
					'description' => 'Annual membership fees',
					'frequency'   => 'Varies by division',
				),
				array(
					'type'        => 'trust_account',
					'description' => 'Trust account compliance',
					'frequency'   => 'Annual certification',
				),
			),
		),
		'IL' => array(
			'name'             => 'Illinois',
			'bar_name'         => 'Attorney Registration and Disciplinary Commission',
			'cle_hours'        => 30,
			'cle_period'       => '2 years',
			'ethics_hours'     => 6,
			'competence_hours' => 0,
			'renewal_month'    => 1,
			'deadlines'        => array(
				array(
					'type'        => 'cle_compliance',
					'description' => 'Biennial MCLE compliance',
					'frequency'   => 'Every 2 years ending June 30',
				),
				array(
					'type'        => 'registration',
					'description' => 'Annual attorney registration',
					'frequency'   => 'Annual based on last name',
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
		return 'lf_bar_deadline_monitor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Bar Deadline Monitor', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Monitors bar association reporting deadlines, CLE requirements, and membership renewal dates by state for attorneys.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'state'       => array(
					'type'        => 'string',
					'description' => __( 'Two-letter state abbreviation (e.g., "CA", "NY", "TX").', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 2,
					'maxLength'   => 2,
				),
				'attorney_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the attorney to check deadlines for.', 'mcp-ai-wpoos-pro' ),
				),
				'include_cle' => array(
					'type'        => 'boolean',
					'description' => __( 'Include CLE requirement details in the response.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'state' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' );
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

		$state       = isset( $arguments['state'] ) ? strtoupper( sanitize_text_field( $arguments['state'] ) ) : '';
		$attorney_id = isset( $arguments['attorney_id'] ) ? absint( $arguments['attorney_id'] ) : 0;
		$include_cle = isset( $arguments['include_cle'] ) ? (bool) $arguments['include_cle'] : true;

		if ( empty( $state ) ) {
			return new WP_Error( 'missing_required', __( 'State abbreviation is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$state_data = isset( self::$state_bar_data[ $state ] ) ? self::$state_bar_data[ $state ] : null;

		if ( ! $state_data ) {
			return array(
				'success'    => true,
				'message'    => sprintf(
					/* translators: %s: state abbreviation */
					__( 'Detailed data for %s is not yet available. General deadlines provided.', 'mcp-ai-wpoos-pro' ),
					$state
				) . ' ' . self::DISCLAIMER,
				'data'       => array(
					'state'             => $state,
					'data_available'    => false,
					'general_deadlines' => array(
						__( 'Annual bar membership dues — check your state bar website.', 'mcp-ai-wpoos-pro' ),
						__( 'CLE compliance reporting — varies by state.', 'mcp-ai-wpoos-pro' ),
						__( 'Trust account/IOLTA reporting — typically annual.', 'mcp-ai-wpoos-pro' ),
						__( 'Attorney registration — varies by state.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'disclaimer' => self::DISCLAIMER,
			);
		}

		$deadlines          = $state_data['deadlines'];
		$attorney_deadlines = array();

		// If an attorney ID is provided, check their stored deadline data.
		if ( $attorney_id > 0 ) {
			$attorney_user = get_userdata( $attorney_id );
			if ( $attorney_user ) {
				$bar_admission_date = get_user_meta( $attorney_id, '_lf_bar_admission_date', true );
				$last_cle_report    = get_user_meta( $attorney_id, '_lf_last_cle_report', true );
				$bar_number         = get_user_meta( $attorney_id, '_lf_bar_number', true );

				$attorney_deadlines = array(
					'attorney_name'      => $attorney_user->display_name,
					'bar_number'         => $bar_number ? $bar_number : __( 'Not recorded', 'mcp-ai-wpoos-pro' ),
					'bar_admission_date' => $bar_admission_date ? $bar_admission_date : __( 'Not recorded', 'mcp-ai-wpoos-pro' ),
					'last_cle_report'    => $last_cle_report ? $last_cle_report : __( 'Not recorded', 'mcp-ai-wpoos-pro' ),
				);

				// Calculate next CLE deadline.
				if ( $last_cle_report ) {
					$period_years = (int) $state_data['cle_period'];
					$next_cle     = gmdate( 'Y-m-d', strtotime( $last_cle_report . ' + ' . $period_years . ' years' ) );

					$attorney_deadlines['next_cle_deadline'] = $next_cle;
					$days_until                              = ( strtotime( $next_cle ) - time() ) / DAY_IN_SECONDS;

					if ( $days_until < 0 ) {
						$attorney_deadlines['cle_status'] = 'overdue';
					} elseif ( $days_until < 90 ) {
						$attorney_deadlines['cle_status'] = 'urgent';
					} elseif ( $days_until < 180 ) {
						$attorney_deadlines['cle_status'] = 'approaching';
					} else {
						$attorney_deadlines['cle_status'] = 'on_track';
					}
				}
			}
		}

		$response_data = array(
			'state'          => $state,
			'state_name'     => $state_data['name'],
			'bar_name'       => $state_data['bar_name'],
			'data_available' => true,
			'deadlines'      => $deadlines,
		);

		if ( $include_cle ) {
			$response_data['cle_requirements'] = array(
				'total_hours'      => $state_data['cle_hours'],
				'period'           => $state_data['cle_period'],
				'ethics_hours'     => $state_data['ethics_hours'],
				'competence_hours' => $state_data['competence_hours'],
			);
		}

		if ( ! empty( $attorney_deadlines ) ) {
			$response_data['attorney_details'] = $attorney_deadlines;
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: state name, 2: number of deadlines */
				__( 'Retrieved %1$s bar deadlines: %2$d deadline categories found.', 'mcp-ai-wpoos-pro' ),
				$state_data['name'],
				count( $deadlines )
			) . ' ' . self::DISCLAIMER,
			'data'       => $response_data,
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
