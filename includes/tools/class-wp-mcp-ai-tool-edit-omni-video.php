<?php
/**
 * Tool for editing videos using Gemini Omni conversational editing.
 *
 * Omni replaces Veo as the primary video editing model, enabling natural-language
 * multi-turn video editing where users describe desired changes and Omni applies
 * them while preserving context across edit sessions.
 *
 * Capabilities:
 * - Swap backgrounds while preserving subjects
 * - Change wardrobe, adjust lighting, stabilize footage
 * - Remove objects or people from video
 * - Transfer visual styles between videos
 * - Multi-turn editing with preserved context (pass previous_video_id)
 * - All outputs include SynthID watermark
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-async-metadata.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-omni-service.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-video-response.php';

/**
 * Edit Omni Video Tool.
 */
class WP_MCP_AI_Tool_Edit_Omni_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Async_Metadata_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Video_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'edit_omni_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Edit Video with Omni', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Edits videos using Gemini Omni conversational editing. Describe the changes you want in plain language — swap backgrounds, change wardrobe, adjust lighting, stabilize footage, remove objects, transfer styles, or make other creative edits. Supports multi-turn editing: pass the previous video\'s operation ID to continue editing with full context preserved. All edited videos include SynthID watermark. Requires Omni API access (available in the coming weeks per Google I/O 2026).', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'edit_prompt'       => array(
					'type'        => 'string',
					'description' => __( 'Natural language description of the edits to apply. Be specific (e.g., "change the background to a beach at sunset", "remove the person on the left", "stabilize the shaky footage", "make it look like a film noir").', 'mcp-ai-wpoos' ),
				),
				'source_video_id'   => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the video to edit. Required unless continuing a previous edit session via previous_video_id.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'previous_video_id' => array(
					'type'        => 'string',
					'description' => __( 'Operation ID from a previous Omni video edit. Pass this to continue multi-turn editing with preserved context. The previous video becomes the source, so source_video_id is optional when this is set.', 'mcp-ai-wpoos' ),
				),
				'aspect_ratio'      => array(
					'type'        => 'string',
					'description' => __( 'Output aspect ratio override. If not set, preserves original video\'s aspect ratio.', 'mcp-ai-wpoos' ),
					'enum'        => array( '1:1', '2:3', '3:2', '16:9', '9:16' ),
				),
				'async'             => array(
					'type'        => 'boolean',
					'description' => __( 'Run editing asynchronously. Returns a job ID for status tracking.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'   => array( 'edit_prompt' ),
		);
	}

	/**
	 * Execute the Omni Video edit tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Sanitize inputs (two-gate rule).
		$edit_prompt       = isset( $arguments['edit_prompt'] ) ? sanitize_textarea_field( $arguments['edit_prompt'] ) : '';
		$source_video_id   = isset( $arguments['source_video_id'] ) ? absint( $arguments['source_video_id'] ) : 0;
		$previous_video_id = isset( $arguments['previous_video_id'] ) ? sanitize_text_field( $arguments['previous_video_id'] ) : '';
		$aspect_ratio      = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '';
		$use_async         = ! empty( $arguments['async'] );
		$user_id           = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( empty( $edit_prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_edit_prompt',
				__( 'An edit description is required. Describe what changes you want to make.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( 0 === $source_video_id && empty( $previous_video_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_source',
				__( 'Either a source video (source_video_id) or a previous edit session (previous_video_id) is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Validate source video exists.
		if ( $source_video_id > 0 ) {
			$source_path = get_attached_file( $source_video_id );
			$source_mime = get_post_mime_type( $source_video_id );

			if ( ! $source_path || ! file_exists( $source_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_source_not_found',
					__( 'Source video not found in Media Library.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			if ( ! $source_mime || 0 !== strpos( $source_mime, 'video/' ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_source',
					__( 'Source file is not a video.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}
		}

		WP_MCP_AI_Logger::log_event(
			'omni_edit_tool_execute',
			'Executing Omni video edit',
			array(
				'edit_prompt_preview' => substr( $edit_prompt, 0, 80 ),
				'source_video_id'     => $source_video_id,
				'previous_video_id'   => $previous_video_id,
				'async'               => $use_async,
			)
		);

		$service = new WP_MCP_AI_Gemini_Omni_Service();

		$args = array(
			'edit_prompt'       => $edit_prompt,
			'source_video_id'   => $source_video_id,
			'previous_video_id' => $previous_video_id,
			'async'             => $use_async,
			'user_id'           => $user_id,
		);

		if ( ! empty( $aspect_ratio ) ) {
			$args['aspect_ratio'] = $aspect_ratio;
		}

		$result = $service->edit_video( $args );

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Omni video editing failed',
				array(
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
				)
			);
			return $result;
		}

		// Handle async response.
		if ( isset( $result['async'] ) && $result['async'] ) {
			return $this->build_chat_response(
				sprintf(
					/* translators: %s: job ID */
					__( 'Video editing queued. Job ID: %s. Use check_omni_video_status to track progress.', 'mcp-ai-wpoos' ),
					esc_html( $result['job_id'] )
				),
				array(
					'job_id' => esc_html( $result['job_id'] ),
					'status' => 'queued',
				)
			);
		}

		// Save edited video to Media Library.
		return $this->save_edited_video( $result, $edit_prompt );
	}

	/**
	 * Save edited video to Media Library.
	 *
	 * @param array  $result      Edit result.
	 * @param string $edit_prompt Edit instruction.
	 * @return array|WP_Error Media library data or error.
	 */
	protected function save_edited_video( $result, $edit_prompt ) {
		if ( ! isset( $result['video_data'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_video_data',
				__( 'No video data in edit result.', 'mcp-ai-wpoos' )
			);
		}

		$video_data = $result['video_data'];

		$filename = sanitize_file_name(
			sprintf(
				'omni-edit-%s-%s.mp4',
				substr( sanitize_title( $edit_prompt ), 0, 40 ),
				substr( md5( $edit_prompt . time() ), 0, 8 )
			)
		);

		$upload = wp_upload_bits( $filename, null, $video_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				sprintf(
					/* translators: %s: upload error message */
					__( 'Failed to save edited video: %s', 'mcp-ai-wpoos' ),
					$upload['error']
				)
			);
		}

		$attachment = array(
			'post_mime_type' => 'video/mp4',
			'post_title'     => sprintf(
				/* translators: %s: edit description */
				__( 'Omni Edited Video: %s', 'mcp-ai-wpoos' ),
				substr( $edit_prompt, 0, 80 )
			),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		update_post_meta( $attach_id, '_wp_mcp_ai_generated_by', 'gemini-omni-edit' );
		update_post_meta( $attach_id, '_wp_mcp_ai_edit_prompt', $edit_prompt );

		$attachment_url = wp_get_attachment_url( $attach_id );

		return array(
			'success'        => true,
			'attachment_id'  => $attach_id,
			'attachment_url' => $attachment_url,
			'edit_prompt'    => $edit_prompt,
			'filename'       => $filename,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'background-only'  => true,
			'token_multiplier' => 2.5,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(
			'providers'    => array( 'gemini' ),
			'capabilities' => array( 'video-generation' ),
			'required'     => true,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_async_metadata() {
		return array(
			'background-only' => true,
			'timeout'         => 300,
		);
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
		unset( $arguments, $context );

		return array(
			'expected_url'      => '',
			'expected_filename' => sanitize_file_name( 'omni-edit-' . $job_id ) . '.mp4',
			'message'           => sprintf(
				/* translators: %s: job ID */
				__( 'Video edit started and is being processed. Job ID: %s', 'mcp-ai-wpoos' ),
				$job_id
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Sanitize omni-video edit results for LLM context consumption.
	 *
	 * @param mixed $result Raw tool execution result.
	 * @return mixed Sanitized result safe for LLM context.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$keep_fields = array(
			'success',
			'message',
			'url',
			'expected_url',
			'video_url',
			'job_id',
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}
}
