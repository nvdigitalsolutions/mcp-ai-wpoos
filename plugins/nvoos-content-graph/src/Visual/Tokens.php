<?php
declare(strict_types=1);

namespace NvoosContentGraph\Visual;

use NvoosContentGraph\Schema;

use function absint;
use function apply_filters;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function min;
use function pow;
use function round;
use function sanitize_hex_color;
use function sanitize_key;

/**
 * Visual token registry for the graph explorer.
 *
 * Single source of truth for every theme token, palette, icon mapping,
 * edge family, and the `visual` config delivered to the JavaScript theme
 * engine (assets/js/content-graph-theme.js). Also owns the WCAG 2.2
 * contrast math used by the Appearance tab's contrast report and by the
 * PHPUnit contrast gate.
 *
 * The JS theme engine mirrors the derive/ensure-contrast algorithm so a
 * type color always stays >= 3:1 against the active canvas in both
 * surfaces (admin + frontend).
 *
 * @since 1.0.4
 */
final class Tokens {

	/**
	 * Minimum WCAG contrast ratio for non-text elements (SC 1.4.11).
	 *
	 * @since 1.0.4
	 */
	public const MIN_NON_TEXT_CONTRAST = 3.0;

	/**
	 * Theme token sets (canvas, surfaces, edges, labels, selection).
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function themes(): array {
		return array(
			'dark'  => array(
				'canvas'          => '#0f0f1a',
				'surface'         => '#1a1a2e',
				'node_label'      => '#e0e0ff',
				'edge'            => '#5b6478',
				'edge_hierarchy'  => '#7c9ff2',
				'edge_similarity' => '#2ecc9e',
				'edge_reference'  => '#e0a94f',
				'edge_authorship' => '#b58ce0',
				'edge_label'      => '#b8bcd4',
				'border'          => '#2a2a4a',
				'selection'       => '#ffffff',
				'accent'          => '#7c9ff2',
				'muted'           => '#8b8fa3',
			),
			'light' => array(
				'canvas'          => '#f7f8fa',
				'surface'         => '#ffffff',
				'node_label'      => '#1e293b',
				'edge'            => '#9aa1b2',
				'edge_hierarchy'  => '#3f5fae',
				'edge_similarity' => '#0f8a6d',
				'edge_reference'  => '#a06c14',
				'edge_authorship' => '#7c4dbb',
				'edge_label'      => '#4b5563',
				'border'          => '#d7dbe4',
				'selection'       => '#2271b1',
				'accent'          => '#2271b1',
				'muted'           => '#6b7280',
			),
		);
	}

	/**
	 * Curated node-type palette (base colors, corrected per theme at
	 * render time via ensure_contrast()).
	 *
	 * Extensible via the `nvoos_content_graph/type_palette` filter.
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,string> Type slug => hex color.
	 */
	public static function type_palette(): array {
		$palette = array(
			'post'         => '#3498db',
			'page'         => '#2ecc71',
			'term'         => '#f39c12',
			'topic'        => '#9b59b6',
			'entity'       => '#e74c3c',
			'person'       => '#e67e22',
			'place'        => '#1abc9c',
			'organization' => '#2980b9',
			'user'         => '#c0392b',
			'media'        => '#7f8c8d',
			'memory'       => '#f1c40f',
			'agent'        => '#16a085',
			'wing'         => '#8e44ad',
			'room'         => '#27ae60',
		);

		return (array) apply_filters( Schema::FILTER_TYPE_PALETTE, $palette );
	}

	/**
	 * Curated node-type icon map (type slug => icon slug).
	 *
	 * Extensible via the `nvoos_content_graph/type_icons` filter.
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,string>
	 */
	public static function type_icon_map(): array {
		$map = array(
			'post'         => 'doc',
			'page'         => 'page',
			'term'         => 'tag',
			'topic'        => 'bulb',
			'entity'       => 'cube',
			'person'       => 'user',
			'place'        => 'pin',
			'organization' => 'building',
			'user'         => 'user-round',
			'media'        => 'image',
			'memory'       => 'brain',
			'agent'        => 'bot',
			'wing'         => 'grid',
			'room'         => 'door',
		);

		return (array) apply_filters( Schema::FILTER_TYPE_ICONS, $map );
	}

	/**
	 * Categorical community palette (12 CVD-aware hues, Tableau-10 style).
	 *
	 * @since 1.0.4
	 *
	 * @return string[]
	 */
	public static function community_palette(): array {
		return array(
			'#4e79a7',
			'#f28e2b',
			'#e15759',
			'#76b7b2',
			'#59a14f',
			'#edc948',
			'#b07aa1',
			'#ff9da7',
			'#9c755f',
			'#bab0ac',
			'#86bcb6',
			'#d37295',
		);
	}

	/**
	 * Sequential degree ramp (viridis 5 stops).
	 *
	 * @since 1.0.4
	 *
	 * @return string[]
	 */
	public static function degree_ramp(): array {
		return array(
			'#440154',
			'#3b528b',
			'#21918c',
			'#5ec962',
			'#fde725',
		);
	}

	/**
	 * Icon catalog: slug => translatable label.
	 *
	 * The glyph geometry itself lives in assets/js/content-graph-icons.js;
	 * this list is the server-side allowlist for the icon pickers.
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,string>
	 */
	public static function icon_catalog(): array {
		return array(
			'doc'        => __( 'Document', 'nvoos-content-graph' ),
			'page'       => __( 'Page', 'nvoos-content-graph' ),
			'tag'        => __( 'Tag', 'nvoos-content-graph' ),
			'bulb'       => __( 'Idea', 'nvoos-content-graph' ),
			'cube'       => __( 'Entity', 'nvoos-content-graph' ),
			'user'       => __( 'Person', 'nvoos-content-graph' ),
			'user-round' => __( 'User', 'nvoos-content-graph' ),
			'pin'        => __( 'Place', 'nvoos-content-graph' ),
			'building'   => __( 'Organization', 'nvoos-content-graph' ),
			'image'      => __( 'Media', 'nvoos-content-graph' ),
			'brain'      => __( 'Memory', 'nvoos-content-graph' ),
			'bot'        => __( 'Agent', 'nvoos-content-graph' ),
			'grid'       => __( 'Grid', 'nvoos-content-graph' ),
			'door'       => __( 'Room', 'nvoos-content-graph' ),
			'cart'       => __( 'Product', 'nvoos-content-graph' ),
			'calendar'   => __( 'Event', 'nvoos-content-graph' ),
			'link'       => __( 'Link', 'nvoos-content-graph' ),
			'code'       => __( 'Code', 'nvoos-content-graph' ),
			'video'      => __( 'Video', 'nvoos-content-graph' ),
			'audio'      => __( 'Audio', 'nvoos-content-graph' ),
			'file'       => __( 'File', 'nvoos-content-graph' ),
			'star'       => __( 'Star', 'nvoos-content-graph' ),
			'dot'        => __( 'Dot', 'nvoos-content-graph' ),
			'external'   => __( 'External', 'nvoos-content-graph' ),
		);
	}

	/**
	 * Edge relation families (relation pattern => family slug).
	 *
	 * The family slug resolves to the `edge_<family>` theme token.
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,string[]>
	 */
	public static function edge_families(): array {
		return array(
			'hierarchical' => array( 'belongs_to', 'in_category', 'has_category', 'parent_of', 'child_of', 'has_term', 'has_tag', 'in_taxonomy', 'category', 'tag' ),
			'similarity'   => array( 'related_to', 'related', 'similar_to', 'similar', 'co_occur', 'cooccurs', 'semantic' ),
			'reference'    => array( 'links_to', 'references', 'mentions', 'cites', 'links', 'link', 'hyperlink' ),
			'authorship'   => array( 'authored_by', 'created_by', 'created', 'author', 'owner' ),
		);
	}

	/**
	 * Optional category => cytoscape shape map ("shape mode").
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,string>
	 */
	public static function shape_map(): array {
		return array(
			'post'         => 'round-rectangle',
			'page'         => 'round-rectangle',
			'term'         => 'tag',
			'entity'       => 'diamond',
			'media'        => 'rectangle',
			'organization' => 'hexagon',
		);
	}

	/**
	 * Default `visual_*` settings (flat keys stored in the unified option).
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,mixed>
	 */
	public static function visual_defaults(): array {
		return array(
			'visual_theme'           => 'dark',
			'visual_preset'          => 'default',
			'visual_color_by'        => 'type',
			'visual_show_icons'      => 1,
			'visual_icon_mode'       => 'filled',
			'visual_node_shapes'     => 0,
			'visual_show_legend'     => 1,
			'visual_min_label_zoom'  => 0.35,
			'visual_edge_style'      => 'plain',
			'visual_edge_labels'     => 'hover',
			'visual_size_min'        => 12,
			'visual_size_max'        => 60,
			'visual_label_font_size' => 10,
			'visual_anim_enabled'    => 1,
			'visual_type_colors'     => array(),
			'visual_type_icons'      => array(),
		);
	}

	/**
	 * Named visual presets applied client-side by the Appearance tab.
	 *
	 * Each preset is a partial `visual_*` map; the JS fills the form
	 * fields with these values before save.
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function presets(): array {
		return array(
			'default'       => array(
				'label'  => __( 'Default (dark, icons)', 'nvoos-content-graph' ),
				'visual' => array(
					'visual_theme'       => 'dark',
					'visual_color_by'    => 'type',
					'visual_show_icons'  => 1,
					'visual_icon_mode'   => 'filled',
					'visual_node_shapes' => 0,
					'visual_edge_style'  => 'plain',
				),
			),
			'high_contrast' => array(
				'label'  => __( 'High Contrast', 'nvoos-content-graph' ),
				'visual' => array(
					'visual_theme'           => 'dark',
					'visual_color_by'        => 'type',
					'visual_show_icons'      => 1,
					'visual_icon_mode'       => 'high',
					'visual_edge_style'      => 'arrows',
					'visual_label_font_size' => 12,
				),
			),
			'editorial'     => array(
				'label'  => __( 'Editorial (light)', 'nvoos-content-graph' ),
				'visual' => array(
					'visual_theme'           => 'light',
					'visual_color_by'        => 'type',
					'visual_show_icons'      => 1,
					'visual_icon_mode'       => 'outline',
					'visual_edge_style'      => 'plain',
					'visual_label_font_size' => 12,
				),
			),
			'minimal'       => array(
				'label'  => __( 'Minimal', 'nvoos-content-graph' ),
				'visual' => array(
					'visual_theme'       => 'dark',
					'visual_color_by'    => 'monochrome',
					'visual_show_icons'  => 0,
					'visual_node_shapes' => 1,
					'visual_show_legend' => 0,
					'visual_edge_style'  => 'plain',
				),
			),
		);
	}

	// ─── WCAG 2.2 contrast math ─────────────────────────────────

	/**
	 * Relative luminance of a hex color per WCAG 2.2.
	 *
	 * @since 1.0.4
	 *
	 * @param string $hex Hex color (#rgb or #rrggbb).
	 * @return float 0..1 (0 when unparseable).
	 */
	public static function relative_luminance( string $hex ): float {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return 0.0;
		}

		$linear = array();
		foreach ( $rgb as $channel ) {
			$s        = $channel / 255;
			$linear[] = ( $s <= 0.04045 ) ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}

	/**
	 * WCAG contrast ratio between two hex colors.
	 *
	 * @since 1.0.4
	 *
	 * @param string $a First hex color.
	 * @param string $b Second hex color.
	 * @return float Ratio (1..21).
	 */
	public static function contrast_ratio( string $a, string $b ): float {
		$la = self::relative_luminance( $a );
		$lb = self::relative_luminance( $b );

		if ( $la >= $lb ) {
			$hi = $la;
			$lo = $lb;
		} else {
			$hi = $lb;
			$lo = $la;
		}

		return ( $hi + 0.05 ) / ( $lo + 0.05 );
	}

	/**
	 * Adjust a color's lightness until it meets a minimum contrast ratio
	 * against the given canvas. Hue and saturation are preserved.
	 *
	 * Deterministic and pure — the JS theme engine mirrors this algorithm.
	 *
	 * @since 1.0.4
	 *
	 * @param string $hex    Hex color to correct.
	 * @param string $canvas Canvas hex color.
	 * @param float  $min    Minimum ratio (default 3.0 per WCAG SC 1.4.11).
	 * @return string Corrected #rrggbb hex.
	 */
	public static function ensure_contrast( string $hex, string $canvas, float $min = self::MIN_NON_TEXT_CONTRAST ): string {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return self::ensure_contrast( '#7c9ff2', $canvas, $min );
		}

		$current = self::rgb_to_hex( $rgb );
		if ( self::contrast_ratio( $current, $canvas ) >= $min ) {
			return $current;
		}

		list( $h, $s, $l ) = self::rgb_to_hsl( $rgb[0], $rgb[1], $rgb[2] );

		// Dark canvases want lighter colors; light canvases want darker.
		$step = ( self::relative_luminance( $canvas ) < 0.5 ) ? 0.015 : -0.015;

		for ( $i = 0; $i < 40; $i++ ) {
			$l += $step;
			if ( $l <= 0.02 || $l >= 0.98 ) {
				break;
			}
			$next = self::hsl_to_hex( $h, $s, $l );
			if ( self::contrast_ratio( $next, $canvas ) >= $min ) {
				return $next;
			}
		}

		// Give up gracefully: return the lightest/darkest tried value.
		return self::hsl_to_hex( $h, $s, max( 0.02, min( 0.98, $l ) ) );
	}

	// ─── Config delivery ────────────────────────────────────────

	/**
	 * Build the nested `visual` config object delivered to the JS theme
	 * engine. Reads flat `visual_*` settings, merges curated tokens, and
	 * applies the `nvoos_content_graph/visual_config` filter.
	 *
	 * @since 1.0.4
	 *
	 * @param array<string,mixed> $settings Full settings array (Settings::all()).
	 * @return array<string,mixed>
	 */
	public static function visual_config( array $settings ): array {
		$defaults = self::visual_defaults();

		$theme = isset( $settings['visual_theme'] ) && is_string( $settings['visual_theme'] )
			? sanitize_key( $settings['visual_theme'] )
			: $defaults['visual_theme'];
		if ( ! in_array( $theme, array( 'dark', 'light', 'auto', 'admin' ), true ) ) {
			$theme = 'dark';
		}

		$colorBy = isset( $settings['visual_color_by'] ) && is_string( $settings['visual_color_by'] )
			? sanitize_key( $settings['visual_color_by'] )
			: $defaults['visual_color_by'];
		if ( ! in_array( $colorBy, array( 'type', 'community', 'degree', 'monochrome' ), true ) ) {
			$colorBy = 'type';
		}

		$iconMode = isset( $settings['visual_icon_mode'] ) && is_string( $settings['visual_icon_mode'] )
			? sanitize_key( $settings['visual_icon_mode'] )
			: $defaults['visual_icon_mode'];
		if ( ! in_array( $iconMode, array( 'filled', 'outline', 'high' ), true ) ) {
			$iconMode = 'filled';
		}

		$edgeStyle = isset( $settings['visual_edge_style'] ) && is_string( $settings['visual_edge_style'] )
			? sanitize_key( $settings['visual_edge_style'] )
			: $defaults['visual_edge_style'];
		if ( ! in_array( $edgeStyle, array( 'plain', 'arrows', 'tapered', 'density', 'auto' ), true ) ) {
			$edgeStyle = 'plain';
		}

		$edgeLabels = isset( $settings['visual_edge_labels'] ) && is_string( $settings['visual_edge_labels'] )
			? sanitize_key( $settings['visual_edge_labels'] )
			: $defaults['visual_edge_labels'];
		if ( ! in_array( $edgeLabels, array( 'off', 'hover', 'always' ), true ) ) {
			$edgeLabels = 'hover';
		}

		$sizeMin = isset( $settings['visual_size_min'] ) ? absint( $settings['visual_size_min'] ) : (int) $defaults['visual_size_min'];
		$sizeMax = isset( $settings['visual_size_max'] ) ? absint( $settings['visual_size_max'] ) : (int) $defaults['visual_size_max'];
		$sizeMin = max( 8, min( 40, $sizeMin ) );
		$sizeMax = max( 40, min( 120, $sizeMax ) );
		if ( $sizeMax < $sizeMin ) {
			$sizeMax = $sizeMin + 12;
		}

		$fontSize = isset( $settings['visual_label_font_size'] ) ? absint( $settings['visual_label_font_size'] ) : (int) $defaults['visual_label_font_size'];
		$fontSize = max( 9, min( 16, $fontSize ) );

		$minZoom = isset( $settings['visual_min_label_zoom'] ) ? (float) $settings['visual_min_label_zoom'] : (float) $defaults['visual_min_label_zoom'];
		$minZoom = max( 0.0, min( 1.0, $minZoom ) );

		// Booleans stored as '0'/'1' — fall back to defaults when absent
		// (visual_config() must be callable with a partial settings array).
		$showIcons   = isset( $settings['visual_show_icons'] ) ? $settings['visual_show_icons'] : $defaults['visual_show_icons'];
		$showLegend  = isset( $settings['visual_show_legend'] ) ? $settings['visual_show_legend'] : $defaults['visual_show_legend'];
		$nodeShapes  = isset( $settings['visual_node_shapes'] ) ? $settings['visual_node_shapes'] : $defaults['visual_node_shapes'];
		$animEnabled = isset( $settings['visual_anim_enabled'] ) ? $settings['visual_anim_enabled'] : $defaults['visual_anim_enabled'];

		$typeColors = self::sanitize_type_colors( isset( $settings['visual_type_colors'] ) && is_array( $settings['visual_type_colors'] ) ? $settings['visual_type_colors'] : array() );
		$typeIcons  = self::sanitize_type_icons( isset( $settings['visual_type_icons'] ) && is_array( $settings['visual_type_icons'] ) ? $settings['visual_type_icons'] : array() );

		$visual = array(
			'version'           => '1',
			'theme'             => $theme,
			'color_by'          => $colorBy,
			'show_icons'        => ! empty( $showIcons ),
			'icon_mode'         => $iconMode,
			'node_shapes'       => ! empty( $nodeShapes ),
			'show_legend'       => ! empty( $showLegend ),
			'min_label_zoom'    => $minZoom,
			'edge_style'        => $edgeStyle,
			'edge_labels'       => $edgeLabels,
			'size_min'          => $sizeMin,
			'size_max'          => $sizeMax,
			'label_font_size'   => $fontSize,
			'anim_enabled'      => ! empty( $animEnabled ),
			'type_colors'       => (object) $typeColors,
			'type_icons'        => (object) $typeIcons,
			'type_palette'      => (object) self::type_palette(),
			'type_icon_map'     => (object) self::type_icon_map(),
			'community_palette' => self::community_palette(),
			'degree_ramp'       => self::degree_ramp(),
			'themes'            => self::themes(),
			'edge_families'     => self::edge_families(),
			'shape_map'         => self::shape_map(),
		);

		return (array) apply_filters( Schema::FILTER_VISUAL_CONFIG, $visual, $settings );
	}

	/**
	 * Contrast report rows for the Appearance tab.
	 *
	 * For every type in the palette (plus any override keys) reports the
	 * resolved color and its contrast ratio against both canvases, with
	 * a suggested correction when below the 3:1 threshold.
	 *
	 * @since 1.0.4
	 *
	 * @param array<string,string> $overrides Type => hex overrides from settings.
	 * @return array<int,array<string,mixed>>
	 */
	public static function contrast_report( array $overrides ): array {
		$palette = self::type_palette();
		$themes  = self::themes();
		$types   = array_merge( array_keys( $palette ), array_keys( $overrides ) );
		$types   = array_values( array_unique( $types ) );

		$rows = array();
		foreach ( $types as $type ) {
			$color = isset( $overrides[ $type ] ) && is_string( $overrides[ $type ] ) && sanitize_hex_color( $overrides[ $type ] )
				? sanitize_hex_color( $overrides[ $type ] )
				: ( $palette[ $type ] ?? '#95a5a6' );

			$ratioDark  = self::contrast_ratio( $color, $themes['dark']['canvas'] );
			$ratioLight = self::contrast_ratio( $color, $themes['light']['canvas'] );

			$rows[] = array(
				'type'        => $type,
				'color'       => $color,
				'ratio_dark'  => round( $ratioDark, 2 ),
				'ok_dark'     => $ratioDark >= self::MIN_NON_TEXT_CONTRAST,
				'fix_dark'    => $ratioDark >= self::MIN_NON_TEXT_CONTRAST ? $color : self::ensure_contrast( $color, $themes['dark']['canvas'] ),
				'ratio_light' => round( $ratioLight, 2 ),
				'ok_light'    => $ratioLight >= self::MIN_NON_TEXT_CONTRAST,
				'fix_light'   => $ratioLight >= self::MIN_NON_TEXT_CONTRAST ? $color : self::ensure_contrast( $color, $themes['light']['canvas'] ),
			);
		}

		return $rows;
	}

	/**
	 * Sanitize a type => color override map.
	 *
	 * @since 1.0.4
	 *
	 * @param array<string,mixed> $raw Raw submitted map.
	 * @return array<string,string>
	 */
	public static function sanitize_type_colors( array $raw ): array {
		$clean = array();
		foreach ( $raw as $type => $color ) {
			$typeKey = sanitize_key( (string) $type );
			// sanitize_hex_color() returns null on invalid input — a
			// truthiness check (not a '' comparison) drops those rows.
			$hex = is_string( $color ) ? sanitize_hex_color( $color ) : null;
			if ( '' !== $typeKey && ! empty( $hex ) ) {
				$clean[ $typeKey ] = $hex;
			}
		}
		return $clean;
	}

	/**
	 * Sanitize a type => icon override map against the icon catalog.
	 *
	 * @since 1.0.4
	 *
	 * @param array<string,mixed> $raw Raw submitted map.
	 * @return array<string,string>
	 */
	public static function sanitize_type_icons( array $raw ): array {
		$catalog = self::icon_catalog();
		$clean   = array();
		foreach ( $raw as $type => $icon ) {
			$typeKey = sanitize_key( (string) $type );
			$iconKey = is_string( $icon ) ? sanitize_key( $icon ) : '';
			if ( '' !== $typeKey && array_key_exists( $iconKey, $catalog ) ) {
				$clean[ $typeKey ] = $iconKey;
			}
		}
		return $clean;
	}

	// ─── Internal color helpers ─────────────────────────────────

	/**
	 * Parse a hex color into RGB channels.
	 *
	 * @since 1.0.4
	 *
	 * @param string $hex Hex color.
	 * @return array{0:int,1:int,2:int}|null
	 */
	private static function hex_to_rgb( string $hex ): ?array {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return null;
		}
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * RGB channels to #rrggbb.
	 *
	 * @since 1.0.4
	 *
	 * @param array{0:int,1:int,2:int} $rgb Channels.
	 * @return string
	 */
	private static function rgb_to_hex( array $rgb ): string {
		return sprintf( '#%02x%02x%02x', max( 0, min( 255, $rgb[0] ) ), max( 0, min( 255, $rgb[1] ) ), max( 0, min( 255, $rgb[2] ) ) );
	}

	/**
	 * RGB to HSL (all channels normalized 0..1).
	 *
	 * @since 1.0.4
	 *
	 * @param int $r Red 0..255.
	 * @param int $g Green 0..255.
	 * @param int $b Blue 0..255.
	 * @return array{0:float,1:float,2:float}
	 */
	private static function rgb_to_hsl( int $r, int $g, int $b ): array {
		$r /= 255;
		$g /= 255;
		$b /= 255;

		$maxC = max( $r, $g, $b );
		$minC = min( $r, $g, $b );
		$l    = ( $maxC + $minC ) / 2;

		if ( $maxC === $minC ) {
			return array( 0.0, 0.0, $l );
		}

		$d = $maxC - $minC;
		$s = ( $l > 0.5 ) ? $d / ( 2 - $maxC - $minC ) : $d / ( $maxC + $minC );

		if ( $maxC === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $maxC === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}
		$h /= 6;

		return array( $h, $s, $l );
	}

	/**
	 * HSL to #rrggbb (h 0..1, s 0..1, l 0..1).
	 *
	 * @since 1.0.4
	 *
	 * @param float $h Hue.
	 * @param float $s Saturation.
	 * @param float $l Lightness.
	 * @return string
	 */
	private static function hsl_to_hex( float $h, float $s, float $l ): string {
		$h = $h - floor( $h );

		if ( $s <= 0.0001 ) {
			$v = (int) round( $l * 255 );
			return self::rgb_to_hex( array( $v, $v, $v ) );
		}

		$q = ( $l < 0.5 ) ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p = 2 * $l - $q;

		$hueToRgb = static function ( float $t ) use ( $p, $q ): float {
			if ( $t < 0 ) {
				++$t;
			}
			if ( $t > 1 ) {
				--$t;
			}
			if ( $t < 1 / 6 ) {
				return $p + ( $q - $p ) * 6 * $t;
			}
			if ( $t < 1 / 2 ) {
				return $q;
			}
			if ( $t < 2 / 3 ) {
				return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
			}
			return $p;
		};

		return self::rgb_to_hex(
			array(
				(int) round( $hueToRgb( $h + 1 / 3 ) * 255 ),
				(int) round( $hueToRgb( $h ) * 255 ),
				(int) round( $hueToRgb( $h - 1 / 3 ) * 255 ),
			)
		);
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
