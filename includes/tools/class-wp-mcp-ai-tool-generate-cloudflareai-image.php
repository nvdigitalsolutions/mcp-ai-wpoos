<?php
/**
 * Tool that generates images using Cloudflare Workers AI text-to-image models.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-nodejs-subprocess.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-image-response.php';

/**
 * Provides a tool for generating images via Cloudflare Workers AI and storing them as attachments.
 */
class WP_MCP_AI_Tool_Generate_CloudflareAI_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Rules_Interface {
	use WP_MCP_AI_NodeJS_Subprocess;
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Image_Response;

	const DEFAULT_MODEL     = '@cf/stabilityai/stable-diffusion-xl-base-1.0';
	const DEFAULT_WIDTH     = 1024;
	const DEFAULT_HEIGHT    = 1024;
	const DEFAULT_NUM_STEPS = 20;
	const DEFAULT_GUIDANCE  = 7.5;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'cloudflareai_text_to_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Cloudflare AI Image', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates an image with Cloudflare Workers AI and stores it in the Media Library.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$defaults = $this->get_configured_defaults();

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'        => array(
					'type'        => 'string',
					'description' => __( 'The text prompt describing the desired image.', 'mcp-ai-wpoos' ),
				),
				'model'         => array(
					'type'        => 'string',
					'description' => __( 'Cloudflare Workers AI model to use. Examples: @cf/black-forest-labs/flux-2-dev, @cf/leonardo/phoenix-1.0, @cf/stabilityai/stable-diffusion-xl-base-1.0', 'mcp-ai-wpoos' ),
					'default'     => $defaults['model'],
				),
				'width'         => array(
					'type'        => 'integer',
					'description' => __( 'Width of the generated image in pixels (256-2048).', 'mcp-ai-wpoos' ),
					'minimum'     => 256,
					'maximum'     => 2048,
					'default'     => $defaults['width'],
				),
				'height'        => array(
					'type'        => 'integer',
					'description' => __( 'Height of the generated image in pixels (256-2048).', 'mcp-ai-wpoos' ),
					'minimum'     => 256,
					'maximum'     => 2048,
					'default'     => $defaults['height'],
				),
				'num_steps'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of diffusion steps. More steps can improve quality but take longer (1-20).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => $defaults['num_steps'],
				),
				'guidance'      => array(
					'type'        => 'number',
					'description' => __( 'Guidance scale controls how closely the image follows the prompt. Higher values mean stricter adherence.', 'mcp-ai-wpoos' ),
					'default'     => $defaults['guidance'],
				),
				'seed'          => array(
					'type'        => 'integer',
					'description' => __( 'Random seed for reproducibility. Use the same seed with the same prompt to get similar results.', 'mcp-ai-wpoos' ),
				),
				'output_format' => array(
					'type'        => 'string',
					'description' => __( 'Output format for the generated image. Use "svg" to vectorize the raster output. Default is raster format.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'default', 'svg' ),
					'default'     => 'default',
				),
				'file_name'     => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved image attachment.', 'mcp-ai-wpoos' ),
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Override the Cloudflare request timeout in seconds.', 'mcp-ai-wpoos' ),
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
				'label'   => __( 'cloudflareai_text_to_image', 'mcp-ai-wpoos' ),
				'payload' => __( 'cloudflareai_text_to_image', 'mcp-ai-wpoos' ),
			),
			array(
				'label'   => __( 'Generate product visualization', 'mcp-ai-wpoos' ),
				'payload' => __( 'Use the `cloudflareai_text_to_image` tool to create a product visualization. Gather details about the product, setting, lighting, and camera angle, then assemble a detailed prompt.', 'mcp-ai-wpoos' ),
			),
			array(
				'label'   => __( 'Create blog post hero image', 'mcp-ai-wpoos' ),
				'payload' => __( 'Use the `cloudflareai_text_to_image` tool to generate a hero image for a blog post. Ask about the blog post topic and tone, then create a relevant, eye-catching image.', 'mcp-ai-wpoos' ),
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
			'model'     => self::DEFAULT_MODEL,
			'width'     => self::DEFAULT_WIDTH,
			'height'    => self::DEFAULT_HEIGHT,
			'num_steps' => self::DEFAULT_NUM_STEPS,
			'guidance'  => self::DEFAULT_GUIDANCE,
		);

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return $defaults;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		if ( ! empty( $settings['cloudflare_image_model'] ) ) {
			$defaults['model'] = sanitize_text_field( $settings['cloudflare_image_model'] );
		}

		if ( ! empty( $settings['cloudflare_image_width'] ) ) {
			$width = absint( $settings['cloudflare_image_width'] );
			if ( $width >= 256 && $width <= 2048 ) {
				$defaults['width'] = $width;
			}
		}

		if ( ! empty( $settings['cloudflare_image_height'] ) ) {
			$height = absint( $settings['cloudflare_image_height'] );
			if ( $height >= 256 && $height <= 2048 ) {
				$defaults['height'] = $height;
			}
		}

		if ( isset( $settings['cloudflare_image_num_steps'] ) && '' !== $settings['cloudflare_image_num_steps'] ) {
			$num_steps = absint( $settings['cloudflare_image_num_steps'] );
			if ( $num_steps >= 1 && $num_steps <= 20 ) {
				$defaults['num_steps'] = $num_steps;
			}
		}

		if ( isset( $settings['cloudflare_image_guidance'] ) && '' !== $settings['cloudflare_image_guidance'] ) {
			$guidance = (float) $settings['cloudflare_image_guidance'];
			if ( $guidance >= 1.0 && $guidance <= 20.0 ) {
				$defaults['guidance'] = $guidance;
			}
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to generate images.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate images.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'No prompt was supplied for the image request.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		$defaults = $this->get_configured_defaults();

		// Process parameters.
		$model     = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : $defaults['model'];
		$width     = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : $defaults['width'];
		$height    = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : $defaults['height'];
		$num_steps = isset( $arguments['num_steps'] ) ? absint( $arguments['num_steps'] ) : $defaults['num_steps'];
		$guidance  = isset( $arguments['guidance'] ) ? (float) $arguments['guidance'] : $defaults['guidance'];
		$seed      = isset( $arguments['seed'] ) ? absint( $arguments['seed'] ) : null;
		$file_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : '';
		$timeout   = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 0;

		// Validate and clamp values.
		$width     = max( 256, min( 2048, $width ) );
		$height    = max( 256, min( 2048, $height ) );
		$num_steps = max( 1, min( 20, $num_steps ) );

		$options = array(
			'model'     => $model,
			'width'     => $width,
			'height'    => $height,
			'num_steps' => $num_steps,
			'guidance'  => $guidance,
		);

		if ( null !== $seed ) {
			$options['seed'] = $seed;
		}

		if ( $timeout > 0 ) {
			$options['timeout'] = max( 5, min( 300, $timeout ) );
		}

		$client = new WP_MCP_AI_Cloudflare_Client();
		$image  = $client->generate_image( $prompt, $options );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( empty( $image['image'] ) ) {
			return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Cloudflare Workers AI returned an empty image response.', 'mcp-ai-wpoos' ) );
		}

		$storage = $this->store_image_attachment( $image, $file_name, $prompt, $user_id, $context );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		// Check if SVG output is requested.
		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'default';

		if ( 'svg' === $output_format ) {
			// Convert the generated raster image to SVG.
			$svg_storage = $this->convert_to_svg( $storage, $arguments );

			if ( is_wp_error( $svg_storage ) ) {
				// If SVG conversion fails, return the original raster image.
				WP_MCP_AI_Logger::log_error(
					'cloudflareai_svg_conversion_failed',
					'Failed to convert Cloudflare AI-generated image to SVG',
					array(
						'error'         => $svg_storage->get_error_message(),
						'attachment_id' => $storage['attachment_id'],
					)
				);
			} else {
				// Replace storage with SVG version.
				$storage = $svg_storage;
			}
		}

		// Build descriptive text message for the LLM and chat UI.
		$text_parts   = array();
		$text_parts[] = sprintf(
			/* translators: 1: attachment ID */
			__( 'Successfully generated image (ID: %d).', 'mcp-ai-wpoos' ),
			$storage['attachment_id']
		);

		$text_parts[] = sprintf(
			/* translators: 1: width, 2: height, 3: num_steps */
			__( 'Size: %1$dx%2$d, Steps: %3$d', 'mcp-ai-wpoos' ),
			$width,
			$height,
			$num_steps
		);

		if ( null !== $seed ) {
			$text_parts[] = sprintf(
				/* translators: %d: seed value */
				__( 'Seed: %d', 'mcp-ai-wpoos' ),
				$seed
			);
		}

		$text    = implode( ' ', $text_parts );
		$message = $text;

		$result = array(
			'attachment_id' => $storage['attachment_id'],
			'url'           => $storage['url'],
			'download_url'  => isset( $storage['download_url'] ) && '' !== $storage['download_url'] ? $storage['download_url'] : $storage['url'],
			'file_path'     => $storage['file'],
			'file_name'     => $storage['file_name'],
			'mime_type'     => $storage['mime_type'],
			'bytes'         => $storage['bytes'],
			'format'        => isset( $image['format'] ) ? $image['format'] : 'png',
			'width'         => $width,
			'height'        => $height,
			'num_steps'     => $num_steps,
			'guidance'      => $guidance,
			'model'         => $model,
			'provider'      => 'cloudflare',
			'created'       => isset( $image['created'] ) ? $image['created'] : time(),
			'text'          => $text,
			'message'       => $message,
		);

		if ( null !== $seed ) {
			$result['seed'] = $seed;
		}

		// Add usage metadata if available (Cloudflare typically doesn't return this).
		if ( isset( $image['usage'] ) && is_array( $image['usage'] ) ) {
			$result['usage'] = $image['usage'];
		}

		/**
		 * Allow third parties to filter the Cloudflare AI image generation result before it is returned.
		 *
		 * @param array $result    Result array to be returned.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_cloudflareai_image_result', $result, $arguments, $context );

		// Add rendered image HTML to the response for display in chat UI.
		$result = $this->add_image_html_to_response( $result );

		return $result;
	}

	/**
	 * Store the generated image as a WordPress attachment.
	 *
	 * @param array  $image     Response payload from the Cloudflare client.
	 * @param string $file_name Optional preferred file name.
	 * @param string $prompt    Original text prompt.
	 * @param int    $user_id   Acting user ID.
	 * @param array  $context   Optional. Execution context containing parent_job_id.
	 * @return array|WP_Error
	 */
	protected function store_image_attachment( array $image, $file_name, $prompt, $user_id, array $context = array() ) {
		$data      = isset( $image['image'] ) ? $image['image'] : '';
		$format    = isset( $image['format'] ) ? sanitize_key( $image['format'] ) : 'png';
		$mime_type = isset( $image['mime_type'] ) ? sanitize_mime_type( $image['mime_type'] ) : 'image/png';

		if ( '' === $data ) {
			return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Unable to store image: no image data provided.', 'mcp-ai-wpoos' ) );
		}

		// Map format to file extension.
		$extensions = array(
			'png'  => 'png',
			'jpeg' => 'jpg',
			'jpg'  => 'jpg',
			'webp' => 'webp',
		);

		$extension = isset( $extensions[ $format ] ) ? $extensions[ $format ] : 'png';

		// Use job_id for filename if available, otherwise use file_name or default.
		$job_id = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
		if ( ! empty( $job_id ) ) {
			$file_name = sprintf( 'cloudflare-image-%s.%s', $job_id, $extension );
		} else {
			$file_stem = $this->normalise_file_stem( $file_name );
			$file_name = sprintf( '%s-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $extension );
		}

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to save the generated image file.', 'mcp-ai-wpoos' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to write the generated image file to disk.', 'mcp-ai-wpoos' ) );
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
			$this->delete_file_safely( $file_path );

			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register the generated image as an attachment.', 'mcp-ai-wpoos' ), array( 'error' => $attachment_id ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Store Cloudflare response metadata for reference.
		$cloudflare_meta = array(
			'source'          => 'cloudflare',
			'original_prompt' => sanitize_textarea_field( $prompt ),
		);

		if ( ! empty( $image['model'] ) ) {
			$cloudflare_meta['model'] = sanitize_text_field( $image['model'] );
		}

		if ( isset( $image['width'] ) && $image['width'] > 0 ) {
			$cloudflare_meta['width'] = absint( $image['width'] );
		}

		if ( isset( $image['height'] ) && $image['height'] > 0 ) {
			$cloudflare_meta['height'] = absint( $image['height'] );
		}

		if ( isset( $image['num_steps'] ) && $image['num_steps'] > 0 ) {
			$cloudflare_meta['num_steps'] = absint( $image['num_steps'] );
		}

		if ( ! empty( $format ) ) {
			$cloudflare_meta['format'] = sanitize_key( $format );
		}

		// Store job_id if available - allows correlation between job IDs and files.
		if ( ! empty( $job_id ) ) {
			$cloudflare_meta['job_id'] = $job_id;
		}

		update_post_meta( $attachment_id, '_wp_mcp_ai_cloudflare_image_meta', $cloudflare_meta );

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
	 * Normalise a file stem used for generated attachments.
	 *
	 * @param string $file_name Raw file name input.
	 * @return string
	 */
	protected function normalise_file_stem( $file_name ) {
		$file_name = sanitize_file_name( (string) $file_name );

		if ( '' === $file_name ) {
			return 'cloudflare-image';
		}

		$info = pathinfo( $file_name );
		$stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
		$stem = sanitize_title( $stem );

		if ( '' === $stem ) {
			return 'cloudflare-image';
		}

		return $stem;
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
			return __( 'Cloudflare AI Image', 'mcp-ai-wpoos' );
		}

		$excerpt = wp_trim_words( $prompt, 12, '…' );

		/* translators: %s: Short excerpt of the prompt used to generate an image. */
		return sprintf( __( 'Cloudflare AI Image: %s', 'mcp-ai-wpoos' ), $excerpt );
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
	 * Convert a raster image to SVG format using vectorization.
	 *
	 * @param array $storage    Stored raster image data.
	 * @param array $arguments  Tool arguments.
	 * @return array|WP_Error SVG storage data or error.
	 */
	protected function convert_to_svg( array $storage, array $arguments ) {
		// Check if Node.js is available.
		if ( ! $this->is_nodejs_available() ) {
			return new WP_Error(
				'wp_mcp_ai_nodejs_required',
				__( 'Node.js is required for SVG vectorization but was not found on the system.', 'mcp-ai-wpoos' )
			);
		}

		$file_path = isset( $storage['file'] ) ? $storage['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Generated image file not found for SVG conversion.', 'mcp-ai-wpoos' )
			);
		}

		// Prepare SVG output file.
		$temp_output = wp_tempnam( 'cloudflare-svg-' );
		if ( ! $temp_output ) {
			return new WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to create temporary SVG output file.', 'mcp-ai-wpoos' ) );
		}

		// Add .svg extension.
		$temp_output_svg = $temp_output . '.svg';
		rename( $temp_output, $temp_output_svg );
		$temp_output = $temp_output_svg;

		// Prepare vectorization options.
		$vectorization_options = array(
			'colorMode'      => 'color',
			'colorPrecision' => isset( $arguments['color_precision'] ) ? absint( $arguments['color_precision'] ) : 6,
			'filterSpeckle'  => isset( $arguments['filter_speckle'] ) ? absint( $arguments['filter_speckle'] ) : 4,
			'mode'           => isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'spline',
			'hierarchical'   => isset( $arguments['hierarchical'] ) ? sanitize_text_field( $arguments['hierarchical'] ) : 'stacked',
		);

		// Execute vectorization script.
		$script_path = WP_MCP_AI_PATH . 'bin/vectorize-image.js';
		$script_args = array(
			$file_path,
			$temp_output,
			wp_json_encode( $vectorization_options ),
		);

		$vectorize_result = $this->execute_nodejs_script(
			$script_path,
			$script_args,
			array(
				'timeout'    => 60,
				'parse_json' => true,
			)
		);

		if ( is_wp_error( $vectorize_result ) ) {
			wp_delete_file( $temp_output );
			return $vectorize_result;
		}

		if ( ! isset( $vectorize_result['success'] ) || ! $vectorize_result['success'] ) {
			wp_delete_file( $temp_output );
			return new WP_Error(
				'wp_mcp_ai_vectorization_failed',
				isset( $vectorize_result['error'] ) ? $vectorize_result['error'] : __( 'SVG vectorization failed.', 'mcp-ai-wpoos' )
			);
		}

		// Read SVG file.
		$svg_data = file_get_contents( $temp_output );
		if ( false === $svg_data || '' === $svg_data ) {
			wp_delete_file( $temp_output );
			return new WP_Error( 'wp_mcp_ai_read_error', __( 'Failed to read vectorized SVG file.', 'mcp-ai-wpoos' ) );
		}

		// Cleanup temporary output file.
		wp_delete_file( $temp_output );

		// Save as WordPress attachment.
		$svg_storage = $this->save_svg_as_attachment( $svg_data, $arguments );
		if ( is_wp_error( $svg_storage ) ) {
			return $svg_storage;
		}

		// Add vectorization metadata.
		$svg_storage['vectorized']  = true;
		$svg_storage['svg_size']    = isset( $vectorize_result['output_size'] ) ? $vectorize_result['output_size'] : $svg_storage['bytes'];
		$svg_storage['source_size'] = isset( $vectorize_result['input_size'] ) ? $vectorize_result['input_size'] : $storage['bytes'];
		$svg_storage['duration_ms'] = isset( $vectorize_result['duration_ms'] ) ? $vectorize_result['duration_ms'] : 0;

		return $svg_storage;
	}

	/**
	 * Save SVG data as WordPress attachment.
	 *
	 * @param string $svg_data  SVG file content.
	 * @param array  $arguments Tool arguments for naming.
	 * @return array|WP_Error Attachment data or error.
	 */
	protected function save_svg_as_attachment( $svg_data, array $arguments ) {
		// Generate file name.
		$base_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : 'cloudflare-image';
		if ( empty( $base_name ) ) {
			$base_name = 'cloudflare-image';
		}

		// Remove extension if present.
		$base_name = preg_replace( '/\.(png|jpg|jpeg|gif|webp)$/i', '', $base_name );
		$file_name = $base_name . '-svg-' . gmdate( 'Ymd-His' ) . '.svg';

		// Upload SVG file.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $svg_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_upload_failed', __( 'Failed to save SVG file.', 'mcp-ai-wpoos' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_upload_failed', __( 'Failed to write SVG file to disk.', 'mcp-ai-wpoos' ) );
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => sanitize_text_field( __( 'Cloudflare AI SVG Image', 'mcp-ai-wpoos' ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register SVG as an attachment.', 'mcp-ai-wpoos' ), array( 'error' => $attachment_id ) );
		}

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		// Get attachment URL using utility class.
		$local_url = WP_MCP_AI_Media_URL_Utils::get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => wp_basename( $file_path ),
			'url'           => $local_url,
			'download_url'  => $local_url,
			'mime_type'     => 'image/svg+xml',
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => get_the_title( $attachment_id ),
		);
	}

	/**
	 * Sanitize image generation results for LLM consumption.
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with only metadata and image_url for vision.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Keep only essential metadata for LLM reasoning.
		$keep_fields = array(
			'attachment_id',
			'url',
			'download_url',
			'file_name',
			'mime_type',
			'bytes',
			'format',
			'width',
			'height',
			'num_steps',
			'guidance',
			'seed',
			'model',
			'provider',
			'text',
			'usage',
			'cost',
			'vectorized',
			'svg_size',
			'source_size',
			'duration_ms',
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add image_url structure for the agentic loop.
		// This allows vision models to "see" the generated image in subsequent iterations.
		// Prefer download_url (if available) over url for Cloudflare images.
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

			'toolkit'               => 'content_publishing',

			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),

			'profession_tags'       => array( 'graphic_designer', 'content_creator' ),

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // Requires Cloudflare API credentials.
			'requires-capability',  // Requires user capabilities.
			'write',                // Creates media files.
			'async',                // May take significant time to generate images.
			'rate-limited',         // Subject to Cloudflare rate limits.
			'requires-model',       // Requires image model specification.
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
				'providers' => array( 'cloudflare' ),
				'models'    => array(
					'@cf/stabilityai/stable-diffusion-xl-base-1.0',
					'@cf/bytedance/stable-diffusion-xl-lightning',
					'@cf/black-forest-labs/flux-1-schnell',
					'@cf/black-forest-labs/flux-2-dev',
					'@cf/leonardo/lucid-origin',
					'@cf/leonardo/phoenix-1.0',
					'@cf/lykon/dreamshaper-8-lcm',
				),
				'required'  => false,
			),
			'parameter_constraints' => array(
				'required_fields'   => array( 'prompt' ),
				'optional_fields'   => array( 'model', 'width', 'height', 'num_steps', 'guidance', 'seed', 'file_name', 'output_format', 'timeout' ),
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
					'api_token'  => 'cloudflare_api_token',
					'account_id' => 'cloudflare_account_id',
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
}
