<?php
/**
 * Deposition Summary Generator Tool
 *
 * Generates structured summaries from deposition transcript text, identifying
 * key admissions, contradictions, and follow-up questions.
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
 * Generates structured deposition summaries with key admissions and contradictions.
 */
class WP_MCP_AI_Tool_LF_Deposition_Summary_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_deposition_summary_generator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Deposition Summary Generator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates structured summaries from deposition transcript text. Identifies key admissions, contradictions, and suggested follow-up questions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'transcript_text' => array(
					'type'        => 'string',
					'description' => __( 'Full text of the deposition transcript.', 'mcp-ai-wpoos-pro' ),
				),
				'deponent_name'   => array(
					'type'        => 'string',
					'description' => __( 'Name of the deponent (person being deposed).', 'mcp-ai-wpoos-pro' ),
				),
				'matter_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Optional WordPress post ID of the related matter.', 'mcp-ai-wpoos-pro' ),
				),
				'focus_topics'    => array(
					'type'        => 'array',
					'description' => __( 'Topics to focus the summary on.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
			),
			'required'   => array( 'transcript_text', 'deponent_name' ),
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

		$transcript   = isset( $arguments['transcript_text'] ) ? sanitize_textarea_field( $arguments['transcript_text'] ) : '';
		$deponent     = isset( $arguments['deponent_name'] ) ? sanitize_text_field( $arguments['deponent_name'] ) : '';
		$matter_id    = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$focus_topics = array();
		if ( ! empty( $arguments['focus_topics'] ) && is_array( $arguments['focus_topics'] ) ) {
			$focus_topics = array_map( 'sanitize_text_field', $arguments['focus_topics'] );
		}

		if ( empty( $transcript ) || empty( $deponent ) ) {
			return new WP_Error( 'missing_required', __( 'Transcript text and deponent name are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$lower      = strtolower( $transcript );
		$sentences  = preg_split( '/(?<=[.?!])\s+/', $transcript, -1, PREG_SPLIT_NO_EMPTY );
		$word_count = str_word_count( $transcript );

		// Build summary sections by splitting into rough page-equivalent blocks.
		$block_size       = max( 1, (int) ceil( count( $sentences ) / 5 ) );
		$summary_sections = array();
		$chunks           = array_chunk( $sentences, $block_size );
		foreach ( $chunks as $idx => $chunk ) {
			$section_text = implode( ' ', $chunk );
			$section_word = str_word_count( $section_text );
			$topic_hits   = array();
			if ( ! empty( $focus_topics ) ) {
				foreach ( $focus_topics as $topic ) {
					if ( false !== stripos( $section_text, $topic ) ) {
						$topic_hits[] = $topic;
					}
				}
			}
			$summary_sections[] = array(
				'section'    => $idx + 1,
				'word_count' => $section_word,
				'excerpt'    => wp_trim_words( $section_text, 40, '...' ),
				'topic_hits' => $topic_hits,
			);
		}

		// Detect key admissions — statements containing admission-related language.
		$admission_patterns = array(
			'i admit',
			'i acknowledge',
			'that is correct',
			'yes, i did',
			'i agree',
			'i concede',
			'i was responsible',
			'i confirm',
			'that\'s true',
			'i was aware',
			'i knew',
			'i authorized',
		);
		$key_admissions     = array();
		foreach ( $sentences as $idx => $sentence ) {
			$s_lower = strtolower( $sentence );
			foreach ( $admission_patterns as $pattern ) {
				if ( false !== strpos( $s_lower, $pattern ) ) {
					$key_admissions[] = array(
						'statement' => trim( $sentence ),
						'pattern'   => $pattern,
						'position'  => $idx + 1,
					);
					break;
				}
			}
		}

		// Detect contradictions — look for negation phrases near similar topics.
		$contradiction_markers = array(
			'i never',
			'i don\'t recall',
			'i don\'t remember',
			'that\'s not true',
			'i didn\'t',
			'i deny',
			'that is incorrect',
			'no, i did not',
			'i was not',
			'i wasn\'t',
			'i have no knowledge',
		);
		$contradictions        = array();
		foreach ( $sentences as $idx => $sentence ) {
			$s_lower = strtolower( $sentence );
			foreach ( $contradiction_markers as $marker ) {
				if ( false !== strpos( $s_lower, $marker ) ) {
					// Check if an admission exists on a similar topic.
					foreach ( $key_admissions as $admission ) {
						$common = array_intersect(
							str_word_count( strtolower( $admission['statement'] ), 1 ),
							str_word_count( $s_lower, 1 )
						);
						// Filter out very common words.
						$common = array_diff( $common, array( 'i', 'the', 'a', 'is', 'was', 'did', 'do', 'that', 'it', 'to', 'and', 'of', 'in', 'not', 'no', 'yes' ) );
						if ( count( $common ) >= 2 ) {
							$contradictions[] = array(
								'denial_statement'    => trim( $sentence ),
								'admission_statement' => $admission['statement'],
								'overlapping_terms'   => array_values( $common ),
								'denial_position'     => $idx + 1,
								'admission_position'  => $admission['position'],
							);
							break;
						}
					}
					break;
				}
			}
		}

		// Generate follow-up questions based on admissions and contradictions.
		$follow_up_questions = array();
		foreach ( array_slice( $key_admissions, 0, 3 ) as $admission ) {
			$follow_up_questions[] = sprintf(
				/* translators: 1: deponent name, 2: statement excerpt */
				__( 'Can you elaborate on your statement: "%1$s"? What were the specific circumstances?', 'mcp-ai-wpoos-pro' ),
				wp_trim_words( $admission['statement'], 15, '...' )
			);
		}
		foreach ( array_slice( $contradictions, 0, 3 ) as $contradiction ) {
			$follow_up_questions[] = sprintf(
				/* translators: 1: denial statement excerpt */
				__( 'You previously stated the opposite. Can you explain the discrepancy regarding: "%1$s"?', 'mcp-ai-wpoos-pro' ),
				wp_trim_words( $contradiction['denial_statement'], 15, '...' )
			);
		}
		if ( empty( $follow_up_questions ) && ! empty( $focus_topics ) ) {
			foreach ( array_slice( $focus_topics, 0, 3 ) as $topic ) {
				$follow_up_questions[] = sprintf(
					/* translators: 1: deponent name, 2: topic */
					__( 'Please describe in detail your involvement with %1$s regarding %2$s.', 'mcp-ai-wpoos-pro' ),
					$deponent,
					$topic
				);
			}
		}

		$data = array(
			'deponent_name'       => $deponent,
			'word_count'          => $word_count,
			'sentence_count'      => count( $sentences ),
			'summary_sections'    => $summary_sections,
			'key_admissions'      => $key_admissions,
			'contradictions'      => $contradictions,
			'follow_up_questions' => $follow_up_questions,
		);

		if ( $matter_id > 0 ) {
			$data['matter_id'] = $matter_id;
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: deponent name, 2: admissions count, 3: contradictions count */
				__( 'Deposition summary for %1$s generated. Found %2$d key admissions and %3$d potential contradictions. ', 'mcp-ai-wpoos-pro' ),
				$deponent,
				count( $key_admissions ),
				count( $contradictions )
			) . self::DISCLAIMER,
			'data'       => $data,
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
