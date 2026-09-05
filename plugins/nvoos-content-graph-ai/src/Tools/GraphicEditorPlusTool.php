<?php
/**
 * Graphic Editor Plus tool (D8 Cluster 2c-5 port of the base plugin's
 * WP_MCP_AI_Tool_Graphic_Editor_Plus — byte-identical slug, schema,
 * error codes, envelopes, local-operation pixel logic, and Gemini
 * image-edit flow).
 *
 * The base tool extends WP_MCP_AI_Tool_Image_Base; the inherited
 * helpers it uses (source schema, argument enrichment, image loading,
 * attachment saving) are inlined as private methods so the port is
 * standalone-safe. Monolith installs reuse the base
 * WP_MCP_AI_Gemini_Client::edit_image() and the base message-attachment
 * index for file_id resolution; standalone installs keep local
 * operations identical and return wp_mcp_ai_client_unavailable for the
 * AI-powered operations (the addon's provider clients expose no
 * equivalent image-edit endpoint).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Comprehensive graphic editing tool with local operations (logo
 * overlay, smart resize, canvas expansion) and AI-powered features
 * (style transfer, background removal, intelligent enhancement).
 */
class GraphicEditorPlusTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'graphic_editor_plus';
	}

	public function getName(): string {
		return __( 'Graphic Editor Plus', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Comprehensive graphic editing tool with both local operations (logo overlay, smart resize, canvas expansion) and AI-powered features (style transfer, background removal, intelligent enhancement). Use local operations for speed and AI operations for intelligent transformations.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->get_source_parameters_schema(),
				array(
					'operation'          => array(
						'type'        => 'string',
						'description' => __( 'Operation to perform. LOCAL: add_logo, resize_graphic, expand_scene. AI-POWERED: ai_enhance, ai_style, ai_background, ai_retouch', 'nvoos-content-graph-ai' ),
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
						'description' => __( 'Attachment ID of logo image. Required for add_logo.', 'nvoos-content-graph-ai' ),
						'minimum'     => 1,
					),
					'logo_url'           => array(
						'type'        => 'string',
						'description' => __( 'URL of logo image. Alternative to logo_attachment_id.', 'nvoos-content-graph-ai' ),
						'format'      => 'uri',
					),
					'logo_position'      => array(
						'type'        => 'string',
						'description' => __( 'Logo position: top-left, top-right, bottom-left, bottom-right, center. Default: bottom-left', 'nvoos-content-graph-ai' ),
						'enum'        => array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' ),
						'default'     => 'bottom-left',
					),
					'logo_scale'         => array(
						'type'        => 'number',
						'description' => __( 'Logo scale relative to image width (0.05-0.5). Default: 0.15', 'nvoos-content-graph-ai' ),
						'minimum'     => 0.05,
						'maximum'     => 0.5,
						'default'     => 0.15,
					),
					'logo_margin'        => array(
						'type'        => 'integer',
						'description' => __( 'Margin in pixels from edge. Default: 20', 'nvoos-content-graph-ai' ),
						'minimum'     => 0,
						'maximum'     => 500,
						'default'     => 20,
					),
					// Resize parameters (for resize_graphic operation).
					'target_width'       => array(
						'type'        => 'integer',
						'description' => __( 'Target width in pixels for resize_graphic.', 'nvoos-content-graph-ai' ),
						'minimum'     => 1,
						'maximum'     => 10000,
					),
					'target_height'      => array(
						'type'        => 'integer',
						'description' => __( 'Target height in pixels for resize_graphic.', 'nvoos-content-graph-ai' ),
						'minimum'     => 1,
						'maximum'     => 10000,
					),
					'output_format'      => array(
						'type'        => 'string',
						'description' => __( 'Output format: png, jpg, webp. Default: png', 'nvoos-content-graph-ai' ),
						'enum'        => array( 'png', 'jpg', 'jpeg', 'webp' ),
						'default'     => 'png',
					),
					'maintain_ratio'     => array(
						'type'        => 'boolean',
						'description' => __( 'Maintain aspect ratio when resizing. Default: true', 'nvoos-content-graph-ai' ),
						'default'     => true,
					),
					'quality'            => array(
						'type'        => 'integer',
						'description' => __( 'Output quality for JPG/WebP (1-100). Default: 90', 'nvoos-content-graph-ai' ),
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 90,
					),
					// Expand scene parameters (for expand_scene operation).
					'expand_direction'   => array(
						'type'        => 'string',
						'description' => __( 'Expansion direction: all, top, bottom, left, right, horizontal, vertical. Default: all', 'nvoos-content-graph-ai' ),
						'enum'        => array( 'all', 'top', 'bottom', 'left', 'right', 'horizontal', 'vertical' ),
						'default'     => 'all',
					),
					'expand_pixels'      => array(
						'type'        => 'integer',
						'description' => __( 'Pixels to expand. Default: 50', 'nvoos-content-graph-ai' ),
						'minimum'     => 1,
						'maximum'     => 2000,
						'default'     => 50,
					),
					'background_color'   => array(
						'type'        => 'string',
						'description' => __( 'Background color (hex like #FF0000 or "transparent"). Default: transparent', 'nvoos-content-graph-ai' ),
						'default'     => 'transparent',
					),
					// AI operation parameters (for AI-powered operations).
					'prompt'             => array(
						'type'        => 'string',
						'description' => __( 'Instruction for AI operations (ai_enhance, ai_style, ai_background, ai_retouch). Examples: "remove background", "convert to watercolor", "enhance brightness"', 'nvoos-content-graph-ai' ),
					),
					'model'              => array(
						'type'        => 'string',
						'description' => __( 'Gemini model for AI operations. Default: gemini-2.0-flash-exp', 'nvoos-content-graph-ai' ),
						'default'     => 'gemini-2.0-flash-exp',
					),
					'aspect_ratio'       => array(
						'type'        => 'string',
						'description' => __( 'Aspect ratio for AI operations: 1:1, 16:9, 4:3, 3:2, 9:16. Default: 1:1', 'nvoos-content-graph-ai' ),
						'enum'        => array( '1:1', '16:9', '4:3', '3:2', '2:3', '9:16', '3:4' ),
						'default'     => '1:1',
					),
				)
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'upload_files';
	}

	public function getCapabilityFlags(): array {
		return array(
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates new media files.
			'mixed-mode',           // Some operations local, some use external APIs.
			'idempotent',           // Can be called multiple times safely.
			'performance-impact',   // Large images may temporarily affect performance.
			'pro-tool',             // Available only in full/pro version.
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to use Graphic Editor Plus.', 'nvoos-content-graph-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'nvoos-content-graph-ai' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
		}

		// Get operation type.
		$operation = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : '';

		$valid_operations = array( 'add_logo', 'resize_graphic', 'expand_scene', 'ai_enhance', 'ai_style', 'ai_background', 'ai_retouch' );
		if ( ! in_array( $operation, $valid_operations, true ) ) {
			return new \WP_Error( 'wp_mcp_ai_invalid_operation', __( 'Invalid operation. Must be: add_logo, resize_graphic, expand_scene, ai_enhance, ai_style, ai_background, or ai_retouch.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
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

		return new \WP_Error( 'wp_mcp_ai_invalid_operation', __( 'Operation not implemented.', 'nvoos-content-graph-ai' ), array( 'status' => 500 ) );
	}

	/**
	 * Execute local operation (no AI, uses WordPress Image Editor).
	 *
	 * @param string $operation Operation name.
	 * @param array  $arguments Tool arguments.
	 * @param int    $user_id   User ID.
	 * @return array|\WP_Error Tool results or error.
	 */
	private function execute_local_operation( $operation, $arguments, $user_id ) {
		switch ( $operation ) {
			case 'add_logo':
				return $this->execute_add_logo( $arguments, $user_id );
			case 'resize_graphic':
				return $this->execute_resize_graphic( $arguments, $user_id );
			case 'expand_scene':
				return $this->execute_expand_scene( $arguments, $user_id );
			default:
				return new \WP_Error( 'wp_mcp_ai_invalid_operation', __( 'Unknown local operation.', 'nvoos-content-graph-ai' ) );
		}
	}

	/**
	 * Execute AI-powered operation (uses Gemini).
	 *
	 * @param string $operation Operation name.
	 * @param array  $arguments Tool arguments.
	 * @param int    $user_id   User ID.
	 * @param array  $context   Execution context.
	 * @return array|\WP_Error Tool results or error.
	 */
	private function execute_ai_operation( $operation, $arguments, $user_id, $context ) {
		// Validate that prompt is provided for AI operations.
		if ( empty( $arguments['prompt'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_missing_prompt', __( 'AI operations require a prompt parameter describing the desired transformation.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
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
		$options = array(
			'model'        => $model,
			'aspect_ratio' => $aspect_ratio,
			'mime_type'    => $mime_type,
			'source_image' => $source_image,
		);

		$image = $this->call_edit_image( $prompt, $options );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( empty( $image['image'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_empty_response', __( 'AI returned an empty image response.', 'nvoos-content-graph-ai' ) );
		}

		// Store the result as an attachment.
		$file_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : '';
		$storage   = $this->store_ai_image_result( $image, $file_name, $prompt, $user_id, $operation );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$message = sprintf(
			/* translators: 1: operation name, 2: prompt */
			__( 'Successfully applied %1$s using AI: "%2$s"', 'nvoos-content-graph-ai' ),
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
	 * @param string $operation   Operation name.
	 * @param string $base_prompt User's prompt.
	 * @return string Enhanced prompt.
	 */
	private function build_ai_prompt( $operation, $base_prompt ) {
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
	 * @return array|\WP_Error Source image data or error.
	 */
	private function get_source_image_for_ai( $arguments, $user_id ) {
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin or temp file; WP_Filesystem is not available in this REST/cron/tool execution context.
		$image_data = file_get_contents( $temp_file );
		wp_delete_file( $temp_file );

		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( false === $image_data ) {
			return new \WP_Error( 'wp_mcp_ai_read_failed', __( 'Failed to read image data.', 'nvoos-content-graph-ai' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used to encode binary image data for API transmission, not for obfuscation.
		$encoded_data = base64_encode( $image_data );

		return array(
			'data'      => $encoded_data,
			// WP_Image_Editor::get_mime_type() is protected; use the MIME type returned
			// by save(), which provides the same value via the public 'mime-type' key.
			'mime_type' => isset( $save_result['mime-type'] ) ? $save_result['mime-type'] : '',
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
	 * @return array|\WP_Error Storage result or error.
	 */
	private function store_ai_image_result( $image, $file_name, $prompt, $user_id, $operation ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64_decode used to decode binary image/file data received from the API, not for code obfuscation.
		$image_data = base64_decode( $image['image'] );

		if ( false === $image_data ) {
			return new \WP_Error( 'wp_mcp_ai_decode_failed', __( 'Failed to decode AI image data.', 'nvoos-content-graph-ai' ) );
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to WordPress uploads directory (wp_upload_dir() path); never to plugin directory. WP_Filesystem is not available in this REST/cron/tool execution context.
		$saved = file_put_contents( $file_path, $image_data );

		if ( false === $saved ) {
			return new \WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save AI-generated image.', 'nvoos-content-graph-ai' ) );
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
	 * Call the Gemini image-editing endpoint (per-mode AI-call seam).
	 *
	 * Monolith installs reuse the base client verbatim; standalone the
	 * addon's provider clients expose no equivalent image-edit endpoint,
	 * so the call degrades to a WP_Error carrying the base error-code
	 * vocabulary.
	 *
	 * @param string $prompt  Enhanced edit prompt.
	 * @param array  $options Model/aspect-ratio/mime-type/source options.
	 * @return array|\WP_Error Image payload or error.
	 */
	private function call_edit_image( $prompt, $options ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			$client = new \WP_MCP_AI_Gemini_Client();

			return $client->edit_image( $prompt, $options );
		}

		return new \WP_Error(
			'wp_mcp_ai_client_unavailable',
			__( 'The Gemini image editing client is not available. AI image operations require the NV oOS base plugin.', 'nvoos-content-graph-ai' )
		);
	}

	/**
	 * Execute add logo operation (LOCAL).
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return array|\WP_Error Tool results or error.
	 */
	private function execute_add_logo( $arguments, $user_id ) {
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
			return new \WP_Error( 'wp_mcp_ai_missing_logo', __( 'Either logo_attachment_id or logo_url must be specified for add_logo operation.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
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
			__( 'Successfully added logo at %s position.', 'nvoos-content-graph-ai' ),
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
	 * @return array|\WP_Error Tool results or error.
	 */
	private function execute_resize_graphic( $arguments, $user_id ) {
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
			return new \WP_Error( 'wp_mcp_ai_missing_dimensions', __( 'Either target_width or target_height must be specified.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
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
			__( 'Successfully resized from %1$s to %2$s (%3$s)', 'nvoos-content-graph-ai' ),
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
	 * @return array|\WP_Error Tool results or error.
	 */
	private function execute_expand_scene( $arguments, $user_id ) {
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
			return new \WP_Error( 'wp_mcp_ai_invalid_direction', __( 'Invalid expansion direction.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
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
			__( 'Successfully expanded canvas %1$s by %2$d pixels from %3$s to %4$s.', 'nvoos-content-graph-ai' ),
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
	private function calculate_position( $image_size, $overlay_size, $position, $margin ) {
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
	private function calculate_expansion( $original_size, $direction, $pixels ) {
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
	 * @return array|\WP_Error RGB array with r, g, b keys or error.
	 */
	private function parse_hex_color( $hex_color ) {
		// Remove # if present.
		$hex = ltrim( $hex_color, '#' );

		// Validate hex string.
		if ( ! preg_match( '/^[0-9A-Fa-f]{3}$|^[0-9A-Fa-f]{6}$/', $hex ) ) {
			return new \WP_Error( 'wp_mcp_ai_invalid_color', __( 'Invalid hex color format. Use #RRGGBB or #RGB.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
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
	 * Get the underlying image resource from a WordPress image editor.
	 *
	 * WP_Image_Editor_Imagick::get_image() and WP_Image_Editor_GD::get_image()
	 * are unavailable on WordPress 6.9+/7.x, so fall back to reflecting the
	 * protected image property when the public accessor does not exist.
	 *
	 * @param \WP_Image_Editor $editor Image editor instance.
	 * @return mixed|\WP_Error Image resource (Imagick or GdImage) or error.
	 */
	private function get_editor_image_resource( $editor ) {
		if ( ! is_object( $editor ) ) {
			return new \WP_Error( 'wp_mcp_ai_resource_error', __( 'Invalid image editor instance.', 'nvoos-content-graph-ai' ) );
		}

		if ( method_exists( $editor, 'get_image' ) ) {
			return $editor->get_image();
		}

		try {
			$reflection = new \ReflectionClass( $editor );
			$property   = $reflection->getProperty( 'image' );
			$property->setAccessible( true );

			return $property->getValue( $editor );
		} catch ( \ReflectionException $e ) {
			return new \WP_Error( 'wp_mcp_ai_resource_error', __( 'Failed to access image resource.', 'nvoos-content-graph-ai' ) );
		}
	}

	/**
	 * Expand canvas of an image.
	 *
	 * @param \WP_Image_Editor $image_editor Image editor instance.
	 * @param array            $expansion    Expansion data from calculate_expansion().
	 * @param array|string     $color        RGB color array or 'transparent'.
	 * @return true|\WP_Error True on success, error on failure.
	 */
	private function expand_canvas( $image_editor, $expansion, $color ) {
		$image_resource = $this->get_editor_image_resource( $image_editor );

		if ( is_wp_error( $image_resource ) ) {
			return new \WP_Error( 'wp_mcp_ai_resource_error', __( 'Failed to get image resource.', 'nvoos-content-graph-ai' ) );
		}

		$new_width  = $expansion['new_width'];
		$new_height = $expansion['new_height'];
		$offset_x   = $expansion['offset_x'];
		$offset_y   = $expansion['offset_y'];

		// Handle ImageMagick.
		if ( $image_resource instanceof \Imagick ) {
			try {
				// Set background color.
				if ( 'transparent' === $color ) {
					$background = new \ImagickPixel( 'transparent' );
				} else {
					$background = new \ImagickPixel( sprintf( 'rgb(%d,%d,%d)', $color['r'], $color['g'], $color['b'] ) );
				}

				// Extend the image (canvas expansion).
				$image_resource->extentImage( $new_width, $new_height, -$offset_x, -$offset_y );
				$image_resource->setImageBackgroundColor( $background );

				return true;
			} catch ( \Exception $e ) {
				return new \WP_Error( 'wp_mcp_ai_imagick_error', $e->getMessage() );
			}
		}

		// Handle GD.
		if ( is_resource( $image_resource ) || ( is_object( $image_resource ) && 'GdImage' === get_class( $image_resource ) ) ) {
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
				return new \WP_Error( 'wp_mcp_ai_gd_error', __( 'Failed to expand canvas with GD.', 'nvoos-content-graph-ai' ) );
			}

			// Replace the image resource in the editor.
			// For GD editors, we need to use reflection to set the resource.
			if ( method_exists( $image_editor, 'update_image' ) ) {
				$image_editor->update_image( $new_canvas );
			} else {
				// Fallback: Destroy old resource and set new one via reflection.
				imagedestroy( $image_resource );
				$reflection = new \ReflectionClass( $image_editor );
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

		return new \WP_Error( 'wp_mcp_ai_unsupported', __( 'Unsupported image editor type for canvas expansion.', 'nvoos-content-graph-ai' ) );
	}

	/**
	 * Overlay images using direct image manipulation.
	 *
	 * @param \WP_Image_Editor $base_editor    Base image editor.
	 * @param \WP_Image_Editor $overlay_editor Overlay image editor.
	 * @param int              $x              X position.
	 * @param int              $y              Y position.
	 * @return true|\WP_Error True on success, error on failure.
	 */
	private function overlay_images( $base_editor, $overlay_editor, $x, $y ) {
		$base_resource    = $this->get_editor_image_resource( $base_editor );
		$overlay_resource = $this->get_editor_image_resource( $overlay_editor );

		if ( is_wp_error( $base_resource ) || is_wp_error( $overlay_resource ) ) {
			return new \WP_Error( 'wp_mcp_ai_resource_error', __( 'Failed to get image resources.', 'nvoos-content-graph-ai' ) );
		}

		$overlay_size = $overlay_editor->get_size();

		// Handle ImageMagick.
		if ( $base_resource instanceof \Imagick && $overlay_resource instanceof \Imagick ) {
			try {
				$base_resource->compositeImage( $overlay_resource, \Imagick::COMPOSITE_OVER, $x, $y );
				return true;
			} catch ( \Exception $e ) {
				return new \WP_Error( 'wp_mcp_ai_imagick_error', $e->getMessage() );
			}
		}

		// Handle GD.
		if ( is_resource( $base_resource ) || ( is_object( $base_resource ) && 'GdImage' === get_class( $base_resource ) ) ) {
			imagealphablending( $base_resource, true );
			imagesavealpha( $base_resource, true );

			$result = imagecopy( $base_resource, $overlay_resource, $x, $y, 0, 0, $overlay_size['width'], $overlay_size['height'] );

			return $result ? true : new \WP_Error( 'wp_mcp_ai_gd_error', __( 'GD overlay failed.', 'nvoos-content-graph-ai' ) );
		}

		return new \WP_Error( 'wp_mcp_ai_unsupported', __( 'Unsupported image editor type.', 'nvoos-content-graph-ai' ) );
	}

	/**
	 * Common parameter schema elements for image source (base-identical).
	 *
	 * @return array
	 */
	private function get_source_parameters_schema() {
		return array(
			'attachment_id' => array(
				'type'        => 'integer',
				'description' => __( 'WordPress attachment ID of the image to process.', 'nvoos-content-graph-ai' ),
			),
			'file_id'       => $this->get_file_id_parameter_schema(),
			'url'           => $this->get_url_parameter_schema( 'image' ),
			'image_url'     => array(
				'type'        => 'string',
				'description' => __( 'URL of the image to process (alternative to attachment_id). Legacy parameter, use url instead.', 'nvoos-content-graph-ai' ),
			),
			'image_data'    => array(
				'type'        => 'string',
				'description' => __( 'Base64-encoded image data to process (alternative to attachment_id, file_id, or url).', 'nvoos-content-graph-ai' ),
			),
			'file_name'     => array(
				'type'        => 'string',
				'description' => __( 'Optional base file name for the saved image attachment.', 'nvoos-content-graph-ai' ),
			),
		);
	}

	/**
	 * Get file_id schema definition for tool parameters (base-identical).
	 *
	 * @return array Parameter schema definition.
	 */
	private function get_file_id_parameter_schema() {
		return array(
			'type'        => 'string',
			'description' => __( 'OpenAI or Gemini file identifier. Alternative to attachment_id for files already uploaded to the AI provider.', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Get url schema definition for tool parameters (base-identical).
	 *
	 * @param string $media_type Media type (e.g., 'image').
	 * @return array Parameter schema definition.
	 */
	private function get_url_parameter_schema( $media_type = 'file' ) {
		$description = sprintf(
			/* translators: %s: media type (image, video, audio, file) */
			__( 'URL to the %s. Can be a WordPress media URL or external URL.', 'nvoos-content-graph-ai' ),
			$media_type
		);

		return array(
			'type'        => 'string',
			'format'      => 'uri',
			'description' => $description,
		);
	}

	/**
	 * Enrich arguments with metadata from context messages (base-identical).
	 *
	 * When OpenAI processes messages, it strips custom metadata fields
	 * (attachment_id, file_name, mime_type, bytes) from image segments,
	 * only preserving the URL. This method restores them from the original
	 * user messages in the context for agentic workflows.
	 *
	 * @param array $arguments Tool arguments from OpenAI.
	 * @param array $context   Execution context including messages.
	 * @return array Enriched arguments with metadata.
	 */
	private function enrich_arguments_from_messages( array $arguments, array $context ) {
		// If attachment_id is already provided, no need to enrich.
		if ( ! empty( $arguments['attachment_id'] ) ) {
			return $arguments;
		}

		// If no messages in context, can't enrich.
		if ( empty( $context['messages'] ) || ! is_array( $context['messages'] ) ) {
			return $arguments;
		}

		// Look for URL in arguments to match against messages.
		$target_url = '';
		if ( ! empty( $arguments['url'] ) ) {
			$target_url = $arguments['url'];
		} elseif ( ! empty( $arguments['image_url'] ) ) {
			$target_url = $arguments['image_url'];
		}

		if ( '' === $target_url ) {
			return $arguments;
		}

		// Normalize URL for comparison (strip query strings and fragments).
		$target_url_normalized = strtok( $target_url, '?' );
		$target_url_normalized = strtok( $target_url_normalized, '#' );

		// Collect all image attachments found in messages as fallback.
		// Stored in reverse order (most recent first).
		$found_images = array();

		// Search through messages for matching image attachment.
		foreach ( $context['messages'] as $message ) {
			// Only check user messages (where attachments originate).
			if ( ! isset( $message['role'] ) || 'user' !== $message['role'] ) {
				continue;
			}

			// Check if message has content array with segments.
			if ( ! isset( $message['content'] ) || ! is_array( $message['content'] ) ) {
				continue;
			}

			foreach ( $message['content'] as $segment ) {
				if ( ! is_array( $segment ) ) {
					continue;
				}

				// Check for image segments (input_image or image_url type).
				$type = isset( $segment['type'] ) ? $segment['type'] : '';
				if ( ! in_array( $type, array( 'input_image', 'image_url' ), true ) ) {
					continue;
				}

				// Extract URL from segment.
				$segment_url = '';
				if ( isset( $segment['url'] ) ) {
					$segment_url = $segment['url'];
				} elseif ( isset( $segment['image_url']['url'] ) ) {
					$segment_url = $segment['image_url']['url'];
				} elseif ( isset( $segment['image_url'] ) && is_string( $segment['image_url'] ) ) {
					$segment_url = $segment['image_url'];
				}

				if ( '' === $segment_url ) {
					continue;
				}

				// Normalize segment URL for comparison.
				$segment_url_normalized = strtok( $segment_url, '?' );
				$segment_url_normalized = strtok( $segment_url_normalized, '#' );

				// Check if URLs match.
				if ( $segment_url_normalized === $target_url_normalized ) {
					// Found matching image! Extract metadata.
					if ( isset( $segment['attachment_id'] ) && $segment['attachment_id'] > 0 ) {
						$arguments['attachment_id'] = absint( $segment['attachment_id'] );
					}

					if ( isset( $segment['file_name'] ) && '' !== $segment['file_name'] ) {
						$arguments['file_name'] = sanitize_text_field( $segment['file_name'] );
					}

					if ( isset( $segment['mime_type'] ) && '' !== $segment['mime_type'] ) {
						$arguments['source_mime_type'] = sanitize_text_field( $segment['mime_type'] );
					}

					if ( isset( $segment['bytes'] ) && $segment['bytes'] > 0 ) {
						$arguments['bytes'] = absint( $segment['bytes'] );
					}

					// Found the match, no need to continue searching.
					return $arguments;
				}

				// Store this image as a potential fallback.
				// We store images in order found (which typically means most recent first in the messages array).
				$found_images[] = array(
					'url'            => $segment_url,
					'url_normalized' => $segment_url_normalized,
					'attachment_id'  => isset( $segment['attachment_id'] ) ? absint( $segment['attachment_id'] ) : 0,
					'file_name'      => isset( $segment['file_name'] ) ? sanitize_text_field( $segment['file_name'] ) : '',
					'mime_type'      => isset( $segment['mime_type'] ) ? sanitize_text_field( $segment['mime_type'] ) : '',
					'bytes'          => isset( $segment['bytes'] ) ? absint( $segment['bytes'] ) : 0,
				);
			}
		}

		// No exact match found.
		// If we found any images in messages and the provided URL domain doesn't match any of them,
		// it's likely a hallucinated/incorrect URL. Use the most recent image instead.
		if ( ! empty( $found_images ) ) {
			// Check if the target URL domain matches any found image domains.
			$target_domain         = $this->extract_domain_from_url( $target_url );
			$found_matching_domain = false;

			foreach ( $found_images as $image ) {
				$image_domain = $this->extract_domain_from_url( $image['url'] );
				if ( $target_domain === $image_domain ) {
					$found_matching_domain = true;
					break;
				}
			}

			// If the target URL domain doesn't match any images from messages,
			// it's likely incorrect. Use the most recent (first) image instead.
			if ( ! $found_matching_domain ) {
				$fallback_image = $found_images[0];

				// Replace URL with the correct one from messages.
				if ( ! empty( $arguments['url'] ) ) {
					$arguments['url'] = $fallback_image['url'];
				}
				if ( ! empty( $arguments['image_url'] ) ) {
					$arguments['image_url'] = $fallback_image['url'];
				}

				// Add metadata from the fallback image.
				if ( $fallback_image['attachment_id'] > 0 ) {
					$arguments['attachment_id'] = $fallback_image['attachment_id'];
				}
				if ( '' !== $fallback_image['file_name'] ) {
					$arguments['file_name'] = $fallback_image['file_name'];
				}
				if ( '' !== $fallback_image['mime_type'] ) {
					$arguments['source_mime_type'] = $fallback_image['mime_type'];
				}
				if ( $fallback_image['bytes'] > 0 ) {
					$arguments['bytes'] = $fallback_image['bytes'];
				}

				// Log the URL correction for debugging.
				if ( function_exists( 'wp_mcp_ai_log_activity' ) ) {
					\wp_mcp_ai_log_activity(
						'image_url_corrected',
						sprintf(
							'Corrected incorrect image URL from %s to %s',
							$target_url,
							$fallback_image['url']
						)
					);
				}
			}
		}

		return $arguments;
	}

	/**
	 * Load source image from various input formats (base-identical).
	 *
	 * Supports:
	 * - attachment_id: WordPress attachment ID
	 * - file_id: OpenAI/Gemini file identifier (converted to attachment_id)
	 * - url: URL to image file
	 * - image_url: URL to image file (legacy parameter)
	 * - image_data: Base64-encoded image data
	 *
	 * @param array $arguments Tool arguments containing image source.
	 * @param int   $user_id   Current user ID for permission checks.
	 * @return \WP_Image_Editor|\WP_Error Image editor instance or error.
	 */
	private function load_source_image( array $arguments, $user_id = 0 ) {
		// Try to resolve from attachment_id, file_id, or url first.
		if ( ! empty( $arguments['attachment_id'] ) || ! empty( $arguments['file_id'] ) || ! empty( $arguments['url'] ) ) {
			$resolved = $this->resolve_attachment_id( $arguments );

			// Handle remote URL case.
			if ( is_array( $resolved ) && isset( $resolved['url'] ) ) {
				// Fall through to URL handling below.
				$image_url = $resolved['url'];
			} elseif ( is_wp_error( $resolved ) ) {
				return $resolved;
			} elseif ( $resolved > 0 ) {
				$attachment_id = $resolved;
			}
		}

		// Initialize variables if not set by above.
		if ( ! isset( $attachment_id ) ) {
			$attachment_id = 0;
		}
		if ( ! isset( $image_url ) ) {
			$image_url = isset( $arguments['image_url'] ) ? esc_url_raw( $arguments['image_url'] ) : '';
		}
		$image_data = isset( $arguments['image_data'] ) ? $arguments['image_data'] : '';

		$file_path     = '';
		$is_local_file = false;

		if ( $attachment_id > 0 ) {
			// Load from WordPress attachment.
			$file_path = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new \WP_Error( 'wp_mcp_ai_invalid_attachment', __( 'The specified attachment does not exist.', 'nvoos-content-graph-ai' ), array( 'status' => 404 ) );
			}

			// Check permissions against the acting user, not the global
			// current user — the tool executes under $context['user_id'] and
			// the global state may not reflect that user (e.g. cron, CLI, or
			// token-authenticated executions).
			if ( $user_id && ! user_can( $user_id, 'read_post', $attachment_id ) ) {
				return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to access this attachment.', 'nvoos-content-graph-ai' ), array( 'status' => 403 ) );
			}
		} elseif ( '' !== $image_url ) {
			// Try to resolve URL to attachment ID first.
			// This handles cases where the URL is a WordPress media URL that might have.
			// different scheme (http vs https) or other variations that prevent direct.
			// filesystem access but still refers to a valid local attachment.
			$file_path              = null;
			$resolved_attachment_id = $this->resolve_attachment_id_from_url( $image_url );

			if ( $resolved_attachment_id > 0 ) {
				$resolved_file_path = get_attached_file( $resolved_attachment_id );

				if ( $resolved_file_path && file_exists( $resolved_file_path ) && is_readable( $resolved_file_path ) ) {
					$file_path     = $resolved_file_path;
					$is_local_file = true;
				}
			}

			// Try to use local file path first to avoid HTTP auth issues.
			if ( null === $file_path && $this->is_local_wordpress_url( $image_url ) ) {
				$local_file_path = $this->get_file_path_from_local_url( $image_url );

				if ( $local_file_path && file_exists( $local_file_path ) && is_readable( $local_file_path ) ) {
					$file_path     = $local_file_path;
					$is_local_file = true;
				}
			}

			// If no local file path, download via HTTP.
			if ( null === $file_path ) {
				// Validate URL before making HTTP request.
				if ( ! wp_http_validate_url( $image_url ) ) {
					return new \WP_Error( 'wp_mcp_ai_invalid_url', __( 'The provided image URL is not valid.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
				}

				$response = wp_safe_remote_get( $image_url, array( 'timeout' => 30 ) );

				if ( is_wp_error( $response ) ) {
					return new \WP_Error( 'wp_mcp_ai_download_error', __( 'Failed to download the source image.', 'nvoos-content-graph-ai' ), array( 'error' => $response->get_error_message() ) );
				}

				$status_code = wp_remote_retrieve_response_code( $response );
				if ( $status_code < 200 || $status_code >= 300 ) {
					/* translators: %d: HTTP status code */
					return new \WP_Error( 'wp_mcp_ai_download_error', sprintf( __( 'Failed to download image. HTTP %d', 'nvoos-content-graph-ai' ), $status_code ), array( 'status' => $status_code ) );
				}

				$image_contents = wp_remote_retrieve_body( $response );
				if ( '' === $image_contents ) {
					return new \WP_Error( 'wp_mcp_ai_download_error', __( 'Downloaded image is empty.', 'nvoos-content-graph-ai' ) );
				}

				// Create temporary file from downloaded content.
				$file_path = $this->create_temp_file( $image_contents, $image_url );
				if ( is_wp_error( $file_path ) ) {
					return $file_path;
				}
			}
		} elseif ( '' !== $image_data ) {
			// Use base64-encoded data.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64_decode used to decode binary image/file data provided by the caller, not for code obfuscation.
			$decoded_data = base64_decode( $image_data, true );

			if ( false === $decoded_data || '' === $decoded_data ) {
				return new \WP_Error( 'wp_mcp_ai_invalid_image_data', __( 'The provided image data is not valid base64.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
			}

			// Create temporary file.
			$file_path = $this->create_temp_file( $decoded_data );
			if ( is_wp_error( $file_path ) ) {
				return $file_path;
			}
		} else {
			return new \WP_Error( 'wp_mcp_ai_missing_source', __( 'Either attachment_id, image_url, or image_data must be provided.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
		}

		// Load image with WordPress image editor.
		$image_editor = wp_get_image_editor( $file_path );

		if ( is_wp_error( $image_editor ) ) {
			// Clean up temp file if we created one.
			// Don't delete if it's an attachment or a local file from the uploads directory.
			if ( ! $attachment_id && ! $is_local_file ) {
				$this->delete_temp_file( $file_path );
			}
			return $image_editor;
		}

		// Stash the on-disk path on the editor so tools can read the actual
		// source file. The core file property is protected and
		// generate_filename() returns a size-suffixed path that may not exist.
		$image_editor->source_file_path = $file_path;

		// Store whether this is a temp file for cleanup later.
		// Don't mark local upload files as temp - only mark truly temporary files.
		if ( ! $attachment_id && ! $is_local_file ) {
			$image_editor->temp_file = $file_path;
		}

		return $image_editor;
	}

	/**
	 * Resolve attachment ID from arguments (base-identical).
	 *
	 * Accepts attachment_id, file_id, or url from the arguments array and
	 * returns a WordPress attachment ID when possible.
	 *
	 * @param array  $arguments  Tool arguments that may contain attachment_id, file_id, or url.
	 * @param string $param_name Optional parameter name to check. Default 'attachment_id'.
	 * @return int|\WP_Error|array Attachment ID on success, WP_Error on failure, or array with 'url' key if URL cannot be resolved to attachment.
	 */
	private function resolve_attachment_id( array $arguments, $param_name = 'attachment_id' ) {
		// First check for direct attachment_id parameter.
		if ( ! empty( $arguments[ $param_name ] ) ) {
			$attachment_id = absint( $arguments[ $param_name ] );
			if ( $attachment_id > 0 ) {
				// Verify it's actually an attachment.
				if ( 'attachment' === get_post_type( $attachment_id ) ) {
					return $attachment_id;
				}
				return new \WP_Error(
					'wp_mcp_ai_invalid_attachment',
					sprintf(
						/* translators: %d: attachment ID */
						__( 'Attachment ID %d does not exist or is not an attachment.', 'nvoos-content-graph-ai' ),
						$attachment_id
					),
					array( 'status' => 404 )
				);
			}
		}

		// Check for file_id parameter.
		$file_id_param = str_replace( 'attachment_id', 'file_id', $param_name );
		if ( ! empty( $arguments[ $file_id_param ] ) ) {
			$file_id = sanitize_text_field( $arguments[ $file_id_param ] );
			if ( '' !== $file_id ) {
				return $this->resolve_attachment_id_from_file_id( $file_id );
			}
		}

		// Check for url parameter.
		$url_param = str_replace( 'attachment_id', 'url', $param_name );
		if ( ! empty( $arguments[ $url_param ] ) ) {
			$url = esc_url_raw( $arguments[ $url_param ] );
			if ( '' !== $url ) {
				return $this->resolve_attachment_id_from_url( $url );
			}
		}

		return 0;
	}

	/**
	 * Resolve a WordPress attachment ID from an OpenAI/Gemini file ID
	 * (per-mode seam: base message-attachments index in monolith installs).
	 *
	 * @param string $file_id OpenAI/Gemini file identifier (e.g., 'file-abc123').
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	private function resolve_attachment_id_from_file_id( $file_id ) {
		$file_id = sanitize_text_field( $file_id );

		if ( '' === $file_id ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_file_id',
				__( 'File ID cannot be empty.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		// Standalone: no base message-attachments index to resolve against.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_file_not_found',
				sprintf(
					/* translators: %s: file ID */
					__( 'No attachment found for file ID: %s', 'nvoos-content-graph-ai' ),
					$file_id
				),
				array( 'status' => 404 )
			);
		}

		// Load the base message attachments helper.
		if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			require_once \WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
		}

		$attachments_helper = new \WP_MCP_AI_Message_Attachments();
		$attachment_id      = $attachments_helper->get_attachment_id_for_openai_file( $file_id );

		if ( ! $attachment_id ) {
			return new \WP_Error(
				'wp_mcp_ai_file_not_found',
				sprintf(
					/* translators: %s: file ID */
					__( 'No attachment found for file ID: %s', 'nvoos-content-graph-ai' ),
					$file_id
				),
				array( 'status' => 404 )
			);
		}

		// Verify the attachment still exists and is valid.
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_attachment_invalid',
				sprintf(
					/* translators: %s: file ID */
					__( 'File ID %s resolved to an invalid attachment.', 'nvoos-content-graph-ai' ),
					$file_id
				),
				array( 'status' => 404 )
			);
		}

		return $attachment_id;
	}

	/**
	 * Resolve a WordPress attachment ID from a URL with scheme-agnostic
	 * matching (base-identical).
	 *
	 * WordPress's attachment_url_to_postid() function is scheme-sensitive
	 * and will fail if the URL scheme (http vs https) doesn't match what's
	 * stored in the database. This method provides a fallback that tries
	 * both schemes.
	 *
	 * @param string $url URL to resolve.
	 * @return int Attachment ID on success, 0 if not found.
	 */
	private function resolve_attachment_id_from_url( $url ) {
		if ( '' === $url ) {
			return 0;
		}

		// First, try the URL as-is.
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id > 0 ) {
			return $attachment_id;
		}

		// If that failed, try with the opposite scheme.
		// This handles cases where the LLM passes a URL with http but WordPress.
		// is configured with https (or vice versa).
		$alternate_url = '';

		if ( 0 === strpos( $url, 'https://' ) ) {
			// Try http instead.
			$alternate_url = 'http://' . substr( $url, 8 );
		} elseif ( 0 === strpos( $url, 'http://' ) ) {
			// Try https instead.
			$alternate_url = 'https://' . substr( $url, 7 );
		}

		if ( '' !== $alternate_url ) {
			$attachment_id = attachment_url_to_postid( $alternate_url );
			if ( $attachment_id > 0 ) {
				return $attachment_id;
			}
		}

		return 0;
	}

	/**
	 * Create a temporary file from image data (base-identical).
	 *
	 * @param string $data     Image binary data.
	 * @param string $filename Optional filename for extension detection.
	 * @return string|\WP_Error Temporary file path or error.
	 */
	private function create_temp_file( $data, $filename = '' ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp_file = wp_tempnam( $filename );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
		if ( false === file_put_contents( $temp_file, $data ) ) {
			return new \WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to create temporary image file.', 'nvoos-content-graph-ai' ) );
		}

		return $temp_file;
	}

	/**
	 * Delete a temporary file safely (base-identical).
	 *
	 * @param string $file_path Path to temporary file.
	 * @return void
	 */
	private function delete_temp_file( $file_path ) {
		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}

	/**
	 * Check if a URL is a local WordPress URL (base-identical).
	 *
	 * @param string $url URL to check.
	 * @return bool True if the URL belongs to this WordPress installation.
	 */
	private function is_local_wordpress_url( $url ) {
		if ( '' === $url ) {
			return false;
		}

		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return false;
		}

		// Normalize URL to remove scheme differences (http vs https).
		$normalized_url = $this->normalize_url_for_comparison( $url );

		// Get the WordPress upload directory URL.
		$upload_dir = wp_upload_dir();
		$base_url   = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';

		if ( '' !== $base_url ) {
			$normalized_base = $this->normalize_url_for_comparison( $base_url );
			if ( 0 === strpos( $normalized_url, $normalized_base ) ) {
				return true;
			}
		}

		// Also check against home_url and site_url as fallback.
		$home_url            = home_url();
		$site_url            = site_url();
		$normalized_home_url = $this->normalize_url_for_comparison( $home_url );
		$normalized_site_url = $this->normalize_url_for_comparison( $site_url );

		if ( 0 === strpos( $normalized_url, $normalized_home_url ) || 0 === strpos( $normalized_url, $normalized_site_url ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize a URL for comparison by removing the scheme (base-identical).
	 *
	 * This helps match URLs that differ only in http vs https.
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL without scheme.
	 */
	private function normalize_url_for_comparison( $url ) {
		if ( '' === $url ) {
			return '';
		}

		// Remove http:// or https:// prefix for comparison.
		$url = preg_replace( '#^https?://#i', '', $url );

		return $url;
	}

	/**
	 * Convert a local WordPress URL to a file path (base-identical).
	 *
	 * @param string $url Local WordPress URL.
	 * @return string|false File path on success, false on failure.
	 */
	private function get_file_path_from_local_url( $url ) {
		if ( '' === $url ) {
			return false;
		}

		// Get the WordPress upload directory information.
		$upload_dir = wp_upload_dir();
		$base_url   = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';
		$base_dir   = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';

		if ( '' === $base_url || '' === $base_dir ) {
			return false;
		}

		// Normalize URLs to handle scheme differences (http vs https).
		$normalized_url      = $this->normalize_url_for_comparison( $url );
		$normalized_base_url = $this->normalize_url_for_comparison( $base_url );

		// Check if URL starts with the upload base URL (using normalized comparison).
		if ( 0 === strpos( $normalized_url, $normalized_base_url ) ) {
			// Extract the relative path after the base URL.
			$relative_path = substr( $normalized_url, strlen( $normalized_base_url ) );

			// Security: Decode URL-encoding before checking for traversal sequences so
			// that encoded variants like %2e%2e cannot bypass the check.
			$decoded_relative = urldecode( $relative_path );
			if ( false !== strpos( $decoded_relative, '..' ) ) {
				return false;
			}

			// Build the file path.
			$file_path = $base_dir . $relative_path;

			// Normalize path separators.
			$file_path = wp_normalize_path( $file_path );

			// Security: Verify the resolved path stays within the uploads directory.
			$resolved = realpath( $file_path );
			if ( false === $resolved ) {
				return false;
			}
			$uploads_base = wp_normalize_path( trailingslashit( $base_dir ) );
			if ( 0 !== strpos( wp_normalize_path( $resolved ), $uploads_base ) ) {
				return false;
			}
			return $resolved;
		}

		// Try using WordPress built-in function as fallback.
		// This handles cases where URL might be in a different format.
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id > 0 ) {
			return get_attached_file( $attachment_id );
		}

		return false;
	}

	/**
	 * Extract domain from URL for comparison (base-identical).
	 *
	 * @param string $url URL to extract domain from.
	 * @return string Domain (e.g., 'example.com') or empty string on failure.
	 */
	private function extract_domain_from_url( $url ) {
		if ( '' === $url ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		if ( ! isset( $parsed['host'] ) ) {
			return '';
		}

		// Return the host without www prefix for better matching.
		$host = strtolower( $parsed['host'] );
		$host = preg_replace( '/^www\./i', '', $host );

		return $host;
	}

	/**
	 * Save image editor contents as WordPress attachment (base-identical).
	 *
	 * @param \WP_Image_Editor $image_editor Image editor instance.
	 * @param array            $arguments    Tool arguments for naming/metadata.
	 * @param int              $user_id      User ID for attachment author.
	 * @param string           $operation    Operation name for title generation.
	 * @return array|\WP_Error Attachment data or error.
	 */
	private function save_as_attachment( \WP_Image_Editor $image_editor, array $arguments, $user_id, $operation ) {
		// Get file name from arguments or generate one.
		$file_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : '';
		if ( '' === $file_name ) {
			$source_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
			if ( $source_id ) {
				$source_file = get_attached_file( $source_id );
				if ( $source_file ) {
					$pathinfo  = pathinfo( $source_file );
					$file_name = isset( $pathinfo['filename'] ) ? $pathinfo['filename'] : 'image';
				}
			}
			if ( '' === $file_name ) {
				$file_name = 'image';
			}
		}

		// Generate unique filename.
		// WP_Image_Editor::get_mime_type() is protected; derive the extension from the
		// filename that generate_filename() computes (which preserves the source extension).
		// If the generated path has no extension (uncommon), fall back to get_extension_from_mime_type()
		// using the MIME type resolved from the source arguments.
		$generated_name = $image_editor->generate_filename();
		$extension      = pathinfo( $generated_name, PATHINFO_EXTENSION );
		if ( '' === $extension ) {
			$extension = $this->get_extension_from_mime_type( $this->resolve_source_mime_type( $arguments ) );
		}
		$file_name = sprintf( '%s-%s-%s.%s', sanitize_title( $file_name ), sanitize_title( $operation ), gmdate( 'Ymd-His' ), $extension );

		// Save to uploads directory.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$saved = $image_editor->save();

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Capture the MIME type from the save result.
		// WP_Image_Editor::save() returns 'mime-type' in its result array, avoiding
		// the need to call the protected WP_Image_Editor::get_mime_type() method.
		$saved_mime_type = isset( $saved['mime-type'] ) ? $saved['mime-type'] : '';

		$file_path = isset( $saved['path'] ) ? $saved['path'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new \WP_Error( 'wp_mcp_ai_save_error', __( 'Failed to save edited image.', 'nvoos-content-graph-ai' ) );
		}

		// Read file contents to re-upload with proper name.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		$image_data = file_get_contents( $file_path );
		if ( false === $image_data ) {
			wp_delete_file( $file_path );
			return new \WP_Error( 'wp_mcp_ai_read_error', __( 'Failed to read saved image file.', 'nvoos-content-graph-ai' ) );
		}

		// Upload with proper filename.
		$upload = wp_upload_bits( $file_name, null, $image_data );

		// Delete the temporary saved file.
		wp_delete_file( $file_path );

		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_upload_error', $upload['error'] );
		}

		$final_file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $final_file_path || ! file_exists( $final_file_path ) ) {
			return new \WP_Error( 'wp_mcp_ai_upload_error', __( 'Failed to upload edited image.', 'nvoos-content-graph-ai' ) );
		}

		// Create attachment.
		$title = $this->generate_attachment_title( $operation, $arguments );

		$attachment = array(
			'post_mime_type' => $saved_mime_type,
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $final_file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $final_file_path );
			return new \WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to create attachment.', 'nvoos-content-graph-ai' ), array( 'error' => $attachment_id ) );
		}

		// Generate attachment metadata.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $final_file_path );
		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$bytes = file_exists( $final_file_path ) ? filesize( $final_file_path ) : 0;

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $final_file_path,
			'file_name'     => wp_basename( $final_file_path ),
			'url'           => isset( $upload['url'] ) ? $upload['url'] : wp_get_attachment_url( $attachment_id ),
			'mime_type'     => $saved_mime_type,
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => $title,
			'size'          => $image_editor->get_size(),
		);
	}

	/**
	 * Get allowed image MIME types (base-identical).
	 *
	 * @return array
	 */
	private function get_allowed_mime_types() {
		return array(
			'image/jpeg'    => 'jpg',
			'image/jpg'     => 'jpg',
			'image/png'     => 'png',
			'image/webp'    => 'webp',
			'image/gif'     => 'gif',
			'image/svg+xml' => 'svg',
		);
	}

	/**
	 * Get file extension from MIME type (base-identical).
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	private function get_extension_from_mime_type( $mime_type ) {
		$allowed = $this->get_allowed_mime_types();
		return isset( $allowed[ $mime_type ] ) ? $allowed[ $mime_type ] : 'jpg';
	}

	/**
	 * Resolve the MIME type of the source image from tool arguments
	 * (base-identical).
	 *
	 * WP_Image_Editor::get_mime_type() is a protected method and cannot be
	 * called from outside the class hierarchy. This helper determines the
	 * source MIME type from the information available in the arguments
	 * array, without touching the image editor instance.
	 *
	 * @param array $arguments Enriched tool arguments (attachment_id, url, image_url, file_id).
	 * @return string MIME type string (e.g. 'image/jpeg'), or empty string if undetectable.
	 */
	private function resolve_source_mime_type( array $arguments ) {
		// Prefer attachment ID — most reliable and avoids filesystem calls.
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$mime = get_post_mime_type( absint( $arguments['attachment_id'] ) );
			if ( $mime ) {
				return $mime;
			}
		}

		if ( ! empty( $arguments['file_id'] ) ) {
			$mime = get_post_mime_type( absint( $arguments['file_id'] ) );
			if ( $mime ) {
				return $mime;
			}
		}

		// Try to resolve MIME type from URL via attachment ID lookup.
		$url = '';
		if ( ! empty( $arguments['url'] ) ) {
			$url = $arguments['url'];
		} elseif ( ! empty( $arguments['image_url'] ) ) {
			$url = $arguments['image_url'];
		}

		if ( '' !== $url ) {
			$resolved_id = $this->resolve_attachment_id_from_url( $url );
			if ( $resolved_id > 0 ) {
				$mime = get_post_mime_type( $resolved_id );
				if ( $mime ) {
					return $mime;
				}
			}

			// Fall back to checking the file extension from the URL basename.
			$type_info = wp_check_filetype( wp_basename( $url ) );
			if ( ! empty( $type_info['type'] ) ) {
				return $type_info['type'];
			}
		}

		return '';
	}

	/**
	 * Generate attachment title based on operation (base-identical).
	 *
	 * @param string $operation Operation name.
	 * @param array  $arguments Tool arguments.
	 * @return string
	 */
	private function generate_attachment_title( $operation, array $arguments ) {
		$source_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;

		if ( $source_id ) {
			$source_title = get_the_title( $source_id );
			if ( $source_title ) {
				/* translators: 1: operation name, 2: source title */
				return sprintf( __( '%1$s: %2$s', 'nvoos-content-graph-ai' ), ucfirst( $operation ), $source_title );
			}
		}

		/* translators: %s: operation name */
		return sprintf( __( '%s Image', 'nvoos-content-graph-ai' ), ucfirst( $operation ) );
	}
}
