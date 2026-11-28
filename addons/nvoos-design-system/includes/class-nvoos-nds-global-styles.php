<?php
/**
 * NV oOS Design System — Global Styles Bridge.
 *
 * Publishes design tokens into the block editor's Global Styles through the
 * `wp_theme_json_data_theme` filter (WordPress 6.1+). The bridge only adds
 * presets and custom properties — it never writes theme files and never
 * replaces the theme's own palette.
 *
 * @package NV_oOS_Design_System
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime `theme.json` bridge.
 *
 * @since 1.0.0
 */
class NV_oOS_Design_System_Global_Styles {

	/**
	 * Option key for the sync toggle.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_nds_global_styles';

	/**
	 * Register the filter when the feature is enabled.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_filter( 'wp_theme_json_data_theme', array( __CLASS__, 'filter_theme_json' ) );
	}

	/**
	 * Whether Global Styles sync is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_KEY, false );
	}

	/**
	 * Merge the token-derived presets into the theme's data.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme data object.
	 * @return WP_Theme_JSON_Data
	 */
	public static function filter_theme_json( $theme_json ) {
		if ( ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json;
		}

		$partial = self::build_partial();
		if ( empty( $partial['settings'] ) ) {
			return $theme_json;
		}

		$data = method_exists( $theme_json, 'get_data' ) ? $theme_json->get_data() : array();

		// Merge the palette rather than replacing the theme's list.
		if ( isset( $partial['settings']['color']['palette'] ) ) {
			$existing                                = isset( $data['settings']['color']['palette'] ) ? $data['settings']['color']['palette'] : array();
			$partial['settings']['color']['palette'] = self::merge_palette( $existing, $partial['settings']['color']['palette'] );
		}

		return $theme_json->update_with( $partial );
	}

	/**
	 * Build the `theme.json` partial from the token registry.
	 *
	 * @return array
	 */
	public static function build_partial() {
		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$tokens   = $registry->get_all();

		$settings = array();

		$palette = self::build_palette( $tokens );
		if ( ! empty( $palette ) ) {
			$settings['color'] = array( 'palette' => $palette );
		}

		$font_families = self::build_font_families( $tokens );
		$font_sizes    = self::build_font_sizes( $tokens );
		if ( ! empty( $font_families ) || ! empty( $font_sizes ) ) {
			$settings['typography'] = array();
			if ( ! empty( $font_families ) ) {
				$settings['typography']['fontFamilies'] = $font_families;
			}
			if ( ! empty( $font_sizes ) ) {
				$settings['typography']['fontSizes'] = $font_sizes;
			}
		}

		$spacing = self::build_spacing( $tokens );
		if ( ! empty( $spacing ) ) {
			$settings['spacing'] = array( 'spacingSizes' => $spacing );
		}

		$shadows = self::build_shadows( $tokens );
		if ( ! empty( $shadows ) ) {
			$settings['shadow'] = array( 'presets' => $shadows );
		}

		$settings['custom'] = array( 'nds' => self::build_custom( $tokens ) );

		return array(
			'version'  => self::schema_version(),
			'settings' => $settings,
		);
	}

	/**
	 * Export the partial as JSON.
	 *
	 * @return string
	 */
	public static function export_json() {
		return wp_json_encode( self::build_partial(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * `theme.json` schema version for the current WordPress version.
	 *
	 * @return int
	 */
	private static function schema_version() {
		if ( function_exists( 'wp_get_wp_version' ) && version_compare( wp_get_wp_version(), '6.6', '>=' ) ) {
			return 3;
		}
		return 2;
	}

	/**
	 * Build the colour palette.
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens Tokens.
	 * @return array<int, array{name:string,slug:string,color:string}>
	 */
	private static function build_palette( $tokens ) {
		$palette = array();

		foreach ( $tokens as $token ) {
			if ( 'color' !== $token->type ) {
				continue;
			}
			if ( preg_match( '/_(dark|hc)$/', $token->id ) ) {
				continue;
			}

			$color = NV_oOS_Design_System_DTCG_Codec::css_color_to_dtcg( $token->value );
			if ( null === $color ) {
				continue;
			}

			$hex = isset( $color['hex'] ) ? $color['hex'] : $token->value;
			if ( ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $hex ) ) {
				continue;
			}

			$palette[] = array(
				'name'  => $token->label ? $token->label : $token->id,
				'slug'  => self::slug( $token->id ),
				'color' => $hex,
			);
		}

		return $palette;
	}

	/**
	 * Build font family presets.
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens Tokens.
	 * @return array<int, array{name:string,slug:string,fontFamily:string}>
	 */
	private static function build_font_families( $tokens ) {
		$families = array();

		foreach ( $tokens as $token ) {
			if ( 0 !== strpos( $token->id, 'font_family' ) ) {
				continue;
			}
			$families[] = array(
				'name'       => $token->label ? $token->label : $token->id,
				'slug'       => self::slug( $token->id ),
				'fontFamily' => $token->value,
			);
		}

		return $families;
	}

	/**
	 * Build font size presets.
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens Tokens.
	 * @return array<int, array{name:string,slug:string,size:string}>
	 */
	private static function build_font_sizes( $tokens ) {
		$sizes = array();

		foreach ( $tokens as $token ) {
			if ( 0 !== strpos( $token->id, 'font_size' ) ) {
				continue;
			}
			$sizes[] = array(
				'name' => $token->label ? $token->label : $token->id,
				'slug' => self::slug( $token->id ),
				'size' => $token->value,
			);
		}

		return $sizes;
	}

	/**
	 * Build spacing presets.
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens Tokens.
	 * @return array<int, array{name:string,slug:string,size:string}>
	 */
	private static function build_spacing( $tokens ) {
		$spacing = array();

		foreach ( $tokens as $token ) {
			if ( 0 !== strpos( $token->id, 'space_' ) ) {
				continue;
			}
			$spacing[] = array(
				'name' => $token->label ? $token->label : $token->id,
				'slug' => self::slug( $token->id ),
				'size' => $token->value,
			);
		}

		return $spacing;
	}

	/**
	 * Build shadow presets.
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens Tokens.
	 * @return array<int, array{name:string,slug:string,shadow:string}>
	 */
	private static function build_shadows( $tokens ) {
		$shadows = array();

		foreach ( $tokens as $token ) {
			if ( 0 !== strpos( $token->id, 'shadow' ) ) {
				continue;
			}
			$shadows[] = array(
				'name'   => $token->label ? $token->label : $token->id,
				'slug'   => self::slug( $token->id ),
				'shadow' => $token->value,
			);
		}

		return $shadows;
	}

	/**
	 * Build the custom-property map (`--wp--custom--nds--…`).
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens Tokens.
	 * @return array<string, string>
	 */
	private static function build_custom( $tokens ) {
		$custom = array();

		foreach ( $tokens as $token ) {
			$custom[ self::slug( $token->id ) ] = $token->value;
		}

		return $custom;
	}

	/**
	 * Merge a palette into an existing palette, preserving origin-keyed shapes.
	 *
	 * @param mixed $existing Existing palette (list or `{theme, default}`).
	 * @param array $nds      NDS palette.
	 * @return array
	 */
	private static function merge_palette( $existing, $nds ) {
		if ( is_array( $existing ) && isset( $existing['theme'] ) && is_array( $existing['theme'] ) ) {
			$existing['theme'] = array_merge( $existing['theme'], $nds );
			return $existing;
		}

		if ( is_array( $existing ) && isset( $existing['default'] ) && is_array( $existing['default'] ) ) {
			$existing['default'] = array_merge( $existing['default'], $nds );
			return $existing;
		}

		return array_merge( is_array( $existing ) ? $existing : array(), $nds );
	}

	/**
	 * Convert a token id to a `theme.json` slug.
	 *
	 * @param string $id Token id.
	 * @return string
	 */
	private static function slug( $id ) {
		return sanitize_title( str_replace( '_', '-', (string) $id ) );
	}
}
