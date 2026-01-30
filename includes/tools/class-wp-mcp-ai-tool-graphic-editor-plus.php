<?php
/**
 * Comprehensive Graphic Editor Plus tool with local and AI-powered operations.
 *
 * Combines the best of both worlds:
 * - Local operations using WordPress Image Editor (ImageMagick/GD)
 * - AI-powered operations using Gemini for intelligent transformations
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';

/**
 * Graphic Editor Plus - Comprehensive graphic editing tool.
 *
 * Operations supported:
 *
 * LOCAL OPERATIONS (Fast, no API cost):
 * - add_logo: Overlay logo with positioning and transparency
 * - resize_graphic: Smart resize with format conversion
 * - expand_scene: Canvas expansion with background color
 *
 * AI-POWERED OPERATIONS (Intelligent, using Gemini):
 * - ai_enhance: AI-powered photo enhancement
 * - ai_style: Change image style (watercolor, sketch, etc.)
 * - ai_background: Remove or change background
 * - ai_retouch: General AI-powered retouching and edits
 */
class WP_MCP_AI_Tool_Graphic_Editor_Plus extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'graphic_editor_plus';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Graphic Editor Plus', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Comprehensive graphic editing tool with both local operations (logo overlay, smart resize, canvas expansion) and AI-powered features (style transfer, background removal, intelligent enhancement). Use local operations for speed and AI operations for intelligent transformations.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->get_source_parameters_schema(),
				array(
					'operation'          => array(
						'type'        => 'string',
						'description' => __( 'Operation to perform. LOCAL: add_logo, resize_graphic, expand_scene. AI-POWERED: ai_enhance, ai_style, ai_background, ai_retouch', 'mcp-ai-wpoos' ),
						'enum'        => array(
							'add_logo',
							'resize_graphic',
							'expand_scene',
							'ai_enhance',
							'ai_style',
							'ai_background',
							'ai_retouch',
						),
					),
					// Logo parameters (for add_logo operation).
					'logo_attachment_id' => array(
						'type'        => 'integer',
						'description' => __( 'Attachment ID of logo image. Required for add_logo.', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
					),
					'logo_url'           => array(
						'type'        => 'string',
						'description' => __( 'URL of logo image. Alternative to logo_attachment_id.', 'mcp-ai-wpoos' ),
						'format'      => 'uri',
					),
					'logo_position'      => array(
						'type'        => 'string',
						'description' => __( 'Logo position: top-left, top-right, bottom-left, bottom-right, center. Default: bottom-left', 'mcp-ai-wpoos' ),
						'enum'        => array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' ),
						'default'     => 'bottom-left',
					),
					'logo_scale'         => array(
						'type'        => 'number',
						'description' => __( 'Logo scale relative to image width (0.05-0.5). Default: 0.15', 'mcp-ai-wpoos' ),
						'minimum'     => 0.05,
						'maximum'     => 0.5,
						'default'     => 0.15,
					),
					'logo_margin'        => array(
						'type'        => 'integer',
						'description' => __( 'Margin in pixels from edge. Default: 20', 'mcp-ai-wpoos' ),
						'minimum'     => 0,
						'maximum'     => 500,
						'default'     => 20,
					),
					// Resize parameters (for resize_graphic operation).
					'target_width'       => array(
						'type'        => 'integer',
						'description' => __( 'Target width in pixels for resize_graphic.', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
						'maximum'     => 10000,
					),
					'target_height'      => array(
						'type'        => 'integer',
						'description' => __( 'Target height in pixels for resize_graphic.', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
						'maximum'     => 10000,
					),
					'output_format'      => array(
						'type'        => 'string',
						'description' => __( 'Output format: png, jpg, webp. Default: png', 'mcp-ai-wpoos' ),
						'enum'        => array( 'png', 'jpg', 'jpeg', 'webp' ),
						'default'     => 'png',
					),
					'maintain_ratio'     => array(
						'type'        => 'boolean',
						'description' => __( 'Maintain aspect ratio when resizing. Default: true', 'mcp-ai-wpoos' ),
						'default'     => true,
					),
					'quality'            => array(
						'type'        => 'integer',
						'description' => __( 'Output quality for JPG/WebP (1-100). Default: 90', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 90,
					),
					// Expand scene parameters (for expand_scene operation).
					'expand_direction'   => array(
						'type'        => 'string',
						'description' => __( 'Expansion direction: all, top, bottom, left, right, horizontal, vertical. Default: all', 'mcp-ai-wpoos' ),
						'enum'        => array( 'all', 'top', 'bottom', 'left', 'right', 'horizontal', 'vertical' ),
						'default'     => 'all',
					),
					'expand_pixels'      => array(
						'type'        => 'integer',
						'description' => __( 'Pixels to expand. Default: 50', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
						'maximum'     => 2000,
						'default'     => 50,
					),
					'background_color'   => array(
						'type'        => 'string',
						'description' => __( 'Background color (hex like #FF0000 or "transparent"). Default: transparent', 'mcp-ai-wpoos' ),
						'default'     => 'transparent',
					),
					// AI operation parameters (for AI-powered operations).
					'prompt'             => array(
						'type'        => 'string',
						'description' => __( 'Instruction for AI operations (ai_enhance, ai_style, ai_background, ai_retouch). Examples: "remove background", "convert to watercolor", "enhance brightness"', 'mcp-ai-wpoos' ),
					),
					'model'              => array(
						'type'        => 'string',
						'description' => __( 'Gemini model for AI operations. Default: gemini-2.0-flash-exp', 'mcp-ai-wpoos' ),
						'default'     => 'gemini-2.0-flash-exp',
					),
					'aspect_ratio'       => array(
						'type'        => 'string',
						'description' => __( 'Aspect ratio for AI operations: 1:1, 16:9, 4:3, 3:2, 9:16. Default: 1:1', 'mcp-ai-wpoos' ),
						'enum'        => array( '1:1', '16:9', '4:3', '3:2', '2:3', '9:16', '3:4' ),
						'default'     => '1:1',
					),
				)
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'media_processing',

			'pattern_compatibility' => array( 'sequential' ),

			'profession_tags'       => array( 'graphic_designer', 'photographer' ),

			'risk_level'            => 'standard',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates new media files.
			'mixed-mode',           // Some operations local, some use external APIs.
			'idempotent',           // Can be called multiple times safely.
			'performance-impact',   // Large images may temporarily affect performance.
			'pro-tool',             // Available only in full/pro version.
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

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to use Graphic Editor Plus.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'mcp-ai-wpoos' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Get operation type.
		$operation = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : '';

		$valid_operations = array( 'add_logo', 'resize_graphic', 'expand_scene', 'ai_enhance', 'ai_style', 'ai_background', 'ai_retouch' );
		if ( ! in_array( $operation, $valid_operations, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_operation', __( 'Invalid operation. Must be: add_logo, resize_graphic, expand_scene, ai_enhance, ai_style, ai_background, or ai_retouch.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		// Enrich arguments with metadata from context messages if available.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Route to appropriate handler.
		$local_operations = array( 'add_logo', 'resize_graphic', 'expand_scene' );
		$ai_operations    = array( 'ai_enhance', 'ai_style', 'ai_background', 'ai_retouch' );

		if ( in_array( $operation, $local_operations, true ) ) {
			return $this->execute_local_operation( $operation, $arguments, $user_id );
		} elseif ( in_array( $operation, $ai_operations, true ) ) {
			return $this->execute_ai_operation( $operation, $arguments, $user_id, $context );
		}

		return new WP_Error( 'wp_mcp_ai_invalid_operation', __( 'Operation not implemented.', 'mcp-ai-wpoos' ), array( 'status' => 500 ) );
	}

	/**
	 * Execute local operation (no AI, uses WordPress Image Editor).
	 *
	 * @param string $operation Operation name.
	 * @param array  $arguments Tool arguments.
	 * @param int    $user_id   User ID.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_local_operation( $operation, $arguments, $user_id ) {
		switch ( $operation ) {
			case 'add_logo':
				return $this->execute_add_logo( $arguments, $user_id );
			case 'resize_graphic':
				return $this->execute_resize_graphic( $arguments, $user_id );
			case 'expand_scene':
				return $this->execute_expand_scene( $arguments, $user_id );
			default:
				return new WP_Error( 'wp_mcp_ai_invalid_operation', __( 'Unknown local operation.', 'mcp-ai-wpoos' ) );
		}
	}

	/**
	 * Execute AI-powered operation (uses Gemini).
	 *
	 * @param string $operation Operation name.
	 * @param array  $arguments Tool arguments.
	 * @param int    $user_id   User ID.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_ai_operation( $operation, $arguments, $user_id, $context ) {
		// Validate that prompt is provided for AI operations.
		if ( empty( $arguments['prompt'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'AI operations require a prompt parameter describing the desired transformation.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		// Build intelligent prompt based on operation type.
		$base_prompt = sanitize_textarea_field( $arguments['prompt'] );
		$prompt      = $this->build_ai_prompt( $operation, $base_prompt );

		// Get source image.
		$source_image = $this->get_source_image_for_ai( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get AI parameters.
		$model        = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'gemini-2.0-flash-exp';
		$aspect_ratio = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '1:1';
		$mime_type    = isset( $arguments['output_format'] ) ? 'image/' . sanitize_text_field( $arguments['output_format'] ) : 'image/png';

		// Call Gemini for image editing.
		$client  = new WP_MCP_AI_Gemini_Client();
		$options = array(
			'model'        => $model,
			'aspect_ratio' => $aspect_ratio,
			'mime_type'    => $mime_type,
			'source_image' => $source_image,
		);

		$image = $client->edit_image( $prompt, $options );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( empty( $image['image'] ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_response', __( 'AI returned an empty image response.', 'mcp-ai-wpoos' ) );
		}

		// Store the result as an attachment.
		$file_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : '';
		$storage   = $this->store_ai_image_result( $image, $file_name, $prompt, $user_id, $operation );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$message = sprintf(
			/* translators: 1: operation name, 2: prompt */
			__( 'Successfully applied %1$s using AI: "%2$s"', 'mcp-ai-wpoos' ),
			str_replace( '_', ' ', $operation ),
			$base_prompt
		);

		$result = array(
			'attachment_id' => $storage['attachment_id'],
			'url'           => $storage['url'],
			'file_name'     => $storage['file_name'],
			'mime_type'     => $storage['mime_type'],
			'bytes'         => $storage['bytes'],
			'title'         => $storage['title'],
			'operation'     => $operation,
			'prompt'        => $prompt,
			'model'         => $model,
			'text'          => $message,
			'message'       => $message,
		);

		/**
		 * Filter the AI operation result.
		 *
		 * @param array $result    Result array.
		 * @param array $arguments Tool arguments.
		 */
		return apply_filters( 'wp_mcp_ai_graphic_editor_plus_ai_result', $result, $arguments );
	}

	/**
	 * Build intelligent prompt for AI operations.
	 *
	 * @param string $operation Operation name.
	 * @param string $base_prompt User's prompt.
	 * @return string Enhanced prompt.
	 */
	protected function build_ai_prompt( $operation, $base_prompt ) {
		$prompts = array(
			'ai_enhance'    => sprintf( 'Enhance this image: %s. Improve quality, lighting, and details while maintaining the original subject.', $base_prompt ),
			'ai_style'      => sprintf( 'Transform this image style: %s. Apply the artistic style or effect described.', $base_prompt ),
			'ai_background' => sprintf( 'Modify the background: %s. Change or remove the background as instructed while keeping the main subject.', $base_prompt ),
			'ai_retouch'    => sprintf( 'Retouch and edit this image: %s. Make the modifications described.', $base_prompt ),
		);

		return isset( $prompts[ $operation ] ) ? $prompts[ $operation ] : $base_prompt;
	}

	/**
	 * Get source image for AI operations.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Source image data or error.
	 */
	protected function get_source_image_for_ai( $arguments, $user_id ) {
		// This would use the same logic as the edit_gemini_image tool.
		// For now, return a placeholder structure.
		$image_editor = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $image_editor ) ) {
			return $image_editor;
		}

		// Get image as base64.
		$temp_file   = wp_tempnam();
		$save_result = $image_editor->save( $temp_file );

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$image_data = file_get_contents( $temp_file );
		wp_delete_file( $temp_file );

		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( false === $image_data ) {
			return new WP_Error( 'wp_mcp_ai_read_failed', __( 'Failed to read image data.', 'mcp-ai-wpoos' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return array(
			'data'      => base64_encode( $image_data ),
			'mime_type' => $image_editor->mime_type,
		);
	}

	/**
	 * Store AI-generated image as attachment.
	 *
	 * @param array  $image     Image data from Gemini.
	 * @param string $file_name Optional file name.
	 * @param string $prompt    Prompt used.
	 * @param int    $user_id   User ID.
	 * @param string $operation Operation name.
	 * @return array|WP_Error Storage result or error.
	 */
	protected function store_ai_image_result( $image, $file_name, $prompt, $user_id, $operation ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$image_data = base64_decode( $image['image'] );

		if ( false === $image_data ) {
			return new WP_Error( 'wp_mcp_ai_decode_failed', __( 'Failed to decode AI image data.', 'mcp-ai-wpoos' ) );
		}

		// Generate filename.
		if ( empty( $file_name ) ) {
			$file_name = sanitize_title( substr( $prompt, 0, 50 ) ) . '-' . $operation;
		}

		$mime_type = isset( $image['mime_type'] ) ? $image['mime_type'] : 'image/png';
		$extension = str_replace( 'image/', '', $mime_type );
		$file_name = $file_name . '.' . $extension;

		// Save to uploads directory.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . wp_unique_filename( $upload_dir['path'], $file_name );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$saved = file_put_contents( $file_path, $image_data );

		if ( false === $saved ) {
			return new WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save AI-generated image.', 'mcp-ai-wpoos' ) );
		}

		// Create attachment.
		$attachment = array(
			'guid'           => $upload_dir['url'] . '/' . basename( $file_path ),
			'post_mime_type' => $mime_type,
			'post_title'     => sanitize_text_field( $prompt ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $file_path, 0, true );

		if ( is_wp_error( $attach_id ) ) {
			wp_delete_file( $file_path );
			return $attach_id;
		}

		// Generate metadata.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		return array(
			'attachment_id' => $attach_id,
			'url'           => wp_get_attachment_url( $attach_id ),
			'file_name'     => basename( $file_path ),
			'mime_type'     => $mime_type,
			'bytes'         => filesize( $file_path ),
			'title'         => get_the_title( $attach_id ),
		);
	}

	/**
	 * Execute add logo operation (LOCAL).
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_add_logo( $arguments, $user_id ) {
		// Load source image.
		$image_editor = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $image_editor ) ) {
			return $image_editor;
		}

		// Load logo image.
		$logo_args = array();
		if ( isset( $arguments['logo_attachment_id'] ) ) {
			$logo_args['attachment_id'] = absint( $arguments['logo_attachment_id'] );
		} elseif ( isset( $arguments['logo_url'] ) ) {
			$logo_args['url'] = esc_url_raw( $arguments['logo_url'] );
		} else {
			if ( isset( $image_editor->temp_file ) ) {
				$this->delete_temp_file( $image_editor->temp_file );
			}
			return new WP_Error( 'wp_mcp_ai_missing_logo', __( 'Either logo_attachment_id or logo_url must be specified for add_logo operation.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		$logo_editor = $this->load_source_image( $logo_args, $user_id );
		if ( is_wp_error( $logo_editor ) ) {
			if ( isset( $image_editor->temp_file ) ) {
				$this->delete_temp_file( $image_editor->temp_file );
			}
			return $logo_editor;
		}

		// Get parameters.
		$position = isset( $arguments['logo_position'] ) ? sanitize_text_field( $arguments['logo_position'] ) : 'bottom-left';
		$scale    = isset( $arguments['logo_scale'] ) ? floatval( $arguments['logo_scale'] ) : 0.15;
		$margin   = isset( $arguments['logo_margin'] ) ? absint( $arguments['logo_margin'] ) : 20;

		$scale = max( 0.05, min( 0.5, $scale ) );

		// Get image dimensions.
		$image_size = $image_editor->get_size();
		$logo_size  = $logo_editor->get_size();

		// Calculate logo dimensions.
		$max_logo_width  = (int) round( $image_size['width'] * $scale );
		$logo_ratio      = $logo_size['width'] / $logo_size['height'];
		$new_logo_width  = min( $max_logo_width, $logo_size['width'] );
		$new_logo_height = (int) round( $new_logo_width / $logo_ratio );

		// Resize logo if needed.
		if ( $new_logo_width !== $logo_size['width'] ) {
			$resize_result = $logo_editor->resize( $new_logo_width, $new_logo_height, false );
			if ( is_wp_error( $resize_result ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				if ( isset( $logo_editor->temp_file ) ) {
					$this->delete_temp_file( $logo_editor->temp_file );
				}
				return $resize_result;
			}
			$logo_size = $logo_editor->get_size();
		}

		// Calculate position.
		$coords = $this->calculate_position( $image_size, $logo_size, $position, $margin );

		// Overlay using multi_resize method (WordPress doesn't have a native overlay method).
		// We'll need to use direct image resource manipulation.
		$overlay_result = $this->overlay_images( $image_editor, $logo_editor, $coords['x'], $coords['y'] );

		if ( isset( $logo_editor->temp_file ) ) {
			$this->delete_temp_file( $logo_editor->temp_file );
		}

		if ( is_wp_error( $overlay_result ) ) {
			if ( isset( $image_editor->temp_file ) ) {
				$this->delete_temp_file( $image_editor->temp_file );
			}
			return $overlay_result;
		}

		// Save as new attachment.
		$storage = $this->save_as_attachment( $image_editor, $arguments, $user_id, 'logo-added' );

		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$message = sprintf(
			/* translators: %s: logo position */
			__( 'Successfully added logo at %s position.', 'mcp-ai-wpoos' ),
			$position
		);

		return array(
			'attachment_id' => $storage['attachment_id'],
			'url'           => $storage['url'],
			'file_name'     => $storage['file_name'],
			'mime_type'     => $storage['mime_type'],
			'bytes'         => $storage['bytes'],
			'title'         => $storage['title'],
			'operation'     => 'add_logo',
			'logo_position' => $position,
			'logo_scale'    => $scale,
			'text'          => $message,
			'message'       => $message,
		);
	}

	/**
	 * Execute resize graphic operation (LOCAL).
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_resize_graphic( $arguments, $user_id ) {
		$image_editor = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $image_editor ) ) {
			return $image_editor;
		}

		$original_size = $image_editor->get_size();
		$width         = isset( $arguments['target_width'] ) ? absint( $arguments['target_width'] ) : 0;
		$height        = isset( $arguments['target_height'] ) ? absint( $arguments['target_height'] ) : 0;

		if ( ! $width && ! $height ) {
			if ( isset( $image_editor->temp_file ) ) {
				$this->delete_temp_file( $image_editor->temp_file );
			}
			return new WP_Error( 'wp_mcp_ai_missing_dimensions', __( 'Either target_width or target_height must be specified.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		$maintain_ratio = isset( $arguments['maintain_ratio'] ) ? (bool) $arguments['maintain_ratio'] : true;

		if ( $maintain_ratio ) {
			if ( $width && ! $height ) {
				$ratio  = $width / $original_size['width'];
				$height = (int) round( $original_size['height'] * $ratio );
			} elseif ( ! $width && $height ) {
				$ratio = $height / $original_size['height'];
				$width = (int) round( $original_size['width'] * $ratio );
			}
		} else {
			$width  = ! empty( $width ) ? $width : $original_size['width'];
			$height = ! empty( $height ) ? $height : $original_size['height'];
		}

		$result = $image_editor->resize( $width, $height, false );

		if ( is_wp_error( $result ) ) {
			if ( isset( $image_editor->temp_file ) ) {
				$this->delete_temp_file( $image_editor->temp_file );
			}
			return $result;
		}

		// Handle format conversion.
		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'png';
		$output_format = ( 'jpg' === $output_format ) ? 'jpeg' : $output_format;

		if ( in_array( $output_format, array( 'jpeg', 'webp' ), true ) ) {
			$quality = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 90;
			$image_editor->set_quality( max( 1, min( 100, $quality ) ) );
		}

		$arguments['target_format'] = $output_format;
		$storage                    = $this->save_as_attachment( $image_editor, $arguments, $user_id, 'resized-' . $output_format );

		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$new_size = isset( $storage['size'] ) ? $storage['size'] : array(
			'width'  => $width,
			'height' => $height,
		);

		$message = sprintf(
			/* translators: 1: original dimensions, 2: new dimensions, 3: format */
			__( 'Successfully resized from %1$s to %2$s (%3$s)', 'mcp-ai-wpoos' ),
			$original_size['width'] . 'x' . $original_size['height'],
			$new_size['width'] . 'x' . $new_size['height'],
			strtoupper( $output_format )
		);

		return array(
			'attachment_id'   => $storage['attachment_id'],
			'url'             => $storage['url'],
			'file_name'       => $storage['file_name'],
			'mime_type'       => $storage['mime_type'],
			'bytes'           => $storage['bytes'],
			'title'           => $storage['title'],
			'original_width'  => $original_size['width'],
			'original_height' => $original_size['height'],
			'new_width'       => $new_size['width'],
			'new_height'      => $new_size['height'],
			'output_format'   => $output_format,
			'operation'       => 'resize_graphic',
			'text'            => $message,
			'message'         => $message,
		);
	}

	/**
	 * Execute expand scene operation (LOCAL).
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_expand_scene( $arguments, $user_id ) {
		// Load source image.
		$image_editor = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $image_editor ) ) {
			return $image_editor;
		}

		$original_size = $image_editor->get_size();

		// Get parameters.
		$direction        = isset( $arguments['expand_direction'] ) ? sanitize_text_field( $arguments['expand_direction'] ) : 'all';
		$pixels           = isset( $arguments['expand_pixels'] ) ? absint( $arguments['expand_pixels'] ) : 50;
		$background_color = isset( $arguments['background_color'] ) ? sanitize_text_field( $arguments['background_color'] ) : 'transparent';

		// Validate direction.
		$valid_directions = array( 'all', 'top', 'bottom', 'left', 'right', 'horizontal', 'vertical' );
		if ( ! in_array( $direction, $valid_directions, true ) ) {
			if ( isset( $image_editor->temp_file ) ) {
				$this->delete_temp_file( $image_editor->temp_file );
			}
			return new WP_Error( 'wp_mcp_ai_invalid_direction', __( 'Invalid expansion direction.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		// Calculate new dimensions and offsets.
		$expansion = $this->calculate_expansion( $original_size, $direction, $pixels );

		// Parse background color.
		if ( 'transparent' !== $background_color ) {
			$color = $this->parse_hex_color( $background_color );
			if ( is_wp_error( $color ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return $color;
			}
		} else {
			$color = 'transparent';
		}

		// Perform canvas expansion.
		$expand_result = $this->expand_canvas( $image_editor, $expansion, $color );

		if ( is_wp_error( $expand_result ) ) {
			if ( isset( $image_editor->temp_file ) ) {
				$this->delete_temp_file( $image_editor->temp_file );
			}
			return $expand_result;
		}

		// Save as new attachment.
		$storage = $this->save_as_attachment( $image_editor, $arguments, $user_id, 'expanded' );

		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$message = sprintf(
			/* translators: 1: direction, 2: pixels, 3: original dimensions, 4: new dimensions */
			__( 'Successfully expanded canvas %1$s by %2$d pixels from %3$s to %4$s.', 'mcp-ai-wpoos' ),
			$direction,
			$pixels,
			$original_size['width'] . 'x' . $original_size['height'],
			$expansion['new_width'] . 'x' . $expansion['new_height']
		);

		return array(
			'attachment_id'   => $storage['attachment_id'],
			'url'             => $storage['url'],
			'file_name'       => $storage['file_name'],
			'mime_type'       => $storage['mime_type'],
			'bytes'           => $storage['bytes'],
			'title'           => $storage['title'],
			'operation'       => 'expand_scene',
			'direction'       => $direction,
			'pixels'          => $pixels,
			'original_width'  => $original_size['width'],
			'original_height' => $original_size['height'],
			'new_width'       => $expansion['new_width'],
			'new_height'      => $expansion['new_height'],
			'text'            => $message,
			'message'         => $message,
		);
	}

	/**
	 * Calculate position coordinates.
	 *
	 * @param array  $image_size   Image size.
	 * @param array  $overlay_size Overlay size.
	 * @param string $position     Position name.
	 * @param int    $margin       Margin pixels.
	 * @return array Coordinates.
	 */
	protected function calculate_position( $image_size, $overlay_size, $position, $margin ) {
		$coords = array(
			'x' => 0,
			'y' => 0,
		);

		switch ( $position ) {
			case 'top-left':
				$coords = array(
					'x' => $margin,
					'y' => $margin,
				);
				break;
			case 'top-right':
				$coords['x'] = $image_size['width'] - $overlay_size['width'] - $margin;
				$coords['y'] = $margin;
				break;
			case 'bottom-left':
				$coords['x'] = $margin;
				$coords['y'] = $image_size['height'] - $overlay_size['height'] - $margin;
				break;
			case 'bottom-right':
				$coords['x'] = $image_size['width'] - $overlay_size['width'] - $margin;
				$coords['y'] = $image_size['height'] - $overlay_size['height'] - $margin;
				break;
			case 'center':
				$coords['x'] = (int) round( ( $image_size['width'] - $overlay_size['width'] ) / 2 );
				$coords['y'] = (int) round( ( $image_size['height'] - $overlay_size['height'] ) / 2 );
				break;
		}

		$coords['x'] = max( 0, $coords['x'] );
		$coords['y'] = max( 0, $coords['y'] );

		return $coords;
	}

	/**
	 * Calculate expansion dimensions and offsets.
	 *
	 * @param array  $original_size Original image size with width and height.
	 * @param string $direction     Expansion direction.
	 * @param int    $pixels        Pixels to expand.
	 * @return array Expansion data with new_width, new_height, offset_x, offset_y.
	 */
	protected function calculate_expansion( $original_size, $direction, $pixels ) {
		$new_width  = $original_size['width'];
		$new_height = $original_size['height'];
		$offset_x   = 0;
		$offset_y   = 0;

		switch ( $direction ) {
			case 'all':
				$new_width  += $pixels * 2;
				$new_height += $pixels * 2;
				$offset_x    = $pixels;
				$offset_y    = $pixels;
				break;
			case 'top':
				$new_height += $pixels;
				$offset_y    = $pixels;
				break;
			case 'bottom':
				$new_height += $pixels;
				break;
			case 'left':
				$new_width += $pixels;
				$offset_x   = $pixels;
				break;
			case 'right':
				$new_width += $pixels;
				break;
			case 'horizontal':
				$new_width += $pixels * 2;
				$offset_x   = $pixels;
				break;
			case 'vertical':
				$new_height += $pixels * 2;
				$offset_y    = $pixels;
				break;
		}

		return array(
			'new_width'  => $new_width,
			'new_height' => $new_height,
			'offset_x'   => $offset_x,
			'offset_y'   => $offset_y,
		);
	}

	/**
	 * Parse hex color string into RGB components.
	 *
	 * @param string $hex_color Hex color string (with or without #).
	 * @return array|WP_Error RGB array with r, g, b keys or error.
	 */
	protected function parse_hex_color( $hex_color ) {
		// Remove # if present.
		$hex = ltrim( $hex_color, '#' );

		// Validate hex string.
		if ( ! preg_match( '/^[0-9A-Fa-f]{3}$|^[0-9A-Fa-f]{6}$/', $hex ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_color', __( 'Invalid hex color format. Use #RRGGBB or #RGB.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		// Convert 3-digit hex to 6-digit.
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		// Parse RGB components.
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		return array(
			'r' => $r,
			'g' => $g,
			'b' => $b,
		);
	}

	/**
	 * Expand canvas of an image.
	 *
	 * @param WP_Image_Editor $image_editor Image editor instance.
	 * @param array           $expansion    Expansion data from calculate_expansion().
	 * @param array|string    $color        RGB color array or 'transparent'.
	 * @return true|WP_Error True on success, error on failure.
	 */
	protected function expand_canvas( $image_editor, $expansion, $color ) {
		$image_resource = $image_editor->get_image();

		if ( is_wp_error( $image_resource ) ) {
			return new WP_Error( 'wp_mcp_ai_resource_error', __( 'Failed to get image resource.', 'mcp-ai-wpoos' ) );
		}

		$new_width  = $expansion['new_width'];
		$new_height = $expansion['new_height'];
		$offset_x   = $expansion['offset_x'];
		$offset_y   = $expansion['offset_y'];

		// Handle ImageMagick.
		if ( $image_resource instanceof Imagick ) {
			try {
				// Set background color.
				if ( 'transparent' === $color ) {
					$background = new ImagickPixel( 'transparent' );
				} else {
					$background = new ImagickPixel( sprintf( 'rgb(%d,%d,%d)', $color['r'], $color['g'], $color['b'] ) );
				}

				// Extend the image (canvas expansion).
				$image_resource->extentImage( $new_width, $new_height, -$offset_x, -$offset_y );
				$image_resource->setImageBackgroundColor( $background );

				return true;
			} catch ( Exception $e ) {
				return new WP_Error( 'wp_mcp_ai_imagick_error', $e->getMessage() );
			}
		}

		// Handle GD.
		if ( is_resource( $image_resource ) || ( is_object( $image_resource ) && get_class( $image_resource ) === 'GdImage' ) ) {
			// Create new canvas.
			$new_canvas = imagecreatetruecolor( $new_width, $new_height );

			// Set up transparency or background color.
			if ( 'transparent' === $color ) {
				// Enable alpha blending.
				imagealphablending( $new_canvas, false );
				imagesavealpha( $new_canvas, true );

				// Fill with transparent color.
				$transparent = imagecolorallocatealpha( $new_canvas, 0, 0, 0, 127 );
				imagefill( $new_canvas, 0, 0, $transparent );
			} else {
				// Fill with solid color.
				$bg_color = imagecolorallocate( $new_canvas, $color['r'], $color['g'], $color['b'] );
				imagefill( $new_canvas, 0, 0, $bg_color );
			}

			// Enable alpha blending for copying.
			imagealphablending( $new_canvas, true );

			// Copy original image onto new canvas.
			$original_size = $image_editor->get_size();
			$result        = imagecopy( $new_canvas, $image_resource, $offset_x, $offset_y, 0, 0, $original_size['width'], $original_size['height'] );

			if ( ! $result ) {
				imagedestroy( $new_canvas );
				return new WP_Error( 'wp_mcp_ai_gd_error', __( 'Failed to expand canvas with GD.', 'mcp-ai-wpoos' ) );
			}

			// Replace the image resource in the editor.
			// For GD editors, we need to use reflection to set the resource.
			if ( method_exists( $image_editor, 'update_image' ) ) {
				$image_editor->update_image( $new_canvas );
			} else {
				// Fallback: Destroy old resource and set new one via reflection.
				imagedestroy( $image_resource );
				$reflection = new ReflectionClass( $image_editor );
				$property   = $reflection->getProperty( 'image' );
				$property->setAccessible( true );
				$property->setValue( $image_editor, $new_canvas );

				// Update size property.
				$size_property = $reflection->getProperty( 'size' );
				$size_property->setAccessible( true );
				$size_property->setValue(
					$image_editor,
					array(
						'width'  => $new_width,
						'height' => $new_height,
					)
				);
			}

			return true;
		}

		return new WP_Error( 'wp_mcp_ai_unsupported', __( 'Unsupported image editor type for canvas expansion.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Overlay images using direct image manipulation.
	 *
	 * @param WP_Image_Editor $base_editor    Base image editor.
	 * @param WP_Image_Editor $overlay_editor Overlay image editor.
	 * @param int             $x              X position.
	 * @param int             $y              Y position.
	 * @return true|WP_Error True on success, error on failure.
	 */
	protected function overlay_images( $base_editor, $overlay_editor, $x, $y ) {
		$base_resource    = $base_editor->get_image();
		$overlay_resource = $overlay_editor->get_image();

		if ( is_wp_error( $base_resource ) || is_wp_error( $overlay_resource ) ) {
			return new WP_Error( 'wp_mcp_ai_resource_error', __( 'Failed to get image resources.', 'mcp-ai-wpoos' ) );
		}

		$overlay_size = $overlay_editor->get_size();

		// Handle ImageMagick.
		if ( $base_resource instanceof Imagick && $overlay_resource instanceof Imagick ) {
			try {
				$base_resource->compositeImage( $overlay_resource, Imagick::COMPOSITE_OVER, $x, $y );
				return true;
			} catch ( Exception $e ) {
				return new WP_Error( 'wp_mcp_ai_imagick_error', $e->getMessage() );
			}
		}

		// Handle GD.
		if ( is_resource( $base_resource ) || ( is_object( $base_resource ) && get_class( $base_resource ) === 'GdImage' ) ) {
			imagealphablending( $base_resource, true );
			imagesavealpha( $base_resource, true );

			$result = imagecopy( $base_resource, $overlay_resource, $x, $y, 0, 0, $overlay_size['width'], $overlay_size['height'] );

			return $result ? true : new WP_Error( 'wp_mcp_ai_gd_error', __( 'GD overlay failed.', 'mcp-ai-wpoos' ) );
		}

		return new WP_Error( 'wp_mcp_ai_unsupported', __( 'Unsupported image editor type.', 'mcp-ai-wpoos' ) );
	}
}
