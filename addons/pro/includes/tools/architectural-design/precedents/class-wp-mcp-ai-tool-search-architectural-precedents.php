<?php
/**
 * Tool — Search Architectural Precedents.
 *
 * Performs embedding-based semantic search over the
 * `mcp_ai_arch_prec` CPT using OpenAI embeddings + cosine similarity.
 * Falls back to deterministic keyword scoring when embeddings are not
 * available (no API key, offline environment, or filter short-circuit).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Search architectural precedents.
 */
class WP_MCP_AI_Tool_Search_Architectural_Precedents implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */

	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'search_architectural_precedents';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Search Architectural Precedents', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Semantic search over the architectural precedent library using OpenAI embeddings + cosine similarity. Optional filters for country, building type and floor area. Falls back to keyword scoring when embeddings are unavailable.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'         => array(
					'type'        => 'string',
					'description' => 'Natural-language description of the design challenge.',
				),
				'country_code'  => array(
					'type'        => 'string',
					'description' => 'ISO 3166-1 alpha-2 country code (e.g. LK, JM, US).',
				),
				'building_type' => array(
					'type'        => 'string',
					'description' => 'Building type (e.g. residential, commercial, healthcare).',
				),
				'min_area_m2'   => array( 'type' => 'number' ),
				'max_area_m2'   => array( 'type' => 'number' ),
				'limit'         => array(
					'type'        => 'integer',
					'description' => 'Maximum number of results to return (1-50). Default 5.',
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'cacheable', 'external-api' );
	}

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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search precedents.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Precedents_Engine' )
			|| ! class_exists( 'WP_MCP_AI_Architectural_Precedent_CPT' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Precedent engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$query = isset( $arguments['query'] ) ? trim( (string) $arguments['query'] ) : '';
		if ( '' === $query ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'query is required and cannot be empty.', 'mcp-ai-wpoos-pro' ) );
		}
		$limit = isset( $arguments['limit'] ) ? max( 1, min( 50, (int) $arguments['limit'] ) ) : 5;

		$country = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( (string) $arguments['country_code'] ) ) : '';
		$btype   = isset( $arguments['building_type'] ) ? sanitize_key( (string) $arguments['building_type'] ) : '';
		$min_a   = isset( $arguments['min_area_m2'] ) ? (float) $arguments['min_area_m2'] : null;
		$max_a   = isset( $arguments['max_area_m2'] ) ? (float) $arguments['max_area_m2'] : null;

		$meta_query = array();
		if ( '' !== $country ) {
			$meta_query[] = array(
				'key'     => '_arch_prec_country_code',
				'value'   => $country,
				'compare' => '=',
			);
		}
		if ( '' !== $btype ) {
			$meta_query[] = array(
				'key'     => '_arch_prec_building_type',
				'value'   => $btype,
				'compare' => '=',
			);
		}
		if ( null !== $min_a ) {
			$meta_query[] = array(
				'key'     => '_arch_prec_area_m2',
				'value'   => (float) $min_a,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}
		if ( null !== $max_a ) {
			$meta_query[] = array(
				'key'     => '_arch_prec_area_m2',
				'value'   => (float) $max_a,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}

		$args = array(
			'post_type'      => WP_MCP_AI_Architectural_Precedent_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
		);
		if ( ! empty( $meta_query ) ) {
			$meta_query['relation'] = 'AND';
			$args['meta_query']     = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$candidates = get_posts( $args );

		$query_vec = WP_MCP_AI_Architectural_Precedents_Engine::embed_text( $query );
		$mode      = is_array( $query_vec ) ? 'embedding' : 'keyword';

		$scored = array();
		foreach ( $candidates as $post ) {
			$score = 0.0;
			if ( 'embedding' === $mode ) {
				$cached = get_post_meta( $post->ID, '_arch_prec_embedding', true );
				if ( is_array( $cached ) && ! empty( $cached ) ) {
					$score = WP_MCP_AI_Architectural_Precedents_Engine::cosine( $query_vec, $cached );
				} else {
					// No cached embedding — fall through to keyword for this row only.
					$score = WP_MCP_AI_Architectural_Precedents_Engine::keyword_score(
						$query,
						WP_MCP_AI_Architectural_Precedents_Engine::build_corpus( $post )
					);
				}
			} else {
				$score = WP_MCP_AI_Architectural_Precedents_Engine::keyword_score(
					$query,
					WP_MCP_AI_Architectural_Precedents_Engine::build_corpus( $post )
				);
			}
			$scored[] = array(
				'post'  => $post,
				'score' => (float) $score,
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);

		$results = array();
		foreach ( array_slice( $scored, 0, $limit ) as $row ) {
			$post      = $row['post'];
			$features  = get_post_meta( $post->ID, '_arch_prec_key_features', true );
			$results[] = array(
				'id'                    => $post->ID,
				'title'                 => $post->post_title,
				'excerpt'               => $post->post_excerpt,
				'country_code'          => (string) get_post_meta( $post->ID, '_arch_prec_country_code', true ),
				'building_type'         => (string) get_post_meta( $post->ID, '_arch_prec_building_type', true ),
				'climate_zone'          => (string) get_post_meta( $post->ID, '_arch_prec_climate_zone', true ),
				'sustainability_rating' => (string) get_post_meta( $post->ID, '_arch_prec_sustainability_rating', true ),
				'year_completed'        => (int) get_post_meta( $post->ID, '_arch_prec_year_completed', true ),
				'area_m2'               => (float) get_post_meta( $post->ID, '_arch_prec_area_m2', true ),
				'key_features'          => is_array( $features ) ? $features : array(),
				'references_url'        => (string) get_post_meta( $post->ID, '_arch_prec_references_url', true ),
				'similarity'            => round( $row['score'], 4 ),
			);
		}

		return array(
			'success'         => true,
			'mode'            => $mode,
			'query'           => $query,
			'candidate_count' => count( $candidates ),
			'returned'        => count( $results ),
			'results'         => $results,
		);
	}
}
