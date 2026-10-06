<?php
/**
 * Fashion Model Identity CPT (Pro).
 *
 * Reusable AI model identities for the Media Studio fashion pipeline
 * (Phase 2 of the fashion photography enhancement plan). Identities are
 * prompt-only by default; face transforms (face-swap / try-on) require
 * explicit consent (`_fashion_model_consent_status = granted`) per the
 * D-1 disclosure policy.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fashion model identity library.
 */
class WP_MCP_AI_Fashion_Model_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_fashion_model';

	/**
	 * Seeding option key + version.
	 *
	 * @var string
	 */
	const SEEDED_KEY   = 'wp_mcp_ai_fashion_models_seeded';
	const SEED_VERSION = '1.0.0';

	/**
	 * Meta keys.
	 *
	 * @var string
	 */
	const META_GENDER       = '_fashion_model_gender';
	const META_SKIN_TONE    = '_fashion_model_skin_tone';
	const META_BODY_TYPE    = '_fashion_model_body_type';
	const META_AGE_GROUP    = '_fashion_model_age_group';
	const META_HEIGHT       = '_fashion_model_height';
	const META_CONSENT      = '_fashion_model_consent_status';
	const META_PROMPT_EMBED = '_fashion_model_prompt_embed';
	const META_IS_CUSTOM    = '_fashion_model_is_custom';

	/**
	 * Consent statuses.
	 *
	 * @var string
	 */
	const CONSENT_NONE    = 'none';
	const CONSENT_GRANTED = 'granted';
	const CONSENT_REVOKED = 'revoked';

	/**
	 * Initialize the CPT: registration, meta, and versioned seeding.
	 *
	 * @return void
	 */
	public static function init() {
		self::register_post_type();
		self::register_meta();
		add_action( 'init', array( __CLASS__, 'seed' ), 20 );
	}

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- slug is the class constant, per repo convention.
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Fashion Models', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Fashion Model', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Fashion Model', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Fashion Model', 'mcp-ai-wpoos-pro' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-businessperson',
				'supports'        => array( 'title', 'thumbnail' ),
				'capability_type' => 'post',
				'has_archive'     => false,
			)
		);
	}

	/**
	 * Register post meta with sanitization and REST exposure.
	 *
	 * @return void
	 */
	public static function register_meta() {
		$text_fields = array(
			self::META_GENDER,
			self::META_SKIN_TONE,
			self::META_BODY_TYPE,
			self::META_AGE_GROUP,
			self::META_HEIGHT,
		);
		foreach ( $text_fields as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}

		register_post_meta(
			self::POST_TYPE,
			self::META_CONSENT,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => self::CONSENT_NONE,
				'sanitize_callback' => array( __CLASS__, 'sanitize_consent' ),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_PROMPT_EMBED,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_IS_CUSTOM,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
	}

	/**
	 * Sanitize the consent status to the allowed enum.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_consent( $value ) {
		$value   = sanitize_key( (string) $value );
		$allowed = array( self::CONSENT_NONE, self::CONSENT_GRANTED, self::CONSENT_REVOKED );
		return in_array( $value, $allowed, true ) ? $value : self::CONSENT_NONE;
	}

	/**
	 * Seed a starter library of prompt-only identities (versioned, idempotent).
	 *
	 * Seeded identities ship with consent "none" — face transforms require an
	 * administrator to grant consent per identity (D-1 / D-2 decisions).
	 *
	 * @return void
	 */
	public static function seed() {
		if ( get_option( self::SEEDED_KEY ) === self::SEED_VERSION ) {
			return;
		}

		$identities = array(
			array(
				'slug'         => 'starter-ava',
				'title'        => __( 'Ava — Studio Editorial', 'mcp-ai-wpoos-pro' ),
				'gender'       => 'female',
				'skin_tone'    => 'fair',
				'body_type'    => 'slim',
				'age_group'    => '20s',
				'height'       => '170 cm',
				'prompt_embed' => __( 'Adult female model, fair skin, slim build, early twenties, 170 cm, straight dark hair, clean studio look.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'slug'         => 'starter-marcus',
				'title'        => __( 'Marcus — Athletic Fit', 'mcp-ai-wpoos-pro' ),
				'gender'       => 'male',
				'skin_tone'    => 'medium',
				'body_type'    => 'athletic',
				'age_group'    => '30s',
				'height'       => '183 cm',
				'prompt_embed' => __( 'Adult male model, medium skin tone, athletic build, thirties, 183 cm, short hair, confident stance.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'slug'         => 'starter-sofia',
				'title'        => __( 'Sofia — Curvy Editorial', 'mcp-ai-wpoos-pro' ),
				'gender'       => 'female',
				'skin_tone'    => 'tan',
				'body_type'    => 'curvy',
				'age_group'    => '30s',
				'height'       => '168 cm',
				'prompt_embed' => __( 'Adult female model, tan skin, curvy build, thirties, 168 cm, wavy hair, warm editorial expression.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'slug'         => 'starter-ravi',
				'title'        => __( 'Ravi — Classic Menswear', 'mcp-ai-wpoos-pro' ),
				'gender'       => 'male',
				'skin_tone'    => 'deep',
				'body_type'    => 'average',
				'age_group'    => '40s',
				'height'       => '178 cm',
				'prompt_embed' => __( 'Adult male model, deep skin tone, average build, forties, 178 cm, classic menswear posture.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'slug'         => 'starter-mei',
				'title'        => __( 'Mei — Youthful Streetwear', 'mcp-ai-wpoos-pro' ),
				'gender'       => 'female',
				'skin_tone'    => 'light',
				'body_type'    => 'slim',
				'age_group'    => '20s',
				'height'       => '162 cm',
				'prompt_embed' => __( 'Adult female model, light skin tone, slim build, early twenties, 162 cm, streetwear energy.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'slug'         => 'starter-daniel',
				'title'        => __( 'Daniel — Senior Lifestyle', 'mcp-ai-wpoos-pro' ),
				'gender'       => 'male',
				'skin_tone'    => 'light',
				'body_type'    => 'average',
				'age_group'    => '60s',
				'height'       => '175 cm',
				'prompt_embed' => __( 'Adult male model, light skin tone, average build, sixties, 175 cm, relaxed lifestyle presence.', 'mcp-ai-wpoos-pro' ),
			),
		);

		foreach ( $identities as $identity ) {
			$existing = get_posts(
				array(
					'post_type'   => self::POST_TYPE,
					'name'        => $identity['slug'],
					'post_status' => 'any',
					'numberposts' => 1,
				)
			);
			if ( $existing ) {
				continue;
			}

			wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $identity['title'],
					'post_name'   => $identity['slug'],
					'meta_input'  => array(
						self::META_GENDER       => $identity['gender'],
						self::META_SKIN_TONE    => $identity['skin_tone'],
						self::META_BODY_TYPE    => $identity['body_type'],
						self::META_AGE_GROUP    => $identity['age_group'],
						self::META_HEIGHT       => $identity['height'],
						self::META_CONSENT      => self::CONSENT_NONE,
						self::META_PROMPT_EMBED => $identity['prompt_embed'],
						self::META_IS_CUSTOM    => false,
					),
				)
			);
		}

		update_option( self::SEEDED_KEY, self::SEED_VERSION );
	}

	/**
	 * Query the identity library in the SPA payload shape.
	 *
	 * @return array
	 */
	public static function get_models() {
		$posts = get_posts(
			array(
				'post_type'     => self::POST_TYPE,
				'post_status'   => 'publish',
				'numberposts'   => 100,
				'orderby'       => 'title',
				'order'         => 'ASC',
				'no_found_rows' => true,
			)
		);

		$models = array();
		foreach ( $posts as $post ) {
			$thumb_id = get_post_thumbnail_id( $post->ID );
			$models[] = array(
				'id'             => $post->ID,
				'name'           => sanitize_text_field( get_the_title( $post ) ),
				'thumb_url'      => $thumb_id ? esc_url_raw( wp_get_attachment_url( $thumb_id ) ) : '',
				'gender'         => sanitize_text_field( (string) get_post_meta( $post->ID, self::META_GENDER, true ) ),
				'skin_tone'      => sanitize_text_field( (string) get_post_meta( $post->ID, self::META_SKIN_TONE, true ) ),
				'body_type'      => sanitize_text_field( (string) get_post_meta( $post->ID, self::META_BODY_TYPE, true ) ),
				'age_group'      => sanitize_text_field( (string) get_post_meta( $post->ID, self::META_AGE_GROUP, true ) ),
				'consent_status' => self::sanitize_consent( get_post_meta( $post->ID, self::META_CONSENT, true ) ),
				'is_custom'      => (bool) get_post_meta( $post->ID, self::META_IS_CUSTOM, true ),
			);
		}

		return $models;
	}

	/**
	 * Consent filter for the base addon's face-transform gate.
	 *
	 * Returns null (fall through to the base default) for non-identity posts.
	 *
	 * @param bool|null $consent     Consent override.
	 * @param int       $identity_id Identity post ID.
	 * @return bool|null
	 */
	public static function consent_filter( $consent, $identity_id ) {
		$identity_id = absint( $identity_id );
		if ( $identity_id <= 0 ) {
			return $consent;
		}
		if ( self::POST_TYPE !== get_post_type( $identity_id ) ) {
			return $consent;
		}
		return self::CONSENT_GRANTED === self::sanitize_consent( get_post_meta( $identity_id, self::META_CONSENT, true ) );
	}
}
