# GTmetrix Audit → WordPress Fix Mapping

Reference for `design-performance-audit`. Each entry: the GTmetrix audit, what
it usually means on a WordPress site, the concrete fixes, and risk level.

## Page weight / payloads

### Avoid enormous network payloads (total size too big)

Biggest contributors on typical WP/Elementor/WooCommerce sites:

- **PNGs served where WebP/AVIF should be** (product shots, illustrations).
  Fix: Breeze WebP conversion (Cloudways) or ShortPixel/Imagify. Expected
  360KB PNG → 60–90KB WebP. Re-encode flag icons/badges too.
- **Large JPEGs** — recompress (quality ~82) and rely on srcset.
- **Third-party scripts** — see individual sections below (reCAPTCHA, GTM).

### Properly size images

- Elementor Image widgets set to "Full" size serve the original; on a 1366px
  viewport that's often 1.5–2× what's displayed. Fix: pick a registered size,
  or `add_image_size( 'mb-hero', 1400, ... )` + regenerate thumbnails to fill
  srcset gaps between 1024w and 1536w.
- Background images (`background-image` in inline CSS) get no srcset at all —
  upload a right-sized variant per breakpoint or accept the cost.

### Efficiently encode images

- Re-encode JPEGs with lower quality / progressive; PNG→WebP. Breeze's WebP
  conversion covers `<img>` and CSS background images on Cloudways hosts.

## LCP

### Don't lazy load the Largest Contentful Paint image

The report's LCP phase breakdown tells the story: high **Load Delay** +
`loading="lazy"` on the LCP element = lazy-loading the hero.

Fixes, in order of preference:

1. Elementor Image widget → Content → **Lazy Load: No** (no code).
2. `add_filter( 'wp_omit_loading_attr_threshold', fn() => 1 );` — stops WP
   core adding `loading="lazy"` to the first content image (only affects
   native WP image output, not Elementor's own attribute).
3. If Breeze lazy-load is on, exclude the hero via its exclusion options.

### Reduce initial server response time

TTFB/backend < 150ms is fine; don't chase it. If slow: check Breeze cache
warmth, PHP-FPM health, and object cache.

## CLS

### Avoid large layout shifts / explicit width & height

Common WordPress culprits:

- **SVGs without dimensions** (icon strips, badges): set fixed size in the
  Image widget, or CSS `width`/`height`/`aspect-ratio`.
- **Entrance animations above the fold** (`_animation: fadeInUp` in Elementor
  widget settings, `elementor-invisible` class in the capture): remove or
  move them below the fold.
- **Font swap** on large display headings: see "webfont load" below.

### Ensure text remains visible during webfont load (font-display)

- Elementor Pro Custom Fonts: `add_filter( 'elementor_pro_custom_fonts_font_display', fn() => 'swap' );`
  (if the filter doesn't take effect on the installed version, redeclare the
  `@font-face` rules in the child theme CSS with `font-display: swap`).
- Theme fonts: edit the child theme's `@font-face` blocks.
- Preload the 2–3 critical font files: `<link rel="preload" as="font" type="font/woff2" crossorigin href="...">`.

## Render-blocking / script bloat

### Avoid chaining critical requests / render-blocking resources

- **jQuery** (`jquery.min.js` in head): required by most WooCommerce/Elementor
  stacks — deferring it breaks things. Leave it; instead kill the
  *duplicates* around it.
- **jQuery UI from a CDN** (`code.jquery.com/ui/1.12.1/jquery-ui.min.js`):
  usually a hardcoded `<script>` tag right after `<body>` (header template or
  a WPCode HTML snippet). WP core already ships `jquery-ui-core` 1.13.3.
  Delete the CDN tag; test menus/popups/sliders on staging. (High risk if a
  plugin relies on the CDN copy's widgets.)
- **Font Awesome v4 shims**: Elementor 4.x loads FA5 `all.min.css` AND
  `v4-shims` CSS+JS. If no legacy `fa fa-*` icons are used:
  `wp_dequeue_style( 'font-awesome-4-shim-css' ); wp_dequeue_script( 'font-awesome-4-shim-js' );`
  on `wp_enqueue_scripts` priority 999. Test staging.
- **CSS @import in plugin stylesheets** (e.g. Indeed Affiliate Pro's
  `main_public.min.css` importing Google Fonts): dequeue the stylesheet on
  pages that don't use that plugin's UI (URL-based `style_loader_src` filter
  is safest when handles are unknown), plus preconnect hints.

### Avoid unused JavaScript / CSS

- **Unused JS** is usually third-party (reCAPTCHA ~247KB unused, gtag, Yotpo).
  Fix the source of the duplication rather than the report line.
- **Unused CSS** on Elementor sites is mostly the page's own inline post CSS
  + plugin CSS loaded site-wide. Breeze "Minify CSS" + "Group" helps bytes,
  not unused-ness. Conditional dequeue is the real fix.

### Minify JavaScript / CSS

- Breeze → Basic Options → Minify HTML/CSS/JS (test checkout and popups after
  enabling; WooCommerce JS is sensitive to defer/group).

### Avoid serving legacy JavaScript to modern browsers

- Babel-transpiled plugin bundles (Funnel Builder, fkcart, Yotpo) — cannot fix
  from the site; ignore (N/A audits).

## Third-party consolidation (biggest real-world wins)

### reCAPTCHA loaded on every page

Symptom: `recaptcha/api.js?render=explicit` + `recaptcha__en.js` (348KB,
~247KB unused) site-wide. Cause: a Form widget with a reCAPTCHA field inside
a site-wide Elementor **popup** — popups render their HTML on every page, so
the scripts enqueue everywhere.

Fixes:

1. **Switch to Cloudflare Turnstile or hCaptcha** — native options in the
   Elementor Pro Form widget's reCAPTCHA section (Turnstile since 3.18,
   hCaptcha since 3.12). ~30KB instead of 348KB, no Google dependency, no
   code. **Preferred.**
2. Keep reCAPTCHA and lazy-load on interaction: dequeue
   `elementor-recaptcha_v3-api` and inject `api.js?render=explicit` on first
   `mouseover/touchstart/keydown/scroll` or on `jQuery(document).on('elementor/popup/show', ...)`.
   **Experimental — test form submits on staging.**

### Duplicate Google analytics

Symptom: the same GA4 ID appears multiple times in the third-party table
(direct enqueue, `cx=c&gtm` GTM-fired copy, local-GA plugin copy) plus GTM
`gtm.js` plus an Ads `AW-…` tag. Common sources:

- GTM container firing GA4 + Ads tags (`&cx=c&gtm=…` URLs)
- A direct enqueue by a plugin/snippet (`gtag/js?id=G-XXXX` with no cx param —
  check Site Kit, WooCommerce GA integrations, WPCode snippets)
- A local-GA plugin (CAOS / "Local GA JS" — serves `?local_ga_js=<md5>`)

Fix: keep ONE path. Usually GTM alone (fires GA4 + Ads). Remove the local-GA
plugin and find the direct enqueue (grep plugins/snippets for the measurement
ID; ask the user for wp-admin access if needed). Don't guess the source —
give diagnostic steps.

### Other marketing scripts

Brevo (`sibautomation.com/sa.js` + `cdn.brevo.com/js/sdk-loader.js`), Noptin,
Funnel Builder `tracks.min.js`, fkcart, Yotpo — keep only what the business
actively uses; deactivate unused plugins rather than hacking around them.

### Serve static assets with an efficient cache policy

Mostly third-party (Yotpo widget.js has no TTL; Brevo 60s–8h) — **cannot fix
from the site**. Skip unless the report flags first-party URLs (those should
have long TTLs via Breeze/CDN headers).

## Caching / plugin config

- **Breeze**: cache active + prefetch-links with a proper ignore list is the
  right baseline. Lazy-load images via Breeze only after the hero/LCP image
  is excluded or set eager.
- **Cache-busting anti-pattern**: `rand()`/`time()` as enqueue versions force
  a re-download every page view and defeat CDN caching. Replace with
  `(string) ( filemtime( $file ) ?: 1 )`.
- **Preconnect hints**: add for `fonts.googleapis.com`, `fonts.gstatic.com`,
  `www.gstatic.com` (reCAPTCHA), Yotpo static hosts, GTM — first-party head
  output, safe.

## Structure audits that are usually acceptable

- **Excessive DOM size** (2–3k elements): normal for Elementor + WooCommerce
  + Yotpo; only act if > ~3k or depth > 40 causes TBT problems.
- **Use a CDN**: the host's CDN (Cloudways/CDN or Cloudflare) covers
  first-party; third-party hosts are out of scope.
- **User Timing marks**: informational.
- **Long main-thread tasks / JS execution**: dominated by jQuery + GTM +
  third-party; fixing the payload list above usually fixes these too.

## Verification checklist before signing off

- [ ] Snippet has `defined( 'ABSPATH' ) || exit;` guard
- [ ] Risky blocks commented with staging warnings
- [ ] Only blocks matching this site's findings are included
- [ ] HTML report validates (no mismatched/unclosed tags)
- [ ] New GTmetrix PDF archived for the next before/after diff
- [ ] Anything unverifiable is stated with next steps (not guessed)
