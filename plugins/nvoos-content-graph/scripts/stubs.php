<?php
/**
 * Standalone stubs for the verify scripts (never ships in the plugin ZIP —
 * see .distignore). Provides the Schema constants and WP function stubs the
 * visual classes touch, so the contrast gate can run outside WordPress.
 *
 * @package NvoosContentGraph
 */

namespace NvoosContentGraph {
	if ( ! class_exists( __NAMESPACE__ . '\Schema' ) ) {
		/**
		 * Minimal Schema constant stub for standalone verification.
		 */
		class Schema {
			public const FILTER_TYPE_PALETTE  = 'nvoos_content_graph/type_palette';
			public const FILTER_TYPE_ICONS    = 'nvoos_content_graph/type_icons';
			public const FILTER_VISUAL_CONFIG = 'nvoos_content_graph/visual_config';
		}
	}
}

namespace {
	if ( ! function_exists( '__' ) ) {
		/** @return string */
		function __( $s, $d = null ) {
			return $s;
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		/** @return string */
		function sanitize_key( $s ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
		}
	}
	if ( ! function_exists( 'sanitize_hex_color' ) ) {
		/** @return string|null */
		function sanitize_hex_color( $c ) {
			$c = ltrim( trim( (string) $c ), '#' );
			if ( 3 === strlen( $c ) ) {
				$c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
			}
			return preg_match( '/^[0-9a-fA-F]{6}$/', $c ) ? '#' . strtolower( $c ) : null;
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		/** @return mixed */
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
}
