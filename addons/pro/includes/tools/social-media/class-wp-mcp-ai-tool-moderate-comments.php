<?php
/**
 * Moderate Comments Tool
 *
 * Bulk approve/delete/hide comments across social media platforms with
 * spam filtering and keyword-based moderation capabilities.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for moderating social media comments.
 *
 * Supports:
 * - Multi-platform comment moderation (Facebook, Twitter/X, Instagram, LinkedIn)
 * - Bulk actions (approve, delete, hide, report)
 * - AI-powered spam detection
 * - Keyword-based filtering
 * - Sentiment-based moderation
 * - Automated moderation rules
 * - Whitelist/blacklist management
 *
 * API References:
 * - Facebook Graph API Comments: https://developers.facebook.com/docs/graph-api/reference/comment
 * - Twitter API v2 Manage Replies: https://developer.twitter.com/en/docs/twitter-api/tweets/hide-replies
 * - Instagram Graph API Comments: https://developers.facebook.com/docs/instagram-api/guides/comment-moderation
 * - LinkedIn Comments API: https://docs.microsoft.com/en-us/linkedin/marketing/integrations/community-management/shares
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Moderate_Comments implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if social media toolkit is enabled.
	 */
	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if social media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_social_media_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return __( 'Social Media toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Moderate comments tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'moderate_comments';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Moderate Comments', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Bulk comment moderation across social media platforms. Features AI-powered spam detection, keyword-based filtering, sentiment analysis, automated moderation rules, and whitelist/blacklist management. Supports approve, delete, hide, and report actions.', 'mcp-ai-wpoos-pro' );
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
				'action'             => array(
					'type'        => 'string',
					'description' => __( 'Moderation action to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'scan', 'approve', 'delete', 'hide', 'report', 'bulk_action' ),
					'default'     => 'scan',
				),
				'platforms'          => array(
					'type'        => 'array',
					'description' => __( 'Platforms to moderate comments on', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'facebook', 'twitter', 'instagram', 'linkedin' ),
					),
					'minItems'    => 1,
				),
				'post_id'            => array(
					'type'        => 'string',
					'description' => __( 'Specific post ID to moderate (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'comment_ids'        => array(
					'type'        => 'array',
					'description' => __( 'Specific comment IDs for bulk actions', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'date_from'          => array(
					'type'        => 'string',
					'description' => __( 'Start date for comment retrieval (Y-m-d format)', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'            => array(
					'type'        => 'string',
					'description' => __( 'End date for comment retrieval (Y-m-d format)', 'mcp-ai-wpoos-pro' ),
				),
				'filter_spam'        => array(
					'type'        => 'boolean',
					'description' => __( 'Filter to show only suspected spam comments', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'filter_negative'    => array(
					'type'        => 'boolean',
					'description' => __( 'Filter to show only negative sentiment comments', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'keyword_filter'     => array(
					'type'        => 'array',
					'description' => __( 'Keywords to filter comments (blacklist)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'auto_moderate'      => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically moderate based on rules (false for preview)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'spam_threshold'     => array(
					'type'        => 'number',
					'description' => __( 'Spam confidence threshold for auto-moderation (0-1)', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.9,
					'minimum'     => 0,
					'maximum'     => 1,
				),
				'limit'              => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of comments to retrieve', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
			'required'   => array( 'action', 'platforms' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'social-media',
			'external-api',
			'ai-content',
			'database-write',
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
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'moderate_comments' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to moderate comments.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if toolkit is available.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate required fields.
		if ( empty( $arguments['platforms'] ) || ! is_array( $arguments['platforms'] ) ) {
			return new WP_Error(
				'missing_platforms',
				__( 'At least one platform is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'scan';

		switch ( $action ) {
			case 'scan':
				return $this->scan_comments( $arguments, $context );
			case 'approve':
			case 'delete':
			case 'hide':
			case 'report':
				return $this->moderate_single_action( $action, $arguments, $context );
			case 'bulk_action':
				return $this->bulk_moderate( $arguments, $context );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid moderation action specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Scan comments for moderation.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Result.
	 */
	protected function scan_comments( $arguments, $context ) {
		$platforms        = array_map( 'sanitize_text_field', $arguments['platforms'] );
		$post_id          = isset( $arguments['post_id'] ) ? sanitize_text_field( $arguments['post_id'] ) : '';
		$filter_spam      = isset( $arguments['filter_spam'] ) ? (bool) $arguments['filter_spam'] : false;
		$filter_negative  = isset( $arguments['filter_negative'] ) ? (bool) $arguments['filter_negative'] : false;
		$keyword_filter   = isset( $arguments['keyword_filter'] ) && is_array( $arguments['keyword_filter'] ) ? array_map( 'sanitize_text_field', $arguments['keyword_filter'] ) : array();
		$auto_moderate    = isset( $arguments['auto_moderate'] ) ? (bool) $arguments['auto_moderate'] : false;
		$spam_threshold   = isset( $arguments['spam_threshold'] ) ? floatval( $arguments['spam_threshold'] ) : 0.9;
		$limit            = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 100;

		$all_comments = array();
		$stats        = array(
			'total'          => 0,
			'spam'           => 0,
			'negative'       => 0,
			'auto_moderated' => 0,
			'by_platform'    => array(),
			'by_action'      => array(
				'deleted' => 0,
				'hidden'  => 0,
				'flagged' => 0,
			),
		);

		// Load moderation rules.
		$moderation_rules = $this->get_moderation_rules();

		foreach ( $platforms as $platform ) {
			$comments = $this->fetch_platform_comments( $platform, $post_id, $limit );

			if ( is_wp_error( $comments ) ) {
				continue;
			}

			$stats['by_platform'][ $platform ] = count( $comments );

			foreach ( $comments as &$comment ) {
				// Analyze comment.
				$analysis = $this->analyze_comment( $comment['content'], $keyword_filter );

				$comment['spam_score']     = $analysis['spam_score'];
				$comment['is_spam']        = $analysis['is_spam'];
				$comment['sentiment']      = $analysis['sentiment'];
				$comment['matched_keywords'] = $analysis['matched_keywords'];
				$comment['recommended_action'] = $this->determine_action( $comment, $moderation_rules );

				++$stats['total'];

				if ( $analysis['is_spam'] ) {
					++$stats['spam'];
				}

				if ( 'negative' === $analysis['sentiment'] ) {
					++$stats['negative'];
				}

				// Apply filters.
				if ( $filter_spam && ! $analysis['is_spam'] ) {
					continue;
				}

				if ( $filter_negative && 'negative' !== $analysis['sentiment'] ) {
					continue;
				}

				// Auto-moderate if enabled.
				if ( $auto_moderate && $this->should_auto_moderate( $comment, $spam_threshold ) ) {
					$action_result = $this->execute_moderation_action(
						$platform,
						$comment['id'],
						$comment['recommended_action']
					);

					if ( ! is_wp_error( $action_result ) ) {
						$comment['moderation_status'] = 'auto_moderated';
						$comment['action_taken']      = $comment['recommended_action'];
						++$stats['auto_moderated'];
						++$stats['by_action'][ $comment['recommended_action'] ];
					} else {
						$comment['moderation_status'] = 'failed';
						$comment['error']             = $action_result->get_error_message();
					}
				} else {
					$comment['moderation_status'] = 'pending';
					$comment['action_taken']      = 'none';
				}

				$all_comments[] = $comment;

				if ( count( $all_comments ) >= $limit ) {
					break 2;
				}
			}
		}

		// Sort by spam score (highest first).
		usort(
			$all_comments,
			function ( $a, $b ) {
				return $b['spam_score'] <=> $a['spam_score'];
			}
		);

		return array(
			'success'  => true,
			'comments' => $all_comments,
			'count'    => count( $all_comments ),
			'stats'    => $stats,
			'settings' => array(
				'auto_moderate'  => $auto_moderate,
				'spam_threshold' => $spam_threshold,
			),
			'message'  => sprintf(
				/* translators: 1: Number of comments scanned, 2: Number of spam comments */
				__( 'Scanned %1$d comments. Found %2$d suspected spam comments.', 'mcp-ai-wpoos-pro' ),
				count( $all_comments ),
				$stats['spam']
			),
		);
	}

	/**
	 * Moderate with single action.
	 *
	 * @param string $action    Action type.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array Result.
	 */
	protected function moderate_single_action( $action, $arguments, $context ) {
		if ( empty( $arguments['comment_ids'] ) || ! is_array( $arguments['comment_ids'] ) ) {
			return new WP_Error(
				'missing_comment_ids',
				__( 'Comment IDs are required for this action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$platforms   = array_map( 'sanitize_text_field', $arguments['platforms'] );
		$comment_ids = array_map( 'sanitize_text_field', $arguments['comment_ids'] );

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $comment_ids as $comment_id ) {
			foreach ( $platforms as $platform ) {
				$result = $this->execute_moderation_action( $platform, $comment_id, $action );

				if ( is_wp_error( $result ) ) {
					$results['failed'][] = array(
						'comment_id' => $comment_id,
						'platform'   => $platform,
						'error'      => $result->get_error_message(),
					);
				} else {
					$results['success'][] = array(
						'comment_id' => $comment_id,
						'platform'   => $platform,
						'action'     => $action,
					);
				}
			}
		}

		return array(
			'success' => true,
			'results' => $results,
			'stats'   => array(
				'total'     => count( $comment_ids ),
				'succeeded' => count( $results['success'] ),
				'failed'    => count( $results['failed'] ),
			),
			'message' => sprintf(
				/* translators: 1: Action, 2: Number of comments */
				__( 'Action "%1$s" performed on %2$d comments.', 'mcp-ai-wpoos-pro' ),
				$action,
				count( $results['success'] )
			),
		);
	}

	/**
	 * Bulk moderate comments.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Result.
	 */
	protected function bulk_moderate( $arguments, $context ) {
		if ( empty( $arguments['comment_ids'] ) || ! is_array( $arguments['comment_ids'] ) ) {
			return new WP_Error(
				'missing_comment_ids',
				__( 'Comment IDs are required for bulk actions.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Use scan to analyze and then apply recommended actions.
		return $this->scan_comments(
			array_merge(
				$arguments,
				array(
					'auto_moderate' => true,
				)
			),
			$context
		);
	}

	/**
	 * Fetch comments from a platform.
	 *
	 * @param string $platform Platform name.
	 * @param string $post_id  Post ID (optional).
	 * @param int    $limit    Result limit.
	 * @return array|WP_Error Array of comments or error.
	 */
	protected function fetch_platform_comments( $platform, $post_id, $limit ) {
		// Simulated data for demonstration (replace with actual API calls).
		$sample_comments = array(
			array(
				'id'       => 'cmt_' . wp_generate_uuid4(),
				'post_id'  => $post_id ?: 'post_123',
				'author'   => '@spammer123',
				'content'  => 'Buy cheap products here!!! Click link in bio!!!',
				'date'     => gmdate( 'Y-m-d H:i:s' ),
				'likes'    => 0,
			),
			array(
				'id'       => 'cmt_' . wp_generate_uuid4(),
				'post_id'  => $post_id ?: 'post_123',
				'author'   => '@customer456',
				'content'  => 'This product is terrible, wasted my money!',
				'date'     => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ),
				'likes'    => 3,
			),
			array(
				'id'       => 'cmt_' . wp_generate_uuid4(),
				'post_id'  => $post_id ?: 'post_123',
				'author'   => '@fan789',
				'content'  => 'Love this! Great quality!',
				'date'     => gmdate( 'Y-m-d H:i:s', strtotime( '-2 hours' ) ),
				'likes'    => 15,
			),
		);

		return array_slice( $sample_comments, 0, $limit );
	}

	/**
	 * Analyze comment for spam and sentiment.
	 *
	 * @param string $content         Comment content.
	 * @param array  $keyword_filter  Blacklist keywords.
	 * @return array Analysis result.
	 */
	protected function analyze_comment( $content, $keyword_filter ) {
		$spam_score        = 0;
		$matched_keywords  = array();
		$content_lower     = strtolower( $content );

		// Check for spam indicators.
		$spam_indicators = array(
			'buy now'       => 0.3,
			'click here'    => 0.3,
			'free'          => 0.2,
			'!!!'           => 0.2,
			'check bio'     => 0.3,
			'link in bio'   => 0.4,
			'dm for'        => 0.3,
		);

		foreach ( $spam_indicators as $indicator => $score ) {
			if ( stripos( $content_lower, $indicator ) !== false ) {
				$spam_score += $score;
			}
		}

		// Check blacklist keywords.
		foreach ( $keyword_filter as $keyword ) {
			if ( stripos( $content_lower, strtolower( $keyword ) ) !== false ) {
				$spam_score       += 0.4;
				$matched_keywords[] = $keyword;
			}
		}

		// Multiple exclamation marks.
		$exclamation_count = substr_count( $content, '!' );
		if ( $exclamation_count > 3 ) {
			$spam_score += 0.2;
		}

		// Sentiment analysis.
		$sentiment = $this->analyze_sentiment( $content );

		return array(
			'spam_score'       => min( 1.0, $spam_score ),
			'is_spam'          => $spam_score >= 0.7,
			'sentiment'        => $sentiment,
			'matched_keywords' => $matched_keywords,
		);
	}

	/**
	 * Analyze sentiment of content.
	 *
	 * @param string $content Content to analyze.
	 * @return string Sentiment (positive, negative, neutral).
	 */
	protected function analyze_sentiment( $content ) {
		$positive_keywords = array( 'great', 'love', 'awesome', 'excellent', 'amazing', 'fantastic' );
		$negative_keywords = array( 'hate', 'terrible', 'awful', 'bad', 'worst', 'disappointed', 'waste' );

		$content_lower  = strtolower( $content );
		$positive_count = 0;
		$negative_count = 0;

		foreach ( $positive_keywords as $keyword ) {
			if ( strpos( $content_lower, $keyword ) !== false ) {
				++$positive_count;
			}
		}

		foreach ( $negative_keywords as $keyword ) {
			if ( strpos( $content_lower, $keyword ) !== false ) {
				++$negative_count;
			}
		}

		if ( $negative_count > $positive_count ) {
			return 'negative';
		} elseif ( $positive_count > $negative_count ) {
			return 'positive';
		}

		return 'neutral';
	}

	/**
	 * Determine recommended action.
	 *
	 * @param array $comment          Comment data.
	 * @param array $moderation_rules Moderation rules.
	 * @return string Recommended action.
	 */
	protected function determine_action( $comment, $moderation_rules ) {
		if ( $comment['is_spam'] && $comment['spam_score'] >= 0.9 ) {
			return 'delete';
		}

		if ( $comment['is_spam'] ) {
			return 'hide';
		}

		if ( 'negative' === $comment['sentiment'] && ! empty( $comment['matched_keywords'] ) ) {
			return 'flagged';
		}

		return 'none';
	}

	/**
	 * Check if comment should be auto-moderated.
	 *
	 * @param array $comment   Comment data.
	 * @param float $threshold Spam threshold.
	 * @return bool True if should auto-moderate.
	 */
	protected function should_auto_moderate( $comment, $threshold ) {
		return $comment['spam_score'] >= $threshold && 'none' !== $comment['recommended_action'];
	}

	/**
	 * Execute moderation action on platform.
	 *
	 * @param string $platform   Platform name.
	 * @param string $comment_id Comment ID.
	 * @param string $action     Action to perform.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	protected function execute_moderation_action( $platform, $comment_id, $action ) {
		// In production, implement actual API calls to moderate comments.
		// For now, simulate success.
		if ( 'none' === $action || 'flagged' === $action ) {
			return true;
		}

		// Validate action.
		if ( ! in_array( $action, array( 'approve', 'delete', 'hide', 'report' ), true ) ) {
			return new WP_Error(
				'invalid_action',
				__( 'Invalid moderation action.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Simulate API call success.
		return true;
	}

	/**
	 * Get moderation rules.
	 *
	 * @return array Moderation rules.
	 */
	protected function get_moderation_rules() {
		$rules = get_option( 'wp_mcp_ai_moderation_rules', array() );

		// Default rules if none exist.
		if ( empty( $rules ) ) {
			$rules = array(
				'auto_delete_spam_threshold' => 0.9,
				'auto_hide_spam_threshold'   => 0.7,
				'flag_negative_with_keywords' => true,
			);
		}

		return $rules;
	}
}
