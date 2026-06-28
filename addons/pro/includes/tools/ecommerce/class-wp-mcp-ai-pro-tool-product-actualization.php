<?php
/**
 * Product Actualization Tool - Pro add-on tool for product image compositing.
 *
 * Composites product images into AI-generated scenes while preserving original product pixels.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for product actualization into scenes.
 *
 * Provides functionality to:
 * - Composite product images into generated scenes
 * - Generate static images or short videos
 * - Preserve original product pixels (non-destructive)
 * - Auto background removal when needed
 * - Professional compositing with shadows and reflections
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Product_Actualization implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Rules_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if dependencies are met.
	 */
	public static function is_available() {
		// Check for Imagick or GD support.
		return extension_loaded( 'imagick' ) || extension_loaded( 'gd' );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'Product Actualization tool requires either Imagick or GD PHP extension to be installed.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'product_actualization';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Product Actualization', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Integrate a product image into a generated scene or short video using AI-powered scene fusion. In AI integration mode (default), the product is naturally embedded into the generated environment using AI image editing — matching lighting, shadows, reflections, and depth — rather than being mechanically layered on top. Works with Gemini (preferred) or OpenAI. Image mode creates static integrated images; video mode uses Google Gemini VEO to animate the scene around the product. Perfect for lifestyle marketing shots, social ads, and product visualization.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_attachment_id' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID of the product image to be composited. Also accepts a public image URL (https://...) or a file_id string from chat file uploads (e.g., "file-abc123").', 'mcp-ai-wpoos-pro' ),
				),
				'mode'                  => array(
					'type'        => 'string',
					'enum'        => array( 'image', 'video' ),
					'default'     => 'image',
					'description' => __( 'Whether to create a still image or a short video.', 'mcp-ai-wpoos-pro' ),
				),
				'scene_prompt'          => array(
					'type'        => 'string',
					'description' => __( 'High-level description of the desired scene/background (e.g., "bright kitchen counter, morning light, shallow depth of field").', 'mcp-ai-wpoos-pro' ),
				),
				'aspect_ratio'          => array(
					'type'        => 'string',
					'enum'        => array( '1:1', '4:5', '16:9', '9:16', '3:2', '2:3', 'auto' ),
					'default'     => '3:2',
					'description' => __( 'Aspect ratio for the background generation. Note: For image mode, OpenAI supports 1:1, 2:3 (portrait), and 3:2 (landscape). For video mode with Veo, supports 1:1, 2:3, 3:2, and auto. 16:9 and 9:16 will map to closest supported ratios.', 'mcp-ai-wpoos-pro' ),
				),
				'duration_seconds'      => array(
					'type'        => 'integer',
					'minimum'     => 4,
					'maximum'     => 10,
					'default'     => 6,
					'description' => __( 'Duration of the output video in seconds (video mode only).', 'mcp-ai-wpoos-pro' ),
				),
				'background_mode'       => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'remove', 'preserve' ),
					'default'     => 'auto',
					'description' => __( 'Control background removal strategy. "auto" detects transparency, "remove" forces removal, "preserve" keeps original background.', 'mcp-ai-wpoos-pro' ),
				),
				'placement_hint'        => array(
					'type'        => 'string',
					'description' => __( 'Optional hint for product placement (e.g., "center on a table", "bottom-right on a shelf", "floating in air").', 'mcp-ai-wpoos-pro' ),
				),
				'scale_factor'          => array(
					'type'        => 'number',
					'minimum'     => 0.1,
					'maximum'     => 2.0,
					'default'     => 1.0,
					'description' => __( 'Scale factor for the product relative to the scene (1.0 = natural size). Only applies in composite integration mode.', 'mcp-ai-wpoos-pro' ),
				),
				'integration_mode'      => array(
					'type'        => 'string',
					'enum'        => array( 'ai', 'composite' ),
					'default'     => 'ai',
					'description' => __( 'How the product is placed into the scene. "ai" (default) uses AI image editing to naturally embed the product with matching lighting, shadows, and depth. "composite" uses the classic generate-then-overlay method.', 'mcp-ai-wpoos-pro' ),
				),
				'provider'              => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'gemini', 'openai' ),
					'default'     => 'auto',
					'description' => __( 'AI provider to use for scene generation and integration. "auto" prefers Gemini when a Gemini API key is configured, otherwise falls back to OpenAI.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'product_attachment_id', 'scene_prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		// Authentication check.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to use product actualization.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Capability check for authenticated users.
		if ( $user_id ) {
			if ( ! user_can( $user_id, 'upload_files' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to create product visualizations.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_site',
					__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}
		}

		// Validate required parameters.
		$product_id_param = isset( $arguments['product_attachment_id'] ) ? $arguments['product_attachment_id'] : '';

		// Handle three input types: public image URL, numeric attachment ID, or file_id string from chat uploads.
		// URLs are downloaded to a temp working file directly (no WordPress attachment lookup needed).
		// file_ids (e.g. "file-abc123") are resolved to a WordPress attachment_id via the message-attachments helper.
		$product_id   = 0;
		$working_path = null; // Set directly for URL inputs, bypassing the attachment lookup below.

		if ( is_string( $product_id_param ) && $this->is_valid_http_url( $product_id_param ) ) {
			// URL input: download to a temp working file, skipping attachment DB lookup.
			$url_result = $this->download_url_to_temp( $product_id_param );
			if ( is_wp_error( $url_result ) ) {
				return $url_result;
			}
			$working_path = $url_result['file_path'];
		} elseif ( is_int( $product_id_param ) || is_numeric( $product_id_param ) ) {
			$product_id = absint( $product_id_param );
		} elseif ( is_string( $product_id_param ) && '' !== $product_id_param ) {
			// This might be a file_id from OpenAI - try to resolve it to an attachment_id.
			if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
			}
			$attachments_helper = new WP_MCP_AI_Message_Attachments();
			$product_id         = $attachments_helper->get_attachment_id_for_openai_file( $product_id_param );
		}

		$scene_prompt     = isset( $arguments['scene_prompt'] ) ? sanitize_textarea_field( $arguments['scene_prompt'] ) : '';
		$mode             = isset( $arguments['mode'] ) ? sanitize_key( $arguments['mode'] ) : 'image';
		$aspect_ratio     = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '16:9';
		$duration         = isset( $arguments['duration_seconds'] ) ? absint( $arguments['duration_seconds'] ) : 6;
		$bg_mode          = isset( $arguments['background_mode'] ) ? sanitize_key( $arguments['background_mode'] ) : 'auto';
		$placement        = isset( $arguments['placement_hint'] ) ? sanitize_text_field( $arguments['placement_hint'] ) : '';
		$scale_factor     = isset( $arguments['scale_factor'] ) ? floatval( $arguments['scale_factor'] ) : 1.0;
		$integration_mode = isset( $arguments['integration_mode'] ) ? sanitize_key( $arguments['integration_mode'] ) : 'ai';
		$provider         = isset( $arguments['provider'] ) ? sanitize_key( $arguments['provider'] ) : 'auto';

		if ( ! $product_id && null === $working_path ) {
			return new WP_Error(
				'wp_mcp_ai_missing_product',
				__( 'A product attachment ID, image URL, or file ID is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $scene_prompt ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Scene prompt is required to generate the background.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Validate mode.
		if ( ! in_array( $mode, array( 'image', 'video' ), true ) ) {
			$mode = 'image';
		}

		// Validate scale factor.
		if ( $scale_factor < 0.1 || $scale_factor > 2.0 ) {
			$scale_factor = 1.0;
		}

		// Validate integration mode.
		if ( ! in_array( $integration_mode, array( 'ai', 'composite' ), true ) ) {
			$integration_mode = 'ai';
		}

		// Validate provider.
		if ( ! in_array( $provider, array( 'auto', 'gemini', 'openai' ), true ) ) {
			$provider = 'auto';
		}

		// Step 1: Resolve the product to a working file path.
		if ( null === $working_path ) {
			// Attachment ID or file_id path: look up the file on disk.
			$file_path = get_attached_file( $product_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_product',
					__( 'Invalid product attachment ID - file not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			// Validate file type.
			$mime_type = get_post_mime_type( $product_id );
			if ( ! $mime_type || ! str_starts_with( $mime_type, 'image/' ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_file_type',
					__( 'Product attachment must be an image.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			// Step 2: Duplicate to avoid touching the original.
			$working_path = $this->duplicate_attachment_file( $product_id );
			if ( is_wp_error( $working_path ) ) {
				return $working_path;
			}
		}

		// Step 3: Optional background removal.
		if ( $this->should_remove_background( $working_path, $bg_mode ) ) {
			$removed_bg_path = $this->remove_background( $working_path );
			if ( is_wp_error( $removed_bg_path ) ) {
				// If background removal fails, continue with original.
				// Log the error but don't fail the entire operation.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WP MCP AI: Background removal failed: ' . $removed_bg_path->get_error_message() );
				}
			} else {
				// Clean up old working file.
				if ( file_exists( $working_path ) ) {
					wp_delete_file( $working_path );
				}
				$working_path = $removed_bg_path;
			}
		}

		// Step 4: Generate or AI-integrate the scene.
		if ( 'image' === $mode ) {
			// Build the integrated or composited product scene.
			$composited_path = $this->build_product_scene(
				$working_path,
				$scene_prompt,
				$aspect_ratio,
				$placement,
				$scale_factor,
				$integration_mode,
				$provider,
				$context
			);

			// working_path is cleaned up inside build_product_scene.
			if ( is_wp_error( $composited_path ) ) {
				return $composited_path;
			}

			// Step 5: Save final asset to Media Library (image mode only).
			$attachment_id = $this->import_composited_asset( $composited_path, $mode, $arguments, $user_id, $context );

			if ( is_wp_error( $attachment_id ) ) {
				if ( file_exists( $composited_path ) ) {
					wp_delete_file( $composited_path );
				}
				return $attachment_id;
			}

			// Clean up composited file (now in Media Library).
			if ( file_exists( $composited_path ) ) {
				wp_delete_file( $composited_path );
			}
		} else {
			// Video mode - build the reference image first, then pass to VEO.
			$composited_path = $this->build_product_scene(
				$working_path,
				$scene_prompt,
				$aspect_ratio,
				$placement,
				$scale_factor,
				$integration_mode,
				$provider,
				$context
			);

			// working_path is cleaned up inside build_product_scene.
			if ( is_wp_error( $composited_path ) ) {
				return $composited_path;
			}

			// Import reference image temporarily to use as VEO reference.
			$composited_attachment_id = $this->import_composited_asset(
				$composited_path,
				'image',
				$arguments,
				$user_id,
				$context
			);

			if ( file_exists( $composited_path ) ) {
				wp_delete_file( $composited_path );
			}

			if ( is_wp_error( $composited_attachment_id ) ) {
				return $composited_attachment_id;
			}

			// Generate video using VEO with the integrated product image as reference.
			$video_result = $this->generate_scene_video(
				$scene_prompt,
				$duration,
				$aspect_ratio,
				$composited_attachment_id,
				$context
			);

			// Clean up temporary reference image.
			wp_delete_attachment( $composited_attachment_id, true );

			if ( is_wp_error( $video_result ) ) {
				return $video_result;
			}

			// VEO already saves to Media Library, so we're done.
			$attachment_id = $video_result['attachment_id'];
		}

		// Get final URL.
		$url = wp_get_attachment_url( $attachment_id );

		return array(
			'mode'          => $mode,
			'attachment_id' => $attachment_id,
			'url'           => $url,
			'text'          => sprintf(
				/* translators: 1: attachment ID, 2: mode */
				__( 'Successfully created product visualization (ID: %1$d) in %2$s mode.', 'mcp-ai-wpoos-pro' ),
				$attachment_id,
				$mode
			),
		);
	}

	/**
	 * Duplicate attachment file to a working copy.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|WP_Error Path to duplicated file or error.
	 */
	protected function duplicate_attachment_file( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Source file not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-temp';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$file_info = pathinfo( $file_path );
		$extension = isset( $file_info['extension'] ) ? $file_info['extension'] : 'png';
		$temp_name = 'product-' . $attachment_id . '-' . wp_generate_password( 12, false ) . '.' . $extension;
		$temp_path = trailingslashit( $temp_dir ) . $temp_name;

		// Copy file.
		if ( ! copy( $file_path, $temp_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_copy_failed',
				__( 'Failed to duplicate product file.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $temp_path;
	}

	/**
	 * Check whether a string is a valid public HTTP or HTTPS URL.
	 *
	 * Uses filter_var() for format validation only — no DNS lookup is performed.
	 * Security against private/loopback IP ranges is enforced by wp_safe_remote_get()
	 * when the URL is actually fetched.
	 *
	 * @param string $url String to test.
	 * @return bool True when the string is a syntactically valid http(s) URL.
	 */
	protected function is_valid_http_url( $url ) {
		return is_string( $url )
			&& false !== filter_var( $url, FILTER_VALIDATE_URL )
			&& (bool) preg_match( '#^https?://#i', $url );
	}

	/**
	 * Download an image from a URL to a temporary working file.
	 *
	 * Uses wp_safe_remote_get (which blocks private/localhost URLs) and validates
	 * that the response content-type is an image before saving.
	 *
	 * @param string $url Public URL of the product image.
	 * @return array|WP_Error Array with 'file_path' key, or WP_Error on failure.
	 */
	protected function download_url_to_temp( $url ) {
		// Validate URL format (must be http/https, no DNS lookup at this stage).
		// Security against private IPs is enforced by wp_safe_remote_get() below.
		if ( ! $this->is_valid_http_url( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_url',
				__( 'Invalid product image URL provided.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Download the image; wp_safe_remote_get blocks private/localhost addresses.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_url_download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download product image from URL: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				),
				array( 'status' => 400 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_url_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Product image URL returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				),
				array( 'status' => 400 )
			);
		}

		// Validate content-type is an image.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		// Strip charset and other parameters (e.g. "image/png; charset=utf-8" → "image/png").
		$mime_type = trim( strtok( (string) $content_type, ';' ) );

		if ( ! $mime_type || ! str_starts_with( $mime_type, 'image/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_file_type',
				__( 'Product URL does not point to a supported image.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Determine file extension from content-type.
		$ext_map   = array(
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		$extension = isset( $ext_map[ $mime_type ] ) ? $ext_map[ $mime_type ] : 'png';

		$image_data = wp_remote_retrieve_body( $response );
		if ( empty( $image_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_data',
				__( 'Product image URL returned empty content.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-temp';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$file_name = 'product-url-' . wp_generate_password( 12, false ) . '.' . $extension;
		$file_path = trailingslashit( $temp_dir ) . $file_name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $file_path, $image_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_save_failed',
				__( 'Failed to save downloaded product image to temporary file.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array( 'file_path' => $file_path );
	}

	/**
	 * Determine if background should be removed.
	 *
	 * @param string $file_path      Path to the file.
	 * @param string $background_mode Background mode setting.
	 * @return bool True if background should be removed.
	 */
	protected function should_remove_background( $file_path, $background_mode ) {
		if ( 'preserve' === $background_mode ) {
			return false;
		}

		if ( 'remove' === $background_mode ) {
			return true;
		}

		// Auto mode: check if file has transparency.
		return ! $this->has_transparency( $file_path );
	}

	/**
	 * Check if an image file has transparency.
	 *
	 * @param string $file_path Path to the file.
	 * @return bool True if image has transparency.
	 */
	protected function has_transparency( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$mime_type = wp_check_filetype( $file_path );
		$type      = isset( $mime_type['type'] ) ? $mime_type['type'] : '';

		// PNG can have transparency.
		if ( 'image/png' === $type ) {
			if ( extension_loaded( 'imagick' ) ) {
				try {
					$image = new Imagick( $file_path );
					$alpha = $image->getImageAlphaChannel();
					$image->destroy();
					return Imagick::ALPHACHANNEL_UNDEFINED !== $alpha;
				} catch ( Exception $e ) {
					// Intentionally empty - error handled elsewhere.
					// Fall through to GD check.
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'WP MCP AI: Imagick transparency check failed: ' . $e->getMessage() );
					}
				}
			}

			// GD fallback.
			if ( extension_loaded( 'gd' ) ) {
				$img = imagecreatefrompng( $file_path );
				if ( $img ) {
					$has_alpha = imagecolorstotal( $img ) === 0 || imagecolortransparent( $img ) !== -1;
					imagedestroy( $img );
					return $has_alpha;
				}
			}
		}

		// JPEG doesn't support transparency.
		if ( 'image/jpeg' === $type || 'image/jpg' === $type ) {
			return false;
		}

		// Assume no transparency for other formats.
		return false;
	}

	/**
	 * Remove background from an image.
	 *
	 * Uses the remove.bg API service or custom filter implementation.
	 * Falls back gracefully if background removal fails.
	 *
	 * @param string $file_path Path to the file.
	 * @return string|WP_Error Path to file with removed background or error.
	 */
	protected function remove_background( $file_path ) {
		/**
		 * Filter to allow custom background removal implementation.
		 *
		 * @param string|WP_Error $result    Result of background removal (path or error).
		 * @param string          $file_path Original file path.
		 */
		$custom_result = apply_filters( 'wp_mcp_ai_pro_remove_background', null, $file_path );

		if ( null !== $custom_result ) {
			return $custom_result;
		}

		// Use the built-in remove.bg integration if available.
		if ( function_exists( 'wp_mcp_ai_remove_image_background' ) ) {
			return wp_mcp_ai_remove_image_background( $file_path );
		}

		// If remove-background.php isn't loaded, try to load it.
		$remove_bg_file = defined( 'WP_MCP_AI_PRO_PATH' ) ? WP_MCP_AI_PRO_PATH . 'includes/tools/remove-background.php' : '';
		if ( file_exists( $remove_bg_file ) ) {
			require_once $remove_bg_file;
			if ( function_exists( 'wp_mcp_ai_remove_image_background' ) ) {
				return wp_mcp_ai_remove_image_background( $file_path );
			}
		}

		// Default: Return error indicating implementation needed.
		return new WP_Error(
			'wp_mcp_ai_bg_removal_not_configured',
			__( 'Background removal is not configured. Please set background_mode to "preserve" or configure the remove.bg API key in plugin settings.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Build the product scene using either AI integration or classic compositing.
	 *
	 * Orchestrates the scene-building strategy:
	 * - "ai" mode: product image is sent to an AI image editor (Gemini preferred,
	 *   OpenAI fallback) which naturally embeds the product into the generated scene
	 *   with matching lighting, shadows, and depth — no PHP pixel-level compositing.
	 * - "composite" mode: generates a background scene via AI then overlays the
	 *   product using Imagick/GD (legacy behaviour).
	 *
	 * @param string $product_path     Path to working product image (may have bg removed).
	 * @param string $scene_prompt     Scene description.
	 * @param string $aspect_ratio     Aspect ratio.
	 * @param string $placement        Placement hint (composite mode only).
	 * @param float  $scale_factor     Scale factor (composite mode only).
	 * @param string $integration_mode 'ai' or 'composite'.
	 * @param string $provider         'auto', 'gemini', or 'openai'.
	 * @param array  $context          Execution context.
	 * @return string|WP_Error Path to the final output image, or error.
	 */
	protected function build_product_scene( $product_path, $scene_prompt, $aspect_ratio, $placement, $scale_factor, $integration_mode, $provider, $context ) {
		if ( 'ai' === $integration_mode ) {
			// AI-powered integration: the AI embeds the product naturally into the scene.
			$ai_result = $this->generate_ai_integrated_image( $product_path, $scene_prompt, $aspect_ratio, $provider, $context );

			// Always clean up the working product file.
			if ( file_exists( $product_path ) ) {
				wp_delete_file( $product_path );
			}

			if ( is_wp_error( $ai_result ) ) {
				return $ai_result;
			}

			return $ai_result['file_path'];
		}

		// Classic composite mode: generate background then overlay product with PHP.
		$background_result = $this->generate_scene_image( $scene_prompt, $aspect_ratio, $provider, $context );

		if ( is_wp_error( $background_result ) ) {
			if ( file_exists( $product_path ) ) {
				wp_delete_file( $product_path );
			}
			return $background_result;
		}

		$background_path = $background_result['file_path'];

		$composited_path = $this->composite_product_onto_image(
			$product_path,
			$background_path,
			$placement,
			$scale_factor
		);

		// Clean up intermediate files.
		if ( file_exists( $product_path ) ) {
			wp_delete_file( $product_path );
		}
		if ( file_exists( $background_path ) ) {
			wp_delete_file( $background_path );
		}

		return $composited_path;
	}

	/**
	 * Generate an AI-integrated product scene using AI image editing.
	 *
	 * Sends the product image to an AI image editor (Gemini preferred, OpenAI
	 * fallback) with a scene-integration prompt. The AI generates the surrounding
	 * environment natively around the product — matching lighting, cast shadows,
	 * reflections, and perspective — rather than mechanically overlaying it.
	 *
	 * @param string $product_path Path to the product image (background removed when applicable).
	 * @param string $scene_prompt Scene description.
	 * @param string $aspect_ratio Aspect ratio.
	 * @param string $provider     'auto', 'gemini', or 'openai'.
	 * @param array  $context      Execution context.
	 * @return array|WP_Error Array with 'file_path', or error.
	 */
	protected function generate_ai_integrated_image( $product_path, $scene_prompt, $aspect_ratio, $provider, $context ) {
		// Build a detailed integration prompt so the AI embeds the product naturally.
		$integration_prompt = sprintf(
			/* translators: %s: scene description provided by the user */
			__( 'You are given a product image. Create a complete, photorealistic scene around this product: %s. The product must appear physically present in the scene with naturally matching lighting, cast shadows, reflections, and perspective depth. Do not alter the product itself; generate the surrounding environment so the product belongs there authentically.', 'mcp-ai-wpoos-pro' ),
			$scene_prompt
		);

		/**
		 * Filter the AI integration prompt used when embedding a product into a scene.
		 *
		 * @param string $integration_prompt Full integration prompt sent to the AI.
		 * @param string $scene_prompt       Original scene description from the caller.
		 * @param string $provider           Resolved provider ('gemini' or 'openai').
		 */
		$integration_prompt = apply_filters( 'wp_mcp_ai_pro_product_integration_prompt', $integration_prompt, $scene_prompt, $provider );

		$resolved_provider = $this->detect_preferred_provider( $provider );

		if ( 'gemini' === $resolved_provider ) {
			return $this->generate_ai_integrated_image_gemini( $product_path, $integration_prompt, $aspect_ratio );
		}

		if ( 'openai' === $resolved_provider ) {
			return $this->generate_ai_integrated_image_openai( $product_path, $integration_prompt, $aspect_ratio );
		}

		return new WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider is configured. Please add a Gemini or OpenAI API key in the plugin settings to use AI integration mode. Alternatively, set integration_mode to "composite".', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * AI-integrate the product into a scene using Gemini image editing.
	 *
	 * Gemini's nano-banana (gemini-3.1-flash-image) model is used by default for
	 * fast, high-quality scene integration with the product naturally embedded.
	 *
	 * @param string $product_path Path to the product image.
	 * @param string $prompt       Full integration prompt.
	 * @param string $aspect_ratio Aspect ratio for the output.
	 * @return array|WP_Error Array with 'file_path', or error.
	 */
	protected function generate_ai_integrated_image_gemini( $product_path, $prompt, $aspect_ratio ) {
		if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dependency',
				__( 'Gemini client is not available for AI integration.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Read and base64-encode the product image for the Gemini edit_image call.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$image_data = file_get_contents( $product_path );
		if ( false === $image_data || '' === $image_data ) {
			return new WP_Error(
				'wp_mcp_ai_read_failed',
				__( 'Failed to read product image for Gemini integration.', 'mcp-ai-wpoos-pro' )
			);
		}

		$mime_info     = wp_check_filetype( $product_path );
		$mime_type     = ( isset( $mime_info['type'] ) && '' !== $mime_info['type'] ) ? $mime_info['type'] : 'image/png';
		$encoded_image = base64_encode( $image_data );

		$client  = new WP_MCP_AI_Gemini_Client();
		$options = array(
			'source_image' => array(
				'data'      => $encoded_image,
				'mime_type' => $mime_type,
			),
			'aspect_ratio' => $this->normalise_aspect_ratio_for_gemini( $aspect_ratio ),
			'mime_type'    => 'image/png',
		);

		$result = $client->edit_image( $prompt, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['image'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_result',
				__( 'Gemini returned an empty image response during product integration.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Gemini client already decodes the inline base64 to raw binary in edit_image().
		return $this->save_ai_result_to_temp( $result['image'], 'png', false );
	}

	/**
	 * AI-integrate the product into a scene using OpenAI image editing.
	 *
	 * Uses OpenAI's image edit endpoint to place the product naturally within
	 * the generated scene (without a mask, OpenAI edits the full image context).
	 *
	 * @param string $product_path Path to the product image.
	 * @param string $prompt       Full integration prompt.
	 * @param string $aspect_ratio Aspect ratio for the output.
	 * @return array|WP_Error Array with 'file_path', or error.
	 */
	protected function generate_ai_integrated_image_openai( $product_path, $prompt, $aspect_ratio ) {
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dependency',
				__( 'OpenAI client is not available for AI integration.', 'mcp-ai-wpoos-pro' )
			);
		}

		$client  = new WP_MCP_AI_OpenAI_Client();
		$size    = $this->aspect_ratio_to_size( $aspect_ratio );
		$options = array(
			'size'  => $size,
			'model' => 'gpt-image-2',
		);

		$result = $client->edit_image( $product_path, $prompt, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['data'] ) || ! is_array( $result['data'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_result',
				__( 'OpenAI returned an empty image response during product integration.', 'mcp-ai-wpoos-pro' )
			);
		}

		$image_data = $result['data'][0];
		$raw_data   = null;

		if ( ! empty( $image_data['b64_json'] ) ) {
			// Strip any embedded whitespace before decoding (some API responses
			// include MIME-style line-wrapped base64 that fails strict mode).
			$b64_clean = str_replace( array( "\r", "\n", ' ' ), '', $image_data['b64_json'] );
			$decoded   = base64_decode( $b64_clean, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary image data from API response.
			if ( false === $decoded ) {
				return new WP_Error(
					'wp_mcp_ai_decode_failed',
					__( 'Failed to decode base64 image data from OpenAI response during product integration.', 'mcp-ai-wpoos-pro' )
				);
			}
			$raw_data = $decoded;
		} elseif ( ! empty( $image_data['url'] ) ) {
			$response = wp_remote_get(
				$image_data['url'],
				array( 'timeout' => 60 )
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$raw_data = wp_remote_retrieve_body( $response );
		}

		if ( empty( $raw_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_result',
				__( 'Could not retrieve image data from OpenAI response during product integration.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $this->save_ai_result_to_temp( $raw_data, 'png', false );
	}

	/**
	 * Save raw AI-returned image bytes to a temporary file.
	 *
	 * @param string $image_data Raw image data (binary or base64-encoded string).
	 * @param string $format     File extension / format (e.g. 'png', 'jpg').
	 * @param bool   $is_base64  True when $image_data is base64-encoded; false for raw bytes.
	 * @return array|WP_Error Array with 'file_path' key, or WP_Error on failure.
	 */
	protected function save_ai_result_to_temp( $image_data, $format = 'png', $is_base64 = true ) {
		if ( $is_base64 ) {
			$raw_data = base64_decode( $image_data, true );
			if ( false === $raw_data ) {
				return new WP_Error(
					'wp_mcp_ai_decode_failed',
					__( 'Failed to decode base64 image data returned by AI during product integration.', 'mcp-ai-wpoos-pro' )
				);
			}
		} else {
			$raw_data = $image_data;
		}

		if ( empty( $raw_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_data',
				__( 'AI returned empty image data during product integration.', 'mcp-ai-wpoos-pro' )
			);
		}

		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-temp';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$file_name = 'ai-integrated-' . wp_generate_password( 12, false ) . '.' . $format;
		$file_path = trailingslashit( $temp_dir ) . $file_name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $file_path, $raw_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_save_failed',
				__( 'Failed to save AI-integrated image to temporary file.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array( 'file_path' => $file_path );
	}

	/**
	 * Detect the preferred AI provider based on availability and request.
	 *
	 * Gemini is preferred when a Gemini API key is configured (faster, high-quality
	 * integration via the nano-banana flash model). Falls back to OpenAI when Gemini
	 * is not configured or not available.
	 *
	 * @param string $requested_provider Caller's preference: 'auto', 'gemini', or 'openai'.
	 * @return string Resolved provider slug ('gemini' or 'openai'), or '' if none available.
	 */
	protected function detect_preferred_provider( $requested_provider ) {
		if ( 'openai' === $requested_provider ) {
			return 'openai';
		}

		if ( 'gemini' === $requested_provider ) {
			return 'gemini';
		}

		// Auto: prefer Gemini when its API key is configured.
		if ( $this->is_gemini_available() ) {
			return 'gemini';
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		if ( ! empty( $settings['openai_api_key'] ) && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return 'openai';
		}

		return '';
	}

	/**
	 * Check whether Gemini is available for image generation / editing.
	 *
	 * @return bool True when a Gemini API key is configured and the client class exists.
	 */
	protected function is_gemini_available() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		return ! empty( $settings['gemini_api_key'] ) && class_exists( 'WP_MCP_AI_Gemini_Client' );
	}

	/**
	 * Generate a scene background image using AI (provider-agnostic).
	 *
	 * Used in composite integration mode to generate a standalone background
	 * before the product is overlaid via Imagick/GD. Prefers Gemini when
	 * available, falls back to OpenAI.
	 *
	 * @param string $prompt      Scene description.
	 * @param string $aspect_ratio Aspect ratio.
	 * @param string $provider    Provider preference ('auto', 'gemini', 'openai').
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Array with file_path and attachment_id, or error.
	 */
	protected function generate_scene_image( $prompt, $aspect_ratio, $provider, $context ) {
		$resolved_provider = $this->detect_preferred_provider( $provider );

		if ( 'gemini' === $resolved_provider ) {
			$result = $this->generate_scene_image_gemini( $prompt, $aspect_ratio, $context );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			// Fall through to OpenAI if Gemini scene generation fails.
		}

		return $this->generate_scene_image_openai( $prompt, $aspect_ratio, $context );
	}

	/**
	 * Generate a scene background using Gemini image generation.
	 *
	 * @param string $prompt      Scene description.
	 * @param string $aspect_ratio Aspect ratio.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Array with file_path and attachment_id, or error.
	 */
	protected function generate_scene_image_gemini( $prompt, $aspect_ratio, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Gemini_Image' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dependency',
				__( 'Gemini image generation tool is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$tool   = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$args   = array(
			'prompt'       => $prompt,
			'aspect_ratio' => $this->normalise_aspect_ratio_for_gemini( $aspect_ratio ),
		);
		$result = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['attachment_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_result',
				__( 'Gemini scene generation returned invalid result.', 'mcp-ai-wpoos-pro' )
			);
		}

		$file_path = get_attached_file( $result['attachment_id'] );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Generated Gemini scene image file not found on disk.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'file_path'     => $file_path,
			'attachment_id' => $result['attachment_id'],
		);
	}

	/**
	 * Generate a scene background using OpenAI image generation.
	 *
	 * @param string $prompt       Scene description.
	 * @param string $aspect_ratio Aspect ratio.
	 * @param array  $context      Execution context.
	 * @return array|WP_Error Array with file_path and attachment_id, or error.
	 */
	protected function generate_scene_image_openai( $prompt, $aspect_ratio, $context ) {
		// Convert aspect ratio to size.
		$size = $this->aspect_ratio_to_size( $aspect_ratio );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_OpenAI_Image' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dependency',
				__( 'OpenAI image generation tool is required but not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$args   = array(
			'prompt' => $prompt,
			'size'   => $size,
		);
		$result = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['attachment_id'] ) || ! isset( $result['file_path'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_result',
				__( 'Scene generation returned invalid result.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'file_path'     => $result['file_path'],
			'attachment_id' => $result['attachment_id'],
		);
	}

	/**
	 * Normalise an aspect ratio string to a Gemini-supported value.
	 *
	 * Gemini supports: 1:1, 4:3, 3:4, 16:9, 9:16.
	 *
	 * @param string $aspect_ratio Input aspect ratio string.
	 * @return string Nearest Gemini-supported aspect ratio.
	 */
	protected function normalise_aspect_ratio_for_gemini( $aspect_ratio ) {
		$map = array(
			'1:1'  => '1:1',
			'4:5'  => '4:3',  // Closest Gemini-supported portrait.
			'16:9' => '16:9',
			'9:16' => '9:16',
			'3:2'  => '4:3',  // Closest Gemini-supported landscape.
			'2:3'  => '3:4',  // Closest Gemini-supported portrait.
			'auto' => '4:3',
		);

		return isset( $map[ $aspect_ratio ] ) ? $map[ $aspect_ratio ] : '4:3';
	}

	/**
	 * Convert aspect ratio to OpenAI image size.
	 *
	 * Note: OpenAI currently supports square (1024x1024) and rectangular (1536x1024, 1024x1536) sizes.
	 * For 4:5 and 16:9 ratios, we use the closest available size and handle cropping/padding in compositing.
	 *
	 * @param string $aspect_ratio Aspect ratio string.
	 * @return string OpenAI size parameter.
	 */
	protected function aspect_ratio_to_size( $aspect_ratio ) {
		$map = array(
			'1:1'  => '1024x1024',
			'4:5'  => '1024x1024', // Use square and adjust in compositing (OpenAI doesn't support 4:5).
			'16:9' => '1536x1024', // Closest available landscape size (3:2 ratio).
			'9:16' => '1024x1536', // Closest available portrait size (2:3 ratio).
			'3:2'  => '1536x1024', // Native OpenAI landscape size.
			'2:3'  => '1024x1536', // Native OpenAI portrait size.
			'auto' => 'auto',
		);

		return isset( $map[ $aspect_ratio ] ) ? $map[ $aspect_ratio ] : '1024x1024';
	}

	/**
	 * Generate a scene video using VEO.
	 *
	 * @param string $prompt                     Scene description.
	 * @param int    $duration                   Duration in seconds.
	 * @param string $aspect_ratio               Aspect ratio.
	 * @param int    $reference_image_attachment_id Reference image attachment ID (composited product scene).
	 * @param array  $context                    Execution context.
	 * @return array|WP_Error Array with attachment_id, or error.
	 */
	protected function generate_scene_video( $prompt, $duration, $aspect_ratio, $reference_image_attachment_id, $context ) {
		// Check if VEO video generation tool exists.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Veo_Video' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dependency',
				__( 'VEO video generation tool is required but not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Build prompt that emphasizes keeping the product static while animating the scene.
		$video_prompt = sprintf(
			'%s. The product in the image should remain perfectly still and static while the environment around it is subtly animated (gentle lighting changes, atmospheric effects, slight camera movement). Cinematic, professional, smooth motion.',
			$prompt
		);

		$args = array(
			'prompt'             => $video_prompt,
			'duration'           => $duration,
			'aspect_ratio'       => $aspect_ratio,
			'reference_image_id' => $reference_image_attachment_id,
			'save_to_media'      => true,
			'style'              => 'cinematic', // Use cinematic style for professional look.
		);

		$result = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['attachment_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_result',
				__( 'Video generation returned invalid result.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'attachment_id' => $result['attachment_id'],
			'url'           => isset( $result['url'] ) ? $result['url'] : '',
		);
	}

	/**
	 * Composite product onto background image.
	 *
	 * @param string $product_path  Path to product image (with or without background).
	 * @param string $background_path Path to background image.
	 * @param string $placement_hint Placement hint.
	 * @param float  $scale_factor  Scale factor.
	 * @return string|WP_Error Path to composited image or error.
	 */
	protected function composite_product_onto_image( $product_path, $background_path, $placement_hint, $scale_factor ) {
		if ( extension_loaded( 'imagick' ) ) {
			return $this->composite_with_imagick( $product_path, $background_path, $placement_hint, $scale_factor );
		}

		if ( extension_loaded( 'gd' ) ) {
			return $this->composite_with_gd( $product_path, $background_path, $placement_hint, $scale_factor );
		}

		return new WP_Error(
			'wp_mcp_ai_no_image_library',
			__( 'No image processing library available (Imagick or GD required).', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Composite using Imagick (preferred method).
	 *
	 * @param string $product_path    Path to product image.
	 * @param string $background_path Path to background image.
	 * @param string $placement_hint  Placement hint.
	 * @param float  $scale_factor    Scale factor.
	 * @return string|WP_Error Path to composited image or error.
	 */
	protected function composite_with_imagick( $product_path, $background_path, $placement_hint, $scale_factor ) {
		try {
			$background = new Imagick( $background_path );
			$product    = new Imagick( $product_path );

			// Get dimensions.
			$bg_width  = $background->getImageWidth();
			$bg_height = $background->getImageHeight();

			$prod_width  = $product->getImageWidth();
			$prod_height = $product->getImageHeight();

			// Scale product.
			$new_prod_width  = (int) ( $prod_width * $scale_factor );
			$new_prod_height = (int) ( $prod_height * $scale_factor );

			// Ensure product doesn't exceed 80% of background dimensions.
			$max_width  = (int) ( $bg_width * 0.8 );
			$max_height = (int) ( $bg_height * 0.8 );

			if ( $new_prod_width > $max_width || $new_prod_height > $max_height ) {
				$ratio           = min( $max_width / $new_prod_width, $max_height / $new_prod_height );
				$new_prod_width  = (int) ( $new_prod_width * $ratio );
				$new_prod_height = (int) ( $new_prod_height * $ratio );
			}

			$product->resizeImage( $new_prod_width, $new_prod_height, Imagick::FILTER_LANCZOS, 1 );

			// Calculate position based on placement hint.
			list( $x, $y ) = $this->calculate_position( $bg_width, $bg_height, $new_prod_width, $new_prod_height, $placement_hint );

			// Create shadow layer (subtle).
			$shadow = clone $product;
			$shadow->setImageBackgroundColor( new ImagickPixel( 'black' ) );
			$shadow->shadowImage( 80, 3, 5, 5 );

			// Composite shadow first.
			$background->compositeImage( $shadow, Imagick::COMPOSITE_MULTIPLY, $x + 5, $y + 5 );
			$shadow->destroy();

			// Composite product.
			$background->compositeImage( $product, Imagick::COMPOSITE_OVER, $x, $y );

			// Flatten and save.
			$background->flattenImages();
			$background->setImageFormat( 'png' );

			$upload_dir  = wp_upload_dir();
			$temp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-temp';
			$output_name = 'composited-' . wp_generate_password( 12, false ) . '.png';
			$output_path = trailingslashit( $temp_dir ) . $output_name;

			$background->writeImage( $output_path );

			// Clean up.
			$product->destroy();
			$background->destroy();

			return $output_path;

		} catch ( Exception $e ) {
			// Intentionally empty - error handled elsewhere.
			return new WP_Error(
				'wp_mcp_ai_composite_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Image compositing failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Composite using GD (fallback method).
	 *
	 * @param string $product_path    Path to product image.
	 * @param string $background_path Path to background image.
	 * @param string $placement_hint  Placement hint.
	 * @param float  $scale_factor    Scale factor.
	 * @return string|WP_Error Path to composited image or error.
	 */
	protected function composite_with_gd( $product_path, $background_path, $placement_hint, $scale_factor ) {
		// Detect image types.
		$bg_info   = getimagesize( $background_path );
		$prod_info = getimagesize( $product_path );

		if ( ! $bg_info || ! $prod_info ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_image',
				__( 'Failed to read image information.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Load images.
		$background = $this->gd_load_image( $background_path, $bg_info[2] );
		$product    = $this->gd_load_image( $product_path, $prod_info[2] );

		if ( ! $background || ! $product ) {
			return new WP_Error(
				'wp_mcp_ai_image_load_failed',
				__( 'Failed to load images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get dimensions.
		$bg_width    = imagesx( $background );
		$bg_height   = imagesy( $background );
		$prod_width  = imagesx( $product );
		$prod_height = imagesy( $product );

		// Scale product.
		$new_prod_width  = (int) ( $prod_width * $scale_factor );
		$new_prod_height = (int) ( $prod_height * $scale_factor );

		// Ensure product doesn't exceed 80% of background.
		$max_width  = (int) ( $bg_width * 0.8 );
		$max_height = (int) ( $bg_height * 0.8 );

		if ( $new_prod_width > $max_width || $new_prod_height > $max_height ) {
			$ratio           = min( $max_width / $new_prod_width, $max_height / $new_prod_height );
			$new_prod_width  = (int) ( $new_prod_width * $ratio );
			$new_prod_height = (int) ( $new_prod_height * $ratio );
		}

		// Create resized product.
		$resized_product = imagecreatetruecolor( $new_prod_width, $new_prod_height );
		imagealphablending( $resized_product, false );
		imagesavealpha( $resized_product, true );
		imagecopyresampled( $resized_product, $product, 0, 0, 0, 0, $new_prod_width, $new_prod_height, $prod_width, $prod_height );

		// Calculate position.
		list( $x, $y ) = $this->calculate_position( $bg_width, $bg_height, $new_prod_width, $new_prod_height, $placement_hint );

		// Enable alpha blending for compositing.
		imagealphablending( $background, true );

		// Composite product onto background.
		imagecopy( $background, $resized_product, $x, $y, 0, 0, $new_prod_width, $new_prod_height );

		// Save result.
		$upload_dir  = wp_upload_dir();
		$temp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-temp';
		$output_name = 'composited-' . wp_generate_password( 12, false ) . '.png';
		$output_path = trailingslashit( $temp_dir ) . $output_name;

		imagesavealpha( $background, true );
		$result = imagepng( $background, $output_path );

		// Clean up.
		imagedestroy( $background );
		imagedestroy( $product );
		imagedestroy( $resized_product );

		if ( ! $result ) {
			return new WP_Error(
				'wp_mcp_ai_save_failed',
				__( 'Failed to save composited image.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $output_path;
	}

	/**
	 * Load image using GD based on type.
	 *
	 * @param string $path Image path.
	 * @param int    $type Image type constant.
	 * @return resource|false Image resource or false on failure.
	 */
	protected function gd_load_image( $path, $type ) {
		switch ( $type ) {
			case IMAGETYPE_JPEG:
				return imagecreatefromjpeg( $path );
			case IMAGETYPE_PNG:
				return imagecreatefrompng( $path );
			case IMAGETYPE_GIF:
				return imagecreatefromgif( $path );
			case IMAGETYPE_WEBP:
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					return imagecreatefromwebp( $path );
				}
				break;
		}

		return false;
	}

	/**
	 * Calculate position for product placement.
	 *
	 * @param int    $bg_width       Background width.
	 * @param int    $bg_height      Background height.
	 * @param int    $prod_width     Product width.
	 * @param int    $prod_height    Product height.
	 * @param string $placement_hint Placement hint.
	 * @return array Array with x and y coordinates.
	 */
	protected function calculate_position( $bg_width, $bg_height, $prod_width, $prod_height, $placement_hint ) {
		$hint = strtolower( $placement_hint );

		// Parse common placement hints.
		if ( str_contains( $hint, 'center' ) || empty( $hint ) ) {
			return array(
				(int) ( ( $bg_width - $prod_width ) / 2 ),
				(int) ( ( $bg_height - $prod_height ) / 2 ),
			);
		}

		if ( str_contains( $hint, 'top' ) && str_contains( $hint, 'left' ) ) {
			return array(
				(int) ( $bg_width * 0.1 ),
				(int) ( $bg_height * 0.1 ),
			);
		}

		if ( str_contains( $hint, 'top' ) && str_contains( $hint, 'right' ) ) {
			return array(
				(int) ( $bg_width * 0.9 - $prod_width ),
				(int) ( $bg_height * 0.1 ),
			);
		}

		if ( str_contains( $hint, 'bottom' ) && str_contains( $hint, 'left' ) ) {
			return array(
				(int) ( $bg_width * 0.1 ),
				(int) ( $bg_height * 0.9 - $prod_height ),
			);
		}

		if ( str_contains( $hint, 'bottom' ) && str_contains( $hint, 'right' ) ) {
			return array(
				(int) ( $bg_width * 0.9 - $prod_width ),
				(int) ( $bg_height * 0.9 - $prod_height ),
			);
		}

		if ( str_contains( $hint, 'left' ) ) {
			return array(
				(int) ( $bg_width * 0.1 ),
				(int) ( ( $bg_height - $prod_height ) / 2 ),
			);
		}

		if ( str_contains( $hint, 'right' ) ) {
			return array(
				(int) ( $bg_width * 0.9 - $prod_width ),
				(int) ( ( $bg_height - $prod_height ) / 2 ),
			);
		}

		// Default to center.
		return array(
			(int) ( ( $bg_width - $prod_width ) / 2 ),
			(int) ( ( $bg_height - $prod_height ) / 2 ),
		);
	}

	/**
	 * Import composited asset to Media Library.
	 *
	 * @param string $file_path  Path to composited file.
	 * @param string $mode       Mode (image or video).
	 * @param array  $arguments  Original arguments.
	 * @param int    $user_id    User ID.
	 * @param array  $context    Execution context.
	 * @return int|WP_Error Attachment ID or error.
	 */
	protected function import_composited_asset( $file_path, $mode, $arguments, $user_id, $context ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Composited file not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Generate filename.
		$file_name = 'product-actualization-' . gmdate( 'Ymd-His' ) . '.png';

		// Read file contents.
		$file_contents = file_get_contents( $file_path );
		if ( false === $file_contents ) {
			return new WP_Error(
				'wp_mcp_ai_read_failed',
				__( 'Failed to read composited file.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $file_contents );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to upload composited file: %s', 'mcp-ai-wpoos-pro' ),
					$upload['error']
				)
			);
		}

		$uploaded_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( ! $uploaded_path || ! file_exists( $uploaded_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				__( 'Failed to save composited file to uploads directory.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Create attachment.
		$title = sprintf(
			/* translators: %s: scene prompt excerpt */
			__( 'Product Visualization: %s', 'mcp-ai-wpoos-pro' ),
			wp_trim_words( $arguments['scene_prompt'], 8, '...' )
		);

		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $uploaded_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $uploaded_path );
			return $attachment_id;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded_path );
		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Store metadata.
		$meta = array(
			'source'              => 'product_actualization',
			'mode'                => $mode,
			'original_product_id' => $arguments['product_attachment_id'],
			'scene_prompt'        => sanitize_textarea_field( $arguments['scene_prompt'] ),
			'aspect_ratio'        => sanitize_text_field( $arguments['aspect_ratio'] ),
			'background_mode'     => sanitize_key( $arguments['background_mode'] ),
			'integration_mode'    => isset( $arguments['integration_mode'] ) ? sanitize_key( $arguments['integration_mode'] ) : 'ai',
			'provider'            => isset( $arguments['provider'] ) ? sanitize_key( $arguments['provider'] ) : 'auto',
		);

		if ( ! empty( $arguments['placement_hint'] ) ) {
			$meta['placement_hint'] = sanitize_text_field( $arguments['placement_hint'] );
		}

		if ( isset( $arguments['scale_factor'] ) ) {
			$meta['scale_factor'] = floatval( $arguments['scale_factor'] );
		}

		update_post_meta( $attachment_id, '_wp_mcp_ai_product_actualization_meta', $meta );

		return $attachment_id;
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',                   // Pro tier tool.
			'requires-credentials',  // Requires a Gemini or OpenAI API key for scene generation/integration.
			'requires-capability',   // Requires upload_files capability.
			'write',                 // Creates media files.
			'async',                 // May take significant time.
			'consumes-tokens',       // Uses AI credits/tokens for scene generation and integration.
			'external-api',          // Makes external API calls (implies network-dependent).
		);
	}

	/**
	 * Get model requirements.
	 *
	 * @return array<string>
	 */
	public function get_model_requirements() {
		return array(
			'image-generation', // Requires image generation/editing capability (Gemini or OpenAI).
		);
	}

	/**
	 * Get tool rules.
	 *
	 * @return array
	 */
	public function get_tool_rules() {
		return array(
			'model_requirements'    => array(
				// Supports Gemini (preferred) and OpenAI for both scene generation and AI integration.
				// Google Gemini VEO is used for video mode.
				'providers'    => array( 'gemini', 'openai', 'google' ),
				'capabilities' => array( 'image-generation', 'image-editing' ),
				'required'     => true,
			),
			'parameter_constraints' => array(
				'required_fields' => array( 'product_attachment_id', 'scene_prompt' ),
				'optional_fields' => array( 'mode', 'aspect_ratio', 'duration_seconds', 'background_mode', 'placement_hint', 'scale_factor', 'integration_mode', 'provider' ),
			),
			'rate_limits'           => array(
				'requests_per_minute' => 5,
				'requests_per_hour'   => 30,
				'concurrent_requests' => 2,
			),
			'timeout_constraints'   => array(
				'recommended_timeout' => 90,  // Image mode (AI integration).
				'max_execution_time'  => 300, // Video mode can take up to 5 minutes.
			),
			'dependencies'          => array(
				'required_settings'   => array(
					// At least one of these API keys must be present.
					'gemini_api_key' => 'gemini_api_key', // Preferred for AI integration.
					'openai_api_key' => 'openai_api_key', // Fallback for AI integration and composite mode.
				),
				'required_extensions' => array( 'imagick', 'gd' ), // At least one required for composite mode.
				'optional_tools'      => array( 'generate_veo_video' ), // Required for video mode.
			),
			'orchestration_hints'   => array(
				'can_run_parallel' => true,
				'requires_lock'    => false,
				'cache_ttl'        => 0,
				'retry_strategy'   => 'exponential_backoff',
				'max_retries'      => 2,
			),
		);
	}
}
