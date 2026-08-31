<?php
/**
 * Clean Content Slash Command
 *
 * Content quality assurance with 3-phase detection (HIGH/MEDIUM/LOW certainty).
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clean Content Command Class
 *
 * Implements 3-phase content quality detection:
 * - Phase 1: Regex patterns (HIGH certainty) - Auto-fixable
 * - Phase 2: Content analysis (MEDIUM certainty) - Reportable
 * - Phase 3: AI review (LOW certainty) - Suggestions
 *
 * @since 1.2.0
 */
class SlashCommandCleanContent {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Execute clean-content command
	 *
	 * @param array $args    Positional arguments (post IDs or 'all').
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|WP_Error Command result or error.
	 */
	public function execute( $args, $flags, $context ) {
		$user_id = isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id();

		// Check permissions.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new \WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to clean content.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Parse arguments.
		$target = isset( $args[0] ) ? $args[0] : 'recent';

		// Parse flags.
		$dry_run   = isset( $flags['dry-run'] ) || isset( $flags['n'] );
		$auto_fix  = isset( $flags['auto-fix'] ) || isset( $flags['a'] );
		$phase     = isset( $flags['phase'] ) ? absint( $flags['phase'] ) : 0; // 0 = all phases.
		$limit     = isset( $flags['limit'] ) ? absint( $flags['limit'] ) : 10;
		$post_type = isset( $flags['post-type'] ) ? sanitize_text_field( $flags['post-type'] ) : 'post';
		$verbose   = isset( $flags['verbose'] ) || isset( $flags['v'] );

		// Get posts to check.
		$post_ids = $this->get_posts_to_check( $target, $post_type, $limit );

		if ( empty( $post_ids ) ) {
			return $this->format_response(
				'success',
				__( 'No posts to check.', 'nvoos-content-graph-ai-platform' ),
				array()
			);
		}

		// Process each post.
		$results = array(
			'total_posts'   => count( $post_ids ),
			'posts_checked' => 0,
			'posts_cleaned' => 0,
			'total_issues'  => 0,
			'issues_fixed'  => 0,
			'posts'         => array(),
		);

		foreach ( $post_ids as $post_id ) {
			$post_result = $this->check_post(
				$post_id,
				array(
					'phase'    => $phase,
					'auto_fix' => $auto_fix && ! $dry_run,
					'dry_run'  => $dry_run,
					'verbose'  => $verbose,
				)
			);

			$results['posts'][] = $post_result;
			++$results['posts_checked'];

			if ( ! empty( $post_result['issues'] ) ) {
				$results['total_issues'] += count( $post_result['issues'] );
			}

			if ( ! empty( $post_result['fixed'] ) ) {
				++$results['posts_cleaned'];
				$results['issues_fixed'] += count( $post_result['fixed'] );
			}
		}

		return $this->format_response(
			'success',
			sprintf(
				/* translators: 1: posts checked, 2: total issues */
				__( 'Checked %1$d post(s), found %2$d issue(s).', 'nvoos-content-graph-ai-platform' ),
				$results['posts_checked'],
				$results['total_issues']
			),
			$results
		);
	}

	/**
	 * Get posts to check
	 *
	 * @param string $target    Target (post ID, 'all', 'recent').
	 * @param string $post_type Post type.
	 * @param int    $limit     Limit.
	 * @return array Post IDs.
	 */
	private function get_posts_to_check( $target, $post_type, $limit ) {
		if ( is_numeric( $target ) ) {
			return array( absint( $target ) );
		}

		$args = array(
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'numberposts' => $limit,
			'orderby'     => 'modified',
			'order'       => 'DESC',
		);

		if ( 'all' === $target ) {
			$args['numberposts'] = -1;
		}

		$posts = get_posts( $args );
		return wp_list_pluck( $posts, 'ID' );
	}

	/**
	 * Check a single post
	 *
	 * @param int   $post_id Post ID.
	 * @param array $options Check options.
	 * @return array Check results.
	 */
	private function check_post( $post_id, $options ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array(
				'post_id' => $post_id,
				'status'  => 'error',
				'message' => __( 'Post not found', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$issues = array();
		$fixed  = array();

		// Phase 1: Regex patterns (HIGH certainty).
		if ( 0 === $options['phase'] || 1 === $options['phase'] ) {
			$phase1 = $this->phase1_regex_detection( $post, $options );
			$issues = array_merge( $issues, $phase1['issues'] );
			if ( ! empty( $phase1['fixed'] ) ) {
				$fixed = array_merge( $fixed, $phase1['fixed'] );
			}
		}

		// Phase 2: Content analysis (MEDIUM certainty).
		if ( 0 === $options['phase'] || 2 === $options['phase'] ) {
			$phase2 = $this->phase2_content_analysis( $post );
			$issues = array_merge( $issues, $phase2['issues'] );
		}

		// Phase 3: AI review (LOW certainty).
		if ( 0 === $options['phase'] || 3 === $options['phase'] ) {
			$phase3 = $this->phase3_ai_review( $post, $options );
			$issues = array_merge( $issues, $phase3['suggestions'] );
		}

		return array(
			'post_id' => $post_id,
			'title'   => get_the_title( $post_id ),
			'url'     => get_permalink( $post_id ),
			'issues'  => $issues,
			'fixed'   => $fixed,
			'status'  => empty( $issues ) ? 'clean' : 'needs_attention',
		);
	}

	/**
	 * Phase 1: Regex pattern detection (HIGH certainty)
	 *
	 * @param WP_Post $post    Post object.
	 * @param array   $options Check options.
	 * @return array Detection results.
	 */
	private function phase1_regex_detection( $post, $options ) {
		$content = $post->post_content;
		$issues  = array();
		$fixed   = array();

		// Pattern 1: Lorem ipsum placeholder text.
		if ( preg_match( '/lorem\s+ipsum/i', $content ) ) {
			$issues[] = array(
				'type'      => 'placeholder_text',
				'certainty' => 'HIGH',
				'message'   => __( 'Contains "Lorem ipsum" placeholder text', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => true,
			);

			if ( $options['auto_fix'] ) {
				$content = preg_replace( '/lorem\s+ipsum[^.!?]*[.!?]/i', '', $content );
				$fixed[] = 'Removed Lorem ipsum text';
			}
		}

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Regex character classes in a comment, not commented-out code.
		// Pattern 2: Draft markers ([TODO], [DRAFT], [TBD]).
		$draft_markers  = array( '\[TODO\]', '\[DRAFT\]', '\[TBD\]', '\[FIXME\]', '\[XXX\]' );
		$marker_pattern = '/' . implode( '|', $draft_markers ) . '/i';
		if ( preg_match( $marker_pattern, $content ) ) {
			$issues[] = array(
				'type'      => 'draft_markers',
				'certainty' => 'HIGH',
				'message'   => __( 'Contains draft markers like [TODO], [DRAFT]', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => true,
			);

			if ( $options['auto_fix'] ) {
				$content = preg_replace( $marker_pattern, '', $content );
				$fixed[] = 'Removed draft markers';
			}
		}

		// Pattern 3: Broken shortcodes.
		if ( preg_match( '/\[\/?[^\]]*$/m', $content ) ) {
			$issues[] = array(
				'type'      => 'broken_shortcodes',
				'certainty' => 'HIGH',
				'message'   => __( 'Contains broken/unclosed shortcodes', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => false,
			);
		}

		// Pattern 4: Empty HTML tags.
		if ( preg_match( '/<(?!br|hr|img|input|meta|link)[^>]+><\/[^>]+>/i', $content ) ) {
			$issues[] = array(
				'type'      => 'empty_tags',
				'certainty' => 'HIGH',
				'message'   => __( 'Contains empty HTML tags', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => true,
			);

			if ( $options['auto_fix'] ) {
				$content = preg_replace( '/<(?!br|hr|img|input|meta|link)([a-z][a-z0-9]*)\b[^>]*><\/\1>/i', '', $content );
				$fixed[] = 'Removed empty HTML tags';
			}
		}

		// Pattern 5: Multiple consecutive spaces.
		if ( preg_match( '/  +/', $content ) ) {
			$issues[] = array(
				'type'      => 'extra_spaces',
				'certainty' => 'HIGH',
				'message'   => __( 'Contains multiple consecutive spaces', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => true,
			);

			if ( $options['auto_fix'] ) {
				$content = preg_replace( '/  +/', ' ', $content );
				$fixed[] = 'Normalized spacing';
			}
		}

		// Pattern 6: WordPress default "Hello World" content.
		if ( stripos( $content, 'Welcome to WordPress' ) !== false ) {
			$issues[] = array(
				'type'      => 'default_content',
				'certainty' => 'HIGH',
				'message'   => __( 'Contains default WordPress content', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => false,
			);
		}

		// Apply fixes if any were made.
		if ( ! empty( $fixed ) && $options['auto_fix'] ) {
			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				)
			);
		}

		return array(
			'issues' => $issues,
			'fixed'  => $fixed,
		);
	}

	/**
	 * Phase 2: Content analysis (MEDIUM certainty)
	 *
	 * @param WP_Post $post Post object.
	 * @return array Analysis results.
	 */
	private function phase2_content_analysis( $post ) {
		$issues = array();

		// Check 1: Thin content (word count).
		$content_text = wp_strip_all_tags( $post->post_content );
		$word_count   = str_word_count( $content_text );

		if ( $word_count < 300 ) {
			$issues[] = array(
				'type'      => 'thin_content',
				'certainty' => 'MEDIUM',
				'message'   => sprintf(
					/* translators: %d: word count */
					__( 'Content is thin (%d words, minimum 300 recommended)', 'nvoos-content-graph-ai-platform' ),
					$word_count
				),
				'fixable'   => false,
			);
		}

		// Check 2: Readability issues (very long sentences).
		$sentences      = preg_split( '/[.!?]+/', $content_text );
		$long_sentences = 0;

		foreach ( $sentences as $sentence ) {
			$sentence_word_count = str_word_count( trim( $sentence ) );
			if ( $sentence_word_count > 30 ) {
				++$long_sentences;
			}
		}

		if ( $long_sentences > 3 ) {
			$issues[] = array(
				'type'      => 'readability',
				'certainty' => 'MEDIUM',
				'message'   => sprintf(
					/* translators: %d: number of long sentences */
					__( 'Found %d sentences over 30 words (may hurt readability)', 'nvoos-content-graph-ai-platform' ),
					$long_sentences
				),
				'fixable'   => false,
			);
		}

		// Check 3: Broken links.
		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches );
		$links        = isset( $matches[1] ) ? $matches[1] : array();
		$broken_links = 0;

		foreach ( $links as $url ) {
			// Only check internal links for broken.
			if ( strpos( $url, get_site_url() ) === 0 ) {
				$post_id = url_to_postid( $url );
				if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
					++$broken_links;
				}
			}
		}

		if ( $broken_links > 0 ) {
			$issues[] = array(
				'type'      => 'broken_links',
				'certainty' => 'MEDIUM',
				'message'   => sprintf(
					/* translators: %d: number of broken links */
					__( 'Found %d broken internal link(s)', 'nvoos-content-graph-ai-platform' ),
					$broken_links
				),
				'fixable'   => false,
			);
		}

		// Check 4: Missing meta description.
		$meta_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( empty( $meta_desc ) ) {
			$meta_desc = get_post_meta( $post->ID, 'rank_math_description', true );
		}

		if ( empty( $meta_desc ) ) {
			$issues[] = array(
				'type'      => 'missing_meta',
				'certainty' => 'MEDIUM',
				'message'   => __( 'Missing SEO meta description', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => false,
			);
		}

		// Check 5: Duplicate content (title too similar to excerpt).
		if ( ! empty( $post->post_excerpt ) ) {
			$title_words   = str_word_count( strtolower( $post->post_title ), 1 );
			$excerpt_words = str_word_count( strtolower( $post->post_excerpt ), 1 );
			$common_words  = array_intersect( $title_words, $excerpt_words );

			if ( count( $common_words ) / count( $title_words ) > 0.8 ) {
				$issues[] = array(
					'type'      => 'duplicate_content',
					'certainty' => 'MEDIUM',
					'message'   => __( 'Excerpt is too similar to title (80%+ word overlap)', 'nvoos-content-graph-ai-platform' ),
					'fixable'   => false,
				);
			}
		}

		return array( 'issues' => $issues );
	}

	/**
	 * Phase 3: AI review (LOW certainty)
	 *
	 * @param WP_Post $post    Post object.
	 * @param array   $options Check options.
	 * @return array Review results.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function phase3_ai_review( $post, $options ) {
		$suggestions = array();

		// For now, these are heuristic-based suggestions.
		// In a full implementation, these would use AI/ML models.

		// Suggestion 1: Brand voice consistency.
		$content = wp_strip_all_tags( $post->post_content );

		// Check for inconsistent tone indicators.
		$informal_words = array( "ain't", 'gonna', 'wanna', 'kinda', 'sorta', 'dunno' );
		$informal_count = 0;

		foreach ( $informal_words as $word ) {
			if ( stripos( $content, $word ) !== false ) {
				++$informal_count;
			}
		}

		if ( $informal_count > 2 ) {
			$suggestions[] = array(
				'type'      => 'brand_voice',
				'certainty' => 'LOW',
				'message'   => __( 'Content may be too informal for your brand voice', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => false,
			);
		}

		// Suggestion 2: Engagement quality.
		$question_count = substr_count( $content, '?' );
		$word_count     = str_word_count( $content );

		if ( $word_count > 500 && 0 === $question_count ) {
			$suggestions[] = array(
				'type'      => 'engagement',
				'certainty' => 'LOW',
				'message'   => __( 'Long content without questions may reduce engagement', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => false,
			);
		}

		// Suggestion 3: Tone analysis.
		$negative_words = array( 'unfortunately', 'problem', 'difficult', 'hard', 'confusing', 'complicated' );
		$negative_count = 0;

		foreach ( $negative_words as $word ) {
			if ( stripos( $content, $word ) !== false ) {
				++$negative_count;
			}
		}

		if ( $negative_count > 5 ) {
			$suggestions[] = array(
				'type'      => 'tone',
				'certainty' => 'LOW',
				'message'   => __( 'Content tone may be overly negative', 'nvoos-content-graph-ai-platform' ),
				'fixable'   => false,
			);
		}

		return array( 'suggestions' => $suggestions );
	}

	/**
	 * Format command response
	 *
	 * @param string $status  Response status.
	 * @param string $message User message.
	 * @param array  $data    Additional data.
	 * @return string Formatted response.
	 */
	private function format_response( $status, $message, $data = array() ) {
		$output = "## {$message}\n\n";

		if ( isset( $data['total_posts'] ) ) {
			$output .= sprintf(
				"**Summary:**\n- Posts checked: %d\n- Posts cleaned: %d\n- Total issues found: %d\n- Issues fixed: %d\n\n",
				$data['posts_checked'],
				$data['posts_cleaned'],
				$data['total_issues'],
				$data['issues_fixed']
			);
		}

		if ( isset( $data['posts'] ) && ! empty( $data['posts'] ) ) {
			$output .= "### Details\n\n";

			foreach ( $data['posts'] as $post_result ) {
				$output .= $this->format_post_result( $post_result );
			}
		}

		return $output;
	}

	/**
	 * Format individual post result
	 *
	 * @param array $result Post result data.
	 * @return string Formatted output.
	 */
	private function format_post_result( $result ) {
		$status_icons = array(
			'clean'           => '✅',
			'needs_attention' => '⚠️',
			'error'           => '❌',
		);

		$icon = isset( $status_icons[ $result['status'] ] ) ? $status_icons[ $result['status'] ] : '❓';

		$output = sprintf(
			"#### %s %s\n\n",
			$icon,
			esc_html( $result['title'] )
		);

		// Fixed issues.
		if ( ! empty( $result['fixed'] ) ) {
			$output .= "**Auto-fixed:**\n";
			foreach ( $result['fixed'] as $fix ) {
				$output .= sprintf( "- ✓ %s\n", esc_html( $fix ) );
			}
			$output .= "\n";
		}

		// Issues found.
		if ( ! empty( $result['issues'] ) ) {
			// Group by certainty.
			$by_certainty = array(
				'HIGH'   => array(),
				'MEDIUM' => array(),
				'LOW'    => array(),
			);

			foreach ( $result['issues'] as $issue ) {
				$certainty                    = $issue['certainty'];
				$by_certainty[ $certainty ][] = $issue;
			}

			foreach ( $by_certainty as $certainty => $issues ) {
				if ( empty( $issues ) ) {
					continue;
				}

				$certainty_label = array(
					'HIGH'   => '🔴 High Certainty Issues',
					'MEDIUM' => '🟡 Medium Certainty Issues',
					'LOW'    => '🟢 Low Certainty Suggestions',
				);

				$output .= sprintf( "**%s:**\n", $certainty_label[ $certainty ] );

				foreach ( $issues as $issue ) {
					$fixable_text = isset( $issue['fixable'] ) && $issue['fixable'] ? ' (auto-fixable)' : '';
					$output      .= sprintf(
						"- %s%s\n",
						esc_html( $issue['message'] ),
						$fixable_text
					);
				}
				$output .= "\n";
			}
		}

		if ( isset( $result['url'] ) ) {
			$output .= sprintf( "[View Post](%s)\n\n", esc_url( $result['url'] ) );
		}

		return $output;
	}
}
