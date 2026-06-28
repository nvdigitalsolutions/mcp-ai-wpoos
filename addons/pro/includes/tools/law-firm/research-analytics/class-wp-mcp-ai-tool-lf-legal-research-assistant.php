<?php
/**
 * Legal Research Assistant Tool
 *
 * Assists with legal research by generating outlines, suggesting sources,
 * and providing search strategies for legal issues.
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
 * Assists with legal research by generating outlines, sources, and search strategies.
 */
class WP_MCP_AI_Tool_LF_Legal_Research_Assistant implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_legal_research_assistant'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Legal Research Assistant', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Assists with legal research by generating research outlines, suggesting primary and secondary sources, and providing search strategies for legal issues.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'research_query'            => array(
					'type'        => 'string',
					'description' => __( 'The legal research question or issue to investigate.', 'mcp-ai-wpoos-pro' ),
				),
				'jurisdiction'              => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction for the research (e.g., "federal", "california", "new_york").', 'mcp-ai-wpoos-pro' ),
				),
				'practice_area'             => array(
					'type'        => 'string',
					'description' => __( 'Practice area (e.g., "contract_law", "tort", "criminal", "family").', 'mcp-ai-wpoos-pro' ),
				),
				'date_range'                => array(
					'type'        => 'string',
					'enum'        => array( 'last_year', 'last_5_years', 'all' ),
					'description' => __( 'Date range for sources to consider.', 'mcp-ai-wpoos-pro' ),
				),
				'include_secondary_sources' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include secondary sources like treatises and law reviews (default true).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'research_query' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'external-api' ); }

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

		$research_query            = isset( $arguments['research_query'] ) ? sanitize_text_field( $arguments['research_query'] ) : '';
		$jurisdiction              = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : 'federal';
		$practice_area             = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : 'general';
		$date_range                = isset( $arguments['date_range'] ) ? sanitize_text_field( $arguments['date_range'] ) : 'all';
		$include_secondary_sources = isset( $arguments['include_secondary_sources'] ) ? (bool) $arguments['include_secondary_sources'] : true;

		if ( empty( $research_query ) ) {
			return new WP_Error( 'missing_required', __( 'Research query is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$allowed_ranges = array( 'last_year', 'last_5_years', 'all' );
		if ( ! in_array( $date_range, $allowed_ranges, true ) ) {
			$date_range = 'all';
		}

		// Build research outline from stored matters and research notes.
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_lf_practice_area',
				'value'   => $practice_area,
				'compare' => 'LIKE',
			),
		);

		if ( ! empty( $jurisdiction ) && 'federal' !== $jurisdiction ) {
			$meta_query[] = array(
				'key'     => '_lf_jurisdiction',
				'value'   => $jurisdiction,
				'compare' => 'LIKE',
			);
		}

		$date_query = array();
		if ( 'last_year' === $date_range ) {
			$date_query = array( array( 'after' => '1 year ago' ) );
		} elseif ( 'last_5_years' === $date_range ) {
			$date_query = array( array( 'after' => '5 years ago' ) );
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_lf_matter',
			'posts_per_page' => 50,
			'post_status'    => 'publish',
			's'              => $research_query,
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		if ( ! empty( $date_query ) ) {
			$query_args['date_query'] = $date_query;
		}

		$related_matters = new WP_Query( $query_args );
		$matter_refs     = array();

		if ( $related_matters->have_posts() ) {
			foreach ( $related_matters->posts as $matter ) {
				$matter_refs[] = array(
					'matter_id'     => $matter->ID,
					'title'         => $matter->post_title,
					'practice_area' => get_post_meta( $matter->ID, '_lf_practice_area', true ),
					'jurisdiction'  => get_post_meta( $matter->ID, '_lf_jurisdiction', true ),
					'date'          => $matter->post_date,
				);
			}
		}

		// Build research outline sections.
		$outline_sections = array(
			array(
				'section'     => __( 'Issue Identification', 'mcp-ai-wpoos-pro' ),
				'description' => sprintf(
					/* translators: %s: research query */
					__( 'Define the specific legal issue: %s', 'mcp-ai-wpoos-pro' ),
					$research_query
				),
			),
			array(
				'section'     => __( 'Applicable Law', 'mcp-ai-wpoos-pro' ),
				'description' => sprintf(
					/* translators: %s: jurisdiction */
					__( 'Identify relevant statutes, regulations, and constitutional provisions in %s jurisdiction.', 'mcp-ai-wpoos-pro' ),
					ucfirst( $jurisdiction )
				),
			),
			array(
				'section'     => __( 'Case Law Analysis', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Locate and analyze binding and persuasive authority.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'section'     => __( 'Synthesis & Application', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Apply legal rules to the facts and synthesize conclusions.', 'mcp-ai-wpoos-pro' ),
			),
		);

		// Build suggested sources.
		$suggested_sources = array(
			'primary' => array(
				array(
					'type'        => 'statutes',
					'description' => sprintf(
						/* translators: 1: jurisdiction, 2: practice area */
						__( '%1$s statutes and codes related to %2$s.', 'mcp-ai-wpoos-pro' ),
						ucfirst( $jurisdiction ),
						str_replace( '_', ' ', $practice_area )
					),
				),
				array(
					'type'        => 'case_law',
					'description' => sprintf(
						/* translators: %s: jurisdiction */
						__( 'Binding precedent from %s courts.', 'mcp-ai-wpoos-pro' ),
						ucfirst( $jurisdiction )
					),
				),
				array(
					'type'        => 'regulations',
					'description' => __( 'Applicable administrative regulations and agency guidance.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);

		if ( $include_secondary_sources ) {
			$suggested_sources['secondary'] = array(
				array(
					'type'        => 'treatises',
					'description' => sprintf(
						/* translators: %s: practice area */
						__( 'Leading treatises on %s.', 'mcp-ai-wpoos-pro' ),
						str_replace( '_', ' ', $practice_area )
					),
				),
				array(
					'type'        => 'law_reviews',
					'description' => __( 'Recent law review articles and scholarly commentary.', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'type'        => 'practice_guides',
					'description' => __( 'Practice guides and continuing legal education materials.', 'mcp-ai-wpoos-pro' ),
				),
			);
		}

		// Build search strategies.
		$keywords          = array_filter( explode( ' ', $research_query ) );
		$search_strategies = array(
			array(
				'strategy'    => __( 'Terms and Connectors', 'mcp-ai-wpoos-pro' ),
				'description' => sprintf(
					/* translators: %s: keywords joined */
					__( 'Use Boolean operators: %s', 'mcp-ai-wpoos-pro' ),
					implode( ' AND ', array_slice( $keywords, 0, 5 ) )
				),
			),
			array(
				'strategy'    => __( 'Natural Language Search', 'mcp-ai-wpoos-pro' ),
				'description' => sprintf(
					/* translators: %s: research query */
					__( 'Use full question: "%s"', 'mcp-ai-wpoos-pro' ),
					$research_query
				),
			),
			array(
				'strategy'    => __( 'Key Number Digest', 'mcp-ai-wpoos-pro' ),
				'description' => sprintf(
					/* translators: %s: practice area */
					__( 'Browse West Key Number System under %s topics.', 'mcp-ai-wpoos-pro' ),
					str_replace( '_', ' ', $practice_area )
				),
			),
			array(
				'strategy'    => __( 'Citator Review', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Shepardize or KeyCite key authorities to find citing references and check validity.', 'mcp-ai-wpoos-pro' ),
			),
		);

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: outline section count, 2: related matter count */
				__( 'Research plan generated with %1$d outline sections and %2$d related matters found. ', 'mcp-ai-wpoos-pro' ),
				count( $outline_sections ),
				count( $matter_refs )
			) . self::DISCLAIMER,
			'data'       => array(
				'research_query'    => $research_query,
				'jurisdiction'      => $jurisdiction,
				'practice_area'     => $practice_area,
				'date_range'        => $date_range,
				'research_outline'  => $outline_sections,
				'suggested_sources' => $suggested_sources,
				'search_strategies' => $search_strategies,
				'related_matters'   => $matter_refs,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
