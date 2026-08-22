# WordPress.org Review — Detailed Resolution Report

**Plugin:** NV oOS Content Graph (formerly "NV oOS Graphify", slug `nvoos-graphify`)
**Review ID:** AUTOPREREVIEW ❗TRM nvoos-graphify/vsamtani/17Aug26/T1 17Aug26/4.2RC1 (P0TDX353496HGN)
**Report prepared:** 2026-08-21
**Resolved in:** v1.0.2 (2026-08-18) and v1.0.3 (2026-08-20)

**Purpose:** Internal record mapping every finding from the WordPress.org
auto-review (and the earlier 18-point agent review) to its resolution. The
short reply actually sent to the reviewer lives in `WPORG-REVIEW-REPLY.md`;
this file is the detailed working record and is **excluded from the
distribution ZIP** via `.distignore` (`WPORG-REVIEW-*.md`).

---

## Status Summary

| # | Review Finding | Status | Fixed In |
|---|---|---|---|
| 1 | Name/slug trademark: "Graphify" | ✅ Renamed to "NV oOS Content Graph", slug `nvoos-content-graph` | v1.0.2 |
| 2 | Use `wp_enqueue_*` — inline `<script>`/`<style>` | ✅ Live output enqueued; export-file exception documented & hardened | v1.0.2 / v1.0.3 |
| 3 | Output escaping — JSON-LD `</script>` breakout | ✅ JSON_HEX flags + `wp_strip_all_tags()` | v1.0.2 |
| 4 | `Tested up to` stale (6.7) | ✅ `Tested up to: 7.1` (current stable, released 2026-08-19) | v1.0.2 / v1.0.3 |
| 5 | Contributors list missing `vsamtani` | ✅ Added | v1.0.2 |
| 6 | Short description > 150 chars (163) | ✅ Shortened to 140 chars | v1.0.2 |
| 7 | `composer.json` missing from ZIP | ✅ Ships; autoloader preserved | v1.0.3 |
| 8 | Undocumented external service (Wikidata) | ✅ `== External services ==` + `== Privacy Notice ==` sections | v1.0.2 |
| 9 | `/resolve` permission_callback (auto-ingest) | ✅ `manage_options` required; default `auto_ingest=false` | v1.0.2 / v1.0.3 |
| 10 | Admin notices not dismissible (Guideline 11) | ✅ `is-dismissible` + scoped to plugin page | v1.0.2 |
| 11 | `esc_html()` in JSON-LD (agent review) | ✅ `wp_strip_all_tags()` | v1.0.2 |
| 12 | Untranslatable legend labels (agent review) | ✅ Page removed; strings translated in Settings page | v1.0.3 |
| 13 | Missing privacy notice (Guideline 7) | ✅ Added | v1.0.2 |
| 14 | Missing third-party license attribution (Guideline 2) | ✅ `== Third-Party Libraries ==` section | v1.0.2 |
| 15 | Webhook permission callback returns `true` (INFO) | ℹ️ Intentional — HMAC-SHA256 verified per-request in `receiveWebhook()`; documented | — |

---

## 1. Name / Slug — Trademark ("Graphify")

**Finding:** ✨ "Graphify" flagged as a potential trademark of an existing
knowledge-graph project; name could confuse users.

**Resolution:** Renamed the plugin and its slug, then requested the permalink
change from the review team in the reply email:

- Display name: "NV oOS Graphify" → **"NV oOS Content Graph"**
- Slug: `nvoos-graphify` → **`nvoos-content-graph`**
- Updated everywhere: plugin header, text domain, hooks
  (`nvoos_content_graph_*`), option keys, custom table names, REST namespace
  (`/wp-json/nvoos-content-graph/v1/`), and asset handles.

## 2. Enqueue vs. Inline Scripts

**Finding:** Inline `<script>`/`<style>` tags reported in
`src/Graph/Exporter.php` and `src/Admin/RemoteAdmin.php`.

**Resolution:**

- `src/Admin/RemoteAdmin.php` — the inline `<script>` block in the Embeddings
  panel was removed; its handler moved to the enqueued `remote-admin` asset.
  A dead legacy commented-out JS block was removed at the same time.
- `src/Graph/Exporter.php` — intentional and retained. This file generates a
  **self-contained standalone HTML file for download** (one of the six export
  formats); it is not markup printed on a WordPress page, so
  `wp_enqueue_*()` does not apply. The inline graph payload was hardened with
  `JSON_HEX_*` flags and the enqueue exemption is documented in the code.
  This clarification was included in the reply email.

All live output (admin settings page, graph explorer, frontend shortcode,
Gutenberg block) is enqueued via `wp_enqueue_script()` /
`wp_enqueue_style()` / `wp_add_inline_script()`.

## 3. Output Escaping — JSON-LD

**Finding:** `src/Frontend/SchemaOrg.php` echoed JSON inside
`<script type="application/ld+json">` with `JSON_UNESCAPED_SLASHES`, allowing
a crafted `</script>` sequence to terminate the element.

**Resolution (`src/Frontend/SchemaOrg.php`):**

- Node labels are cleaned with `wp_strip_all_tags()` before encoding
  (replaces the earlier `esc_html()` use, which produced invalid HTML
  entities in JSON-LD).
- The payload is emitted via `wp_json_encode()` with
  `JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
  JSON_HEX_QUOT` — no raw `<`, `>`, or `&` can appear in the output.
- The `phpcs:ignore` for the echo is justified in a comment.

The same hardening was applied to the standalone HTML export payload.

## 4. "Tested up to" Value

**Finding:** `Tested up to: 6.7` was below the accepted version (≥ 6.9).

**Resolution:** Updated to `Tested up to: 7.1`, matching WordPress 7.1
(released 2026-08-19, current stable). Also `Requires at least: 6.5`,
`Requires PHP: 8.1`.

## 5. Contributors

**Finding:** `vsamtani` (the submitting account) was not in the readme
contributors list.

**Resolution:** `Contributors: nvdigitalsolutions, vsamtani`.

## 6. Short Description Length

**Finding:** 163 characters (limit 150; only first 150 used).

**Resolution:** Shortened to 140 characters:
"Map your WordPress content into an interactive knowledge graph. See
relationships between posts, terms, and authors. No API keys required."

## 7. composer.json Missing from ZIP

**Finding:** The distribution ZIP contained no `composer.json`.

**Resolution:** `.distignore` no longer strips the Composer autoloader or
`composer.json` from distribution builds; `vendor/autoload.php` ships intact
(required at runtime for the PSR-4 class mapping).

## 8. External Services Disclosure

**Finding:** The plugin queries Wikidata but the readme lacked terms of
service / privacy policy links.

**Resolution (readme.txt):**

- New `== External services ==` section: documents the Wikidata driver
  (`https://www.wikidata.org/w/api.php`), what data is sent (QID + standard
  HTTP metadata), when (explicit request only), and links to the Wikimedia
  Foundation Terms of Use and Privacy Policy. Also covers user-configured
  remote source drivers as opt-in only.
- New `== Privacy Notice ==` section (Guideline 7): core engine runs fully
  on-server; no personal data collected by default; remote-source
  credentials stored AES-256-GCM encrypted with documented OpenSSL fallback;
  AI addons are separate plugins with their own policies.

## 9. REST `/resolve` permission_callback

**Finding:** ✨ Read-level users or guest-token holders could call
`auto_ingest=true` to create graph nodes via the `/resolve` endpoint.

**Resolution:**

- `auto_ingest` now defaults to `false`.
- Auto-ingest requires `manage_options`; read-level users get read-only
  resolution.
- The matching `ResolveExternal` tool was aligned: defaults to
  `auto_ingest=false` and requires `manage_options` for auto-ingest.

## 10. Admin Notices (Guideline 11)

**Finding (agent review):** Site-wide, non-dismissible notices.

**Resolution (`src/Plugin.php` `renderAdminNotices()`):**

- All notices now carry the `is-dismissible` class.
- The OpenSSL and "graph not enabled" warnings are additionally scoped to
  the plugin's own settings screen via `get_current_screen()`.
- The build-complete success notice is transient-driven and self-removes
  after a single view.

## 11–12. Agent Review: JSON-LD `esc_html()` + Legend i18n

- Item 11 is superseded by the hardening in section 3 above.
- The standalone Graph Explorer admin page containing the hardcoded legend
  labels ("Post", "Page", "Term", "User") was **removed** in v1.0.3; the
  explorer is embedded in the Settings page where every string is wrapped
  with `esc_html_e()` / `esc_attr_e()`.

## 13–14. Privacy Notice + Third-Party Licenses

- See section 8 for the privacy notice.
- New `== Third-Party Libraries ==` section credits Cytoscape.js v3.28.1
  (MIT) and cytoscape-fcose v2.2.0 (MIT), with repo links, and confirms both
  are served locally from `assets/vendor/` (never from CDNs).

## 15. Webhook Permission Callback (INFO)

`checkWebhookPermission()` returns `true` because the endpoint must accept
unauthenticated HTTP requests; each request is individually verified with
HMAC-SHA256 inside `receiveWebhook()`. This is documented in the code. No
action taken, per the review's own note.

---

## Additional Hardening Beyond the Review (v1.0.3)

From the project's own follow-up audits:

- Remote-source credentials (API tokens, passwords) are now encrypted with
  AES-256-GCM **before** storage (`Crypto::encrypt()` wired into
  `Db::saveRemoteSource()`); reads decrypt transparently. `Crypto::getKey()`
  falls back to a fixed salt when `AUTH_KEY`/`SECURE_AUTH_KEY` are empty.
- SSRF guard re-validates every redirect hop; transport-level redirect
  following is disabled in `HttpClient`.
- Config keys are sanitized and unknown drivers rejected in both the AJAX
  and REST source-creation paths.
- Fixed invalid inline JS from the `[nvoos_graph]` shortcode — frontend
  embed and Gutenberg block render correctly.
- "Scheduled Rebuild" setting wired to WP-Cron with a new "Never" option;
  cron kept in sync on activation, boot, and settings save.
- Fixed the admin "Test" button and REST `/test` endpoint to use the
  selected source's decrypted credentials.
- Fixed `Db::upsertNode()` dropping `external_id`, `source_slug`,
  `confidence`, and `expires_at` on updates.
- Remote Sources modal now honors schema field types (password, textarea,
  checkbox, number, url).
- Field-map validator ported into the plugin — no legacy addon dependency.
- Embeddings reindex bridges to the AI addon's `EmbeddingService` via
  `CoreBridge`.

---

## Correspondence Checklist

- [x] Short reply prepared: `WPORG-REVIEW-REPLY.md` (send as a reply to the
      original review email thread — not a new email).
- [x] New slug `nvoos-content-graph` explicitly requested in the reply.
- [ ] v1.0.3 uploaded via the "Add your plugin" page, logged in as
      `vsamtani`.
- [x] No claim of trademark ownership made — renaming resolves the finding.
- [x] Both files excluded from the distribution ZIP via
      `.distignore` (`WPORG-REVIEW-*.md`), mirrored in
      `.github/workflows/build-nvoos-content-graph.yml` and
      `bin/build-nvoos-content-graph.sh`.
