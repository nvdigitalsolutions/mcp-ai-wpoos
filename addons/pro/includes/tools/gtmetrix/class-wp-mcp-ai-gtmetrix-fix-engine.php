<?php
/**
 * GTmetrix Toolkit — Fix Engine.
 *
 * Encodes the audit → WordPress-fix taxonomy (proposal Appendix B, condensed
 * from the design-performance-audit skill's common-fixes knowledge base):
 * every failing Lighthouse audit maps to concrete fixes with risk levels,
 * staging warnings and WPCode snippet blocks. Generates WPCode-ready PHP
 * snippets containing only the blocks that match a report's findings.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GTmetrix audit → fix engine.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_GTmetrix_Fix_Engine {

	/**
	 * Fix taxonomy. Keys are Lighthouse audit IDs (or report-derived keys).
	 *
	 * @return array<string, array>
	 */
	public static function fixes() {
		return array(
			'lcp-lazy-loaded'           => array(
				'audit_ids'   => array( 'lcp-lazy-loaded' ),
				'symptom'     => __( 'The LCP image is lazy-loaded, delaying the largest paint.', 'mcp-ai-wpoos-pro' ),
				'action'      => __( 'Exclude the hero image from lazy-load: set the Elementor Image widget to "Lazy Load: No", or add a filter to stop WordPress adding loading="lazy" to the first content image, or exclude it in Breeze lazy-load settings.', 'mcp-ai-wpoos-pro' ),
				'target'      => __( 'Elementor widget / WordPress core / Breeze', 'mcp-ai-wpoos-pro' ),
				'risk'        => 'low',
				'block'       => 'lcp-eager-hero',
				'code_type'   => 'php',
				'conditional' => array(
					'rule_type' => 'front_page',
					'operator'  => '=',
					'value'     => '1',
				),
			),
			'uses-optimized-images'     => array(
				'audit_ids'   => array( 'uses-optimized-images', 'uses-responsive-images' ),
				'symptom'     => __( 'Images are served in heavier formats or at full size.', 'mcp-ai-wpoos-pro' ),
				'action'      => __( 'Convert PNGs to WebP/AVIF (Breeze, ShortPixel or Imagify) and recompress large JPEGs to quality ~82. For srcset gaps, register an intermediate size with add_image_size() and regenerate thumbnails.', 'mcp-ai-wpoos-pro' ),
				'target'      => __( 'Breeze / ShortPixel / Imagify / media settings', 'mcp-ai-wpoos-pro' ),
				'risk'        => 'low',
				'block'       => 'image-weight',
				'code_type'   => 'php',
				'conditional' => array(),
			),
			'font-display'              => array(
				'audit_ids'   => array( 'font-display' ),
				'symptom'     => __( 'Text is invisible while webfonts load.', 'mcp-ai-wpoos-pro' ),
				'action'      => __( 'Set font-display: swap for Elementor Pro custom fonts via the elementor_pro_custom_fonts_font_display filter, redeclare theme @font-face rules with swap, and preload the 2–3 critical woff2 files.', 'mcp-ai-wpoos-pro' ),
				'target'      => __( 'Elementor Pro / child theme CSS', 'mcp-ai-wpoos-pro' ),
				'risk'        => 'medium',
				'block'       => 'font-display-swap',
				'code_type'   => 'php',
				'conditional' => array(),
			),
			'render-blocking-resources' => array(
				'audit_ids'   => array( 'render-blocking-resources' ),
				'symptom'     => __( 'Render-blocking requests chain in the head.', 'mcp-ai-wpoos-pro' ),
				'action'      => __( 'Remove hardcoded CDN jQuery/jQuery UI script tags (WordPress core already ships jquery-ui-core) and dequeue FontAwesome v4 shims if no legacy fa-* icons are used. Test menus, popups and sliders on staging.', 'mcp-ai-wpoos-pro' ),
				'target'      => __( 'Header template / WPCode HTML snippet', 'mcp-ai-wpoos-pro' ),
				'risk'        => 'high',
				'block'       => 'dequeue-fa4-shims',
				'code_type'   => 'php',
				'conditional' => array(),
			),
			'unused-css-rules'          => array(
				'audit_ids'   => array( 'unused-css-rules' ),
				'symptom'     => __( 'Plugin CSS loads site-wide where the plugin UI is absent.', 'mcp-ai-wpoos-pro' ),
				'action'      => __( 'Dequeue the plugin stylesheet on pages that do not use its UI via a URL-based style_loader_src filter. Verify the handle against the live page source first.', 'mcp-ai-wpoos-pro' ),
				'target'      => __( 'WPCode PHP snippet', 'mcp-ai-wpoos-pro' ),
				'risk'        => 'high',
				'block'       => 'conditional-plugin-css',
				'code_type'   => 'php',
				'conditional' => array(),
			),
			'third-party-recaptcha'     => array(
				'audit_ids'   => array( 'third-party-summary' ),
				'symptom'     => __( 'reCAPTCHA (~348 KB) loads on every page.', 'mcp-ai-wpoos-pro' ),
				'action'      => __( 'Preferred: switch the form to Cloudflare Turnstile or hCaptcha (native Elementor Pro Form options, ~30 KB). Alternative: lazy-load reCAPTCHA on first interaction. Test form submissions on staging.', 'mcp-ai-wpoos-pro' ),
				'target'      => __( 'Elementor Pro Form widget / WPCode snippet', 'mcp-ai-wpoos-pro' ),
				'risk'        => 'high',
				'block'       => 'lazy-recaptcha',
				'code_type'   => 'php',
				'conditional' => array(),
			),
			'third-party-duplicate-ga'  => array(
				'audit_ids'   => array( 'third-party-summary' ),
				'symptom'     => __( 'The same analytics tag fires multiple times (GTM + direct + local plugin).', 'mcp-ai-wpoos-pro' ),
				'action'      => __( 'Keep ONE analytics path (usually GTM alone). Remove the local-GA plugin and find the direct enqueue by grepping for the measurement ID; disable duplicate plugins instead of hacking around them.', 'mcp-ai-wpoos-pro' ),
				'target'      => __( 'Plugin deactivation / snippet cleanup', 'mcp-ai-wpoos-pro' ),
				'risk'        => 'medium',
				'block'       => '',
				'code_type'   => '',
				'conditional' => array(),
			),
			'cache-busting'             => array(
				'audit_ids'   => array( '_code-scan-cache-busting' ),
				'symptom'     => __( 'rand()/time() enqueue versions defeat browser and CDN caching.', 'mcp-ai-wpoos-pro' ),
				'action'      => __( 'Replace rand()/time() script/style versions with (string) ( filemtime( $file ) ?: 1 ) so assets cache until they actually change.', 'mcp-ai-wpoos-pro' ),
				'target'      => __( 'functions.php / WPCode snippet', 'mcp-ai-wpoos-pro' ),
				'risk'        => 'low',
				'block'       => 'filemtime-cache-busting',
				'code_type'   => 'php',
				'conditional' => array(),
			),
			'cls-shifts'                => array(
				'audit_ids'   => array( 'layout-shifts', 'cls' ),
				'symptom'     => __( 'Visible content shifts during load (dimensionless SVGs, above-fold animations).', 'mcp-ai-wpoos-pro' ),
				'action'      => __( 'Set fixed width/height or aspect-ratio on SVGs and banners, and remove Elementor entrance animations above the fold.', 'mcp-ai-wpoos-pro' ),
				'target'      => __( 'Widget settings / child theme CSS', 'mcp-ai-wpoos-pro' ),
				'risk'        => 'medium',
				'block'       => 'cls-stabilize',
				'code_type'   => 'css',
				'conditional' => array(),
			),
		);
	}

	/**
	 * Match failing audits (impact-ordered) to fix records.
	 *
	 * @param array $audits Lighthouse audits block.
	 * @return array{fixes: array, unfixable: array, unknown: array}
	 */
	public static function match( $audits ) {
		$fixes     = array();
		$unfixable = array();
		$unknown   = array();

		foreach ( $audits as $row ) {
			$id = isset( $row['id'] ) ? $row['id'] : '';
			if ( '' === $id ) {
				continue;
			}

			// Diagnostics (no impact score) never generate fixes.
			if ( null === $row['impact'] ) {
				continue;
			}

			$matched = false;
			foreach ( self::fixes() as $fix ) {
				if ( in_array( $id, $fix['audit_ids'], true ) ) {
					$fix['matched_audit'] = $id;
					$fix['impact']        = $row['impact'];
					$fixes[]              = $fix;
					$matched              = true;
					break;
				}
			}

			if ( $matched ) {
				continue;
			}

			if ( self::is_unfixable( $id ) ) {
				$unfixable[] = array(
					'id'            => $id,
					'title'         => isset( $row['title'] ) ? $row['title'] : $id,
					'display_value' => isset( $row['display_value'] ) ? $row['display_value'] : '',
					'reason'        => __( 'Third-party or host-level concern; not fixable from the site.', 'mcp-ai-wpoos-pro' ),
				);
			} else {
				$unknown[] = array(
					'id'            => $id,
					'title'         => isset( $row['title'] ) ? $row['title'] : $id,
					'display_value' => isset( $row['display_value'] ) ? $row['display_value'] : '',
				);
			}
		}

		return array(
			'fixes'     => $fixes,
			'unfixable' => $unfixable,
			'unknown'   => $unknown,
		);
	}

	/**
	 * Audits the engine deliberately does not "fix" from the site.
	 *
	 * @param string $audit_id Lighthouse audit ID.
	 * @return bool
	 */
	private static function is_unfixable( $audit_id ) {
		$unfixable = array(
			'uses-long-cache-ttl',
			'third-party-facades',
			'legacy-javascript',
			'dom-size',
			'uses-text-compression',
		);

		return in_array( $audit_id, $unfixable, true );
	}

	/**
	 * Generate a WPCode-ready PHP snippet from matched fixes.
	 *
	 * Only blocks matching the findings are included; risky blocks are
	 * commented out with a staging warning.
	 *
	 * @param array $fixes        Matched fix records (from match()).
	 * @param array $context      Snippet context: report_id, url.
	 * @return string PHP snippet source.
	 */
	public static function generate_snippet( $fixes, $context = array() ) {
		$report_id = isset( $context['report_id'] ) ? sanitize_text_field( $context['report_id'] ) : 'unknown';
		$url       = isset( $context['url'] ) ? esc_url_raw( $context['url'] ) : '';

		$blocks = array();
		foreach ( $fixes as $fix ) {
			if ( '' === $fix['block'] ) {
				continue; // Config-only fixes carry no code block.
			}
			$blocks[ $fix['block'] ] = $fix;
		}

		$lines   = array();
		$lines[] = '<?php';
		$lines[] = '/**';
		$lines[] = ' * GTmetrix Performance Optimizations (WPCode snippet).';
		$lines[] = ' *';
		$lines[] = ' * Generated from GTmetrix report: ' . $report_id;
		if ( '' !== $url ) {
			$lines[] = ' * Page: ' . $url;
		}
		$lines[] = ' *';
		$lines[] = ' * RULES FOR USE:';
		$lines[] = ' *   - Only blocks matching this report\'s findings are included.';
		$lines[] = ' *   - Safe blocks are enabled; risky blocks stay commented out';
		$lines[] = ' *     until verified on STAGING.';
		$lines[] = ' *   - Verify handle names and URLs against the live page view-source.';
		$lines[] = ' *';
		$lines[] = ' * Deploy: WPCode → Add Snippet → PHP → Insert method: Auto Insert →';
		$lines[] = ' * Run Everywhere. Enable blocks one at a time and re-test.';
		$lines[] = ' */';
		$lines[] = '';
		$lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
		$lines[] = "\texit; // Prevent direct access.";
		$lines[] = '}';
		$lines[] = '';

		foreach ( $blocks as $block => $fix ) {
			$lines[] = '/*';
			$lines[] = ' * Fix: ' . $fix['symptom'];
			$lines[] = ' * Action: ' . $fix['action'];
			$lines[] = ' */';
			if ( 'high' === $fix['risk'] ) {
				$lines[] = '/* TEST ON STAGING BEFORE ENABLING — RISK: HIGH. */';
				$lines[] = '/*';
			}
			$lines = array_merge( $lines, self::block_code( $block ) );
			if ( 'high' === $fix['risk'] ) {
				$lines[] = '*/';
			}
			$lines[] = '';
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Snippet block templates.
	 *
	 * @param string $block Block name.
	 * @return string[]
	 */
	private static function block_code( $block ) {
		switch ( $block ) {
			case 'lcp-eager-hero':
				return array(
					'// Stop WordPress adding loading="lazy" to the first content image.',
					"add_filter( 'wp_omit_loading_attr_threshold', function () {",
					"\treturn 1;",
					'} );',
				);

			case 'font-display-swap':
				return array(
					'// Ensure Elementor Pro custom fonts swap (text stays visible).',
					"add_filter( 'elementor_pro_custom_fonts_font_display', function () {",
					"\treturn 'swap';",
					'} );',
				);

			case 'dequeue-fa4-shims':
				return array(
					'// Dequeue FontAwesome v4 shims (remove only if no legacy fa-* icons).',
					"add_action( 'wp_enqueue_scripts', function () {",
					"\twp_dequeue_style( 'font-awesome-4-shim-css' );",
					"\twp_dequeue_script( 'font-awesome-4-shim-js' );",
					'}, 999 );',
				);

			case 'conditional-plugin-css':
				return array(
					'// Dequeue a plugin stylesheet everywhere except its own pages.',
					'// Verify the handle and the URL patterns against view-source first.',
					"add_filter( 'style_loader_src', function ( $src, $handle ) {",
					"\tif ( 'REPLACE_WITH_HANDLE' !== $handle || is_page( 'REPLACE_WITH_SLUG' ) ) {",
					"\t\treturn $src;",
					"\t}",
					"\treturn false;",
					'}, 10, 2 );',
				);

			case 'lazy-recaptcha':
				return array(
					'// Lazy-load reCAPTCHA on first interaction (experimental).',
					"add_action( 'wp_enqueue_scripts', function () {",
					"\twp_dequeue_script( 'elementor-recaptcha_v3-api' );",
					'} );',
					"add_action( 'wp_footer', function () {",
					"\techo '<script>(function(){var s=document.createElement(\"script\");s.src=\"https://www.google.com/recaptcha/api.js?render=explicit\";s.async=true;var l=function(){document.head.appendChild(s);[\"mouseover\",\"touchstart\",\"keydown\",\"scroll\"].forEach(function(e){window.removeEventListener(e,l)});};[\"mouseover\",\"touchstart\",\"keydown\",\"scroll\"].forEach(function(e){window.addEventListener(e,l,{once:true})});})();</script>';",
					'} );',
				);

			case 'filemtime-cache-busting':
				return array(
					'// Replace rand()/time() enqueue versions with filemtime so assets',
					'// cache until they actually change.',
					'// Example: wp_enqueue_script( \'handle\', $url, array(), (string) ( filemtime( $file ) ?: 1 ) );',
				);

			case 'cls-stabilize':
				return array(
					'/* CSS: fix layout shifts — give SVGs/banners fixed dimensions. */',
					'/* .site-header svg { width: 180px; height: 40px; } */',
				);

			default:
				return array();
		}
	}
}
