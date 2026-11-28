<?php
/**
 * NV oOS Design System — Token Registry
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central store of all design tokens.
 *
 * Initialises tokens from preset definitions, loads saved overrides from the
 * WordPress options table, and provides grouped access for the CSS generator,
 * email renderer, and admin UI.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Token_Registry {

	/**
	 * Option key for DTCG round-trip metadata (aliases, extensions).
	 *
	 * @var string
	 */
	const META_KEY = 'nvoos_nds_token_meta';

	/**
	 * All registered tokens, keyed by token ID.
	 *
	 * @var array<string, NV_oOS_Design_System_Data_Token>
	 */
	private $tokens = array();

	/**
	 * Token definitions grouped by category.
	 *
	 * @var array<string, array<string, NV_oOS_Design_System_Data_Token>>
	 */
	private $grouped;

	/**
	 * Constructor. Seeds tokens from the built-in preset and overlays saved values.
	 */
	public function __construct() {
		$this->seed_defaults();
		$this->apply_saved_values();
		$this->apply_saved_meta();
	}

	// -----------------------------------------------------------------------
	// Public API.
	// -----------------------------------------------------------------------

	/**
	 * Get all tokens as a flat array.
	 *
	 * @return array<string, NV_oOS_Design_System_Data_Token>
	 */
	public function get_all() {
		return $this->tokens;
	}

	/**
	 * Get all tokens grouped by category.
	 *
	 * @return array<string, array<string, NV_oOS_Design_System_Data_Token>>
	 */
	public function get_grouped() {
		if ( null === $this->grouped ) {
			$this->grouped = array();
			foreach ( $this->tokens as $token ) {
				$this->grouped[ $token->group ][ $token->id ] = $token;
			}
		}
		return $this->grouped;
	}

	/**
	 * Get a single token by its ID.
	 *
	 * @param string $id Token identifier.
	 * @return NV_oOS_Design_System_Data_Token|null Null if not found.
	 */
	public function get( $id ) {
		return isset( $this->tokens[ $id ] ) ? $this->tokens[ $id ] : null;
	}

	/**
	 * Get all token values as a simple key-value map.
	 *
	 * @return array<string, string>
	 */
	public function get_values_map() {
		$map = array();
		foreach ( $this->tokens as $token ) {
			$map[ $token->id ] = $token->value;
		}
		return $map;
	}

	/**
	 * Get a key-value map of the token values in a single group.
	 *
	 * @param string $group Group identifier (e.g. 'emails').
	 * @return array<string, string>
	 */
	public function get_group_values( $group ) {
		$map = array();
		foreach ( $this->tokens as $token ) {
			if ( $group === $token->group ) {
				$map[ $token->id ] = $token->value;
			}
		}
		return $map;
	}

	/**
	 * Set the value of a single token.
	 *
	 * @param string $id    Token identifier.
	 * @param string $value New value.
	 * @return bool True on success, false if token not found.
	 */
	public function set( $id, $value ) {
		if ( ! isset( $this->tokens[ $id ] ) ) {
			return false;
		}

		$sanitized = $this->sanitize_value( $this->tokens[ $id ], $value );
		if ( '' === $sanitized && '' !== trim( (string) $value ) ) {
			return false;
		}

		$this->tokens[ $id ]->value = $sanitized;
		return true;
	}

	/**
	 * Bulk-update token values from a key-value map.
	 *
	 * Unknown keys are silently ignored. Values are sanitised per token type.
	 *
	 * @param array<string, string> $values Associative array of id => value.
	 * @return int Number of tokens updated.
	 */
	public function set_all( array $values ) {
		$updated = 0;
		foreach ( $values as $id => $value ) {
			if ( $this->set( $id, $value ) ) {
				++$updated;
			}
		}
		return $updated;
	}

	/**
	 * Persist current token values to the WordPress options table.
	 *
	 * @return bool True if the option was updated.
	 */
	public function save() {
		return update_option(
			NV_oOS_Design_System_Plugin::OPTION_KEY,
			$this->get_values_map(),
			false
		);
	}

	/**
	 * Get a token's DTCG round-trip metadata.
	 *
	 * @param string $id Token id.
	 * @return array<string, mixed>
	 */
	public function get_meta( $id ) {
		return isset( $this->tokens[ $id ] ) ? $this->tokens[ $id ]->meta : array();
	}

	/**
	 * Set a token's DTCG round-trip metadata.
	 *
	 * @param string               $id   Token id.
	 * @param array<string, mixed> $meta Metadata.
	 * @return bool True when the token exists.
	 */
	public function set_meta( $id, $meta ) {
		if ( ! isset( $this->tokens[ $id ] ) ) {
			return false;
		}
		$this->tokens[ $id ]->meta = $this->sanitize_meta( $meta );
		return true;
	}

	/**
	 * Persist all token metadata.
	 *
	 * @return bool
	 */
	public function save_meta() {
		$meta = array();
		foreach ( $this->tokens as $id => $token ) {
			if ( ! empty( $token->meta ) ) {
				$meta[ $id ] = $token->meta;
			}
		}
		return update_option( self::META_KEY, $meta, false );
	}

	/**
	 * Delete all token metadata.
	 *
	 * @return bool
	 */
	public function clear_meta() {
		foreach ( $this->tokens as $token ) {
			$token->meta = array();
		}
		return delete_option( self::META_KEY );
	}

	/**
	 * Reset all tokens to their factory defaults.
	 *
	 * @return void
	 */
	public function reset_all() {
		foreach ( $this->tokens as $token ) {
			$token->reset();
		}
		$this->grouped = null;
	}

	/**
	 * Apply a named preset, resetting all tokens to the preset's values.
	 *
	 * @param string $preset_class Fully-qualified class name of a preset.
	 * @return void
	 */
	public function apply_preset( $preset_class ) {
		if ( ! class_exists( $preset_class ) ) {
			return;
		}

		/**
		 * The preset instance.
		 *
		 * @var NV_oOS_Design_System_Data_Preset
		 */
		$preset = new $preset_class();
		$values = $preset->token_values();

		foreach ( $this->tokens as $token ) {
			if ( isset( $values[ $token->id ] ) ) {
				$token->value = $this->sanitize_value( $token, $values[ $token->id ] );
			} else {
				$token->reset();
			}
		}

		$this->grouped = null;
	}

	// -----------------------------------------------------------------------
	// Internals.
	// -----------------------------------------------------------------------

	/**
	 * Seed tokens from the built-in preset.
	 *
	 * @return void
	 */
	private function seed_defaults() {
		$preset = new NV_oOS_Design_System_Preset_Minimal();
		foreach ( $preset->definitions() as $def ) {
			$this->tokens[ $def['id'] ] = new NV_oOS_Design_System_Data_Token(
				$def['id'],
				$def['label'],
				$def['group'],
				$def['type'],
				$def['default'],
				null,
				isset( $def['description'] ) ? $def['description'] : ''
			);
		}
	}

	/**
	 * Overlay saved values from the options table.
	 *
	 * @return void
	 */
	private function apply_saved_values() {
		$saved = get_option( NV_oOS_Design_System_Plugin::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			return;
		}

		foreach ( $saved as $id => $value ) {
			if ( isset( $this->tokens[ $id ] ) ) {
				$sanitized = $this->sanitize_value( $this->tokens[ $id ], $value );
				if ( '' !== $sanitized || '' === trim( (string) $value ) ) {
					$this->tokens[ $id ]->value = $sanitized;
				}
			}
		}
	}

	/**
	 * Sanitize token metadata before it enters the registry.
	 *
	 * @param mixed $meta  Raw metadata.
	 * @param int   $depth Current recursion depth.
	 * @return array<string, mixed>
	 */
	private function sanitize_meta( $meta, $depth = 0 ) {
		if ( ! is_array( $meta ) || $depth > 3 ) {
			return array();
		}

		$clean = array();
		$count = 0;

		foreach ( $meta as $key => $value ) {
			++$count;
			if ( $count > 64 ) {
				break;
			}

			$key = sanitize_text_field( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_meta( $value, $depth + 1 );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$text          = sanitize_text_field( (string) $value );
				$text          = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );
				$clean[ $key ] = $text;
			}
		}

		return $clean;
	}

	/**
	 * Overlay saved DTCG metadata from the options table.
	 *
	 * @return void
	 */
	private function apply_saved_meta() {
		$meta = get_option( self::META_KEY, array() );
		if ( ! is_array( $meta ) ) {
			return;
		}
		foreach ( $meta as $id => $data ) {
			if ( isset( $this->tokens[ $id ] ) && is_array( $data ) ) {
				$this->tokens[ $id ]->meta = $this->sanitize_meta( $data );
			}
		}
	}

	/**
	 * Sanitize a token value based on its type.
	 *
	 * @param NV_oOS_Design_System_Data_Token $token Token definition.
	 * @param string                          $value Raw value.
	 * @return string Sanitized value, or an empty string when rejected.
	 */
	private function sanitize_value( $token, $value ) {
		$value = (string) $value;

		switch ( $token->type ) {
			case 'color':
				return $this->sanitize_color( $value );

			case 'size':
			case 'spacing':
				return $this->sanitize_size( $value );

			case 'font':
				return $this->sanitize_font( $value );

			default:
				return $this->sanitize_css_value( $value );
		}
	}

	/**
	 * Sanitize a CSS colour value.
	 *
	 * Only a strict allowlist is accepted: hex, rgb(a), hsl(a), a single
	 * `var(--token)` reference, or a CSS colour keyword. Everything else is
	 * rejected so a token value can never break out of the `:root{}` block.
	 *
	 * @param string $value Raw colour value.
	 * @return string
	 */
	private function sanitize_color( $value ) {
		$value = trim( (string) $value );

		if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^rgba?\(\s*-?[0-9.]+%?\s*,\s*-?[0-9.]+%?\s*,\s*-?[0-9.]+%?\s*(?:,\s*-?[0-9.]+%?\s*)?\)$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^hsla?\(\s*-?[0-9.]+(?:deg)?\s*,\s*[0-9.]+%\s*,\s*[0-9.]+%\s*(?:,\s*-?[0-9.]+%?\s*)?\)$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^var\(\s*--[a-zA-Z0-9_-]+\s*\)$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^[a-zA-Z]{3,20}$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Sanitize a CSS size value (px, em, rem, %, etc.).
	 *
	 * @param string $value Raw size value.
	 * @return string
	 */
	private function sanitize_size( $value ) {
		$value = trim( (string) $value );

		if ( preg_match( '/^-?\d+(?:\.\d+)?(px|em|rem|%|vh|vw|vmin|vmax|ch|ex|pt|pc|in|cm|mm)?$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^var\(\s*--[a-zA-Z0-9_-]+\s*\)$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^calc\([0-9+\-*\/(). a-z%]+\)$/i', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Sanitize a font-related value.
	 *
	 * Font stacks legitimately contain quotes, commas and hyphens; braces,
	 * semicolons and angle brackets are never valid.
	 *
	 * @param string $value Raw font value.
	 * @return string
	 */
	private function sanitize_font( $value ) {
		$value = trim( (string) $value );

		if ( preg_match( '/^var\(\s*--[a-zA-Z0-9_-]+\s*\)$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^[A-Za-z0-9 _,\-,"\']+$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Sanitize a generic CSS value (shadow, transition, unknown types).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_css_value( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/[{}<>;]/', $value ) || false !== stripos( $value, 'url(' ) || false !== stripos( $value, 'expression(' ) ) {
			return '';
		}

		return sanitize_text_field( $value );
	}
}
