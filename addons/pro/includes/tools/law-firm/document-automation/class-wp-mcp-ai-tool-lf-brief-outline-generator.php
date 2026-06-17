<?php
/**
 * Brief Outline Generator Tool
 *
 * Generates structured outlines for legal briefs.
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
 * Generates structured brief outlines with standard sections.
 */
class WP_MCP_AI_Tool_LF_Brief_Outline_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Check if tool is available.
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
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_brief_outline_generator'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Brief Outline Generator', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generates structured outlines for appellate, trial, amicus, or reply briefs with standard sections and argument framework.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'brief_type'    => array(
					'type'        => 'string',
					'description' => __( 'Type of brief.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'appellate', 'trial', 'amicus', 'reply' ),
				),
				'issue'         => array(
					'type'        => 'string',
					'description' => __( 'Primary legal issue.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'jurisdiction'  => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction.', 'mcp-ai-wpoos-pro' ),
				),
				'key_arguments' => array(
					'type'        => 'array',
					'description' => __( 'Key arguments to include.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'brief_type', 'issue' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$brief_type    = isset( $arguments['brief_type'] ) ? sanitize_text_field( $arguments['brief_type'] ) : '';
		$issue         = isset( $arguments['issue'] ) ? sanitize_textarea_field( $arguments['issue'] ) : '';
		$jurisdiction  = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';
		$key_arguments = array();

		if ( ! empty( $arguments['key_arguments'] ) && is_array( $arguments['key_arguments'] ) ) {
			foreach ( $arguments['key_arguments'] as $arg ) {
				$key_arguments[] = sanitize_text_field( $arg );
			}
		}

		$valid_types = array( 'appellate', 'trial', 'amicus', 'reply' );
		if ( ! in_array( $brief_type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_param', __( 'Invalid brief type.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $issue ) ) {
			return new WP_Error( 'missing_required', __( 'Issue is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$outline = $this->build_outline( $brief_type, $issue, $key_arguments );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: brief type (appellate, trial, etc.) */
				__( '%s brief outline generated. ', 'mcp-ai-wpoos-pro' ),
				ucfirst( $brief_type )
			) . self::DISCLAIMER,
			'data'       => array(
				'brief_type'   => $brief_type,
				'issue'        => $issue,
				'jurisdiction' => $jurisdiction,
				'outline'      => $outline,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Build_outline.
	 *
	 * @param string $type Parameter.
	 * @param string $issue Parameter.
	 * @param array  $args Parameter.
	 * @return array|WP_Error Result.
	 */
	private function build_outline( string $type, string $issue, array $args ): array {
		$structures = array(
			'appellate' => array(
				array(
					'section' => 'table_of_contents',
					'content' => __( '[Auto-generated after completion]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'table_of_authorities',
					'content' => __( '[Cases, statutes, and secondary sources cited]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'jurisdictional_statement',
					'content' => __( '[Basis for appellate jurisdiction]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'statement_of_issues',
					'content' => $issue,
				),
				array(
					'section' => 'statement_of_the_case',
					'content' => __( '[Procedural history]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'statement_of_facts',
					'content' => __( '[Record-based facts with citations]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'standard_of_review',
					'content' => __( '[Applicable standard: de novo, abuse of discretion, clear error]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'argument',
					'content' => ! empty( $args ) ? $args : array( __( '[Develop arguments with case law support]', 'mcp-ai-wpoos-pro' ) ),
				),
				array(
					'section' => 'conclusion',
					'content' => __( '[Specific relief requested]', 'mcp-ai-wpoos-pro' ),
				),
			),
			'trial'     => array(
				array(
					'section' => 'table_of_contents',
					'content' => __( '[Auto-generated]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'statement_of_issues',
					'content' => $issue,
				),
				array(
					'section' => 'statement_of_facts',
					'content' => __( '[Factual background with evidentiary support]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'argument',
					'content' => ! empty( $args ) ? $args : array( __( '[Legal arguments]', 'mcp-ai-wpoos-pro' ) ),
				),
				array(
					'section' => 'conclusion',
					'content' => __( '[Relief requested]', 'mcp-ai-wpoos-pro' ),
				),
			),
			'amicus'    => array(
				array(
					'section' => 'table_of_contents',
					'content' => __( '[Auto-generated]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'table_of_authorities',
					'content' => __( '[Sources cited]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'interest_of_amicus',
					'content' => __( '[Statement of amicus interest and authority to file]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'summary_of_argument',
					'content' => __( '[Brief summary]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'argument',
					'content' => ! empty( $args ) ? $args : array( __( '[Arguments from amicus perspective]', 'mcp-ai-wpoos-pro' ) ),
				),
				array(
					'section' => 'conclusion',
					'content' => __( '[Recommended disposition]', 'mcp-ai-wpoos-pro' ),
				),
			),
			'reply'     => array(
				array(
					'section' => 'table_of_contents',
					'content' => __( '[Auto-generated]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'introduction',
					'content' => __( '[Response to opposition arguments]', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'section' => 'statement_of_issues',
					'content' => $issue,
				),
				array(
					'section' => 'argument',
					'content' => ! empty( $args ) ? $args : array( __( '[Rebut opposition points]', 'mcp-ai-wpoos-pro' ) ),
				),
				array(
					'section' => 'conclusion',
					'content' => __( '[Reiterate requested relief]', 'mcp-ai-wpoos-pro' ),
				),
			),
		);

		return $structures[ $type ] ?? array();
	}
}
