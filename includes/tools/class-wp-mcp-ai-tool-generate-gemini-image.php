<?php
/**
 * Tool that generates images using Gemini's multimodal endpoint.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-llm-sanitizer-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for generating images via Gemini and storing them as attachments.
 */
class WP_MCP_AI_Tool_Generate_Gemini_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Rules_Interface {
	const DEFAULT_MODEL        = 'gemini-2.5-flash-image';
	const DEFAULT_MIME_TYPE    = 'image/png';
	const DEFAULT_ASPECT_RATIO = '1:1';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_gemini_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Gemini Image', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates an image with Gemini and stores it in the Media Library.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$defaults = $this->get_configured_defaults();

		$aspect_choices = array_keys( $this->get_allowed_aspect_ratios() );
		$mime_choices   = array_keys( $this->get_allowed_mime_types() );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'       => array(
					'type'        => 'string',
					'description' => __( 'The text prompt describing the desired image.', 'wp-mcp-ai' ),
				),
				'model'        => array(
					'type'        => 'string',
					'description' => __( 'Gemini image model to use.', 'wp-mcp-ai' ),
					'default'     => $defaults['model'],
				),
				'aspect_ratio' => array(
					'type'        => 'string',
					'description' => __( 'Aspect ratio for the generated image.', 'wp-mcp-ai' ),
					'enum'        => $aspect_choices,
					'default'     => $defaults['aspect_ratio'],
				),
				'mime_type'    => array(
					'type'        => 'string',
					'description' => __( 'Preferred MIME type for the saved image.', 'wp-mcp-ai' ),
					'enum'        => $mime_choices,
					'default'     => $defaults['mime_type'],
				),
				'file_name'    => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved image attachment.', 'wp-mcp-ai' ),
				),
				'timeout'      => array(
					'type'        => 'integer',
					'description' => __( 'Override the Gemini request timeout in seconds.', 'wp-mcp-ai' ),
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
				'label'   => __( 'generate_gemini_image', 'wp-mcp-ai' ),
				'payload' => __( 'generate_gemini_image', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Revise existing concept', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `generate_gemini_image` tool to update an existing concept. Ask what should change, capture the current prompt for context, then propose an adjusted prompt reflecting the requested edits before running the tool.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Add product to lifestyle scene', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `generate_gemini_image` tool to place the product in a lifestyle setting. Gather details about the environment, target audience, props, and camera angle, then assemble a detailed prompt that keeps the product as the hero of the scene.', 'wp-mcp-ai' ),
			),
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

		$aspect_ratio = isset( $arguments['aspect_ratio'] ) ? $this->normalise_aspect_ratio_value( $arguments['aspect_ratio'] ) : $defaults['aspect_ratio'];
		if ( '' === $aspect_ratio ) {
			$aspect_ratio = $defaults['aspect_ratio'];
		}

		$mime_type = isset( $arguments['mime_type'] ) ? $this->normalise_mime_type( $arguments['mime_type'] ) : $defaults['mime_type'];
		if ( '' === $mime_type ) {
			$mime_type = $defaults['mime_type'];
		}

		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : $defaults['model'];
		$model = '' === $model ? $defaults['model'] : $model;

		$file_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : '';
		$timeout   = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 0;

		$client  = new WP_MCP_AI_Gemini_Client();
		$options = array(
			'model'        => $model,
			'aspect_ratio' => $aspect_ratio,
			'mime_type'    => $mime_type,
		);

		if ( $timeout ) {
			$options['timeout'] = max( 5, min( 300, $timeout ) );
		}

		$image = $client->generate_image( $prompt, $options );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( empty( $image['image'] ) ) {
			return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Gemini returned an empty image response.', 'wp-mcp-ai' ) );
		}

		$storage = $this->store_image_attachment( $image, $file_name, $prompt, $user_id );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		// Build descriptive text message for the LLM and chat UI.
		$text_parts = array();
		$text_parts[] = sprintf(
			/* translators: 1: image title, 2: attachment ID */
			__( 'Successfully generated image "%1$s" (ID: %2$d).', 'wp-mcp-ai' ),
			$storage['title'],
			$storage['attachment_id']
		);
		
		if ( ! empty( $image['revised_prompt'] ) ) {
			$text_parts[] = sprintf(
				/* translators: %s: revised prompt from Gemini */
				__( 'Description: %s', 'wp-mcp-ai' ),
				$image['revised_prompt']
			);
		}
		
		$text_parts[] = sprintf(
			/* translators: 1: aspect ratio, 2: format */
			__( 'Format: %1$s, %2$s', 'wp-mcp-ai' ),
			isset( $image['aspect_ratio'] ) ? $image['aspect_ratio'] : $aspect_ratio,
			strtoupper( isset( $image['format'] ) ? $image['format'] : $this->map_mime_type_to_format( $storage['mime_type'] ) )
		);
		
		$result = array(
			'attachment_id'  => $storage['attachment_id'],
			'url'            => $storage['url'],
			'download_url'   => isset( $storage['download_url'] ) && '' !== $storage['download_url'] ? $storage['download_url'] : $storage['url'],
			'file_name'      => $storage['file_name'],
			'mime_type'      => $storage['mime_type'],
			'bytes'          => $storage['bytes'],
			'title'          => $storage['title'],
			'model'          => isset( $image['model'] ) ? $image['model'] : $model,
			'aspect_ratio'   => isset( $image['aspect_ratio'] ) ? $image['aspect_ratio'] : $aspect_ratio,
			'format'         => isset( $image['format'] ) ? $image['format'] : $this->map_mime_type_to_format( $storage['mime_type'] ),
			'prompt'         => $prompt,
			'revised_prompt' => isset( $image['revised_prompt'] ) ? $image['revised_prompt'] : '',
			'created'        => isset( $image['created'] ) ? $image['created'] : time(),
			'provider'       => 'gemini', // Track provider for accurate cost attribution.
			'text'           => implode( ' ', $text_parts ), // Descriptive message for LLM and chat UI.
		);

		// Include usage metadata if available for accurate cost tracking.
		if ( isset( $image['usage'] ) && is_array( $image['usage'] ) ) {
			$result['usage'] = $image['usage'];
		}

		$inline_content = $this->build_inline_content_payload( $storage );

		if ( ! empty( $inline_content ) ) {
			$result['content'] = $inline_content;
		}

		/**
		 * Allow third parties to filter the Gemini image generation result before it is returned.
		 *
		 * @param array $result    Result array to be returned.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_gemini_image_result', $result, $arguments, $context );

		return $result;
	}

	/**
	 * Retrieve the configured defaults for Gemini image generation.
	 *
	 * @return array
	 */
	protected function get_configured_defaults() {
		$defaults = array(
			'model'        => self::DEFAULT_MODEL,
			'mime_type'    => self::DEFAULT_MIME_TYPE,
			'aspect_ratio' => self::DEFAULT_ASPECT_RATIO,
		);

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( ! empty( $settings['gemini_image_model'] ) ) {
				$defaults['model'] = sanitize_text_field( $settings['gemini_image_model'] );
			}

			if ( ! empty( $settings['gemini_image_mime_type'] ) ) {
				$mime_type = $this->normalise_mime_type( $settings['gemini_image_mime_type'] );
				if ( '' !== $mime_type ) {
					$defaults['mime_type'] = $mime_type;
				}
			}

			if ( ! empty( $settings['gemini_image_aspect_ratio'] ) ) {
				$aspect_ratio = $this->normalise_aspect_ratio_value( $settings['gemini_image_aspect_ratio'] );
				if ( '' !== $aspect_ratio ) {
					$defaults['aspect_ratio'] = $aspect_ratio;
				}
			}
		}

		/**
		 * Allow third parties to filter the default Gemini image settings.
		 *
		 * @param array $defaults Default settings array.
		 */
		$defaults = apply_filters( 'wp_mcp_ai_gemini_image_defaults', $defaults );

		if ( empty( $defaults['model'] ) ) {
			$defaults['model'] = self::DEFAULT_MODEL;
		}

		$defaults['mime_type'] = $this->normalise_mime_type( isset( $defaults['mime_type'] ) ? $defaults['mime_type'] : self::DEFAULT_MIME_TYPE );
		if ( '' === $defaults['mime_type'] ) {
			$defaults['mime_type'] = self::DEFAULT_MIME_TYPE;
		}

		$defaults['aspect_ratio'] = $this->normalise_aspect_ratio_value( isset( $defaults['aspect_ratio'] ) ? $defaults['aspect_ratio'] : self::DEFAULT_ASPECT_RATIO );
		if ( '' === $defaults['aspect_ratio'] ) {
			$defaults['aspect_ratio'] = self::DEFAULT_ASPECT_RATIO;
		}

		return $defaults;
	}

	/**
	 * Store the generated image as a WordPress attachment.
	 *
	 * @param array  $image     Response payload from the Gemini client.
	 * @param string $file_name Optional preferred file name.
	 * @param string $prompt    Original text prompt.
	 * @param int    $user_id   Acting user ID.
	 * @return array|WP_Error
	 */
	protected function store_image_attachment( array $image, $file_name, $prompt, $user_id ) {
		$data      = isset( $image['image'] ) ? $image['image'] : '';
		$mime_type = isset( $image['mime_type'] ) ? $this->normalise_mime_type( $image['mime_type'] ) : self::DEFAULT_MIME_TYPE;
		$mimes     = $this->get_allowed_mime_types();

		if ( '' === $data || '' === $mime_type || ! isset( $mimes[ $mime_type ] ) ) {
			return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Unable to determine the image format for storage.', 'wp-mcp-ai' ) );
		}

		$file_stem = $this->normalise_file_stem( $file_name );
		$file_name = sprintf( '%s-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $mimes[ $mime_type ]['extension'] );

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

		$resolved_mime = $this->determine_mime_type( $file_path, $mime_type, $image );
		$title         = $this->generate_attachment_title( $prompt );

		$attachment = array(
			'post_mime_type' => $resolved_mime,
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
		}

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( ! $attachment_url && isset( $upload['url'] ) && '' !== $upload['url'] ) {
			$attachment_url = $upload['url'];
		}

		$download_url = $this->prepare_attachment_download_url( $attachment_id, $attachment_url );

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => wp_basename( $file_path ),
			'url'           => $attachment_url ? $attachment_url : ( isset( $upload['url'] ) ? $upload['url'] : '' ),
			'download_url'  => $download_url,
			'mime_type'     => $resolved_mime,
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => $title,
		);
	}

	/**
	 * Build a download URL for the stored attachment.
	 *
	 * @param int    $attachment_id  Attachment ID.
	 * @param string $fallback       Fallback URL if a specific download link cannot be generated.
	 * @return string
	 */
	protected function prepare_attachment_download_url( $attachment_id, $fallback ) {
		$attachment_id = absint( $attachment_id );

		if ( $attachment_id ) {
			$download_url = wp_get_attachment_url( $attachment_id );

			if ( $download_url ) {
				return $download_url;
			}
		}

		return (string) $fallback;
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
	 * Retrieve the allowed aspect ratio options.
	 *
	 * @return array
	 */
	protected function get_allowed_aspect_ratios() {
		$ratios = array(
			'1:1'  => __( 'Square (1:1)', 'wp-mcp-ai' ),
			'3:4'  => __( 'Portrait (3:4)', 'wp-mcp-ai' ),
			'4:3'  => __( 'Landscape (4:3)', 'wp-mcp-ai' ),
			'9:16' => __( 'Vertical (9:16)', 'wp-mcp-ai' ),
			'16:9' => __( 'Widescreen (16:9)', 'wp-mcp-ai' ),
		);

		/**
		 * Allow third parties to filter the Gemini image aspect ratio options.
		 *
		 * @param array $ratios Allowed aspect ratios.
		 */
		return apply_filters( 'wp_mcp_ai_gemini_image_aspect_ratios', $ratios );
	}

	/**
	 * Retrieve the allowed MIME types for generated images.
	 *
	 * @return array
	 */
	protected function get_allowed_mime_types() {
		$types = array(
			'image/png'  => array(
				'label'     => __( 'PNG', 'wp-mcp-ai' ),
				'extension' => 'png',
			),
			'image/jpeg' => array(
				'label'     => __( 'JPEG', 'wp-mcp-ai' ),
				'extension' => 'jpg',
			),
			'image/webp' => array(
				'label'     => __( 'WebP', 'wp-mcp-ai' ),
				'extension' => 'webp',
			),
		);

		/**
		 * Allow third parties to filter the Gemini image MIME type options.
		 *
		 * @param array $types Allowed MIME types keyed by mime string.
		 */
		return apply_filters( 'wp_mcp_ai_gemini_image_mime_types', $types );
	}

	/**
	 * Normalise a requested MIME type.
	 *
	 * @param string $mime_type Raw MIME type.
	 * @return string
	 */
	protected function normalise_mime_type( $mime_type ) {
		$mime_type = sanitize_mime_type( (string) $mime_type );
		$allowed   = $this->get_allowed_mime_types();

		if ( isset( $allowed[ $mime_type ] ) ) {
			return $mime_type;
		}

		if ( 'image/jpg' === $mime_type && isset( $allowed['image/jpeg'] ) ) {
			return 'image/jpeg';
		}

		return '';
	}

	/**
	 * Normalise a requested aspect ratio value.
	 *
	 * @param string $aspect_ratio Raw aspect ratio input.
	 * @return string
	 */
	protected function normalise_aspect_ratio_value( $aspect_ratio ) {
		$aspect_ratio = strtoupper( (string) $aspect_ratio );
		$aspect_ratio = str_replace( ' ', '', $aspect_ratio );

		if ( preg_match( '/^(\d+):(\d+)$/', $aspect_ratio, $matches ) ) {
			$left  = ltrim( $matches[1], '0' );
			$right = ltrim( $matches[2], '0' );

			if ( '' === $left ) {
				$left = '0';
			}

			if ( '' === $right ) {
				$right = '0';
			}

			$aspect_ratio = $left . ':' . $right;
		}

		$allowed = $this->get_allowed_aspect_ratios();

		return isset( $allowed[ $aspect_ratio ] ) ? $aspect_ratio : '';
	}

	/**
	 * Map a MIME type to a file format identifier.
	 *
	 * @param string $mime_type MIME type string.
	 * @return string
	 */
	protected function map_mime_type_to_format( $mime_type ) {
		switch ( $mime_type ) {
			case 'image/jpeg':
				return 'jpeg';
			case 'image/webp':
				return 'webp';
			case 'image/png':
			default:
				return 'png';
		}
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
			return 'gemini-image';
		}

		$info = pathinfo( $file_name );
		$stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
		$stem = sanitize_title( $stem );

		if ( '' === $stem ) {
			return 'gemini-image';
		}

		return $stem;
	}

	/**
	 * Determine the MIME type for the saved image file.
	 *
	 * @param string $file_path      Absolute file path.
	 * @param string $preferred_type Preferred MIME type for the format.
	 * @param array  $image          Response payload from Gemini.
	 * @return string
	 */
	protected function determine_mime_type( $file_path, $preferred_type, array $image ) {
		$file_info = wp_check_filetype( wp_basename( $file_path ), null );

		if ( ! empty( $file_info['type'] ) ) {
			return $file_info['type'];
		}

		if ( ! empty( $image['mime_type'] ) ) {
			$content_type = $this->normalise_mime_type( $image['mime_type'] );
			if ( '' !== $content_type ) {
				return $content_type;
			}
		}

		if ( ! empty( $preferred_type ) ) {
			return $preferred_type;
		}

		return self::DEFAULT_MIME_TYPE;
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
			return __( 'Gemini Image', 'wp-mcp-ai' );
		}

		$excerpt = wp_trim_words( $prompt, 12, '…' );

		/* translators: %s: Short excerpt of the prompt used to generate an image. */
		return sprintf( __( 'Gemini Image: %s', 'wp-mcp-ai' ), $excerpt );
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // Requires Gemini API credentials.
			'requires-capability',  // Requires user capabilities.
			'write',                // Creates media files.
			'async',                // May take significant time to generate images.
			'rate-limited',         // Subject to Gemini rate limits.
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
				'providers' => array( 'gemini' ),
				'models'    => array( 'gemini-2.5-flash-image', 'gemini-exp-1206' ),
				'required'  => true,
			),
			'parameter_constraints' => array(
				'required_fields'   => array( 'prompt' ),
				'optional_fields'   => array( 'model', 'aspect_ratio', 'mime_type', 'file_name', 'timeout' ),
				'max_prompt_length' => 4000,
			),
			'rate_limits'           => array(
				'requests_per_minute' => 15,
				'requests_per_hour'   => 100,
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
					'api_key' => 'wp_mcp_ai_gemini_api_key',
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
	 * Sanitize tool result before passing to LLM.
	 *
	 * Strips large base64-encoded image data to prevent bloating the LLM context.
	 * The full result with inline content is preserved for frontend display.
	 *
	 * For the agentic loop to work with vision models, we add an image_url structure
	 * that allows the model to "see" the generated image in subsequent iterations.
	 *
	 * @param mixed $result Raw tool execution result.
	 * @return mixed Sanitized result safe for LLM context.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip base64 content to reduce token usage.
		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			unset( $result['content']['data'] );
			unset( $result['content']['data_url'] );

			if ( empty( $result['content'] ) ) {
				unset( $result['content'] );
			}
		}

		// Keep only essential metadata for LLM reasoning.
		$keep_fields = array(
			'attachment_id',
			'url',
			'download_url',
			'file_name',
			'mime_type',
			'bytes',
			'title',
			'model',
			'aspect_ratio',
			'format',
			'prompt',
			'revised_prompt',
			'provider',
			'usage',
			'cost',  // Cost data for UI display.
			'text',  // Descriptive message about the generated image.
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add image_url structure for the agentic loop.
		// This allows vision models to "see" the generated image in subsequent iterations.
		// Prefer download_url (if available) over url for Gemini images.
		$image_url = isset( $result['download_url'] ) && '' !== $result['download_url']
			? $result['download_url']
			: ( isset( $result['url'] ) && '' !== $result['url'] ? $result['url'] : '' );

		if ( '' !== $image_url ) {
			$sanitized['image_url'] = array(
				'url' => $image_url,
			);
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}
}
