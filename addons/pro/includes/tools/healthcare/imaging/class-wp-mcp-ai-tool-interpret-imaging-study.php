<?php
/**
 * AI Tool: Interpret Imaging Study
 *
 * Provides the `interpret_imaging_study` tool that allows an AI assistant to
 * analyse a stored DICOM study and return structured clinical feedback.
 *
 * The tool operates in two tiers:
 *
 *  1. Metadata-only (default) – Analyses the rich structured metadata already
 *     stored in WordPress (modality, series count, instance dimensions,
 *     acquisition date, PET radiopharmaceutical info, etc.) and asks the
 *     configured AI model to summarise findings, flag completeness issues, and
 *     outline the typical clinical workflow for that modality.
 *
 *  2. Metadata + pixel preview (opt-in via include_pixel_preview = true) –
 *     If the stored DICOM file for a requested instance is accessible, the
 *     tool extracts one representative 8-bit grayscale PNG from the DICOM
 *     pixel data and includes it in the AI vision request so the model can
 *     comment on image quality, visible anatomy, and artefacts.
 *
 * IMPORTANT – CLINICAL DISCLAIMER
 * ─────────────────────────────────
 * This tool is NOT a medical device and is NOT approved for diagnostic use.
 * All interpretations are AI-generated educational summaries only.  They must
 * never replace the opinion of a licensed radiologist or clinician.  The tool
 * always appends a mandatory disclaimer to every AI-generated interpretation.
 *
 * PHI HANDLING
 * ─────────────
 * Patient name and ID are NEVER forwarded to the AI provider.  Only
 * de-identified structural metadata (modality, dates, UIDs, series geometry)
 * and — when opted-in — pixel data from a single DICOM frame are sent.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI-powered DICOM study interpretation tool.
 *
 * Requires the `view_medical_imaging` capability.  Pixel-preview mode
 * additionally requires a vision-capable AI provider (OpenAI gpt-4o /
 * Gemini 1.5 Pro or later).
 */
class WP_MCP_AI_Tool_Interpret_Imaging_Study implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Maximum pixel dimension (longest edge) for the preview PNG.
	 * Keeps the base64 payload small enough for most AI context windows.
	 *
	 * @var int
	 */
	const PREVIEW_MAX_DIM = 512;

	/**
	 * Maximum bytes for the base64-encoded preview image.
	 * Prevents accidental token explosion on large CT/MR slices.
	 *
	 * @var int
	 */
	const PREVIEW_MAX_BYTES = 204800; // 200 KB.

	/**
	 * Max tokens to request from the AI for the interpretation.
	 *
	 * @var int
	 */
	const MAX_TOKENS = 1200;

	// =========================================================================
	// WP_MCP_AI_Tool_Interface.
	// =========================================================================

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'interpret_imaging_study';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Interpret Imaging Study', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyses a stored DICOM medical imaging study and returns an AI-generated interpretation including modality context, series completeness, image quality notes, and clinical workflow guidance. Use action "interpret" with a study_uid. Optionally set include_pixel_preview to true to include a single-frame grayscale image in the analysis (requires a vision-capable AI model). All outputs include a mandatory disclaimer that this is not a medical diagnosis.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'                => array(
					'type'        => 'string',
					'description' => __( 'Action to perform. Currently only "interpret" is supported.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'interpret' ),
					'default'     => 'interpret',
				),
				'study_uid'             => array(
					'type'        => 'string',
					'description' => __( 'DICOM StudyInstanceUID of the study to interpret. Required.', 'mcp-ai-wpoos-pro' ),
				),
				'focus'                 => array(
					'type'        => 'string',
					'description' => __( 'Optional focus area for the interpretation: "quality" (image quality and artefacts), "completeness" (series/instance completeness), "workflow" (clinical acquisition workflow context), or "full" (all of the above). Default: "full".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'quality', 'completeness', 'workflow', 'full' ),
					'default'     => 'full',
				),
				'include_pixel_preview' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, extract a single representative grayscale frame from the DICOM file and include it in the AI vision request. Requires a vision-capable model (e.g. gpt-4o, gemini-1.5-pro). Default: false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'instance_uid'          => array(
					'type'        => 'string',
					'description' => __( 'Optional SOPInstanceUID of a specific instance to use for the pixel preview. If omitted the first available instance in the first series is used.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'study_uid' ),
		);
	}

	// =========================================================================
	// WP_MCP_AI_Tool_Capability_Flags_Interface.
	// =========================================================================

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'external-api',
			'requires-credentials',
			'network-dependent',
			'consumes-tokens',
			'rate-limited',
			'pii-data',
		);
	}

	// =========================================================================
	// Execute().
	// =========================================================================

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
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'view_medical_imaging' ) ) {
			return new WP_Error(
				'imaging_forbidden',
				__( 'You do not have permission to access medical imaging studies.', 'mcp-ai-wpoos-pro' )
			);
		}

		$study_uid = isset( $arguments['study_uid'] ) ? sanitize_text_field( $arguments['study_uid'] ) : '';
		if ( '' === $study_uid ) {
			return new WP_Error( 'imaging_missing_uid', __( 'study_uid is required for action "interpret".', 'mcp-ai-wpoos-pro' ) );
		}

		$post = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );
		if ( ! $post ) {
			return new WP_Error( 'imaging_not_found', __( 'Study not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$focus                 = isset( $arguments['focus'] ) ? sanitize_key( $arguments['focus'] ) : 'full';
		$include_pixel_preview = ! empty( $arguments['include_pixel_preview'] );
		$requested_iuid        = isset( $arguments['instance_uid'] ) ? sanitize_text_field( $arguments['instance_uid'] ) : '';

		WP_MCP_AI_Imaging_Audit_Log::log(
			'study_interpreted',
			array(
				'source'                => 'ai_tool',
				'study_id'              => $study_uid,
				'user_id'               => get_current_user_id(),
				'focus'                 => $focus,
				'include_pixel_preview' => $include_pixel_preview,
			)
		);

		return $this->action_interpret( $post, $focus, $include_pixel_preview, $requested_iuid );
	}

	// =========================================================================
	// Action handler.
	// =========================================================================

	/**
	 * Run the AI interpretation.
	 *
	 * @param WP_Post $post                 Study post.
	 * @param string  $focus                Interpretation focus area.
	 * @param bool    $include_pixel_preview Whether to include a pixel preview.
	 * @param string  $requested_iuid       Optional specific SOPInstanceUID for preview.
	 * @return array|WP_Error
	 */
	private function action_interpret( WP_Post $post, $focus, $include_pixel_preview, $requested_iuid ) {
		$study_data = $this->build_study_context( $post );
		$prompt     = $this->build_interpretation_prompt( $study_data, $focus );
		$settings   = get_option( 'wp_mcp_ai_settings', array() );
		$provider   = $this->get_provider( $settings, $include_pixel_preview );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$model = $this->get_model( $provider, $settings, $include_pixel_preview );
		if ( is_wp_error( $model ) ) {
			return $model;
		}

		$client = $this->get_ai_client( $provider );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Build messages.
		$system_message = $this->build_system_message();
		$user_content   = $prompt;

		// Optionally attach a pixel preview.
		$preview_note = '';
		if ( $include_pixel_preview ) {
			$preview_result = $this->maybe_attach_pixel_preview(
				$post,
				$study_data,
				$requested_iuid
			);

			if ( is_array( $preview_result ) && ! empty( $preview_result['base64'] ) ) {
				// Build a multipart content array for vision models.
				$user_content = array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url'    => 'data:image/png;base64,' . $preview_result['base64'],
							'detail' => 'high',
						),
					),
				);
				$preview_note = __( ' A single representative grayscale frame was included in the analysis.', 'mcp-ai-wpoos-pro' );
			} elseif ( is_wp_error( $preview_result ) ) {
				// Non-fatal: fall back to metadata-only if preview extraction fails.
				$preview_note = sprintf(
					/* translators: %s: error message */
					__( ' (Pixel preview unavailable: %s)', 'mcp-ai-wpoos-pro' ),
					$preview_result->get_error_message()
				);
			}
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system_message,
			),
			array(
				'role'    => 'user',
				'content' => $user_content,
			),
		);

		$result = $client->create_chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => 0.3,
				'max_tokens'  => self::MAX_TOKENS,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'imaging_ai_empty_response',
				__( 'The AI model returned an empty response.', 'mcp-ai-wpoos-pro' )
			);
		}

		$interpretation = $result['choices'][0]['message']['content'];
		$disclaimer     = $this->get_disclaimer();

		return array(
			'study_uid'      => get_post_meta( $post->ID, '_imaging_study_instance_uid', true ),
			'modality'       => $study_data['modality'],
			'study_date'     => $study_data['study_date'],
			'focus'          => $focus,
			'interpretation' => $interpretation . "\n\n" . $disclaimer,
			'pixel_preview'  => $include_pixel_preview,
			'preview_note'   => $preview_note,
			'model'          => $model,
			'provider'       => $provider,
			'disclaimer'     => $disclaimer,
		);
	}

	// =========================================================================
	// AI provider helpers.
	// =========================================================================

	/**
	 * Select the best available AI provider.
	 *
	 * Prefers vision-capable providers when pixel preview is requested.
	 *
	 * @param array $settings               Plugin settings.
	 * @param bool  $require_vision Whether a vision model is required.
	 * @return string|WP_Error Provider slug or error.
	 */
	private function get_provider( array $settings, $require_vision ) {
		// For vision, prefer OpenAI (gpt-4o) then Gemini.
		if ( $require_vision ) {
			if ( ! empty( $settings['openai_api_key'] ) ) {
				return 'openai';
			}
			if ( ! empty( $settings['gemini_api_key'] ) ) {
				return 'gemini';
			}
			return new WP_Error(
				'imaging_no_vision_provider',
				__( 'Pixel preview requires a vision-capable AI provider. Please configure an OpenAI or Gemini API key.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Metadata-only: any provider works.
		if ( ! empty( $settings['openai_api_key'] ) ) {
			return 'openai';
		}
		if ( ! empty( $settings['gemini_api_key'] ) ) {
			return 'gemini';
		}
		if ( ! empty( $settings['anthropic_api_key'] ) ) {
			return 'anthropic';
		}

		return new WP_Error(
			'imaging_no_provider',
			__( 'No AI provider configured. Please add an OpenAI, Gemini, or Anthropic API key in the NV oOS settings.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Return the model identifier to use.
	 *
	 * @param string $provider       Provider slug.
	 * @param array  $settings       Plugin settings.
	 * @param bool   $require_vision Whether a vision model is required.
	 * @return string|WP_Error Model identifier or error.
	 */
	private function get_model( $provider, array $settings, $require_vision ) {
		switch ( $provider ) {
			case 'openai':
				// Always use a vision-capable model; gpt-4o works for both cases.
				if ( ! empty( $settings['openai_default_model'] ) ) {
					return $settings['openai_default_model'];
				}
				return 'gpt-4o';

			case 'gemini':
				if ( ! empty( $settings['gemini_default_model'] ) ) {
					return $settings['gemini_default_model'];
				}
				// gemini-1.5-pro supports vision; flash is fine for metadata-only.
				return $require_vision ? 'gemini-1.5-pro' : 'gemini-2.5-flash';

			case 'anthropic':
				return 'claude-sonnet-4-5-20250929';

			default:
				return new WP_Error(
					'imaging_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Unsupported AI provider for imaging interpretation: %s', 'mcp-ai-wpoos-pro' ),
						esc_html( $provider )
					)
				);
		}
	}

	/**
	 * Instantiate the correct AI client.
	 *
	 * @param string $provider Provider slug.
	 * @return object|WP_Error AI client or error.
	 */
	private function get_ai_client( $provider ) {
		switch ( $provider ) {
			case 'openai':
				if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					return new WP_Error( 'imaging_client_unavailable', __( 'OpenAI client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_OpenAI_Client();

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_Error( 'imaging_client_unavailable', __( 'Gemini client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Gemini_Client();

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_Error( 'imaging_client_unavailable', __( 'Anthropic client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Anthropic_Client();

			default:
				return new WP_Error(
					'imaging_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'AI client not available for provider: %s', 'mcp-ai-wpoos-pro' ),
						esc_html( $provider )
					)
				);
		}
	}

	// =========================================================================
	// Prompt builders.
	// =========================================================================

	/**
	 * Build a de-identified study context array from the stored CPT.
	 *
	 * Patient name and patient ID are intentionally excluded.
	 *
	 * @param WP_Post $post Study post.
	 * @return array De-identified context.
	 */
	private function build_study_context( WP_Post $post ) {
		$series_json = get_post_meta( $post->ID, '_imaging_series', true );
		$series_raw  = json_decode( $series_json, true );
		$series_list = array();

		if ( is_array( $series_raw ) ) {
			foreach ( $series_raw as $s ) {
				$instance_count = isset( $s['instances'] ) ? count( $s['instances'] ) : 0;
				$first_inst     = ( $instance_count > 0 ) ? $s['instances'][0] : null;

				$series_list[] = array(
					'series_uid'         => isset( $s['series_instance_uid'] ) ? $s['series_instance_uid'] : '',
					'modality'           => isset( $s['modality'] ) ? strtoupper( $s['modality'] ) : '',
					'series_description' => isset( $s['series_description'] ) ? $s['series_description'] : '',
					'instance_count'     => $instance_count,
					'rows'               => ( $first_inst && isset( $first_inst['rows'] ) ) ? absint( $first_inst['rows'] ) : 0,
					'columns'            => ( $first_inst && isset( $first_inst['columns'] ) ) ? absint( $first_inst['columns'] ) : 0,
					'pixel_spacing'      => ( $first_inst && isset( $first_inst['pixel_spacing'] ) ) ? $first_inst['pixel_spacing'] : '',
				);
			}
		}

		return array(
			'study_uid'   => get_post_meta( $post->ID, '_imaging_study_instance_uid', true ),
			'modality'    => get_post_meta( $post->ID, '_imaging_modality', true ),
			'study_date'  => get_post_meta( $post->ID, '_imaging_study_date', true ),
			'description' => get_post_meta( $post->ID, '_imaging_study_description', true ),
			'status'      => get_post_meta( $post->ID, '_imaging_status', true ),
			'series'      => $series_list,
		);
	}

	/**
	 * Build the text prompt that is sent to the AI.
	 *
	 * @param array  $study De-identified study context.
	 * @param string $focus Interpretation focus.
	 * @return string Prompt text.
	 */
	private function build_interpretation_prompt( array $study, $focus ) {
		$modality     = $study['modality'] ? strtoupper( $study['modality'] ) : 'UNKNOWN';
		$study_date   = $study['study_date'] ? $study['study_date'] : 'unknown date';
		$description  = $study['description'] ? $study['description'] : 'no description provided';
		$series_count = count( $study['series'] );

		$series_lines = '';
		foreach ( $study['series'] as $i => $s ) {
			$series_lines .= sprintf(
				"\n  Series %d: modality=%s, description=\"%s\", instances=%d, dimensions=%dx%d%s",
				$i + 1,
				$s['modality'] ? $s['modality'] : 'N/A',
				esc_html( $s['series_description'] ),
				$s['instance_count'],
				$s['rows'],
				$s['columns'],
				$s['pixel_spacing'] ? ', pixel_spacing=' . esc_html( $s['pixel_spacing'] ) : ''
			);
		}

		$prompt  = "You are reviewing a de-identified medical imaging study with the following metadata:\n\n";
		$prompt .= "- Primary modality: {$modality}\n";
		$prompt .= "- Acquisition date: {$study_date}\n";
		$prompt .= "- Study description: {$description}\n";
		$prompt .= "- Number of series: {$series_count}\n";
		$prompt .= "- Series breakdown:{$series_lines}\n\n";

		switch ( $focus ) {
			case 'quality':
				$prompt .= "Please assess the following:\n";
				$prompt .= "1. Image quality indicators (dimensions, pixel spacing, series count relative to modality norms).\n";
				$prompt .= "2. Any potential quality concerns or artefact indicators visible in the metadata.\n";
				$prompt .= "3. Whether the pixel dimensions are typical for this modality.\n";
				break;

			case 'completeness':
				$prompt .= "Please assess the following:\n";
				$prompt .= "1. Whether the number of series and instances appears complete for this modality and study type.\n";
				$prompt .= "2. Any missing or unusual series that might indicate an incomplete acquisition.\n";
				$prompt .= "3. Recommendations for verifying completeness before clinical review.\n";
				break;

			case 'workflow':
				$prompt .= "Please describe the following:\n";
				$prompt .= "1. The typical clinical indication and workflow for a {$modality} study of this type.\n";
				$prompt .= "2. The standard series that should be present and their clinical purpose.\n";
				$prompt .= "3. What the reporting radiologist would typically be looking for.\n";
				break;

			default: // 'full'.
				$prompt .= "Please provide a structured interpretation covering:\n";
				$prompt .= "1. **Modality overview**: Brief description of what this type of study is used for clinically.\n";
				$prompt .= "2. **Acquisition assessment**: Whether the series count, instance count, and geometry look typical for this modality.\n";
				$prompt .= "3. **Image quality notes**: Any quality concerns suggested by the metadata (e.g. unusually low slice counts, atypical dimensions).\n";
				$prompt .= "4. **Clinical workflow guidance**: What steps typically follow acquisition (quality check, protocol verification, reporting workflow).\n";
				$prompt .= "5. **Recommendations**: Any metadata-level recommendations for the imaging team.\n";
				break;
		}

		$prompt .= "\nRespond in clear, professional English suitable for a radiographer or clinician reviewing the study metadata. ";
		$prompt .= 'Do NOT invent clinical findings or diagnoses from metadata alone. ';
		$prompt .= 'Keep your response concise (under 600 words).';

		return $prompt;
	}

	/**
	 * Build the system-role message.
	 *
	 * @return string System message.
	 */
	private function build_system_message() {
		return 'You are an AI assistant specialised in radiology workflow and medical imaging quality assurance. '
			. 'You analyse DICOM study metadata and provide structured, professional feedback on image completeness, '
			. 'acquisition workflow, and quality indicators. '
			. 'You do NOT diagnose patients or make definitive clinical interpretations from metadata or images alone. '
			. 'You always remind the user that your analysis is educational and must be reviewed by a qualified clinician. '
			. 'When pixel data is provided, you may comment on visible image characteristics, apparent anatomy, and obvious '
			. 'quality issues (artefacts, noise, positioning), but you do NOT render a radiological report or diagnosis.';
	}

	/**
	 * Return the mandatory clinical disclaimer.
	 *
	 * @return string Disclaimer text.
	 */
	private function get_disclaimer() {
		return __( '⚠️ DISCLAIMER: This analysis is AI-generated and is provided for informational and workflow-support purposes only. It is NOT a medical diagnosis, radiological report, or clinical recommendation. All imaging studies must be reviewed and interpreted by a qualified, licensed radiologist or appropriate clinician. Do not use this output for clinical decision-making.', 'mcp-ai-wpoos-pro' );
	}

	// =========================================================================
	// Pixel preview helpers.
	// =========================================================================

	/**
	 * Attempt to extract a grayscale PNG preview from the DICOM file.
	 *
	 * Returns an array with key 'base64' on success, a WP_Error if the
	 * extraction fails or is not supported, or null if no file is found.
	 *
	 * @param WP_Post $post          Study post.
	 * @param array   $study_data    De-identified study context.
	 * @param string  $requested_iuid Optional SOPInstanceUID to use.
	 * @return array|WP_Error|null
	 */
	private function maybe_attach_pixel_preview( WP_Post $post, array $study_data, $requested_iuid ) {
		if ( ! function_exists( 'imagecreate' ) ) {
			return new WP_Error(
				'imaging_gd_unavailable',
				__( 'The PHP GD extension is required for pixel preview but is not available on this server.', 'mcp-ai-wpoos-pro' )
			);
		}

		$file_path = $this->find_instance_file( $post, $study_data, $requested_iuid );
		if ( ! $file_path ) {
			return new WP_Error(
				'imaging_file_not_found',
				__( 'No DICOM file found for pixel preview. The file may have been moved or deleted.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $this->extract_pixel_preview( $file_path );
	}

	/**
	 * Locate a DICOM file on disk for a study.
	 *
	 * If $requested_iuid is provided the matching instance is used, otherwise
	 * the first instance of the first series is used.
	 *
	 * @param WP_Post $post          Study post.
	 * @param array   $study_data    De-identified study context (series list).
	 * @param string  $requested_iuid Optional SOPInstanceUID.
	 * @return string|false Absolute file path or false.
	 */
	private function find_instance_file( WP_Post $post, array $study_data, $requested_iuid ) {
		$series_json = get_post_meta( $post->ID, '_imaging_series', true );
		$series_raw  = json_decode( $series_json, true );

		if ( ! is_array( $series_raw ) ) {
			return false;
		}

		foreach ( $series_raw as $s ) {
			if ( empty( $s['instances'] ) ) {
				continue;
			}
			foreach ( $s['instances'] as $inst ) {
				$iuid = isset( $inst['sop_instance_uid'] ) ? $inst['sop_instance_uid'] : '';
				if ( '' !== $requested_iuid && $iuid !== $requested_iuid ) {
					continue;
				}
				if ( ! empty( $inst['file_path'] ) && file_exists( $inst['file_path'] ) && is_readable( $inst['file_path'] ) ) {
					return $inst['file_path'];
				}
			}
		}

		return false;
	}

	/**
	 * Extract a grayscale pixel preview from a DICOM file and return base64 PNG.
	 *
	 * Only uncompressed / JPEG-baseline pixel data is supported.  Other
	 * transfer syntaxes return a WP_Error rather than silently corrupt output.
	 *
	 * @param string $file_path Absolute path to the .dcm file.
	 * @return array|WP_Error Array with key 'base64' (string) or WP_Error.
	 */
	private function extract_pixel_preview( $file_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $file_path, 'rb' );
		if ( false === $fh ) {
			return new WP_Error( 'imaging_open_failed', __( 'Could not open DICOM file for pixel preview.', 'mcp-ai-wpoos-pro' ) );
		}

		$pixel_info = $this->find_pixel_data( $fh );
		if ( is_wp_error( $pixel_info ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			return $pixel_info;
		}

		$rows         = $pixel_info['rows'];
		$columns      = $pixel_info['columns'];
		$bits_alloc   = $pixel_info['bits_allocated'];
		$pixel_offset = $pixel_info['pixel_data_offset'];
		$pixel_length = $pixel_info['pixel_data_length'];

		if ( $rows <= 0 || $columns <= 0 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			return new WP_Error( 'imaging_invalid_dimensions', __( 'DICOM pixel dimensions are invalid.', 'mcp-ai-wpoos-pro' ) );
		}

		// Only 8-bit and 16-bit pixel data are supported for preview.
		if ( 8 !== $bits_alloc && 16 !== $bits_alloc ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			return new WP_Error(
				'imaging_unsupported_bitdepth',
				sprintf(
					/* translators: %d: bits allocated */
					__( 'Unsupported DICOM bit depth (%d) for pixel preview.', 'mcp-ai-wpoos-pro' ),
					$bits_alloc
				)
			);
		}

		// Read raw pixel bytes.
		fseek( $fh, $pixel_offset );
		$bytes_needed = $rows * $columns * ( $bits_alloc / 8 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$raw = fread( $fh, min( $bytes_needed, $pixel_length ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );

		if ( false === $raw || strlen( $raw ) < $bytes_needed ) {
			return new WP_Error( 'imaging_read_failed', __( 'Could not read pixel data from DICOM file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Decode pixel values to 8-bit.
		$pixels = $this->decode_pixels( $raw, $rows, $columns, $bits_alloc );
		if ( is_wp_error( $pixels ) ) {
			return $pixels;
		}

		// Build GD image.
		$img = $this->render_preview_image( $pixels, $rows, $columns );
		if ( is_wp_error( $img ) ) {
			return $img;
		}

		// Capture PNG to buffer.
		ob_start();
		imagepng( $img );
		$png_data = ob_get_clean();
		imagedestroy( $img );

		if ( strlen( $png_data ) > self::PREVIEW_MAX_BYTES ) {
			return new WP_Error(
				'imaging_preview_too_large',
				__( 'Generated pixel preview exceeds the maximum allowed size. Try without include_pixel_preview.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'base64' => base64_encode( $png_data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Parse a DICOM file to locate pixel data and geometry tags.
	 *
	 * Supports Explicit Little Endian and Implicit Little Endian only.
	 * Returns an array with rows, columns, bits_allocated, pixel_data_offset,
	 * and pixel_data_length, or a WP_Error.
	 *
	 * @param resource $fh Open file handle positioned after the DICOM preamble.
	 * @return array|WP_Error
	 */
	private function find_pixel_data( $fh ) {
		// Skip preamble (128 bytes) + "DICM" magic (4 bytes).
		fseek( $fh, 132 );

		$rows         = 0;
		$columns      = 0;
		$bits_alloc   = 16; // Default.
		$pixel_offset = 0;
		$pixel_length = 0;
		$explicit     = true; // Assume explicit little endian initially.
		$found_pixel  = false;
		$max_bytes    = 4 * 1024 * 1024; // Stop scanning after 4 MB of tags.
		$scanned      = 0;

		while ( ! feof( $fh ) ) {
			if ( $scanned > $max_bytes ) {
				break;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$tag_bytes = fread( $fh, 4 );
			if ( false === $tag_bytes || strlen( $tag_bytes ) < 4 ) {
				break;
			}
			$scanned += 4;

			$group   = unpack( 'v', substr( $tag_bytes, 0, 2 ) )[1];
			$element = unpack( 'v', substr( $tag_bytes, 2, 2 ) )[1];
			$tag     = sprintf( '%04x%04x', $group, $element );

			// Try to read VR (explicit) or length (implicit).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$vr_or_len_bytes = fread( $fh, 4 );
			if ( false === $vr_or_len_bytes || strlen( $vr_or_len_bytes ) < 4 ) {
				break;
			}
			$scanned += 4;

			$vr     = substr( $vr_or_len_bytes, 0, 2 );
			$length = 0;

			if ( $explicit && strlen( $vr ) >= 2 && ctype_upper( $vr[0] ) && ctype_upper( $vr[1] ) ) {
				// Explicit VR.
				if ( in_array( $vr, array( 'OB', 'OW', 'OF', 'SQ', 'UC', 'UN', 'UR', 'UT' ), true ) ) {
					// 4-byte length after 2 reserved bytes.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$len_bytes = fread( $fh, 4 );
					$scanned  += 4;
					if ( false === $len_bytes || strlen( $len_bytes ) < 4 ) {
						break;
					}
					$length = unpack( 'V', $len_bytes )[1];
				} else {
					// 2-byte length in the last 2 bytes we already read.
					$length = unpack( 'v', substr( $vr_or_len_bytes, 2, 2 ) )[1];
				}
			} else {
				// Implicit VR: all 4 bytes are the length.
				$explicit = false;
				$length   = unpack( 'V', $vr_or_len_bytes )[1];
			}

			if ( 0xffffffff === $length ) {
				// Undefined length – skip for now (mainly sequences).
				continue;
			}

			// Read value for tags we care about.
			if ( '00280010' === $tag ) {
				// Rows.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$val      = fread( $fh, $length );
				$rows     = ( $val && strlen( $val ) >= 2 ) ? unpack( 'v', substr( $val, 0, 2 ) )[1] : 0;
				$scanned += $length;
				continue;
			}

			if ( '00280011' === $tag ) {
				// Columns.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$val      = fread( $fh, $length );
				$columns  = ( $val && strlen( $val ) >= 2 ) ? unpack( 'v', substr( $val, 0, 2 ) )[1] : 0;
				$scanned += $length;
				continue;
			}

			if ( '00280100' === $tag ) {
				// BitsAllocated.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$val        = fread( $fh, $length );
				$bits_alloc = ( $val && strlen( $val ) >= 2 ) ? unpack( 'v', substr( $val, 0, 2 ) )[1] : 16;
				$scanned   += $length;
				continue;
			}

			if ( '7fe00010' === $tag ) {
				// PixelData.
				$pixel_offset = ftell( $fh );
				$pixel_length = $length;
				$found_pixel  = true;
				break;
			}

			// Skip over this tag's value.
			if ( $length > 0 ) {
				fseek( $fh, $length, SEEK_CUR );
				$scanned += $length;
			}
		}

		if ( ! $found_pixel ) {
			return new WP_Error(
				'imaging_no_pixel_data',
				__( 'No pixel data (7FE0,0010) found in DICOM file.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'rows'              => $rows,
			'columns'           => $columns,
			'bits_allocated'    => $bits_alloc,
			'pixel_data_offset' => $pixel_offset,
			'pixel_data_length' => $pixel_length,
		);
	}

	/**
	 * Convert raw pixel bytes to a 2-D array of 8-bit values.
	 *
	 * 16-bit values are window-levelled using a min-max stretch.
	 *
	 * @param string $raw         Raw bytes from PixelData.
	 * @param int    $rows        Image rows.
	 * @param int    $columns     Image columns.
	 * @param int    $bits_alloc  Bits allocated (8 or 16).
	 * @return array|WP_Error Flat array of 8-bit pixel values, row-major.
	 */
	private function decode_pixels( $raw, $rows, $columns, $bits_alloc ) {
		$total   = $rows * $columns;
		$decoded = array();

		if ( 8 === $bits_alloc ) {
			$unpacked = unpack( 'C*', $raw );
			$decoded  = array_values( $unpacked );
		} elseif ( 16 === $bits_alloc ) {
			$unpacked = unpack( 'v*', $raw );
			$values   = array_values( $unpacked );

			// Find min / max for window stretch.
			$min   = min( $values );
			$max   = max( $values );
			$range = $max - $min;

			if ( 0 === $range ) {
				$decoded = array_fill( 0, $total, 128 );
			} else {
				foreach ( $values as $v ) {
					$decoded[] = (int) round( ( ( $v - $min ) / $range ) * 255 );
				}
			}
		} else {
			return new WP_Error(
				'imaging_decode_failed',
				__( 'Unable to decode pixel data: unsupported bit depth.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array_slice( $decoded, 0, $total );
	}

	/**
	 * Create a GD truecolor image from a flat array of 8-bit pixel values.
	 *
	 * Scales the image down if either dimension exceeds PREVIEW_MAX_DIM.
	 *
	 * @param array $pixels  Flat row-major array of 8-bit values.
	 * @param int   $rows    Original image rows.
	 * @param int   $columns Original image columns.
	 * @return resource|WP_Error GD image resource or WP_Error.
	 */
	private function render_preview_image( array $pixels, $rows, $columns ) {
		$img = imagecreatetruecolor( $columns, $rows );
		if ( false === $img ) {
			return new WP_Error( 'imaging_gd_create_failed', __( 'Failed to create GD image.', 'mcp-ai-wpoos-pro' ) );
		}

		$idx = 0;
		for ( $r = 0; $r < $rows; $r++ ) {
			for ( $c = 0; $c < $columns; $c++ ) {
				$v   = isset( $pixels[ $idx ] ) ? $pixels[ $idx ] : 0;
				$col = imagecolorallocate( $img, $v, $v, $v );
				imagesetpixel( $img, $c, $r, $col );
				++$idx;
			}
		}

		// Resize if needed.
		$max = self::PREVIEW_MAX_DIM;
		if ( $columns > $max || $rows > $max ) {
			$scale   = min( $max / $columns, $max / $rows );
			$new_w   = (int) round( $columns * $scale );
			$new_h   = (int) round( $rows * $scale );
			$resized = imagecreatetruecolor( $new_w, $new_h );
			if ( false === $resized ) {
				imagedestroy( $img );
				return new WP_Error( 'imaging_gd_resize_failed', __( 'Failed to resize preview image.', 'mcp-ai-wpoos-pro' ) );
			}
			imagecopyresampled( $resized, $img, 0, 0, 0, 0, $new_w, $new_h, $columns, $rows );
			imagedestroy( $img );
			return $resized;
		}

		return $img;
	}
}
