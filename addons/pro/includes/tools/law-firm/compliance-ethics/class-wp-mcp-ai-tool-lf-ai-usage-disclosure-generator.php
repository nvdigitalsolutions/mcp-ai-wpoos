<?php
/**
 * AI Usage Disclosure Generator Tool
 *
 * Generates ABA Formal Opinion 512 compliant AI usage disclosure documents.
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
 * Generates AI usage disclosure text compliant with ABA Formal Opinion 512.
 */
class WP_MCP_AI_Tool_LF_AI_Usage_Disclosure_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

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
		return 'lf_ai_usage_disclosure_generator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'AI Usage Disclosure Generator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates AI usage disclosure text compliant with ABA Formal Opinion 512, covering informed consent, confidentiality, competence, and supervision obligations when AI tools are used in legal practice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Post ID of the legal matter (mcp_ai_lf_matter CPT).', 'mcp-ai-wpoos-pro' ),
				),
				'ai_tools_used'   => array(
					'type'        => 'array',
					'description' => __( 'List of AI tools or systems used in the representation.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'purpose'         => array(
					'type'        => 'string',
					'description' => __( 'Description of the purpose for which AI tools are being used.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'client_name'     => array(
					'type'        => 'string',
					'description' => __( 'Name of the client receiving the disclosure.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'disclosure_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of disclosure to generate.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'initial_engagement', 'matter_specific', 'general_notice' ),
					'default'     => 'matter_specific',
				),
			),
			'required'   => array( 'ai_tools_used', 'purpose', 'client_name' ),
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
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$matter_id       = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$ai_tools_used   = array();
		$purpose         = isset( $arguments['purpose'] ) ? sanitize_textarea_field( $arguments['purpose'] ) : '';
		$client_name     = isset( $arguments['client_name'] ) ? sanitize_text_field( $arguments['client_name'] ) : '';
		$disclosure_type = isset( $arguments['disclosure_type'] ) ? sanitize_text_field( $arguments['disclosure_type'] ) : 'matter_specific';

		if ( ! empty( $arguments['ai_tools_used'] ) && is_array( $arguments['ai_tools_used'] ) ) {
			$ai_tools_used = array_map( 'sanitize_text_field', $arguments['ai_tools_used'] );
		}

		if ( empty( $ai_tools_used ) ) {
			return new WP_Error( 'missing_required', __( 'At least one AI tool must be specified.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $purpose ) ) {
			return new WP_Error( 'missing_required', __( 'Purpose of AI usage is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $client_name ) ) {
			return new WP_Error( 'missing_required', __( 'Client name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_types = array( 'initial_engagement', 'matter_specific', 'general_notice' );
		if ( ! in_array( $disclosure_type, $valid_types, true ) ) {
			$disclosure_type = 'matter_specific';
		}

		// Gather matter details if available.
		$matter_title = '';
		if ( $matter_id ) {
			$matter = get_post( $matter_id );
			if ( $matter && 'mcp_ai_lf_matter' === $matter->post_type ) {
				$matter_title = $matter->post_title;
			}
		}

		// Get firm details from settings.
		$settings  = get_option( 'wp_mcp_ai_settings', array() );
		$firm_name = isset( $settings['law_firm_name'] ) ? $settings['law_firm_name'] : get_bloginfo( 'name' );

		// Get the generating attorney's name.
		$attorney      = get_userdata( $uid );
		$attorney_name = $attorney ? $attorney->display_name : __( 'Attorney', 'mcp-ai-wpoos-pro' );

		$tools_list = implode( ', ', $ai_tools_used );
		$date       = wp_date( get_option( 'date_format' ) );

		// Generate the disclosure text based on type.
		switch ( $disclosure_type ) {
			case 'initial_engagement':
				$disclosure_text = $this->generate_initial_engagement( $client_name, $firm_name, $tools_list, $purpose, $attorney_name, $date );
				break;
			case 'general_notice':
				$disclosure_text = $this->generate_general_notice( $client_name, $firm_name, $tools_list, $purpose, $attorney_name, $date );
				break;
			default:
				$disclosure_text = $this->generate_matter_specific( $client_name, $firm_name, $tools_list, $purpose, $attorney_name, $date, $matter_title );
		}

		// ABA Opinion 512 compliance checklist.
		$compliance_checklist = array(
			array(
				'requirement' => __( 'Informed Consent (Rule 1.4)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Client has been informed about the use of AI tools and the nature of AI-generated work product.', 'mcp-ai-wpoos-pro' ),
				'addressed'   => true,
			),
			array(
				'requirement' => __( 'Confidentiality (Rule 1.6)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Disclosure addresses how client information is protected when using AI tools.', 'mcp-ai-wpoos-pro' ),
				'addressed'   => true,
			),
			array(
				'requirement' => __( 'Competence (Rule 1.1)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Lawyer maintains competence in understanding AI tool capabilities and limitations.', 'mcp-ai-wpoos-pro' ),
				'addressed'   => true,
			),
			array(
				'requirement' => __( 'Supervision (Rule 5.1/5.3)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'All AI-generated work product is reviewed and verified by a licensed attorney.', 'mcp-ai-wpoos-pro' ),
				'addressed'   => true,
			),
			array(
				'requirement' => __( 'Reasonable Fees (Rule 1.5)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'AI usage is reflected in fee arrangements and billing practices.', 'mcp-ai-wpoos-pro' ),
				'addressed'   => true,
			),
			array(
				'requirement' => __( 'Candor (Rule 3.3)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Obligations regarding disclosure of AI usage to tribunals when required.', 'mcp-ai-wpoos-pro' ),
				'addressed'   => 'general_notice' !== $disclosure_type,
			),
		);

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: disclosure type, 2: client name */
				__( 'Generated %1$s AI usage disclosure for %2$s.', 'mcp-ai-wpoos-pro' ),
				str_replace( '_', ' ', $disclosure_type ),
				$client_name
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'disclosure_type'      => $disclosure_type,
				'client_name'          => $client_name,
				'matter_id'            => $matter_id,
				'matter_title'         => $matter_title,
				'ai_tools_used'        => $ai_tools_used,
				'purpose'              => $purpose,
				'disclosure_text'      => $disclosure_text,
				'compliance_checklist' => $compliance_checklist,
				'generated_date'       => $date,
				'generated_by'         => $attorney_name,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Generate initial engagement disclosure text.
	 *
	 * @param string $client_name   Client name.
	 * @param string $firm_name     Firm name.
	 * @param string $tools_list    Comma-separated tool names.
	 * @param string $purpose       Purpose of AI usage.
	 * @param string $attorney_name Attorney name.
	 * @param string $date          Current date.
	 * @return string
	 */
	private function generate_initial_engagement( $client_name, $firm_name, $tools_list, $purpose, $attorney_name, $date ) {
		$lines = array();

		$lines[] = sprintf(
			/* translators: %s: date */
			__( 'AI TECHNOLOGY USAGE DISCLOSURE — Date: %s', 'mcp-ai-wpoos-pro' ),
			$date
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: client name */
			__( 'Dear %s,', 'mcp-ai-wpoos-pro' ),
			$client_name
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: firm name */
			__( 'As part of our commitment to transparency and in accordance with our ethical obligations under the ABA Model Rules of Professional Conduct (as interpreted by ABA Formal Opinion 512), %s wishes to inform you of our use of artificial intelligence technology in legal practice.', 'mcp-ai-wpoos-pro' ),
			$firm_name
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: tools list */
			__( 'AI TOOLS IN USE: Our firm utilizes the following AI-assisted tools: %s.', 'mcp-ai-wpoos-pro' ),
			$tools_list
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: purpose */
			__( 'PURPOSE: These tools are used for: %s.', 'mcp-ai-wpoos-pro' ),
			$purpose
		);
		$lines[] = '';
		$lines[] = __( 'IMPORTANT SAFEGUARDS:', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '1. All AI-generated work product is reviewed and verified by a licensed attorney before use.', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '2. Your confidential information is protected in accordance with our data security policies and Rule 1.6.', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '3. AI tools supplement — but do not replace — the professional judgment of your attorney.', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '4. Our attorneys maintain competence in the AI technologies used (Rule 1.1).', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '5. Any cost savings from AI efficiency are reflected in our billing practices (Rule 1.5).', 'mcp-ai-wpoos-pro' );
		$lines[] = '';
		$lines[] = __( 'You have the right to ask questions about our use of AI technology and to request that AI tools not be used in your matter, though this may affect efficiency and costs.', 'mcp-ai-wpoos-pro' );
		$lines[] = '';
		$lines[] = __( 'By continuing with our engagement, you acknowledge this disclosure. Please contact us with any questions or concerns.', 'mcp-ai-wpoos-pro' );
		$lines[] = '';
		$lines[] = __( 'Sincerely,', 'mcp-ai-wpoos-pro' );
		$lines[] = $attorney_name;
		$lines[] = $firm_name;

		return implode( "\n", $lines );
	}

	/**
	 * Generate matter-specific disclosure text.
	 *
	 * @param string $client_name   Client name.
	 * @param string $firm_name     Firm name.
	 * @param string $tools_list    Comma-separated tool names.
	 * @param string $purpose       Purpose of AI usage.
	 * @param string $attorney_name Attorney name.
	 * @param string $date          Current date.
	 * @param string $matter_title  Matter title.
	 * @return string
	 */
	private function generate_matter_specific( $client_name, $firm_name, $tools_list, $purpose, $attorney_name, $date, $matter_title ) {
		$lines = array();

		$lines[] = sprintf(
			/* translators: %s: date */
			__( 'MATTER-SPECIFIC AI USAGE DISCLOSURE — Date: %s', 'mcp-ai-wpoos-pro' ),
			$date
		);
		$lines[] = '';

		if ( $matter_title ) {
			$lines[] = sprintf(
				/* translators: %s: matter title */
				__( 'Re: %s', 'mcp-ai-wpoos-pro' ),
				$matter_title
			);
			$lines[] = '';
		}

		$lines[] = sprintf(
			/* translators: %s: client name */
			__( 'Dear %s,', 'mcp-ai-wpoos-pro' ),
			$client_name
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: tools list */
			__( 'In connection with the above-referenced matter, we wish to disclose that the following AI tools have been or will be utilized: %s.', 'mcp-ai-wpoos-pro' ),
			$tools_list
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: purpose */
			__( 'SPECIFIC USE: %s.', 'mcp-ai-wpoos-pro' ),
			$purpose
		);
		$lines[] = '';
		$lines[] = __( 'ATTORNEY OVERSIGHT: All AI-assisted work product in this matter has been or will be independently reviewed, verified, and approved by a licensed attorney before being relied upon or submitted.', 'mcp-ai-wpoos-pro' );
		$lines[] = '';
		$lines[] = __( 'DATA PROTECTION: Client information processed through AI tools is handled in accordance with our confidentiality obligations under ABA Model Rule 1.6, including reasonable measures to prevent unauthorized disclosure.', 'mcp-ai-wpoos-pro' );
		$lines[] = '';
		$lines[] = __( 'Please acknowledge receipt of this disclosure by signing below or responding in writing.', 'mcp-ai-wpoos-pro' );
		$lines[] = '';
		$lines[] = __( 'Sincerely,', 'mcp-ai-wpoos-pro' );
		$lines[] = $attorney_name;
		$lines[] = $firm_name;
		$lines[] = '';
		$lines[] = __( '___________________________', 'mcp-ai-wpoos-pro' );
		$lines[] = sprintf(
			/* translators: %s: client name */
			__( 'Client Acknowledgment: %s', 'mcp-ai-wpoos-pro' ),
			$client_name
		);
		$lines[] = __( 'Date: _______________', 'mcp-ai-wpoos-pro' );

		return implode( "\n", $lines );
	}

	/**
	 * Generate general notice disclosure text.
	 *
	 * @param string $client_name   Client name.
	 * @param string $firm_name     Firm name.
	 * @param string $tools_list    Comma-separated tool names.
	 * @param string $purpose       Purpose of AI usage.
	 * @param string $attorney_name Attorney name.
	 * @param string $date          Current date.
	 * @return string
	 */
	private function generate_general_notice( $client_name, $firm_name, $tools_list, $purpose, $attorney_name, $date ) {
		$lines = array();

		$lines[] = sprintf(
			/* translators: 1: firm name, 2: date */
			__( '%1$s — NOTICE REGARDING USE OF ARTIFICIAL INTELLIGENCE — Effective: %2$s', 'mcp-ai-wpoos-pro' ),
			strtoupper( $firm_name ),
			$date
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: firm name */
			__( '%s embraces technological innovation to better serve our clients while maintaining the highest ethical standards.', 'mcp-ai-wpoos-pro' ),
			$firm_name
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: tools list */
			__( 'AI TECHNOLOGIES: Our firm may utilize AI-assisted tools including: %s.', 'mcp-ai-wpoos-pro' ),
			$tools_list
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: purpose */
			__( 'GENERAL PURPOSE: %s.', 'mcp-ai-wpoos-pro' ),
			$purpose
		);
		$lines[] = '';
		$lines[] = __( 'OUR COMMITMENTS:', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '- Human attorney review of all AI-assisted work product', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '- Protection of client confidentiality per ABA Rule 1.6', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '- Ongoing attorney competence in AI technologies per ABA Rule 1.1', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '- Transparent billing reflecting AI-driven efficiencies per ABA Rule 1.5', 'mcp-ai-wpoos-pro' );
		$lines[] = __( '- Compliance with ABA Formal Opinion 512 and applicable court orders', 'mcp-ai-wpoos-pro' );
		$lines[] = '';
		$lines[] = __( 'Clients may request additional information about AI usage or request that AI tools not be used in their matters at any time.', 'mcp-ai-wpoos-pro' );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: firm name */
			__( 'For questions, contact your attorney or email our office. — %s', 'mcp-ai-wpoos-pro' ),
			$firm_name
		);

		return implode( "\n", $lines );
	}
}
