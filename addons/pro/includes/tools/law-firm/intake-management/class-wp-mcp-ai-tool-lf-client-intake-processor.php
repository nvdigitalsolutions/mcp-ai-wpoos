<?php
/**
 * Client Intake Processor Tool
 *
 * Processes new client intake forms and creates client records in the system.
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
 * Processes client intake data and creates client records.
 */
class WP_MCP_AI_Tool_LF_Client_Intake_Processor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Legal disclaimer constant.
	 *
	 * @var string
	 */
	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

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
		return 'lf_client_intake_processor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Client Intake Processor', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Processes new client intake forms and creates client records with contact information, practice area, case description, urgency level, and referral source.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'client_name'      => array(
					'type'        => 'string',
					'description' => __( 'Full name of the client.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'email'            => array(
					'type'        => 'string',
					'description' => __( 'Client email address.', 'mcp-ai-wpoos-pro' ),
				),
				'phone'            => array(
					'type'        => 'string',
					'description' => __( 'Client phone number.', 'mcp-ai-wpoos-pro' ),
				),
				'practice_area'    => array(
					'type'        => 'string',
					'description' => __( 'Area of law for the matter.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array(
						'litigation',
						'corporate',
						'real_estate',
						'family',
						'criminal',
						'ip',
						'immigration',
						'bankruptcy',
						'tax',
						'employment',
						'estate_planning',
					),
				),
				'case_description' => array(
					'type'        => 'string',
					'description' => __( 'Description of the client case or legal matter.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'urgency'          => array(
					'type'        => 'string',
					'description' => __( 'Urgency level of the matter.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'low', 'medium', 'high', 'critical' ),
				),
				'referral_source'  => array(
					'type'        => 'string',
					'description' => __( 'How the client was referred.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'client_name', 'case_description' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$client_name      = isset( $arguments['client_name'] ) ? sanitize_text_field( $arguments['client_name'] ) : '';
		$email            = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';
		$phone            = isset( $arguments['phone'] ) ? sanitize_text_field( $arguments['phone'] ) : '';
		$practice_area    = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';
		$case_description = isset( $arguments['case_description'] ) ? sanitize_textarea_field( $arguments['case_description'] ) : '';
		$urgency          = isset( $arguments['urgency'] ) ? sanitize_text_field( $arguments['urgency'] ) : 'medium';
		$referral_source  = isset( $arguments['referral_source'] ) ? sanitize_text_field( $arguments['referral_source'] ) : '';

		if ( empty( $client_name ) || empty( $case_description ) ) {
			return new WP_Error( 'missing_required', __( 'Client name and case description are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_urgencies = array( 'low', 'medium', 'high', 'critical' );
		if ( ! in_array( $urgency, $valid_urgencies, true ) ) {
			$urgency = 'medium';
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_lf_client',
				'post_title'   => $client_name,
				'post_content' => $case_description,
				'post_status'  => 'publish',
				'post_author'  => $uid,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $email ) {
			update_post_meta( $post_id, '_lf_email', $email );
		}
		if ( $phone ) {
			update_post_meta( $post_id, '_lf_phone', $phone );
		}
		if ( $practice_area ) {
			update_post_meta( $post_id, '_lf_practice_area', $practice_area );
		}
		update_post_meta( $post_id, '_lf_urgency', $urgency );
		if ( $referral_source ) {
			update_post_meta( $post_id, '_lf_referral_source', $referral_source );
		}
		update_post_meta( $post_id, '_lf_intake_date', current_time( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, '_lf_status', 'new' );

		return array(
			'success'    => true,
			'message'    => __( 'Client intake record created successfully. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'client_id'     => $post_id,
				'client_name'   => $client_name,
				'email'         => $email,
				'phone'         => $phone,
				'practice_area' => $practice_area,
				'urgency'       => $urgency,
				'intake_date'   => current_time( 'Y-m-d H:i:s' ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
