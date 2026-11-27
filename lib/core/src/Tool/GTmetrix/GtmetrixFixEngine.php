<?php

/**
 * GTmetrix audit → remediation mapping.
 *
 * Port of `WP_MCP_AI_GTmetrix_Fix_Engine` (Proposal 164 sections 164.94 and
 * 164.95): the matcher and the WPCode snippet emitter.
 *
 * ## Correction
 *
 * **Every fix that names an audit is returned.** WordPress broke out of the
 * loop after the first match, and both `third-party-recaptcha` and
 * `third-party-duplicate-ga` name the `third-party-summary` audit, so the
 * duplicate-GA advice was unreachable: the engine always answered the reCAPTCHA
 * fix, even when the flagged third party was an analytics tag. The row the
 * matcher receives (`id`, `title`, `display_value`, `impact`) cannot tell the
 * two apart, so the port returns both and lets the caller or the human decide
 * from the audit detail. Each fix carries the `matched_audit` that produced it.
 *
 * A diagnostic audit — no impact score — never produces a fix: it describes
 * the page rather than a cost. The `unfixable` list names the audits that are a
 * host or third-party concern, so an agent does not report them as gaps.
 *
 * ## The snippet
 *
 * `generateSnippet()` emits a self-contained PHP file with an `ABSPATH` guard,
 * one commented block per matched finding, and only the blocks that finding
 * names. A `high`-risk block is commented out under a staging warning, because
 * dequeuing a script is a change that needs a browser, not a review.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

final class GtmetrixFixEngine
{
    /**
     * The remediation table. `audit_ids` are Lighthouse audit ids or the
     * report-derived keys the toolkit scans for.
     *
     * @var array<string, array{
     *     audit_ids: list<string>,
     *     symptom: string,
     *     action: string,
     *     target: string,
     *     risk: string,
     *     block: string,
     *     code_type: string,
     *     conditional: array<string, string>
     * }>
     */
    public const FIXES = array(
        'lcp-lazy-loaded'           => array(
            'audit_ids'   => array('lcp-lazy-loaded'),
            'symptom'     => 'The LCP image is lazy-loaded, delaying the largest paint.',
            'action'      => 'Exclude the hero image from lazy-load: set the Elementor Image widget to "Lazy Load: No", or add a filter to stop WordPress adding loading="lazy" to the first content image, or exclude it in Breeze lazy-load settings.',
            'target'      => 'Elementor widget / WordPress core / Breeze',
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
            'audit_ids'   => array('uses-optimized-images', 'uses-responsive-images'),
            'symptom'     => 'Images are served in heavier formats or at full size.',
            'action'      => 'Convert PNGs to WebP/AVIF (Breeze, ShortPixel or Imagify) and recompress large JPEGs to quality ~82. For srcset gaps, register an intermediate size with add_image_size() and regenerate thumbnails.',
            'target'      => 'Breeze / ShortPixel / Imagify / media settings',
            'risk'        => 'low',
            'block'       => 'image-weight',
            'code_type'   => 'php',
            'conditional' => array(),
        ),
        'font-display'              => array(
            'audit_ids'   => array('font-display'),
            'symptom'     => 'Text is invisible while webfonts load.',
            'action'      => 'Set font-display: swap for Elementor Pro custom fonts via the elementor_pro_custom_fonts_font_display filter, redeclare theme @font-face rules with swap, and preload the 2-3 critical woff2 files.',
            'target'      => 'Elementor Pro / child theme CSS',
            'risk'        => 'medium',
            'block'       => 'font-display-swap',
            'code_type'   => 'php',
            'conditional' => array(),
        ),
        'render-blocking-resources' => array(
            'audit_ids'   => array('render-blocking-resources'),
            'symptom'     => 'Render-blocking requests chain in the head.',
            'action'      => 'Remove hardcoded CDN jQuery/jQuery UI script tags (WordPress core already ships jquery-ui-core) and dequeue FontAwesome v4 shims if no legacy fa-* icons are used. Test menus, popups and sliders on staging.',
            'target'      => 'Header template / WPCode HTML snippet',
            'risk'        => 'high',
            'block'       => 'dequeue-fa4-shims',
            'code_type'   => 'php',
            'conditional' => array(),
        ),
        'unused-css-rules'          => array(
            'audit_ids'   => array('unused-css-rules'),
            'symptom'     => 'Plugin CSS loads site-wide where the plugin UI is absent.',
            'action'      => 'Dequeue the plugin stylesheet on pages that do not use its UI via a URL-based style_loader_src filter. Verify the handle against the live page source first.',
            'target'      => 'WPCode PHP snippet',
            'risk'        => 'high',
            'block'       => 'conditional-plugin-css',
            'code_type'   => 'php',
            'conditional' => array(),
        ),
        'third-party-recaptcha'     => array(
            'audit_ids'   => array('third-party-summary'),
            'symptom'     => 'reCAPTCHA (~348 KB) loads on every page.',
            'action'      => 'Preferred: switch the form to Cloudflare Turnstile or hCaptcha (native Elementor Pro Form options, ~30 KB). Alternative: lazy-load reCAPTCHA on first interaction. Test form submissions on staging.',
            'target'      => 'Elementor Pro Form widget / WPCode snippet',
            'risk'        => 'high',
            'block'       => 'lazy-recaptcha',
            'code_type'   => 'php',
            'conditional' => array(),
        ),
        'third-party-duplicate-ga'  => array(
            'audit_ids'   => array('third-party-summary'),
            'symptom'     => 'The same analytics tag fires multiple times (GTM + direct + local plugin).',
            'action'      => 'Keep ONE analytics path (usually GTM alone). Remove the local-GA plugin and find the direct enqueue by grepping for the measurement ID; disable duplicate plugins instead of hacking around them.',
            'target'      => 'Plugin deactivation / snippet cleanup',
            'risk'        => 'medium',
            'block'       => '',
            'code_type'   => '',
            'conditional' => array(),
        ),
        'cache-busting'             => array(
            'audit_ids'   => array('_code-scan-cache-busting'),
            'symptom'     => 'rand()/time() enqueue versions defeat browser and CDN caching.',
            'action'      => 'Replace rand()/time() script/style versions with (string) ( filemtime( $file ) ?: 1 ) so assets cache until they actually change.',
            'target'      => 'functions.php / WPCode snippet',
            'risk'        => 'low',
            'block'       => 'filemtime-cache-busting',
            'code_type'   => 'php',
            'conditional' => array(),
        ),
        'cls-shifts'                => array(
            'audit_ids'   => array('layout-shifts', 'cls'),
            'symptom'     => 'Visible content shifts during load (dimensionless SVGs, above-fold animations).',
            'action'      => 'Set fixed width/height or aspect-ratio on SVGs and banners, and remove Elementor entrance animations above the fold.',
            'target'      => 'Widget settings / child theme CSS',
            'risk'        => 'medium',
            'block'       => 'cls-stabilize',
            'code_type'   => 'css',
            'conditional' => array(),
        ),
    );

    /**
     * Audits the engine deliberately does not "fix" from the site.
     */
    public const UNFIXABLE = array(
        'uses-long-cache-ttl',
        'third-party-facades',
        'legacy-javascript',
        'dom-size',
        'uses-text-compression',
    );

    /**
     * Match impact-ordered audits to fix records.
     *
     * @param list<array<string, mixed>> $rows Rows from GtmetrixScoreEngine::impactOrder().
     *
     * @return array{
     *     fixes: list<array<string, mixed>>,
     *     unfixable: list<array<string, mixed>>,
     *     unknown: list<array<string, mixed>>
     * }
     */
    public static function match(array $rows): array
    {
        $fixes     = array();
        $unfixable = array();
        $unknown   = array();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';

            if ('' === $id) {
                continue;
            }

            // A diagnostic has no impact score and describes rather than costs.
            if (! is_numeric($row['impact'] ?? null)) {
                continue;
            }

            $matched = false;

            foreach (self::FIXES as $fix) {
                if (! in_array($id, $fix['audit_ids'], true)) {
                    continue;
                }

                $fix['matched_audit'] = $id;
                $fix['impact']        = $row['impact'] + 0;
                $fixes[]              = $fix;
                $matched              = true;
            }

            if ($matched) {
                continue;
            }

            $entry = array(
                'id'            => $id,
                'title'         => is_scalar($row['title'] ?? null) && '' !== trim((string) $row['title']) ? (string) $row['title'] : $id,
                'display_value' => is_scalar($row['display_value'] ?? null) ? (string) $row['display_value'] : '',
            );

            if (in_array($id, self::UNFIXABLE, true)) {
                $entry['reason'] = 'Third-party or host-level concern; not fixable from the site.';
                $unfixable[]     = $entry;
            } else {
                $unknown[] = $entry;
            }
        }

        return array(
            'fixes'     => $fixes,
            'unfixable' => $unfixable,
            'unknown'   => $unknown,
        );
    }

    /**
     * Emit a WPCode-ready PHP snippet for the matched fixes.
     *
     * A fix with no `block` is advice without code (deactivate a plugin, keep
     * one analytics path) and contributes a comment only.
     *
     * @param list<array<string, mixed>> $fixes  Matched fix records.
     * @param string                     $reportId Report id the snippet came from.
     * @param string                     $url      Tested page, or ''.
     */
    public static function generateSnippet(array $fixes, string $reportId, string $url = ''): string
    {
        $blocks = array();

        foreach ($fixes as $fix) {
            $block = is_scalar($fix['block'] ?? null) ? (string) $fix['block'] : '';

            if ('' === $block) {
                continue;
            }

            $blocks[ $block ] = $fix;
        }

        $lines = array(
            '<?php',
            '/**',
            ' * GTmetrix Performance Optimizations (WPCode snippet).',
            ' *',
            ' * Generated from GTmetrix report: ' . $reportId,
        );

        if ('' !== $url) {
            $lines[] = ' * Page: ' . $url;
        }

        $lines = array_merge(
            $lines,
            array(
                ' *',
                ' * RULES FOR USE:',
                " *   - Only blocks matching this report's findings are included.",
                ' *   - Safe blocks are enabled; risky blocks stay commented out',
                ' *     until verified on STAGING.',
                ' *   - Verify handle names and URLs against the live page view-source.',
                ' *',
                ' * Deploy: WPCode -> Add Snippet -> PHP -> Insert method: Auto Insert ->',
                ' * Run Everywhere. Enable blocks one at a time and re-test.',
                ' */',
                '',
                "if ( ! defined( 'ABSPATH' ) ) {",
                "\texit; // Prevent direct access.",
                '}',
                '',
            )
        );

        foreach ($blocks as $block => $fix) {
            $symptom = is_scalar($fix['symptom'] ?? null) ? (string) $fix['symptom'] : '';
            $action  = is_scalar($fix['action'] ?? null) ? (string) $fix['action'] : '';
            $risk    = is_scalar($fix['risk'] ?? null) ? (string) $fix['risk'] : '';

            $lines[] = '/*';
            $lines[] = ' * Fix: ' . $symptom;
            $lines[] = ' * Action: ' . $action;
            $lines[] = ' */';

            if ('high' === $risk) {
                $lines[] = '/* TEST ON STAGING BEFORE ENABLING - RISK: HIGH. */';
                $lines[] = '/*';
            }

            $lines = array_merge($lines, self::blockCode($block));

            if ('high' === $risk) {
                $lines[] = '*/';
            }

            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The code for one block, or an empty list for an unknown block.
     *
     * @return list<string>
     */
    private static function blockCode(string $block): array
    {
        switch ($block) {
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
                    <<<'JS'
	echo '<script>(function(){var s=document.createElement("script");s.src="https://www.google.com/recaptcha/api.js?render=explicit";s.async=true;var l=function(){document.head.appendChild(s);["mouseover","touchstart","keydown","scroll"].forEach(function(e){window.removeEventListener(e,l)});};["mouseover","touchstart","keydown","scroll"].forEach(function(e){window.addEventListener(e,l,{once:true})});})();</script>';
JS,
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
                    '/* CSS: fix layout shifts - give SVGs/banners fixed dimensions. */',
                    '/* .site-header svg { width: 180px; height: 40px; } */',
                );

            default:
                return array();
        }
    }
}
