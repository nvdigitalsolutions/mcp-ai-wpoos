<?php
/**
 * Comments integration for AI-powered comment moderation.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Handles automatic analysis and moderation of comments using AI.
 */
class WP_MCP_AI_Comments {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Comments|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Comments
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Hook into comment preprocessing.
		add_filter( 'preprocess_comment', array( $this, 'analyze_comment' ), 10, 1 );
	}

	/**
	 * Analyze comment before it's saved.
	 *
	 * @param array $commentdata Comment data.
	 * @return array Modified comment data.
	 */
	public function analyze_comment( $commentdata ) {
		// Check if the feature is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_ai_comments_moderation'] ) ) {
			return $commentdata;
		}

		// Skip if user is logged in and has moderation capability.
		$user_id = get_current_user_id();
		if ( $user_id && user_can( $user_id, 'moderate_comments' ) ) {
			return $commentdata;
		}

		// Skip if comment is already marked as spam by other plugins.
		if ( isset( $commentdata['comment_approved'] ) && 'spam' === $commentdata['comment_approved'] ) {
			return $commentdata;
		}

		// Get sensitivity setting.
		$sensitivity = isset( $settings['ai_comments_sensitivity'] ) ? $settings['ai_comments_sensitivity'] : 'medium';

		// Get the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'analyze_comment_content' );

		if ( ! $tool ) {
			$this->log_error( 'Comment analysis tool not found' );
			return $commentdata;
		}

		// Execute the tool.
		$result = $tool->execute(
			array(
				'comment_content' => $commentdata['comment_content'],
				'comment_author'  => isset( $commentdata['comment_author'] ) ? $commentdata['comment_author'] : '',
				'comment_email'   => isset( $commentdata['comment_author_email'] ) ? $commentdata['comment_author_email'] : '',
				'comment_url'     => isset( $commentdata['comment_author_url'] ) ? $commentdata['comment_author_url'] : '',
				'user_ip'         => isset( $commentdata['comment_author_IP'] ) ? $commentdata['comment_author_IP'] : '',
				'sensitivity'     => $sensitivity,
			),
			array(
				'user_id' => 0, // System context.
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->log_error( 'Failed to analyze comment: ' . $result->get_error_message() );
			return $commentdata;
		}

		// Log the analysis.
		$this->log_activity(
			sprintf(
				'Comment analyzed: %s (confidence: %.2f)',
				$result['recommended_action'],
				$result['confidence']
			)
		);

		// Apply the recommendation based on confidence.
		$min_confidence = isset( $settings['ai_comments_min_confidence'] ) ? floatval( $settings['ai_comments_min_confidence'] ) : 0.7;

		if ( $result['confidence'] >= $min_confidence ) {
			switch ( $result['recommended_action'] ) {
				case 'spam':
					$commentdata['comment_approved'] = 'spam';
					$this->log_activity( 'Comment marked as spam by AI' );
					break;

				case 'hold':
					$commentdata['comment_approved'] = 0; // Hold for moderation.
					$this->log_activity( 'Comment held for moderation by AI' );
					break;

				case 'approved':
					// Let WordPress's default moderation rules handle approval.
					// We don't force approval here to avoid bypassing other security checks.
					break;
			}

			// Store the AI analysis as comment meta for review by moderators.
			add_filter(
				'wp_insert_comment',
				function ( $comment_id ) use ( $result ) {
					update_comment_meta( $comment_id, '_wp_mcp_ai_analysis', $result );
					return $comment_id;
				},
				10,
				1
			);
		} else {
			// Low confidence - hold for manual review.
			$auto_hold_low_confidence = isset( $settings['ai_comments_auto_hold_low_confidence'] ) ? $settings['ai_comments_auto_hold_low_confidence'] : true;

			if ( $auto_hold_low_confidence ) {
				$commentdata['comment_approved'] = 0;
				$this->log_activity( 'Comment held due to low AI confidence' );
			}

			// Still store the analysis.
			add_filter(
				'wp_insert_comment',
				function ( $comment_id ) use ( $result ) {
					update_comment_meta( $comment_id, '_wp_mcp_ai_analysis', $result );
					return $comment_id;
				},
				10,
				1
			);
		}

		return $commentdata;
	}

	/**
	 * Log error message.
	 *
	 * @param string $message Error message.
	 */
	private function log_error( $message ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		$log_entry = '[WP_MCP_AI_Comments] ' . $message;

		error_log( $log_entry ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Also store in plugin's error log if available.
		$recent_errors = get_option( 'wp_mcp_ai_recent_errors', array() );

		if ( ! is_array( $recent_errors ) ) {
			$recent_errors = array();
		}

		array_unshift(
			$recent_errors,
			array(
				'timestamp' => current_time( 'mysql' ),
				'message'   => $log_entry,
			)
		);

		// Keep only the last 100 errors.
		$recent_errors = array_slice( $recent_errors, 0, 100 );

		update_option( 'wp_mcp_ai_recent_errors', $recent_errors, false );
	}

	/**
	 * Log activity message.
	 *
	 * @param string $message Activity message.
	 */
	private function log_activity( $message ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		$log_entry = '[WP_MCP_AI_Comments] ' . $message;

		// Store in plugin's activity log if available.
		$recent_activity = get_option( 'wp_mcp_ai_recent_activity', array() );

		if ( ! is_array( $recent_activity ) ) {
			$recent_activity = array();
		}

		array_unshift(
			$recent_activity,
			array(
				'timestamp' => current_time( 'mysql' ),
				'message'   => $log_entry,
			)
		);

		// Keep only the last 100 activities.
		$recent_activity = array_slice( $recent_activity, 0, 100 );

		update_option( 'wp_mcp_ai_recent_activity', $recent_activity, false );
	}
}
