<?php
/**
 * Tool for applying artistic styles to images using AI.
 *
 * Applies real AI style transfer via the Media Worker sidecar
 * /api/image/edit route (Gemini/OpenAI/Replicate provider chain on the
 * worker) or the PHP Gemini/OpenAI provider clients as the local fallback.
 * The style preset drives a deterministic prompt; when neither backend can
 * serve the request the tool returns an honest wp_mcp_ai_no_provider error
 * instead of claiming success. The _wp_mcp_ai_artistic_style meta is
 * written only when real style transfer happened.
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
 * Apply artistic styles to images with real AI style transfer.
 */
class WP_MCP_AI_Tool_Apply_Artistic_Style extends WP_MCP_AI_Tool_Image_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Sharp_Image_Processing;
	use WP_MCP_AI_Provider_Image_Edit;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'apply_artistic_style';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Apply Artistic Style', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Apply artistic styles to images with real AI style transfer via the Media Worker sidecar or the Gemini/OpenAI provider clients.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Use to transform a photo into artwork with an AI style preset such as van_gogh, watercolor, or pop_art.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Use enhance_image_quality for realistic correction of sharpness and noise, or generate_image_ai to create a new image from a prompt.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'enhance_image_quality', 'generate_image_ai', 'generate_image_variations' ),
			'notes'           => __( 'Style strength is 0-1. Reference style images are accepted for compatibility but not applied yet — the preset prompt drives the style. Requires a Media Worker sidecar or a Gemini/OpenAI API key.', 'mcp-ai-wpoos-pro' ),
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
					'style'       => array(
						'type'        => 'string',
						'description' => __( 'Style preset: "van_gogh", "picasso", "monet", "kandinsky", "ukiyo-e", "pop_art", "watercolor", "oil_painting", "sketch".', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'van_gogh', 'picasso', 'monet', 'kandinsky', 'ukiyo-e', 'pop_art', 'watercolor', 'oil_painting', 'sketch' ),
						'default'     => 'van_gogh',
					),
					'strength'    => array(
						'type'        => 'number',
						'description' => __( 'Style strength (0-1). Higher values apply the style more strongly.', 'mcp-ai-wpoos-pro' ),
						'minimum'     => 0,
						'maximum'     => 1,
						'default'     => 0.8,
					),
					'style_image' => array(
						'type'        => 'object',
						'description' => __( 'Custom style reference image (optional). Accepted for compatibility; not applied yet.', 'mcp-ai-wpoos-pro' ),
						'properties'  => array(
							'attachment_id' => array( 'type' => 'integer' ),
							'url'           => array( 'type' => 'string' ),
						),
					),
					'use_remote'  => array(
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
	 * The style preset → prompt map (single source of truth, mirrored to
	 * the worker's /api/image/edit route).
	 *
	 * @return array Prompt per style preset.
	 */
	protected function get_style_prompts() {
		return array(
			'van_gogh'     => __( 'Restyle this image as an oil painting in the style of Vincent van Gogh, with bold swirling brushstrokes and vivid colors.', 'mcp-ai-wpoos-pro' ),
			'picasso'      => __( 'Restyle this image in the Cubist style of Pablo Picasso, with fragmented geometric forms.', 'mcp-ai-wpoos-pro' ),
			'monet'        => __( 'Restyle this image in the Impressionist style of Claude Monet, with soft light and visible brushstrokes.', 'mcp-ai-wpoos-pro' ),
			'kandinsky'    => __( 'Restyle this image in the abstract style of Wassily Kandinsky, with bold shapes and color fields.', 'mcp-ai-wpoos-pro' ),
			'ukiyo-e'      => __( 'Restyle this image as a traditional Japanese ukiyo-e woodblock print, with flat colors and strong outlines.', 'mcp-ai-wpoos-pro' ),
			'pop_art'      => __( 'Restyle this image in the pop art style, with bold colors, high contrast, and halftone dots.', 'mcp-ai-wpoos-pro' ),
			'watercolor'   => __( 'Restyle this image as a delicate watercolor painting, with soft washes and light pigment.', 'mcp-ai-wpoos-pro' ),
			'oil_painting' => __( 'Restyle this image as a classical oil painting, with rich texture and layered brushwork.', 'mcp-ai-wpoos-pro' ),
			'sketch'       => __( 'Restyle this image as a detailed pencil sketch, monochrome with fine linework.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Strength modifier appended to the preset prompt.
	 *
	 * Deterministic thresholds: strong (>= 0.75) and subtle (<= 0.35)
	 * bands adjust the prompt; the middle band leaves it unchanged.
	 *
	 * @param float $strength Style strength 0-1.
	 * @return string Prompt modifier (may be empty).
	 */
	protected function get_style_strength_modifier( $strength ) {
		if ( $strength >= 0.75 ) {
			return __( ' Apply the style strongly.', 'mcp-ai-wpoos-pro' );
		}
		if ( $strength <= 0.35 ) {
			return __( ' Apply the style subtly.', 'mcp-ai-wpoos-pro' );
		}
		return '';
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
				__( 'You do not have permission to apply styles to images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get and validate the style preset.
		$style   = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'van_gogh';
		$prompts = $this->get_style_prompts();
		if ( ! isset( $prompts[ $style ] ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Unknown style preset. Allowed: van_gogh, picasso, monet, kandinsky, ukiyo-e, pop_art, watercolor, oil_painting, sketch.', 'mcp-ai-wpoos-pro' )
			);
		}

		$strength = isset( $arguments['strength'] ) ? floatval( $arguments['strength'] ) : 0.8;
		$strength = max( 0, min( 1, $strength ) );

		// Reference style images are accepted for compatibility but not
		// applied yet — echoed honestly in the response.
		$style_image_requested = ! empty( $arguments['style_image'] );

		$prompt = $prompts[ $style ] . $this->get_style_strength_modifier( $strength );

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
				__( 'Artistic style transfer requires a Media Worker sidecar or a Gemini/OpenAI API key, and neither is available. Configure a provider key in the NV oOS settings or set up the Media Worker sidecar.', 'mcp-ai-wpoos-pro' )
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
						'operation' => 'style_transfer',
						'style'     => $style,
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
			$base       = wp_tempnam( 'style-output-' );
			$final_path = $base . '.png';
			wp_delete_file( $base );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing provider-returned image bytes to a site temp file.
			if ( false === file_put_contents( $final_path, $edited ) ) {
				$this->cleanup_source_image( $source_image, $arguments );
				return new WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to write the styled image file.', 'mcp-ai-wpoos-pro' ) );
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
					__( 'Style transfer failed on every available backend (%s).', 'mcp-ai-wpoos-pro' ),
					'' !== $last_error ? $last_error : __( 'unknown error', 'mcp-ai-wpoos-pro' )
				)
			);
		}

		// Land the processed file in the media library.
		$parent_id     = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$attachment_id = $this->upload_processed_image( $final_path, $parent_id, __( 'Styled Image', 'mcp-ai-wpoos-pro' ) );
		wp_delete_file( $final_path );

		if ( ! $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_error',
				__( 'Failed to create attachment for the styled image.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Honest metadata: written only after real style transfer succeeded.
		update_post_meta( $attachment_id, '_wp_mcp_ai_artistic_style', $style );
		update_post_meta( $attachment_id, '_wp_mcp_ai_style_strength', $strength );

		$response = $this->format_attachment_response( $attachment_id, $arguments );

		$response['text'] = sprintf(
			/* translators: %s: processing engine */
			__( 'Artistic style applied successfully with %s.', 'mcp-ai-wpoos-pro' ),
			$this->get_engine_label( $engine )
		);
		$response['engine']   = $engine;
		$response['style']    = $style;
		$response['strength'] = $strength;
		$response['prompt']   = $prompt;

		if ( $style_image_requested ) {
			$response['style_image_applied'] = false;
			$response['note']                = __( 'Reference style images are accepted for compatibility but not applied yet; the preset prompt drove the style.', 'mcp-ai-wpoos-pro' );
		}

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
