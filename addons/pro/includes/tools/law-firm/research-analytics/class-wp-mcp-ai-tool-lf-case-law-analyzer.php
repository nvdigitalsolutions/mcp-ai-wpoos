<?php
/**
 * Case Law Analyzer Tool
 *
 * Analyzes case law for holdings, reasoning, dissent, and impact.
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
 * Analyzes case law including holdings, reasoning, dissent, and downstream impact.
 */
class WP_MCP_AI_Tool_LF_Case_Law_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_case_law_analyzer'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Case Law Analyzer', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Analyzes case law for holdings, reasoning, dissent, and impact. Supports comparison with other cases.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'case_citation' => array(
					'type'        => 'string',
					'description' => __( 'Full case citation (e.g., "Brown v. Board of Education, 347 U.S. 483 (1954)").', 'mcp-ai-wpoos-pro' ),
				),
				'analysis_type' => array(
					'type'        => 'string',
					'enum'        => array( 'holding', 'reasoning', 'dissent', 'impact', 'all' ),
					'description' => __( 'Type of analysis to perform (default: all).', 'mcp-ai-wpoos-pro' ),
				),
				'compare_to'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Array of case citations to compare against.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'case_citation' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' ); }

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
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$case_citation = isset( $arguments['case_citation'] ) ? sanitize_text_field( $arguments['case_citation'] ) : '';
		$analysis_type = isset( $arguments['analysis_type'] ) ? sanitize_text_field( $arguments['analysis_type'] ) : 'all';
		$compare_to    = isset( $arguments['compare_to'] ) && is_array( $arguments['compare_to'] ) ? array_map( 'sanitize_text_field', $arguments['compare_to'] ) : array();

		if ( empty( $case_citation ) ) {
			return new WP_Error( 'missing_required', __( 'Case citation is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$allowed_types = array( 'holding', 'reasoning', 'dissent', 'impact', 'all' );
		if ( ! in_array( $analysis_type, $allowed_types, true ) ) {
			$analysis_type = 'all';
		}

		// Look up case in stored case law entries.
		$case_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lf_matter',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_lf_case_citation',
					'value'   => $case_citation,
					'compare' => 'LIKE',
				),
				),
			)
		);

		$case_data = array();
		if ( $case_query->have_posts() ) {
			$case_post = $case_query->posts[0];
			$case_data = array(
				'post_id'       => $case_post->ID,
				'title'         => $case_post->post_title,
				'citation'      => get_post_meta( $case_post->ID, '_lf_case_citation', true ),
				'court'         => get_post_meta( $case_post->ID, '_lf_court', true ),
				'date_decided'  => get_post_meta( $case_post->ID, '_lf_date_decided', true ),
				'judge'         => get_post_meta( $case_post->ID, '_lf_judge', true ),
				'practice_area' => get_post_meta( $case_post->ID, '_lf_practice_area', true ),
				'holding'       => get_post_meta( $case_post->ID, '_lf_holding', true ),
				'reasoning'     => get_post_meta( $case_post->ID, '_lf_reasoning', true ),
				'dissent'       => get_post_meta( $case_post->ID, '_lf_dissent', true ),
			);
		}

		// Build the analysis based on type.
		$case_summary = array(
			'citation'      => $case_citation,
			'title'         => ! empty( $case_data['title'] ) ? $case_data['title'] : $case_citation,
			'court'         => ! empty( $case_data['court'] ) ? $case_data['court'] : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
			'date_decided'  => ! empty( $case_data['date_decided'] ) ? $case_data['date_decided'] : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
			'stored_record' => ! empty( $case_data ),
		);

		$key_holdings = array();
		if ( 'all' === $analysis_type || 'holding' === $analysis_type ) {
			$holding_text = ! empty( $case_data['holding'] ) ? $case_data['holding'] : '';
			$key_holdings = array(
				'primary_holding' => ! empty( $holding_text ) ? $holding_text : __( 'No holding data stored. Review the case to extract holdings.', 'mcp-ai-wpoos-pro' ),
				'rule_of_law'     => ! empty( $case_data['practice_area'] ) ? sprintf(
					/* translators: %s: practice area */
					__( 'Rule relates to %s law.', 'mcp-ai-wpoos-pro' ),
					str_replace( '_', ' ', $case_data['practice_area'] )
				) : __( 'Practice area not specified.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$reasoning_analysis = array();
		if ( 'all' === $analysis_type || 'reasoning' === $analysis_type ) {
			$reasoning_text     = ! empty( $case_data['reasoning'] ) ? $case_data['reasoning'] : '';
			$reasoning_analysis = array(
				'majority_reasoning' => ! empty( $reasoning_text ) ? $reasoning_text : __( 'No reasoning data stored. Review the case to extract the court\'s analysis.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$dissent_analysis = array();
		if ( 'all' === $analysis_type || 'dissent' === $analysis_type ) {
			$dissent_text     = ! empty( $case_data['dissent'] ) ? $case_data['dissent'] : '';
			$dissent_analysis = array(
				'dissenting_opinion' => ! empty( $dissent_text ) ? $dissent_text : __( 'No dissent data stored or case was unanimous.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$impact_analysis = array();
		if ( 'all' === $analysis_type || 'impact' === $analysis_type ) {
			// Find matters that cite this case.
			$citing_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_lf_matter',
					'posts_per_page' => 20,
					'post_status'    => 'publish',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_lf_cited_cases',
						'value'   => $case_citation,
						'compare' => 'LIKE',
					),
					),
				)
			);

			$citing_matters = array();
			if ( $citing_query->have_posts() ) {
				foreach ( $citing_query->posts as $citing ) {
					$citing_matters[] = array(
						'matter_id' => $citing->ID,
						'title'     => $citing->post_title,
					);
				}
			}

			$impact_analysis = array(
				'times_cited'    => count( $citing_matters ),
				'citing_matters' => $citing_matters,
			);
		}

		// Comparison analysis.
		$comparisons = array();
		if ( ! empty( $compare_to ) ) {
			foreach ( $compare_to as $comp_citation ) {
				$comp_query = new WP_Query(
					array(
						'post_type'      => 'mcp_ai_lf_matter',
						'posts_per_page' => 1,
						'post_status'    => 'publish',
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_lf_case_citation',
							'value'   => $comp_citation,
							'compare' => 'LIKE',
						),
						),
					)
				);

				$comp_data = array(
					'citation' => $comp_citation,
					'found'    => $comp_query->have_posts(),
				);

				if ( $comp_query->have_posts() ) {
					$comp_post            = $comp_query->posts[0];
					$comp_data['title']   = $comp_post->post_title;
					$comp_data['holding'] = get_post_meta( $comp_post->ID, '_lf_holding', true );
					$comp_data['court']   = get_post_meta( $comp_post->ID, '_lf_court', true );
				}

				$comparisons[] = $comp_data;
			}
		}

		$result_data = array(
			'case_summary'  => $case_summary,
			'analysis_type' => $analysis_type,
		);

		if ( ! empty( $key_holdings ) ) {
			$result_data['key_holdings'] = $key_holdings;
		}
		if ( ! empty( $reasoning_analysis ) ) {
			$result_data['reasoning_analysis'] = $reasoning_analysis;
		}
		if ( ! empty( $dissent_analysis ) ) {
			$result_data['dissent_analysis'] = $dissent_analysis;
		}
		if ( ! empty( $impact_analysis ) ) {
			$result_data['impact_analysis'] = $impact_analysis;
		}
		if ( ! empty( $comparisons ) ) {
			$result_data['comparisons'] = $comparisons;
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: case citation, 2: analysis type */
				__( 'Case analysis completed for %1$s (%2$s analysis). ', 'mcp-ai-wpoos-pro' ),
				$case_citation,
				$analysis_type
			) . self::DISCLAIMER,
			'data'       => $result_data,
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
