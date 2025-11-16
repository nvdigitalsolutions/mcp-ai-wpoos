<?php
/**
 * Tool for analyzing video content using AI vision capabilities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Analyzes video content using AI vision models that support video understanding.
 */
class WP_MCP_AI_Tool_Analyze_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Video', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes video content to extract information, describe scenes, identify objects, and provide insights using AI vision models with video understanding capabilities.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'video_url'     => array(
					'type'        => 'string',
					'description' => __( 'URL of the video to analyze. Supports MP4 and QuickTime formats.', 'wp-mcp-ai' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the video to analyze.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'prompt'        => array(
					'type'        => 'string',
					'description' => __( 'Specific question or analysis prompt for the video content. If not provided, a general description will be generated.', 'wp-mcp-ai' ),
				),
				'context'       => array(
					'type'        => 'string',
					'description' => __( 'Optional context about the video to help generate more relevant analysis.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array(),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check user capabilities.
		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to analyze videos.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Get video source.
		$video_url = '';

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$video_url     = wp_get_attachment_url( $attachment_id );

			if ( ! $video_url ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_attachment',
					__( 'Invalid attachment ID provided.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			// Verify it's a video attachment.
			$mime_type = get_post_mime_type( $attachment_id );
			if ( ! $mime_type || false === strpos( $mime_type, 'video/' ) ) {
				return new WP_Error(
					'wp_mcp_ai_not_video',
					__( 'The provided attachment is not a video file.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( ! empty( $arguments['video_url'] ) ) {
			$video_url = esc_url_raw( $arguments['video_url'] );
		} else {
			return new WP_Error(
				'wp_mcp_ai_missing_video',
				__( 'Either video_url or attachment_id must be provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get settings.
		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$default_provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'gemini';

		// Build prompt.
		$user_prompt  = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$user_context = isset( $arguments['context'] ) ? sanitize_text_field( $arguments['context'] ) : '';
		$prompt       = $this->build_prompt( $user_prompt, $user_context );

		// Call video-capable vision model and capture metadata.
		$attachment_id_for_video = ! empty( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : null;
		$api_response            = $this->call_video_model( $video_url, $prompt, $default_provider, $attachment_id_for_video );

		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		// Extract analysis and metadata.
		$analysis = is_array( $api_response ) && isset( $api_response['text'] ) ? $api_response['text'] : $api_response;
		$usage    = is_array( $api_response ) && isset( $api_response['usage'] ) ? $api_response['usage'] : null;
		$model    = is_array( $api_response ) && isset( $api_response['model'] ) ? $api_response['model'] : '';
		$provider = is_array( $api_response ) && isset( $api_response['provider'] ) ? $api_response['provider'] : $default_provider;

		$result = array(
			'analysis' => $analysis,
			'success'  => true,
		);

		// Include provider/model/usage metadata for accurate cost tracking.
		if ( $provider ) {
			$result['provider'] = $provider;
		}

		if ( $model ) {
			$result['model'] = $model;
		}

		if ( $usage ) {
			$result['usage'] = $usage;
		}

		return $result;
	}

	/**
	 * Build the analysis prompt.
	 *
	 * @param string $user_prompt  User's specific question or prompt.
	 * @param string $user_context Additional context about the video.
	 * @return string
	 */
	protected function build_prompt( $user_prompt, $user_context ) {
		if ( ! empty( $user_prompt ) ) {
			$prompt = $user_prompt;
			if ( ! empty( $user_context ) ) {
				$prompt = sprintf(
					/* translators: 1: context, 2: prompt */
					__( 'Context: %1$s\n\n%2$s', 'wp-mcp-ai' ),
					$user_context,
					$prompt
				);
			}
			return $prompt;
		}

		// Default comprehensive analysis prompt.
		$prompt = __(
			'Please analyze this video and provide a detailed description including:
1. Main subjects and objects in the video
2. Actions and activities taking place
3. Setting and environment
4. Notable visual elements or transitions
5. Overall tone and mood
6. Any text or graphics visible in the video',
			'wp-mcp-ai'
		);

		if ( ! empty( $user_context ) ) {
			$prompt = sprintf(
				/* translators: 1: context, 2: default prompt */
				__( 'Context: %1$s\n\n%2$s', 'wp-mcp-ai' ),
				$user_context,
				$prompt
			);
		}

		return $prompt;
	}

	/**
	 * Call a video-capable vision model.
	 *
	 * @param string   $video_url     URL of the video to analyze.
	 * @param string   $prompt        Analysis prompt.
	 * @param string   $provider      AI provider to use.
	 * @param int|null $attachment_id WordPress attachment ID if available.
	 * @return array|WP_Error Response with text, usage, model, and provider.
	 */
	protected function call_video_model( $video_url, $prompt, $provider, $attachment_id = null ) {
		// Gemini is the primary provider with native video support.
		if ( 'gemini' === $provider || 'google' === $provider ) {
			return $this->call_gemini_video( $video_url, $prompt, $attachment_id );
		}

		// GPT-4o supports video frames (extract frames and analyze).
		if ( 'openai' === $provider ) {
			return $this->call_openai_video_frames( $video_url, $prompt );
		}

		return new WP_Error(
			'wp_mcp_ai_unsupported_provider',
			sprintf(
				/* translators: %s: provider name */
				__( 'Video analysis is not supported for provider: %s. Please use Gemini or OpenAI.', 'wp-mcp-ai' ),
				$provider
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Call Gemini API with video support.
	 *
	 * Uploads the video to Gemini File API, waits for processing,
	 * analyzes it with the given prompt, and cleans up the uploaded file.
	 *
	 * @param string   $video_url     URL of the video.
	 * @param string   $prompt        Analysis prompt.
	 * @param int|null $attachment_id WordPress attachment ID if available.
	 * @return array|WP_Error Response with text, usage, model, and provider.
	 */
	protected function call_gemini_video( $video_url, $prompt, $attachment_id = null ) {
		// Ensure required classes are loaded.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-video-file-manager.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';

		// Get file path from attachment or download from URL.
		$file_path = null;
		$mime_type = null;
		$temp_file = false;

		if ( $attachment_id ) {
			// Get file from WordPress attachment.
			$file_path = get_attached_file( $attachment_id );
			$mime_type = get_post_mime_type( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_file_not_found',
					__( 'Video file not found on server.', 'wp-mcp-ai' ),
					array( 'status' => 404 )
				);
			}
		} else {
			// Download video from URL to temporary file.
			$download_result = $this->download_video_to_temp( $video_url );
			if ( is_wp_error( $download_result ) ) {
				return $download_result;
			}

			$file_path = $download_result['file_path'];
			$mime_type = $download_result['mime_type'];
			$temp_file = true;
		}

		// Initialize services.
		$file_service    = new WP_MCP_AI_Gemini_File_Service();
		$file_manager    = new WP_MCP_AI_Video_File_Manager( $file_service );
		$video_hash      = $file_manager->generate_video_hash( $file_path );
		$upload_result   = null;
		$cache_hit       = false;

		if ( is_wp_error( $video_hash ) ) {
			// If hash generation fails, proceed without caching.
			$video_hash = null;
		} else {
			// Check cache for existing upload.
			$cached_file = $file_manager->get_cached_file( $video_hash );
			if ( false !== $cached_file ) {
				// Cache hit! Use existing file.
				$upload_result = $cached_file;
				$cache_hit     = true;

				// Update last used timestamp.
				$file_manager->touch_file( $video_hash );

				WP_MCP_AI_Logger::log_event(
					'video_cache_hit',
					'Using cached video file upload.',
					array(
						'video_hash' => $video_hash,
						'file_name'  => $cached_file['file_name'],
					)
				);
			}
		}

		// Upload if not in cache.
		if ( ! $cache_hit ) {
			$upload_result = $file_service->upload_file( $file_path, $mime_type, basename( $file_path ) );

			// Register the upload in cache if successful.
			if ( ! is_wp_error( $upload_result ) && null !== $video_hash ) {
				$metadata = array(
					'attachment_id' => $attachment_id,
					'video_url'     => $video_url,
				);
				$file_manager->register_file( $video_hash, $upload_result, $metadata );
			}
		}

		// Clean up temp file after upload if needed.
		if ( $temp_file && $file_path ) {
			wp_delete_file( $file_path );
		}

		if ( is_wp_error( $upload_result ) ) {
			return $upload_result;
		}

		$file_name = $upload_result['file_name'];
		$file_uri  = $upload_result['file_uri'];

		// Wait for file processing to complete.
		$processing_result = $file_service->wait_for_processing( $file_name, 300 );

		if ( is_wp_error( $processing_result ) ) {
			// Try to clean up file if processing failed and not cached.
			if ( ! $cache_hit ) {
				$file_service->delete_file( $file_name );
			}
			return $processing_result;
		}

		// Build message with file reference.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
					array(
						'type'      => 'file',
						'file_uri'  => $file_uri,
						'mime_type' => $mime_type,
					),
				),
			),
		);

		// Call Gemini with video.
		$client = new WP_MCP_AI_Gemini_Client();

		// Get model from settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$model    = isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-2.0-flash-exp';

		$response = $client->create_chat_completion(
			$messages,
			array(
				'model' => $model,
			)
		);

		// Clean up uploaded file only if it was a new upload (not from cache).
		// Cached files are managed by the cleanup cron job.
		if ( ! $cache_hit ) {
			// Don't delete immediately - let it stay for potential reuse.
			// The cleanup cron will handle expired files.
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Add provider metadata.
		$response['provider'] = 'gemini';

		return $response;
	}

	/**
	 * Download video from URL to temporary file.
	 *
	 * @param string $video_url Video URL to download.
	 * @return array|WP_Error Array with file_path and mime_type, or error.
	 */
	protected function download_video_to_temp( $video_url ) {
		// Download file using WordPress HTTP API.
		$response = wp_remote_get(
			$video_url,
			array(
				'timeout' => 300, // 5 minutes for large videos.
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Failed to download video. HTTP status: %d', 'wp-mcp-ai' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		$body      = wp_remote_retrieve_body( $response );
		$mime_type = wp_remote_retrieve_header( $response, 'content-type' );

		// Validate MIME type.
		if ( ! $mime_type || false === strpos( $mime_type, 'video/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_video',
				__( 'Downloaded file is not a video.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Create temporary file.
		$temp_file = wp_tempnam( 'video' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $temp_file, $body );

		if ( false === $written ) {
			return new WP_Error(
				'wp_mcp_ai_temp_file_failed',
				__( 'Failed to write video to temporary file.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'file_path' => $temp_file,
			'mime_type' => $mime_type,
		);
	}

	/**
	 * Call OpenAI with extracted video frames.
	 *
	 * This is a fallback for providers that don't support native video.
	 * Note: This requires frame extraction which is not yet implemented.
	 *
	 * @param string $video_url URL of the video.
	 * @param string $prompt    Analysis prompt.
	 * @return array|WP_Error
	 */
	protected function call_openai_video_frames( $video_url, $prompt ) {
		return new WP_Error(
			'wp_mcp_ai_not_implemented',
			__( 'Video frame extraction for OpenAI is not yet implemented. Please use Gemini for video analysis.', 'wp-mcp-ai' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Check if a Gemini model supports video.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	protected function is_video_capable_gemini_model( $model ) {
		// Gemini 2.0 and later support video.
		$video_capable_models = array(
			'gemini-2.0-flash-exp',
			'gemini-2.5-flash',
			'gemini-exp-1206',
		);

		return in_array( $model, $video_capable_models, true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials',  // Requires AI provider API key.
			'requires-video-model',  // Requires video-capable AI model.
			'read-only',             // Only reads/analyzes data.
			'external-api',          // Makes external API requests.
			'network-dependent',     // Requires internet connection.
			'consumes-tokens',       // Uses AI tokens/credits.
			'model-dependent',       // Behavior varies by model.
			'async',                 // May take significant time.
			'rate-limited',          // Subject to API rate limits.
		);
	}
}
