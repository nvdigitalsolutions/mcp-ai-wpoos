<?php
/**
 * Tool for AI-powered image colorization.
 *
 * Colorizes black and white or grayscale images with real AI processing —
 * the Media Worker sidecar /api/image/edit route (Gemini/OpenAI/Replicate
 * provider chain on the worker) or the PHP Gemini/OpenAI provider clients
 * as the local fallback. When neither backend can serve the request the
 * tool returns an honest wp_mcp_ai_no_provider error instead of claiming
 * success. The _wp_mcp_ai_colorized meta is written only when real
 * colorization happened.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/traits/trait-wp-mcp-ai-sharp-image-processing.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/traits/trait-wp-mcp-ai-provider-image-edit.php';

/**
 * Colorize black and white images with real AI processing.
 */
class WP_MCP_AI_Tool_Colorize_Image extends WP_MCP_AI_Tool_Image_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Sharp_Image_Processing;
	use WP_MCP_AI_Provider_Image_Edit;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'colorize_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Colorize Image', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Colorize black and white or grayscale images with real AI processing via the Media Worker sidecar or the Gemini/OpenAI provider clients.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Use to add realistic color to black-and-white or grayscale photos with AI, choosing auto, vibrant, or subtle color modes.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Use enhance_image_quality to correct color and contrast on already-color images, or apply_artistic_style for artistic recoloring.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'enhance_image_quality', 'apply_artistic_style', 'generate_image_variations' ),
			'notes'           => __( 'Requires a Media Worker sidecar or a Gemini/OpenAI API key — without either the tool errors honestly. Set use_remote=true to prefer the sidecar.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->get_source_parameters_schema(),
				array(
					'color_mode' => array(
						'type'        => 'string',
						'description' => __( 'Colorization mode: "auto" (AI decides), "vibrant" (bold colors), "subtle" (muted tones).', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'auto', 'vibrant', 'subtle' ),
						'default'     => 'auto',
					),
					'use_remote' => array(
						'type'        => 'boolean',
						'description' => __( 'Prefer the Media Worker sidecar over the PHP provider clients.', 'mcp-ai-wpoos-pro' ),
						'default'     => false,
					),
				)
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'write',
			'external-api',
			'performance-impact',
			'idempotent',
		);
	}

	/**
	 * The color_mode → prompt map (single source of truth, mirrored to the
	 * worker's /api/image/edit route).
	 *
	 * @return array Prompt per color mode.
	 */
	protected function get_colorize_prompts() {
		return array(
			'auto'    => __( 'Colorize this black-and-white photo, preserving realism and natural colors.', 'mcp-ai-wpoos-pro' ),
			'vibrant' => __( 'Colorize this black-and-white photo using vibrant, bold, saturated colors.', 'mcp-ai-wpoos-pro' ),
			'subtle'  => __( 'Colorize this black-and-white photo using subtle, muted, natural tones.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Human-readable label for a processing engine.
	 *
	 * @param string $engine Engine id.
	 * @return string Label.
	 */
	protected function get_engine_label( $engine ) {
		switch ( $engine ) {
			case 'sidecar':
				return __( 'the Media Worker sidecar', 'mcp-ai-wpoos-pro' );
			case 'gemini':
				return __( 'Gemini', 'mcp-ai-wpoos-pro' );
			case 'openai':
				return __( 'OpenAI', 'mcp-ai-wpoos-pro' );
			default:
				return $engine;
		}
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to colorize images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get and validate the color mode.
		$color_mode = isset( $arguments['color_mode'] ) ? sanitize_text_field( $arguments['color_mode'] ) : 'auto';
		$prompts    = $this->get_colorize_prompts();
		if ( ! isset( $prompts[ $color_mode ] ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Unknown color_mode. Allowed: auto, vibrant, subtle.', 'mcp-ai-wpoos-pro' )
			);
		}
		$prompt = $prompts[ $color_mode ];

		// Determine the processing backends.
		$source_path = isset( $source_image->source_file_path ) && is_string( $source_image->source_file_path )
			? $source_image->source_file_path
			: '';
		$source_ext  = $source_path ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) pathinfo( $source_path, PATHINFO_EXTENSION ) ) : '';
		if ( '' === $source_ext ) {
			$source_ext = 'jpg';
		}

		$sidecar_supported = $this->is_sidecar_upload_supported();
		$gemini_available  = $this->provider_has_credentials( 'gemini' );
		$openai_available  = $this->provider_has_credentials( 'openai' );

		if ( ! $sidecar_supported && ! $gemini_available && ! $openai_available ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return new WP_Error(
				'wp_mcp_ai_no_provider',
				__( 'Image colorization requires a Media Worker sidecar or a Gemini/OpenAI API key, and neither is available. Configure a provider key in the NV oOS settings or set up the Media Worker sidecar.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build the backend attempt chain: use_remote prefers the sidecar;
		// the default path prefers the PHP provider clients.
		$use_remote = ! empty( $arguments['use_remote'] );
		$attempts   = array();
		if ( $use_remote && $sidecar_supported ) {
			$attempts[] = 'sidecar';
		}
		if ( $gemini_available ) {
			$attempts[] = 'gemini';
		}
		if ( $openai_available ) {
			$attempts[] = 'openai';
		}
		if ( ! $use_remote && $sidecar_supported ) {
			$attempts[] = 'sidecar';
		}

		$processed  = false;
		$final_path = '';
		$engine     = '';
		$last_error = '';

		foreach ( $attempts as $attempt ) {
			if ( 'sidecar' === $attempt ) {
				$result = $this->process_image_via_sidecar(
					'/api/image/edit',
					$source_path,
					array(
						'operation'  => 'colorize',
						'color_mode' => $color_mode,
					),
					$source_ext
				);
				if ( is_array( $result ) && ! isset( $result['error'] ) && ! empty( $result['output_path'] ) && file_exists( $result['output_path'] ) ) {
					$processed  = true;
					$final_path = $result['output_path'];
					$engine     = 'sidecar';
					break;
				}
				$last_error = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : __( 'Worker image editing failed.', 'mcp-ai-wpoos-pro' );
				continue;
			}

			$edited = $this->ai_edit_image_bytes( $source_path, $prompt, $attempt );
			if ( is_wp_error( $edited ) ) {
				$last_error = $edited->get_error_message();
				continue;
			}
			if ( '' === $edited ) {
				$last_error = __( 'Provider returned empty image data.', 'mcp-ai-wpoos-pro' );
				continue;
			}

			// Write provider bytes to a temp file for the media upload.
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$base       = wp_tempnam( 'colorize-output-' );
			$final_path = $base . '.png';
			wp_delete_file( $base );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing provider-returned image bytes to a site temp file.
			if ( false === file_put_contents( $final_path, $edited ) ) {
				$this->cleanup_source_image( $source_image, $arguments );
				return new WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to write the colorized image file.', 'mcp-ai-wpoos-pro' ) );
			}
			$processed = true;
			$engine    = $attempt;
			break;
		}

		// Clean up source image if it was a temp file.
		$this->cleanup_source_image( $source_image, $arguments );

		if ( ! $processed ) {
			return new WP_Error(
				'wp_mcp_ai_no_provider',
				sprintf(
					/* translators: %s: last backend error message */
					__( 'Image colorization failed on every available backend (%s).', 'mcp-ai-wpoos-pro' ),
					'' !== $last_error ? $last_error : __( 'unknown error', 'mcp-ai-wpoos-pro' )
				)
			);
		}

		// Land the processed file in the media library.
		$parent_id     = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$attachment_id = $this->upload_processed_image( $final_path, $parent_id, __( 'Colorized Image', 'mcp-ai-wpoos-pro' ) );
		wp_delete_file( $final_path );

		if ( ! $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_error',
				__( 'Failed to create attachment for the colorized image.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Honest metadata: written only after real colorization succeeded.
		update_post_meta( $attachment_id, '_wp_mcp_ai_colorized', true );
		update_post_meta( $attachment_id, '_wp_mcp_ai_color_mode', $color_mode );

		$response = $this->format_attachment_response( $attachment_id, $arguments );

		$response['text'] = sprintf(
			/* translators: %s: processing engine */
			__( 'Image colorized successfully with %s.', 'mcp-ai-wpoos-pro' ),
			$this->get_engine_label( $engine )
		);
		$response['engine']     = $engine;
		$response['color_mode'] = $color_mode;
		$response['prompt']     = $prompt;

		return $response;
	}

	/**
	 * Sanitize the tool result for LLM consumption.
	 *
	 * @param array|WP_Error $result The result to sanitize.
	 * @return array Sanitized result.
	 */
	public function sanitize_for_llm( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
			);
		}

		return array(
			'success' => true,
			'result'  => $result,
		);
	}
}
