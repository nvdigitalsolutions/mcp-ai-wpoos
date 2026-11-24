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

## Live dispatch — the two contract bugs (bridge 0.3.3–0.3.4 + Form.astro, 2026-11-24)

Wiring the ECA enquiry bands end to end proved the bridge is only as good as the
**pair** of contracts it sits between. Both halves were wrong at once, and each one
alone produced the same opaque symptom.

### Symptom

`POST /wp-json/nvoos-forge/v1/forms/submit` → HTTP **200** with
`{"ok":false,"message":"Your submission failed because the form is invalid.","errors":[]}`,
and **no** row in Elementor → Submissions. A 200 means a native handler ran and
rejected the submission — it is never the route/gate layer (that answers 404/403/429).

### Bug 1 — the wrong ajax action (bridge)

Elementor Pro gates every classic-form dispatch on its own action, not a nonce:

```php
// elementor-pro/modules/forms/classes/ajax-handler.php
public static function is_form_submitted() {
    // PHPCS - No nonce is required, all visitors may send the form.
    return wp_doing_ajax() && isset( $_POST['action'] )
        && 'elementor_pro_forms_send_form' === $_POST['action'];
}
```

The bridge forced `wp_doing_ajax()` true but set
`$_POST['action'] = 'elementor_pro_forms'` — the **nonce** action. The strings
differ by `_send_form`, so `is_form_submitted()` was false on every dispatch.
Sending a valid `_nonce` changes nothing (the handler never verifies one; a bogus
or empty `_nonce` still succeeds natively).

Fixed by `Forms::elementor_request()` — one method that builds the whole ajax
request, unit-tested on the exact action string.

### Bug 2 — the page never sent `post_id` (component)

With the bridge fixed, the **browser** still failed while the identical curl
payload succeeded. Elementor resolves the widget through the document:

```php
$document = Plugin::$instance->documents->get( (int) $_POST['post_id'] );
$this->instance = $this->find_element_recursive( $document->get_elements_data(), $form_id );
```

The forged form renders the hidden `post_id` carrier (it is in the source markup)
and the bridge's `elementor_has_form()` deliberately searches **all** posts — so the
render was found, `post_id` 0 was handed to the handler, `documents->get(0)` returned
null, and the same "form is invalid" came back. `Form.astro` had been skipping
`post_id` with the other reserved keys when collecting fields. It now reads the
hidden carrier into the payload; `tests/forms.test.ts` pins it.

`queried_id`, `referrer` and `referer_title` are **not** required — native
submissions succeed with none of them.

Bridge **0.3.4** makes it self-describing: an Elementor submission with `post_id`
0 answers `400 nvoos_forge_form_post_id_required` instead of being forwarded to a
guaranteed rejection.

### JetFormBuilder is different

`Jet_Form_Builder\Form_Handler::process_form()` has **no** action guard: it needs
`$_POST[ $form_key ]` (`_jet_engine_booking_form_id`) and
`$_POST[ $refer_key ]` (`_jet_engine_refer`), or it answers `{status:'failed', reload:true}`.
It reads `$_POST['form_fields']` as a **nested map**, which PHP's own POST parsing
produces from `form_fields[name]` keys — a JSON body never does, so
`Forms::bracket_fields()` rebuilds it while keeping the flat keys for
`jet_fb_context()->update_request()`.

### Diagnostic ladder (in this order)

1. `GET /wp-json/nvoos-forge/v1/health` → `formsEnabled`, `formsConfigured`,
   `formsOriginsCount`.
2. The submitting origin is in Settings → Forge Bridge → Static origins (exact
   `scheme://host[:port]`, no trailing slash).
3. Submit **in a browser** from the forged origin and read `.ds-form-status`. A curl
   that passes while the page fails means the component's payload (bug 2 class).
4. `POST …/forms/submit` with the page's own body + an `Origin` header:
   `404 form_not_found` = neither handler resolved the identifier;
   200 `ok:false` = a native handler ran and rejected it (bug 1 class).
5. Submit the **real** WP form and read the `admin-ajax.php` response
   (`{"success":true,"data":{"message":"Your submission was successful."}}`).
   If the source fails too, the site is the problem, not the bridge.
6. To read a handler's own source/state on a live site, install a throwaway probe
   plugin with a token-gated REST route (`ReflectionClass::getFileName()` +
   `file_get_contents()`) — 5 upload cycles of guessing is slower than one probe.
   Delete it afterwards.

### Operational notes (learned the hard way)

- **Package the bridge with `bin/build-nvoos-forge-bridge.py`**, never a hand-rolled
  zip: `plugins/nvoos-forge-bridge/.distignore` excludes the dev `vendor/`, and the
  builder emits the 16-file shipping shape. A 17.7 MB zip that includes
  `vendor/wp-phpunit` extracts into a `tmp-…` wrapper directory that WordPress then
  cannot activate ("Plugin file does not exist."); re-uploading the clean zip on top
  fixes the stale row and leaves the correct `nvoos-forge-bridge/` path.
- **Replacing the bridge re-activates it** via "Replace current with uploaded" —
  always re-check `/health` for the new `pluginVersion` before testing a fix, and
  never ship two different artefacts under one version.
- **Elementor submissions are a CPT the admin list paginates** ("All (n)"); a test
  form's rows land there and are worth trashing after verification. The bulk-action
  combobox is a React control — `select.value = …` does not register; use the real
  select or the row action.
