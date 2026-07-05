<?php
/**
 * NV oOS Crocoblock DS — Token Registry
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central store of all design tokens.
 *
 * Initialises tokens from preset definitions, loads saved overrides from the
 * WordPress options table, and provides grouped access for the CSS generator
 * and admin UI.
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Token_Registry {

	/**
	 * All registered tokens, keyed by token ID.
	 *
	 * @var array<string, NV_oOS_Crocoblock_DS_Data_Token>
	 */
	private $tokens = array();

	/**
	 * Token definitions grouped by category.
	 *
	 * @var array<string, array<string, NV_oOS_Crocoblock_DS_Data_Token>>
	 */
	private $grouped;

	/**
	 * Constructor. Seeds tokens from the built-in preset and overlays saved values.
	 */
	public function __construct() {
		$this->seed_defaults();
		$this->apply_saved_values();
	}

	// -----------------------------------------------------------------------
	// Public API.
	// -----------------------------------------------------------------------

	/**
	 * Get all tokens as a flat array.
	 *
	 * @return array<string, NV_oOS_Crocoblock_DS_Data_Token>
	 */
	public function get_all() {
		return $this->tokens;
	}

	/**
	 * Get all tokens grouped by category.
	 *
	 * @return array<string, array<string, NV_oOS_Crocoblock_DS_Data_Token>>
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
	 * @return NV_oOS_Crocoblock_DS_Data_Token|null Null if not found.
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
		$this->tokens[ $id ]->value = $this->sanitize_value( $this->tokens[ $id ], $value );
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
			NV_oOS_Crocoblock_DS_Plugin::OPTION_KEY,
			$this->get_values_map(),
			false
		);
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
		 * @var NV_oOS_Crocoblock_DS_Data_Preset
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
		$preset = new NV_oOS_Crocoblock_DS_Preset_Minimal();
		foreach ( $preset->definitions() as $def ) {
			$this->tokens[ $def['id'] ] = new NV_oOS_Crocoblock_DS_Data_Token(
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
		$saved = get_option( NV_oOS_Crocoblock_DS_Plugin::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			return;
		}

		foreach ( $saved as $id => $value ) {
			if ( isset( $this->tokens[ $id ] ) ) {
				$this->tokens[ $id ]->value = $this->sanitize_value( $this->tokens[ $id ], $value );
			}
		}
	}

	/**
	 * Sanitize a token value based on its type.
	 *
	 * @param NV_oOS_Crocoblock_DS_Data_Token $token Token definition.
	 * @param string                          $value Raw value.
	 * @return string Sanitized value.
	 */
	private function sanitize_value( $token, $value ) {
		switch ( $token->type ) {
			case 'color':
				return $this->sanitize_color( $value );

			case 'size':
			case 'spacing':
				return $this->sanitize_size( $value );

			case 'font':
				return $this->sanitize_font( $value );

			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitize a CSS color value (hex, rgb(), var(), or named).
	 *
	 * @param string $value Raw color value.
	 * @return string
	 */
	private function sanitize_color( $value ) {
		$value = trim( $value );

		// Allow CSS custom property references like var(--e-global-color-primary).
		if ( 0 === strpos( $value, 'var(' ) ) {
			return $value;
		}

		// Allow rgba() / rgb().
		if ( 0 === strpos( $value, 'rgb' ) ) {
			return $value;
		}

		// Allow hsl().
		if ( 0 === strpos( $value, 'hsl' ) ) {
			return $value;
		}

		// Allow hex colors.
		if ( 0 === strpos( $value, '#' ) ) {
			$sanitized = sanitize_hex_color( $value );
			return $sanitized ? $sanitized : $value;
		}

		// Fallback: strip dangerous characters.
		return wp_strip_all_tags( $value );
	}

	/**
	 * Sanitize a CSS size value (px, em, rem, %, etc.).
	 *
	 * @param string $value Raw size value.
	 * @return string
	 */
	private function sanitize_size( $value ) {
		$value = trim( $value );

		// Allow CSS custom property references.
		if ( 0 === strpos( $value, 'var(' ) ) {
			return $value;
		}

		// Allow numeric + unit (e.g. '16px', '1.5rem', '100%', '0').
		if ( preg_match( '/^-?\d+(\.\d+)?(px|em|rem|%|vh|vw|ch|ex|vmin|vmax|cm|mm|in|pt|pc)?$/', $value ) ) {
			return $value;
		}

		// Allow calc().
		if ( 0 === strpos( $value, 'calc(' ) ) {
			return $value;
		}

		// Fallback.
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize a font-related value.
	 *
	 * @param string $value Raw font value.
	 * @return string
	 */
	private function sanitize_font( $value ) {
		$value = trim( $value );

		// Font stacks often contain quotes and commas; allow them.
		if ( 0 === strpos( $value, 'var(' ) ) {
			return $value;
		}

		return sanitize_text_field( $value );
	}
}
