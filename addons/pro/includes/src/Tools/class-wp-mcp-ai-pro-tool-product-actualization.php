<?php
/**
 * Product Actualization Tool - Pro add-on tool for product image compositing.
 *
 * Composites product images into AI-generated scenes while preserving original product pixels.
 *
 * @package WP_MCP_AI_Pro
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
		return __( 'Product Actualization tool requires either Imagick or GD PHP extension to be installed.', 'wp-mcp-ai-pro' );
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
		return __( 'Product Actualization', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Composite a product image into a generated scene or short video while preserving the original product pixels. Image mode creates static composited images. Video mode uses Google Gemini VEO to animate the scene around the product. Perfect for lifestyle marketing shots, social ads, and product visualization.', 'wp-mcp-ai-pro' );
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
					'type'        => 'integer',
					'description' => __( 'Attachment ID of the product image to be composited.', 'wp-mcp-ai-pro' ),
				),
				'mode'                  => array(
					'type'        => 'string',
					'enum'        => array( 'image', 'video' ),
					'default'     => 'image',
					'description' => __( 'Whether to create a still image or a short video.', 'wp-mcp-ai-pro' ),
				),
				'scene_prompt'          => array(
					'type'        => 'string',
					'description' => __( 'High-level description of the desired scene/background (e.g., "bright kitchen counter, morning light, shallow depth of field").', 'wp-mcp-ai-pro' ),
				),
				'aspect_ratio'          => array(
					'type'        => 'string',
					'enum'        => array( '1:1', '4:5', '16:9', '9:16', 'auto' ),
					'default'     => '16:9',
					'description' => __( 'Aspect ratio for the background generation.', 'wp-mcp-ai-pro' ),
				),
				'duration_seconds'      => array(
					'type'        => 'integer',
					'minimum'     => 4,
					'maximum'     => 10,
					'default'     => 6,
					'description' => __( 'Duration of the output video in seconds (video mode only).', 'wp-mcp-ai-pro' ),
				),
				'background_mode'       => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'remove', 'preserve' ),
					'default'     => 'auto',
					'description' => __( 'Control background removal strategy. "auto" detects transparency, "remove" forces removal, "preserve" keeps original background.', 'wp-mcp-ai-pro' ),
				),
				'placement_hint'        => array(
					'type'        => 'string',
					'description' => __( 'Optional hint for product placement (e.g., "center on a table", "bottom-right on a shelf", "floating in air").', 'wp-mcp-ai-pro' ),
				),
				'scale_factor'          => array(
					'type'        => 'number',
					'minimum'     => 0.1,
					'maximum'     => 2.0,
					'default'     => 1.0,
					'description' => __( 'Scale factor for the product relative to the scene (1.0 = natural size).', 'wp-mcp-ai-pro' ),
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
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		// Authentication check.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to use product actualization.', 'wp-mcp-ai-pro' ),
				array( 'status' => 403 )
			);
		}

		// Capability check for authenticated users.
		if ( $user_id ) {
			if ( ! user_can( $user_id, 'upload_files' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to create product visualizations.', 'wp-mcp-ai-pro' ),
					array( 'status' => 403 )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_site',
					__( 'You do not have access to this site.', 'wp-mcp-ai-pro' ),
					array( 'status' => 403 )
				);
			}
		}

		// Validate required parameters.
		$product_id    = isset( $arguments['product_attachment_id'] ) ? absint( $arguments['product_attachment_id'] ) : 0;
		$scene_prompt  = isset( $arguments['scene_prompt'] ) ? sanitize_textarea_field( $arguments['scene_prompt'] ) : '';
		$mode          = isset( $arguments['mode'] ) ? sanitize_key( $arguments['mode'] ) : 'image';
		$aspect_ratio  = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '16:9';
		$duration      = isset( $arguments['duration_seconds'] ) ? absint( $arguments['duration_seconds'] ) : 6;
		$bg_mode       = isset( $arguments['background_mode'] ) ? sanitize_key( $arguments['background_mode'] ) : 'auto';
		$placement     = isset( $arguments['placement_hint'] ) ? sanitize_text_field( $arguments['placement_hint'] ) : '';
		$scale_factor  = isset( $arguments['scale_factor'] ) ? floatval( $arguments['scale_factor'] ) : 1.0;

		if ( ! $product_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_product',
				__( 'Product attachment ID is required.', 'wp-mcp-ai-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $scene_prompt ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Scene prompt is required to generate the background.', 'wp-mcp-ai-pro' ),
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

		// Step 1: Validate and fetch product attachment.
		$file_path = get_attached_file( $product_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_product',
				__( 'Invalid product attachment ID - file not found.', 'wp-mcp-ai-pro' ),
				array( 'status' => 400 )
			);
		}

		// Validate file type.
		$mime_type = get_post_mime_type( $product_id );
		if ( ! $mime_type || ! str_starts_with( $mime_type, 'image/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_file_type',
				__( 'Product attachment must be an image.', 'wp-mcp-ai-pro' ),
				array( 'status' => 400 )
			);
		}

		// Step 2: Duplicate to avoid touching the original.
		$working_path = $this->duplicate_attachment_file( $product_id );
		if ( is_wp_error( $working_path ) ) {
			return $working_path;
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

		// Step 4: Generate the scene.
		if ( 'image' === $mode ) {
			$background_result = $this->generate_scene_image( $scene_prompt, $aspect_ratio, $context );
			if ( is_wp_error( $background_result ) ) {
				// Clean up working file.
				if ( file_exists( $working_path ) ) {
					wp_delete_file( $working_path );
				}
				return $background_result;
			}

			$background_path = $background_result['file_path'];

			// Step 5: Composite product onto image.
			$composited_path = $this->composite_product_onto_image(
				$working_path,
				$background_path,
				$placement,
				$scale_factor
			);

			if ( is_wp_error( $composited_path ) ) {
				// Clean up.
				if ( file_exists( $working_path ) ) {
					wp_delete_file( $working_path );
				}
				if ( file_exists( $background_path ) ) {
					wp_delete_file( $background_path );
				}
				return $composited_path;
			}

			// Clean up intermediate files.
			if ( file_exists( $working_path ) ) {
				wp_delete_file( $working_path );
			}
			if ( file_exists( $background_path ) ) {
				wp_delete_file( $background_path );
			}

			// Step 6: Save final asset to Media Library (image mode only).
			$attachment_id = $this->import_composited_asset( $composited_path, $mode, $arguments, $user_id, $context );

			if ( is_wp_error( $attachment_id ) ) {
				// Clean up.
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
			// Video mode - use VEO for video generation with composited image as reference.
			// First, create the composited image.
			$background_result = $this->generate_scene_image( $scene_prompt, $aspect_ratio, $context );
			if ( is_wp_error( $background_result ) ) {
				// Clean up working file.
				if ( file_exists( $working_path ) ) {
					wp_delete_file( $working_path );
				}
				return $background_result;
			}

			$background_path = $background_result['file_path'];

			// Composite product onto image first.
			$composited_path = $this->composite_product_onto_image(
				$working_path,
				$background_path,
				$placement,
				$scale_factor
			);

			if ( is_wp_error( $composited_path ) ) {
				// Clean up.
				if ( file_exists( $working_path ) ) {
					wp_delete_file( $working_path );
				}
				if ( file_exists( $background_path ) ) {
					wp_delete_file( $background_path );
				}
				return $composited_path;
			}

			// Import composited image temporarily to use as VEO reference.
			$composited_attachment_id = $this->import_composited_asset(
				$composited_path,
				'image',
				$arguments,
				$user_id,
				$context
			);

			// Clean up intermediate files.
			if ( file_exists( $working_path ) ) {
				wp_delete_file( $working_path );
			}
			if ( file_exists( $background_path ) ) {
				wp_delete_file( $background_path );
			}
			if ( file_exists( $composited_path ) ) {
				wp_delete_file( $composited_path );
			}

			if ( is_wp_error( $composited_attachment_id ) ) {
				return $composited_attachment_id;
			}

			// Generate video using VEO with the composited image as reference.
			$video_result = $this->generate_scene_video(
				$scene_prompt,
				$duration,
				$aspect_ratio,
				$composited_attachment_id,
				$context
			);

			// Clean up temporary composited image.
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
				__( 'Successfully created product visualization (ID: %1$d) in %2$s mode.', 'wp-mcp-ai-pro' ),
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
				__( 'Source file not found.', 'wp-mcp-ai-pro' )
			);
		}

		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-temp';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$file_info    = pathinfo( $file_path );
		$extension    = isset( $file_info['extension'] ) ? $file_info['extension'] : 'png';
		$temp_name    = 'product-' . $attachment_id . '-' . wp_generate_password( 12, false ) . '.' . $extension;
		$temp_path    = trailingslashit( $temp_dir ) . $temp_name;

		// Copy file.
		if ( ! copy( $file_path, $temp_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_copy_failed',
				__( 'Failed to duplicate product file.', 'wp-mcp-ai-pro' )
			);
		}

		return $temp_path;
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
					return $alpha !== Imagick::ALPHACHANNEL_UNDEFINED;
				} catch ( Exception $e ) {
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
	 * This is a placeholder that could integrate with services like:
	 * - remove.bg API
	 * - Cloudinary background removal
	 * - Local ML model
	 *
	 * For now, returns an error to indicate implementation is needed.
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

		// Default: Return error indicating implementation needed.
		return new WP_Error(
			'wp_mcp_ai_bg_removal_not_configured',
			__( 'Background removal is not configured. Please set background_mode to "preserve" or implement a custom background removal filter.', 'wp-mcp-ai-pro' )
		);
	}

	/**
	 * Generate a scene image using AI.
	 *
	 * @param string $prompt      Scene description.
	 * @param string $aspect_ratio Aspect ratio.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Array with file_path and attachment_id, or error.
	 */
	protected function generate_scene_image( $prompt, $aspect_ratio, $context ) {
		// Convert aspect ratio to size.
		$size = $this->aspect_ratio_to_size( $aspect_ratio );

		// Check if OpenAI image generation tool exists.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_OpenAI_Image' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dependency',
				__( 'OpenAI image generation tool is required but not available.', 'wp-mcp-ai-pro' )
			);
		}

		$tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image();

		$args = array(
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
				__( 'Scene generation returned invalid result.', 'wp-mcp-ai-pro' )
			);
		}

		return array(
			'file_path'     => $result['file_path'],
			'attachment_id' => $result['attachment_id'],
		);
	}

	/**
	 * Convert aspect ratio to OpenAI image size.
	 *
	 * Note: OpenAI currently supports square (1024x1024) and rectangular (1792x1024, 1024x1792) sizes.
	 * For 4:5 ratio, we use the closest available size and handle cropping/padding in compositing.
	 *
	 * @param string $aspect_ratio Aspect ratio string.
	 * @return string OpenAI size parameter.
	 */
	protected function aspect_ratio_to_size( $aspect_ratio ) {
		$map = array(
			'1:1'  => '1024x1024',
			'4:5'  => '1024x1024', // Use square and adjust in compositing (OpenAI doesn't support 4:5).
			'16:9' => '1792x1024',
			'9:16' => '1024x1792',
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
				__( 'VEO video generation tool is required but not available.', 'wp-mcp-ai-pro' )
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
				__( 'Video generation returned invalid result.', 'wp-mcp-ai-pro' )
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
			__( 'No image processing library available (Imagick or GD required).', 'wp-mcp-ai-pro' )
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
				$ratio = min( $max_width / $new_prod_width, $max_height / $new_prod_height );
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
			return new WP_Error(
				'wp_mcp_ai_composite_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Image compositing failed: %s', 'wp-mcp-ai-pro' ),
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
				__( 'Failed to read image information.', 'wp-mcp-ai-pro' )
			);
		}

		// Load images.
		$background = $this->gd_load_image( $background_path, $bg_info[2] );
		$product    = $this->gd_load_image( $product_path, $prod_info[2] );

		if ( ! $background || ! $product ) {
			return new WP_Error(
				'wp_mcp_ai_image_load_failed',
				__( 'Failed to load images.', 'wp-mcp-ai-pro' )
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
			$ratio = min( $max_width / $new_prod_width, $max_height / $new_prod_height );
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
				__( 'Failed to save composited image.', 'wp-mcp-ai-pro' )
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
				__( 'Composited file not found.', 'wp-mcp-ai-pro' )
			);
		}

		// Generate filename.
		$file_name = 'product-actualization-' . gmdate( 'Ymd-His' ) . '.png';

		// Read file contents.
		$file_contents = file_get_contents( $file_path );
		if ( false === $file_contents ) {
			return new WP_Error(
				'wp_mcp_ai_read_failed',
				__( 'Failed to read composited file.', 'wp-mcp-ai-pro' )
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
					__( 'Failed to upload composited file: %s', 'wp-mcp-ai-pro' ),
					$upload['error']
				)
			);
		}

		$uploaded_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( ! $uploaded_path || ! file_exists( $uploaded_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				__( 'Failed to save composited file to uploads directory.', 'wp-mcp-ai-pro' )
			);
		}

		// Create attachment.
		$title = sprintf(
			/* translators: %s: scene prompt excerpt */
			__( 'Product Visualization: %s', 'wp-mcp-ai-pro' ),
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
			'source'                => 'product_actualization',
			'mode'                  => $mode,
			'original_product_id'   => $arguments['product_attachment_id'],
			'scene_prompt'          => sanitize_textarea_field( $arguments['scene_prompt'] ),
			'aspect_ratio'          => sanitize_text_field( $arguments['aspect_ratio'] ),
			'background_mode'       => sanitize_key( $arguments['background_mode'] ),
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
			'requires-credentials',  // Requires OpenAI API credentials for scene generation.
			'requires-capability',   // Requires upload_files capability.
			'write',                 // Creates media files.
			'async',                 // May take significant time.
			'consumes-tokens',       // Uses AI credits/tokens for scene generation.
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
			'image-generation', // Requires image generation capability for scenes.
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
				'providers'    => array( 'openai', 'google' ), // OpenAI for images, Google Gemini VEO for videos.
				'capabilities' => array( 'image-generation' ), // Video generation via VEO when mode=video.
				'required'     => true,
			),
			'parameter_constraints' => array(
				'required_fields' => array( 'product_attachment_id', 'scene_prompt' ),
				'optional_fields' => array( 'mode', 'aspect_ratio', 'duration_seconds', 'background_mode', 'placement_hint', 'scale_factor' ),
			),
			'rate_limits'           => array(
				'requests_per_minute' => 5,
				'requests_per_hour'   => 30,
				'concurrent_requests' => 2,
			),
			'timeout_constraints'   => array(
				'recommended_timeout' => 90,  // Image mode.
				'max_execution_time'  => 300, // Video mode can take up to 5 minutes.
			),
			'dependencies'          => array(
				'required_settings'   => array(
					'api_key' => 'wp_mcp_ai_openai_api_key', // For image generation.
					// VEO uses Google API key configured in core settings.
				),
				'required_extensions' => array( 'imagick', 'gd' ), // At least one required.
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
