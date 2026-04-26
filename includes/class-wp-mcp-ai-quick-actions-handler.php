<?php
/**
 * Quick Actions Widget Handler - handles AJAX requests and asset enqueuing.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		$tool_slug = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
		if ( empty( $tool_slug ) ) {
			wp_send_json_error( __( 'No tool specified.', 'mcp-ai-wpoos' ) );
		}

		// Get tool from registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $tool_slug );

		if ( ! $tool ) {
			wp_send_json_error( __( 'Invalid tool specified.', 'mcp-ai-wpoos' ) );
		}

		// Require at minimum a logged-in user with 'read' capability.
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( __( 'You do not have permission to execute this tool.', 'mcp-ai-wpoos' ) );
		}

		// Check tool-specific capabilities if declared.
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
			$media_id   = absint( wp_unslash( $_POST['media_id'] ) );
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

		$file_type = wp_check_filetype( sanitize_file_name( $file['name'] ) );
		$mime_type = ! empty( $file_type['type'] ) ? $file_type['type'] : sanitize_mime_type( $file['type'] );

		if ( ! in_array( $mime_type, $allowed_types, true ) ) {
			return new WP_Error( 'invalid_file_type', __( 'File type not allowed.', 'mcp-ai-wpoos' ) );
		}

		// SVG hardening (audit F-XSS-02): only users who can upload files may
		// submit SVG (subscribers cannot), and the file is sanitised before it
		// reaches the WordPress media library so it cannot carry inline
		// scripts, event handlers, foreign objects, or javascript:/vbscript:
		// URLs.
		if ( 'image/svg+xml' === $mime_type ) {
			if ( ! current_user_can( 'upload_files' ) ) {
				return new WP_Error( 'invalid_file_type', __( 'SVG uploads require the upload_files capability.', 'mcp-ai-wpoos' ) );
			}

			$tmp_path = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
			if ( '' === $tmp_path || ! is_readable( $tmp_path ) ) {
				return new WP_Error( 'upload_error', __( 'SVG upload could not be read for sanitisation.', 'mcp-ai-wpoos' ) );
			}

			$raw_svg       = file_get_contents( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- temp upload, no remote URI.
			$sanitised_svg = false === $raw_svg ? false : $this->sanitize_svg_contents( $raw_svg );

			if ( false === $sanitised_svg || '' === trim( (string) $sanitised_svg ) ) {
				return new WP_Error( 'invalid_file_type', __( 'SVG could not be parsed or contained no safe content.', 'mcp-ai-wpoos' ) );
			}

			$bytes_written = file_put_contents( $tmp_path, $sanitised_svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- temp upload buffer.
			if ( false === $bytes_written ) {
				return new WP_Error( 'upload_error', __( 'Sanitised SVG could not be written for upload.', 'mcp-ai-wpoos' ) );
			}

			// Refresh the size for the subsequent size check and for
			// wp_handle_upload's bookkeeping.
			$file['size'] = $bytes_written;
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
	 * Sanitise an SVG document, stripping anything that could execute script
	 * when the SVG is later rendered inline or via <object>/<img>.
	 *
	 * Removes:
	 * - <script>, <foreignObject>, <handler>, <iframe>, <embed>, <set>, <use> (with external href)
	 * - All `on*` event handler attributes (onload, onclick, ...).
	 * - href / xlink:href values whose scheme is not http(s), mailto, tel, or
	 *   a same-document fragment ("#id"). javascript:/vbscript:/data: URLs
	 *   are dropped so the surviving tag becomes inert rather than removing
	 *   it entirely (preserves graphical content where possible).
	 * - External DTDs / network entities via LIBXML_NONET + LIBXML_NOENT off.
	 *
	 * @param string $svg Raw SVG markup as read from the uploaded tmp file.
	 * @return string|false Sanitised SVG, or false if parsing failed.
	 */
	protected function sanitize_svg_contents( $svg ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument / DOMNode API uses camelCase by design.
		$svg = (string) $svg;
		if ( '' === $svg ) {
			return false;
		}

		// Disable external entity loading on PHP < 8.0 globally (no-op on 8.0+).
		// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- guarded behind PHP_VERSION_ID < 80000.
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- guarded behind PHP_VERSION_ID < 80000.
			$prev_entity_loader = libxml_disable_entity_loader( true );
		} else {
			$prev_entity_loader = null;
		}
		$prev_internal_errors = libxml_use_internal_errors( true );

		$dom                     = new DOMDocument( '1.0', 'UTF-8' );
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput       = false;

		// LIBXML_NONET blocks network access for external DTDs/entities.
		// LIBXML_NOENT is intentionally NOT set so entity expansion is left to
		// the parser's default (no substitution) — defends against billion-laughs.
		$loaded = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_internal_errors );
		if ( null !== $prev_entity_loader && function_exists( 'libxml_disable_entity_loader' ) ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- guarded; restoring previous state.
			libxml_disable_entity_loader( $prev_entity_loader );
		}

		if ( ! $loaded || null === $dom->documentElement ) {
			return false;
		}

		// Reject DOCTYPE — even when LIBXML_NONET is set, an attacker-supplied
		// internal subset can embed entity declarations.
		if ( $dom->doctype ) {
			$dom->removeChild( $dom->doctype );
		}

		$dangerous_tags = array(
			'script',
			'foreignobject',
			'iframe',
			'embed',
			'object',
			'handler',
			'set',
			'animate',
			'animatetransform',
			'animatemotion',
		);

		// Walk every element. Use XPath to find dangerous tags case-insensitively
		// across namespaces, then strip event handlers + javascript: hrefs.
		$xpath = new DOMXPath( $dom );

		// Remove dangerous elements.
		foreach ( $dangerous_tags as $tag ) {
			$nodes = $xpath->query( '//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "' . $tag . '"]' );
			if ( false === $nodes ) {
				continue;
			}
			// Iterate in reverse so removal does not invalidate indices.
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		// Strip event-handler attributes and unsafe href schemes on the
		// remaining elements.
		$all_elements = $xpath->query( '//*' );
		if ( $all_elements instanceof DOMNodeList ) {
			foreach ( $all_elements as $element ) {
				if ( ! $element instanceof DOMElement ) {
					continue;
				}

				$attrs_to_remove = array();
				foreach ( $element->attributes as $attr ) {
					$name = strtolower( $attr->nodeName );

					if ( 0 === strpos( $name, 'on' ) ) {
						$attrs_to_remove[] = $attr->nodeName;
						continue;
					}

					if ( 'href' === $name || 'xlink:href' === $name ) {
						$value = trim( (string) $attr->nodeValue );
						if ( '' !== $value && ! $this->is_safe_svg_href( $value ) ) {
							$attrs_to_remove[] = $attr->nodeName;
						}
					}

					// `style` can carry expression() / url(javascript:...) on
					// some legacy renderers; cheapest defence is to drop it.
					if ( 'style' === $name ) {
						$value = (string) $attr->nodeValue;
						if ( preg_match( '/expression\s*\(|javascript:|vbscript:/i', $value ) ) {
							$attrs_to_remove[] = $attr->nodeName;
						}
					}
				}

				foreach ( $attrs_to_remove as $name ) {
					$element->removeAttribute( $name );
				}
			}
		}

		$cleaned = $dom->saveXML( $dom->documentElement );
		return is_string( $cleaned ) ? $cleaned : false;
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Whether an href / xlink:href value is safe to keep in a sanitised SVG.
	 *
	 * @param string $value Trimmed attribute value.
	 * @return bool True if safe; false to drop the attribute.
	 */
	protected function is_safe_svg_href( $value ) {
		// Same-document fragment.
		if ( '' !== $value && '#' === $value[0] ) {
			return true;
		}
		// Relative path (no scheme delimiter).
		if ( false === strpos( $value, ':' ) ) {
			return true;
		}
		// Allowed schemes only.
		return (bool) preg_match( '#^(https?|mailto|tel):#i', $value );
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
