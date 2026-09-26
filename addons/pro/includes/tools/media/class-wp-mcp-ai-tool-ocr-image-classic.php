<?php
/**
 * Classic (non-LLM) OCR image tool.
 *
 * Exposes the OCR service's tesseract path as a standalone tool: worker
 * tesseract.js first, system tesseract CLI as fallback — never a vision
 * language model. The cheap text rung of the non-LLM image identification
 * ladder.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-url-guard.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';

/**
 * Provides an assistant tool that OCRs an image using classic (non-LLM)
 * tesseract OCR via the shared OCR service.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_Ocr_Image_Classic implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Attachment_File_Resolver;

	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'ocr_image_classic';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Classic OCR Image', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Extracts text from an image using classic tesseract OCR (media-worker tesseract.js first, system tesseract fallback). Deterministic and free — no vision language model involved. Prefer this over extract_image_text when cost matters and layout fidelity does not.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Pulling text out of screenshots, scans, and receipts without spending vision tokens. Identification questions where the image contains text that answers them.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Handwriting, low-contrast scans, or layout-sensitive documents — use extract_image_text (AI OCR) for those. Images without text.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'extract_image_text', 'identify_image', 'detect_image_content' ),
			'notes'           => __( 'Requires the media-worker sidecar or a system tesseract binary. Returns a WP_Error when neither is available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID of the image to OCR.', 'mcp-ai-wpoos-pro' ),
				),
				'image_url'     => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'URL of the image. Remote URLs are downloaded server-side (SSRF-guarded).', 'mcp-ai-wpoos-pro' ),
				),
				'language'      => array(
					'type'        => 'string',
					'description' => __( 'Tesseract language code (e.g. "eng", "deu", "spa"). Default eng.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'eng',
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters(
			'wp_mcp_ai_ocr_image_classic_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_ocr_image_classic_forbidden',
				__( 'You do not have permission to use Classic OCR Image.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$language = isset( $arguments['language'] ) ? sanitize_key( $arguments['language'] ) : 'eng';
		if ( '' === $language ) {
			$language = 'eng';
		}

		$resolved = $this->resolve_local_file( $arguments );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		list( $image_path, $is_temp ) = $resolved;

		$service = new WP_MCP_AI_OCR_Service();
		$text    = $service->extract_text_from_image(
			$image_path,
			array(
				'provider'   => 'tesseract',
				'preprocess' => false,
				'language'   => $language,
			)
		);

		if ( $is_temp && file_exists( $image_path ) ) {
			wp_delete_file( $image_path );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		return $this->format_success_response(
			__( 'Text extracted with classic tesseract OCR.', 'mcp-ai-wpoos-pro' ),
			array(
				'text'     => sanitize_textarea_field( (string) $text ),
				'language' => $language,
				'provider' => 'tesseract',
				'length'   => strlen( (string) $text ),
			)
		);
	}

	/**
	 * Resolve the input to a local file path.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Two-element array ( path, is_temp ), or WP_Error.
	 */
	private function resolve_local_file( array $arguments ) {
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$file_path     = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_ocr_image_classic_missing_file',
					sprintf(
						/* translators: %d: attachment ID */
						__( 'No local file found for attachment ID %d.', 'mcp-ai-wpoos-pro' ),
						$attachment_id
					),
					array( 'status' => 404 )
				);
			}

			return array( $file_path, false );
		}

		if ( ! empty( $arguments['image_url'] ) ) {
			$url = esc_url_raw( $arguments['image_url'] );

			$attachment_id = attachment_url_to_postid( $url );
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				$file_path = get_attached_file( $attachment_id );

				if ( $file_path && file_exists( $file_path ) ) {
					return array( $file_path, false );
				}
			}

			$guard = WP_MCP_AI_URL_Guard::validate( $url );
			if ( is_wp_error( $guard ) ) {
				return new WP_Error(
					'wp_mcp_ai_ocr_image_classic_blocked_url',
					$guard->get_error_message(),
					array( 'status' => 403 )
				);
			}

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$tmp_file = download_url( $url, 30 );

			if ( is_wp_error( $tmp_file ) ) {
				return new WP_Error(
					'wp_mcp_ai_ocr_image_classic_download_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not download the image: %s', 'mcp-ai-wpoos-pro' ),
						$tmp_file->get_error_message()
					),
					array( 'status' => 502 )
				);
			}

			return array( $tmp_file, true );
		}

		return new WP_Error(
			'wp_mcp_ai_ocr_image_classic_missing_input',
			__( 'One of attachment_id or image_url must be provided.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 400 )
		);
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
			'toolkit'               => 'media_processing',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'data_scientist', 'researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires user capabilities.
			'external-api',         // Media-worker sidecar or system binary.
			'cacheable',            // Deterministic for a given image.
		);
	}
}
