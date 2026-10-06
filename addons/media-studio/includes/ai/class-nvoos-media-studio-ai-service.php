<?php
/**
 * NV oOS Media Studio — AI Transform Service
 *
 * Server-side bridge between the Media Studio SPA and the NV oOS image stack.
 *
 * Responsibilities (see docs/project/plans/media-studio-fashion-photography-enhancement-plan.md):
 *  - Capability introspection for the `fashion-studio` SPA mode.
 *  - Transform routing (on-model, model-swap, face-swap, background, recolor,
 *    packshot, detail-repair, try-on) through existing core tools.
 *  - Cost estimation + review tripwires (decisions D-3 in the implementation plan).
 *  - Consent / acknowledgment gates for face transforms (decision D-1).
 *  - Provenance attachment meta + forced disclosure watermark on face outputs.
 *  - Media Library import/export helpers.
 *
 * The service never holds provider credentials; execution happens through the
 * core tool registry (or a sidecar filter seam), so the existing API key store
 * remains the single source of secrets.
 *
 * @package NV_oOS_Media_Studio
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI transform service.
 *
 * @since 0.2.0
 */
class NV_oOS_Media_Studio_AI_Service {

	/**
	 * Addon settings option key (shared with NV_oOS_Media_Studio_Plugin).
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_media_studio_settings';

	/**
	 * Supported transform slugs.
	 *
	 * @var array
	 */
	const TRANSFORMS = array( 'on-model', 'model-swap', 'face-swap', 'background', 'recolor', 'packshot', 'detail-repair', 'try-on', 'video' );

	/**
	 * Transforms subject to the face-output disclosure policy (D-1).
	 *
	 * @var array
	 */
	const FACE_TRANSFORMS = array( 'face-swap', 'try-on' );

	/**
	 * Provenance meta keys written on every AI output.
	 *
	 * @var string
	 */
	const META_GENERATED   = '_nvoos_ai_generated';
	const META_PROVIDER    = '_nvoos_ai_provider';
	const META_MODEL       = '_nvoos_ai_model';
	const META_PROMPT_HASH = '_nvoos_ai_prompt_hash';
	const META_TRANSFORM   = '_nvoos_ai_transform';
	const META_IDENTITY    = '_nvoos_ai_identity_id';
	const META_WATERMARKED = '_nvoos_ai_watermarked';

	/**
	 * Default cost tripwires (D-3) in USD.
	 */
	const DEFAULT_PER_IMAGE_CEILING = 0.25;
	const DEFAULT_PER_JOB_CEILING   = 10.0;
	const DEFAULT_HARD_CAP          = 100.0;

	/**
	 * Prompt templates per transform.
	 *
	 * Fixed lighting / background / composition parts are identical across
	 * generations; only the user-supplied descriptor varies (mirrors the
	 * product-photography AI-consistency rule).
	 *
	 * @var array
	 */
	const PROMPT_TEMPLATES = array(
		'on-model'      => 'Place this exact garment on a professional fashion model in a full-length studio pose. Preserve every garment detail precisely: color, fabric texture, seams, stitching, prints, logos, buttons, and hardware. Natural fit with realistic proportions.',
		'model-swap'    => 'Replace the person in this image with a different professional fashion model while keeping the garment, pose, framing, and background exactly as they are. Preserve garment details: color, fit, seams, prints, and fabric texture.',
		'face-swap'     => 'Replace the model face with the provided reference face, preserving the pose, lighting, garment, and background exactly. Keep skin tone natural and consistent.',
		'background'    => 'Keep the garment and model exactly as they are and replace the background with a new scene.',
		'recolor'       => 'Recolor this garment to the target color while preserving all other details: seams, stitching, prints, fabric texture, lighting, and shadows. Do not change the model or pose.',
		'packshot'      => 'Convert this to a clean e-commerce packshot: the garment perfectly centered, ghost-mannequin or flat presentation, on a pure white background (RGB 255,255,255), evenly lit, no shadows on the background.',
		'detail-repair' => 'Repair and restore this product image: fix or sharpen logos, text, and print details while preserving the overall garment appearance, lighting, and composition.',
		'try-on'        => 'Show this person wearing the garment described below. Preserve the person, pose, and lighting exactly; render the garment with natural fit and realistic fabric behavior.',
		'video'         => 'Fashion video of the garment shown in the source image. Keep the garment, colors, and fabric identical across frames; smooth camera motion.',
	);

	/**
	 * Core tool slug per transform (all route through edit_gemini_image in the
	 * base plugin; a sidecar can override via the nvoos_media_studio_sidecar_transform filter).
	 *
	 * @var array
	 */
	const TRANSFORM_TOOLS = array(
		'on-model'      => 'edit_gemini_image',
		'model-swap'    => 'edit_gemini_image',
		'face-swap'     => 'edit_gemini_image',
		'background'    => 'edit_gemini_image',
		'recolor'       => 'edit_gemini_image',
		'packshot'      => 'edit_gemini_image',
		'detail-repair' => 'edit_gemini_image',
		'try-on'        => 'edit_gemini_image',
		// Sidecar-only (Phase 5): the media-worker /api/video/generate route.
		'video'         => '',
	);

	/**
	 * Retrieve addon settings with D-1/D-3 defaults applied.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'ai_disclosure'     => 'metadata',
			'watermark_face'    => true,
			'require_ack'       => true,
			'per_image_ceiling' => self::DEFAULT_PER_IMAGE_CEILING,
			'per_job_ceiling'   => self::DEFAULT_PER_JOB_CEILING,
			'hard_cap'          => self::DEFAULT_HARD_CAP,
			// Phase 4: optional C2PA signing service (best-effort, https only).
			'c2pa_sign_url'     => '',
		);

		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = array_merge( $defaults, $saved );

		// D-1: the disclosure floor is metadata — never downgradable.
		if ( ! in_array( $settings['ai_disclosure'], array( 'metadata', 'metadata+watermark' ), true ) ) {
			$settings['ai_disclosure'] = 'metadata';
		}
		$settings['watermark_face']    = (bool) $settings['watermark_face'];
		$settings['require_ack']       = (bool) $settings['require_ack'];
		$settings['per_image_ceiling'] = max( 0.0, (float) $settings['per_image_ceiling'] );
		$settings['per_job_ceiling']   = max( 0.0, (float) $settings['per_job_ceiling'] );
		$settings['hard_cap']          = max( 0.0, (float) $settings['hard_cap'] );
		$settings['c2pa_sign_url']     = isset( $settings['c2pa_sign_url'] ) ? esc_url_raw( trim( (string) $settings['c2pa_sign_url'] ) ) : '';

		return $settings;
	}

	/**
	 * Whether the media-worker sidecar URL is configured.
	 *
	 * @return bool
	 */
	public static function is_sidecar_available() {
		return defined( 'WP_MEDIA_WORKER_URL' ) && '' !== WP_MEDIA_WORKER_URL;
	}

	/**
	 * Capability map consumed by the SPA (`GET /ai/capabilities`).
	 *
	 * @return array
	 */
	public static function get_capabilities() {
		$settings = self::get_settings();

		$providers = array();
		if ( class_exists( 'WP_MCP_AI_Model_Config' ) ) {
			$available = WP_MCP_AI_Model_Config::get_available_providers();
			foreach ( (array) $available as $slug => $label ) {
				$slug = sanitize_key( $slug );
				if ( '' === $slug ) {
					continue;
				}
				$providers[ $slug ] = array(
					'label'      => sanitize_text_field( is_string( $label ) ? $label : $slug ),
					'configured' => true,
				);
			}
		}

		$registry = class_exists( 'WP_MCP_AI_Tool_Registry' ) ? WP_MCP_AI_Tool_Registry::get_instance() : null;

		$transforms = array();
		foreach ( self::TRANSFORMS as $slug ) {
			if ( 'video' === $slug ) {
				// Phase 5: sidecar-only transform — no core tool backend.
				$transforms[ $slug ] = array(
					'available'        => self::is_sidecar_available(),
					'backend'          => self::is_sidecar_available() ? 'sidecar' : 'none',
					'fidelity'         => 'prompt-bound',
					'requires_consent' => false,
				);
				continue;
			}
			$tool                = self::TRANSFORM_TOOLS[ $slug ];
			$available           = $registry instanceof WP_MCP_AI_Tool_Registry ? ( null !== $registry->get_tool( $tool ) ) : false;
			$transforms[ $slug ] = array(
				'available'        => (bool) $available,
				'backend'          => $available ? $tool : 'none',
				'fidelity'         => in_array( $slug, array( 'on-model', 'try-on' ), true ) ? 'prompt-bound' : 'native',
				'requires_consent' => in_array( $slug, self::FACE_TRANSFORMS, true ),
			);
		}

		return array(
			'version'    => defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ? NVOOS_MEDIA_STUDIO_VERSION : 'unknown',
			'providers'  => $providers,
			'transforms' => $transforms,
			'profiles'   => class_exists( 'NV_oOS_Media_Studio_Output_Pipeline' ) ? NV_oOS_Media_Studio_Output_Pipeline::get_profiles() : array(),
			'compliance' => class_exists( 'NV_oOS_Media_Studio_Provenance' ) ? NV_oOS_Media_Studio_Provenance::get_compliance_block() : array(),
			'settings'   => array(
				'ai_disclosure'     => $settings['ai_disclosure'],
				'watermark_face'    => $settings['watermark_face'],
				'require_ack'       => $settings['require_ack'],
				'per_image_ceiling' => $settings['per_image_ceiling'],
				'per_job_ceiling'   => $settings['per_job_ceiling'],
				'hard_cap'          => $settings['hard_cap'],
			),
			'sidecar'    => self::is_sidecar_available(),
			'wc_active'  => class_exists( 'WooCommerce' ),
			'pro_active' => defined( 'WP_MCP_AI_PRO_PATH' ),
			// Pro registers the batch surface (identities, jobs) into this namespace.
			'batch'      => class_exists( 'WP_MCP_AI_Fashion_Batch' ),
		);
	}

	/**
	 * Base-level presets shared with the SPA (Pro adds more later).
	 *
	 * @return array
	 */
	public static function get_presets() {
		$presets = array(
			array(
				'slug'       => 'pdp-white',
				'label'      => __( 'PDP — pure white', 'nvoos-media-studio' ),
				'transform'  => 'packshot',
				'background' => 'studio',
			),
			array(
				'slug'       => 'pdp-lifestyle',
				'label'      => __( 'PDP — lifestyle', 'nvoos-media-studio' ),
				'transform'  => 'background',
				'background' => 'lifestyle',
			),
			array(
				'slug'       => 'editorial',
				'label'      => __( 'Editorial', 'nvoos-media-studio' ),
				'transform'  => 'on-model',
				'background' => 'editorial',
			),
		);

		/**
		 * Filter the base fashion presets (Pro appends its seeded set).
		 *
		 * @param array $presets Preset list.
		 */
		return apply_filters( 'nvoos_media_studio_presets', $presets );
	}

	/**
	 * Estimate the per-image cost of a transform.
	 *
	 * @param string $transform Transform slug.
	 * @param array  $args      Transform arguments (unused by the estimator, reserved).
	 * @return array{usd:float,known:bool,tool:string}
	 */
	public static function estimate_transform_cost( $transform, $args = array() ) {
		unset( $args );
		$tool     = self::TRANSFORM_TOOLS[ $transform ];
		$estimate = 0.0;
		$known    = false;

		if ( $tool && class_exists( 'WP_MCP_AI_Cost_Tracker' ) ) {
			$estimate = (float) WP_MCP_AI_Cost_Tracker::estimate( $tool );
			// All fashion transforms are paid; a non-positive estimate means the
			// pricing table has no entry for the tool (treat as unknown).
			$known = $estimate > 0;
		}

		$result = array(
			'usd'   => $estimate,
			'known' => $known,
			'tool'  => $tool,
		);

		/**
		 * Filter the per-image cost estimate (sidecar pricing, tests).
		 *
		 * @param array  $result    Array{usd:float,known:bool,tool:string}.
		 * @param string $transform Transform slug.
		 */
		return apply_filters( 'nvoos_media_studio_cost_estimate', $result, $transform );
	}

	/**
	 * Decide whether a transform requires explicit review (D-3 tripwires).
	 *
	 * @param string $transform Transform slug.
	 * @param array  $args      Transform arguments.
	 * @param int    $count     Number of planned generations (default 1).
	 * @return array{required:bool,blocked:bool,reason:string,estimate_usd:?float,per_image_usd:?float}
	 */
	public static function review_required( $transform, $args = array(), $count = 1 ) {
		$settings = self::get_settings();
		$count    = max( 1, absint( $count ) );

		// Consent-driven transforms always require an explicit confirm step.
		if ( in_array( $transform, self::FACE_TRANSFORMS, true ) ) {
			return array(
				'required'      => true,
				'blocked'       => false,
				'reason'        => 'consent_transform',
				'estimate_usd'  => null,
				'per_image_usd' => null,
			);
		}

		$estimate = self::estimate_transform_cost( $transform, $args );
		if ( ! $estimate['known'] ) {
			return array(
				'required'      => true,
				'blocked'       => false,
				'reason'        => 'unknown_pricing',
				'estimate_usd'  => null,
				'per_image_usd' => null,
			);
		}

		$per_image = $estimate['usd'];
		$per_job   = $per_image * $count;

		if ( $per_job > (float) $settings['hard_cap'] ) {
			return array(
				'required'      => true,
				'blocked'       => true,
				'reason'        => 'hard_cap',
				'estimate_usd'  => $per_job,
				'per_image_usd' => $per_image,
			);
		}
		if ( $per_image > (float) $settings['per_image_ceiling'] ) {
			return array(
				'required'      => true,
				'blocked'       => false,
				'reason'        => 'per_image_ceiling',
				'estimate_usd'  => $per_job,
				'per_image_usd' => $per_image,
			);
		}
		if ( $per_job > (float) $settings['per_job_ceiling'] ) {
			return array(
				'required'      => true,
				'blocked'       => false,
				'reason'        => 'per_job_ceiling',
				'estimate_usd'  => $per_job,
				'per_image_usd' => $per_image,
			);
		}

		return array(
			'required'      => false,
			'blocked'       => false,
			'reason'        => '',
			'estimate_usd'  => $per_job,
			'per_image_usd' => $per_image,
		);
	}

	/**
	 * Consent + acknowledgment gate for face transforms (D-1).
	 *
	 * @param string $transform   Transform slug.
	 * @param int    $identity_id Identity post ID (Pro CPT) or 0.
	 * @param int    $user_id     Acting user ID.
	 * @return true|WP_Error
	 */
	public static function check_consent( $transform, $identity_id, $user_id ) {
		if ( ! in_array( $transform, self::FACE_TRANSFORMS, true ) ) {
			return true;
		}

		if ( $identity_id > 0 ) {
			/**
			 * Filter the consent status of an identity. Returns true when the
			 * identity may be used for face transforms, false when not, or null
			 * to fall back to the Pro consent meta.
			 *
			 * @param bool|null $consent     Consent override.
			 * @param int       $identity_id Identity post ID.
			 */
			$consent = apply_filters( 'nvoos_media_studio_identity_consent', null, $identity_id );
			if ( null === $consent ) {
				$consent = ( 'granted' === get_post_meta( $identity_id, '_fashion_model_consent_status', true ) );
			}
			if ( ! $consent ) {
				return new WP_Error(
					'nvoos_ms_consent_required',
					__( 'This identity has not granted consent for face transforms.', 'nvoos-media-studio' ),
					array( 'status' => 403 )
				);
			}
		}

		$settings = self::get_settings();
		if ( ! empty( $settings['require_ack'] ) && $user_id > 0 && ! get_user_meta( $user_id, 'nvoos_ms_ai_ack', true ) ) {
			return new WP_Error(
				'nvoos_ms_ack_required',
				__( 'AI face transforms require a one-time acknowledgment of disclosure obligations.', 'nvoos-media-studio' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Record the one-time disclosure acknowledgment for a user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function record_ack( $user_id ) {
		update_user_meta( absint( $user_id ), 'nvoos_ms_ai_ack', time() );
	}

	/**
	 * Whether the current user has acknowledged disclosure obligations.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_acked( $user_id ) {
		return (bool) get_user_meta( absint( $user_id ), 'nvoos_ms_ai_ack', true );
	}

	/**
	 * Sanitize user-supplied prompt text (two-gate rule, entry gate).
	 *
	 * @param string $text Raw text.
	 * @return string Sanitized, instruction-stripped text.
	 */
	public static function sanitize_user_text( $text ) {
		$text = sanitize_textarea_field( (string) $text );
		$text = trim( $text );

		// Prompt-injection hardening: cap length and strip instruction markers.
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );

		$markers = array(
			'ignore previous instructions',
			'ignore all previous',
			'disregard previous',
			'as an ai language model',
			'system prompt:',
			'system:',
		);
		foreach ( $markers as $marker ) {
			$pos = stripos( $text, $marker );
			if ( false !== $pos ) {
				$text = trim( substr( $text, 0, $pos ) );
			}
		}

		return $text;
	}

	/**
	 * Resolve a SPA-friendly aspect ratio token to the Gemini enum value.
	 *
	 * @param array $args Transform arguments.
	 * @return string
	 */
	protected static function resolve_aspect_ratio( $args ) {
		$map   = array(
			'auto'       => 'auto',
			'square'     => '1:1',
			'portrait'   => '3:4',
			'landscape'  => '4:3',
			'story'      => '9:16',
			'widescreen' => '16:9',
		);
		$token = isset( $args['aspect_ratio'] ) ? sanitize_key( $args['aspect_ratio'] ) : 'auto';
		return isset( $map[ $token ] ) ? $map[ $token ] : 'auto';
	}

	/**
	 * Resolve a SPA-friendly format token to a MIME type.
	 *
	 * @param array $args Transform arguments.
	 * @return string
	 */
	protected static function resolve_mime_type( $args ) {
		$map   = array(
			'png'  => 'image/png',
			'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
		);
		$token = isset( $args['mime_type'] ) ? sanitize_key( $args['mime_type'] ) : 'png';
		return isset( $map[ $token ] ) ? $map[ $token ] : 'image/png';
	}

	/**
	 * Background style clause for the background transform.
	 *
	 * @param array $args Transform arguments.
	 * @return string
	 */
	protected static function background_style_clause( $args ) {
		$style   = isset( $args['background_style'] ) ? sanitize_key( $args['background_style'] ) : 'studio';
		$clauses = array(
			'studio'    => 'a clean professional studio background with soft neutral lighting.',
			'lifestyle' => 'a natural lifestyle scene appropriate to the garment, with realistic ambient light.',
			'gradient'  => 'a subtle gradient studio background.',
			'editorial' => 'an editorial fashion scene with dramatic, magazine-style lighting.',
			'custom'    => 'the custom background described by the user.',
		);
		return isset( $clauses[ $style ] ) ? $clauses[ $style ] : $clauses['studio'];
	}

	/**
	 * Build the full transform prompt from the fixed template + variable parts.
	 *
	 * @param string $transform Transform slug.
	 * @param array  $args      Transform arguments.
	 * @return string
	 */
	public static function build_prompt( $transform, $args ) {
		$prompt = self::PROMPT_TEMPLATES[ $transform ];

		if ( 'background' === $transform ) {
			$prompt .= ' ' . self::background_style_clause( $args );
			$custom  = self::sanitize_user_text( isset( $args['description'] ) ? $args['description'] : '' );
			if ( '' !== $custom ) {
				$prompt .= ' Background description: ' . $custom . '.';
			}
		} elseif ( 'recolor' === $transform ) {
			$color = isset( $args['color'] ) ? sanitize_hex_color( $args['color'] ) : '';
			if ( $color ) {
				$prompt .= ' Target color: #' . ltrim( $color, '#' ) . '.';
			}
		} elseif ( 'try-on' === $transform ) {
			$descriptor = self::sanitize_user_text( isset( $args['description'] ) ? $args['description'] : '' );
			if ( '' !== $descriptor ) {
				$prompt .= ' Garment description: ' . $descriptor . '.';
			}
		} else {
			$descriptor = self::sanitize_user_text( isset( $args['description'] ) ? $args['description'] : '' );
			if ( '' !== $descriptor ) {
				$prompt .= ' Additional guidance: ' . $descriptor . '.';
			}
		}

		return trim( $prompt );
	}

	/**
	 * Build a provenance-friendly output file name (never IMG_xxxx).
	 *
	 * @param string $transform     Transform slug.
	 * @param int    $attachment_id Source attachment ID.
	 * @return string
	 */
	protected static function build_file_name( $transform, $attachment_id ) {
		$hash = substr( sha1( $transform . ':' . absint( $attachment_id ) . ':' . time() ), 0, 8 );
		return 'fashion-' . $transform . '-' . $hash;
	}

	/**
	 * Resolve the Gemini image model for edit transforms.
	 *
	 * Mirrors the edit tool's own default resolution (settings
	 * `gemini_image_model`, falling back to `gemini-3.1-flash-image`) and
	 * restricts the result to the tool rules' `model_requirements` allowlist
	 * so the registry's pre-execution validation cannot reject the call.
	 *
	 * @return string
	 */
	protected static function resolve_edit_model() {
		$fallback = 'gemini-3.1-flash-image';
		$allowed  = array( 'gemini-3.1-flash-image', 'gemini-exp-1206' );
		$model    = $fallback;

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings  = WP_MCP_AI_Admin_Settings::get_settings();
			$candidate = isset( $settings['gemini_image_model'] ) ? sanitize_text_field( $settings['gemini_image_model'] ) : '';
			if ( '' !== $candidate && in_array( $candidate, $allowed, true ) ) {
				$model = $candidate;
			}
		}

		return $model;
	}

	/**
	 * Execute the sidecar-only video transform (Phase 5).
	 *
	 * The media-worker `/api/video/generate` route is synchronous text-to-video
	 * (Replicate-backed, internal polling); the approved still drives the prompt
	 * via a garment-continuity description. Results are NOT sideloaded into the
	 * Media Library in v1 — the envelope carries the remote video URL.
	 *
	 * @param int   $attachment_id Source attachment ID (drives the prompt).
	 * @param array $args          Transform arguments (duration, description, seed, confirmed).
	 * @param int   $user_id       Acting user ID.
	 * @return array|WP_Error
	 */
	public static function execute_video( $attachment_id, $args, $user_id ) {
		$settings = self::get_settings();

		// Cost gate: sidecar pricing is unknown to the core cost tracker, so
		// the D-3 unknown-pricing tripwire requires an explicit confirm.
		$review = self::review_required( 'video', $args, 1 );
		if ( $review['required'] ) {
			if ( ! empty( $review['blocked'] ) ) {
				return new WP_Error( 'nvoos_ms_hard_cap', __( 'Estimated cost exceeds the configured hard cap.', 'nvoos-media-studio' ), array( 'status' => 402 ) );
			}
			if ( empty( $args['confirmed'] ) ) {
				$error = new WP_Error(
					'nvoos_ms_review_required',
					__( 'This generation requires review before running.', 'nvoos-media-studio' ),
					array( 'status' => 409 )
				);
				$error->add_data(
					array(
						'status' => 409,
						'review' => $review,
					),
					'nvoos_ms_review_required'
				);
				return $error;
			}
		}

		$duration = isset( $args['duration'] ) ? min( 15, max( 5, absint( $args['duration'] ) ) ) : 5;
		$seed     = isset( $args['seed'] ) ? absint( $args['seed'] ) : 0;
		$title    = get_the_title( $attachment_id );
		$prompt   = 'Fashion video of the garment shown in the source image (' . sanitize_text_field( $title ) . '). Keep the garment, colors, and fabric identical across frames; smooth camera motion.';
		$custom   = self::sanitize_user_text( isset( $args['description'] ) ? $args['description'] : '' );
		if ( '' !== $custom ) {
			$prompt .= ' ' . $custom;
		}

		$context = array( 'user_id' => $user_id );

		/**
		 * Filter the video generation result (tests, alternate providers).
		 * Return null to call the media-worker sidecar.
		 *
		 * @param mixed  $result        Override result or null.
		 * @param string $prompt        Video prompt.
		 * @param array  $args          Transform arguments.
		 * @param array  $context       Execution context.
		 */
		$result = apply_filters( 'nvoos_media_studio_video_generate', null, $prompt, $args, $context );

		if ( null === $result ) {
			$result = self::request_sidecar_video( $prompt, $duration, $seed );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) || empty( $result['video_url'] ) ) {
			return new WP_Error( 'nvoos_ms_video_failed', __( 'The video provider returned no usable clip.', 'nvoos-media-studio' ), array( 'status' => 502 ) );
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'media_studio_transform',
				sprintf( 'Media Studio video transform (source %d)', $attachment_id ),
				array(
					'transform'    => 'video',
					'source'       => $attachment_id,
					'user_id'      => $user_id,
					'estimate_usd' => $review['estimate_usd'],
					'provider'     => 'media-worker',
				)
			);
		}

		return array(
			'attachment_id' => 0,
			'url'           => '',
			'video_url'     => esc_url_raw( $result['video_url'] ),
			'prediction_id' => isset( $result['prediction_id'] ) ? sanitize_text_field( $result['prediction_id'] ) : '',
			'transform'     => 'video',
			'provider'      => 'media-worker',
			'model'         => isset( $result['model'] ) ? sanitize_text_field( $result['model'] ) : '',
			'duration'      => $duration,
			'disclosure'    => $settings['ai_disclosure'],
			'watermarked'   => false,
			'xmp_embedded'  => false,
			'c2pa_signed'   => false,
			'estimate_usd'  => $review['estimate_usd'],
			'per_image_usd' => $review['per_image_usd'],
		);
	}

	/**
	 * Call the media-worker sidecar video route.
	 *
	 * @param string $prompt   Video prompt.
	 * @param int    $duration Clip length in seconds (5–15).
	 * @param int    $seed     Optional seed.
	 * @return array|WP_Error
	 */
	protected static function request_sidecar_video( $prompt, $duration, $seed = 0 ) {
		if ( ! self::is_sidecar_available() ) {
			return new WP_Error( 'nvoos_ms_sidecar_unavailable', __( 'The media-worker sidecar is not configured.', 'nvoos-media-studio' ), array( 'status' => 503 ) );
		}

		$body = array(
			'prompt'   => $prompt,
			'duration' => $duration,
			'model'    => 'stable-video-diffusion',
		);
		if ( $seed > 0 ) {
			$body['seed'] = $seed;
		}

		$response = wp_remote_post(
			untrailingslashit( WP_MEDIA_WORKER_URL ) . '/api/video/generate',
			array(
				'timeout' => 300, // The sidecar polls Replicate internally.
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'nvoos_ms_video_unreachable', __( 'Could not reach the media-worker video service.', 'nvoos-media-studio' ), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['video_url'] ) ) {
			$message = is_array( $data ) && ! empty( $data['error'] ) ? sanitize_text_field( $data['error'] ) : __( 'The video service rejected the request.', 'nvoos-media-studio' );
			return new WP_Error( 'nvoos_ms_video_rejected', $message, array( 'status' => 502 ) );
		}

		return array(
			'video_url'     => esc_url_raw( $data['video_url'] ),
			'prediction_id' => isset( $data['prediction_id'] ) ? sanitize_text_field( $data['prediction_id'] ) : '',
			'model'         => isset( $data['model'] ) ? sanitize_text_field( $data['model'] ) : '',
		);
	}

	/**
	 * Execute one transform on one attachment.
	 *
	 * @param string $transform     Transform slug.
	 * @param int    $attachment_id Source attachment ID.
	 * @param array  $args          Transform arguments (description, color, background_style,
	 *                              aspect_ratio, mime_type, identity_id, count, seed, confirmed).
	 * @param int    $user_id       Acting user ID.
	 * @return array|WP_Error
	 */
	public static function execute_transform( $transform, $attachment_id, $args = array(), $user_id = 0 ) {
		$transform = sanitize_key( $transform );
		if ( ! in_array( $transform, self::TRANSFORMS, true ) ) {
			return new WP_Error( 'nvoos_ms_invalid_transform', __( 'Unknown transform.', 'nvoos-media-studio' ), array( 'status' => 400 ) );
		}

		$attachment_id = absint( $attachment_id );
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'nvoos_ms_attachment_not_found', __( 'Source image not found.', 'nvoos-media-studio' ), array( 'status' => 404 ) );
		}
		if ( false === strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
			return new WP_Error( 'nvoos_ms_invalid_image', __( 'Source attachment is not an image.', 'nvoos-media-studio' ), array( 'status' => 400 ) );
		}

		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
		$settings = self::get_settings();

		// Phase 5: the video transform is sidecar-only and returns a video
		// URL instead of a Media Library attachment.
		if ( 'video' === $transform ) {
			return self::execute_video( $attachment_id, $args, $user_id );
		}

		$identity_id = isset( $args['identity_id'] ) ? absint( $args['identity_id'] ) : 0;
		$count       = isset( $args['count'] ) ? absint( $args['count'] ) : 1;
		$confirmed   = ! empty( $args['confirmed'] );

		// Consent / acknowledgment gate.
		$consent = self::check_consent( $transform, $identity_id, $user_id );
		if ( is_wp_error( $consent ) ) {
			return $consent;
		}

		// Cost review gate (D-3). Hard-cap blocks always; other tripwires require confirm.
		$review = self::review_required( $transform, $args, $count );
		if ( $review['required'] ) {
			if ( ! empty( $review['blocked'] ) ) {
				return new WP_Error( 'nvoos_ms_hard_cap', __( 'Estimated cost exceeds the configured hard cap.', 'nvoos-media-studio' ), array( 'status' => 402 ) );
			}
			if ( ! $confirmed ) {
				$error = new WP_Error(
					'nvoos_ms_review_required',
					__( 'This generation requires review before running.', 'nvoos-media-studio' ),
					array( 'status' => 409 )
				);
				$error->add_data(
					array(
						'status' => 409,
						'review' => $review,
					),
					'nvoos_ms_review_required'
				);
				return $error;
			}
		}

		$tool      = self::TRANSFORM_TOOLS[ $transform ];
		$prompt    = self::build_prompt( $transform, $args );
		$tool_args = array(
			'attachment_id' => $attachment_id,
			'prompt'        => $prompt,
			'aspect_ratio'  => self::resolve_aspect_ratio( $args ),
			'mime_type'     => self::resolve_mime_type( $args ),
			'file_name'     => self::build_file_name( $transform, $attachment_id ),
			// The registry validates `model_requirements.required` against the
			// tool rules before executing; a missing model surfaces as an
			// unhandled 500 on the REST route. Resolve the same default the
			// Gemini edit tool itself would use.
			'model'         => self::resolve_edit_model(),
		);

		$context = array( 'user_id' => $user_id );

		/**
		 * Override the tool execution step (sidecar routing, tests).
		 * Return null to fall through to the core tool registry.
		 *
		 * @param mixed  $result    Override result or null.
		 * @param string $transform Transform slug.
		 * @param string $tool      Tool slug about to execute.
		 * @param array  $tool_args Tool arguments.
		 * @param array  $context   Execution context.
		 */
		$result = apply_filters( 'nvoos_media_studio_execute_tool', null, $transform, $tool, $tool_args, $context );

		if ( null === $result ) {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				return new WP_Error( 'nvoos_ms_no_backend', __( 'The core tool registry is not available.', 'nvoos-media-studio' ), array( 'status' => 503 ) );
			}
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$result   = $registry->execute_tool( $tool, $tool_args, $context );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) || empty( $result['attachment_id'] ) ) {
			return new WP_Error( 'nvoos_ms_generation_failed', __( 'The provider returned no usable image.', 'nvoos-media-studio' ), array( 'status' => 502 ) );
		}

		$output_id = absint( $result['attachment_id'] );
		self::attach_provenance( $output_id, $transform, $prompt, $result, $identity_id );

		// D-1: forced visible disclosure on face outputs.
		$watermarked = false;
		if ( in_array( $transform, self::FACE_TRANSFORMS, true ) && ! empty( $settings['watermark_face'] ) ) {
			$watermarked = self::apply_disclosure_watermark( $output_id );
		}

		// Phase 4: IPTC 2025.1 XMP provenance + best-effort C2PA (after the
		// watermark so the metadata describes the final pixels).
		$provenance = array(
			'xmp_embedded' => false,
			'c2pa_signed'  => false,
		);
		if ( class_exists( 'NV_oOS_Media_Studio_Provenance' ) ) {
			$provenance = NV_oOS_Media_Studio_Provenance::record( $output_id, $user_id );
		}

		// Post-check for packshots (Phase 3 validator).
		$white_check = null;
		if ( 'packshot' === $transform ) {
			$white_check = self::validate_white_background( $output_id );
			if ( is_wp_error( $white_check ) ) {
				$white_check = null;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'media_studio_transform',
				sprintf( 'Media Studio transform %s (attachment %d → %d)', $transform, $attachment_id, $output_id ),
				array(
					'transform'    => $transform,
					'source'       => $attachment_id,
					'output'       => $output_id,
					'user_id'      => $user_id,
					'estimate_usd' => $review['estimate_usd'],
					'provider'     => isset( $result['provider'] ) ? sanitize_key( $result['provider'] ) : 'unknown',
				)
			);
		}

		return array(
			'attachment_id'    => $output_id,
			'url'              => wp_get_attachment_url( $output_id ),
			'transform'        => $transform,
			'provider'         => isset( $result['provider'] ) ? sanitize_key( $result['provider'] ) : 'unknown',
			'model'            => isset( $result['model'] ) ? sanitize_text_field( $result['model'] ) : '',
			'disclosure'       => $settings['ai_disclosure'],
			'watermarked'      => $watermarked,
			'xmp_embedded'     => ! empty( $provenance['xmp_embedded'] ),
			'c2pa_signed'      => ! empty( $provenance['c2pa_signed'] ),
			'estimate_usd'     => $review['estimate_usd'],
			'per_image_usd'    => $review['per_image_usd'],
			'white_background' => $white_check,
		);
	}

	/**
	 * Write provenance meta on an AI output attachment.
	 *
	 * @param int    $attachment_id Output attachment ID.
	 * @param string $transform     Transform slug.
	 * @param string $prompt        Full prompt used.
	 * @param array  $result        Tool result array.
	 * @param int    $identity_id   Identity post ID or 0.
	 * @return void
	 */
	public static function attach_provenance( $attachment_id, $transform, $prompt, $result, $identity_id = 0 ) {
		update_post_meta( $attachment_id, self::META_GENERATED, 1 );
		update_post_meta( $attachment_id, self::META_TRANSFORM, sanitize_key( $transform ) );
		update_post_meta( $attachment_id, self::META_PROVIDER, isset( $result['provider'] ) ? sanitize_key( $result['provider'] ) : 'unknown' );
		update_post_meta( $attachment_id, self::META_MODEL, isset( $result['model'] ) ? sanitize_text_field( $result['model'] ) : '' );
		update_post_meta( $attachment_id, self::META_PROMPT_HASH, substr( hash( 'sha256', (string) $prompt ), 0, 16 ) );
		if ( $identity_id > 0 ) {
			update_post_meta( $attachment_id, self::META_IDENTITY, $identity_id );
		}
	}

	/**
	 * Render a visible "AI-generated" disclosure badge onto the image file (GD).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when the watermark was rendered and saved.
	 */
	public static function apply_disclosure_watermark( $attachment_id ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return false;
		}

		$mime = get_post_mime_type( $attachment_id );
		$img  = false;
		if ( 'image/jpeg' === $mime ) {
			$img = imagecreatefromjpeg( $path );
		} elseif ( 'image/png' === $mime ) {
			$img = imagecreatefrompng( $path );
		} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
			$img = imagecreatefromwebp( $path );
		}
		if ( ! $img ) {
			return false;
		}

		$width  = imagesx( $img );
		$height = imagesy( $img );

		$label = __( 'AI-generated', 'nvoos-media-studio' );
		$font  = 4; // Built-in GD bitmap font — no TTF dependency.
		$tw    = imagefontwidth( $font ) * strlen( $label );
		$th    = imagefontheight( $font );
		$pad   = 8;
		$bw    = $tw + ( 2 * $pad );
		$bh    = $th + ( 2 * $pad );
		$x     = max( 0, $width - $bw - 16 );
		$y     = max( 0, $height - $bh - 16 );

		$band  = imagecreatetruecolor( $bw, $bh );
		$black = imagecolorallocate( $band, 0, 0, 0 );
		imagefilledrectangle( $band, 0, 0, $bw, $bh, $black );
		imagecopymerge( $img, $band, $x, $y, 0, 0, $bw, $bh, 60 );

		$white = imagecolorallocate( $img, 255, 255, 255 );
		imagestring( $img, $font, $x + $pad, $y + $pad, $label, $white );

		if ( 'image/jpeg' === $mime ) {
			$saved = imagejpeg( $img, $path, 92 );
		} elseif ( 'image/webp' === $mime && function_exists( 'imagewebp' ) ) {
			$saved = imagewebp( $img, $path, 92 );
		} else {
			$saved = imagepng( $img, $path );
		}
		imagedestroy( $img );
		imagedestroy( $band );

		if ( $saved ) {
			update_post_meta( $attachment_id, self::META_WATERMARKED, 1 );
			self::regenerate_attachment_metadata( $attachment_id );
		}

		return (bool) $saved;
	}

	/**
	 * Regenerate attachment metadata (thumbnails) after a file-level edit.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	protected static function regenerate_attachment_metadata( $attachment_id ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
	}

	/**
	 * Validate that an image has a (near-)pure white background by sampling
	 * corners and edge midpoints (Amazon main-image rule: RGB 255,255,255).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $tolerance     Max per-channel delta from 255 (default 8).
	 * @return array|WP_Error Array{is_white:bool,max_delta:int,tolerance:int} or error.
	 */
	public static function validate_white_background( $attachment_id, $tolerance = 8 ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return new WP_Error( 'nvoos_ms_gd_missing', __( 'GD image library is not available.', 'nvoos-media-studio' ) );
		}
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'nvoos_ms_attachment_not_found', __( 'Image file not found.', 'nvoos-media-studio' ) );
		}

		$mime = get_post_mime_type( $attachment_id );
		$img  = false;
		if ( 'image/jpeg' === $mime ) {
			$img = imagecreatefromjpeg( $path );
		} elseif ( 'image/png' === $mime ) {
			$img = imagecreatefrompng( $path );
		} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
			$img = imagecreatefromwebp( $path );
		}
		if ( ! $img ) {
			return new WP_Error( 'nvoos_ms_invalid_image', __( 'Unsupported image format.', 'nvoos-media-studio' ) );
		}

		$tolerance = max( 0, absint( $tolerance ) );
		$width     = imagesx( $img );
		$height    = imagesy( $img );
		$inset     = max( 1, (int) round( min( $width, $height ) * 0.03 ) );

		$samples = array(
			array( 0, 0 ),
			array( $width - 1, 0 ),
			array( 0, $height - 1 ),
			array( $width - 1, $height - 1 ),
			array( (int) ( $width / 2 ), $inset ),
			array( $inset, (int) ( $height / 2 ) ),
			array( $width - 1 - $inset, (int) ( $height / 2 ) ),
			array( (int) ( $width / 2 ), $height - 1 - $inset ),
		);

		$max_delta = 0;
		foreach ( $samples as $point ) {
			$rgb   = imagecolorat( $img, $point[0], $point[1] );
			$delta = max( abs( 255 - ( ( $rgb >> 16 ) & 0xFF ) ), abs( 255 - ( ( $rgb >> 8 ) & 0xFF ) ), abs( 255 - ( $rgb & 0xFF ) ) );
			if ( $delta > $max_delta ) {
				$max_delta = $delta;
			}
		}
		imagedestroy( $img );

		return array(
			'is_white'  => $max_delta <= $tolerance,
			'max_delta' => $max_delta,
			'tolerance' => $tolerance,
		);
	}

	/**
	 * Import (register) an existing Media Library attachment as an editor source.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       Acting user ID.
	 * @return array|WP_Error
	 */
	public static function import_attachment( $attachment_id, $user_id = 0 ) {
		$attachment_id = absint( $attachment_id );
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'nvoos_ms_attachment_not_found', __( 'Source image not found.', 'nvoos-media-studio' ), array( 'status' => 404 ) );
		}
		if ( $user_id > 0 && ! user_can( $user_id, 'read', $attachment_id ) ) {
			return new WP_Error( 'nvoos_ms_forbidden', __( 'You cannot import this attachment.', 'nvoos-media-studio' ), array( 'status' => 403 ) );
		}

		return array(
			'attachment_id' => $attachment_id,
			'url'           => esc_url_raw( wp_get_attachment_url( $attachment_id ) ),
			'mime_type'     => sanitize_text_field( get_post_mime_type( $attachment_id ) ),
			'title'         => sanitize_text_field( get_the_title( $attachment_id ) ),
			'ai_generated'  => (bool) get_post_meta( $attachment_id, self::META_GENERATED, true ),
			'transform'     => sanitize_key( (string) get_post_meta( $attachment_id, self::META_TRANSFORM, true ) ),
		);
	}

	/**
	 * Export a client-side canvas/dataURL into the Media Library with provenance meta.
	 *
	 * @param string $data_url Base64 data URL (image/png, image/jpeg, image/webp).
	 * @param array  $args     Export arguments (file_name, title, ai_generated, transform).
	 * @param int    $user_id  Acting user ID.
	 * @return array|WP_Error
	 */
	public static function export_image( $data_url, $args = array(), $user_id = 0 ) {
		unset( $user_id );

		$data_url = (string) $data_url;
		if ( ! preg_match( '#^data:(image/(?:png|jpe?g|webp));base64,([A-Za-z0-9+/=\r\n]+)$#', $data_url, $matches ) ) {
			return new WP_Error( 'nvoos_ms_invalid_payload', __( 'Invalid image payload.', 'nvoos-media-studio' ), array( 'status' => 400 ) );
		}

		$mime  = $matches[1];
		$bytes = base64_decode( preg_replace( '/\r|\n/', '', $matches[2] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'nvoos_ms_invalid_payload', __( 'Invalid image payload.', 'nvoos-media-studio' ), array( 'status' => 400 ) );
		}

		$ext    = 'image/jpeg' === $mime ? 'jpg' : ( 'image/webp' === $mime ? 'webp' : 'png' );
		$base   = isset( $args['file_name'] ) ? sanitize_file_name( $args['file_name'] ) : '';
		$base   = '' === $base ? 'media-studio-export-' . gmdate( 'Ymd-His' ) : $base;
		$upload = wp_upload_bits( $base . '.' . $ext, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'nvoos_ms_export_failed', $upload['error'], array( 'status' => 500 ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : $base,
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
			return new WP_Error( 'nvoos_ms_export_failed', __( 'Could not create the attachment.', 'nvoos-media-studio' ), array( 'status' => 500 ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( ! empty( $args['ai_generated'] ) ) {
			self::attach_provenance(
				$attachment_id,
				isset( $args['transform'] ) ? sanitize_key( $args['transform'] ) : 'canvas',
				isset( $args['prompt'] ) ? sanitize_textarea_field( $args['prompt'] ) : '',
				array( 'provider' => 'media-studio-export' ),
				0
			);
			if ( class_exists( 'NV_oOS_Media_Studio_Provenance' ) ) {
				NV_oOS_Media_Studio_Provenance::record( $attachment_id, isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0 );
			}
		}

		return array(
			'attachment_id' => $attachment_id,
			'url'           => esc_url_raw( wp_get_attachment_url( $attachment_id ) ),
			'mime_type'     => sanitize_text_field( $mime ),
			'ai_generated'  => ! empty( $args['ai_generated'] ),
		);
	}
}
