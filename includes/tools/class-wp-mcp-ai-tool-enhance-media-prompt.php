<?php
/**
 * Tool that assembles generative-media prompts deterministically.
 *
 * Applies photographic modifiers (camera, lens, focal length, aperture),
 * a visual style preset, optional quality tags, and ordinal reference-identity
 * instructions. No provider call, no token cost, no non-determinism: the same
 * arguments always produce the same prompt, which makes the operation free,
 * reproducible, and fully unit testable.
 *
 * The composed prompt is returned alongside the reference instructions as a
 * separate field so the caller can inspect, reorder, or omit either part
 * instead of trusting an opaque rewrite.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-prompt-craft.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Composes a media-generation prompt from structured inputs.
 */
class WP_MCP_AI_Tool_Enhance_Media_Prompt implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'enhance_media_prompt';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Enhance Media Prompt', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deterministically assembles an image or video prompt from structured photographic choices: camera body, lens, focal length, aperture, visual style, quality tags, and ordinal reference-identity instructions ("Reference image 1 shows Sarah; keep this person\'s face…"). No AI call, no cost, fully reproducible. Returns the composed prompt, the reference instructions separately, and a list of what was applied. Feed the result into generate_higgsfield_image, generate_higgsfield_video, generate_veo_video, generate_omni_video, or generate_sora_video.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Turning a plain brief plus structured photographic intent into a consistent prompt, or when multiple reference photos must keep a character or product identical.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Rewriting a prompt with an LLM, or generating the media itself; this tool only assembles strings. Choosing a model or checking its limits; use media_model_capabilities.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'media_model_capabilities', 'generate_higgsfield_image', 'generate_higgsfield_video', 'generate_veo_video', 'generate_omni_video', 'generate_sora_video' ),
			'notes'           => __( 'reference_instructions is returned separately so the caller can place it. characters/items counts are the number of reference photos per subject; ordinals are numbered in send order (characters first, then items).', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'                => array(
					'type'        => 'string',
					'description' => __( 'Base description of the shot: subject, action, setting, lighting.', 'mcp-ai-wpoos' ),
					'minLength'   => 1,
				),
				'style'                 => array(
					'type'        => 'string',
					'description' => __( 'Visual style preset. "none" adds nothing and is accepted so a video-tool enum can be passed straight through.', 'mcp-ai-wpoos' ),
					'enum'        => WP_MCP_AI_Prompt_Craft::get_styles(),
				),
				'camera'                => array(
					'type'        => 'string',
					'description' => __( 'Camera body preset.', 'mcp-ai-wpoos' ),
					'enum'        => WP_MCP_AI_Prompt_Craft::get_cameras(),
				),
				'lens'                  => array(
					'type'        => 'string',
					'description' => __( 'Lens character preset.', 'mcp-ai-wpoos' ),
					'enum'        => WP_MCP_AI_Prompt_Craft::get_lenses(),
				),
				'focal_length'          => array(
					'type'        => 'integer',
					'description' => __( 'Focal length in millimetres.', 'mcp-ai-wpoos' ),
					'enum'        => WP_MCP_AI_Prompt_Craft::get_focal_lengths(),
				),
				'aperture'              => array(
					'type'        => 'string',
					'description' => __( 'Aperture without the "f/" prefix: "1.4" (shallow), "4" (balanced), "11" (deep focus).', 'mcp-ai-wpoos' ),
					'enum'        => WP_MCP_AI_Prompt_Craft::get_apertures(),
				),
				'include_quality_tags'  => array(
					'type'        => 'boolean',
					'description' => __( 'Append the standard quality fragments (professional photography, ultra-detailed, high dynamic range). Default true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'include_identity_lock' => array(
					'type'        => 'boolean',
					'description' => __( 'Build ordinal reference-identity instructions from characters and items. Default true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'characters'            => array(
					'type'        => 'array',
					'description' => __( 'People or characters that must stay identical across reference photos.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'  => array(
								'type'        => 'string',
								'description' => __( 'Subject name used in the instruction, e.g. "Sarah".', 'mcp-ai-wpoos' ),
							),
							'count' => array(
								'type'        => 'integer',
								'description' => __( 'How many reference photos show this subject.', 'mcp-ai-wpoos' ),
								'minimum'     => 1,
								'maximum'     => 30,
							),
						),
						'required'   => array( 'count' ),
					),
				),
				'items'                 => array(
					'type'        => 'array',
					'description' => __( 'Products or objects that must stay identical across reference photos.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'  => array(
								'type'        => 'string',
								'description' => __( 'Product name used in the instruction, e.g. "the serum bottle".', 'mcp-ai-wpoos' ),
							),
							'count' => array(
								'type'        => 'integer',
								'description' => __( 'How many reference photos show this product.', 'mcp-ai-wpoos' ),
								'minimum'     => 1,
								'maximum'     => 30,
							),
						),
						'required'   => array( 'count' ),
					),
				),
				'extra_tags'            => array(
					'type'        => 'array',
					'description' => __( 'Additional prompt fragments appended verbatim.', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => 20,
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
		// Gate 1 — sanitize at entry.
		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( (string) $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'A base prompt is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$options = array(
			'style'                => '',
			'camera'               => '',
			'lens'                 => '',
			'focal_length'         => 0,
			'aperture'             => '',
			'include_quality_tags' => isset( $arguments['include_quality_tags'] ) ? (bool) $arguments['include_quality_tags'] : true,
			'extra_tags'           => array(),
		);

		$applied = array();

		$style = isset( $arguments['style'] ) ? sanitize_key( (string) $arguments['style'] ) : '';
		if ( '' !== $style && in_array( $style, WP_MCP_AI_Prompt_Craft::get_styles(), true ) ) {
			$options['style'] = $style;
			if ( 'none' !== $style ) {
				$applied[] = sprintf(
					/* translators: %s: style slug */
					__( 'style: %s', 'mcp-ai-wpoos' ),
					$style
				);
			}
		}

		$camera = isset( $arguments['camera'] ) ? sanitize_key( (string) $arguments['camera'] ) : '';
		if ( '' !== $camera && in_array( $camera, WP_MCP_AI_Prompt_Craft::get_cameras(), true ) ) {
			$options['camera'] = $camera;
			$applied[]         = sprintf(
				/* translators: %s: camera slug */
				__( 'camera: %s', 'mcp-ai-wpoos' ),
				$camera
			);
		}

		$lens = isset( $arguments['lens'] ) ? sanitize_key( (string) $arguments['lens'] ) : '';
		if ( '' !== $lens && in_array( $lens, WP_MCP_AI_Prompt_Craft::get_lenses(), true ) ) {
			$options['lens'] = $lens;
			$applied[]       = sprintf(
				/* translators: %s: lens slug */
				__( 'lens: %s', 'mcp-ai-wpoos' ),
				$lens
			);
		}

		$focal = isset( $arguments['focal_length'] ) ? absint( $arguments['focal_length'] ) : 0;
		if ( $focal > 0 && in_array( $focal, WP_MCP_AI_Prompt_Craft::get_focal_lengths(), true ) ) {
			$options['focal_length'] = $focal;
			$applied[]               = sprintf(
				/* translators: %d: focal length in millimetres */
				__( 'focal length: %dmm', 'mcp-ai-wpoos' ),
				$focal
			);
		}

		$aperture = isset( $arguments['aperture'] ) ? sanitize_text_field( (string) $arguments['aperture'] ) : '';
		$aperture = ltrim( $aperture, 'fF/' );
		if ( '' !== $aperture && in_array( $aperture, WP_MCP_AI_Prompt_Craft::get_apertures(), true ) ) {
			$options['aperture'] = $aperture;
			$applied[]           = sprintf(
				/* translators: %s: aperture value without the f/ prefix */
				__( 'aperture: f/%s', 'mcp-ai-wpoos' ),
				$aperture
			);
		}

		if ( $options['include_quality_tags'] ) {
			$applied[] = __( 'quality tags', 'mcp-ai-wpoos' );
		}

		if ( isset( $arguments['extra_tags'] ) && is_array( $arguments['extra_tags'] ) ) {
			$tags = array();
			foreach ( array_slice( $arguments['extra_tags'], 0, 20 ) as $tag ) {
				if ( ! is_scalar( $tag ) ) {
					continue;
				}
				$tag = sanitize_text_field( (string) $tag );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
			$options['extra_tags'] = $tags;
			if ( ! empty( $tags ) ) {
				$applied[] = sprintf(
					/* translators: %d: number of extra tags */
					_n( '%d extra tag', '%d extra tags', count( $tags ), 'mcp-ai-wpoos' ),
					count( $tags )
				);
			}
		}

		$characters = $this->normalize_subjects( isset( $arguments['characters'] ) ? $arguments['characters'] : array() );
		$items      = $this->normalize_subjects( isset( $arguments['items'] ) ? $arguments['items'] : array() );

		$include_lock = isset( $arguments['include_identity_lock'] ) ? (bool) $arguments['include_identity_lock'] : true;
		$references   = $include_lock ? WP_MCP_AI_Prompt_Craft::build_identity_lock( $characters, $items ) : '';
		$slot_count   = $include_lock ? WP_MCP_AI_Prompt_Craft::count_identity_slots( $characters, $items ) : 0;

		if ( $slot_count > 0 ) {
			$applied[] = sprintf(
				/* translators: %d: number of reference slots described */
				_n( 'identity lock over %d reference image', 'identity lock over %d reference images', $slot_count, 'mcp-ai-wpoos' ),
				$slot_count
			);
		}

		$composed = WP_MCP_AI_Prompt_Craft::compose( $prompt, $options );

		if ( '' !== $references ) {
			$composed = $composed . ' ' . $references;
		}

		return array(
			'success' => true,
			'message' => empty( $applied )
				? __( 'Prompt returned unchanged; no modifiers were supplied.', 'mcp-ai-wpoos' )
				: sprintf(
					/* translators: %s: comma-separated list of applied modifiers */
					__( 'Prompt assembled with: %s.', 'mcp-ai-wpoos' ),
					implode( ', ', $applied )
				),
			'data'    => array(
				'enhanced_prompt'        => $composed,
				'base_prompt'            => $prompt,
				'reference_instructions' => $references,
				'reference_slot_count'   => $slot_count,
				'applied'                => $applied,
				'deterministic'          => true,
			),
		);
	}

	/**
	 * Normalise a characters/items list into name + count pairs.
	 *
	 * Entries without a positive count are discarded: a subject with zero
	 * references has nothing to lock.
	 *
	 * @param mixed $subjects Raw list.
	 * @return array<int,array{name:string,count:int}>
	 */
	protected function normalize_subjects( $subjects ) {
		$normalized = array();

		if ( ! is_array( $subjects ) ) {
			return $normalized;
		}

		foreach ( array_slice( $subjects, 0, 20 ) as $subject ) {
			if ( ! is_array( $subject ) ) {
				continue;
			}

			$count = isset( $subject['count'] ) ? absint( $subject['count'] ) : 0;
			if ( $count < 1 ) {
				continue;
			}

			$normalized[] = array(
				'name'  => isset( $subject['name'] ) ? sanitize_text_field( (string) $subject['name'] ) : '',
				'count' => min( 30, $count ),
			);
		}

		return $normalized;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'video_producer', 'content_creator', 'graphic_designer' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only', // Produces text only; never mutates state.
		);
	}
}
