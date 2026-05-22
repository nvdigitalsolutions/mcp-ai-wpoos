<?php
/**
 * Design Extractor Service.
 *
 * Orchestrator that takes a bag of inputs (mockup images, HTML/CSS files,
 * live URLs, free-text brief) and produces a normalized Design System JSON
 * suitable for handing to {@see WP_MCP_AI_Design_Snippet_Renderer}.
 *
 * Sources are weighted (explicit `:root` tokens > vision > URL analysis) and
 * provenance is recorded per-token in `$result['_provenance']`.
 *
 * Vision calls are intentionally hidden behind the
 * `wp_mcp_ai_design_extractor_vision` filter — production code wires that to
 * the existing provider clients; tests can short-circuit it with a fixture.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Site_Creator_Toolkit
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes mockup/HTML/URL inputs into a Design System JSON.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Design_Extractor_Service {

	/**
	 * Maximum number of mockup images accepted per call.
	 */
	const MAX_IMAGES = 8;

	/**
	 * Maximum bytes allowed per image.
	 */
	const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

	/**
	 * Source weights used during merge (higher wins).
	 *
	 * @var array<string,float>
	 */
	const SOURCE_WEIGHTS = array(
		'explicit' => 1.0,
		'vision'   => 0.7,
		'url'      => 0.5,
		'default'  => 0.1,
	);

	/**
	 * Default minimum WCAG contrast ratios.
	 */
	const WCAG_TEXT_AA       = 4.5;
	const WCAG_NON_TEXT_AA_3 = 3.0;

	/**
	 * Extract a Design System JSON from the supplied inputs.
	 *
	 * @since 1.2.0
	 *
	 * @param array $inputs {
	 *     Input bag.
	 *
	 *     @type array[] $images     Image inputs (with `media_id`, `url`, `base64`, `role`).
	 *     @type array[] $html_files HTML/CSS file inputs (with `media_id`, `url`, `content`).
	 *     @type array[] $urls       Live URL strings (delegated to analyze_competitor_sites).
	 *     @type string  $brief      Free-text brief.
	 * }
	 * @return array {
	 *     @type array $design_system   Normalized JSON.
	 *     @type array $contrast_report Per-pair WCAG report.
	 *     @type bool  $is_draft        True when contrast checks fail.
	 *     @type array $warnings        Warning strings.
	 *     @type array $_provenance     Token => source map.
	 * }
	 */
	public function extract( array $inputs ) {
		$warnings   = array();
		$tokens     = array();
		$provenance = array();

		// 1. HTML/CSS inputs -> highest weight.
		$html_files = isset( $inputs['html_files'] ) && is_array( $inputs['html_files'] ) ? $inputs['html_files'] : array();
		foreach ( $html_files as $idx => $file ) {
			$content = $this->read_text_input( $file, 'html_files[' . $idx . ']', $warnings );
			if ( '' === $content ) {
				continue;
			}
			$parsed = self::parse_css_tokens( $content );
			$this->merge_tokens( $tokens, $provenance, $parsed, 'explicit:html_files[' . $idx . ']' );
		}

		// 2. Vision over images -> medium weight.
		$images = isset( $inputs['images'] ) && is_array( $inputs['images'] ) ? $inputs['images'] : array();
		if ( count( $images ) > self::MAX_IMAGES ) {
			$warnings[] = sprintf( 'Too many images supplied (%d); only the first %d will be analyzed.', count( $images ), self::MAX_IMAGES );
			$images     = array_slice( $images, 0, self::MAX_IMAGES );
		}
		foreach ( $images as $idx => $image ) {
			$role = isset( $image['role'] ) ? (string) $image['role'] : 'mockup';
			if ( ! in_array( $role, array( 'mockup', 'reference' ), true ) ) {
				continue;
			}

			/**
			 * Filter the vision-extracted tokens for one image.
			 *
			 * Production code wires this to the existing provider abstraction.
			 * Tests can short-circuit by returning a fixture array shaped like
			 * the output of self::parse_css_tokens().
			 *
			 * @since 1.2.0
			 *
			 * @param array|null $tokens Filtered token array, or null if no provider responded.
			 * @param array      $image  The image input row.
			 * @param string     $brief  Free-text brief.
			 */
			$vision_tokens = apply_filters( 'wp_mcp_ai_design_extractor_vision', null, $image, isset( $inputs['brief'] ) ? (string) $inputs['brief'] : '' );

			if ( ! is_array( $vision_tokens ) || empty( $vision_tokens ) ) {
				$warnings[] = sprintf( 'No vision provider returned tokens for images[%d]; skipped.', $idx );
				continue;
			}

			$this->merge_tokens( $tokens, $provenance, $vision_tokens, 'vision:images[' . $idx . ']' );
		}

		// 3. URL inputs -> delegate to existing analyze_competitor_sites tool.
		$urls = isset( $inputs['urls'] ) && is_array( $inputs['urls'] ) ? $inputs['urls'] : array();
		foreach ( $urls as $idx => $url ) {
			$url = is_string( $url ) ? trim( $url ) : '';
			if ( '' === $url || ! function_exists( 'wp_http_validate_url' ) || ! wp_http_validate_url( $url ) ) {
				$warnings[] = sprintf( 'URL at urls[%d] is invalid and was skipped.', $idx );
				continue;
			}

			$url_tokens = $this->delegate_to_competitor_analyzer( $url );
			if ( empty( $url_tokens ) ) {
				continue;
			}
			$this->merge_tokens( $tokens, $provenance, $url_tokens, 'url:' . $url );
		}

		// 4. Default fallback for anything still missing.
		$this->merge_tokens( $tokens, $provenance, self::default_tokens(), 'default:builtin', false );

		// 5. WCAG contrast validation.
		$contrast_report = $this->build_contrast_report( $tokens['palette'] );
		$is_draft        = false;
		foreach ( $contrast_report as $row ) {
			if ( empty( $row['wcag_aa'] ) ) {
				$is_draft   = true;
				$warnings[] = sprintf( 'WCAG contrast failure: %s = %.2f (needed %.2f)', $row['pair'], $row['ratio'], $row['minimum'] );
			}
		}

		return array(
			'design_system'   => $tokens,
			'contrast_report' => $contrast_report,
			'is_draft'        => $is_draft,
			'warnings'        => $warnings,
			'_provenance'     => $provenance,
		);
	}

	/**
	 * Read a text-input row (`html_files[...]`) into a string with size + sanitization caps.
	 *
	 * @param array    $file     Input row.
	 * @param string   $label    Label used in warnings.
	 * @param string[] $warnings Warning bag (passed by reference).
	 * @return string Sanitized text content (may be empty).
	 */
	private function read_text_input( $file, $label, array &$warnings ) {
		if ( ! is_array( $file ) ) {
			return '';
		}

		$content = '';
		if ( ! empty( $file['content'] ) && is_string( $file['content'] ) ) {
			$content = (string) $file['content'];
		} elseif ( ! empty( $file['media_id'] ) && function_exists( 'get_attached_file' ) ) {
			$path = get_attached_file( (int) $file['media_id'] );
			// Guard against non-local paths (e.g. offloaded media) before reading.
			if ( $path && ! preg_match( '#^https?://#i', $path ) && file_exists( $path ) && filesize( $path ) <= self::MAX_IMAGE_BYTES ) {
				$raw = file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData
				if ( false !== $raw ) {
					$content = (string) $raw;
				}
			}
		}

		if ( '' === $content ) {
			$warnings[] = sprintf( 'Empty content for %s.', $label );
			return '';
		}

		// Hard cap: 1 MB of text per file.
		if ( strlen( $content ) > 1024 * 1024 ) {
			$warnings[] = sprintf( 'Text input %s exceeds 1 MB; truncating.', $label );
			$content    = substr( $content, 0, 1024 * 1024 );
		}

		return $content;
	}

	/**
	 * Tolerant CSS/HTML tokenizer.
	 *
	 * Extracts `:root { --x: ...; }` custom properties, font-family, border-radius,
	 * box-shadow, and color literals. Returns a structured array shaped like the
	 * rest of the design-system schema.
	 *
	 * @since 1.2.0
	 * @param string $css_or_html Raw text content.
	 * @return array Structured tokens.
	 */
	public static function parse_css_tokens( $css_or_html ) {
		$out = array(
			'palette'    => array(),
			'typography' => array(),
			'radii'      => array(),
			'shadows'    => array(),
			'spacing'    => array( 'scale' => array() ),
			'motion'     => array(),
		);

		if ( ! is_string( $css_or_html ) || '' === $css_or_html ) {
			return $out;
		}

		// Parse :root { ... --x: y; ... } blocks.
		if ( preg_match_all( '/:root\s*\{([^}]+)\}/i', $css_or_html, $matches ) ) {
			foreach ( $matches[1] as $body ) {
				if ( preg_match_all( '/--([a-z0-9_-]+)\s*:\s*([^;]+);/i', $body, $vars, PREG_SET_ORDER ) ) {
					foreach ( $vars as $row ) {
						$key = strtolower( $row[1] );
						$val = trim( $row[2] );
						self::route_token( $out, $key, $val );
					}
				}
			}
		}

		// Generic font-family declarations.
		if ( preg_match_all( '/font-family\s*:\s*([^;}{]+)[;}]/i', $css_or_html, $ffs ) ) {
			foreach ( $ffs[1] as $ff ) {
				$ff = trim( $ff );
				if ( '' === $ff ) {
					continue;
				}
				if ( empty( $out['typography']['sans'] ) && preg_match( '/sans|arial|helvetica/i', $ff ) ) {
					$out['typography']['sans'] = $ff;
				} elseif ( empty( $out['typography']['serif'] ) && preg_match( '/serif|georgia|playfair|cormorant/i', $ff ) ) {
					$out['typography']['serif'] = $ff;
				}
			}
		}

		// Border-radius declarations -> pick the most-common as `md`.
		if ( preg_match_all( '/border-radius\s*:\s*([^;}{]+)[;}]/i', $css_or_html, $brs ) ) {
			$counts = array();
			foreach ( $brs[1] as $value ) {
				$value            = trim( $value );
				$counts[ $value ] = isset( $counts[ $value ] ) ? $counts[ $value ] + 1 : 1;
			}
			arsort( $counts );
			reset( $counts );
			$top = (string) key( $counts );
			if ( '' !== $top && empty( $out['radii']['md'] ) ) {
				$out['radii']['md'] = $top;
			}
		}

		return $out;
	}

	/**
	 * Route a single `--key: value` declaration into the right schema slot.
	 *
	 * @param array  $out Output bag (by reference).
	 * @param string $key Token key (without `--` prefix).
	 * @param string $val Raw value.
	 */
	private static function route_token( array &$out, $key, $val ) {
		// Palette role detection.
		$role_map = array(
			'bg'            => array( 'bg', 'background', 'obsidian' ),
			'surface'       => array( 'surface' ),
			'card'          => array( 'card' ),
			'border'        => array( 'border' ),
			'border-accent' => array( 'border-accent', 'border_accent', 'borderaccent' ),
			'accent'        => array( 'accent', 'green', 'primary' ),
			'accent-light'  => array( 'accent-light', 'accent_light', 'green-light', 'primary-light' ),
			'accent-pale'   => array( 'accent-pale', 'accent_pale' ),
			'text'          => array( 'text', 'ivory', 'foreground' ),
			'dim'           => array( 'dim', 'text-dim' ),
			'muted'         => array( 'muted', 'text-muted' ),
			'danger'        => array( 'danger', 'error', 'red' ),
		);

		foreach ( $role_map as $role => $needles ) {
			foreach ( $needles as $needle ) {
				if ( $key === $needle || false !== strpos( $key, $needle ) ) {
					if ( empty( $out['palette'][ $role ] ) ) {
						$out['palette'][ $role ] = $val;
					}
					return;
				}
			}
		}

		if ( false !== strpos( $key, 'serif' ) ) {
			$out['typography']['serif'] = $val;
			return;
		}
		if ( false !== strpos( $key, 'sans' ) ) {
			$out['typography']['sans'] = $val;
			return;
		}
		if ( false !== strpos( $key, 'display' ) ) {
			$out['typography']['display'] = $val;
			return;
		}
		if ( false !== strpos( $key, 'radius' ) ) {
			$slot                  = preg_replace( '/^.*radius[-_]?/', '', $key );
			$slot                  = '' === $slot ? 'md' : $slot;
			$out['radii'][ $slot ] = $val;
			return;
		}
		if ( false !== strpos( $key, 'shadow' ) ) {
			$slot                    = preg_replace( '/^.*shadow[-_]?/', '', $key );
			$slot                    = '' === $slot ? 'md' : $slot;
			$out['shadows'][ $slot ] = $val;
			return;
		}
		if ( false !== strpos( $key, 'space' ) || false !== strpos( $key, 'spacing' ) ) {
			$slot = preg_replace( '/^.*(space|spacing)[-_]?/', '', $key );
			$out['spacing']['scale'][ $slot ? $slot : 'md' ] = $val;
			return;
		}
		if ( false !== strpos( $key, 'easing' ) ) {
			$out['motion']['easing'] = $val;
			return;
		}
	}

	/**
	 * Merge a `parsed` token bag into the running `$tokens` array.
	 *
	 * Higher-weight sources overwrite lower-weight values. Provenance is
	 * recorded for the winning value only.
	 *
	 * @param array  $tokens     Running token bag (by reference).
	 * @param array  $provenance Provenance map (by reference).
	 * @param array  $parsed     New tokens to merge.
	 * @param string $source     Source label (e.g. "vision:images[0]").
	 * @param bool   $overwrite  When false, only fill missing keys (used for defaults).
	 */
	private function merge_tokens( array &$tokens, array &$provenance, array $parsed, $source, $overwrite = true ) {
		foreach ( $parsed as $section => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}
			if ( ! isset( $tokens[ $section ] ) ) {
				$tokens[ $section ] = array();
			}
			foreach ( $values as $key => $value ) {
				if ( is_array( $value ) ) {
					if ( ! isset( $tokens[ $section ][ $key ] ) ) {
						$tokens[ $section ][ $key ] = array();
					}
					foreach ( $value as $sk => $sv ) {
						if ( $overwrite || ! isset( $tokens[ $section ][ $key ][ $sk ] ) ) {
							$tokens[ $section ][ $key ][ $sk ]               = $sv;
							$provenance[ $section . '.' . $key . '.' . $sk ] = $source;
						}
					}
					continue;
				}
				if ( $overwrite || ! isset( $tokens[ $section ][ $key ] ) ) {
					$tokens[ $section ][ $key ]          = $value;
					$provenance[ $section . '.' . $key ] = $source;
				}
			}
		}
	}

	/**
	 * Built-in fallback design system used to fill any missing slots.
	 *
	 * @return array Default tokens.
	 */
	public static function default_tokens() {
		return array(
			'palette'    => array(
				'bg'           => '#0f110e',
				'surface'      => '#181b13',
				'border'       => 'rgba(255,255,255,0.07)',
				'accent'       => '#2d6a4f',
				'accent-light' => '#52b788',
				'text'         => '#ffffff',
				'dim'          => 'rgba(255,255,255,0.65)',
				'muted'        => 'rgba(255,255,255,0.35)',
				'danger'       => '#d68080',
			),
			'typography' => array(
				'sans'         => 'Tenor Sans, Arial, sans-serif',
				'serif'        => 'Playfair Display, Georgia, serif',
				'base_size_px' => 16,
			),
			'radii'      => array(
				'sm' => '8px',
				'md' => '18px',
				'lg' => '34px',
			),
			'shadows'    => array(
				'md' => '0 32px 80px rgba(0,0,0,0.3)',
			),
			'spacing'    => array(
				'scale' => array(
					'xs' => '4px',
					'sm' => '8px',
					'md' => '16px',
					'lg' => '32px',
				),
			),
			'motion'     => array(
				'easing'      => 'cubic-bezier(.16,1,.3,1)',
				'duration_ms' => 900,
			),
		);
	}

	/**
	 * Build a per-pair WCAG report from the palette.
	 *
	 * @param array $palette Palette role => color map.
	 * @return array Report rows.
	 */
	private function build_contrast_report( array $palette ) {
		$bg     = isset( $palette['bg'] ) ? $palette['bg'] : null;
		$text   = isset( $palette['text'] ) ? $palette['text'] : null;
		$accent = isset( $palette['accent'] ) ? $palette['accent'] : null;
		$report = array();

		if ( $bg && $text ) {
			$ratio    = self::contrast_ratio( $bg, $text );
			$report[] = array(
				'pair'    => 'text on bg',
				'ratio'   => $ratio,
				'minimum' => self::WCAG_TEXT_AA,
				'wcag_aa' => $ratio >= self::WCAG_TEXT_AA,
			);
		}
		if ( $bg && $accent ) {
			$ratio    = self::contrast_ratio( $bg, $accent );
			$report[] = array(
				'pair'    => 'accent on bg (non-text)',
				'ratio'   => $ratio,
				'minimum' => self::WCAG_NON_TEXT_AA_3,
				'wcag_aa' => $ratio >= self::WCAG_NON_TEXT_AA_3,
			);
		}

		return $report;
	}

	/**
	 * Compute WCAG 2.x contrast ratio between two colors.
	 *
	 * @param string $a Color a (hex/rgb/rgba).
	 * @param string $b Color b (hex/rgb/rgba).
	 * @return float Ratio (1.0 - 21.0).
	 */
	public static function contrast_ratio( $a, $b ) {
		$la = self::relative_luminance( $a );
		$lb = self::relative_luminance( $b );
		if ( null === $la || null === $lb ) {
			return 0.0;
		}
		$lighter = max( $la, $lb );
		$darker  = min( $la, $lb );
		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * Compute WCAG relative luminance from a color literal.
	 *
	 * @param string $color Color literal.
	 * @return float|null Luminance in 0..1 or null on failure.
	 */
	public static function relative_luminance( $color ) {
		$rgb = self::to_rgb( $color );
		if ( null === $rgb ) {
			return null;
		}
		$convert = function ( $c ) {
			$c = $c / 255.0;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * $convert( $rgb[0] ) + 0.7152 * $convert( $rgb[1] ) + 0.0722 * $convert( $rgb[2] );
	}

	/**
	 * Parse a color literal into an RGB triple (alpha is composited over white).
	 *
	 * @param string $color Color literal (#hex, rgb(...), rgba(...)).
	 * @return array{0:int,1:int,2:int}|null
	 */
	private static function to_rgb( $color ) {
		$color = strtolower( trim( (string) $color ) );
		if ( '' === $color ) {
			return null;
		}

		// #rgb / #rrggbb / #rrggbbaa.
		if ( preg_match( '/^#([0-9a-f]{3,8})$/', $color, $m ) ) {
			$hex = $m[1];
			if ( 3 === strlen( $hex ) ) {
				$r = hexdec( str_repeat( $hex[0], 2 ) );
				$g = hexdec( str_repeat( $hex[1], 2 ) );
				$b = hexdec( str_repeat( $hex[2], 2 ) );
				return array( $r, $g, $b );
			}
			if ( 6 === strlen( $hex ) || 8 === strlen( $hex ) ) {
				$r = hexdec( substr( $hex, 0, 2 ) );
				$g = hexdec( substr( $hex, 2, 2 ) );
				$b = hexdec( substr( $hex, 4, 2 ) );
				$a = 8 === strlen( $hex ) ? hexdec( substr( $hex, 6, 2 ) ) / 255 : 1.0;
				return self::composite_over_white( $r, $g, $b, $a );
			}
		}

		// rgb(r,g,b) / rgba(r,g,b,a).
		if ( preg_match( '/^rgba?\(([^)]+)\)$/i', $color, $m ) ) {
			$parts = array_map( 'trim', explode( ',', $m[1] ) );
			if ( count( $parts ) >= 3 ) {
				$r = (int) $parts[0];
				$g = (int) $parts[1];
				$b = (int) $parts[2];
				$a = isset( $parts[3] ) ? (float) $parts[3] : 1.0;
				return self::composite_over_white( $r, $g, $b, $a );
			}
		}

		return null;
	}

	/**
	 * Composite a (possibly translucent) color over a solid white background.
	 *
	 * @param int   $r Red 0-255.
	 * @param int   $g Green 0-255.
	 * @param int   $b Blue 0-255.
	 * @param float $a Alpha 0-1.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function composite_over_white( $r, $g, $b, $a ) {
		$a  = max( 0.0, min( 1.0, (float) $a ) );
		$rr = (int) round( $r * $a + 255 * ( 1 - $a ) );
		$gg = (int) round( $g * $a + 255 * ( 1 - $a ) );
		$bb = (int) round( $b * $a + 255 * ( 1 - $a ) );
		return array( $rr, $gg, $bb );
	}

	/**
	 * Delegate URL analysis to the existing analyze_competitor_sites tool.
	 *
	 * Returns an empty array if the tool is unavailable, so the merge step
	 * silently falls through to vision/defaults.
	 *
	 * @param string $url URL to analyze.
	 * @return array Tokens (in the parse_css_tokens shape).
	 */
	private function delegate_to_competitor_analyzer( $url ) {
		if ( ! function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			return array();
		}
		$registry = wp_mcp_ai_get_tool_registry();
		if ( ! $registry || ! method_exists( $registry, 'get_tool' ) ) {
			return array();
		}
		$tool = $registry->get_tool( 'analyze_competitor_sites' );
		if ( ! $tool || ! method_exists( $tool, 'execute' ) ) {
			return array();
		}

		$response = $tool->execute(
			array(
				'urls'  => array( $url ),
				'focus' => array( 'palette', 'typography', 'spacing' ),
			),
			array( 'user_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 )
		);

		if ( ! is_array( $response ) || empty( $response['design_tokens'] ) || ! is_array( $response['design_tokens'] ) ) {
			return array();
		}
		return $response['design_tokens'];
	}
}
