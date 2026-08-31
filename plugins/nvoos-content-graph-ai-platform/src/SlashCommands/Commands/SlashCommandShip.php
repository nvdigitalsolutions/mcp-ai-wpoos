<?php
/**
 * Ship Slash Command
 *
 * Automated content review, optimization, and publishing workflow.
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
 * Ship Command Class
 *
 * Implements content publishing workflow:
 * 1. Pre-flight - Check content readiness
 * 2. SEO Check - Verify optimization
 * 3. Quality Review - Grammar, readability
 * 4. Image Optimization - Featured images, alt text
 * 5. Internal Linking - Add relevant links
 * 6. Schedule/Publish - Deploy content
 *
 * @since 1.2.0
 */
class SlashCommandShip {
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
	 * Execute ship command
	 *
	 * @param array $args    Positional arguments (post IDs).
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|WP_Error Command result or error.
	 */
	public function execute( $args, $flags, $context ) {
		$user_id = isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id();

		// Check permissions.
		if ( ! user_can( $user_id, 'publish_posts' ) ) {
			return new \WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to publish posts.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Parse arguments - post ID(s) to ship.
		$post_ids = array();
		if ( ! empty( $args[0] ) ) {
			$post_ids = array_map( 'absint', is_array( $args[0] ) ? $args[0] : array( $args[0] ) );
		}

		// Parse flags.
		$dry_run       = isset( $flags['dry-run'] ) || isset( $flags['n'] );
		$skip_checks   = isset( $flags['skip-checks'] ) || isset( $flags['s'] );
		$schedule_date = isset( $flags['schedule'] ) ? sanitize_text_field( $flags['schedule'] ) : null;
		$skip_seo      = isset( $flags['skip-seo'] );
		$skip_images   = isset( $flags['skip-images'] );
		$skip_links    = isset( $flags['skip-links'] );
		$auto_publish  = isset( $flags['publish'] ) || isset( $flags['p'] );

		// If no post IDs provided, find posts ready to ship.
		if ( empty( $post_ids ) ) {
			$post_ids = $this->find_ready_posts( $user_id );

			if ( empty( $post_ids ) ) {
				return $this->format_response(
					'info',
					__( 'No posts ready to ship. All clear!', 'nvoos-content-graph-ai-platform' ),
					array()
				);
			}
		}

		// Process each post.
		$results = array();
		foreach ( $post_ids as $post_id ) {
			$post_result = $this->process_post(
				$post_id,
				array(
					'dry_run'      => $dry_run,
					'skip_checks'  => $skip_checks,
					'skip_seo'     => $skip_seo,
					'skip_images'  => $skip_images,
					'skip_links'   => $skip_links,
					'auto_publish' => $auto_publish,
					'schedule'     => $schedule_date,
					'user_id'      => $user_id,
				)
			);

			$results[] = $post_result;
		}

		return $this->format_response(
			'success',
			sprintf(
				/* translators: %d: number of posts */
				__( 'Processed %d post(s) for shipping.', 'nvoos-content-graph-ai-platform' ),
				count( $results )
			),
			array(
				'results' => $results,
				'dry_run' => $dry_run,
			)
		);
	}

	/**
	 * Find posts ready to ship
	 *
	 * @param int $user_id User ID.
	 * @return array Post IDs.
	 */
	private function find_ready_posts( $user_id ) {
		// Find draft or pending posts by current user.
		$posts = get_posts(
			array(
				'post_status' => array( 'draft', 'pending' ),
				'post_type'   => 'post',
				'author'      => $user_id,
				'numberposts' => 5,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			)
		);

		return wp_list_pluck( $posts, 'ID' );
	}

	/**
	 * Process a single post through the shipping workflow
	 *
	 * @param int   $post_id Post ID.
	 * @param array $options Processing options.
	 * @return array Processing result.
	 */
	private function process_post( $post_id, $options ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array(
				'post_id' => $post_id,
				'status'  => 'error',
				'message' => __( 'Post not found.', 'nvoos-content-graph-ai-platform' ),
				'checks'  => array(),
			);
		}

		$checks = array();

		// Phase 1: Pre-flight checks.
		if ( ! $options['skip_checks'] ) {
			$checks['preflight'] = $this->run_preflight_checks( $post );
		}

		// Phase 2: SEO verification.
		if ( ! $options['skip_seo'] && ! $options['skip_checks'] ) {
			$checks['seo'] = $this->run_seo_checks( $post );
		}

		// Phase 3: Quality review.
		if ( ! $options['skip_checks'] ) {
			$checks['quality'] = $this->run_quality_checks( $post );
		}

		// Phase 4: Image optimization.
		if ( ! $options['skip_images'] && ! $options['skip_checks'] ) {
			$checks['images'] = $this->run_image_checks( $post );
		}

		// Phase 5: Internal linking.
		if ( ! $options['skip_links'] && ! $options['skip_checks'] ) {
			$checks['links'] = $this->suggest_internal_links( $post );
		}

		// Calculate overall readiness score.
		$readiness = $this->calculate_readiness( $checks );

		// Phase 6: Publishing.
		$publish_result = null;
		if ( ! $options['dry_run'] && $options['auto_publish'] && $readiness['score'] >= 70 ) {
			$publish_result = $this->publish_post( $post, $options );
		}

		return array(
			'post_id'        => $post_id,
			'title'          => get_the_title( $post_id ),
			'status'         => $readiness['status'],
			'readiness'      => $readiness,
			'checks'         => $checks,
			'publish_result' => $publish_result,
		);
	}

	/**
	 * Run pre-flight checks
	 *
	 * @param WP_Post $post Post object.
	 * @return array Check results.
	 */
	private function run_preflight_checks( $post ) {
		$checks = array(
			'has_featured_image' => false,
			'has_categories'     => false,
			'has_excerpt'        => false,
			'word_count'         => 0,
			'issues'             => array(),
		);

		// Check featured image.
		if ( has_post_thumbnail( $post->ID ) ) {
			$checks['has_featured_image'] = true;
		} else {
			$checks['issues'][] = __( 'Missing featured image', 'nvoos-content-graph-ai-platform' );
		}

		// Check categories.
		$categories = wp_get_post_categories( $post->ID );
		if ( ! empty( $categories ) ) {
			$checks['has_categories'] = true;
		} else {
			$checks['issues'][] = __( 'No categories assigned', 'nvoos-content-graph-ai-platform' );
		}

		// Check excerpt.
		if ( ! empty( $post->post_excerpt ) ) {
			$checks['has_excerpt'] = true;
		} else {
			$checks['issues'][] = __( 'Missing excerpt', 'nvoos-content-graph-ai-platform' );
		}

		// Word count.
		$content_text         = wp_strip_all_tags( $post->post_content );
		$checks['word_count'] = str_word_count( $content_text );

		if ( $checks['word_count'] < 300 ) {
			$checks['issues'][] = sprintf(
				/* translators: %d: word count */
				__( 'Content too short (%d words, minimum 300 recommended)', 'nvoos-content-graph-ai-platform' ),
				$checks['word_count']
			);
		}

		$checks['passed'] = empty( $checks['issues'] );

		return $checks;
	}

	/**
	 * Run SEO checks
	 *
	 * @param WP_Post $post Post object.
	 * @return array Check results.
	 */
	private function run_seo_checks( $post ) {
		$checks = array(
			'has_meta_title'       => false,
			'has_meta_description' => false,
			'has_focus_keyword'    => false,
			'seo_plugin'           => $this->detect_seo_plugin(),
			'issues'               => array(),
		);

		// Check for Yoast SEO meta.
		$yoast_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
		$yoast_desc  = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );

		// Check for Rank Math meta.
		$rank_math_title = get_post_meta( $post->ID, 'rank_math_title', true );
		$rank_math_desc  = get_post_meta( $post->ID, 'rank_math_description', true );

		// Meta title check.
		if ( ! empty( $yoast_title ) || ! empty( $rank_math_title ) ) {
			$checks['has_meta_title'] = true;
		} else {
			$checks['issues'][] = __( 'Missing SEO meta title', 'nvoos-content-graph-ai-platform' );
		}

		// Meta description check.
		if ( ! empty( $yoast_desc ) || ! empty( $rank_math_desc ) ) {
			$checks['has_meta_description'] = true;
		} else {
			$checks['issues'][] = __( 'Missing SEO meta description', 'nvoos-content-graph-ai-platform' );
		}

		// Focus keyword check (Yoast).
		$focus_keyword = get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
		if ( ! empty( $focus_keyword ) ) {
			$checks['has_focus_keyword'] = true;
		}

		$checks['passed'] = empty( $checks['issues'] );

		return $checks;
	}

	/**
	 * Run quality checks
	 *
	 * @param WP_Post $post Post object.
	 * @return array Check results.
	 */
	private function run_quality_checks( $post ) {
		$checks = array(
			'readability_score'  => 0,
			'has_headings'       => false,
			'has_images'         => false,
			'sentence_length_ok' => true,
			'issues'             => array(),
		);

		$content = $post->post_content;

		// Check for headings.
		if ( preg_match( '/<h[2-6][^>]*>/i', $content ) ) {
			$checks['has_headings'] = true;
		} else {
			$checks['issues'][] = __( 'No headings (H2-H6) found in content', 'nvoos-content-graph-ai-platform' );
		}

		// Check for images in content.
		if ( preg_match( '/<img[^>]+>/i', $content ) ) {
			$checks['has_images'] = true;
		} else {
			$checks['issues'][] = __( 'No images in content', 'nvoos-content-graph-ai-platform' );
		}

		// Simple readability check (Flesch Reading Ease approximation).
		$text           = wp_strip_all_tags( $content );
		$word_count     = str_word_count( $text );
		$sentence_count = preg_match_all( '/[.!?]+/', $text, $matches );
		$sentence_count = max( 1, $sentence_count ); // Avoid division by zero.

		$avg_sentence_length = $word_count / $sentence_count;

		if ( $avg_sentence_length > 20 ) {
			$checks['sentence_length_ok'] = false;
			$checks['issues'][]           = sprintf(
				/* translators: %d: average sentence length */
				__( 'Sentences too long (avg %d words, keep under 20 for readability)', 'nvoos-content-graph-ai-platform' ),
				round( $avg_sentence_length )
			);
		}

		// Calculate readability score (0-100, higher is better).
		$checks['readability_score'] = min( 100, max( 0, 100 - ( $avg_sentence_length * 2 ) ) );

		$checks['passed'] = empty( $checks['issues'] );

		return $checks;
	}

	/**
	 * Run image checks
	 *
	 * @param WP_Post $post Post object.
	 * @return array Check results.
	 */
	private function run_image_checks( $post ) {
		$checks = array(
			'images_checked'   => 0,
			'missing_alt_text' => 0,
			'issues'           => array(),
		);

		// Check featured image alt text.
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id ) {
			++$checks['images_checked'];
			$alt = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
			if ( empty( $alt ) ) {
				++$checks['missing_alt_text'];
				$checks['issues'][] = __( 'Featured image missing alt text', 'nvoos-content-graph-ai-platform' );
			}
		}

		// Check content images.
		preg_match_all( '/<img[^>]+class="[^"]*wp-image-(\d+)[^"]*"[^>]*>/i', $post->post_content, $matches );
		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $image_id ) {
				++$checks['images_checked'];
				$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
				if ( empty( $alt ) ) {
					++$checks['missing_alt_text'];
				}
			}
		}

		if ( $checks['missing_alt_text'] > 0 ) {
			$checks['issues'][] = sprintf(
				/* translators: %d: number of images */
				__( '%d image(s) missing alt text', 'nvoos-content-graph-ai-platform' ),
				$checks['missing_alt_text']
			);
		}

		$checks['passed'] = empty( $checks['issues'] );

		return $checks;
	}

	/**
	 * Suggest internal links
	 *
	 * @param WP_Post $post Post object.
	 * @return array Link suggestions.
	 */
	private function suggest_internal_links( $post ) {
		$suggestions = array(
			'found_links'     => 0,
			'suggested_links' => array(),
			'issues'          => array(),
		);

		// Count existing internal links.
		$site_url = get_site_url();
		preg_match_all( '/<a[^>]+href="' . preg_quote( $site_url, '/' ) . '[^"]*"[^>]*>/i', $post->post_content, $matches );
		$suggestions['found_links'] = count( $matches[0] );

		if ( $suggestions['found_links'] < 2 ) {
			$suggestions['issues'][] = sprintf(
				/* translators: %d: number of internal links */
				__( 'Only %d internal link(s) found (recommend at least 2-3)', 'nvoos-content-graph-ai-platform' ),
				$suggestions['found_links']
			);

			// Find related posts to suggest.
			$categories = wp_get_post_categories( $post->ID );
			if ( ! empty( $categories ) ) {
				$related = get_posts(
					array(
						'category__in' => $categories,
						'post__not_in' => array( $post->ID ),
						'numberposts'  => 3,
						'orderby'      => 'date',
					)
				);

				foreach ( $related as $related_post ) {
					$suggestions['suggested_links'][] = array(
						'title' => get_the_title( $related_post->ID ),
						'url'   => get_permalink( $related_post->ID ),
					);
				}
			}
		}

		$suggestions['passed'] = empty( $suggestions['issues'] );

		return $suggestions;
	}

	/**
	 * Calculate readiness score
	 *
	 * @param array $checks All check results.
	 * @return array Readiness data.
	 */
	private function calculate_readiness( $checks ) {
		$score      = 0;
		$max_score  = 0;
		$all_issues = array();

		// Weighting: Preflight (30%), SEO (30%), Quality (25%), Images (10%), Links (5%).
		$weights = array(
			'preflight' => 30,
			'seo'       => 30,
			'quality'   => 25,
			'images'    => 10,
			'links'     => 5,
		);

		foreach ( $weights as $check_type => $weight ) {
			$max_score += $weight;

			if ( isset( $checks[ $check_type ] ) ) {
				$check = $checks[ $check_type ];

				// If checks were skipped, check data is absent — treat as passed.
				$has_passed = ! isset( $check['passed'] ) || $check['passed'];
				if ( $has_passed ) {
					$score += $weight;
				}

				// Collect issues.
				if ( ! empty( $check['issues'] ) ) {
					$all_issues = array_merge( $all_issues, $check['issues'] );
				}
			} else {
				// Check type was skipped entirely — treat as passed.
				$score += $weight;
			}
		}

		$percentage = ( $max_score > 0 ) ? round( ( $score / $max_score ) * 100 ) : 0;

		// Determine status.
		if ( $percentage >= 80 ) {
			$status = 'ready';
		} elseif ( $percentage >= 60 ) {
			$status = 'needs_review';
		} else {
			$status = 'not_ready';
		}

		return array(
			'score'        => $percentage,
			'status'       => $status,
			'issues'       => $all_issues,
			'total_issues' => count( $all_issues ),
		);
	}

	/**
	 * Publish post
	 *
	 * @param WP_Post $post    Post object.
	 * @param array   $options Publishing options.
	 * @return array|WP_Error Publish result or error.
	 */
	private function publish_post( $post, $options ) {
		$post_data = array(
			'ID' => $post->ID,
		);

		// Schedule or publish immediately.
		if ( ! empty( $options['schedule'] ) ) {
			$schedule_date = strtotime( $options['schedule'] );
			if ( $schedule_date && $schedule_date > time() ) {
				$post_data['post_status'] = 'future';
				$post_data['post_date']   = gmdate( 'Y-m-d H:i:s', $schedule_date );
			} else {
				return new \WP_Error(
					'invalid_schedule_date',
					__( 'Invalid schedule date. Must be in the future.', 'nvoos-content-graph-ai-platform' )
				);
			}
		} else {
			$post_data['post_status'] = 'publish';
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'published' => true,
			'post_id'   => $post->ID,
			'status'    => $post_data['post_status'],
			'url'       => get_permalink( $post->ID ),
		);
	}

	/**
	 * Detect active SEO plugin
	 *
	 * @return string SEO plugin name or 'none'.
	 */
	private function detect_seo_plugin() {
		if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
			return 'yoast';
		} elseif ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
			return 'rank_math';
		}
		return 'none';
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

		if ( isset( $data['results'] ) && ! empty( $data['results'] ) ) {
			foreach ( $data['results'] as $result ) {
				$output .= $this->format_post_result( $result );
			}
		}

		if ( isset( $data['dry_run'] ) && $data['dry_run'] ) {
			$output .= "\n**Note:** This was a dry run. No changes were made. Use `--publish` to publish posts.\n";
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
		$output = sprintf(
			"### %s\n\n",
			esc_html( $result['title'] )
		);

		// Status indicator.
		$status_icons = array(
			'ready'        => '✅',
			'needs_review' => '⚠️',
			'not_ready'    => '❌',
			'error'        => '🔴',
		);

		$icon = isset( $status_icons[ $result['status'] ] ) ? $status_icons[ $result['status'] ] : '❓';

		$output .= sprintf(
			"**Status:** %s %s\n\n",
			$icon,
			esc_html( ucwords( str_replace( '_', ' ', $result['status'] ) ) )
		);

		// Error message (shown for invalid post IDs etc.).
		if ( isset( $result['message'] ) && ! empty( $result['message'] ) ) {
			$output .= sprintf( "**Message:** %s\n\n", esc_html( $result['message'] ) );
		}

		// Readiness score.
		if ( isset( $result['readiness'] ) ) {
			$readiness = $result['readiness'];
			$output   .= sprintf(
				"**Readiness Score:** %d%%\n\n",
				$readiness['score']
			);

			// Issues.
			if ( ! empty( $readiness['issues'] ) ) {
				$output .= "**Issues to Address:**\n";
				foreach ( $readiness['issues'] as $issue ) {
					$output .= sprintf( "- %s\n", esc_html( $issue ) );
				}
				$output .= "\n";
			}
		}

		// Publish result.
		if ( isset( $result['publish_result'] ) && $result['publish_result'] ) {
			$pub = $result['publish_result'];
			if ( isset( $pub['published'] ) && $pub['published'] ) {
				$output .= sprintf(
					"**Published:** [View Post](%s)\n\n",
					esc_url( $pub['url'] )
				);
			}
		}

		return $output;
	}
}
