<?php
/**
 * Media integration for AI-powered image analysis.
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
 * Handles automatic generation of alt text and captions for uploaded images.
 */
class WP_MCP_AI_Media {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Media|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Media
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
		// Hook into attachment upload.
		add_action( 'add_attachment', array( $this, 'process_new_attachment' ), 10, 1 );
	}

	/**
	 * Process newly uploaded attachments.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public function process_new_attachment( $attachment_id ) {
		// Check if the feature is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_ai_media_library'] ) ) {
			return;
		}

		// Verify it's an image.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		// Get attachment metadata to check if we should process.
		$metadata = wp_get_attachment_metadata( $attachment_id );

		// Skip if metadata doesn't exist or if it's too small (likely an icon).
		if ( empty( $metadata ) || ( isset( $metadata['width'] ) && $metadata['width'] < 100 ) ) {
			return;
		}

		// Check if alt text already exists.
		$existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		// Get settings for what to generate.
		$generate_alt_text  = isset( $settings['ai_media_generate_alt_text'] ) ? $settings['ai_media_generate_alt_text'] : true;
		$generate_caption   = isset( $settings['ai_media_generate_caption'] ) ? $settings['ai_media_generate_caption'] : true;
		$overwrite_existing = isset( $settings['ai_media_overwrite_existing'] ) ? $settings['ai_media_overwrite_existing'] : false;

		// Generate alt text if enabled and (doesn't exist OR overwrite is enabled).
		if ( $generate_alt_text && ( empty( $existing_alt ) || $overwrite_existing ) ) {
			$this->generate_alt_text( $attachment_id );
		}

		// Generate caption if enabled.
		if ( $generate_caption ) {
			$post = get_post( $attachment_id );
			if ( $post && ( empty( $post->post_excerpt ) || $overwrite_existing ) ) {
				$this->generate_caption( $attachment_id );
			}
		}
	}

	/**
	 * Generate alt text for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True on success, false on failure.
	 */
	private function generate_alt_text( $attachment_id ) {
		// Get the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'generate_image_alt_text' );

		if ( ! $tool ) {
			$this->log_error( 'Alt text generation tool not found', $attachment_id );
			return false;
		}

		// Get attachment post for context.
		$post = get_post( $attachment_id );

		// Build context from post title or filename.
		$context_parts = array();

		if ( $post && ! empty( $post->post_title ) ) {
			$context_parts[] = 'Image title: ' . $post->post_title;
		}

		$context = implode( '. ', $context_parts );

		// Execute the tool.
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'context'       => $context,
			),
			array(
				'user_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->log_error( 'Failed to generate alt text: ' . $result->get_error_message(), $attachment_id );
			return false;
		}

		if ( isset( $result['alt_text'] ) && ! empty( $result['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $result['alt_text'] ) );
			$this->log_activity( 'Generated alt text for attachment', $attachment_id );
			return true;
		}

		return false;
	}

	/**
	 * Generate caption for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True on success, false on failure.
	 */
	private function generate_caption( $attachment_id ) {
		// Get the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'generate_image_caption' );

		if ( ! $tool ) {
			$this->log_error( 'Caption generation tool not found', $attachment_id );
			return false;
		}

		// Get attachment post for context.
		$post = get_post( $attachment_id );

		// Build context from post title or filename.
		$context_parts = array();

		if ( $post && ! empty( $post->post_title ) ) {
			$context_parts[] = 'Image title: ' . $post->post_title;
		}

		$context = implode( '. ', $context_parts );

		// Execute the tool.
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'context'       => $context,
			),
			array(
				'user_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->log_error( 'Failed to generate caption: ' . $result->get_error_message(), $attachment_id );
			return false;
		}

		if ( isset( $result['caption'] ) && ! empty( $result['caption'] ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => sanitize_text_field( $result['caption'] ),
				)
			);
			$this->log_activity( 'Generated caption for attachment', $attachment_id );
			return true;
		}

		return false;
	}

	/**
	 * Log error message.
	 *
	 * @param string $message       Error message.
	 * @param int    $attachment_id Attachment ID.
	 */
	private function log_error( $message, $attachment_id ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		$log_entry = sprintf(
			'[WP_MCP_AI_Media] %s (Attachment ID: %d)',
			$message,
			$attachment_id
		);

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
	 * @param string $message       Activity message.
	 * @param int    $attachment_id Attachment ID.
	 */
	private function log_activity( $message, $attachment_id ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		$log_entry = sprintf(
			'[WP_MCP_AI_Media] %s (Attachment ID: %d)',
			$message,
			$attachment_id
		);

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
