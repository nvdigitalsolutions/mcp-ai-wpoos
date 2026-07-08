<?php
/**
 * JetEngine Meta Field Helper
 *
 * Lightweight utility that CPT toolkits call to register their pre-existing
 * post meta fields with JetEngine's internal registry and WordPress core.
 *
 * Two registrations happen per CPT:
 * 1. `store_fields()`     — JetEngine's internal field registry (Listing Builder
 *                           dropdowns, Dynamic Tags, Query Builder filters).
 * 2. `register_post_meta()` — WordPress core REST API exposure so the values
 *                           appear in JSON responses used by the Table Builder,
 *                           Block Editor, and external API consumers.
 *
 * No `Jet_Engine_CPT_Meta` instances are created, so existing native WordPress
 * metaboxes are not duplicated.
 *
 * Each toolkit's `init.php` calls:
 *
 *     WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_xxx' );
 *
 * behind a `function_exists( 'jet_engine' )` guard.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_AI_JetEngine_Meta_Helper {

	/**
	 * CPTs that have already been queued for registration.
	 *
	 * @var array<string,bool>
	 */
	private static $queued = array();

	/**
	 * Whether the `jet-engine/meta-boxes/register-instances` hook has been set.
	 *
	 * @var bool
	 */
	private static $hook_set = false;

	/**
	 * Accumulator of CPT slugs to register once the JetEngine hook fires.
	 *
	 * @var array<string,bool>
	 */
	private static $pending = array();

	/**
	 * Whether the `init` hook for register_post_meta has been set.
	 *
	 * @var bool
	 */
	private static $meta_hook_set = false;

	/**
	 * Accumulator: post_type => array of meta key definitions.
	 *
	 * @var array<string,array>
	 */
	private static $pending_meta = array();

	/**
	 * Register a CPT's meta fields with JetEngine's internal field registry
	 * AND with WordPress core's register_post_meta.
	 *
	 * Idempotent — calling twice for the same CPT is a no-op.
	 * Safe to call before or after `plugins_loaded`.
	 *
	 * @param string $cpt_slug Post type slug (must exist in CPT meta schema).
	 */
	public static function register_cpt_fields( $cpt_slug ) {
		if ( isset( self::$queued[ $cpt_slug ] ) ) {
			return;
		}

		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
			return;
		}

		self::$queued[ $cpt_slug ]  = true;
		self::$pending[ $cpt_slug ] = true;

		if ( ! self::$hook_set ) {
			self::$hook_set = true;
			add_action(
				'jet-engine/meta-boxes/register-instances',
				array( __CLASS__, 'on_register_instances' ),
				20
			);
		}

		// Defer register_post_meta until init so post types exist.
		if ( ! self::$meta_hook_set ) {
			self::$meta_hook_set = true;
			add_action( 'init', array( __CLASS__, 'on_init_register_meta' ), 100 );
		}
	}

	/**
	 * Hook callback: flush all pending CPT registrations to JetEngine.
	 *
	 * @param object $meta JetEngine meta boxes manager instance.
	 */
	public static function on_register_instances( $meta ) {
		if ( empty( self::$pending ) ) {
			return;
		}

		foreach ( array_keys( self::$pending ) as $cpt_slug ) {
			$schema_fields = self::get_schema( $cpt_slug );
			if ( empty( $schema_fields ) ) {
				continue;
			}

			$je_fields = self::map_schema_to_jetengine( $schema_fields );
			$meta->store_fields( $cpt_slug, $je_fields, 'post_type' );

			// Collect for WordPress core register_post_meta.
			if ( ! isset( self::$pending_meta[ $cpt_slug ] ) ) {
				self::$pending_meta[ $cpt_slug ] = array();
			}
			self::$pending_meta[ $cpt_slug ] = array_merge(
				self::$pending_meta[ $cpt_slug ],
				$schema_fields
			);
		}

		self::$pending = array();
	}

	/**
	 * Hook callback: register all pending meta keys with WordPress core.
	 *
	 * Called on `init` at priority 100 so all post types are registered.
	 * register_post_meta with show_in_rest => true is what exposes values
	 * in REST API JSON responses (used by Table Builder, Block Editor, etc.).
	 */
	public static function on_init_register_meta() {
		if ( empty( self::$pending_meta ) ) {
			return;
		}

		foreach ( self::$pending_meta as $cpt_slug => $schema_fields ) {
			if ( ! post_type_exists( $cpt_slug ) ) {
				continue;
			}

			foreach ( $schema_fields as $def ) {
				if ( empty( $def['meta_key'] ) ) {
					continue;
				}

				$meta_key  = $def['meta_key'];
				$type      = isset( $def['type'] ) ? $def['type'] : 'string';
				$label     = isset( $def['label'] ) ? $def['label'] : $meta_key;

				$args = array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => self::schema_type_to_wp_type( $type ),
					'description'   => $label,
					'sanitize_callback' => 'sanitize_text_field',
				);

				// Auth callback: only allow users with edit capability to write.
				$args['auth_callback'] = function () use ( $cpt_slug ) {
					$post_type_obj = get_post_type_object( $cpt_slug );
					if ( ! $post_type_obj ) {
						return false;
					}
					return current_user_can( $post_type_obj->cap->edit_posts );
				};

				register_post_meta( $cpt_slug, $meta_key, $args );
			}
		}

		self::$pending_meta = array();
	}

	/**
	 * Convert schema field definitions to JetEngine-compatible arrays.
	 *
	 * Mapping rules:
	 *   string  → text (or select if `enum` is present)
	 *   integer → number
	 *   number  → number
	 *   boolean → switcher
	 *   array   → textarea
	 *   object  → textarea
	 *
	 * @param array $schema_fields Associative array keyed by meta_key.
	 * @return array JetEngine-compatible field definitions.
	 */
	private static function map_schema_to_jetengine( array $schema_fields ) {
		$fields = array();

		foreach ( $schema_fields as $def ) {
			if ( empty( $def['meta_key'] ) ) {
				continue;
			}

			$type         = isset( $def['type'] ) ? $def['type'] : 'string';
			$meta_key     = $def['meta_key'];
			$label        = isset( $def['label'] ) ? $def['label'] : $meta_key;
			$description  = isset( $def['description'] ) ? $def['description'] : '';
			$has_enum     = ! empty( $def['enum'] ) && is_array( $def['enum'] );

			$field = array(
				'title'        => $label,
				'name'         => $meta_key,
				'object_type'  => 'field',
				'width'        => '100%',
				'show_in_rest' => true,
			);

			if ( '' !== $description ) {
				$field['description'] = $description;
			}

			switch ( $type ) {
				case 'boolean':
					$field['type'] = 'switcher';
					break;

				case 'integer':
				case 'number':
					$field['type'] = 'number';
					break;

				case 'array':
				case 'object':
					$field['type'] = 'textarea';
					break;

				case 'string':
				default:
					if ( $has_enum ) {
						$field['type']    = 'select';
						$field['options'] = array();
						foreach ( $def['enum'] as $value ) {
							$field['options'][] = array(
								'key'   => $value,
								'value' => ucfirst( str_replace( array( '-', '_' ), ' ', $value ) ),
							);
						}
					} else {
						$field['type'] = 'text';
					}
					break;
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Map schema type to WordPress register_meta type string.
	 *
	 * @param string $schema_type Schema type (string, integer, number, boolean, array, object).
	 * @return string WordPress meta type.
	 */
	private static function schema_type_to_wp_type( $schema_type ) {
		switch ( $schema_type ) {
			case 'boolean':
				return 'boolean';
			case 'integer':
				return 'integer';
			case 'number':
				return 'number';
			case 'array':
			case 'object':
				return 'string'; // Serialised storage; store as string for REST.
			default:
				return 'string';
		}
	}

	/**
	 * Load schema fields for a CPT, requiring the schema class if needed.
	 *
	 * @param string $cpt_slug Post type slug.
	 * @return array Schema field definitions, or empty array.
	 */
	private static function get_schema( $cpt_slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' ) ) {
			$_schema_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-cpt-meta-schema.php';
			if ( file_exists( $_schema_file ) ) {
				require_once $_schema_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' ) ) {
			return array();
		}

		return WP_MCP_AI_Pro_CPT_Meta_Schema::get( $cpt_slug );
	}
}
