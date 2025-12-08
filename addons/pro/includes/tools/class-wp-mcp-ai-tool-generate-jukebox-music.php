<?php
/**
 * Tool that generates music using OpenAI Jukebox model.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-jukebox-service.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

/**
 * Provides a tool for generating music via locally-installed OpenAI Jukebox.
 */
class WP_MCP_AI_Tool_Generate_Jukebox_Music implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_jukebox_music';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Jukebox Music', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates music with vocals from a text description using locally-installed OpenAI Jukebox model and saves it to the Media Library. Requires Jukebox to be installed on the server. Supports artist style emulation, genre specification, and custom lyrics.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'        => array(
					'type'        => 'string',
					'description' => __( 'Description of the desired music (e.g., "upbeat jazz piano with vocals" or "rock ballad in the style of Queen").', 'wp-mcp-ai' ),
				),
				'model'         => array(
					'type'        => 'string',
					'description' => __( 'Jukebox model to use: "1b_lyrics" (faster, lower quality), "5b" (no lyrics), or "5b_lyrics" (best quality with lyrics).', 'wp-mcp-ai' ),
					'enum'        => array( '1b_lyrics', '5b', '5b_lyrics' ),
					'default'     => WP_MCP_AI_Jukebox_Service::DEFAULT_MODEL,
				),
				'sample_length' => array(
					'type'        => 'integer',
					'description' => __( 'Duration of the music in seconds (1-60). Note: Longer samples take significantly more time to generate.', 'wp-mcp-ai' ),
					'default'     => WP_MCP_AI_Jukebox_Service::DEFAULT_SAMPLE_LENGTH,
					'minimum'     => 1,
					'maximum'     => WP_MCP_AI_Jukebox_Service::MAX_SAMPLE_LENGTH,
				),
				'artist'        => array(
					'type'        => 'string',
					'description' => __( 'Optional artist style to emulate (e.g., "Ella Fitzgerald", "Frank Sinatra", "The Beatles").', 'wp-mcp-ai' ),
				),
				'genre'         => array(
					'type'        => 'string',
					'description' => __( 'Optional music genre (e.g., "jazz", "rock", "classical", "pop", "country").', 'wp-mcp-ai' ),
				),
				'lyrics'        => array(
					'type'        => 'string',
					'description' => __( 'Optional custom lyrics for the AI to sing. Only works with models that include "_lyrics" in the name.', 'wp-mcp-ai' ),
				),
				'temperature'   => array(
					'type'        => 'number',
					'description' => __( 'Optional creativity level (0.0-1.0, higher = more random). Default is 0.98.', 'wp-mcp-ai' ),
					'default'     => WP_MCP_AI_Jukebox_Service::DEFAULT_TEMPERATURE,
					'minimum'     => 0.0,
					'maximum'     => 1.0,
				),
				'file_name'     => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved audio attachment.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'prompt' ),
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
		$prompt    = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt    = trim( $prompt );

		// Authentication check.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to generate music with Jukebox.', 'wp-mcp-ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Capability check.
		if ( $user_id ) {
			if ( ! user_can( $user_id, 'upload_files' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to generate music.', 'wp-mcp-ai' )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_site',
					__( 'You do not have access to this site.', 'wp-mcp-ai' )
				);
			}
		}

		// Validate prompt.
		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'No prompt was supplied for music generation.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Prepare options for service.
		$options = array();

		if ( isset( $arguments['model'] ) && ! empty( $arguments['model'] ) ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
		}

		if ( isset( $arguments['sample_length'] ) ) {
			$options['sample_length'] = absint( $arguments['sample_length'] );
		}

		if ( isset( $arguments['artist'] ) && ! empty( $arguments['artist'] ) ) {
			$options['artist'] = sanitize_text_field( $arguments['artist'] );
		}

		if ( isset( $arguments['genre'] ) && ! empty( $arguments['genre'] ) ) {
			$options['genre'] = sanitize_text_field( $arguments['genre'] );
		}

		if ( isset( $arguments['lyrics'] ) && ! empty( $arguments['lyrics'] ) ) {
			$options['lyrics'] = sanitize_textarea_field( $arguments['lyrics'] );
		}

		if ( isset( $arguments['temperature'] ) ) {
			$options['temperature'] = floatval( $arguments['temperature'] );
		}

		// Generate music using the service.
		$service = new WP_MCP_AI_Jukebox_Service();
		$result  = $service->generate_music( $prompt, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['audio_file'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_audio',
				__( 'Jukebox returned an empty audio response.', 'wp-mcp-ai' )
			);
		}

		// Store music as WordPress attachment.
		$file_name = $arguments['file_name'] ?? '';
		$storage   = $this->store_music_attachment( $result, $file_name, $prompt, $user_id, $context );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		// Build result array.
		$output = array(
			'attachment_id' => $storage['attachment_id'],
			'url'           => $storage['url'],
			'file_path'     => $storage['file'],
			'file_name'     => $storage['file_name'],
			'mime_type'     => $storage['mime_type'],
			'bytes'         => $storage['bytes'],
			'format'        => $result['format'],
			'sample_length' => $result['sample_length'],
			'prompt'        => $prompt,
			'model'         => $result['model'],
		);

		if ( ! empty( $storage['duration_formatted'] ) ) {
			$output['duration_formatted'] = $storage['duration_formatted'];
		}

		if ( ! empty( $storage['title'] ) ) {
			$output['title'] = $storage['title'];
		}

		// Add optional parameters that were used.
		if ( ! empty( $result['artist'] ) ) {
			$output['artist'] = $result['artist'];
		}
		if ( ! empty( $result['genre'] ) ) {
			$output['genre'] = $result['genre'];
		}
		if ( ! empty( $result['lyrics'] ) ) {
			$output['lyrics'] = $result['lyrics'];
		}

		WP_MCP_AI_Logger::log_event(
			'jukebox_music_generated',
			'Stored Jukebox music generation as a media attachment.',
			array(
				'attachment_id' => $storage['attachment_id'],
				'sample_length' => $result['sample_length'],
				'format'        => $result['format'],
				'model'         => $result['model'],
			)
		);

		/**
		 * Allow third parties to filter the Jukebox music generation tool result.
		 *
		 * @param array $output    Result array returned by the tool.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		return apply_filters( 'wp_mcp_ai_generate_jukebox_music_result', $output, $arguments, $context );
	}

	/**
	 * Store the generated music as a WordPress attachment.
	 *
	 * @param array  $music_data Response payload from the music service.
	 * @param string $file_name  Optional preferred file name.
	 * @param string $prompt     Original prompt.
	 * @param int    $user_id    Acting user ID.
	 * @param array  $context    Optional. Execution context containing parent_job_id.
	 * @return array|WP_Error
	 */
	protected function store_music_attachment( array $music_data, $file_name, $prompt, $user_id, array $context = array() ) {
		$audio_file = isset( $music_data['audio_file'] ) ? $music_data['audio_file'] : '';
		$format     = isset( $music_data['format'] ) ? $music_data['format'] : 'wav';

		if ( empty( $audio_file ) || ! file_exists( $audio_file ) ) {
			return new WP_Error(
				'wp_mcp_ai_music_storage_error',
				__( 'Unable to store music: no audio file.', 'wp-mcp-ai' )
			);
		}

		// Read audio file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$binary_audio = file_get_contents( $audio_file );
		if ( false === $binary_audio ) {
			return new WP_Error(
				'wp_mcp_ai_music_read_error',
				__( 'Unable to read the generated audio file.', 'wp-mcp-ai' )
			);
		}

		// Determine MIME type.
		$mime_types = array(
			'wav'  => 'audio/wav',
			'mp3'  => 'audio/mpeg',
			'ogg'  => 'audio/ogg',
			'flac' => 'audio/flac',
		);

		$mime_type = isset( $mime_types[ $format ] ) ? $mime_types[ $format ] : 'audio/wav';
		$extension = $format;

		// Use job_id for filename if available, otherwise use file_name or default.
		$job_id = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
		if ( ! empty( $job_id ) ) {
			$file_name = sprintf( 'jukebox-music-%s.%s', $job_id, $extension );
		} else {
			$file_stem = $this->normalize_file_stem( $file_name );
			$file_name = sprintf( '%s-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $extension );
		}

		// Upload to WordPress.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $binary_audio );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				__( 'Failed to save the generated music file.', 'wp-mcp-ai' ),
				array( 'error' => $upload['error'] )
			);
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				__( 'Failed to write the music file to disk.', 'wp-mcp-ai' )
			);
		}

		// Create attachment title.
		$title = $this->generate_attachment_title( $prompt );

		// Create attachment post.
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
			// Clean up the file.
			$this->delete_file_safely( $file_path );

			return new WP_Error(
				'wp_mcp_ai_attachment_error',
				__( 'Failed to register the music file as an attachment.', 'wp-mcp-ai' ),
				array( 'error' => $attachment_id )
			);
		}

		// Generate and update attachment metadata.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		} else {
			$metadata = array();
		}

		// Store job_id if available - allows correlation between job IDs and files.
		if ( ! empty( $job_id ) ) {
			update_post_meta( $attachment_id, '_jukebox_music_job_id', $job_id );
		}

		// Store model information.
		if ( ! empty( $music_data['model'] ) ) {
			update_post_meta( $attachment_id, '_jukebox_model', $music_data['model'] );
		}

		// Clean up the original Jukebox output file.
		if ( file_exists( $audio_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $audio_file );
		}

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		// Get local WordPress URL using utility class for SoC compliance.
		$local_url = WP_MCP_AI_Media_URL_Utils::get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id'      => (int) $attachment_id,
			'file'               => $file_path,
			'file_name'          => wp_basename( $file_path ),
			'url'                => $local_url,
			'mime_type'          => $mime_type,
			'bytes'              => $bytes ? (int) $bytes : 0,
			'duration'           => isset( $metadata['length'] ) ? floatval( $metadata['length'] ) : null,
			'duration_formatted' => isset( $metadata['length_formatted'] ) ? $metadata['length_formatted'] : '',
			'title'              => $title,
		);
	}

	/**
	 * Normalize a file stem used for generated attachments.
	 *
	 * @param string $file_name Raw file name input.
	 * @return string
	 */
	protected function normalize_file_stem( $file_name ) {
		$file_name = sanitize_file_name( (string) $file_name );

		if ( empty( $file_name ) ) {
			return 'generated-jukebox-music';
		}

		$info = pathinfo( $file_name );
		$stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
		$stem = sanitize_title( $stem );

		if ( empty( $stem ) ) {
			$stem = 'generated-jukebox-music';
		}

		return $stem;
	}

	/**
	 * Generate a human-readable attachment title using the prompt.
	 *
	 * @param string $prompt Original prompt.
	 * @return string
	 */
	protected function generate_attachment_title( $prompt ) {
		$prompt = (string) $prompt;
		$prompt = preg_replace( '/\s+/', ' ', $prompt );
		$prompt = trim( $prompt );

		if ( empty( $prompt ) ) {
			return __( 'Generated Jukebox Music', 'wp-mcp-ai' );
		}

		$excerpt = wp_trim_words( $prompt, 10, '…' );

		/* translators: %s: Short excerpt of the prompt used to generate music. */
		return sprintf( __( 'Jukebox Music: %s', 'wp-mcp-ai' ), $excerpt );
	}

	/**
	 * Delete a generated file from disk safely when an error occurs.
	 *
	 * @param string $file_path Absolute file path.
	 */
	protected function delete_file_safely( $file_path ) {
		$file_path = (string) $file_path;

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
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
			'pro',                  // Pro feature.
			'local-execution',      // Executes commands on the local server.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
