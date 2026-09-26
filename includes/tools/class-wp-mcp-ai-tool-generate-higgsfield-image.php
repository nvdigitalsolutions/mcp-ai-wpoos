<?php
/**
 * Tool that generates images using the Higgsfield image catalog.
 *
 * Higgsfield (https://docs.higgsfield.ai) exposes its image models through
 * the same authenticated, asynchronous API as its video catalog. This tool
 * wraps the verified image workflows:
 *
 *  - soul-2       — POST /higgsfield-ai/soul/v2/standard
 *                   (portraits, fashion, editorial; optional style_id)
 *  - soul-cinema  — POST /higgsfield-ai/soul/cinema
 *                   (cinema-inspired stills; fixed cinematic style)
 *
 * Both accept a batch_size of 1 or 4; every output is downloaded and saved
 * to the Media Library immediately (provider retention is ~7 days).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-higgsfield-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-async-metadata.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Provides a tool for generating images via the Higgsfield API and storing them as attachments.
 */
class WP_MCP_AI_Tool_Generate_Higgsfield_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Async_Metadata_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	const DEFAULT_MODEL = 'soul-2';

	/**
	 * Verified image-model catalog. Maps a model ID to its endpoint and
	 * constraints. Only documented, verified endpoints are listed.
	 */
	const IMAGE_MODELS = array(
		'soul-2'      => array(
			'endpoint'    => '/higgsfield-ai/soul/v2/standard',
			'resolutions' => array( '720p', '1080p' ),
			'has_style'   => true,
		),
		'soul-cinema' => array(
			'endpoint'    => '/higgsfield-ai/soul/cinema',
			'resolutions' => array( '720p', '1080p' ),
			'has_style'   => false,
		),
	);

	/**
	 * Aspect ratios shared by the verified SOUL workflows.
	 */
	const ALLOWED_ASPECT_RATIOS = array( '9:16', '16:9', '4:3', '3:4', '1:1', '2:3', '3:2' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_higgsfield_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Higgsfield Image', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates images with the Higgsfield image catalog and stores them in the Media Library. Two verified workflows: soul-2 (portraits, fashion, editorial — optional style_id) and soul-cinema (cinema-inspired stills). Supports 1 or 4 outputs per request, 720p/1080p, seven aspect ratios, prompt enhancement, and reproducible seeds. Generation is asynchronous on the provider side; the tool polls with backoff, then downloads every output (provider retention is ~7 days).', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Creating styled character, fashion, editorial, or cinematic stills via the Higgsfield SOUL workflows.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Video generation; use generate_higgsfield_video. Polling or canceling a submitted request; use check_higgsfield_request / cancel_higgsfield_request. Other image providers; use generate_gemini_image or generate_openai_image.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'generate_higgsfield_video', 'check_higgsfield_request', 'cancel_higgsfield_request', 'generate_gemini_image', 'generate_openai_image' ),
			'notes'           => __( 'Needs a Higgsfield API key pair (Key ID + Secret). batch_size is 1 or 4 only. Billing is a flat rate per image from a prepaid balance.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'         => array(
					'type'        => 'string',
					'description' => __( 'The text prompt describing the desired image. Be specific about subject, framing, lighting, and mood.', 'mcp-ai-wpoos' ),
				),
				'model'          => array(
					'type'        => 'string',
					'description' => __( 'Higgsfield image workflow: "soul-2" (portraits, fashion, editorial; optional style_id) or "soul-cinema" (cinema-inspired stills; fixed cinematic style).', 'mcp-ai-wpoos' ),
					'enum'        => array_keys( self::IMAGE_MODELS ),
					'default'     => self::DEFAULT_MODEL,
				),
				'aspect_ratio'   => array(
					'type'        => 'string',
					'description' => __( 'Output width-to-height ratio.', 'mcp-ai-wpoos' ),
					'enum'        => self::ALLOWED_ASPECT_RATIOS,
					'default'     => '1:1',
				),
				'resolution'     => array(
					'type'        => 'string',
					'description' => __( 'Output resolution tier: "720p" (default) or "1080p".', 'mcp-ai-wpoos' ),
					'enum'        => array( '720p', '1080p' ),
					'default'     => '720p',
				),
				'enhance_prompt' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to let Higgsfield enhance the prompt before generation. Default is false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'seed'           => array(
					'type'        => 'integer',
					'description' => __( 'Random seed for reproducible results (1-1000000). Omit for a random seed.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 1000000,
				),
				'batch_size'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of output images: 1 or 4.', 'mcp-ai-wpoos' ),
					'enum'        => array( 1, 4 ),
					'default'     => 1,
				),
				'style_id'       => array(
					'type'        => 'string',
					'description' => __( 'Optional SOUL style identifier (UUID) for the soul-2 workflow. Ignored by soul-cinema. Leave empty for the default style.', 'mcp-ai-wpoos' ),
				),
				'save_to_media'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to save the generated images to the WordPress Media Library. Default is true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'async'          => array(
					'type'        => 'boolean',
					'description' => __( 'Run generation as a background job and return a job_id immediately. Default is true for reliability.', 'mcp-ai-wpoos' ),
				),
				'timeout'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum time in seconds to wait for generation before returning a timeout (60-600). On timeout the request_id is returned; poll it with check_higgsfield_request.', 'mcp-ai-wpoos' ),
					'minimum'     => 60,
					'maximum'     => 600,
					'default'     => 300,
				),
			),
			'required'             => array( 'prompt' ),
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

		// Check user capabilities.
		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate images.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Validate prompt.
		if ( empty( $arguments['prompt'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Image generation requires a prompt.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Check if async mode should be used.
		$use_async = $this->should_use_async( $arguments, $context );

		// If async mode, queue the job and return immediately.
		if ( $use_async ) {
			$queue_result = $this->queue_async_job( $arguments, $context );

			// If queueing failed (e.g., executor unavailable), fall back to sync.
			if ( is_wp_error( $queue_result ) && 'wp_mcp_ai_executor_unavailable' === $queue_result->get_error_code() ) {
				return $this->generate_image_sync( $arguments, $context );
			}

			return $queue_result;
		}

		// Execute synchronously.
		return $this->generate_image_sync( $arguments, $context );
	}

	/**
	 * Determine if async mode should be used.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return bool True if async mode should be used.
	 */
	protected function should_use_async( $arguments, $context = array() ) {
		// CRITICAL: If already running in async executor context, do NOT use tool-level async.
		// This prevents double-async execution.
		if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
			return false;
		}

		// Check if explicitly set in arguments.
		if ( isset( $arguments['async'] ) ) {
			return (bool) $arguments['async'];
		}

		// Default to async for reliability (generation can exceed HTTP timeouts).
		return true;
	}

	/**
	 * Queue an async job via the tool async executor.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Async job info with job_id and status, or error.
	 */
	protected function queue_async_job( $arguments, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			return new WP_Error(
				'wp_mcp_ai_executor_unavailable',
				__( 'The async tool executor is unavailable.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$job_id   = $executor->queue_tool( $this->get_slug(), $arguments, $context );

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		WP_MCP_AI_Logger::log_event(
			'higgsfield_image_queued',
			'Higgsfield image generation queued',
			array(
				'job_id' => $job_id,
				'prompt' => substr( $arguments['prompt'], 0, 100 ),
			)
		);

		$expected_metadata = $this->get_async_pending_metadata( $job_id, $arguments, $context );

		return array(
			'success'           => true,
			'async'             => true,
			'status'            => 'pending',
			'job_id'            => $job_id,
			'message'           => $expected_metadata['message'],
			'expected_url'      => $expected_metadata['expected_url'],
			'expected_filename' => $expected_metadata['expected_filename'],
		);
	}

	/**
	 * Build the request payload from canonical arguments.
	 *
	 * The Higgsfield endpoints reject unknown fields, so every payload key is
	 * gated by the selected model's definition.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Payload (endpoint, model, prompt, payload) or error.
	 */
	protected function build_request_payload( $arguments ) {
		// Gate 1 — sanitize all inputs at entry.
		$prompt = sanitize_textarea_field( $arguments['prompt'] );

		// Note: model IDs are simple slugs here, but keep sanitize_text_field for
		// parity with the video tool (dots and hyphens preserved).
		$model      = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : self::DEFAULT_MODEL;
		$definition = isset( self::IMAGE_MODELS[ $model ] ) ? self::IMAGE_MODELS[ $model ] : self::IMAGE_MODELS[ self::DEFAULT_MODEL ];
		$model      = isset( self::IMAGE_MODELS[ $model ] ) ? $model : self::DEFAULT_MODEL;

		$aspect_ratio = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '1:1';
		if ( ! in_array( $aspect_ratio, self::ALLOWED_ASPECT_RATIOS, true ) ) {
			$aspect_ratio = '1:1';
		}

		$resolution = isset( $arguments['resolution'] ) ? sanitize_text_field( $arguments['resolution'] ) : '720p';
		if ( ! in_array( $resolution, $definition['resolutions'], true ) ) {
			$resolution = '720p';
		}

		$enhance_prompt = isset( $arguments['enhance_prompt'] ) ? (bool) $arguments['enhance_prompt'] : false;

		$batch_size = isset( $arguments['batch_size'] ) ? absint( $arguments['batch_size'] ) : 1;
		$batch_size = in_array( $batch_size, array( 1, 4 ), true ) ? $batch_size : 1;

		$payload = array(
			'prompt'         => $prompt,
			'aspect_ratio'   => $aspect_ratio,
			'resolution'     => $resolution,
			'enhance_prompt' => $enhance_prompt,
			'batch_size'     => $batch_size,
		);

		if ( isset( $arguments['seed'] ) ) {
			$seed = absint( $arguments['seed'] );
			if ( $seed >= 1 && $seed <= 1000000 ) {
				$payload['seed'] = $seed;
			}
		}

		// style_id is only meaningful for the soul-2 workflow.
		if ( ! empty( $definition['has_style'] ) && ! empty( $arguments['style_id'] ) ) {
			$payload['style_id'] = sanitize_text_field( $arguments['style_id'] );
		}

		return array(
			'endpoint' => $definition['endpoint'],
			'model'    => $model,
			'prompt'   => $prompt,
			'payload'  => $payload,
		);
	}

	/**
	 * Generate image synchronously.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Generation result or error.
	 */
	protected function generate_image_sync( $arguments, $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$request = $this->build_request_payload( $arguments );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$timeout = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 300;
		$timeout = max( 60, min( 600, $timeout ) );

		$client = new WP_MCP_AI_Higgsfield_Client();

		WP_MCP_AI_Logger::log_event(
			'higgsfield_image_request',
			'Sending Higgsfield image generation request',
			array(
				'model'      => $request['model'],
				'batch_size' => $request['payload']['batch_size'],
			)
		);

		// Submit the generation request.
		$handle = $client->submit_request( $request['endpoint'], $request['payload'] );

		if ( is_wp_error( $handle ) ) {
			WP_MCP_AI_Logger::log_error(
				'Higgsfield image generation request failed',
				array(
					'model' => $request['model'],
					'error' => $handle->get_error_message(),
				)
			);
			return $handle;
		}

		$request_id = $handle['request_id'];

		// Poll until a terminal state (backoff + jitter inside the client).
		$outputs = $client->wait_for_completion( $request_id, $timeout );

		if ( is_wp_error( $outputs ) ) {
			WP_MCP_AI_Logger::log_error(
				'Higgsfield image generation did not complete',
				array(
					'model'      => $request['model'],
					'request_id' => $request_id,
					'error'      => $outputs->get_error_message(),
				)
			);
			return $outputs;
		}

		if ( empty( $outputs['image_urls'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_missing_output',
				__( 'The request completed but no image output was returned.', 'mcp-ai-wpoos' ),
				array(
					'status'     => 500,
					'request_id' => $request_id,
				)
			);
		}

		// Calculate cost (Higgsfield bills a flat rate per image).
		$cost = $this->calculate_image_cost( count( $outputs['image_urls'] ), $request['model'] );

		$save_to_media = isset( $arguments['save_to_media'] ) ? (bool) $arguments['save_to_media'] : true;

		if ( $save_to_media ) {
			$job_id = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';

			$attachment_ids = array();
			$urls           = array();

			foreach ( $outputs['image_urls'] as $index => $image_url ) {
				$image_data = $client->download_file( $image_url, $timeout );

				if ( is_wp_error( $image_data ) ) {
					WP_MCP_AI_Logger::log_error(
						'Higgsfield image download failed',
						array(
							'request_id' => $request_id,
							'index'      => $index,
							'error'      => $image_data->get_error_message(),
						)
					);
					return $image_data;
				}

				$save_result = $this->save_image_to_media( $image_data, $image_url, $request['prompt'], $request['model'], $user_id, $job_id, $request_id, $index );

				if ( is_wp_error( $save_result ) ) {
					return $save_result;
				}

				$attachment_ids[] = $save_result['attachment_id'];
				$urls[]           = $save_result['url'];
			}

			$text_parts   = array();
			$text_parts[] = sprintf(
				/* translators: 1: number of images, 2: first attachment ID */
				__( 'Successfully generated %1$d image(s) (first ID: %2$d).', 'mcp-ai-wpoos' ),
				count( $attachment_ids ),
				$attachment_ids[0]
			);
			$text_parts[] = sprintf(
				/* translators: %s: model */
				__( 'Model: %s.', 'mcp-ai-wpoos' ),
				$request['model']
			);

			$final_result = array(
				'success'        => true,
				'attachment_ids' => $attachment_ids,
				'attachment_id'  => $attachment_ids[0],
				'url'            => $urls[0],
				'urls'           => $urls,
				'prompt'         => $request['prompt'],
				'model'          => $request['model'],
				'provider'       => 'higgsfield',
				'request_id'     => $request_id,
				'count'          => count( $attachment_ids ),
				'cost'           => $cost,
				'message'        => sprintf(
					/* translators: %d: number of images saved */
					__( 'Generated %d image(s) and saved them to the Media Library.', 'mcp-ai-wpoos' ),
					count( $attachment_ids )
				),
				'text'           => implode( ' ', $text_parts ),
			);

			// Fire completion action.
			if ( ! empty( $context['agentic_loop'] ) ) {
				/**
				 * Fires when a Higgsfield image generation completes successfully in chat context.
				 *
				 * @param array $final_result Image generation result with attachment info.
				 * @param array $arguments    Original generation arguments.
				 * @param array $context      Execution context.
				 */
				do_action( 'wp_mcp_ai_higgsfield_image_completed', $final_result, $arguments, $context );
			}

			return $final_result;
		}

		// Return provider URLs without saving to Media Library.
		$final_result = array(
			'success'    => true,
			'urls'       => $outputs['image_urls'],
			'prompt'     => $request['prompt'],
			'model'      => $request['model'],
			'provider'   => 'higgsfield',
			'request_id' => $request_id,
			'count'      => count( $outputs['image_urls'] ),
			'cost'       => $cost,
			'message'    => __( 'Images generated successfully (temporary - not saved to Media Library; provider retention is ~7 days).', 'mcp-ai-wpoos' ),
			'text'       => sprintf(
				/* translators: %d: number of images */
				__( 'Successfully generated %d temporary image(s).', 'mcp-ai-wpoos' ),
				count( $outputs['image_urls'] )
			),
		);

		// Fire completion action.
		if ( ! empty( $context['agentic_loop'] ) ) {
			/**
			 * Fires when a Higgsfield image generation completes successfully in chat context.
			 *
			 * @param array $final_result Image generation result with provider URLs.
			 * @param array $arguments    Original generation arguments.
			 * @param array $context      Execution context.
			 */
			do_action( 'wp_mcp_ai_higgsfield_image_completed', $final_result, $arguments, $context );
		}

		return $final_result;
	}

	/**
	 * Save a generated image to the Media Library.
	 *
	 * @param string $image_data Image binary data.
	 * @param string $image_url  Provider URL (used to infer the extension).
	 * @param string $prompt     Generation prompt.
	 * @param string $model      Model used.
	 * @param int    $user_id    User ID for ownership.
	 * @param string $job_id     Optional job ID for tracking.
	 * @param string $request_id Optional Higgsfield request ID.
	 * @param int    $index      Zero-based output index within the request.
	 * @return array|WP_Error Attachment result array or error.
	 */
	protected function save_image_to_media( $image_data, $image_url, $prompt, $model, $user_id, $job_id = '', $request_id = '', $index = 0 ) {
		// Infer the file extension from the provider URL, defaulting to jpg.
		$path = wp_parse_url( $image_url, PHP_URL_PATH );
		$ext  = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
		$ext  = in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ? $ext : 'jpg';

		if ( ! empty( $job_id ) ) {
			$filename = 'higgsfield-image-' . sanitize_file_name( $job_id ) . '-' . ( $index + 1 ) . '.' . $ext;
		} elseif ( ! empty( $request_id ) ) {
			$filename = 'higgsfield-image-' . sanitize_file_name( $request_id ) . '-' . ( $index + 1 ) . '.' . $ext;
		} else {
			$filename = 'higgsfield-image-' . wp_generate_password( 12, false ) . '.' . $ext;
		}

		// Include WordPress file functions if not already loaded.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Upload image.
		$upload = wp_upload_bits( $filename, null, $image_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				$upload['error'],
				array( 'status' => 500 )
			);
		}

		$mime_type = wp_check_filetype( $filename );
		$mime_type = ! empty( $mime_type['type'] ) ? $mime_type['type'] : 'image/jpeg';

		// Create attachment.
		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => sprintf(
				/* translators: 1: model, 2: truncated prompt */
				__( 'Higgsfield Image (%1$s): %2$s', 'mcp-ai-wpoos' ),
				$model,
				substr( $prompt, 0, 50 )
			),
			'post_content'   => $prompt,
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Add metadata.
		$metadata = array(
			'higgsfield_prompt' => $prompt,
			'higgsfield_model'  => $model,
			'provider'          => 'higgsfield',
		);

		if ( ! empty( $job_id ) ) {
			$metadata['higgsfield_job_id'] = sanitize_key( $job_id );
		}

		if ( ! empty( $request_id ) ) {
			$metadata['higgsfield_request_id'] = sanitize_text_field( $request_id );
		}

		foreach ( $metadata as $key => $value ) {
			update_post_meta( $attachment_id, '_' . $key, $value );
		}

		// Generate attachment metadata.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		WP_MCP_AI_Logger::log_event(
			'higgsfield_image_saved',
			'Higgsfield generated image saved to Media Library',
			array(
				'attachment_id' => $attachment_id,
				'filename'      => $filename,
				'model'         => $model,
				'request_id'    => $request_id,
			)
		);

		// Return attachment result.
		return WP_MCP_AI_Media_URL_Utils::build_attachment_result( $attachment_id, $upload );
	}

	/**
	 * Calculate cost for image generation.
	 *
	 * Higgsfield bills a flat rate per image from a prepaid USD balance
	 * (e.g. $0.0032/image for SOUL workflows). When no per-image pricing is
	 * registered locally, the cost is flagged as estimated.
	 *
	 * @param int    $count Number of images.
	 * @param string $model Model ID.
	 * @return array Cost data array.
	 */
	protected function calculate_image_cost( $count, $model ) {
		// Load cost calculator.
		if ( ! class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cost-calculator.php';
		}

		$pricing = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'higgsfield', $model );

		$cost = array(
			'cost_usd'     => 0.0,
			'provider'     => 'higgsfield',
			'model'        => $model,
			'is_estimated' => false,
		);

		if ( isset( $pricing['per_image'] ) ) {
			$cost['cost_usd'] = round( (float) $pricing['per_image'] * $count, 6 );
		} else {
			// Mark as estimated if no pricing available.
			$cost['is_estimated'] = true;
		}

		return $cost;
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
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'content_creator', 'video_producer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // Requires Higgsfield API key pair.
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates image files.
			'external-api',         // Makes external API requests.
			'network-dependent',    // Requires internet connection.
			'consumes-tokens',      // Uses AI credits.
			'async',                // Takes significant time.
			'long-running',         // Generation is async.
			'background-only',      // Must run in background.
			'rate-limited',         // Subject to API rate limits.
			'may-timeout',          // May exceed typical HTTP timeouts.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array( 'image-generation' );
	}

	/**
	 * Sanitize image generation results for LLM consumption.
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with only metadata.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Keep only essential metadata.
		$keep_fields = array(
			'success',
			'attachment_id',
			'attachment_ids',
			'url',
			'urls',
			'async',
			'status',
			'job_id',
			'parent_job_id',
			'expected_filename',
			'expected_url',
			'prompt',
			'model',
			'provider',
			'request_id',
			'count',
			'message',
			'usage',
			'cost',
			'text',
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}

	/**
	 * Get pre-execution metadata for async pending response.
	 *
	 * @param string $job_id    The async job identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array Metadata including expected_url and expected_filename.
	 */
	public function get_async_pending_metadata( $job_id, array $arguments = array(), array $context = array() ) {
		$expected_filename = 'higgsfield-image-' . sanitize_file_name( $job_id ) . '-1.jpg';

		$expected_url = '';
		$upload_dir   = wp_upload_dir();
		if ( ! empty( $upload_dir['url'] ) && empty( $upload_dir['error'] ) ) {
			$expected_url = trailingslashit( $upload_dir['url'] ) . $expected_filename;
		}

		$message = sprintf(
			/* translators: 1: expected filename, 2: job ID */
			__( 'Image generation started. Your image (%1$s) is being created and will be available shortly. Job ID: %2$s', 'mcp-ai-wpoos' ),
			$expected_filename,
			$job_id
		);

		return array(
			'expected_url'      => $expected_url,
			'expected_filename' => $expected_filename,
			'message'           => $message,
		);
	}
}
