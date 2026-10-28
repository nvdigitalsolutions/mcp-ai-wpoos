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

1. `add_filter( 'wp_omit_loading_attr_threshold', fn() => 1 );` — stops WP
   core adding `loading="lazy"` to the first CONTENT image. NOTE: only
   affects images in post content, NOT widget-rendered images.
2. **For Elementor/widget images** (VERIFIED 2026-10-13 on Elementor 4.3.x
   + WP 7.x): the `loading="lazy"` may be added by WordPress core's
   `wp_get_attachment_image()` — but on Elementor 4.x the image may render
   WITHOUT going through that function, in which case neither
   `wp_omit_loading_attr_threshold` nor `wp_get_loading_optimization_attributes`
   fires for it. Try the WP filter first:
   ```php
   add_filter( 'wp_get_loading_optimization_attributes', function( $loading_attrs, $tag_name, $attr, $context ) {
       if ( 'img' === $tag_name && ! empty( $attr['src'] ) && false !== strpos( $attr['src'], 'hero-image-fragment' ) ) {
           return array(); // Drop the loading attribute → eager.
       }
       return $loading_attrs;
   }, 10, 4 );
   ```
   **Guaranteed fallback** if the attribute still shows after deploy: rewrite
   the finished HTML with an output buffer on `template_redirect` (priority
   9999 so it sits inside any cache plugin's buffer — the transformed HTML is
   what gets cached):
   ```php
   add_action( 'template_redirect', function() {
       if ( is_admin() || wp_doing_ajax() || is_feed() ) return;
       ob_start( function( $html ) {
           return preg_replace_callback(
               '#<img\b[^>]*hero-image-fragment[^>]*>#i',
               function( $m ) { return preg_replace( '#\s+loading=["\']lazy["\']#i', '', $m[0] ); },
               $html
           );
       });
   }, 9999 );
   ```
   Match on a distinctive src fragment (e.g. the hero file name). Verify in
   view-source after deploy.
3. If Breeze lazy-load is on, exclude the hero via its exclusion options.
4. **Flatsome (non-Elementor)**: the LCP hero is lazy-loaded by Flatsome's
   OWN `flatsome-lazy-load` extension, NOT by WP core `loading="lazy"`. The
   `wp_omit_loading_attr_threshold` filter does NOT touch it. The hero renders
   with class `lazy-load-active` and a `data-src` that Flatsome's JS swaps in
   on scroll — so the fix is to opt the hero OUT of Flatsome lazy-load, not to
   change the WP filter. See "Flatsome (non-Elementor) notes" below.

### Reduce initial server response time

TTFB/backend < 150ms is fine; don't chase it. If slow: check Breeze cache
warmth, PHP-FPM health, and object cache.

## Flatsome (non-Elementor) notes

The fix mapping above assumes Elementor, but many WooCommerce stores (e.g.
The Parfumerie) run the Flatsome theme. Flatsome has DIFFERENT mechanics for
the same audits — apply these instead:

- **Lazy-load**: Flatsome ships its own `flatsome-lazy-load` extension
  (`flatsome/inc/extensions/flatsome-lazy-load/flatsome-lazy-load.js`). It
  swaps in `data-src` on scroll and adds class `lazy-load-active` to already-
  loaded images. WP core's `loading="lazy"` / `wp_omit_loading_attr_threshold`
  do NOT control it. To make the LCP hero eager:
  1. Add class `skip-lazy` to the hero image, OR disable lazy-load on that
     banner/slider block in the UX Builder (Flatsome respects `skip-lazy` /
     `ux-lazyload` opt-outs), OR
  2. Turn lazy-load off site-wide via **Theme Options → Performance → Lazy
     Load Images** (then rely on WP core lazy-load for below-fold images).
  3. The output-buffer fallback (strip `loading="lazy"` from the hero `<img>`)
     still works as a belt-and-braces guarantee regardless of what adds it.
- **Render-blocking JS**: Flatsome loads a monolithic `flatsome.js` plus
  `chunk.slider.js`, `chunk.tooltips.js`, `chunk.popups.js`, `woocommerce.js`
  up front. These show up in the render-blocking/chaining audits and in
  unused-JS. They are largely required by the theme — focus instead on the
  third-party/analytics/pixel scripts and non-commerce plugin CSS that the
  theme is NOT needed for. Deferring Flatsome's own chunks risks breaking the
  header/nav/slider — do not blanket-defer them.

## CLS

### Elementor/JupiterX header nav-menu first-paint "flash" (VERIFIED 2026-10-29)

Symptom: PSI reports a big shift (0.35) on the header nav row
(`elementor-widget-wrap elementor-element-populated` containing the menu
items), persisting AFTER font-display and entrance-animation fixes. On mobile,
the user reports "the menu flashes on the page".

Root cause: on the very first layout the linked Elementor CSS has not been
applied yet, so the nav `<ul>` renders as an unstyled block with ALL
`.elementor-nav-menu--dropdown` sub-menus VISIBLE — the header renders at its
expanded height (measured 1202px on kayaherbhouse.com) and then collapses to
its final height (~170px) when the CSS lands. On PSI's throttled cold run the
intermediate state persists for seconds and gets painted = the CLS. JupiterX
sticky headers render a second header template (same menu pattern) inside the
same wrapper — that is the "mobile menu flash" (hidden-mobile also comes from
the late CSS).

Diagnosis (this is how it was found): instrument the first frame —
`addInitScript` + rAF loop recording `getBoundingClientRect()` of the header/
nav/dropdowns from the very first frame; the flash shows as a huge height
change (1202px → 170px) plus `display:block` on dropdowns at frame 1. Do NOT
rely on warm-cache runs alone — the window is only ~130ms warm but seconds
cold. Also verify with the HTML: two `<header data-elementor-id>` templates
inside one `.jupiterx-header` wrapper + a `wp-custom-css` rule hiding one of
them.

Fix: inline critical CSS in `wp_head` that replicates Elementor's FINAL state
from the first layout (inline styles need no fetch, so they apply before the
linked CSS):

```css
.jupiterx-header .elementor-nav-menu--dropdown,
.jupiterx-header .sub-menu { display: none; }
.jupiterx-header .elementor-nav-menu--layout-horizontal .elementor-nav-menu { display: flex; flex-wrap: wrap; }
.elementor-invisible { visibility: hidden; }
@media (max-width: 767px) { .elementor-hidden-mobile { display: none; } }
@media (min-width: 768px) and (max-width: 1024px) { .elementor-hidden-tablet { display: none; } }
```

**NO `!important` on the dropdown hide** — SmartMenus opens sub-menus by
setting inline `display:block`, which must keep winning (verified: hover-open
still works with the plain rule). Verify by re-running the first-frame probe:
first-frame height must equal final height, and manually setting
`dd.style.display='block'` must still show `block`.

### Avoid large layout shifts / explicit width & height

Common WordPress culprits:

- **SVGs without dimensions** (icon strips, badges): set fixed size in the
  Image widget, or CSS `width`/`height`/`aspect-ratio`.
- **Entrance animations above the fold** (`_animation: fadeInUp` in Elementor
  widget settings, `elementor-invisible` class in the capture): remove or
  move them below the fold.
- **Font swap** on large display headings: see "webfont load" below.

### "Media element lacking an explicit size" — verify before trusting the report

Check the CURRENT served HTML first: if the `<img>` already has `width`/`height`
attributes (computed `aspect-ratio: auto W / H`), the audit cannot fire — the
PSI run is stale or hit an edge-cached copy. Prove space is reserved:
`img.removeAttribute('src')` and confirm the wrapper's `getBoundingClientRect()`
is unchanged. Cloudflare APO serves stale HTML (`Age: N`, `cf-cache-status:
HIT`) — purge and re-run PSI before believing a report. Note: `fetchpriority="high"`
on a below-fold image steals LCP bandwidth — if the attribute exists but the
widget has no saved settings (no `data-settings` on the widget div), it is
injected by a filter; strip it with
`wp_get_attachment_image_attributes` at priority 999.

### Ensure text remains visible during webfont load (font-display)

- Elementor Pro Custom Fonts: `add_filter( 'elementor_pro_custom_fonts_font_display', fn() => 'swap' );`
  — **VERIFIED 2026-10-13: this filter is IGNORED by Elementor Pro 4.3.x**
  (all @font-face rules still print `font-display:auto`). The reliable,
  version-proof fix is to redeclare the same @font-face rules (same
  family/weight/style/src) with `font-display: swap` in a `wp_head` style
  block or the child theme CSS — the browser uses the last matching
  declaration. See `templates/optimization-snippet.php` block 2.
- **`swap` alone does NOT fix font CLS** — the shift is the fallback text
  occupying a different width than the webfont. Fix with metric-matched
  fallback faces (`size-adjust`), VERIFIED 2026-10-29 on kayaherbhouse.com:
  1. Measure the webfont vs the system fallback with fontTools (avg advance
     over the ACTUAL strings rendered — all-caps menu text, accented chars).
  2. Declare fallback faces: one with
     `src: local('Arial'), local('Helvetica Neue'), local('Helvetica')`
     (metric-identical family) and one with `src: local('Roboto')` for
     Android — each with its OWN `size-adjust` (the browser uses the first
     face whose local() font exists).
  3. Push the fallback into the stacks:
     `font-family: "chartrand", "chartrand-fallback", sans-serif` — the old
     stacks end at the custom family with NO generic, so the pre-swap text
     renders in the default serif (far wider) → menu wraps → the shift.
  4. **Tune the numbers LIVE in the browser, not just fontTools** — kerning
     and per-string glyph mixes make measured ratios authoritative. Render
     the same string in webfont vs fallback face, and correct size-adjust by
     the width ratio. Sub-1% deltas are the goal (achieved: nav -0.46%,
     headings 0%).
  5. Vertical overrides (`ascent-override` etc.) are often UNNECESSARY: when
     the theme sets a global unitless line-height (JupiterX does, ~1.15),
     box heights are CSS-driven, not font-metric-driven — check the site
     before adding them.
- Theme fonts: check the child theme's `style.css` @font-face blocks — they
  often omit `font-display` entirely (defaults to `auto`). Add `font-display:
  swap;` to each rule (curl the live stylesheet to verify; you can't see it
  from the page HTML).
- Preload only the 2–3 critical (above-the-fold) font files with
  `<link rel="preload" as="font" type="font/woff2" crossorigin href="...">`.
  Preloading EVERY font wastes priority bandwidth — if the site already
  preloads 7+ fonts, trim the list to the hero/body fonts actually used
  above the fold.
- **VERIFIED 2026-10-13 — two preload gotchas:**
  1. `type` must match the file format — `type="font/woff2"` on a `.woff`
     file makes the browser ignore the preload (wasted bytes).
  2. Elementor's inline "Custom Fonts CSS" prints AFTER the enqueued
     stylesheet, so for the same family/weight it WINS over the theme's
     @font-face. If both the theme and Elementor declare the same family,
     the theme's font files are shadowed — preload (and fix) the files that
     actually render. Check document order with `grep -n` on the HTML
     capture, or `document.fonts.check()` in the browser.

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

1. **VERIFIED 2026-10-13: Elementor Pro natively supports ONLY reCAPTCHA
   v2/v3.** hCaptcha and Cloudflare Turnstile are still feature requests
   (GitHub discussions #31259 / #25671) — do NOT recommend them as native
   options. To use Turnstile/hCaptcha with Elementor Pro forms you need a
   free plugin: "Simple Cloudflare Turnstile" (supports Elementor Pro forms
   incl. template/popup forms), "CWeb Form Protection with Turnstile for
   Elementor Forms", or Cool FormKit. ~30KB instead of 348KB, no Google
   dependency.
2. **No-plugin fixes that keep Google reCAPTCHA:**
   - Restrict the popup's display conditions (popup → Advanced →
     Conditions) so the form only renders on pages that need it — on other
     pages the scripts never enqueue.
   - Lazy-load on interaction: dequeue `elementor-recaptcha_v3-api` and
     inject `api.js?render=explicit` on first
     `mouseover/touchstart/keydown/scroll` or on
     `jQuery(document).on('elementor/popup/show', ...)`.
     **Experimental — test form submits on staging.**
   - Move the form off the site-wide popup onto a dedicated contact page.
3. Last resort: drop captcha entirely and use Elementor's honeypot field +
   Cloudflare bot protection (lightest, weakest).

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

**Concrete likely sources for a DIRECT GA4 enqueue** (`js?id=G-XXXX&l=dataLayer`
with NO `cx=` param, alongside a GTM `gtm.js` that ALREADY fires the same ID):

- **Site Kit by Google** — Site Kit → Settings → Analytics (toggle the
  Analytics module off; keep GTM if that's where the tag already lives).
- **WooCommerce → Settings → Integrations → Google Analytics** (the legacy
  built-in integration prints `gtag/js?id=G-XXXX`).
- **A WPCode / header snippet** (a manually added `<script>` in header.php or
  a WPCode "HTML/Script" snippet) — grep snippets for the measurement ID.
- **Flatsome theme header options** (Theme Options → Advanced → custom
  header/body scripts).

**Verify before removing**: confirm GTM is actually firing the same `G-XXXX`
ID first (look for `collect?v=2&tid=G-XXXX&gtm=...` in the page's network
activity). If it is, the direct enqueue is a pure duplicate and can be removed
with no data loss. If GTM is NOT firing it, keep the direct enqueue instead.
Flipping the wrong one silently kills analytics — check the network trace.

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
- **Cloudflare APO stale-HTML trap (VERIFIED 2026-10-29)**: after deploying
  a snippet, the canonical URL can keep serving the OLD HTML from the edge
  cache (`Age: N` seconds, `cf-cache-status: HIT`, `Cf-Apo-Via: tcache`).
  Verify with a cache-buster query string (`?v=<n>`) and `grep` the raw HTML
  for the new markers; a browser check alone can silently show the stale
  copy. Purge via the Cloudflare API (`POST /zones/:zone_id/purge_cache`
  with `purge_everything: true`) or dashboard, then confirm
  `cf-cache-status: MISS` on the canonical URL.
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
