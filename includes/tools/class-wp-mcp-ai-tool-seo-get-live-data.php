<?php
/**
 * Tool: seo_get_live_data — Reads live SEO metadata from active plugins for a post.
 *
 * Port of mcp-wordpress wp_seo_get_live_data tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Get Live Data — reads current SEO metadata for a single post.
 *
 * Pulls title, description, focus keyword, and canonical/robots values from
 * whichever SEO plugins are active (Rank Math, Yoast, AIOSEO, SEOPress) with
 * a core fallback, plus optional on-page analysis and rule-based
 * recommendations.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_SEO_Get_Live_Data implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_get_live_data';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SEO Live Data Reader', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reads live SEO metadata for a post from the active SEO plugins (Rank Math, Yoast, AIOSEO, SEOPress) with a core fallback, plus optional on-page analysis (word count, lengths, headings, alt text) and rule-based recommendations.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Checking what SEO values a post currently has before editing or reporting on them.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Changing values; use seo_meta_optimizer or seo_bulk_update_metadata. For plugin detection only, use seo_test_integration.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_rankmath_seo', 'seo_meta_optimizer', 'seo_test_integration', 'seo_analyze_content' ),
			'notes'           => __( 'Only sections for detected plugins are returned. When no SEO plugin is active, a core fallback (post title and excerpt) is provided.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'                 => array(
					'type'        => 'integer',
					'description' => __( 'ID of the post to read SEO data for.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'include_analysis'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include basic on-page metrics (word count, lengths, H1 count, missing alt).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'include_recommendations' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include simple rule-based SEO recommendations.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'post_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires user capabilities.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to use this tool.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'wp_mcp_ai_post_not_found', __( 'The requested post could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_post', $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read SEO data for this post.', 'mcp-ai-wpoos' ) );
		}

		$include_analysis        = ! empty( $arguments['include_analysis'] );
		$include_recommendations = ! empty( $arguments['include_recommendations'] );

		$detected = $this->detect_seo_plugins();
		$seo_data = array();

		if ( $detected['rankmath'] ) {
			$seo_data['rank_math'] = array(
				'title'         => sanitize_text_field( (string) get_post_meta( $post_id, 'rank_math_title', true ) ),
				'description'   => sanitize_text_field( (string) get_post_meta( $post_id, 'rank_math_description', true ) ),
				'focus_keyword' => sanitize_text_field( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ),
				'canonical_url' => esc_url_raw( (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ) ),
				'robots'        => sanitize_text_field( (string) get_post_meta( $post_id, 'rank_math_robots', true ) ),
			);
		}

		if ( $detected['yoast'] ) {
			$seo_data['yoast'] = array(
				'title'         => sanitize_text_field( (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ) ),
				'description'   => sanitize_text_field( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ),
				'focus_keyword' => sanitize_text_field( (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ),
				'canonical_url' => esc_url_raw( (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ) ),
			);
		}

		if ( $detected['aioseo'] ) {
			$seo_data['aioseo'] = array(
				'title'         => sanitize_text_field( (string) get_post_meta( $post_id, '_aioseo_title', true ) ),
				'description'   => sanitize_text_field( (string) get_post_meta( $post_id, '_aioseo_description', true ) ),
				'focus_keyword' => sanitize_text_field( (string) get_post_meta( $post_id, '_aioseo_keywords', true ) ),
			);
		}

		if ( $detected['seopress'] ) {
			$seo_data['seopress'] = array(
				'title'         => sanitize_text_field( (string) get_post_meta( $post_id, '_seopress_titles_title', true ) ),
				'description'   => sanitize_text_field( (string) get_post_meta( $post_id, '_seopress_titles_desc', true ) ),
				'focus_keyword' => sanitize_text_field( (string) get_post_meta( $post_id, '_seopress_analysis_target_kw', true ) ),
			);
		}

		$fallback_title       = get_the_title( $post );
		$fallback_description = wp_trim_words( wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ), 32, '' );

		$seo_data['core_fallback'] = array(
			'title'       => esc_html( $fallback_title ),
			'description' => esc_html( $fallback_description ),
		);

		// Effective values for analysis/recommendations: first plugin value wins, fallback last.
		$effective_title       = $fallback_title;
		$effective_description = $fallback_description;
		foreach ( $seo_data as $section ) {
			if ( isset( $section['title'] ) && '' !== $section['title'] ) {
				$effective_title = wp_strip_all_tags( $section['title'] );
				break;
			}
		}
		foreach ( $seo_data as $section ) {
			if ( isset( $section['description'] ) && '' !== $section['description'] ) {
				$effective_description = wp_strip_all_tags( $section['description'] );
				break;
			}
		}

		$analysis = null;
		if ( $include_analysis ) {
			$analysis = array(
				'word_count'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				'title_length'      => mb_strlen( $effective_title ),
				'meta_length'       => mb_strlen( $effective_description ),
				'h1_count'          => preg_match_all( '/<h1[^>]*>/i', $post->post_content, $h1_matches ),
				'image_alt_missing' => preg_match_all( '/<img\b(?![^>]*\balt\s*=)[^>]*>/i', $post->post_content, $alt_matches ),
			);
		}

		$recommendations = null;
		if ( $include_recommendations ) {
			$recommendations = $this->build_recommendations( $post, $effective_title, $effective_description );
		}

		$message = sprintf(
			/* translators: %s: post title */
			__( 'Live SEO data read for "%s".', 'mcp-ai-wpoos' ),
			esc_html( get_the_title( $post ) )
		);

		$response = array(
			'message'  => $message,
			'seo_data' => $seo_data,
		);

		if ( null !== $analysis ) {
			$response['analysis'] = $analysis;
		}

		if ( null !== $recommendations ) {
			$response['recommendations'] = $recommendations;
		}

		return $response;
	}

	/**
	 * Builds rule-based SEO recommendations.
	 *
	 * @since 1.1.87
	 *
	 * @param WP_Post $post                  The post being inspected.
	 * @param string  $effective_title       Effective title (plugin or fallback).
	 * @param string  $effective_description Effective description (plugin or fallback).
	 * @return array Recommendation strings.
	 */
	private function build_recommendations( $post, $effective_title, $effective_description ) {
		$recommendations = array();

		$title_length = mb_strlen( $effective_title );
		if ( $title_length < 30 ) {
			$recommendations[] = sprintf(
				/* translators: %d: current title length */
				__( 'Title is short (%d characters); aim for 30-60.', 'mcp-ai-wpoos' ),
				$title_length
			);
		} elseif ( $title_length > 60 ) {
			$recommendations[] = sprintf(
				/* translators: %d: current title length */
				__( 'Title is long (%d characters); aim for 30-60.', 'mcp-ai-wpoos' ),
				$title_length
			);
		}

		$meta_length = mb_strlen( $effective_description );
		if ( 0 === $meta_length ) {
			$recommendations[] = __( 'No meta description is set; add one of 50-160 characters.', 'mcp-ai-wpoos' );
		} elseif ( $meta_length > 160 ) {
			$recommendations[] = sprintf(
				/* translators: %d: current description length */
				__( 'Meta description is %d characters; trim to 160 or less.', 'mcp-ai-wpoos' ),
				$meta_length
			);
		}

		$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
		if ( $word_count < 300 ) {
			$recommendations[] = sprintf(
				/* translators: %d: word count */
				__( 'Content is thin (%d words); 300+ words are recommended.', 'mcp-ai-wpoos' ),
				$word_count
			);
		}

		$h1_count = preg_match_all( '/<h1[^>]*>/i', $post->post_content, $h1_matches );
		if ( 0 === $h1_count ) {
			$recommendations[] = __( 'The post has no H1 heading.', 'mcp-ai-wpoos' );
		} elseif ( $h1_count > 1 ) {
			$recommendations[] = __( 'The post has multiple H1 headings; keep one.', 'mcp-ai-wpoos' );
		}

		$alt_missing = preg_match_all( '/<img\b(?![^>]*\balt\s*=)[^>]*>/i', $post->post_content, $alt_matches );
		if ( $alt_missing > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of images missing alt text */
				__( '%d image(s) are missing alt text.', 'mcp-ai-wpoos' ),
				$alt_missing
			);
		}

		return $recommendations;
	}

	/**
	 * Detects active SEO plugins.
	 *
	 * @since 1.1.87
	 *
	 * @return array Detection map keyed by plugin slug.
	 */
	private function detect_seo_plugins() {
		$yoast = defined( 'WPSEO_VERSION' );
		if ( ! $yoast && function_exists( 'is_plugin_active' ) ) {
			$yoast = is_plugin_active( 'wordpress-seo/wp-seo.php' );
		}

		return array(
			'rankmath' => class_exists( 'RankMath' ) || function_exists( 'rank_math' ),
			'yoast'    => $yoast,
			'aioseo'   => function_exists( 'aioseo' ),
			'seopress' => function_exists( 'seopress_get_service' ) || defined( 'SEOPRESS_VERSION' ),
		);
	}
}
