<?php
/**
 * Quick Actions Widget Handler - handles AJAX requests and asset enqueuing.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Quick Actions widget functionality.
 */
class WP_MCP_AI_Quick_Actions_Handler {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Quick_Actions_Handler
	 */
	protected static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Quick_Actions_Handler
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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_execute_quick_action', array( $this, 'handle_execute_action' ) );
		add_action( 'wp_ajax_nopriv_wp_mcp_ai_execute_quick_action', array( $this, 'handle_execute_action' ) );
	}

	/**
	 * Enqueue widget assets.
	 */
	public function enqueue_assets() {
		// Only enqueue if Elementor is active and on relevant pages.
		if ( ! did_action( 'elementor/loaded' ) && ! is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-quick-actions-widget',
			WP_MCP_AI_URL . 'assets/css/elementor-quick-actions-widget.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-quick-actions-widget',
			WP_MCP_AI_URL . 'assets/js/elementor-quick-actions-widget.js',
			array( 'jquery', 'wp-util' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-quick-actions-widget',
			'wpMcpAiQuickActions',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_quick_action' ),
			)
		);

		// Enqueue media library scripts if user can upload files.
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Handle AJAX request to execute a quick action.
	 */
	public function handle_execute_action() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_quick_action', 'nonce' );

		// Get tool slug.
		$tool_slug = isset( $_POST['tool'] ) ? sanitize_key( $_POST['tool'] ) : '';
		if ( empty( $tool_slug ) ) {
			wp_send_json_error( __( 'No tool specified.', 'mcp-ai-wpoos' ) );
		}

		// Get tool from registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $tool_slug );

		if ( ! $tool ) {
			wp_send_json_error( __( 'Invalid tool specified.', 'mcp-ai-wpoos' ) );
		}

		// Check capabilities if tool requires them.
		if ( method_exists( $tool, 'get_required_capability' ) ) {
			$required_cap = $tool->get_required_capability();
			if ( ! empty( $required_cap ) && ! current_user_can( $required_cap ) ) {
				wp_send_json_error( __( 'You do not have permission to execute this tool.', 'mcp-ai-wpoos' ) );
			}
		}

		// Prepare arguments.
		$arguments = array();

		// Handle file upload.
		if ( ! empty( $_FILES['file'] ) ) {
			$file_data = $this->handle_file_upload( $_FILES['file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file upload array passed to dedicated handler
			if ( is_wp_error( $file_data ) ) {
				wp_send_json_error( $file_data->get_error_message() );
			}
			$arguments['file'] = $file_data;
		}

		// Handle media library selection.
		if ( isset( $_POST['media_id'] ) ) {
			$media_id   = absint( $_POST['media_id'] );
			$attachment = get_post( $media_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				wp_send_json_error( __( 'Invalid media ID.', 'mcp-ai-wpoos' ) );
			}

			$arguments['attachment_id'] = $media_id;
			$arguments['file_url']      = wp_get_attachment_url( $media_id );
			$arguments['file_path']     = get_attached_file( $media_id );
		}

		// Prepare context.
		$context = array(
			'user_id' => get_current_user_id(),
			'source'  => 'quick_actions_widget',
		);

		// Execute tool.
		try {
			$result = $registry->execute_tool( $tool_slug, $arguments, $context );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			// Format result for display.
			$formatted_result = $this->format_tool_result( $result, $tool_slug );

			wp_send_json_success( $formatted_result );

		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Handle file upload with security checks.
	 *
	 * @param array $file File data from $_FILES.
	 * @return array|WP_Error File data or error.
	 */
	protected function handle_file_upload( $file ) {
		// Check for upload errors.
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_error', __( 'File upload failed.', 'mcp-ai-wpoos' ) );
		}

		// Verify file type.
		$allowed_types = array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/svg+xml',
			'audio/mpeg',
			'audio/wav',
			'video/mp4',
			'video/webm',
			'application/pdf',
		);

		$file_type = wp_check_filetype( $file['name'] );
		$mime_type = $file['type'];

		if ( ! in_array( $mime_type, $allowed_types, true ) ) {
			return new WP_Error( 'invalid_file_type', __( 'File type not allowed.', 'mcp-ai-wpoos' ) );
		}

		// Check file size (default 10MB).
		$max_size = apply_filters( 'wp_mcp_ai_quick_action_max_file_size', 10485760 );
		if ( $file['size'] > $max_size ) {
			return new WP_Error( 'file_too_large', __( 'File size exceeds the maximum allowed.', 'mcp-ai-wpoos' ) );
		}

		// Move uploaded file to WordPress uploads directory.
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload_overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
				'svg'          => 'image/svg+xml',
				'mp3'          => 'audio/mpeg',
				'wav'          => 'audio/wav',
				'mp4'          => 'video/mp4',
				'webm'         => 'video/webm',
				'pdf'          => 'application/pdf',
			),
		);

		$uploaded_file = wp_handle_upload( $file, $upload_overrides );

		if ( isset( $uploaded_file['error'] ) ) {
			return new WP_Error( 'upload_error', $uploaded_file['error'] );
		}

		return array(
			'url'  => $uploaded_file['url'],
			'path' => $uploaded_file['file'],
			'type' => $uploaded_file['type'],
		);
	}

	/**
	 * Format tool result for display in the widget.
	 *
	 * @param mixed  $result    Tool result.
	 * @param string $tool_slug Tool slug.
	 * @return array Formatted result.
	 */
	protected function format_tool_result( $result, $tool_slug ) {
		$formatted = array();

		// Handle different result types.
		if ( is_string( $result ) ) {
			$formatted['text'] = $result;
		} elseif ( is_array( $result ) ) {
			// Check for common result patterns.
			if ( isset( $result['image_url'] ) ) {
				$formatted['image_url'] = esc_url( $result['image_url'] );
			}
			if ( isset( $result['url'] ) && $this->is_image_tool( $tool_slug ) ) {
				$formatted['image_url'] = esc_url( $result['url'] );
			}
			if ( isset( $result['text'] ) ) {
				$formatted['text'] = wp_kses_post( $result['text'] );
			}
			if ( isset( $result['content'] ) ) {
				$formatted['content'] = wp_kses_post( $result['content'] );
			}
			if ( isset( $result['message'] ) ) {
				$formatted['message'] = esc_html( $result['message'] );
			}

			// Include the full result for debugging if WP_DEBUG is enabled.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$formatted['_raw'] = $result;
			}
		}

		// Add success message if not present.
		if ( empty( $formatted['message'] ) && empty( $formatted['text'] ) ) {
			$formatted['message'] = __( 'Tool executed successfully.', 'mcp-ai-wpoos' );
		}

		return $formatted;
	}

	/**
	 * Check if tool is image-related.
	 *
	 * @param string $tool_slug Tool slug.
	 * @return bool True if image tool.
	 */
	protected function is_image_tool( $tool_slug ) {
		$image_patterns = array( 'image', 'photo', 'picture', 'visual' );
		foreach ( $image_patterns as $pattern ) {
			if ( strpos( $tool_slug, $pattern ) !== false ) {
				return true;
			}
		}
		return false;
	}
}

// Initialize handler.
WP_MCP_AI_Quick_Actions_Handler::get_instance();
