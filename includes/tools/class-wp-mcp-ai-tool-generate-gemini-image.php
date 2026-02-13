<?php
/**
 * Tool that generates images using Gemini's multimodal endpoint.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-nodejs-subprocess.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-image-response.php';

/**
 * Provides a tool for generating images via Gemini and storing them as attachments.
 */
class WP_MCP_AI_Tool_Generate_Gemini_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Rules_Interface {
	use WP_MCP_AI_NodeJS_Subprocess;
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Image_Response;

	const DEFAULT_MODEL        = 'gemini-2.5-flash-image';
	const DEFAULT_MIME_TYPE    = 'image/png';
	const DEFAULT_ASPECT_RATIO = '4:3';

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
		return __( 'Generate Gemini Image', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates an image with Gemini and stores it in the Media Library. Supports multi-step orchestration mode with prompt optimization, validation, generation, post-processing, and storage optimization. Set orchestration_mode=true to enable 5-step workflow.', 'mcp-ai-wpoos' );
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
					'description' => __( 'The text prompt describing the desired image.', 'mcp-ai-wpoos' ),
				),
				'orchestration_mode' => array(
					'type'        => 'boolean',
					'description' => __( 'Enable multi-step orchestration workflow. When true, executes 5-step process: Optimize → Validate → Generate → Enhance → Store. Default: false (legacy mode).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'optimize_prompt'   => array(
					'type'        => 'boolean',
					'description' => __( 'Enhance and optimize the prompt using AI. Only applies when orchestration_mode is true.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'generate_alt_text' => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically generate alt text for the image using AI. Only applies when orchestration_mode is true.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'optimize_output'   => array(
					'type'        => 'boolean',
					'description' => __( 'Optimize output format and size for web delivery. Only applies when orchestration_mode is true.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'generate_variants' => array(
					'type'        => 'boolean',
					'description' => __( 'Generate multiple size variants for responsive images. Only applies when orchestration_mode is true.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'model'         => array(
					'type'        => 'string',
					'description' => __( 'Gemini image model to use.', 'mcp-ai-wpoos' ),
					'default'     => $defaults['model'],
				),
				'aspect_ratio'  => array(
					'type'        => 'string',
					'description' => __( 'Aspect ratio for the generated image.', 'mcp-ai-wpoos' ),
					'enum'        => $aspect_choices,
					'default'     => $defaults['aspect_ratio'],
				),
				'mime_type'     => array(
					'type'        => 'string',
					'description' => __( 'Preferred MIME type for the saved image.', 'mcp-ai-wpoos' ),
					'enum'        => $mime_choices,
					'default'     => $defaults['mime_type'],
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
					'description' => __( 'Override the Gemini request timeout in seconds.', 'mcp-ai-wpoos' ),
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
				'label'   => __( 'generate_gemini_image', 'mcp-ai-wpoos' ),
				'payload' => __( 'generate_gemini_image', 'mcp-ai-wpoos' ),
			),
			array(
				'label'   => __( 'Revise existing concept', 'mcp-ai-wpoos' ),
				'payload' => __( 'Use the `generate_gemini_image` tool to update an existing concept. Ask what should change, capture the current prompt for context, then propose an adjusted prompt reflecting the requested edits before running the tool.', 'mcp-ai-wpoos' ),
			),
			array(
				'label'   => __( 'Add product to lifestyle scene', 'mcp-ai-wpoos' ),
				'payload' => __( 'Use the `generate_gemini_image` tool to place the product in a lifestyle setting. Gather details about the environment, target audience, props, and camera angle, then assemble a detailed prompt that keeps the product as the hero of the scene.', 'mcp-ai-wpoos' ),
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

		// Check if orchestration mode is enabled.
		$orchestration_mode = isset( $arguments['orchestration_mode'] ) && $arguments['orchestration_mode'];

		if ( $orchestration_mode ) {
			return $this->execute_orchestrated( $arguments, $context, $user_id );
		}

		// Legacy execution path (maintain backward compatibility).
		return $this->execute_legacy( $arguments, $context, $user_id );
	}

	/**
	 * Legacy execution path without orchestration.
	 *
	 * Maintains backward compatibility with existing integrations.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Image data or error.
	 */
	protected function execute_legacy( array $arguments, array $context, int $user_id ) {

		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'No prompt was supplied for the image request.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
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
			return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Gemini returned an empty image response.', 'mcp-ai-wpoos' ) );
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
					'gemini_svg_conversion_failed',
					'Failed to convert Gemini-generated image to SVG',
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
			/* translators: 1: image title, 2: attachment ID */
			__( 'Successfully generated image "%1$s" (ID: %2$d).', 'mcp-ai-wpoos' ),
			$storage['title'],
			$storage['attachment_id']
		);

		if ( ! empty( $image['revised_prompt'] ) ) {
			$text_parts[] = sprintf(
				/* translators: %s: revised prompt from Gemini */
				__( 'Description: %s', 'mcp-ai-wpoos' ),
				$image['revised_prompt']
			);
		}

		$text_parts[] = sprintf(
			/* translators: 1: aspect ratio, 2: format */
			__( 'Format: %1$s, %2$s', 'mcp-ai-wpoos' ),
			isset( $image['aspect_ratio'] ) ? $image['aspect_ratio'] : $aspect_ratio,
			strtoupper( isset( $image['format'] ) ? $image['format'] : $this->map_mime_type_to_format( $storage['mime_type'] ) )
		);

		$text    = implode( ' ', $text_parts );
		$message = $text;

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
			'text'           => $text, // Descriptive message for LLM and chat UI.
			'message'        => $message,
		);

		// Include usage metadata if available for accurate cost tracking.
		if ( isset( $image['usage'] ) && is_array( $image['usage'] ) ) {
			$result['usage'] = $image['usage'];
		}

		// Note: Inline content payload (base64 encoded image data) is intentionally NOT included
		// in the default response to prevent bloating tool results sent to chat clients and LLMs.
		// If base64 content is needed, it should be retrieved via a separate endpoint or parameter.

		/**
		 * Allow third parties to filter the Gemini image generation result before it is returned.
		 *
		 * @param array $result    Result array to be returned.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_gemini_image_result', $result, $arguments, $context );

		// Add rendered image HTML to the response for display in chat UI.
		$result = $this->add_image_html_to_response( $result );

		return $result;
	}

	/**
	 * Execute image generation with multi-step orchestration.
	 *
	 * Implements a 5-step workflow:
	 * 1. Prompt Optimization (optional AI enhancement)
	 * 2. Parameter Validation (aspect ratio, mime type, model compatibility)
	 * 3. Image Generation (with error handling)
	 * 4. Post-Processing (format optimization, alt text)
	 * 5. Storage & Optimization (Media Library, metadata)
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Image data or error.
	 */
	protected function execute_orchestrated( array $arguments, array $context, int $user_id ) {
		// Generate unique execution ID for tracking.
		$execution_id = 'gemini_image_' . wp_generate_uuid4();
		
		$this->log_orchestration_step( $execution_id, 'started', array(
			'user_id' => $user_id,
			'model'   => $arguments['model'] ?? 'default',
		) );

		// Step 1: Prompt Optimization (optional).
		if ( ! empty( $arguments['optimize_prompt'] ) && $arguments['optimize_prompt'] ) {
			$this->log_orchestration_step( $execution_id, 'optimize', 'Starting prompt optimization' );
			$optimized_prompt = $this->step_optimize_prompt( $arguments, $context );
			
			if ( is_wp_error( $optimized_prompt ) ) {
				$this->log_orchestration_step( $execution_id, 'optimize_failed', $optimized_prompt->get_error_message() );
				// Non-critical: Continue with original prompt.
			} else {
				$arguments['prompt'] = $optimized_prompt;
				$this->log_orchestration_step( $execution_id, 'optimize_completed', 'Prompt optimized' );
			}
		}

		// Step 2: Parameter Validation.
		$this->log_orchestration_step( $execution_id, 'validate', 'Validating parameters' );
		$validation_result = $this->step_validate_parameters( $arguments );
		
		if ( is_wp_error( $validation_result ) ) {
			$this->log_orchestration_step( $execution_id, 'validation_failed', $validation_result->get_error_message() );
			return $this->handle_orchestration_error( 'validate', $validation_result, $execution_id );
		}
		
		$this->log_orchestration_step( $execution_id, 'validation_completed', 'Parameters validated' );

		// Step 3: Image Generation.
		$this->log_orchestration_step( $execution_id, 'generate', 'Generating image' );
		$image_data = $this->execute_legacy( $arguments, $context, $user_id );
		
		if ( is_wp_error( $image_data ) ) {
			$this->log_orchestration_step( $execution_id, 'generation_failed', $image_data->get_error_message() );
			return $this->handle_orchestration_error( 'generate', $image_data, $execution_id );
		}
		
		$attachment_id = $image_data['attachment_id'];
		$this->log_orchestration_step( $execution_id, 'generation_completed', array( 'attachment_id' => $attachment_id ) );

		// Step 4: Post-Processing.
		if ( ! empty( $arguments['generate_alt_text'] ) && $arguments['generate_alt_text'] ) {
			$this->log_orchestration_step( $execution_id, 'post_process', 'Generating alt text' );
			$alt_text_result = $this->step_generate_alt_text( $attachment_id, $arguments, $context );
			
			if ( ! is_wp_error( $alt_text_result ) ) {
				$image_data['alt_text'] = $alt_text_result;
				$this->log_orchestration_step( $execution_id, 'alt_text_completed', 'Alt text generated' );
			} else {
				$this->log_orchestration_step( $execution_id, 'alt_text_skipped', $alt_text_result->get_error_message() );
			}
		}

		// Step 5: Optimization & Storage.
		if ( ! empty( $arguments['optimize_output'] ) && $arguments['optimize_output'] ) {
			$this->log_orchestration_step( $execution_id, 'optimize_storage', 'Optimizing storage' );
			$optimization_result = $this->step_optimize_storage( $attachment_id, $arguments, $context );
			
			if ( ! is_wp_error( $optimization_result ) ) {
				$image_data = array_merge( $image_data, $optimization_result );
				$this->log_orchestration_step( $execution_id, 'storage_optimization_completed', 'Storage optimized' );
			} else {
				$this->log_orchestration_step( $execution_id, 'storage_optimization_skipped', $optimization_result->get_error_message() );
			}
		}

		if ( ! empty( $arguments['generate_variants'] ) && $arguments['generate_variants'] ) {
			$this->log_orchestration_step( $execution_id, 'generate_variants', 'Generating size variants' );
			$variants_result = $this->step_generate_variants( $attachment_id, $arguments );
			
			if ( ! is_wp_error( $variants_result ) ) {
				$image_data['variants'] = $variants_result;
				$this->log_orchestration_step( $execution_id, 'variants_completed', 'Variants generated' );
			} else {
				$this->log_orchestration_step( $execution_id, 'variants_skipped', $variants_result->get_error_message() );
			}
		}

		$this->log_orchestration_step( $execution_id, 'completed', 'Image generation workflow completed' );

		// Add orchestration metadata to response.
		$image_data['execution_id'] = $execution_id;
		$image_data['orchestration'] = array(
			'enabled' => true,
			'steps'   => $this->get_orchestration_steps( $execution_id ),
		);

		return $image_data;
	}

	/**
	 * Step 1: Optimize prompt using AI.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return string|WP_Error Optimized prompt or error.
	 */
	protected function step_optimize_prompt( $arguments, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Streaming' ) ) {
			return new WP_Error( 'ai_unavailable', 'AI streaming not available' );
		}

		$original_prompt = isset( $arguments['prompt'] ) ? $arguments['prompt'] : '';
		
		if ( empty( $original_prompt ) ) {
			return new WP_Error( 'empty_prompt', 'Cannot optimize empty prompt' );
		}

		$ai_client = WP_MCP_AI_Streaming::get_instance();
		$enhance_prompt = sprintf(
			'Improve this image generation prompt for better results. Make it more specific, vivid, and detailed while maintaining the core intent. Original prompt: "%s". Return ONLY the improved prompt, no explanation.',
			sanitize_text_field( $original_prompt )
		);

		$response = $ai_client->send_message( $enhance_prompt );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$optimized = isset( $response['content'] ) ? trim( $response['content'] ) : '';
		
		if ( empty( $optimized ) ) {
			return new WP_Error( 'optimization_failed', 'AI returned empty optimized prompt' );
		}

		return sanitize_textarea_field( $optimized );
	}

	/**
	 * Step 2: Validate parameters.
	 *
	 * @param array $arguments Tool arguments.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	protected function step_validate_parameters( $arguments ) {
		$errors = array();

		// Validate prompt.
		$prompt = isset( $arguments['prompt'] ) ? trim( $arguments['prompt'] ) : '';
		if ( empty( $prompt ) ) {
			$errors[] = __( 'Prompt is required', 'mcp-ai-wpoos' );
		}

		// Validate prompt length.
		if ( ! empty( $prompt ) && strlen( $prompt ) > 4000 ) {
			$errors[] = __( 'Prompt must be 4000 characters or less', 'mcp-ai-wpoos' );
		}

		if ( ! empty( $prompt ) && strlen( $prompt ) < 3 ) {
			$errors[] = __( 'Prompt must be at least 3 characters', 'mcp-ai-wpoos' );
		}

		// Validate aspect ratio.
		if ( ! empty( $arguments['aspect_ratio'] ) ) {
			$aspect_ratio = $arguments['aspect_ratio'];
			$allowed_ratios = array_keys( $this->get_allowed_aspect_ratios() );
			if ( ! in_array( $aspect_ratio, $allowed_ratios, true ) ) {
				$errors[] = sprintf(
					/* translators: %s: aspect ratio value */
					__( 'Invalid aspect ratio: %s', 'mcp-ai-wpoos' ),
					$aspect_ratio
				);
			}
		}

		// Validate MIME type.
		if ( ! empty( $arguments['mime_type'] ) ) {
			$mime_type = $arguments['mime_type'];
			$allowed_mimes = array_keys( $this->get_allowed_mime_types() );
			if ( ! in_array( $mime_type, $allowed_mimes, true ) ) {
				$errors[] = sprintf(
					/* translators: %s: MIME type value */
					__( 'Invalid MIME type: %s', 'mcp-ai-wpoos' ),
					$mime_type
				);
			}
		}

		// Validate model.
		if ( ! empty( $arguments['model'] ) ) {
			$model = $arguments['model'];
			if ( ! is_string( $model ) || empty( $model ) ) {
				$errors[] = __( 'Invalid model specified', 'mcp-ai-wpoos' );
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'parameter_validation_failed',
				__( 'Image generation parameter validation failed', 'mcp-ai-wpoos' ),
				array( 'errors' => $errors )
			);
		}

		return true;
	}

	/**
	 * Step 4: Generate alt text for image.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $arguments     Tool arguments.
	 * @param array $context       Execution context.
	 * @return string|WP_Error Alt text or error.
	 */
	protected function step_generate_alt_text( $attachment_id, $arguments, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return new WP_Error( 'tool_registry_unavailable', 'Tool registry not available' );
		}

		$alt_text_tool = WP_MCP_AI_Tool_Registry::get_tool( 'generate_image_alt_text' );
		
		if ( ! $alt_text_tool ) {
			return new WP_Error( 'alt_text_tool_unavailable', 'Alt text generation tool not available' );
		}

		$image_url = wp_get_attachment_url( $attachment_id );
		
		if ( ! $image_url ) {
			return new WP_Error( 'image_url_not_found', 'Could not get image URL' );
		}

		$result = $alt_text_tool->execute(
			array( 'image_url' => $image_url ),
			$context
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$alt_text = isset( $result['alt_text'] ) ? $result['alt_text'] : '';
		
		if ( empty( $alt_text ) ) {
			return new WP_Error( 'empty_alt_text', 'Alt text generation returned empty result' );
		}

		// Update attachment alt text.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );

		return $alt_text;
	}

	/**
	 * Step 5: Optimize storage.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $arguments     Tool arguments.
	 * @param array $context       Execution context.
	 * @return array|WP_Error Optimization results or error.
	 */
	protected function step_optimize_storage( $attachment_id, $arguments, $context ) {
		$optimization_results = array();

		// Add descriptive title based on prompt.
		if ( ! empty( $arguments['prompt'] ) ) {
			$title = wp_trim_words( $arguments['prompt'], 10 );
			wp_update_post( array(
				'ID'         => $attachment_id,
				'post_title' => sanitize_text_field( $title ),
			) );
			$optimization_results['title_updated'] = true;
		}

		// Add metadata.
		$metadata = array(
			'generation_model'  => $arguments['model'] ?? 'unknown',
			'generation_prompt' => $arguments['prompt'] ?? '',
			'orchestration'     => true,
			'provider'          => 'gemini',
		);
		
		foreach ( $metadata as $key => $value ) {
			update_post_meta( $attachment_id, '_ai_' . $key, $value );
		}
		
		$optimization_results['metadata_added'] = true;

		return $optimization_results;
	}

	/**
	 * Step 5: Generate size variants.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $arguments     Tool arguments.
	 * @return array|WP_Error Variant data or error.
	 */
	protected function step_generate_variants( $attachment_id, $arguments ) {
		$variants = array();

		// Get the attachment file path.
		$file_path = get_attached_file( $attachment_id );
		
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', 'Attachment file not found' );
		}

		// Generate WordPress default image sizes.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				$variants[ $size_name ] = array(
					'file'   => $size_data['file'],
					'width'  => $size_data['width'],
					'height' => $size_data['height'],
				);
			}
		}

		return $variants;
	}

	/**
	 * Log orchestration step.
	 *
	 * @param string $execution_id Execution ID.
	 * @param string $step         Step name.
	 * @param mixed  $data         Step data.
	 */
	protected function log_orchestration_step( $execution_id, $step, $data ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'[WP_MCP_AI] [%s] Step: %s | Data: %s',
					$execution_id,
					$step,
					is_string( $data ) ? $data : wp_json_encode( $data )
				)
			);
		}

		// Store steps in transient for retrieval.
		$steps = get_transient( "wp_mcp_ai_gemini_exec_{$execution_id}" ) ?: array();
		$steps[] = array(
			'step' => $step,
			'time' => current_time( 'mysql' ),
			'data' => $data,
		);
		set_transient( "wp_mcp_ai_gemini_exec_{$execution_id}", $steps, HOUR_IN_SECONDS );
	}

	/**
	 * Get orchestration steps summary.
	 *
	 * @param string $execution_id Execution ID.
	 * @return array Steps summary.
	 */
	protected function get_orchestration_steps( $execution_id ) {
		$steps = get_transient( "wp_mcp_ai_gemini_exec_{$execution_id}" ) ?: array();
		
		return array_map(
			function( $step ) {
				return array(
					'name' => $step['step'],
					'time' => $step['time'],
				);
			},
			$steps
		);
	}

	/**
	 * Handle orchestration error.
	 *
	 * @param string   $step_name Step that failed.
	 * @param WP_Error $error     Error object.
	 * @param string   $execution_id Execution ID.
	 * @return WP_Error Enhanced error.
	 */
	protected function handle_orchestration_error( $step_name, $error, $execution_id ) {
		do_action( 'wp_mcp_ai_gemini_orchestration_failed', $step_name, $error, $execution_id );
		
		return new WP_Error(
			'orchestration_failed',
			sprintf(
				/* translators: 1: step name, 2: error message */
				__( 'Gemini image generation orchestration failed at step: %1$s. %2$s', 'mcp-ai-wpoos' ),
				$step_name,
				$error->get_error_message()
			),
			array(
				'step'          => $step_name,
				'original_code' => $error->get_error_code(),
				'original_data' => $error->get_error_data(),
				'execution_id'  => $execution_id,
			)
		);
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
	 * @param array  $context   Optional. Execution context containing parent_job_id.
	 * @return array|WP_Error
	 */
	protected function store_image_attachment( array $image, $file_name, $prompt, $user_id, array $context = array() ) {
		$data      = isset( $image['image'] ) ? $image['image'] : '';
		$mime_type = isset( $image['mime_type'] ) ? $this->normalise_mime_type( $image['mime_type'] ) : self::DEFAULT_MIME_TYPE;
		$mimes     = $this->get_allowed_mime_types();

		if ( '' === $data || '' === $mime_type || ! isset( $mimes[ $mime_type ] ) ) {
			return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Unable to determine the image format for storage.', 'mcp-ai-wpoos' ) );
		}

		// Use job_id for filename if available, otherwise use file_name or default.
		$job_id = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
		if ( ! empty( $job_id ) ) {
			$file_name = sprintf( 'gemini-image-%s.%s', $job_id, $mimes[ $mime_type ]['extension'] );
		} else {
			$file_stem = $this->normalise_file_stem( $file_name );
			$file_name = sprintf( '%s-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $mimes[ $mime_type ]['extension'] );
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

			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register the generated image as an attachment.', 'mcp-ai-wpoos' ), array( 'error' => $attachment_id ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Store job_id if available - allows correlation between job IDs and files.
		if ( ! empty( $job_id ) ) {
			update_post_meta( $attachment_id, '_gemini_image_job_id', $job_id );
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
			'mime_type'     => $resolved_mime,
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => $title,
		);
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
			'auto' => __( 'Auto (Let AI decide)', 'mcp-ai-wpoos' ),
			'1:1'  => __( 'Square (1:1)', 'mcp-ai-wpoos' ),
			'3:4'  => __( 'Portrait (3:4)', 'mcp-ai-wpoos' ),
			'4:3'  => __( 'Landscape (4:3)', 'mcp-ai-wpoos' ),
			'9:16' => __( 'Vertical (9:16)', 'mcp-ai-wpoos' ),
			'16:9' => __( 'Widescreen (16:9)', 'mcp-ai-wpoos' ),
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
				'label'     => __( 'PNG', 'mcp-ai-wpoos' ),
				'extension' => 'png',
			),
			'image/jpeg' => array(
				'label'     => __( 'JPEG', 'mcp-ai-wpoos' ),
				'extension' => 'jpg',
			),
			'image/webp' => array(
				'label'     => __( 'WebP', 'mcp-ai-wpoos' ),
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
		$aspect_ratio = strtolower( (string) $aspect_ratio );
		$aspect_ratio = str_replace( ' ', '', $aspect_ratio );

		// Special case: "auto" means let the AI decide (no aspectRatio sent to API).
		if ( 'auto' === $aspect_ratio ) {
			return 'auto';
		}

		$aspect_ratio = strtoupper( $aspect_ratio );

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
			return __( 'Gemini Image', 'mcp-ai-wpoos' );
		}

		$excerpt = wp_trim_words( $prompt, 12, '…' );

		/* translators: %s: Short excerpt of the prompt used to generate an image. */
		return sprintf( __( 'Gemini Image: %s', 'mcp-ai-wpoos' ), $excerpt );
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

			'profession_tags'       => array( 'graphic_designer', 'content_creator', 'marketing_manager' ),

			'risk_level'            => 'standard',

		);
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
			'cost',          // Cost data for UI display.
			'text',          // Descriptive message about the generated image.
			'vectorized',    // SVG metadata if present.
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
		$temp_output = wp_tempnam( 'gemini-svg-' );
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
		$base_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : 'gemini-image';
		if ( empty( $base_name ) ) {
			$base_name = 'gemini-image';
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
			'post_title'     => sanitize_text_field( __( 'Gemini SVG Image', 'mcp-ai-wpoos' ) ),
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
}
