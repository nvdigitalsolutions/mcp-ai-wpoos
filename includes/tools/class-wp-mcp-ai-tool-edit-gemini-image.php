<?php
/**
 * Tool that edits images using Gemini's Nano Banana (image editing) capabilities.
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
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

/**
 * Provides a tool for editing images via Gemini Nano Banana and storing them as attachments.
 */
class WP_MCP_AI_Tool_Edit_Gemini_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Rules_Interface {
	const DEFAULT_MODEL        = 'gemini-2.5-flash-image';
	const DEFAULT_MIME_TYPE    = 'image/png';
	const DEFAULT_ASPECT_RATIO = '1:1';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'edit_gemini_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Edit Gemini Image (Nano Banana)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Edits an existing image using Gemini Nano Banana (text + image-to-image) and stores the result in the Media Library.', 'wp-mcp-ai' );
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
				'prompt'        => array(
					'type'        => 'string',
					'description' => __( 'Text instruction describing the desired edits (e.g., "remove background", "change sky to sunset", "make brighter").', 'wp-mcp-ai' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the image to edit.', 'wp-mcp-ai' ),
				),
				'image_url'     => array(
					'type'        => 'string',
					'description' => __( 'URL of the image to edit (alternative to attachment_id).', 'wp-mcp-ai' ),
				),
				'image_data'    => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image data to edit (alternative to attachment_id or image_url). Useful for editing images created in the chat.', 'wp-mcp-ai' ),
				),
				'source_mime_type' => array(
					'type'        => 'string',
					'description' => __( 'MIME type of the source image data (required when using image_data).', 'wp-mcp-ai' ),
				),
				'model'         => array(
					'type'        => 'string',
					'description' => __( 'Gemini image model to use.', 'wp-mcp-ai' ),
					'default'     => $defaults['model'],
				),
				'aspect_ratio'  => array(
					'type'        => 'string',
					'description' => __( 'Aspect ratio for the edited image.', 'wp-mcp-ai' ),
					'enum'        => $aspect_choices,
					'default'     => $defaults['aspect_ratio'],
				),
				'mime_type'     => array(
					'type'        => 'string',
					'description' => __( 'Preferred MIME type for the saved image.', 'wp-mcp-ai' ),
					'enum'        => $mime_choices,
					'default'     => $defaults['mime_type'],
				),
				'file_name'     => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved image attachment.', 'wp-mcp-ai' ),
				),
				'timeout'       => array(
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
				'label'   => __( 'edit_gemini_image', 'wp-mcp-ai' ),
				'payload' => __( 'edit_gemini_image', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Remove background', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `edit_gemini_image` tool to remove the background from an image. Ask for the image to edit, then use a prompt like "remove background, make transparent".', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Change image style', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `edit_gemini_image` tool to change the style of an image. Ask for the image and desired style, then create a prompt like "convert to watercolor painting style".', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Enhance photo', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `edit_gemini_image` tool to enhance a photo. Ask for the image, then use prompts like "enhance brightness and contrast", "sharpen details", or "improve lighting".', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to edit images.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'wp-mcp-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
			}
		}

		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'No editing instruction was supplied.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		// Get the source image - either from attachment_id, image_url, or image_data.
		$source_image = $this->get_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Ensure image data is base64-encoded for Gemini API.
		// All sources return raw binary data, so we need to encode it.
		if ( isset( $source_image['data'] ) && ! empty( $source_image['data'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$source_image['data'] = base64_encode( $source_image['data'] );
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
			'source_image' => $source_image,
		);

		if ( $timeout ) {
			$options['timeout'] = max( 5, min( 300, $timeout ) );
		}

		// Use edit_image method (we'll need to add this to the Gemini client).
		$image = $client->edit_image( $prompt, $options );

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
		$text = sprintf(
			/* translators: 1: image title, 2: attachment ID */
			__( 'Successfully edited image "%1$s" (ID: %2$d). Edit instruction: %3$s', 'wp-mcp-ai' ),
			$storage['title'],
			$storage['attachment_id'],
			$prompt
		);
		
		$result = array(
			'attachment_id'     => $storage['attachment_id'],
			'url'               => $storage['url'],
			'download_url'      => isset( $storage['download_url'] ) && '' !== $storage['download_url'] ? $storage['download_url'] : $storage['url'],
			'file_name'         => $storage['file_name'],
			'mime_type'         => $storage['mime_type'],
			'bytes'             => $storage['bytes'],
			'title'             => $storage['title'],
			'model'             => isset( $image['model'] ) ? $image['model'] : $model,
			'aspect_ratio'      => isset( $image['aspect_ratio'] ) ? $image['aspect_ratio'] : $aspect_ratio,
			'format'            => isset( $image['format'] ) ? $image['format'] : $this->map_mime_type_to_format( $storage['mime_type'] ),
			'edit_instruction'  => $prompt,
			'source_attachment' => isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : null,
			'provider'          => 'gemini', // Track provider for accurate cost attribution.
			'text'              => $text, // Descriptive message for LLM and chat UI.
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
		 * Allow third parties to filter the image editing result before it is returned.
		 *
		 * @param array $result    Result array to be returned.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		$result = apply_filters( 'wp_mcp_ai_edit_gemini_image_result', $result, $arguments, $context );

		return $result;
	}

	/**
	 * Get the source image data from attachment_id, image_url, or image_data.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error Array with image data or WP_Error on failure.
	 */
	protected function get_source_image( array $arguments, $user_id ) {
		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$image_url     = isset( $arguments['image_url'] ) ? esc_url_raw( $arguments['image_url'] ) : '';
		$image_data    = isset( $arguments['image_data'] ) ? $arguments['image_data'] : '';

		if ( $attachment_id > 0 ) {
			// Get image from WordPress attachment.
			$file_path = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_attachment', __( 'The specified attachment does not exist.', 'wp-mcp-ai' ), array( 'status' => 404 ) );
			}

			// Check if user has permission to read this attachment.
			if ( $user_id && ! current_user_can( 'read_post', $attachment_id ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to access this attachment.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
			}

			$image_data = file_get_contents( $file_path );
			if ( false === $image_data ) {
				return new WP_Error( 'wp_mcp_ai_read_error', __( 'Failed to read the image file.', 'wp-mcp-ai' ) );
			}

			$mime_type = get_post_mime_type( $attachment_id );
			
			// Validate MIME type is supported for image editing.
			$supported_mime_types = array(
				'image/jpeg',
				'image/png',
				'image/gif',
				'image/webp',
				'image/bmp',
			);
			
			if ( ! in_array( $mime_type, $supported_mime_types, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_unsupported_image_type',
					sprintf(
						/* translators: %s: MIME type */
						__( 'Image type "%s" is not supported for editing. Please use JPEG, PNG, GIF, WebP, or BMP formats.', 'wp-mcp-ai' ),
						$mime_type
					),
					array( 'status' => 400 )
				);
			}

			return array(
				'data'      => $image_data,
				'mime_type' => $mime_type,
				'source'    => 'attachment',
			);
		} elseif ( '' !== $image_url ) {
			// Download image from URL.
			$response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'wp_mcp_ai_download_error', __( 'Failed to download the source image.', 'wp-mcp-ai' ), array( 'error' => $response->get_error_message() ) );
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $status_code < 200 || $status_code >= 300 ) {
				return new WP_Error( 'wp_mcp_ai_download_error', sprintf( __( 'Failed to download image. HTTP %d', 'wp-mcp-ai' ), $status_code ), array( 'status' => $status_code ) );
			}

			$image_data = wp_remote_retrieve_body( $response );
			if ( '' === $image_data ) {
				return new WP_Error( 'wp_mcp_ai_download_error', __( 'Downloaded image is empty.', 'wp-mcp-ai' ) );
			}

			$headers   = wp_remote_retrieve_headers( $response );
			$mime_type = isset( $headers['content-type'] ) ? $headers['content-type'] : 'image/png';

			return array(
				'data'      => $image_data,
				'mime_type' => $mime_type,
				'source'    => 'url',
			);
		} elseif ( '' !== $image_data ) {
			// Use base64-encoded image data (blob) directly.
			// This is useful for editing images that were just created in the chat.
			$decoded_data = base64_decode( $image_data, true );

			if ( false === $decoded_data ) {
				return new WP_Error( 'wp_mcp_ai_invalid_image_data', __( 'The provided image data is not valid base64.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			if ( '' === $decoded_data ) {
				return new WP_Error( 'wp_mcp_ai_empty_image_data', __( 'The decoded image data is empty.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			// Get MIME type from argument or default to PNG.
			$mime_type = isset( $arguments['source_mime_type'] ) ? sanitize_mime_type( $arguments['source_mime_type'] ) : 'image/png';

			// Validate MIME type.
			$allowed_mime_types = array( 'image/png', 'image/jpeg', 'image/jpg', 'image/webp' );
			if ( ! in_array( $mime_type, $allowed_mime_types, true ) ) {
				$mime_type = 'image/png';
			}

			return array(
				'data'      => $decoded_data,
				'mime_type' => $mime_type,
				'source'    => 'blob',
			);
		} else {
			return new WP_Error( 'wp_mcp_ai_missing_source', __( 'Either attachment_id, image_url, or image_data must be provided.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Retrieve the configured defaults for image editing.
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
		 * Allow third parties to filter the default Gemini image editing settings.
		 *
		 * @param array $defaults Default settings.
		 */
		return apply_filters( 'wp_mcp_ai_gemini_image_edit_defaults', $defaults );
	}

	/**
	 * Get allowed aspect ratios.
	 *
	 * @return array
	 */
	protected function get_allowed_aspect_ratios() {
		return array(
			'1:1'  => '1:1',
			'3:4'  => '3:4',
			'4:3'  => '4:3',
			'9:16' => '9:16',
			'16:9' => '16:9',
		);
	}

	/**
	 * Get allowed MIME types.
	 *
	 * @return array
	 */
	protected function get_allowed_mime_types() {
		return array(
			'image/png'  => 'PNG',
			'image/jpeg' => 'JPEG',
		);
	}

	/**
	 * Normalise aspect ratio value.
	 *
	 * @param string $value Raw aspect ratio value.
	 * @return string
	 */
	protected function normalise_aspect_ratio_value( $value ) {
		$value   = sanitize_text_field( $value );
		$allowed = $this->get_allowed_aspect_ratios();

		return isset( $allowed[ $value ] ) ? $value : '';
	}

	/**
	 * Normalise MIME type value.
	 *
	 * @param string $value Raw MIME type value.
	 * @return string
	 */
	protected function normalise_mime_type( $value ) {
		$value   = sanitize_text_field( $value );
		$allowed = $this->get_allowed_mime_types();

		return isset( $allowed[ $value ] ) ? $value : '';
	}

	/**
	 * Store the edited image as a WordPress attachment.
	 *
	 * @param array  $image     Response payload from the Gemini client.
	 * @param string $file_name Optional preferred file name.
	 * @param string $prompt    Original editing instruction.
	 * @param int    $user_id   Acting user ID.
	 * @return array|WP_Error
	 */
	protected function store_image_attachment( array $image, $file_name, $prompt, $user_id ) {
		$data      = isset( $image['image'] ) ? $image['image'] : '';
		$mime_type = isset( $image['mime_type'] ) ? $image['mime_type'] : self::DEFAULT_MIME_TYPE;

		if ( '' === $data ) {
			return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Unable to determine the image data for storage.', 'wp-mcp-ai' ) );
		}

		$file_stem = $this->normalise_file_stem( $file_name );
		$extension = $this->get_extension_from_mime_type( $mime_type );
		$file_name = sprintf( '%s-edited-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $extension );

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to save the edited image file.', 'wp-mcp-ai' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to write the edited image file to disk.', 'wp-mcp-ai' ) );
		}

		$title = $this->generate_attachment_title( $prompt );

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
			wp_delete_file( $file_path );
			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register the edited image as an attachment.', 'wp-mcp-ai' ), array( 'error' => $attachment_id ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		// Get local WordPress URL using utility class for SoC compliance.
		$local_url = WP_MCP_AI_Media_URL_Utils::get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => wp_basename( $file_path ),
			'url'           => $local_url,
			'download_url'  => $local_url,
			'mime_type'     => $mime_type,
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => $title,
		);
	}

	/**
	 * Normalise a file stem used for edited attachments.
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
	 * Get file extension from MIME type.
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	protected function get_extension_from_mime_type( $mime_type ) {
		$mime_type = strtolower( $mime_type );

		if ( 'image/jpeg' === $mime_type || 'image/jpg' === $mime_type ) {
			return 'jpg';
		}

		return 'png';
	}

	/**
	 * Generate a human readable attachment title using the editing instruction.
	 *
	 * @param string $prompt Original editing instruction.
	 * @return string
	 */
	protected function generate_attachment_title( $prompt ) {
		$prompt = (string) $prompt;
		$prompt = preg_replace( '/\s+/', ' ', $prompt );
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return __( 'Gemini Edited Image', 'wp-mcp-ai' );
		}

		$excerpt = wp_trim_words( $prompt, 8, '…' );

		/* translators: %s: Short excerpt of the editing instruction. */
		return sprintf( __( 'Gemini Edit: %s', 'wp-mcp-ai' ), $excerpt );
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // Requires Gemini API credentials.
			'requires-capability',  // Requires user capabilities.
			'write',                // Creates/modifies media files.
			'async',                // May take significant time to edit images.
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
			'image-editing', // Requires model capable of editing images.
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
				'optional_fields'   => array( 'attachment_id', 'image_url', 'image_data', 'source_mime_type', 'model', 'aspect_ratio', 'mime_type', 'file_name', 'timeout' ),
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
				'cache_ttl'        => 0, // Don't cache - each edit is unique.
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
	 * that allows the model to "see" the edited image in subsequent iterations.
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
			'edit_instruction',
			'source_attachment',
			'provider',
			'usage',
			'cost',  // Cost data for UI display.
			'text',  // Descriptive message about the edited image.
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add image_url structure for the agentic loop.
		// This allows vision models to "see" the edited image in subsequent iterations.
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
