<?php
/**
 * document-automation/class-wp-mcp-ai-tool-lf-discovery-request-builder.php (ecosystem port — Wave F4, law-firm document-automation batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-discovery-request-builder.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

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
		return __( 'Law Firm toolkit is not enabled.', 'nvoos-content-graph-pro' );
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
		return __( 'Discovery Request Builder', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates discovery requests including interrogatories, requests for production, requests for admission, and deposition notices with standard instructions and definitions.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Type of discovery request to generate.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'interrogatories', 'requests_for_production', 'requests_for_admission', 'deposition_notice' ),
				),
				'matter_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Associated matter ID.', 'nvoos-content-graph-pro' ),
				),
				'topic_areas'    => array(
					'type'        => 'array',
					'description' => __( 'Topic areas to cover in discovery.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'num_requests'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of requests to generate (default: 10).', 'nvoos-content-graph-pro' ),
				),
				'jurisdiction'   => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction for applicable rules.', 'nvoos-content-graph-pro' ),
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
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
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
			return new WP_Error( 'invalid_param', __( 'Invalid discovery type.', 'nvoos-content-graph-pro' ) );
		}

		$num_requests = max( 1, min( $num_requests, 25 ) );

		$definitions  = $this->get_standard_definitions();
		$instructions = $this->get_instructions( $discovery_type );
		$requests     = $this->generate_requests( $discovery_type, $topic_areas, $num_requests );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: count, 2: type */
				__( 'Generated %1$d %2$s. ', 'nvoos-content-graph-pro' ),
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
				'definition' => __( 'Any writing, recording, or photograph, including electronically stored information (ESI), as defined in FRCP 34.', 'nvoos-content-graph-pro' ),
			),
			array(
				'term'       => 'Communication',
				'definition' => __( 'Any oral, written, or electronic exchange of information between two or more persons.', 'nvoos-content-graph-pro' ),
			),
			array(
				'term'       => 'Person',
				'definition' => __( 'Any natural person, corporation, partnership, association, or other legal entity.', 'nvoos-content-graph-pro' ),
			),
			array(
				'term'       => 'Identify',
				'definition' => __( 'With respect to a person: name, address, telephone number, and relationship to this action. With respect to a document: author, date, recipients, subject, and current custodian.', 'nvoos-content-graph-pro' ),
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
			__( 'These requests are continuing in nature. Supplemental responses are required per FRCP 26(e).', 'nvoos-content-graph-pro' ),
			__( 'If any information is withheld on privilege grounds, provide a privilege log per FRCP 26(b)(5).', 'nvoos-content-graph-pro' ),
		);

		$specific = array(
			'interrogatories'         => array( __( 'Each interrogatory shall be answered separately and fully in writing under oath per FRCP 33.', 'nvoos-content-graph-pro' ) ),
			'requests_for_production' => array( __( 'Produce documents as kept in the usual course of business or organize and label them per FRCP 34.', 'nvoos-content-graph-pro' ) ),
			'requests_for_admission'  => array( __( 'Each matter is deemed admitted unless a written answer or objection is served within 30 days per FRCP 36.', 'nvoos-content-graph-pro' ) ),
			'deposition_notice'       => array( __( 'The deponent shall appear at the date, time, and place specified and bring any documents identified herein per FRCP 30.', 'nvoos-content-graph-pro' ) ),
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
				/* translators: %s: topic area */
				__( 'Identify all persons with knowledge of %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Describe in detail all facts supporting your contentions regarding %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Identify all documents that relate to %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'State the dates and substance of all communications regarding %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Describe any policies or procedures related to %s.', 'nvoos-content-graph-pro' ),
			),
			'requests_for_production' => array(
				/* translators: %s: topic area */
				__( 'All documents and communications relating to %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'All contracts, agreements, or memoranda concerning %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'All electronically stored information, including emails, relating to %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'All records, reports, or analyses regarding %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'All correspondence between any parties relating to %s.', 'nvoos-content-graph-pro' ),
			),
			'requests_for_admission'  => array(
				/* translators: %s: topic area */
				__( 'Admit that you had knowledge of %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Admit that the documents relating to %s are authentic.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Admit that you were responsible for %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Admit that the facts set forth regarding %s are true and correct.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Admit that no additional agreements exist concerning %s.', 'nvoos-content-graph-pro' ),
			),
			'deposition_notice'       => array(
				/* translators: %s: topic area */
				__( 'Testimony regarding your knowledge of %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Testimony regarding all communications concerning %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Testimony regarding your role in %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Testimony regarding documents you authored or received concerning %s.', 'nvoos-content-graph-pro' ),
				/* translators: %s: topic area */
				__( 'Testimony regarding the chronology of events related to %s.', 'nvoos-content-graph-pro' ),
			),
		);

		$tmpl    = $templates[ $type ] ?? array();
		$results = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$template  = $tmpl[ $i % count( $tmpl ) ];
			$topic     = ! empty( $topic_areas ) ? $topic_areas[ $i % count( $topic_areas ) ] : __( 'the subject matter of this action', 'nvoos-content-graph-pro' );
			$results[] = array(
				'number' => $i + 1,
				'text'   => sprintf( $template, $topic ),
			);
		}

		return $results;
	}
}
