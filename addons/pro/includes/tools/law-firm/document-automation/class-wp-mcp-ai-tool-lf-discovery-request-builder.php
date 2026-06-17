<?php
/**
 * Discovery Request Builder Tool
 *
 * Generates discovery requests including interrogatories, requests for production,
 * requests for admission, and deposition notices.
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
 * Builds structured discovery requests for litigation matters.
 */
class WP_MCP_AI_Tool_LF_Discovery_Request_Builder implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_discovery_request_builder';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Discovery Request Builder', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates discovery requests including interrogatories, requests for production, requests for admission, and deposition notices with standard instructions and definitions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'discovery_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of discovery request to generate.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'interrogatories', 'requests_for_production', 'requests_for_admission', 'deposition_notice' ),
				),
				'matter_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Associated matter ID.', 'mcp-ai-wpoos-pro' ),
				),
				'topic_areas'    => array(
					'type'        => 'array',
					'description' => __( 'Topic areas to cover in discovery.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'num_requests'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of requests to generate (default: 10).', 'mcp-ai-wpoos-pro' ),
				),
				'jurisdiction'   => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction for applicable rules.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'discovery_type' ),
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

		$discovery_type = isset( $arguments['discovery_type'] ) ? sanitize_text_field( $arguments['discovery_type'] ) : '';
		$matter_id      = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$num_requests   = isset( $arguments['num_requests'] ) ? absint( $arguments['num_requests'] ) : 10;
		$jurisdiction   = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : 'federal';
		$topic_areas    = array();

		if ( ! empty( $arguments['topic_areas'] ) && is_array( $arguments['topic_areas'] ) ) {
			foreach ( $arguments['topic_areas'] as $topic ) {
				$topic_areas[] = sanitize_text_field( $topic );
			}
		}

		$valid_types = array( 'interrogatories', 'requests_for_production', 'requests_for_admission', 'deposition_notice' );
		if ( ! in_array( $discovery_type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_param', __( 'Invalid discovery type.', 'mcp-ai-wpoos-pro' ) );
		}

		$num_requests = max( 1, min( $num_requests, 25 ) );

		$definitions  = $this->get_standard_definitions();
		$instructions = $this->get_instructions( $discovery_type );
		$requests     = $this->generate_requests( $discovery_type, $topic_areas, $num_requests );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: count, 2: type */
				__( 'Generated %1$d %2$s. ', 'mcp-ai-wpoos-pro' ),
				count( $requests ),
				str_replace( '_', ' ', $discovery_type )
			) . self::DISCLAIMER,
			'data'       => array(
				'discovery_type' => $discovery_type,
				'matter_id'      => $matter_id,
				'jurisdiction'   => $jurisdiction,
				'definitions'    => $definitions,
				'instructions'   => $instructions,
				'requests'       => $requests,
				'total_requests' => count( $requests ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Get standard discovery definitions.
	 *
	 * @return array
	 */
	private function get_standard_definitions(): array {
		return array(
			array(
				'term'       => 'Document',
				'definition' => __( 'Any writing, recording, or photograph, including electronically stored information (ESI), as defined in FRCP 34.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'term'       => 'Communication',
				'definition' => __( 'Any oral, written, or electronic exchange of information between two or more persons.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'term'       => 'Person',
				'definition' => __( 'Any natural person, corporation, partnership, association, or other legal entity.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'term'       => 'Identify',
				'definition' => __( 'With respect to a person: name, address, telephone number, and relationship to this action. With respect to a document: author, date, recipients, subject, and current custodian.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get instructions for a discovery type.
	 *
	 * @param string $type Discovery type.
	 * @return array
	 */
	private function get_instructions( string $type ): array {
		$common = array(
			__( 'These requests are continuing in nature. Supplemental responses are required per FRCP 26(e).', 'mcp-ai-wpoos-pro' ),
			__( 'If any information is withheld on privilege grounds, provide a privilege log per FRCP 26(b)(5).', 'mcp-ai-wpoos-pro' ),
		);

		$specific = array(
			'interrogatories'         => array( __( 'Each interrogatory shall be answered separately and fully in writing under oath per FRCP 33.', 'mcp-ai-wpoos-pro' ) ),
			'requests_for_production' => array( __( 'Produce documents as kept in the usual course of business or organize and label them per FRCP 34.', 'mcp-ai-wpoos-pro' ) ),
			'requests_for_admission'  => array( __( 'Each matter is deemed admitted unless a written answer or objection is served within 30 days per FRCP 36.', 'mcp-ai-wpoos-pro' ) ),
			'deposition_notice'       => array( __( 'The deponent shall appear at the date, time, and place specified and bring any documents identified herein per FRCP 30.', 'mcp-ai-wpoos-pro' ) ),
		);

		return array_merge( $common, $specific[ $type ] ?? array() );
	}

	/**
	 * Generate numbered discovery requests.
	 *
	 * @param string $type        Discovery type.
	 * @param array  $topic_areas Topic areas.
	 * @param int    $count       Number to generate.
	 * @return array
	 */
	private function generate_requests( string $type, array $topic_areas, int $count ): array {
		$templates = array(
			'interrogatories'         => array(
				__( 'Identify all persons with knowledge of %s.', 'mcp-ai-wpoos-pro' ),
				__( 'Describe in detail all facts supporting your contentions regarding %s.', 'mcp-ai-wpoos-pro' ),
				__( 'Identify all documents that relate to %s.', 'mcp-ai-wpoos-pro' ),
				__( 'State the dates and substance of all communications regarding %s.', 'mcp-ai-wpoos-pro' ),
				__( 'Describe any policies or procedures related to %s.', 'mcp-ai-wpoos-pro' ),
			),
			'requests_for_production' => array(
				__( 'All documents and communications relating to %s.', 'mcp-ai-wpoos-pro' ),
				__( 'All contracts, agreements, or memoranda concerning %s.', 'mcp-ai-wpoos-pro' ),
				__( 'All electronically stored information, including emails, relating to %s.', 'mcp-ai-wpoos-pro' ),
				__( 'All records, reports, or analyses regarding %s.', 'mcp-ai-wpoos-pro' ),
				__( 'All correspondence between any parties relating to %s.', 'mcp-ai-wpoos-pro' ),
			),
			'requests_for_admission'  => array(
				__( 'Admit that you had knowledge of %s.', 'mcp-ai-wpoos-pro' ),
				__( 'Admit that the documents relating to %s are authentic.', 'mcp-ai-wpoos-pro' ),
				__( 'Admit that you were responsible for %s.', 'mcp-ai-wpoos-pro' ),
				__( 'Admit that the facts set forth regarding %s are true and correct.', 'mcp-ai-wpoos-pro' ),
				__( 'Admit that no additional agreements exist concerning %s.', 'mcp-ai-wpoos-pro' ),
			),
			'deposition_notice'       => array(
				__( 'Testimony regarding your knowledge of %s.', 'mcp-ai-wpoos-pro' ),
				__( 'Testimony regarding all communications concerning %s.', 'mcp-ai-wpoos-pro' ),
				__( 'Testimony regarding your role in %s.', 'mcp-ai-wpoos-pro' ),
				__( 'Testimony regarding documents you authored or received concerning %s.', 'mcp-ai-wpoos-pro' ),
				__( 'Testimony regarding the chronology of events related to %s.', 'mcp-ai-wpoos-pro' ),
			),
		);

		$tmpl    = $templates[ $type ] ?? array();
		$results = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$template  = $tmpl[ $i % count( $tmpl ) ];
			$topic     = ! empty( $topic_areas ) ? $topic_areas[ $i % count( $topic_areas ) ] : __( 'the subject matter of this action', 'mcp-ai-wpoos-pro' );
			$results[] = array(
				'number' => $i + 1,
				'text'   => sprintf( $template, $topic ),
			);
		}

		return $results;
	}
}
