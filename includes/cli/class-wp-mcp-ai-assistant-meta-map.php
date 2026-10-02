<?php
/**
 * CLI-to-runtime assistant meta key map.
 *
 * Maps the assistant CLI's friendly flag names to the canonical runtime meta
 * keys (`_wp_mcp_ai_*`) so `assistant create|update|get` and `assistant tools`
 * stay in lock-step with the runtime and the portability engine
 * (WP_MCP_AI_Assistant_Portability), which already reads and writes the
 * canonical keys.
 *
 * WP_CLI-independent: safe to load outside a WP-CLI context.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Assistant_Meta_Map' ) ) {

	/**
	 * Static map between assistant CLI flags and runtime meta keys.
	 *
	 * @since 1.2.0
	 */
	class WP_MCP_AI_Assistant_Meta_Map {

		/**
		 * Friendly flag => canonical runtime meta key.
		 *
		 * @var array<string,string>
		 */
		const KEYS = array(
			'provider'      => '_wp_mcp_ai_provider',
			'model'         => '_wp_mcp_ai_model',
			'system-prompt' => '_wp_mcp_ai_system_prompt',
			'tools'         => '_wp_mcp_ai_tools',
		);

		/**
		 * Resolve a friendly flag name to its canonical runtime meta key.
		 *
		 * @param string $flag Flag name (e.g. 'model', 'system-prompt', 'tools').
		 * @return string Canonical meta key, or empty string when unknown.
		 */
		public static function key( $flag ) {
			return isset( self::KEYS[ $flag ] ) ? self::KEYS[ $flag ] : '';
		}

		/**
		 * Sanitize a flag value for its canonical meta key.
		 *
		 * Two-gate rule (entry gate): every value is sanitized before it is
		 * written to post meta. `tools` is parsed from a comma-separated list
		 * into a deduplicated slug array — the array shape the runtime
		 * (is_array checks) and the portability engine expect.
		 *
		 * @param string $flag  Flag name.
		 * @param mixed  $value Raw flag value.
		 * @return mixed Sanitized value.
		 */
		public static function sanitize( $flag, $value ) {
			switch ( $flag ) {
				case 'provider':
					return sanitize_key( (string) $value );

				case 'model':
					return sanitize_text_field( (string) $value );

				case 'system-prompt':
					return sanitize_textarea_field( (string) $value );

				case 'tools':
					$tools = array_filter(
						array_map( 'trim', explode( ',', (string) $value ) )
					);
					$tools = array_map( 'sanitize_key', $tools );
					return array_values( array_unique( array_filter( $tools ) ) );

				default:
					return $value;
			}
		}

		/**
		 * Read an assistant's tool slugs as an array.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return array Tool slugs (empty when none stored).
		 */
		public static function get_tools( $assistant_id ) {
			$tools = get_post_meta( $assistant_id, self::KEYS['tools'], true );
			return is_array( $tools ) ? array_values( array_filter( array_map( 'sanitize_key', $tools ) ) ) : array();
		}
	}
}
