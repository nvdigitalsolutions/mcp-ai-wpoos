<!-- Split out of ../SKILL.md on 2026-11-17. Content unchanged; moved here
     so the main skill stays under Zed's ~100 KB load limit. -->

## Proposal 114 §3.9 — form bridge P7 (shipped 2026-11-05)

Forms integrate like WooCommerce: **content at build time, behavior proxied
through WordPress.**

- **Render half (the forge):** `sanitize.ts` gains the form allowlist —
  `form`/`label`/`input`/`textarea`/`select`/`option`/`optgroup`/`button`/
  `fieldset`/`legend` + functional attributes only (`action`/`method`/
  `on*`/`formaction`/`style` are never allowed; `javascript:` `value`s die in
  transformTags) — `SANITIZE_VERSION = 2`. `semantic.ts` gains the `form`
  section kind + `FormInfo` (kind search|submit|cta, handler, formId,
  formName, postId, fields, submitLabel): forms are extracted FIRST, before
  the builder-section pipeline, from the top-level nodes; search forms = one
  search-ish input (`type=search` / name s|search|query|q / `role=search` /
  the profile's `forms.searchForms` names) or a hidden-ID carrier
  (`form_id` → elementor, `_jet_engine_booking_form_id` → jetformbuilder,
  `_wpcf7` → wpcf7; `post_id`/`__queried_post_id` → postId); no resolvable ID
  → `cta` (title/links band, dropped when empty — never a dead endpoint).
  `classifyHtml(html, options)` — the no-forms path is byte-identical to
  pre-P7 (all 297 unit tests pin it). `Form.astro` (pure Astro, NO islands):
  search → GET `/search/?q=`; submit → POST to `{NVOOS_FORGE_FORMS_ENDPOINT ||
  manifest.forms.endpoint}/forms/submit` with hidden `form`/`post_id?`/
  `honeypot`/`timestamp`/`_redirect` + delegated fetch enhancement (Astro
  hoists + dedupes identical bundled scripts); the no-JS floor is the
  ordinary form POST + `?form=ok|error` status script. `search.astro`
  prefills + auto-runs from `?q=`.
- **Config:** `types.ts` `ForgeForms` (endpoint/origin/searchForms) on
  `SiteProfile` + `ForgeManifest`; scaffold emits `manifest.forms` (endpoint
  defaults to `<profile.url>/wp-json/nvoos-forge/v1`) and `.env.example`
  (`NVOOS_FORGE_FORMS_ENDPOINT`, `NVOOS_FORGE_FORMS_ORIGIN`).
- **Behavior half (`plugins/nvoos-forge-bridge` 0.2.0):**
  `includes/rest/class-nvoos-forge-bridge-forms.php` — one public route
  `POST /nvoos-forge/v1/forms/submit` (permission `__return_true` — public
  by design; the gates are CSRF/abuse controls, NOT authorization — see the
  invariants below): disabled→404; origin allowlist (Origin header,
  Referer fallback, normalized via `Settings::normalize_origin`)→403;
  per-IP rate limit (transient, 5/10min)→429; honeypot non-empty or
  timestamp outside ±2h (JSON path; optional on the no-JS form-encoded
  floor)→silent 200 discard; optional Turnstile with `action`=
  `nvoos_forge_form` + hostname pinned to the allowlisted origin (fails
  closed on network errors); server-side `sanitize_textarea_field` on every
  field (count/length caps). Dispatch ladder: JFB (`jet_fb_handler()->
  process_form()` driven through the `_jet_engine_booking_form_id` +
  `_jet_engine_refer` `$_POST` carriers, `is_ajax = true` for the JSON
  response; form-id resolution via the `jet-form-builder` CPT) → Elementor
  Pro (`ElementorPro\Modules\Forms\Classes\Ajax_Handler::ajax_send_form()`
  with the admin-ajax contract emulated in-process: `action`/`form_id`/
  `post_id`/`queried_id`/`form_fields` + a fresh `wp_create_nonce(
  'elementor_pro_forms')`; form existence via a bounded `_elementor_data`
  LIKE) → typed 404 `nvoos_forge_form_not_found` / 501
  `nvoos_forge_no_form_handler`. Both native dispatches are wrapped in
  `capture_json()`: it filters `wp_doing_ajax` true (otherwise `wp_send_json`
  calls `die` directly and no die-handler filter runs), swaps
  `wp_die_ajax_handler`/`wp_die_json_handler` for a sentinel thrower, and
  buffers the echoed JSON (both `{status,…}` JFB and `{success,data}` shapes
  map into the envelope `{ok,message,errors?}`). Version-drift seams: `nvoos_forge_bridge_forms_
  jfb_available|elementor_available` (bool filters) + `…_jfb_dispatch|
  elementor_dispatch` (return an envelope array or WP_Error to pin a
  specific plugin release; null = native). Audit seam: the
  `nvoos_forge_bridge_form_submission` action (accepted|rejected). No-JS
  floor: form-encoded bodies redirect (302) to the allowlisted `_redirect`
  with `?form=ok|error` (open-redirect guarded). Settings: `OPTION_FORMS_*`
  (enabled, origins textarea, captcha provider/site key/secret) + UI
  section + `option_keys()` + redacted health fields.
- **Security invariants (0.3.1, do not regress):** only `post_status = publish`
  + empty `post_password` forms resolve (JFB by ID and title; Elementor
  `_elementor_data` lookup joins `wp_posts`); non-public forms return the same
  404 as missing ones (no draft enumeration). The rate-limit IP is
  `REMOTE_ADDR` unless the direct peer is in the
  `nvoos_forge_bridge_trusted_proxies` filter, then the rightmost untrusted
  hop. The route is public by design, so the origin allowlist is a CSRF gate,
  never authorization. A submitted field can never re-target the dispatch:
  JFB's routing carriers (`_jet_engine_booking_form_id` / `_jet_engine_refer`)
  are stripped from the fields and re-asserted last.
- **Tests:** forge — sanitizer form allowlist + XSS vectors, semantic form
  classification (search/submit/cta/JFB/CF7/postId/options), scaffold forms
  emission, e2e fixture forms (xss-trap search+Elementor form, contact JFB
  form) asserting the rebuilt `action`es + honeypot + no carrier/vector
  leaks. Bridge — `tests/test-forms.php` zero-HTTP: origin/rate/spam gates,
  404/501/400/429 contracts, both dispatch seams + sanitization capture,
  captcha via intercepted `pre_http_request`, no-JS 302 + open-redirect
  guard.
- **Gotchas (each cost a fix 2026-11-05, P7):** the generated project's
  frontmatter context cannot resolve DOM type NAMES (`HTMLInputTypeAttribute`
  → ts(2304)) — cast input `type` to a literal union instead; `is:inline`
  scripts are PLAIN JS — TS assertions (`as unknown as`) fail the check
  (ts(8016)); a type error in one template file makes `astro check` emit
  cascading ts(7027) "unreachable code" noise on unrelated inline scripts —
  fix the root error, not the warnings; `nvoos.json` has no `forms` key on
  form-less profiles — read it through an explicit cast shape; `--overwrite`
  re-scaffold wipes `node_modules` (reinstall); `classifyHtml` must split
  forms from the TOP-LEVEL nodes in document order or non-form content
  re-renders forms twice; the JFB `form_id` carrier is a hidden INPUT NAME
  (`_jet_engine_booking_form_id`), Elementor's is `form_id` + `post_id`
  hidden inputs — no `data-*` surface needed; bridge PHPUnit needs the
  Docker `oos-wp` env (wp-tests-config targets the compose network) — phpcs
  + `php -l` run anywhere, PHPUnit does not.
