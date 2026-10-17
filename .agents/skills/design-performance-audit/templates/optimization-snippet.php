<?php
/**
 * {{SITE}} — Performance Optimizations (WPCode snippet)
 *
 * Generated from the GTmetrix review on {{DATE}}.
 * Targets: {{TOP_FINDINGS}} (edit this line to match the site's report).
 *
 * RULES FOR USE:
 *   - INCLUDE ONLY the blocks that match this site's findings.
 *   - Safe blocks (preconnect, font-display, CLS CSS) stay enabled.
 *   - Risky blocks stay commented out until verified on STAGING.
 *   - Verify handle names and URLs against the live page view-source.
 *
 * Deploy: WPCode → Add Snippet → PHP → Insert method: Auto Insert →
 * Run Everywhere. Enable blocks one at a time and re-test.
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1. PRECONNECT + DNS-PREFETCH (safe)
// Add only the third-party origins that actually appear in the waterfall:
// fonts.googleapis.com / fonts.gstatic.com / www.gstatic.com (reCAPTCHA) /
// Yotpo static hosts / googletagmanager.com / Brevo / etc.
// ============================================================================
add_action('wp_head', function() {
	$preconnect = array(
		'https://fonts.googleapis.com' => 'crossorigin',
		'https://fonts.gstatic.com'    => 'crossorigin',
		'https://www.gstatic.com'      => 'crossorigin',
		// 'https://staticw2.yotpo.com'   => '',
		// 'https://www.googletagmanager.com' => '',
	);
	$prefetch = array(
		// 'https://cdn.brevo.com',
		// 'https://api-cdn.yotpo.com',
	);

	foreach ( $preconnect as $url => $crossorigin ) {
		printf(
			"<link rel=\"preconnect\" href=\"%s\"%s />\n",
			esc_url( $url ),
			$crossorigin ? ' crossorigin' : ''
		);
	}
	foreach ( $prefetch as $url ) {
		printf( "<link rel=\"dns-prefetch\" href=\"%s\" />\n", esc_url( $url ) );
	}
}, 1);

// ============================================================================
// 2. FONT-DISPLAY: SWAP (safe)
// Fixes "Ensure text remains visible during webfont load".
// VERIFIED: elementor_pro_custom_fonts_font_display is IGNORED by Elementor
// Pro 4.x — the reliable fix is redeclaring the same @font-face rules (same
// family/weight/src) with font-display:swap; the browser uses the last
// matching declaration. Copy the family/weight/src from the live page's
// inline "Start Custom Fonts CSS" block and fill the rules below.
// ALSO check the child theme style.css @font-face blocks — they often omit
// font-display entirely; add `font-display: swap;` there too.
// ============================================================================
add_filter('elementor_pro_custom_fonts_font_display', fn() => 'swap'); // No-op on EP 4.x — kept for older versions.

add_action('wp_head', function() { ?>
<style>
	/* @font-face { font-family: 'FamilyName'; font-style: normal; font-weight: 700; font-display: swap; src: url('https://SITE/wp-content/uploads/...') format('woff'); } */
</style>
<?php }, 30);

// ============================================================================
// 3. EAGER LCP IMAGE (safe)
// If the report shows the LCP element with loading="lazy" and a large
// "Load Delay" share: first set the hero Image widget to Lazy Load: No in
// Elementor. This filter additionally stops WordPress core lazy-loading the
// first content image.
// ============================================================================
add_filter('wp_omit_loading_attr_threshold', fn() => 1);

// ============================================================================
// 4. DEQUEUE FONT AWESOME v4 SHIMS (test on staging)
// Enable only if no legacy "fa fa-*" icon classes are used and the report
// shows v4-shims.min.css/js loading on every page.
// ============================================================================
// add_action('wp_enqueue_scripts', function() {
// 	wp_dequeue_style('font-awesome-4-shim-css');
// 	wp_dequeue_script('font-awesome-4-shim-js');
// }, 999);

// ============================================================================
// 5. LAZY-LOAD RECAPTCHA V3 ON INTERACTION (EXPERIMENTAL — test on staging!)
// For sites where a Form widget with reCAPTCHA lives in a site-wide popup and
// recaptcha__en.js (~348KB) loads on every page.
// PREFERRED ALTERNATIVE (no code): switch the field to Cloudflare Turnstile
// or hCaptcha in the Form widget's reCAPTCHA section.
// ============================================================================
// add_action('wp_enqueue_scripts', function() {
// 	if ( ! wp_script_is('elementor-recaptcha_v3-api', 'enqueued') ) {
// 		return;
// 	}
// 	wp_dequeue_script('elementor-recaptcha_v3-api');
//
// 	wp_add_inline_script('jquery-core', "
// 		(function() {
// 			function mbLoadRecaptcha() {
// 				if (window.grecaptcha || document.querySelector('script[src*=\"recaptcha/api.js\"]')) return;
// 				var s = document.createElement('script');
// 				s.src = 'https://www.google.com/recaptcha/api.js?render=explicit';
// 				s.async = true;
// 				document.body.appendChild(s);
// 			}
// 			['mouseover', 'touchstart', 'keydown', 'scroll'].forEach(function(ev) {
// 				window.addEventListener(ev, mbLoadRecaptcha, { once: true, passive: true });
// 			});
// 			if (window.jQuery) jQuery(document).on('elementor/popup/show', mbLoadRecaptcha);
// 		})();
// 	");
// }, 9999);

// ============================================================================
// 6. CONDITIONALLY REMOVE PLUGIN ASSETS (test on staging)
// For plugin CSS/JS that loads on pages that don't use it (check the
// waterfall + @import audit). URL-based matching works without knowing the
// enqueue handles — but verify the URLs in view-source first.
// ============================================================================
// add_action('wp_enqueue_scripts', function() {
// 	// Keep the assets on pages that use this plugin's UI:
// 	if ( is_page( array( 'page-that-needs-it' ) ) ) {
// 		return;
// 	}
// 	add_filter('style_loader_src', 'mb_skip_plugin_assets', 10, 2);
// 	add_filter('script_loader_src', 'mb_skip_plugin_assets', 10, 2);
// }, 1);
//
// function mb_skip_plugin_assets($src, $handle) {
// 	if ( ! $src ) {
// 		return $src;
// 	}
// 	$skip = array(
// 		'plugins/plugin-slug/assets/css/file-to-skip',
// 		'plugins/plugin-slug/assets/js/file-to-skip',
// 	);
// 	foreach ( $skip as $needle ) {
// 		if ( false !== strpos( $src, $needle ) ) {
// 			return false;
// 		}
// 	}
// 	return $src;
// }

// ============================================================================
// 7. CLS HELPER CSS (safe)
// For images/SVGs the report flags as missing explicit dimensions. Prefer
// setting a fixed size in the Elementor Image widget; this is the fallback.
// Tune the px values to the rendered size.
// ============================================================================
add_action('wp_head', function() { ?>
<style>
	/* .custom-top-box-img { width: 30px; height: 30px; } */
	/* #omri-front img     { width: 120px; height: auto; } */
</style>
<?php }, 20);

// ============================================================================
// 8. CACHE-BUSTING FIX — goes in the child theme functions.php, NOT here.
// Replace rand()/time() enqueue versions with filemtime():
//
//   $ver = (string) ( filemtime( get_stylesheet_directory() . '/style.css' ) ?: 1 );
//
// so asset URLs stay stable (cacheable) and only change when files change.
// ============================================================================
