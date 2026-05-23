<?php
/**
 * Translation Quality Check Tool
 *
 * Validate translation completeness, consistency, and quality with automated checks.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Translation_Quality_Check tool.
 */
class WP_MCP_AI_Tool_Translation_Quality_Check implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return ! empty( $settings['enable_multilingual_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_multilingual_toolkit'] ) ) {
			return __( 'Multi-language Content toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Translation Quality Check tool is not available.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'translation_quality_check';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Translation Quality Check', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Validate translation completeness, consistency, and quality with automated checks.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'        => array(
					'type'        => 'integer',
					'description' => 'Translated post ID to check',
				),
				'source_post_id' => array(
					'type'        => 'integer',
					'description' => 'Original post ID',
				),
				'checks'         => array(
					'type'        => 'array',
					'description' => 'Checks to perform: completeness, consistency, formatting',
					'default'     => array( 'all' ),
				),
			),
			'required'   => array(),
		);
	}


	/**

	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'content'     => true,
			'translation' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$post_id        = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$source_post_id = isset( $arguments['source_post_id'] ) ? absint( $arguments['source_post_id'] ) : 0;
		$checks         = isset( $arguments['checks'] ) && is_array( $arguments['checks'] ) ? $arguments['checks'] : array( 'all' );
		$run_all        = in_array( 'all', $checks, true );

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-language-detection-service.php';
		$lang_service = new WP_MCP_AI_Language_Detection_Service();

		$results = array();
		$score   = 100;

		// ── Completeness check ────────────────────────────────────────────────
		if ( $run_all || in_array( 'completeness', $checks, true ) ) {
			$translated_text = '';
			$source_text     = '';

			if ( $post_id > 0 ) {
				$translated_post = get_post( $post_id );
				if ( $translated_post ) {
					$translated_text = wp_strip_all_tags( $translated_post->post_content );
				}
			}

			if ( $source_post_id > 0 ) {
				$source_post = get_post( $source_post_id );
				if ( $source_post ) {
					$source_text = wp_strip_all_tags( $source_post->post_content );
				}
			}

			if ( '' !== $translated_text && '' !== $source_text ) {
				$source_words      = str_word_count( $source_text );
				$translated_words  = str_word_count( $translated_text );
				$ratio             = $source_words > 0 ? $translated_words / $source_words : 1;
				$completeness_pass = $ratio >= 0.5 && $ratio <= 2.5;

				if ( ! $completeness_pass ) {
					$score -= 30;
				}

				$results[] = array(
					'check'   => 'completeness',
					'passed'  => $completeness_pass,
					'details' => sprintf(
						/* translators: 1: source word count, 2: translation word count, 3: ratio */
						__( 'Source: %1$d words, Translation: %2$d words (ratio: %3$.2f).', 'mcp-ai-wpoos-pro' ),
						$source_words,
						$translated_words,
						$ratio
					),
				);
			}
		}

		// ── Language consistency check ─────────────────────────────────────────
		if ( $run_all || in_array( 'consistency', $checks, true ) ) {
			$translated_text = '';

			if ( $post_id > 0 ) {
				$translated_post = get_post( $post_id );
				if ( $translated_post ) {
					$translated_text = wp_strip_all_tags( $translated_post->post_content . ' ' . $translated_post->post_title );
				}
			}

			if ( '' !== $translated_text ) {
				$detected         = $lang_service->detect_language( $translated_text );
				$expected_lang    = get_post_meta( $post_id, '_translation_language', true );
				$consistency_pass = true;

				if ( $expected_lang && 'und' !== $detected['code'] ) {
					$consistency_pass = strtolower( $detected['code'] ) === strtolower( $expected_lang );
					if ( ! $consistency_pass ) {
						$score -= 25;
					}
				}

				$results[] = array(
					'check'   => 'consistency',
					'passed'  => $consistency_pass,
					'details' => sprintf(
						/* translators: 1: detected language, 2: confidence */
						__( 'Detected language: %1$s (confidence: %2$.0f%%).', 'mcp-ai-wpoos-pro' ),
						$detected['name'],
						$detected['confidence'] * 100
					),
				);
			}
		}

		// ── Formatting / HTML tags check ───────────────────────────────────────
		if ( $run_all || in_array( 'formatting', $checks, true ) ) {
			$pass    = true;
			$details = '';

			if ( $post_id > 0 && $source_post_id > 0 ) {
				$source_post     = get_post( $source_post_id );
				$translated_post = get_post( $post_id );

				if ( $source_post && $translated_post ) {
					// Count HTML tags in each.
					preg_match_all( '/<[^>]+>/', $source_post->post_content, $src_tags );
					preg_match_all( '/<[^>]+>/', $translated_post->post_content, $trs_tags );

					$src_count = count( $src_tags[0] );
					$trs_count = count( $trs_tags[0] );

					$pass = 0 === $src_count || abs( $src_count - $trs_count ) <= max( 2, (int) ( $src_count * 0.2 ) );

					if ( ! $pass ) {
						$score -= 20;
					}

					$details = sprintf(
						/* translators: 1: source tag count, 2: translation tag count */
						__( 'Source HTML tags: %1$d, Translation HTML tags: %2$d.', 'mcp-ai-wpoos-pro' ),
						$src_count,
						$trs_count
					);
				}
			} else {
				$details = __( 'Provide post_id and source_post_id for formatting check.', 'mcp-ai-wpoos-pro' );
			}

			$results[] = array(
				'check'   => 'formatting',
				'passed'  => $pass,
				'details' => $details,
			);
		}

		$score = max( 0, min( 100, $score ) );

		return array(
			'success'          => true,
			'checks_performed' => count( $results ),
			'results'          => $results,
			'overall_score'    => $score,
			'rating'           => $score >= 80 ? 'good' : ( $score >= 50 ? 'fair' : 'poor' ),
			'message'          => sprintf(
				/* translators: %d: quality score */
				__( 'Translation quality score: %d/100.', 'mcp-ai-wpoos-pro' ),
				$score
			),
		);
	}
}
