# Changelog — Astro Bridge (nvoos-astro-bridge)

## 1.1.0 — 2026-11-03

### Fixed

- **Astro CMS admin page rendered a broken products table in wp-admin** (`admin.php?page=astro-cms`): body rows were text-only (`<tr>1846</tr>`, zero `<td>` elements) while the identical SPA on the storefront `/manage` surface rendered correctly. Root cause: the wp-admin build target rendered through an externals + JSX-shim pipeline (`jsx`/`jsxs` → `window.React.createElement` captured from the wp-admin script chain), which commits an inconsistent tree under WP 7.1.4 (the same surface that broke before — React error #31, 2026-10-29). The admin page now enqueues the **standalone bundle** (`build/standalone/astro-admin.{js,css}`), which bundles React and the `@wordpress/*` packages and is the same artifact `/manage` ships — verified live against the production backend.
- **wp-admin stylesheet was never enqueued**: `AdminPage` referenced a `build/index.css` that does not exist, so the SPA rendered unstyled inside wp-admin. The standalone enqueue now also ships the real stylesheet.

### Changed

- **Full-bleed admin shell**: the Astro CMS page overrides WP's `#wpcontent { padding-left: 20px }` (scoped to `body.toplevel_page_astro-cms`) so the app shell sits edge-to-edge, matching the `/manage` surface.

## 1.0.1 — 2026-10-30

- Rename funiq-bridge → nvoos-astro-bridge (Astro CMS rebrand; data-layer identifiers deliberately unchanged).
- Restore REST cookie auth on single-site HTTPS (AuthSupport session re-resolution) and mint `/auth/nonce` for the restored session.
