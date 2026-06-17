<?php
/**
 * Design Snippet Renderer.
 *
 * Pure-PHP renderer that converts a normalized Design System JSON into a single
 * install-ready PHP "site design snippet" — a file shaped exactly like the
 * Aerlinn-style examples (custom cursor + scroll reveal + header scrolled state
 * + JFB luxury form skin). The renderer performs no I/O and makes no HTTP calls,
 * so it is fully unit-testable with golden-file fixtures.
 *
 * The output PHP file is safe to paste into WPCode (PHP snippet, location
 * "everywhere") or to drop into `wp-content/mu-plugins/`.
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
 * Pure renderer for Site Creator design snippets.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Design_Snippet_Renderer {

	/**
	 * Snippet shape version. Bumped only when the emitted file structure
	 * changes in a backwards-incompatible way.
	 */
	const SNIPPET_SHAPE_VERSION = '1.0.0';

	/**
	 * Allowed skin variants for the JFB skin block.
	 *
	 * @var string[]
	 */
	const SKIN_VARIANTS = array( 'luxury', 'panel', 'minimal' );

	/**
	 * Allowed interaction features.
	 *
	 * @var string[]
	 */
	const FEATURES = array(
		'custom_cursor',
		'scroll_reveal',
		'header_scroll_state',
		'mobile_drawer',
		'rotating_steps',
		'hover_link_underline',
	);

	/**
	 * Allowed render targets.
	 *
	 * @var string[]
	 */
	const TARGETS = array( 'wordpress', 'elementor', 'jet-form-builder' );

	/**
	 * Render a complete install-ready PHP snippet.
	 *
	 * @since 1.2.0
	 *
	 * @param array $design_system Normalized Design System JSON.
	 * @param array $options       {
	 *     Render options.
	 *
	 *     @type string[] $features      Opt-in feature list (subset of self::FEATURES).
	 *     @type string[] $targets       Active stack targets (subset of self::TARGETS).
	 *     @type string   $skin_variant  Skin variant: luxury|panel|minimal|auto.
	 *     @type bool     $is_draft      Mark snippet as DRAFT in header (e.g. failed contrast).
	 *     @type string   $generated_at  ISO-8601 timestamp for the header (testable).
	 *     @type string   $fingerprint   Short hex fingerprint for the header (testable).
	 *     @type array    $provenance    Provenance map (token => source) for header comment.
	 *     @type array    $contrast_report Contrast report rows for header comment.
	 * }
	 * @return string The complete PHP file contents.
	 */
	public static function render( array $design_system, array $options = array() ) {
		$options = self::normalize_options( $options );

		$skin_variant = self::pick_skin_variant( $design_system, $options['skin_variant'] );

		$tokens_css       = self::render_tokens_css( $design_system );
		$interactions_css = self::render_interactions_css( $options['features'] );
		$jfb_css          = in_array( 'jet-form-builder', $options['targets'], true )
			? self::render_jfb_skin_css( $skin_variant )
			: '';
		$interactions_js  = self::render_interactions_js( $options['features'] );
		$cursor_markup    = in_array( 'custom_cursor', $options['features'], true )
			? "<div id=\"nv-cursor\" aria-hidden=\"true\"></div>\n\t<div id=\"nv-cursor-ring\" aria-hidden=\"true\"></div>"
			: '';

		$header = self::render_file_header( $design_system, $options, $skin_variant );

		$head_block = trim( $tokens_css . "\n\n" . $interactions_css . ( '' !== $jfb_css ? "\n\n" . $jfb_css : '' ) );
		$body_parts = array();
		if ( '' !== $cursor_markup ) {
			$body_parts[] = $cursor_markup;
		}
		if ( '' !== $interactions_js ) {
			$body_parts[] = '<script id="nv-aerlinn-effects-js">' . "\n" . $interactions_js . "\n" . '</script>';
		}
		$footer_block = implode( "\n\t", $body_parts );

		$out  = "<?php\n";
		$out .= $header;
		$out .= "\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n";

		$out .= "add_action( 'wp_head', function () {\n\t?>\n\t<style id=\"nv-aerlinn-effects-css\">\n";
		$out .= self::indent_block( $head_block, 2 );
		$out .= "\n\t</style>\n\t<?php\n}, 99 );\n";

		if ( '' !== $footer_block ) {
			$out .= "\nadd_action( 'wp_footer', function () {\n\t?>\n\t";
			$out .= $footer_block;
			$out .= "\n\t<?php\n}, 99 );\n";
		}

		return $out;
	}

	/**
	 * Render only the tokens CSS block (used by the `package` output format).
	 *
	 * @since 1.2.0
	 * @param array $design_system Design System JSON.
	 * @return string CSS rules; no surrounding <style> tags.
	 */
	public static function render_tokens_css( array $design_system ) {
		$palette    = isset( $design_system['palette'] ) && is_array( $design_system['palette'] ) ? $design_system['palette'] : array();
		$typography = isset( $design_system['typography'] ) && is_array( $design_system['typography'] ) ? $design_system['typography'] : array();
		$radii      = isset( $design_system['radii'] ) && is_array( $design_system['radii'] ) ? $design_system['radii'] : array();
		$shadows    = isset( $design_system['shadows'] ) && is_array( $design_system['shadows'] ) ? $design_system['shadows'] : array();
		$spacing    = isset( $design_system['spacing'] ) && is_array( $design_system['spacing'] ) ? $design_system['spacing'] : array();
		$motion     = isset( $design_system['motion'] ) && is_array( $design_system['motion'] ) ? $design_system['motion'] : array();

		$lines = array( ':root {' );

		// Palette role tokens.
		$role_keys = array( 'bg', 'surface', 'card', 'border', 'border-accent', 'accent', 'accent-light', 'accent-pale', 'text', 'dim', 'muted', 'danger' );
		foreach ( $role_keys as $role ) {
			if ( ! empty( $palette[ $role ] ) ) {
				$lines[] = sprintf( "\t--nv-%s: %s;", $role, self::sanitize_color( $palette[ $role ] ) );
			}
		}

		// Typography.
		if ( ! empty( $typography['serif'] ) ) {
			$lines[] = sprintf( "\t--nv-font-serif: %s;", self::sanitize_font_stack( $typography['serif'] ) );
		}
		if ( ! empty( $typography['display'] ) ) {
			$lines[] = sprintf( "\t--nv-font-display: %s;", self::sanitize_font_stack( $typography['display'] ) );
		}
		if ( ! empty( $typography['sans'] ) ) {
			$lines[] = sprintf( "\t--nv-font-sans: %s;", self::sanitize_font_stack( $typography['sans'] ) );
		}
		if ( ! empty( $typography['base_size_px'] ) ) {
			$base_size_px = max( 10, min( 24, (int) $typography['base_size_px'] ) );
			$min_rem      = number_format( $base_size_px * 0.875 / 16, 4, '.', '' );
			$max_rem      = number_format( $base_size_px / 16, 4, '.', '' );
			$lines[]      = sprintf( "\t--nv-font-size-base: clamp(%srem, 0.9rem + 0.25vw, %srem);", $min_rem, $max_rem );
		}

		// Spacing scale (fluid).
		if ( ! empty( $spacing['scale'] ) && is_array( $spacing['scale'] ) ) {
			foreach ( $spacing['scale'] as $name => $value ) {
				$lines[] = sprintf( "\t--nv-space-%s: %s;", self::sanitize_token_key( $name ), self::sanitize_length( $value ) );
			}
		}

		// Radii.
		foreach ( $radii as $name => $value ) {
			$lines[] = sprintf( "\t--nv-radius-%s: %s;", self::sanitize_token_key( $name ), self::sanitize_length( $value ) );
		}

		// Shadows.
		foreach ( $shadows as $name => $value ) {
			$lines[] = sprintf( "\t--nv-shadow-%s: %s;", self::sanitize_token_key( $name ), self::sanitize_shadow( $value ) );
		}

		// Motion / easing curves.
		if ( ! empty( $motion['easing'] ) ) {
			$lines[] = sprintf( "\t--nv-easing: %s;", self::sanitize_easing( $motion['easing'] ) );
		}
		if ( ! empty( $motion['duration_ms'] ) ) {
			$lines[] = sprintf( "\t--nv-duration: %dms;", max( 50, min( 5000, (int) $motion['duration_ms'] ) ) );
		}

		$lines[] = '}';

		// Page baseline that also locks horizontal overflow (matches example 3).
		$lines[] = '';
		$lines[] = 'html, body {';
		$lines[] = "\toverflow-x: hidden;";
		$lines[] = '}';

		return implode( "\n", $lines );
	}

	/**
	 * Render the interactions CSS for the opted-in feature list.
	 *
	 * @since 1.2.0
	 * @param string[] $features Feature list.
	 * @return string CSS rules.
	 */
	public static function render_interactions_css( array $features ) {
		$out = array();

		// Always-on: respect prefers-reduced-motion.
		$out[] = '@media (prefers-reduced-motion: reduce) {';
		$out[] = "\t.nv-reveal, .nv-reveal.nv-visible, .nv-scroll-nav { transition: none !important; transform: none !important; opacity: 1 !important; }";
		$out[] = '}';

		if ( in_array( 'custom_cursor', $features, true ) ) {
			$out[] = '';
			$out[] = '@media (hover: hover) and (pointer: fine) {';
			$out[] = "\tbody.nv-aerlinn-global { cursor: none; }";
			$out[] = '}';
			$out[] = '@media (hover: none), (pointer: coarse), (max-width: 1024px) {';
			$out[] = "\t#nv-cursor, #nv-cursor-ring { display: none !important; }";
			$out[] = '}';
			$out[] = '#nv-cursor { width: 8px; height: 8px; border-radius: 50%; background: var(--nv-accent-light, #52b788); position: fixed; left: 0; top: 0; pointer-events: none; z-index: 99999; transform: translate(-50%, -50%); transition: width .2s ease, height .2s ease, background .2s ease, opacity .2s ease; opacity: 0; }';
			$out[] = '#nv-cursor-ring { width: 36px; height: 36px; border-radius: 50%; border: 1px solid rgba(82,183,136,0.45); position: fixed; left: 0; top: 0; pointer-events: none; z-index: 99998; transform: translate(-50%, -50%); transition: width .2s ease, height .2s ease, border-color .2s ease, opacity .2s ease; opacity: 0; }';
			$out[] = 'body.nv-cursor-ready #nv-cursor, body.nv-cursor-ready #nv-cursor-ring { opacity: 1; }';
			$out[] = 'body.nv-cursor-hover #nv-cursor { width: 14px; height: 14px; }';
			$out[] = 'body.nv-cursor-hover #nv-cursor-ring { width: 52px; height: 52px; border-color: var(--nv-accent, #2d6a4f); }';
		}

		if ( in_array( 'scroll_reveal', $features, true ) ) {
			$out[] = '';
			$out[] = '.nv-reveal { opacity: 0; transform: translateY(32px); transition: opacity .9s cubic-bezier(.16,1,.3,1), transform .9s cubic-bezier(.16,1,.3,1); will-change: opacity, transform; }';
			$out[] = '.nv-reveal.nv-visible { opacity: 1; transform: translateY(0); }';
			$out[] = '.nv-reveal-delay-1 { transition-delay: .1s; }';
			$out[] = '.nv-reveal-delay-2 { transition-delay: .2s; }';
			$out[] = '.nv-reveal-delay-3 { transition-delay: .3s; }';
		}

		if ( in_array( 'header_scroll_state', $features, true ) ) {
			$out[] = '';
			$out[] = '.nv-scroll-nav { transition: padding .4s ease, background .4s ease, backdrop-filter .4s ease, border-color .4s ease, box-shadow .4s ease; }';
			$out[] = '.nv-scroll-nav.nv-scrolled { background: rgba(10,12,8,0.97); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid var(--nv-border, rgba(255,255,255,0.07)); }';
		}

		if ( in_array( 'hover_link_underline', $features, true ) ) {
			$out[] = '';
			$out[] = '.nv-hover-link { position: relative; text-decoration: none; transition: color .3s ease; }';
			$out[] = '.nv-hover-link::after { content: ""; position: absolute; left: 0; right: 0; bottom: -4px; height: 1px; background: var(--nv-accent, #2d6a4f); transform: scaleX(0); transform-origin: center; transition: transform .3s ease; }';
			$out[] = '.nv-hover-link:hover::after, .nv-hover-link:focus::after { transform: scaleX(1); }';
			$out[] = '.nv-outline-accent { border: 1px solid var(--nv-border-accent, rgba(52,107,62,0.35)); transition: all .3s ease; }';
			$out[] = '.nv-outline-accent:hover, .nv-outline-accent:focus { background: var(--nv-accent-pale, rgba(45,106,79,0.09)); border-color: var(--nv-accent, #2d6a4f); color: var(--nv-accent-light, #52b788); }';
		}

		if ( in_array( 'mobile_drawer', $features, true ) ) {
			$out[] = '';
			$out[] = '.nv-hamburger { display: inline-flex; flex-direction: column; justify-content: center; gap: 5px; width: 44px; height: 44px; background: none; border: none; padding: 8px; cursor: pointer; -webkit-tap-highlight-color: transparent; }';
			$out[] = '.nv-hamburger span { display: block; width: 22px; height: 1px; background: currentColor; transition: all .3s ease; transform-origin: center; }';
			$out[] = '.nv-hamburger.nv-open span:nth-child(1) { transform: translateY(6px) rotate(45deg); }';
			$out[] = '.nv-hamburger.nv-open span:nth-child(2) { opacity: 0; transform: scaleX(0); }';
			$out[] = '.nv-hamburger.nv-open span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }';
			$out[] = '.nv-drawer { display: none; position: fixed; inset: 0; z-index: 9990; background: var(--nv-bg, #0f110e); flex-direction: column; align-items: center; justify-content: center; gap: 2rem; }';
			$out[] = '.nv-drawer.nv-open { display: flex; }';
		}

		if ( in_array( 'rotating_steps', $features, true ) ) {
			$out[] = '';
			$out[] = '.nv-hiw-step { transition: all .3s ease; }';
			$out[] = '.nv-hiw-step.is-active .step-title, .nv-hiw-step.is-active .elementor-heading-title { color: var(--nv-accent-light, #52b788); }';
		}

		return implode( "\n", $out );
	}

	/**
	 * Render the JFB form skin CSS for the requested variant.
	 *
	 * Selectors are scoped strictly under `.jet-form-builder` so the snippet
	 * never leaks into the rest of the site.
	 *
	 * @since 1.2.0
	 * @param string $variant Skin variant: luxury|panel|minimal.
	 * @return string CSS rules.
	 */
	public static function render_jfb_skin_css( $variant ) {
		$variant = in_array( $variant, self::SKIN_VARIANTS, true ) ? $variant : 'luxury';
		$out     = array();

		// Shared base across all variants.
		$out[] = '/* JFB skin (variant: ' . $variant . ') */';
		$out[] = '.jet-form-builder { color: var(--nv-text, #fff); font-family: var(--nv-font-sans, sans-serif); }';
		$out[] = '.jet-form-builder .jet-form-builder__label, .jet-form-builder .jet-form-builder__label-text, .jet-form-builder label { color: var(--nv-dim, rgba(255,255,255,0.66)); font-size: .78rem; letter-spacing: .28em; text-transform: uppercase; font-weight: 400; margin-bottom: 10px; }';
		$out[] = '.jet-form-builder input[type="text"], .jet-form-builder input[type="email"], .jet-form-builder input[type="tel"], .jet-form-builder input[type="number"], .jet-form-builder input[type="url"], .jet-form-builder input[type="date"], .jet-form-builder select, .jet-form-builder textarea { width: 100% !important; background: rgba(255,255,255,0.03) !important; color: var(--nv-text, #fff) !important; border: 1px solid var(--nv-border, rgba(255,255,255,0.09)) !important; outline: none !important; box-shadow: none !important; transition: border-color .25s ease, background .25s ease; font-family: inherit; font-size: 1rem; }';
		$out[] = '.jet-form-builder input:focus, .jet-form-builder select:focus, .jet-form-builder textarea:focus { border-color: var(--nv-border-accent, rgba(82,183,136,0.45)) !important; background: rgba(255,255,255,0.05) !important; }';
		$out[] = '.jet-form-builder input::placeholder, .jet-form-builder textarea::placeholder { color: rgba(255,255,255,0.34); }';
		$out[] = '.jet-form-builder input[type="radio"], .jet-form-builder input[type="checkbox"] { width: 20px !important; height: 20px !important; min-width: 20px; accent-color: var(--nv-accent-light, #52b788); }';

		if ( 'luxury' === $variant ) {
			$out[] = '.jet-form-builder { max-width: 1100px; margin: 0 auto; padding: clamp(28px, 4vw, 48px); background: var(--nv-surface, #181b13); border: 1px solid var(--nv-border, rgba(255,255,255,0.07)); border-radius: 34px; box-shadow: 0 32px 80px rgba(0,0,0,0.30), inset 0 1px 0 rgba(255,255,255,0.03); }';
			$out[] = '.jet-form-builder input[type="text"], .jet-form-builder input[type="email"], .jet-form-builder input[type="tel"], .jet-form-builder input[type="number"], .jet-form-builder input[type="url"], .jet-form-builder input[type="date"], .jet-form-builder select, .jet-form-builder textarea { border-radius: 18px !important; padding: 18px 20px !important; }';
			$out[] = '.jet-form-builder button, .jet-form-builder .jet-form-builder__action-button, .jet-form-builder input[type="submit"] { appearance: none; border-radius: 999px !important; border: 1px solid var(--nv-border-accent, rgba(82,183,136,0.35)) !important; background: linear-gradient(135deg, var(--nv-accent, #2d6a4f), #1b4332) !important; color: #fff !important; padding: 16px 30px !important; font-size: .72rem; letter-spacing: .18em; text-transform: uppercase; cursor: pointer; transition: transform .2s ease, border-color .2s ease, opacity .2s ease; }';
			$out[] = '.jet-form-builder button:hover, .jet-form-builder .jet-form-builder__action-button:hover, .jet-form-builder input[type="submit"]:hover { transform: translateY(-1px); border-color: var(--nv-accent-light, rgba(82,183,136,0.65)) !important; }';
		} elseif ( 'panel' === $variant ) {
			$out[] = '.jet-form-builder { max-width: 720px; margin: 0 auto; padding: 0 !important; border-radius: 0 !important; background: #151a13 !important; border: 1px solid var(--nv-border, rgba(255,255,255,0.09)) !important; box-shadow: none !important; position: relative; }';
			$out[] = '.jet-form-builder::before { content: "\u25cf  \u25cf  \u25cf"; display: flex; align-items: center; height: 52px; padding: 0 30px; color: rgba(255,255,255,0.14); letter-spacing: .55em; font-size: .8rem; border-bottom: 1px solid var(--nv-border, rgba(255,255,255,0.08)); }';
			$out[] = '.jet-form-builder > *:not(.jet-form-builder-progress-pages) { margin-left: 30px; margin-right: 30px; }';
			$out[] = '.jet-form-builder input[type="text"], .jet-form-builder input[type="email"], .jet-form-builder select, .jet-form-builder textarea { border-radius: 0 !important; background: #10160f !important; padding: 14px 16px !important; font-size: .9rem !important; }';
			$out[] = '.jet-form-builder button, .jet-form-builder .jet-form-builder__action-button, .jet-form-builder input[type="submit"] { width: 100%; border-radius: 0 !important; background: linear-gradient(90deg, var(--nv-accent, #2d7d5a), var(--nv-accent-light, #58bd8b)) !important; border: 0 !important; padding: 16px 24px !important; letter-spacing: .22em; color: #fff; cursor: pointer; }';
		} else { // minimal.
			$out[] = '.jet-form-builder { max-width: 640px; margin: 0 auto; padding: 24px; background: transparent; border: 0; border-radius: 0; box-shadow: none; }';
			$out[] = '.jet-form-builder input[type="text"], .jet-form-builder input[type="email"], .jet-form-builder select, .jet-form-builder textarea { border-radius: 4px !important; padding: 12px 14px !important; }';
			$out[] = '.jet-form-builder button, .jet-form-builder .jet-form-builder__action-button, .jet-form-builder input[type="submit"] { background: var(--nv-accent, #2d6a4f) !important; color: #fff !important; border: 0 !important; border-radius: 4px !important; padding: 12px 24px !important; cursor: pointer; }';
		}

		// Progress bar (matches example 3).
		$out[] = '.jet-form-builder-progress-pages { position: relative; width: 100%; padding: 0 0 42px !important; margin-bottom: 42px !important; border: 0 !important; }';
		$out[] = '.jet-form-builder-progress-pages::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: rgba(255,255,255,0.08); }';
		$out[] = '.jet-form-builder-progress-pages::after { content: ""; position: absolute; top: 0; left: 0; height: 1px; width: var(--aerlinn-progress, 25%); background: var(--nv-accent-light, #52b788); transition: width .3s ease; }';
		$out[] = '.jet-form-builder-progress-pages__item--label { color: rgba(255,255,255,0.55); font-size: .68rem; letter-spacing: .34em; text-transform: uppercase; white-space: nowrap; }';
		$out[] = '.jet-form-builder-progress-pages__item--wrapper.active-page .jet-form-builder-progress-pages__item--label, .jet-form-builder-progress-pages__item--wrapper.passed-page .jet-form-builder-progress-pages__item--label { color: rgba(255,255,255,0.92); font-weight: 600; }';

		// Mobile.
		$out[] = '@media (max-width: 767px) {';
		$out[] = "\t.jet-form-builder { padding: 24px 18px; border-radius: 0; }";
		$out[] = "\t.jet-form-builder button, .jet-form-builder .jet-form-builder__action-button, .jet-form-builder input[type=\"submit\"] { width: 100%; }";
		$out[] = '}';

		return implode( "\n", $out );
	}

	/**
	 * Render the interactions JS IIFE.
	 *
	 * @since 1.2.0
	 * @param string[] $features Feature list.
	 * @return string JS source (no <script> tags).
	 */
	public static function render_interactions_js( array $features ) {
		if ( empty( $features ) ) {
			return '';
		}

		$lines   = array();
		$lines[] = '(function () {';
		$lines[] = "\tdocument.addEventListener('DOMContentLoaded', function () {";
		$lines[] = "\t\tvar body = document.body;";
		$lines[] = "\t\tif (body && !body.classList.contains('nv-aerlinn-global')) { body.classList.add('nv-aerlinn-global'); }";
		$lines[] = "\t\tvar reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;";

		if ( in_array( 'custom_cursor', $features, true ) ) {
			$lines[] = "\t\tvar cursor = document.getElementById('nv-cursor');";
			$lines[] = "\t\tvar ring = document.getElementById('nv-cursor-ring');";
			$lines[] = "\t\tvar finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;";
			$lines[] = "\t\tif (cursor && ring && finePointer && !reduceMotion) {";
			$lines[] = "\t\t\tvar mx = window.innerWidth / 2, my = window.innerHeight / 2, rx = mx, ry = my;";
			$lines[] = "\t\t\tbody.classList.add('nv-cursor-ready');";
			$lines[] = "\t\t\tdocument.addEventListener('mousemove', function (e) { mx = e.clientX; my = e.clientY; cursor.style.left = mx + 'px'; cursor.style.top = my + 'px'; }, { passive: true });";
			$lines[] = "\t\t\tdocument.addEventListener('mouseleave', function () { body.classList.remove('nv-cursor-hover'); }, { passive: true });";
			$lines[] = "\t\t\tfunction animateRing() { rx += (mx - rx) * 0.12; ry += (my - ry) * 0.12; ring.style.left = rx + 'px'; ring.style.top = ry + 'px'; window.requestAnimationFrame(animateRing); }";
			$lines[] = "\t\t\tanimateRing();";
			$lines[] = "\t\t\tdocument.addEventListener('mouseover', function (e) { if (e.target.closest('a, button, .elementor-button, [role=\"button\"], input[type=\"submit\"], input[type=\"button\"]')) { body.classList.add('nv-cursor-hover'); } });";
			$lines[] = "\t\t\tdocument.addEventListener('mouseout', function (e) { if (e.target.closest('a, button, .elementor-button, [role=\"button\"], input[type=\"submit\"], input[type=\"button\"]')) { body.classList.remove('nv-cursor-hover'); } });";
			$lines[] = "\t\t}";
		}

		if ( in_array( 'header_scroll_state', $features, true ) ) {
			$lines[] = "\t\tvar nav = document.querySelector('.nv-scroll-nav');";
			$lines[] = "\t\tif (nav) { var updateNav = function () { nav.classList.toggle('nv-scrolled', window.scrollY > 60); }; updateNav(); window.addEventListener('scroll', updateNav, { passive: true }); }";
		}

		if ( in_array( 'scroll_reveal', $features, true ) ) {
			$lines[] = "\t\tvar reveals = document.querySelectorAll('.nv-reveal');";
			$lines[] = "\t\tif (reveals.length && 'IntersectionObserver' in window && !reduceMotion) {";
			$lines[] = "\t\t\tvar observer = new IntersectionObserver(function (entries, obs) { entries.forEach(function (entry) { if (entry.isIntersecting) { entry.target.classList.add('nv-visible'); obs.unobserve(entry.target); } }); }, { threshold: 0.12 });";
			$lines[] = "\t\t\treveals.forEach(function (el) { observer.observe(el); });";
			$lines[] = "\t\t} else if (reveals.length) { reveals.forEach(function (el) { el.classList.add('nv-visible'); }); }";
		}

		if ( in_array( 'mobile_drawer', $features, true ) ) {
			$lines[] = "\t\tvar hamburger = document.getElementById('nv-hamburger'), drawer = document.getElementById('nv-drawer');";
			$lines[] = "\t\tif (hamburger && drawer) {";
			$lines[] = "\t\t\tvar closeDrawer = function () { hamburger.classList.remove('nv-open'); drawer.classList.remove('nv-open'); body.style.overflow = ''; };";
			$lines[] = "\t\t\thamburger.addEventListener('click', function () { var isOpen = drawer.classList.toggle('nv-open'); hamburger.classList.toggle('nv-open', isOpen); body.style.overflow = isOpen ? 'hidden' : ''; });";
			$lines[] = "\t\t\tdrawer.querySelectorAll('a').forEach(function (link) { link.addEventListener('click', closeDrawer); });";
			$lines[] = "\t\t}";
		}

		if ( in_array( 'rotating_steps', $features, true ) ) {
			$lines[] = "\t\tvar steps = document.querySelectorAll('.nv-hiw-step');";
			$lines[] = "\t\tif (steps.length > 1 && !reduceMotion) {";
			$lines[] = "\t\t\tvar current = 0;";
			$lines[] = "\t\t\tfunction setStep(index) { steps.forEach(function (step, i) { step.classList.toggle('is-active', i === index); }); }";
			$lines[] = "\t\t\tsetStep(0); window.setInterval(function () { current = (current + 1) % steps.length; setStep(current); }, 3800);";
			$lines[] = "\t\t}";
		}

		$lines[] = "\t});";
		$lines[] = '})();';

		return implode( "\n", $lines );
	}

	/**
	 * Heuristically pick a JFB skin variant from the design system.
	 *
	 * Used when the caller passes `skin_variant: 'auto'`. Picks `panel` for
	 * very dark, low-radius palettes; `minimal` for high-radius, low-saturation
	 * palettes; `luxury` otherwise.
	 *
	 * @since 1.2.0
	 * @param array  $design_system Design System JSON.
	 * @param string $requested     Caller-supplied variant (or 'auto').
	 * @return string Selected variant.
	 */
	public static function pick_skin_variant( array $design_system, $requested ) {
		if ( in_array( $requested, self::SKIN_VARIANTS, true ) ) {
			return $requested;
		}

		$radii = isset( $design_system['radii'] ) && is_array( $design_system['radii'] ) ? $design_system['radii'] : array();
		$base  = isset( $radii['md'] ) ? self::parse_length_px( $radii['md'] ) : 12;

		if ( $base <= 4 ) {
			return 'panel';
		}
		if ( $base >= 24 ) {
			return 'luxury';
		}
		return 'minimal';
	}

	/**
	 * Normalize render options to safe defaults.
	 *
	 * @since 1.2.0
	 * @param array $options Caller options.
	 * @return array Normalized options.
	 */
	private static function normalize_options( array $options ) {
		$features = isset( $options['features'] ) && is_array( $options['features'] ) ? $options['features'] : array();
		$features = array_values( array_intersect( self::FEATURES, $features ) );
		if ( empty( $features ) ) {
			$features = array( 'scroll_reveal', 'header_scroll_state', 'hover_link_underline' );
		}

		$targets = isset( $options['targets'] ) && is_array( $options['targets'] ) ? $options['targets'] : array();
		$targets = array_values( array_intersect( self::TARGETS, $targets ) );
		if ( empty( $targets ) ) {
			$targets = self::TARGETS;
		}

		$skin = isset( $options['skin_variant'] ) ? (string) $options['skin_variant'] : 'auto';
		if ( ! in_array( $skin, self::SKIN_VARIANTS, true ) && 'auto' !== $skin ) {
			$skin = 'auto';
		}

		return array(
			'features'        => $features,
			'targets'         => $targets,
			'skin_variant'    => $skin,
			'is_draft'        => ! empty( $options['is_draft'] ),
			'generated_at'    => isset( $options['generated_at'] ) ? (string) $options['generated_at'] : gmdate( 'c' ),
			'fingerprint'     => isset( $options['fingerprint'] ) ? substr( preg_replace( '/[^a-f0-9]/i', '', (string) $options['fingerprint'] ), 0, 12 ) : '',
			'provenance'      => isset( $options['provenance'] ) && is_array( $options['provenance'] ) ? $options['provenance'] : array(),
			'contrast_report' => isset( $options['contrast_report'] ) && is_array( $options['contrast_report'] ) ? $options['contrast_report'] : array(),
		);
	}

	/**
	 * Render the file-level header comment.
	 *
	 * @since 1.2.0
	 * @param array  $design_system Design System JSON.
	 * @param array  $options       Normalized options.
	 * @param string $variant       Selected skin variant.
	 * @return string The PHPDoc header (with trailing newline).
	 */
	private static function render_file_header( array $design_system, array $options, $variant ) {
		$lines   = array();
		$lines[] = '/**';
		$lines[] = ' * Site Design Snippet — generated by NV oOS Site Creator Toolkit.';
		$lines[] = ' *';
		if ( $options['is_draft'] ) {
			$lines[] = ' * STATUS: DRAFT — one or more contrast checks failed; review before publishing.';
		} else {
			$lines[] = ' * STATUS: READY — all contrast checks passed.';
		}
		$lines[] = ' *';
		$lines[] = ' * Shape version : ' . self::SNIPPET_SHAPE_VERSION;
		$lines[] = ' * Generated at  : ' . $options['generated_at'];
		if ( '' !== $options['fingerprint'] ) {
			$lines[] = ' * Fingerprint   : ' . $options['fingerprint'];
		}
		$lines[] = ' * Skin variant  : ' . $variant;
		$lines[] = ' * Targets       : ' . implode( ', ', $options['targets'] );
		$lines[] = ' * Features      : ' . ( empty( $options['features'] ) ? '(none)' : implode( ', ', $options['features'] ) );

		if ( ! empty( $options['contrast_report'] ) ) {
			$lines[] = ' *';
			$lines[] = ' * Contrast report:';
			foreach ( $options['contrast_report'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$pair    = isset( $row['pair'] ) ? (string) $row['pair'] : '?';
				$ratio   = isset( $row['ratio'] ) ? number_format( (float) $row['ratio'], 2, '.', '' ) : '?';
				$ok      = ! empty( $row['wcag_aa'] ) ? 'PASS' : 'FAIL';
				$lines[] = ' *   ' . $pair . ' = ' . $ratio . ' (' . $ok . ')';
			}
		}

		if ( ! empty( $options['provenance'] ) ) {
			$lines[] = ' *';
			$lines[] = ' * Token provenance (most-significant only):';
			$shown   = 0;
			foreach ( $options['provenance'] as $token => $source ) {
				if ( $shown >= 12 ) {
					break;
				}
				$lines[] = ' *   ' . self::sanitize_token_key( $token ) . ' <- ' . preg_replace( '/[^a-z0-9_\-:\/.]/i', '', (string) $source );
				++$shown;
			}
		}

		$lines[] = ' *';
		$lines[] = ' * @package WP_MCP_AI_Pro';
		$lines[] = ' * @link    https://github.com/nvdigitalsolutions/mcp-ai-wpoos';
		$lines[] = ' * @credit  Snippet shape derived from Aerlinn-style luxury interaction patterns.';
		$lines[] = ' */';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Indent every line in a block by N tabs (used to nest CSS inside the heredoc style block).
	 *
	 * @param string $block The text to indent.
	 * @param int    $tabs  Number of leading tabs to add.
	 * @return string Indented text.
	 */
	private static function indent_block( $block, $tabs ) {
		$prefix = str_repeat( "\t", max( 0, (int) $tabs ) );
		$lines  = explode( "\n", $block );
		foreach ( $lines as $i => $line ) {
			$lines[ $i ] = '' === trim( $line ) ? '' : $prefix . $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Sanitize a hex / rgb / rgba color value.
	 *
	 * Rejects anything that doesn't look like a CSS color literal.
	 *
	 * @param string $value Raw color string.
	 * @return string Sanitized value or the literal `inherit` on failure.
	 */
	public static function sanitize_color( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 'inherit';
		}
		// Allow #fff, #ffffff, #ffffffff, rgb(...), rgba(...), hsl(...), hsla(...), and CSS keywords.
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return strtolower( $value );
		}
		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[0-9.\s,%\/-]+\s*\)$/i', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^[a-z]+$/i', $value ) ) {
			return strtolower( $value );
		}
		return 'inherit';
	}

	/**
	 * Sanitize a CSS length value (px/rem/em/clamp/calc).
	 *
	 * @param string $value Raw length string.
	 * @return string Sanitized value or `0` on failure.
	 */
	public static function sanitize_length( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '0';
		}
		// Allow simple numeric units, calc(), clamp(), min(), max(), var().
		if ( preg_match( '/^[0-9.\-]+(px|rem|em|vh|vw|%|ch|ex|svw|svh|lvw|lvh|dvw|dvh)?$/i', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^(calc|clamp|min|max|var)\([0-9a-z\s,.\-+\/*%()$_-]+\)$/i', $value ) ) {
			return $value;
		}
		return '0';
	}

	/**
	 * Sanitize a CSS box-shadow value (loose: must be safe characters only).
	 *
	 * @param string $value Raw shadow string.
	 * @return string Sanitized value or `none` on failure.
	 */
	public static function sanitize_shadow( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 'none';
		}
		// Allow standard shadow chars (no semicolons, no braces).
		if ( preg_match( '/^[0-9a-z\s,.\-#%()\/]+$/i', $value ) ) {
			return $value;
		}
		return 'none';
	}

	/**
	 * Sanitize a CSS easing curve.
	 *
	 * @param string $value Raw easing string.
	 * @return string Sanitized value or `ease` on failure.
	 */
	public static function sanitize_easing( $value ) {
		$value = trim( (string) $value );
		if ( in_array( $value, array( 'ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out', 'step-start', 'step-end' ), true ) ) {
			return $value;
		}
		if ( preg_match( '/^cubic-bezier\(\s*[\-0-9.\s,]+\s*\)$/', $value ) ) {
			return $value;
		}
		return 'ease';
	}

	/**
	 * Sanitize a CSS font-family stack.
	 *
	 * @param string $value Raw font-family string.
	 * @return string Sanitized stack or sans-serif on failure.
	 */
	public static function sanitize_font_stack( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 'sans-serif';
		}
		// Allow letters, digits, spaces, hyphens, underscores, commas, single + double quotes.
		if ( preg_match( '/^[a-z0-9 _\-,\'"]+$/i', $value ) ) {
			return $value;
		}
		return 'sans-serif';
	}

	/**
	 * Sanitize a token key (used as a CSS custom property suffix).
	 *
	 * @param string $value Raw key.
	 * @return string Lowercase, dash-only key.
	 */
	public static function sanitize_token_key( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		$value = trim( (string) $value, '-' );
		return '' === $value ? 'x' : $value;
	}

	/**
	 * Parse a length string into px (best-effort heuristic for skin-variant picker).
	 *
	 * @param string $value Length value.
	 * @return float Approximate pixel size.
	 */
	private static function parse_length_px( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^([0-9.]+)(px|rem|em)?$/', $value, $matches ) ) {
			$num  = (float) $matches[1];
			$unit = isset( $matches[2] ) ? $matches[2] : 'px';
			if ( 'rem' === $unit || 'em' === $unit ) {
				return $num * 16;
			}
			return $num;
		}
		return 12.0;
	}
}
