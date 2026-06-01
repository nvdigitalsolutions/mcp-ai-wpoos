<?php
/**
 * Document Drafter Tool
 *
 * Drafts legal documents by creating structured templates based on document type,
 * jurisdiction, parties, and practice area.
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
 * Creates draft legal documents with structured sections and metadata.
 */
class WP_MCP_AI_Tool_LF_Document_Drafter implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
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
		return 'lf_document_drafter';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Document Drafter', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Drafts legal documents by creating structured templates with sections appropriate for the selected document type, jurisdiction, and practice area.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'document_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of legal document to draft.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'contract', 'pleading', 'motion', 'brief', 'memo', 'letter', 'agreement', 'will', 'trust', 'discovery' ),
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Title of the document.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'matter_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Associated matter ID.', 'mcp-ai-wpoos-pro' ),
				),
				'jurisdiction'  => array(
					'type'        => 'string',
					'description' => __( 'Applicable jurisdiction (e.g., "CA", "NY", "federal").', 'mcp-ai-wpoos-pro' ),
				),
				'parties'       => array(
					'type'        => 'array',
					'description' => __( 'Parties involved in the document.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'key_terms'     => array(
					'type'        => 'string',
					'description' => __( 'Key terms or provisions to include.', 'mcp-ai-wpoos-pro' ),
				),
				'practice_area' => array(
					'type'        => 'string',
					'description' => __( 'Area of law for the document.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'document_type', 'title' ),
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

		$document_type = isset( $arguments['document_type'] ) ? sanitize_text_field( $arguments['document_type'] ) : '';
		$title         = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$matter_id     = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$jurisdiction  = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';
		$parties       = array();
		$key_terms     = isset( $arguments['key_terms'] ) ? sanitize_textarea_field( $arguments['key_terms'] ) : '';
		$practice_area = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';

		if ( ! empty( $arguments['parties'] ) && is_array( $arguments['parties'] ) ) {
			foreach ( $arguments['parties'] as $party ) {
				$parties[] = sanitize_text_field( $party );
			}
		}

		$valid_types = array( 'contract', 'pleading', 'motion', 'brief', 'memo', 'letter', 'agreement', 'will', 'trust', 'discovery' );
		if ( empty( $document_type ) || ! in_array( $document_type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_param', __( 'Invalid document type.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $title ) ) {
			return new WP_Error( 'missing_required', __( 'Document title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$sections = $this->get_template_sections( $document_type );

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_lf_document',
				'post_title'   => $title,
				'post_content' => wp_json_encode( $sections ),
				'post_status'  => 'draft',
				'post_author'  => $uid,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_object_terms( $post_id, $document_type, 'lf_document_type' );

		if ( $matter_id ) {
			update_post_meta( $post_id, '_lf_matter_id', $matter_id );
		}
		if ( $jurisdiction ) {
			update_post_meta( $post_id, '_lf_jurisdiction', $jurisdiction );
		}
		if ( ! empty( $parties ) ) {
			update_post_meta( $post_id, '_lf_parties', $parties );
		}
		if ( $practice_area ) {
			update_post_meta( $post_id, '_lf_practice_area', $practice_area );
		}
		if ( $key_terms ) {
			update_post_meta( $post_id, '_lf_key_terms', $key_terms );
		}
		update_post_meta( $post_id, '_lf_created_date', current_time( 'Y-m-d H:i:s' ) );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: document type */
				__( 'Draft %s created successfully. ', 'mcp-ai-wpoos-pro' ),
				str_replace( '_', ' ', $document_type )
			) . self::DISCLAIMER,
			'data'       => array(
				'post_id'       => $post_id,
				'document_type' => $document_type,
				'title'         => $title,
				'jurisdiction'  => $jurisdiction,
				'parties'       => $parties,
				'sections'      => $sections,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Get template sections for a document type.
	 *
	 * @param string $type Document type.
	 * @return array Template sections.
	 */
	private function get_template_sections( string $type ): array {
		$templates = array(
			'contract'  => array( 'recitals', 'definitions', 'terms_and_conditions', 'representations_and_warranties', 'indemnification', 'termination', 'governing_law', 'signatures' ),
			'pleading'  => array( 'caption', 'introduction', 'jurisdiction_and_venue', 'factual_allegations', 'causes_of_action', 'prayer_for_relief', 'verification', 'certificate_of_service' ),
			'motion'    => array( 'caption', 'introduction', 'statement_of_facts', 'legal_standard', 'argument', 'conclusion', 'certificate_of_service' ),
			'brief'     => array( 'table_of_contents', 'table_of_authorities', 'statement_of_issues', 'statement_of_case', 'statement_of_facts', 'argument', 'conclusion' ),
			'memo'      => array( 'heading', 'question_presented', 'brief_answer', 'statement_of_facts', 'discussion', 'conclusion' ),
			'letter'    => array( 'header', 'date_and_address', 're_line', 'salutation', 'body', 'closing', 'enclosures' ),
			'agreement' => array( 'preamble', 'recitals', 'definitions', 'obligations', 'payment_terms', 'confidentiality', 'termination', 'dispute_resolution', 'general_provisions', 'signatures' ),
			'will'      => array( 'declaration', 'revocation_of_prior_wills', 'debts_and_expenses', 'specific_bequests', 'residuary_estate', 'executor_appointment', 'guardian_designation', 'attestation' ),
			'trust'     => array( 'declaration_of_trust', 'trust_property', 'trustee_powers', 'beneficiary_designations', 'distribution_provisions', 'amendment_and_revocation', 'successor_trustee', 'governing_law' ),
			'discovery' => array( 'caption', 'definitions', 'instructions', 'interrogatories', 'requests_for_production', 'requests_for_admission', 'certificate_of_service' ),
		);

		$sections = $templates[ $type ] ?? array( 'introduction', 'body', 'conclusion' );
		$result   = array();
		foreach ( $sections as $section ) {
			$result[] = array(
				'name'    => $section,
				'label'   => ucwords( str_replace( '_', ' ', $section ) ),
				'content' => '',
			);
		}
		return $result;
	}
}
