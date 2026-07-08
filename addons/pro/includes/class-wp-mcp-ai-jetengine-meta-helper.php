<?php
/**
 * JetEngine Meta Field Helper
 *
 * Lightweight utility that CPT toolkits call to register their pre-existing
 * post meta fields with JetEngine's internal registry.  Uses `store_fields()`
 * only — no `Jet_Engine_CPT_Meta` instances are created, so existing native
 * WordPress metaboxes are not duplicated.
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
	 * Accumulator of CPT slugs to register once the hook fires.
	 *
	 * @var array<string,bool>
	 */
	private static $pending = array();

	/**
	 * Register a CPT's meta fields with JetEngine's internal field registry.
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

		// Require the schema class if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' ) ) {
			$_schema_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-cpt-meta-schema.php';
			if ( file_exists( $_schema_file ) ) {
				require_once $_schema_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' ) ) {
			return;
		}

		foreach ( array_keys( self::$pending ) as $cpt_slug ) {
			$schema_fields = WP_MCP_AI_Pro_CPT_Meta_Schema::get( $cpt_slug );
			if ( empty( $schema_fields ) ) {
				continue;
			}

			$je_fields = self::map_schema_to_jetengine( $schema_fields );
			$meta->store_fields( $cpt_slug, $je_fields, 'post_type' );
		}

		self::$pending = array();
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
				'title'       => $label,
				'name'        => $meta_key,
				'object_type' => 'field',
				'width'       => '100%',
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
					if ( '' !== $description ) {
						$field['description'] = $description;
					}
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
}
