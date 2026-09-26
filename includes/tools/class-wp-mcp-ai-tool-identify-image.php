<?php
/**
 * Tool for orchestrating the non-LLM image identification ladder.
 *
 * Runs the cheap-first escalation ladder in order — metadata, perceptual-hash
 * media lookup, classic Cloud Vision detection, layout composition, and
 * optional reverse-image web search — and returns a composite envelope with a
 * confidence score. This tool NEVER invokes a vision language model; when the
 * ladder is insufficient it tells the model to fall back to analyze_image.
 *
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cloud-vision-client.php';
require_once WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-image-dhash.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-image-metadata.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-find-similar-media.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-detect-image-content.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-describe-image-layout.php';

/**
 * Provides an assistant tool that identifies an image through the
 * deterministic ladder, without any vision-LLM call.
 *
 * Every external rung is key-gated: when credentials are absent the rung is
 * reported as `skipped` with a reason — user image data never leaves the
 * server without configured credentials.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_Identify_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Attachment_File_Resolver;

	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Confidence floor above which the ladder is considered sufficient.
	 */
	const SUFFICIENT_CONFIDENCE = 0.6;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'identify_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Identify Image', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Identifies what an image shows using a cheap-first, deterministic ladder: WordPress metadata, perceptual-hash media-library lookup, classic (non-LLM) Cloud Vision detection, layout description, and optional reverse-image web search. Never calls a vision LLM — returns a confidence score and an escalation hint so the model only falls back to analyze_image when genuinely needed.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Any "what is this image?" question. Run this first; it answers most identification questions for free or near-free, and its escalation_hint tells you whether to spend vision tokens.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Open-ended visual reasoning or aesthetics questions — go straight to analyze_image. Also avoid when you already know the image needs free-form description.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'analyze_image', 'get_image_metadata', 'find_similar_media', 'detect_image_content', 'describe_image_layout', 'search_similar_images' ),
			'notes'           => __( 'External rungs require credentials (Gemini key for detection, Bing/SerpApi key for web search) and are skipped — never errors — when absent. Web search is opt-in per call and sends image bytes to the provider.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id'      => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID of the image to identify.', 'mcp-ai-wpoos' ),
				),
				'image_url'          => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'URL of the image to identify. Public URLs are required for detection and web search.', 'mcp-ai-wpoos' ),
				),
				'image_content'      => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image content as an alternative input.', 'mcp-ai-wpoos' ),
				),
				'include_web_search' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, also performs reverse-image web search (sends the image to Bing/SerpApi; requires Pro and credentials). Default false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'include_layout'     => array(
					'type'        => 'boolean',
					'description' => __( 'When true, includes a text layout description of detected objects. Default true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array(),
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

		$required_capability = apply_filters(
			'wp_mcp_ai_identify_image_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_identify_image_forbidden',
				__( 'You do not have permission to use Identify Image.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		$image_url          = isset( $arguments['image_url'] ) ? esc_url_raw( $arguments['image_url'] ) : '';
		$image_content      = isset( $arguments['image_content'] ) ? sanitize_text_field( $arguments['image_content'] ) : '';
		$include_web_search = isset( $arguments['include_web_search'] ) && filter_var( $arguments['include_web_search'], FILTER_VALIDATE_BOOLEAN );
		$include_layout     = ! isset( $arguments['include_layout'] ) || filter_var( $arguments['include_layout'], FILTER_VALIDATE_BOOLEAN );

		if ( empty( $image_url ) && empty( $image_content ) && empty( $arguments['attachment_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_identify_image_missing_input',
				__( 'One of attachment_id, image_url, or image_content must be provided.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$attachment_id = 0;

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$resolved = $this->resolve_attachment_id( $arguments, 'attachment_id' );

			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			if ( is_int( $resolved ) && $resolved > 0 ) {
				$attachment_id = $resolved;
			}
		} elseif ( ! empty( $image_url ) ) {
			$resolved_id = attachment_url_to_postid( $image_url );

			if ( $resolved_id > 0 && 'attachment' === get_post_type( $resolved_id ) ) {
				$attachment_id = $resolved_id;
			}
		}

		$data    = array();
		$skipped = array();

		// ── Rung 1: WordPress metadata (free, local) ─────────────
		if ( $attachment_id > 0 ) {
			$metadata_tool   = new WP_MCP_AI_Tool_Get_Image_Metadata();
			$metadata_result = $metadata_tool->execute(
				array( 'attachment_id' => $attachment_id ),
				$context
			);

			if ( ! is_wp_error( $metadata_result ) && ! empty( $metadata_result['attachment_id'] ) ) {
				// Sibling tools flatten array data into their envelope — store the
				// rung payload without the envelope bookkeeping keys.
				unset( $metadata_result['success'], $metadata_result['message'] );
				$data['metadata'] = $metadata_result;
			} else {
				$skipped['metadata'] = 'unavailable';
			}
		} else {
			$skipped['metadata'] = 'no_local_attachment';
		}

		// ── Rung 2: perceptual-hash media lookup (free, local) ──
		if ( $attachment_id > 0 || ! empty( $image_url ) ) {
			$similar_tool   = new WP_MCP_AI_Tool_Find_Similar_Media();
			$similar_result = $similar_tool->execute(
				$attachment_id > 0
					? array(
						'attachment_id' => $attachment_id,
						'threshold'     => 10,
						'limit'         => 5,
					)
					: array(
						'image_url' => $image_url,
						'threshold' => 10,
						'limit'     => 5,
					),
				$context
			);

			if ( ! is_wp_error( $similar_result ) && isset( $similar_result['matches'] ) ) {
				// Sibling tools flatten array data into their envelope — store the
				// rung payload without the envelope bookkeeping keys.
				unset( $similar_result['success'], $similar_result['message'] );
				$data['media_matches'] = $similar_result;
			} else {
				$skipped['media_lookup'] = is_wp_error( $similar_result ) ? $similar_result->get_error_code() : 'unavailable';
			}
		} else {
			$skipped['media_lookup'] = 'requires_local_or_url_input';
		}

		// ── Rung 3: classic Cloud Vision detection (key-gated) ──
		$vision_client = new WP_MCP_AI_Cloud_Vision_Client();
		$api_key       = $vision_client->get_api_key( $context, $arguments );

		if ( empty( $api_key ) ) {
			$skipped['detection'] = 'no_cloud_vision_credentials';

			// Layout is included by default but derives from the detection
			// response — record it so the envelope is complete.
			$skipped['layout'] = $include_layout ? 'no_cloud_vision_credentials' : 'not_requested';
		} else {
			$image = $vision_client->build_image_source( $image_url, $image_content );

			if ( $attachment_id > 0 && empty( $image ) ) {
				$image = $vision_client->build_image_source( (string) wp_get_attachment_url( $attachment_id ), '' );
			}

			if ( empty( $image ) ) {
				$skipped['detection'] = 'no_image_source';
				$skipped['layout']    = $include_layout ? 'no_image_source' : 'not_requested';
			} else {
				$features = array(
					array(
						'type'       => 'LABEL_DETECTION',
						'maxResults' => 10,
					),
					array(
						'type'       => 'TEXT_DETECTION',
						'maxResults' => 10,
					),
					array(
						'type'       => 'WEB_DETECTION',
						'maxResults' => 10,
					),
				);

				if ( $include_layout ) {
					$features[] = array(
						'type'       => 'OBJECT_LOCALIZATION',
						'maxResults' => 20,
					);
				}

				$decoded = $vision_client->annotate( $features, $image, $context, $arguments, $this );

				if ( is_wp_error( $decoded ) ) {
					$skipped['detection'] = $decoded->get_error_code();
					$skipped['layout']    = $include_layout ? 'detection_failed' : 'not_requested';
				} else {
					$data['detections'] = WP_MCP_AI_Tool_Detect_Image_Content::normalize_response(
						$decoded,
						array( 'labels', 'text', 'web_entities' )
					);

					// ── Rung 3b: layout composition from the same response ──
					if ( $include_layout ) {
						$layout_tool   = new WP_MCP_AI_Tool_Describe_Image_Layout();
						$layout_result = $layout_tool->execute(
							array( 'source_tool_result' => wp_json_encode( $decoded ) ),
							$context
						);

						if ( ! is_wp_error( $layout_result ) && ! empty( $layout_result['object_count'] ) ) {
							// Sibling tools flatten array data into their envelope — store the
							// rung payload without the envelope bookkeeping keys.
							unset( $layout_result['success'], $layout_result['message'] );
							$data['layout_summary'] = $layout_result;
						} else {
							$skipped['layout'] = is_wp_error( $layout_result ) ? $layout_result->get_error_code() : 'no_objects_detected';
						}
					} else {
						$skipped['layout'] = 'not_requested';
					}
				}
			}
		}

		// ── Rung 4: reverse-image web search (opt-in, Pro, key-gated) ──
		if ( $include_web_search ) {
			if ( class_exists( 'WP_MCP_AI_Tool_Search_Similar_Images' ) ) {
				if ( method_exists( 'WP_MCP_AI_Tool_Search_Similar_Images', 'has_credentials' )
					&& WP_MCP_AI_Tool_Search_Similar_Images::has_credentials() ) {
					$search_tool = new WP_MCP_AI_Tool_Search_Similar_Images();
					$search_args = array();

					if ( ! empty( $image_url ) ) {
						$search_args['image_url'] = $image_url;
					} elseif ( $attachment_id > 0 ) {
						$search_args['attachment_id'] = $attachment_id;
					} else {
						$search_args['image_content'] = $image_content;
					}

					$search_result = $search_tool->execute( $search_args, $context );

					if ( ! is_wp_error( $search_result ) && ! empty( $search_result['match_count'] ) ) {
						// Sibling tools flatten array data into their envelope — store the
						// rung payload without the envelope bookkeeping keys.
						unset( $search_result['success'], $search_result['message'] );
						$data['web_matches'] = $search_result;
					} else {
						$skipped['web_search'] = is_wp_error( $search_result ) ? $search_result->get_error_code() : 'unavailable';
					}
				} else {
					$skipped['web_search'] = 'no_web_search_credentials';
				}
			} else {
				$skipped['web_search'] = 'pro_addon_required';
			}
		} else {
			$skipped['web_search'] = 'not_requested';
		}

		$confidence      = $this->compute_confidence( $data );
		$escalation_hint = $this->escalation_hint_for( $confidence, $data );

		$payload = array(
			'completed_rungs' => array_keys( $data ),
			'skipped'         => $skipped,
			'confidence'      => $confidence,
			'escalation_hint' => $escalation_hint,
		);

		$payload = array_merge( $payload, $data );

		return $this->format_success_response(
			sprintf(
				/* translators: %s: escalation hint */
				__( 'Image identification completed. Escalation hint: %s', 'mcp-ai-wpoos' ),
				$escalation_hint
			),
			$payload
		);
	}

	/**
	 * Compute a weighted confidence score across completed rungs.
	 *
	 * @param array $data Completed rung data.
	 * @return float Confidence in the 0–1 range.
	 */
	private function compute_confidence( array $data ) {
		$confidence = 0.0;

		// Exact or near-exact media matches are decisive.
		if ( ! empty( $data['media_matches']['matches'] ) ) {
			$best = $data['media_matches']['matches'][0];

			if ( 0 === $best['distance'] ) {
				return 1.0;
			}

			$confidence += 0.6;
		}

		// Metadata that carries descriptive text is a strong signal.
		if ( ! empty( $data['metadata'] ) ) {
			$descriptive = array_filter(
				array(
					$data['metadata']['alt_text'],
					$data['metadata']['caption'],
					$data['metadata']['description'],
					$data['metadata']['title'],
				)
			);

			if ( ! empty( $descriptive ) ) {
				$confidence += 0.4;
			} else {
				$confidence += 0.1;
			}
		}

		// Classic detection: weight by the strongest label score.
		if ( ! empty( $data['detections']['labels'] ) ) {
			$top_score = 0.0;

			foreach ( $data['detections']['labels'] as $label ) {
				if ( $label['score'] > $top_score ) {
					$top_score = $label['score'];
				}
			}

			$confidence += 0.5 * $top_score;
		}

		// Layout composition adds mild corroboration.
		if ( ! empty( $data['layout_summary']['object_count'] ) ) {
			$confidence += 0.1;
		}

		// Web matches corroborate but never decide alone.
		if ( ! empty( $data['web_matches'] ) ) {
			$confidence += 0.3;
		}

		return round( min( 1.0, $confidence ), 2 );
	}

	/**
	 * Decide whether the ladder result is sufficient or needs a vision LLM.
	 *
	 * @param float $confidence Computed confidence.
	 * @param array $data       Completed rung data.
	 * @return string 'sufficient' or 'suggest_analyze_image'.
	 */
	private function escalation_hint_for( $confidence, array $data ) {
		if ( $confidence >= self::SUFFICIENT_CONFIDENCE ) {
			return 'sufficient';
		}

		if ( ! empty( $data['media_matches']['matches'] ) ) {
			return 'sufficient';
		}

		return 'suggest_analyze_image';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_processing',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'data_scientist', 'researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires user capabilities.
			'external-api',         // Optional external rungs (key-gated).
			'cacheable',            // Deterministic when external rungs are absent.
		);
	}
}
