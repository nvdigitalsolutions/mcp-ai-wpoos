<?php
/**
 * Assistant custom post type for the Content Graph AI addon (Wave D-UI-4).
 *
 * Aligned port of the base plugin's assistant CPT registration
 * (`includes/assistants/class-wp-mcp-ai-assistant-cpt.php`,
 * `register_post_type()` / `register_meta()` /
 * `disable_block_editor_for_post_type()`) — the post-type slug, label
 * vocabulary, supports, REST base, menu icon, meta keys, and REST meta
 * schema are byte-identical to the base. Registered standalone-only:
 * in monolith installs the base plugin owns the same CPT and
 * registering here too would double-register (and double-define meta).
 *
 * Decoupling (documented, additive):
 * - No metabox system is ported yet — the base's metaboxes remain with
 *   the base (they land with the assistant editor wave); standalone
 *   installs edit assistant meta through the REST surface and the
 *   builder pages.
 * - The meta auth callback uses the same `edit_post` capability gate
 *   the base applies.
 *
 * @package NvoosContentGraphAi\Admin
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the assistant post type and its REST-visible meta.
 *
 * @since 1.1.0
 */
class AssistantPostType {

	/**
	 * Assistant post type slug (byte-identical to the base plugin).
	 */
	const POST_TYPE = 'mcp_ai_assistant';

	/**
	 * Enabled tool slugs meta key (byte-identical to the base plugin).
	 */
	const META_TOOLS = '_wp_mcp_ai_tools';

	/**
	 * Provider slug meta key (byte-identical to the base plugin).
	 */
	const META_PROVIDER = '_wp_mcp_ai_provider';

	/**
	 * Model identifier meta key (byte-identical to the base plugin).
	 */
	const META_MODEL = '_wp_mcp_ai_model';

	/**
	 * Temperature meta key (byte-identical to the base plugin).
	 */
	const META_TEMPERATURE = '_wp_mcp_ai_temperature';

	/**
	 * System prompt meta key (byte-identical to the base plugin).
	 */
	const META_SYSTEM_PROMPT = '_wp_mcp_ai_system_prompt';

	/**
	 * Knowledge-base file IDs meta key (byte-identical to the base plugin).
	 */
	const META_MEMORY_FILES = '_wp_mcp_ai_memory_files';

	/**
	 * Primary profession-role IDs meta key (byte-identical to the base plugin).
	 */
	const META_PRIMARY_ROLES = '_wp_mcp_ai_primary_roles';

	/**
	 * Source profession template meta key (byte-identical to the base plugin).
	 */
	const META_SOURCE_PROFESSION = '_wp_mcp_ai_source_profession';

	/**
	 * Register the CPT + meta hooks.
	 *
	 * Standalone-only: `Plugin.php` calls this under the
	 * `! defined('WP_MCP_AI_PATH')` discriminator.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor_for_post_type' ), 10, 2 );
	}

	/**
	 * Register the assistant custom post type (byte-identical args).
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return; // Base plugin owns it in monolith installs; idempotent otherwise.
		}

		$labels = array(
			'name'               => __( 'AI Assistants', 'nvoos-content-graph-ai' ),
			'singular_name'      => __( 'AI Assistant', 'nvoos-content-graph-ai' ),
			'add_new'            => __( 'Add New', 'nvoos-content-graph-ai' ),
			'add_new_item'       => __( 'Add New Assistant', 'nvoos-content-graph-ai' ),
			'edit_item'          => __( 'Edit Assistant', 'nvoos-content-graph-ai' ),
			'new_item'           => __( 'New Assistant', 'nvoos-content-graph-ai' ),
			'view_item'          => __( 'View Assistant', 'nvoos-content-graph-ai' ),
			'search_items'       => __( 'Search Assistants', 'nvoos-content-graph-ai' ),
			'not_found'          => __( 'No assistants found', 'nvoos-content-graph-ai' ),
			'not_found_in_trash' => __( 'No assistants found in Trash', 'nvoos-content-graph-ai' ),
			'all_items'          => __( 'All Assistants', 'nvoos-content-graph-ai' ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'rest_base'         => 'mcp-ai-assistants',
			'capability_type'   => 'post',
			'supports'          => array( 'title', 'editor' ),
			'menu_icon'         => 'dashicons-lightbulb',
			'menu_position'     => null,
			'has_archive'       => false,
			'rewrite'           => false,
			'show_in_nav_menus' => false,
			'map_meta_cap'      => true,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Disable the block editor for the assistant post type so the
	 * classic editor (and meta handling) stay authoritative.
	 *
	 * @param bool   $use_block_editor Whether the block editor should be used.
	 * @param string $post_type        Current post type being edited.
	 * @return bool
	 */
	public static function disable_block_editor_for_post_type( $use_block_editor, $post_type ) {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Register assistant post meta for REST access and sanitization
	 * (byte-identical keys/schema to the base plugin).
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return; // Meta registers with its owning post type.
		}

		$auth_callback = array( __CLASS__, 'meta_auth_callback' );

		register_post_meta(
			self::POST_TYPE,
			self::META_TOOLS,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
						),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_tools_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_PROVIDER,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_provider_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_MODEL,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_TEMPERATURE,
			array(
				'type'              => 'number',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 2,
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_temperature_meta' ),
				'auth_callback'     => $auth_callback,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_SYSTEM_PROMPT,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => $auth_callback,
			)
		);
	}

	/**
	 * Meta capability gate — the same `edit_post` check the base applies.
	 *
	 * @param bool   $allowed  Whether the user can edit the meta value.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id  Post ID.
	 * @param int    $user_id  User ID.
	 * @return bool|WP_Error
	 */
	public static function meta_auth_callback( $allowed, $meta_key, $post_id, $user_id ) {
		unset( $allowed, $meta_key, $user_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_cannot_edit',
				__( 'You are not allowed to edit assistant meta.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Sanitize the tools meta (array of tool slugs).
	 *
	 * @param mixed $value Raw meta value.
	 * @return array
	 */
	public static function sanitize_tools_meta( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array();
		}

		$tools = array();
		foreach ( $value as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue; // Non-string entries (ints, arrays) are not tool slugs.
			}
			$slug = sanitize_key( $slug );
			if ( '' !== $slug ) {
				$tools[] = $slug;
			}
		}

		return array_values( array_unique( $tools ) );
	}

	/**
	 * Sanitize the provider meta (known provider slug or empty).
	 *
	 * @param mixed $value Raw meta value.
	 * @return string
	 */
	public static function sanitize_provider_meta( $value ) {
		$provider = sanitize_key( (string) $value );

		$allowed = apply_filters(
			'wp_mcp_ai_allowed_providers',
			array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'ollama', 'lm_studio', 'cloudflare', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'embedded' )
		);

		if ( ! is_array( $allowed ) ) {
			$allowed = array();
		}

		return in_array( $provider, $allowed, true ) ? $provider : '';
	}

	/**
	 * Sanitize the temperature meta (clamped to [0, 2]).
	 *
	 * @param mixed $value Raw meta value.
	 * @return float
	 */
	public static function sanitize_temperature_meta( $value ) {
		return max( 0.0, min( 2.0, floatval( $value ) ) );
	}
}
