<?php
/**
 * Tool that generates images using OpenAI's Images API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-llm-sanitizer-interface.php';

/**
 * Provides a tool for generating images via OpenAI and storing them as attachments.
 */
class WP_MCP_AI_Tool_Generate_OpenAI_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Rules_Interface {
	const DEFAULT_MODEL           = 'gpt-image-1';
	const DEFAULT_SIZE            = '1024x1024';
	const DEFAULT_QUALITY         = 'medium'; // Default for gpt-image-1. DALL-E uses 'standard'.
	const DEFAULT_FORMAT          = 'png';
	const DEFAULT_RESPONSE_FORMAT = 'b64_json';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_openai_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate OpenAI Image', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates an image with OpenAI and stores it in the Media Library.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$defaults = $this->get_configured_defaults();

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'          => array(
					'type'        => 'string',
					'description' => __( 'The text prompt describing the desired image.', 'wp-mcp-ai' ),
				),
				'model'           => array(
					'type'        => 'string',
					'description' => __( 'OpenAI image model to use.', 'wp-mcp-ai' ),
					'default'     => $defaults['model'],
				),
				'size'            => array(
					'type'        => 'string',
					'description' => __( 'Size of the generated image.', 'wp-mcp-ai' ),
					'enum'        => array_values( self::get_allowed_sizes() ),
					'default'     => $defaults['size'],
				),
				'quality'         => array(
					'type'        => 'string',
					'description' => __( 'Image quality setting.', 'wp-mcp-ai' ),
					'enum'        => array_values( self::get_allowed_qualities() ),
					'default'     => $defaults['quality'],
				),
				'response_format' => array(
					'type'        => 'string',
					'description' => __( 'Whether OpenAI should return base64 data or a hosted image URL.', 'wp-mcp-ai' ),
					'enum'        => self::get_allowed_response_formats(),
					'default'     => $defaults['response_format'],
				),
				'format'          => array(
					'type'        => 'string',
					'description' => __( 'Image format for the generated file. OpenAI currently only returns PNG images.', 'wp-mcp-ai' ),
					'enum'        => array( self::DEFAULT_FORMAT ),
					'default'     => self::DEFAULT_FORMAT,
				),
				'file_name'       => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved image attachment.', 'wp-mcp-ai' ),
				),
				'timeout'         => array(
					'type'        => 'integer',
					'description' => __( 'Override the OpenAI request timeout in seconds.', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 300,
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'label'   => __( 'generate_openai_image', 'wp-mcp-ai' ),
				'payload' => __( 'generate_openai_image', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Revise existing concept', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `generate_openai_image` tool to update an existing concept. Ask what should change, capture the current prompt for context, then propose an adjusted prompt reflecting the requested edits before running the tool.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Add product to lifestyle scene', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `generate_openai_image` tool to place the product in a lifestyle setting. Gather details about the environment, target audience, props, and camera angle, then assemble a detailed prompt that keeps the product as the hero of the scene.', 'wp-mcp-ai' ),
			),
		);
	}

	/**
	 * Retrieve the configured defaults for image generation.
	 *
	 * @return array
	 */
	protected function get_configured_defaults() {
		$defaults = array(
			'model'           => self::DEFAULT_MODEL,
			'size'            => self::DEFAULT_SIZE,
			'quality'         => self::DEFAULT_QUALITY,
			'response_format' => self::DEFAULT_RESPONSE_FORMAT,
		);

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return $defaults;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		if ( ! empty( $settings['openai_image_model'] ) ) {
			$defaults['model'] = sanitize_text_field( $settings['openai_image_model'] );
		}

		if ( ! empty( $settings['openai_image_size'] ) ) {
			$size = $this->normalise_image_size( $settings['openai_image_size'] );

			if ( '' !== $size ) {
				$defaults['size'] = $size;
			}
		}

		if ( ! empty( $settings['openai_image_quality'] ) ) {
			$quality = $this->normalise_quality_for_model( $settings['openai_image_quality'], $defaults['model'] );

			if ( '' !== $quality ) {
				$defaults['quality'] = $quality;
			} else {
				// If configured quality is not valid for the model, use model's default.
				$defaults['quality'] = $this->get_model_default_quality( $defaults['model'] );
			}
		} else {
			// No quality configured, use model's default.
			$defaults['quality'] = $this->get_model_default_quality( $defaults['model'] );
		}

		if ( isset( $settings['openai_image_response_format'] ) ) {
			$response_format = $this->normalise_response_format( $settings['openai_image_response_format'] );

			if ( '' !== $response_format && WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( $defaults['model'] ) ) {
				$defaults['response_format'] = $response_format;
			}
		}

		if ( ! WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( $defaults['model'] ) ) {
			$defaults['response_format'] = self::DEFAULT_RESPONSE_FORMAT;
		}

		return $defaults;
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to generate images.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate images.', 'wp-mcp-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
			}
		}

		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'No prompt was supplied for the image request.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$defaults = $this->get_configured_defaults();

		// Process model first, as quality validation depends on it.
		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : $defaults['model'];
		if ( '' === $model ) {
			$model = $defaults['model'];
		}

		$size = isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : $defaults['size'];
		$size = $this->normalise_image_size( $size );

		if ( '' === $size ) {
			$size = $defaults['size'];
		}

		// Validate quality for the selected model.
		$quality = isset( $arguments['quality'] ) ? sanitize_key( $arguments['quality'] ) : $defaults['quality'];

		// Sanitize quality to only allowed values: low, medium, high, auto.
		// This prevents 400 errors from OpenAI API.
		$allowed = array( 'low', 'medium', 'high', 'auto' );
		if ( empty( $quality ) || ! in_array( $quality, $allowed, true ) ) {
			$quality = 'medium';
		}

		$response_format = isset( $arguments['response_format'] ) ? sanitize_key( $arguments['response_format'] ) : $defaults['response_format'];
		$response_format = $this->normalise_response_format( $response_format );

		if ( '' === $response_format ) {
			$response_format = $defaults['response_format'];
		}

		if ( ! WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( $model ) ) {
			$response_format = self::DEFAULT_RESPONSE_FORMAT;
		}

		$options = array(
			'model'   => $model,
			'size'    => $size,
			'quality' => $quality,
		);

		if ( WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( $model ) ) {
			$options['response_format'] = $response_format;
		}

		if ( isset( $arguments['timeout'] ) && '' !== $arguments['timeout'] ) {
			$timeout = absint( $arguments['timeout'] );
			if ( $timeout >= 5 ) {
				$options['timeout'] = min( 300, $timeout );
			}
		}

		$client = new WP_MCP_AI_OpenAI_Client();
		$image  = $client->generate_image( $prompt, $options );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( empty( $image['image'] ) ) {
			return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'OpenAI returned an empty image response.', 'wp-mcp-ai' ) );
		}

		$file_name = isset( $arguments['file_name'] ) ? $arguments['file_name'] : '';
		$storage   = $this->store_image_attachment( $image, $file_name, $prompt, $user_id );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		// Build descriptive text message for the LLM and chat UI.
		$text_parts = array();
		$text_parts[] = sprintf(
			/* translators: 1: attachment ID */
			__( 'Successfully generated image (ID: %d).', 'wp-mcp-ai' ),
			$storage['attachment_id']
		);
		
		if ( ! empty( $image['revised_prompt'] ) ) {
			$text_parts[] = sprintf(
				/* translators: %s: revised prompt from OpenAI */
				__( 'Revised prompt: %s', 'wp-mcp-ai' ),
				$image['revised_prompt']
			);
		}
		
		$text_parts[] = sprintf(
			/* translators: 1: size, 2: quality */
			__( 'Size: %1$s, Quality: %2$s', 'wp-mcp-ai' ),
			$size,
			$quality
		);

		$result = array(
			'attachment_id'   => $storage['attachment_id'],
			'url'             => $storage['url'],
			'file_path'       => $storage['file'],
			'file_name'       => $storage['file_name'],
			'mime_type'       => $storage['mime_type'],
			'bytes'           => $storage['bytes'],
			'format'          => isset( $image['format'] ) ? $this->normalise_image_format( $image['format'] ) : self::DEFAULT_FORMAT,
			'size'            => $size,
			'quality'         => $quality,
			'model'           => $image['model'],
			'provider'        => 'openai', // Track provider for accurate cost attribution.
			'response_format' => $response_format,
			'revised_prompt'  => isset( $image['revised_prompt'] ) ? $image['revised_prompt'] : '',
			'created'         => isset( $image['created'] ) ? $image['created'] : 0,
			'text'            => implode( ' ', $text_parts ), // Descriptive message for LLM and chat UI.
		);

		// Add estimated usage metadata for UI display.
		// OpenAI's image API doesn't return usage data, so we estimate it.
		$estimated_usage = $this->estimate_image_token_usage( $prompt, $size, $image['model'] );
		if ( ! empty( $estimated_usage ) ) {
			$result['usage'] = $estimated_usage;
		}

		// Calculate estimated cost based on usage.
		if ( ! empty( $estimated_usage ) ) {
			$cost_usd = $this->estimate_image_cost( $image['model'], $size, $quality );
			if ( $cost_usd > 0 ) {
				$result['cost'] = array(
					'cost_usd'     => $cost_usd,
					'is_estimated' => true,
					'provider'     => 'openai',
					'model'        => $image['model'],
				);
			}
		}

		// Note: Inline content payload (base64 encoded image data) is intentionally NOT included
		// in the default response to prevent bloating tool results sent to chat clients and LLMs.
		// If base64 content is needed, it should be retrieved via a separate endpoint or parameter.

		/**
		 * Allow third parties to filter the image generation result before it is returned.
		 *
		 * @param array $result    Result array to be returned.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_openai_image_result', $result, $arguments, $context );

		return $result;
	}

	/**
	 * Retrieve the allowed image sizes.
	 *
	 * @return array
	 */
	protected static function get_allowed_sizes() {
		return array(
			'1024x1024',
			'1024x1792',
			'1792x1024',
			'auto',
		);
	}

	/**
	 * Retrieve the allowed image qualities.
	 *
	 * @return array
	 */
	protected static function get_allowed_qualities() {
		return array(
			// DALL-E 2 and DALL-E 3 quality values.
			'standard',
			'hd',
			// gpt-image-1 quality values.
			'low',
			'medium',
			'high',
			'auto',
		);
	}

	/**
	 * Retrieve the allowed OpenAI response formats for image generation.
	 *
	 * @return array
	 */
	protected static function get_allowed_response_formats() {
		return array(
			'b64_json',
			'url',
		);
	}

	/**
	 * Retrieve the allowed formats and metadata.
	 *
	 * @return array
	 */
	protected static function get_allowed_formats() {
		return array(
			'png'  => array(
				'extension' => 'png',
				'mime_type' => 'image/png',
			),
			'jpeg' => array(
				'extension' => 'jpg',
				'mime_type' => 'image/jpeg',
			),
			'webp' => array(
				'extension' => 'webp',
				'mime_type' => 'image/webp',
			),
		);
	}

	/**
	 * Normalise the requested size value.
	 *
	 * @param string $size Raw size input.
	 * @return string
	 */
	protected function normalise_image_size( $size ) {
		$size  = strtolower( (string) $size );
		$sizes = self::get_allowed_sizes();

		return in_array( $size, $sizes, true ) ? $size : '';
	}

	/**
	 * Normalise the requested image format.
	 *
	 * @param string $format Raw format input.
	 * @return string
	 */
	protected function normalise_image_format( $format ) {
		$format  = sanitize_key( $format );
		$formats = self::get_allowed_formats();

		return isset( $formats[ $format ] ) ? $format : '';
	}

	/**
	 * Normalise the requested image quality.
	 *
	 * @param string $quality Raw quality input.
	 * @return string
	 */
	protected function normalise_image_quality( $quality ) {
		$quality   = sanitize_key( $quality );
		$qualities = self::get_allowed_qualities();

		return in_array( $quality, $qualities, true ) ? $quality : '';
	}

	/**
	 * Normalise the requested response format.
	 *
	 * @param string $response_format Raw response format input.
	 * @return string
	 */
	protected function normalise_response_format( $response_format ) {
		$response_format = sanitize_key( $response_format );
		$formats         = self::get_allowed_response_formats();

		return in_array( $response_format, $formats, true ) ? $response_format : '';
	}

	/**
	 * Get allowed quality values for a specific image model.
	 *
	 * Different OpenAI image models support different quality parameter values:
	 * - DALL-E 2 and DALL-E 3 use: 'standard', 'hd'
	 * - gpt-image-1 uses: 'low', 'medium', 'high', 'auto'
	 *
	 * @param string $model Image model identifier.
	 * @return array Array of allowed quality values for the model.
	 */
	protected function get_model_allowed_qualities( $model ) {
		$model = strtolower( sanitize_text_field( $model ) );

		// gpt-image-1 uses a different set of quality values.
		if ( 'gpt-image-1' === $model ) {
			return array( 'low', 'medium', 'high', 'auto' );
		}

		// DALL-E 2, DALL-E 3, and other models use standard/hd.
		return array( 'standard', 'hd' );
	}

	/**
	 * Get the default quality value for a specific image model.
	 *
	 * @param string $model Image model identifier.
	 * @return string Default quality value for the model.
	 */
	protected function get_model_default_quality( $model ) {
		$model = strtolower( sanitize_text_field( $model ) );

		// gpt-image-1 defaults to 'medium' quality.
		if ( 'gpt-image-1' === $model ) {
			return 'medium';
		}

		// DALL-E models default to 'standard' quality.
		return 'standard';
	}

	/**
	 * Normalise quality value for a specific model.
	 *
	 * If the quality value is not valid for the model, returns empty string.
	 *
	 * @param string $quality Raw quality input.
	 * @param string $model   Image model identifier.
	 * @return string Normalized quality value or empty string if invalid.
	 */
	protected function normalise_quality_for_model( $quality, $model ) {
		$quality           = sanitize_key( $quality );
		$allowed_for_model = $this->get_model_allowed_qualities( $model );

		return in_array( $quality, $allowed_for_model, true ) ? $quality : '';
	}

	/**
	 * Store the generated image as a WordPress attachment.
	 *
	 * @param array  $image     Response payload from the OpenAI client.
	 * @param string $file_name Optional preferred file name.
	 * @param string $prompt    Original text prompt.
	 * @param int    $user_id   Acting user ID.
	 * @return array|WP_Error
	 */
	protected function store_image_attachment( array $image, $file_name, $prompt, $user_id ) {
		$data    = isset( $image['image'] ) ? $image['image'] : '';
		$format  = isset( $image['format'] ) ? $this->normalise_image_format( $image['format'] ) : self::DEFAULT_FORMAT;
		$formats = self::get_allowed_formats();

		if ( '' === $data || '' === $format || ! isset( $formats[ $format ] ) ) {
			return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Unable to determine the image format for storage.', 'wp-mcp-ai' ) );
		}

		$file_stem = $this->normalise_file_stem( $file_name );
		$file_name = sprintf( '%s-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $formats[ $format ]['extension'] );

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to save the generated image file.', 'wp-mcp-ai' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to write the generated image file to disk.', 'wp-mcp-ai' ) );
		}

		$mime_type = $this->determine_mime_type( $file_path, $formats[ $format ]['mime_type'], $image );
		$title     = $this->generate_attachment_title( $prompt );

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			$this->delete_file_safely( $file_path );

			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register the generated image as an attachment.', 'wp-mcp-ai' ), array( 'error' => $attachment_id ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		} else {
			$metadata = array();
		}

		// Store OpenAI response metadata for reference.
		$openai_meta = array(
			'source'         => 'openai',
			'original_prompt' => sanitize_textarea_field( $prompt ),
		);

		if ( ! empty( $image['model'] ) ) {
			$openai_meta['model'] = sanitize_text_field( $image['model'] );
		}

		if ( ! empty( $image['revised_prompt'] ) ) {
			$openai_meta['revised_prompt'] = sanitize_textarea_field( $image['revised_prompt'] );
		}

		if ( ! empty( $image['created'] ) ) {
			$openai_meta['created'] = absint( $image['created'] );
		}

		if ( ! empty( $format ) ) {
			$openai_meta['format'] = sanitize_key( $format );
		}

		update_post_meta( $attachment_id, '_wp_mcp_ai_openai_image_meta', $openai_meta );

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => wp_basename( $file_path ),
			'url'           => isset( $upload['url'] ) ? $upload['url'] : '',
			'mime_type'     => $mime_type,
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => $title,
		);
	}

	/**
	 * Normalise a file stem used for generated attachments.
	 *
	 * @param string $file_name Raw file name input.
	 * @return string
	 */
	protected function normalise_file_stem( $file_name ) {
		$file_name = sanitize_file_name( (string) $file_name );

		if ( '' === $file_name ) {
			return 'openai-image';
		}

		$info = pathinfo( $file_name );
		$stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
		$stem = sanitize_title( $stem );

		if ( '' === $stem ) {
			return 'openai-image';
		}

		return $stem;
	}

	/**
	 * Determine the MIME type for the saved image file.
	 *
	 * @param string $file_path      Absolute file path.
	 * @param string $preferred_type Preferred MIME type for the format.
	 * @param array  $image          Response payload from OpenAI.
	 * @return string
	 */
	protected function determine_mime_type( $file_path, $preferred_type, array $image ) {
		$file_info = wp_check_filetype( wp_basename( $file_path ), null );

		if ( ! empty( $file_info['type'] ) ) {
			return $file_info['type'];
		}

		if ( ! empty( $image['mime_type'] ) ) {
			$content_type = sanitize_text_field( $image['mime_type'] );
			if ( '' !== $content_type ) {
				return $content_type;
			}
		}

		if ( ! empty( $preferred_type ) ) {
			return $preferred_type;
		}

		return 'image/png';
	}

	/**
	 * Generate a human readable attachment title using the source prompt.
	 *
	 * @param string $prompt Original prompt text.
	 * @return string
	 */
	protected function generate_attachment_title( $prompt ) {
		$prompt = (string) $prompt;
		$prompt = preg_replace( '/\s+/', ' ', $prompt );
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return __( 'OpenAI Image', 'wp-mcp-ai' );
		}

		$excerpt = wp_trim_words( $prompt, 12, '…' );

		/* translators: %s: Short excerpt of the prompt used to generate an image. */
		return sprintf( __( 'OpenAI Image: %s', 'wp-mcp-ai' ), $excerpt );
	}

	/**
	 * Delete a generated file from disk safely when an error occurs.
	 *
	 * @param string $file_path Absolute file path.
	 */
	protected function delete_file_safely( $file_path ) {
		$file_path = (string) $file_path;

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return;
		}

		if ( ! function_exists( 'wp_delete_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		wp_delete_file( $file_path );
	}

	/**
	 * Build an inline content payload for the stored image so API clients can render immediately.
	 *
	 * @param array $storage Stored attachment metadata.
	 * @return array
	 */
	protected function build_inline_content_payload( array $storage ) {
		$file_path = isset( $storage['file'] ) ? $storage['file'] : '';

		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return array();
		}

		$file_contents = file_get_contents( $file_path );

		if ( false === $file_contents || '' === $file_contents ) {
			return array();
		}

		$encoded = base64_encode( $file_contents );

		if ( '' === $encoded ) {
			return array();
		}

		$mime_type = isset( $storage['mime_type'] ) ? $storage['mime_type'] : '';

		$content = array(
			'encoding' => 'base64',
			'data'     => $encoded,
		);

		if ( '' !== $mime_type ) {
			$content['mime_type'] = $mime_type;
			$content['data_url']  = sprintf( 'data:%s;base64,%s', $mime_type, $encoded );
		}

		if ( isset( $storage['file_name'] ) && '' !== $storage['file_name'] ) {
			$content['file_name'] = $storage['file_name'];
		}

		if ( isset( $storage['bytes'] ) && $storage['bytes'] ) {
			$content['bytes'] = (int) $storage['bytes'];
		}

		return $content;
	}

	/**
	 * Sanitize image generation results for LLM consumption.
	 *
	 * Image generation returns base64-encoded image data that can be 100KB+.
	 * The LLM doesn't need this binary data - it only needs metadata to reference
	 * the generated image (attachment_id, url, file_name, etc.).
	 *
	 * For the agentic loop to work with vision models, we add an image_url structure
	 * that allows the model to "see" the generated image in subsequent iterations.
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with only metadata and image_url for vision.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip base64 content.
		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			unset( $result['content']['data'] );
			unset( $result['content']['data_url'] );

			if ( empty( $result['content'] ) ) {
				unset( $result['content'] );
			}
		}

		// Keep only essential metadata.
		$keep_fields = array(
			'attachment_id',
			'url',
			'file_name',
			'mime_type',
			'bytes',
			'format',
			'size',
			'quality',
			'model',
			'provider',
			'prompt',
			'revised_prompt',
			'text',  // Descriptive message about the generated image.
			'usage', // Token usage data for UI display.
			'cost',  // Cost data for UI display.
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add image_url structure for the agentic loop.
		// This allows vision models to "see" the generated image in subsequent iterations.
		if ( isset( $result['url'] ) && '' !== $result['url'] ) {
			$sanitized['image_url'] = array(
				'url' => $result['url'],
			);
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // Requires OpenAI API credentials.
			'requires-capability',  // Requires user capabilities.
			'write',                // Creates media files.
			'async',                // May take significant time to generate images.
			'rate-limited',         // Subject to OpenAI rate limits.
			'requires-model',       // Requires image model specification.
			'consumes-tokens',      // Uses AI credits/tokens.
			'model-dependent',      // Output quality varies by model.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(
			'image-generation', // Requires model capable of generating images.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tool_rules() {
		return array(
			'model_requirements'    => array(
				'providers' => array( 'openai' ),
				'models'    => array( 'gpt-image-1', 'dall-e-3', 'dall-e-2' ),
				'required'  => true,
			),
			'parameter_constraints' => array(
				'required_fields'   => array( 'prompt' ),
				'optional_fields'   => array( 'model', 'size', 'quality', 'response_format', 'file_name', 'timeout' ),
				'max_prompt_length' => 4000,
			),
			'rate_limits'           => array(
				'requests_per_minute' => 5,
				'requests_per_hour'   => 50,
				'concurrent_requests' => 2,
			),
			'timeout_constraints'   => array(
				'recommended_timeout' => 60,
				'max_execution_time'  => 120,
			),
			'response_constraints'  => array(
				'max_size'           => 5242880, // 5MB typical image size.
				'supports_streaming' => false,
			),
			'dependencies'          => array(
				'required_settings'   => array(
					'api_key' => 'wp_mcp_ai_openai_api_key',
				),
				'required_extensions' => array( 'gd' ), // For image processing.
			),
			'orchestration_hints'   => array(
				'can_run_parallel' => true,
				'requires_lock'    => false,
				'cache_ttl'        => 0, // Don't cache - each generation unique.
				'retry_strategy'   => 'exponential_backoff',
				'max_retries'      => 3,
			),
		);
	}

	/**
	 * Estimate token usage for image generation.
	 *
	 * OpenAI's image generation API doesn't return usage metadata, so we estimate
	 * based on prompt length and output image size. These are approximations for
	 * display purposes only.
	 *
	 * Token estimation based on OpenAI's pricing documentation:
	 * - Input tokens: ~1.3 tokens per word for the text prompt
	 * - Output tokens: Based on image size (varies by model and size)
	 *
	 * @param string $prompt Text prompt for image generation.
	 * @param string $size   Image size (e.g., '1024x1024', '1024x1792').
	 * @param string $model  Model identifier (e.g., 'gpt-image-1', 'dall-e-3').
	 * @return array Estimated usage array with is_estimated flag.
	 */
	protected function estimate_image_token_usage( $prompt, $size, $model ) {
		// Estimate input tokens from prompt (roughly 1.3 tokens per word).
		$words         = str_word_count( $prompt );
		$prompt_tokens = (int) ceil( $words * 1.3 );

		// Estimate output tokens based on image size.
		// These are rough estimates based on OpenAI's image generation token consumption.
		$output_tokens_map = array(
			'1024x1024' => 2048,  // Standard square image.
			'1024x1792' => 3584,  // Portrait format.
			'1792x1024' => 3584,  // Landscape format.
			'512x512'   => 512,   // DALL-E 2 size.
			'256x256'   => 256,   // DALL-E 2 size.
		);

		$completion_tokens = isset( $output_tokens_map[ $size ] ) ? $output_tokens_map[ $size ] : 2048;

		// Adjust for quality if it affects token usage (model-dependent).
		// For gpt-image-1, higher quality may use more tokens.
		// This is an approximation - actual usage may vary.

		$total_tokens = $prompt_tokens + $completion_tokens;

		return array(
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'total_tokens'      => $total_tokens,
			'is_estimated'      => true, // Flag to indicate this is an estimate.
		);
	}

	/**
	 * Estimate cost for image generation.
	 *
	 * Based on OpenAI's pricing as of 2024-2025:
	 * - gpt-image-1: $5/1M input tokens, $40/1M output tokens
	 *   - Low quality (1024x1024): ~$0.011
	 *   - Medium quality (1024x1024): ~$0.042
	 *   - High quality (1024x1024): ~$0.167
	 * - DALL-E 3:
	 *   - Standard quality (1024x1024): $0.04
	 *   - HD quality (1024x1024): $0.08
	 *   - Larger sizes (1024x1792): Standard $0.08, HD $0.12
	 *
	 * @param string $model   Model identifier.
	 * @param string $size    Image size.
	 * @param string $quality Quality setting.
	 * @return float Estimated cost in USD.
	 */
	protected function estimate_image_cost( $model, $size, $quality ) {
		$model = strtolower( $model );

		// gpt-image-1 pricing (token-based).
		if ( 'gpt-image-1' === $model ) {
			$base_cost = 0.042; // Medium quality 1024x1024.

			// Adjust for quality.
			if ( 'low' === $quality ) {
				$base_cost = 0.011;
			} elseif ( 'high' === $quality ) {
				$base_cost = 0.167;
			} elseif ( 'auto' === $quality ) {
				$base_cost = 0.042; // Assume medium.
			}

			// Adjust for larger sizes (approximately 1.5x cost for larger).
			if ( in_array( $size, array( '1024x1792', '1792x1024' ), true ) ) {
				$base_cost *= 1.5;
			}

			return $base_cost;
		}

		// DALL-E 3 pricing (per-image).
		if ( 'dall-e-3' === $model ) {
			$is_large = in_array( $size, array( '1024x1792', '1792x1024' ), true );
			$is_hd    = 'hd' === $quality;

			if ( $is_large ) {
				return $is_hd ? 0.12 : 0.08;
			}

			return $is_hd ? 0.08 : 0.04;
		}

		// DALL-E 2 pricing (per-image).
		if ( 'dall-e-2' === $model ) {
			// DALL-E 2 sizes: 1024x1024, 512x512, 256x256.
			$size_costs = array(
				'1024x1024' => 0.02,
				'512x512'   => 0.018,
				'256x256'   => 0.016,
			);

			return isset( $size_costs[ $size ] ) ? $size_costs[ $size ] : 0.02;
		}

		// Unknown model, return 0.
		return 0.0;
	}
}
