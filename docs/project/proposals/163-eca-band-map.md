# 163 — ECA band map: source vs forged

**Purpose.** The design-detach map. Before the front-end stops being a *re-analysis
of a live Elementor page* and becomes a *template with a data connection*, every
band on every page must be classified as one of:

| Class | Meaning after the detach |
|---|---|
| `freeze` | Design + copy baked into the template/profile. WP never supplies it again. |
| `cms` | Editorial copy the school edits in WordPress (`wp/v2/pages`, `wp/v2/posts`). |
| `eca` | Catalogue data (`wp/v2/mcp_ai_eca`, `nvoos-eca/v1`). Must stay live. |
| `form` | The enquiry path (`forms` bridge). Must stay connected. |
| `media` | Images the media pass mirrors. |

**Captured** 2026-11-24 against `eca.directory` and the staging app
`nodejs-1659781-6801643.cloudwaysnodeapps.com` (build `b4b438a8ee-manual2`).
**Updated** 2026-11-24: the `<h1>` is restored (`46bd8e940f`), the enquiry bands
submit through `nvoos-forge-bridge` 0.3.4, and the Elementor dispatch defect that
made every submission fail is fixed (§6).

> **Source caveat.** The rendered HTML of all three pages is the Elementor
> maintenance screen for anonymous requests (74,808 bytes each — that is why the
> profile's `design.bandSource` is `outline`). Band structure below comes from the
> page bodies served by `wp/v2/pages` (`content.rendered`), which is the same
> markup the build consumes. A maintenance-mode gate is still needed for any
> rendered-HTML measurement (`analyze --design/--text/--geometry`).

---

## 1. Home — 7 source bands, 7 forged bands

| # | Elementor id | Source format (widgets) | Forged kind | Chars src → forged | Status | Class |
|---|---|---|---|---|---|---|
| 1 | `6daaaef9` | heading **h1**, text-editor, testimonial, rating, counter-ish, buttons | `feature-grid` | 310 → 395 | parity, **h1 restored** | `freeze` |
| 2 | `4c649f2e` | heading, text-editor, button ("Explore Our Activities") | `text` | 316 → 316 | parity | `eca` (should be a grid) |
| 3 | `41b89b66` | heading, 4 × icon-box | `feature-grid` | 475 → 465 | parity | `freeze` |
| 4 | `77f4271d` | heading, text-editor, icon-list, icon, buttons ("Phased Delivery") | `feature-grid` | 728 → 694 | parity | `freeze` |
| 5 | `3589820` | heading, text-editor, icon-box, social-icons ("Meet the Project Team") | `text` | 480 → 480 | parity, **social icons lost** | `cms` |
| 6 | `319dfe29` | heading, text-editor, **posts** widget ("Our Updates") | `feature-grid` | 183 → 130 | **diverged** | `cms` |
| 7 | `71dbef2b` | **form**, heading ("Questions?") | `form` | 97 → 2,121 | **live 2026-11-24** | `form` |

### What each divergence is

**Band 1 — the `<h1>` disappeared — FIXED (`46bd8e940f`).** The forged page used
to contain zero `<h1>` elements: the hero's headline ("ECA Management for British
School in Colombo") was captured as a grid item and rendered as `<h3>`, while the
band title became `<h2>Parent ratings</h2>` — a label from the rating widget.
Cause: `pickHeadingTitle()` let a widget label outrank the band's own `h1`, and
the `feature-grid` kind won because the band had ≥2 items. An `h1` inside an item
widget is now the band title (`slots.titleLevel`, `title-tag.ts` `allowH1`,
`DesignRenderer` `pageHeading`), so the home owns exactly one `<h1>` again.

**Band 6 — the posts widget.** Source renders the Elementor posts widget: one
entry ("Hello world!", the WordPress placeholder) plus "Read more articles". The
forged band now drops the placeholder card (by design) and keeps the
`News & Insights` card. The "Read more articles" button renders `href="#"` — the
source link target did not survive classification.

**Band 7 — the form — LIVE.** Source is a real Elementor Pro form
(`Your Name / Your Email / Message / Send my message`, widget id `329253bd`, carrier
post 2) posting `admin-ajax.php?action=elementor_pro_forms_send_form`. The forged
page rebuilds the same three fields, the same `form`/`post_id` carriers and a
`Send my message` button, and posts JSON to
`https://eca.directory/wp-json/nvoos-forge/v1/forms/submit`; the bridge dispatches
into Elementor's own handler, so validation, the submission record and the mail
action all run natively. Verified end to end 2026-11-24 (`{"ok":true}` and a row in
Elementor → Submissions).

When no bridge is configured the band still degrades honestly to the four
`eca.contacts` addresses + `/help/` — never a form that cannot post.

**Band 2 — static where the product wants data.** "Explore Our Activities" is
copy in `4c649f2e`, and the ECA catalogue is a separate route (`/activities/`).
The band renders as text. For a product front-end this band is the natural home of
a live activity grid (`eca` class), not a copy block.

---

## 2. About-us — 4 source bands, 4 forged bands

| # | Elementor id | Source format | Forged kind | Chars src → forged | Status | Class |
|---|---|---|---|---|---|---|
| 1 | `3feed876` | icon-list (breadcrumb), text-editor | `text` | 204 → 199 | parity | `cms` |
| 2 | `49146de0` | heading, 4 × icon-box ("Our benefits") | `feature-grid` | 619 → 609 | parity | `freeze` |
| 3 | `14c60321` | heading, **4 × counter** ("Why choose our platform") | `stats` | 150 → 150 | **fixed 2026-11-24** | `freeze` |
| 4 | `3a289853` | heading, text-editor, button ("Our blog") | `text` | 110 → 110 | parity | `cms` |

### Band 3 — counters (was broken, now correct)

Source values live in `data-to-value`, not in the DOM text:

```html
<div class="elementor-counter">
  <div class="elementor-counter-title">Hour allocation time</div>
  <span class="elementor-counter-number-prefix">≤</span>
  <span class="elementor-counter-number" data-to-value="48" data-from-value="0">0</span>
  <span class="elementor-counter-number-suffix"></span>
</div>
```

Elementor animates from `0`, so **the text is `0` even on the live site**. The
forged band used to render `0 / %`, `0 / % less`, `0 / Term`, with the titles
("Hour allocation time", …) replaced by suffix fragments.

Two causes, both fixed:

1. `sanitize.ts` stripped `data-*` from `span`, so the classifier could only ever
   see `0`. `data-to-value` is now allowlisted (inert in output).
2. `statsFrom()` read text and the *next sibling* as the label. `elementorCounterStats()`
   now reads `data-to-value` + prefix + suffix + `.elementor-counter-title`.

Renders today: `≤48 / Hour allocation time`, `100% / Parents updated within 5
minutes`, `50% less / Routine ECA admin emails`, `1 Term / Pilot implementation`.

---

## 3. Contact-us — 3 source bands, 3 forged bands

| # | Elementor id | Source format | Forged kind | Chars src → forged | Status | Class |
|---|---|---|---|---|---|---|
| 1 | `6f0d483` | icon-list (breadcrumb only) | `text` | 4 → 0 | empty in source too | `freeze` |
| 2 | `9c2d1fa` | **form** (First/Last/Email/Message), heading | `form` | 74 → 2,081 | **live 2026-11-24** | `form` |
| 3 | `2f396e7` | heading, **accordion** (FAQ) | `accordion` | 786 → 709 | parity | `cms` |

Band 2 posts through the same bridge as the home band — this is the enquiry path
that must survive the design detach, and it now does. It degrades to the four
`eca.contacts` addresses (never a dead form) when the bridge is unconfigured.

Band 3 is the only real accordion on the site and it rebuilds cleanly — good
evidence the FAQ copy can stay `cms`.

---

## 4. Formats — what feeds what

| Format | Producer | Consumer | Bands |
|---|---|---|---|
| `nvoos-eca/v1` record (105 records) | `wp/v2/mcp_ai_eca` → `lib/eca.ts` | `/activities/`, `/day/*`, `/stage/*`, `/category/*`, `/type/*`, `/calendar/`, `/timetable/*`, `.ics` | none on the three mapped pages — **catalogue lives in its own routes** |
| Page body (`content.rendered`, Elementor) | `wp/v2/pages` → `sanitizeHtml` → `classifyBody` | `DesignRenderer` | every band above |
| `manifest.contact` (`people[]`, `links[]`) | scaffold, derived from `eca.contacts` | degraded `form` bands, `#contact` block, `Organization.contactPoint` | home 7, contact 2 |
| `manifest.forms` | profile `forms` + env | `Form` component (live bridge) | home 7, contact 2 |
| `profile.design.sections[]` + `text.sections[]` | `analyze --design --text` | band audit (`verify`) | all 10 source bands, keyed by Elementor id |
| `profile.pageBands` | `analyze --geometry` per page | sub-page band facts | about-us / contact-us (absent today → sub-pages reuse home surfaces) |
| `wp/v2/posts` | loader | `/posts/`, home band 6 | home 6 |

**Key structural fact:** the three mapped pages are *page bodies*, not ECA data.
Detaching the design does **not** touch `nvoos-eca/v1` at all. The catalogue is
already independent; only bands 2 (home) and 6 (home) could ever consume it.

---

## 5. What "detach" means, band by band

**Freeze (design stops depending on re-analysis):**
- `6daaaef9` hero — but fix the `h1` first; freezing the current output would bake
  a page with no `h1`.
- `41b89b66`, `49146de0` benefits grids — copy + geometry.
- `77f4271d` phased delivery — the largest band (2,810 px), pure design.
- `14c60321` counters — **fixed** (numbers, prefix, suffix and titles).
- `6f0d483`, `3feed876` breadcrumb/intro — cheap, low risk.

**Keep `cms` (school edits these):** `3589820` team (once social icons are
preserved), `319dfe29` updates, `3a289853` blog teaser, `2f396e7` FAQ, and the
intro copy in `3feed876`.

**Keep `eca` (live data):** band `4c649f2e` should become the activities grid on
the home; everything under `/activities/`.

**Keep `form` (the connection the user wants to retain):** bands `71dbef2b` and
`9c2d1fa`. **Done 2026-11-24** — `nvoos-forge-bridge` 0.3.4 is active on
`eca.directory`, forms are enabled with the staging origin on the allowlist, and
`profile.forms` (`{ "delivery": "bridge" }`) makes the manifest default the
endpoint to `https://eca.directory/wp-json/nvoos-forge/v1`.

**Cutover reminder.** The allowlist in Settings → Forge Bridge must gain the public
origin on the day the domain moves; `apps/` submissions from an unlisted origin are
rejected by design.

### Acceptance checks once the design is frozen

1. Every forged page has exactly one `<h1>` (the source's band-1 headline). **Done** (`46bd8e940f`).
2. The three counters print their `data-to-value` numbers with prefix/suffix. **Done.**
3. 7 / 4 / 3 bands present with the same ids in the same order (`verify --minimal`
   now audits only `/`; the sub-pages need `pageBands` to be measured).
4. The two enquiry bands submit to the bridge (and degrade loudly when it is
   absent, as they do now). **Done** — verified end to end 2026-11-24.
5. No band's copy is silently re-derived from a page that is in maintenance mode —
   freeze from `text.sections`, which is captured copy.

---

## 6. The bridge defect this map surfaced (0.3.3–0.3.4, 2026-11-24)

Wiring these two bands proved the form path end to end — and found a defect that
would have made **every** Elementor form on **every** forged site answer
`"Your submission failed because the form is invalid."` while recording nothing.

### Symptom

The bridge returned HTTP 200 with `{"ok":false,…}` for both bands. The source
form submitted natively from a browser as the same post (`2`) with the same
widget id (`329253bd`) and succeeded — anonymous or logged in. The payload shape
was identical, so the fault had to be in the PHP context the bridge emulates.

### Cause

Elementor Pro gates every dispatch on its own action, not on a nonce:

```php
public static function is_form_submitted() {
    // PHPCS - No nonce is required, all visitors may send the form.
    return wp_doing_ajax() && isset( $_POST['action'] )
        && 'elementor_pro_forms_send_form' === $_POST['action'];
}
```

The bridge set `wp_doing_ajax()` true but sent
`$_POST['action'] = 'elementor_pro_forms'` — the **nonce** action, which differs by
`_send_form`. So `is_form_submitted()` was false on every submission.

### Fix

The request is now built by one documented, unit-tested method —
`Forms::elementor_request()` — instead of five inline `$_POST` writes, so the
contract cannot drift again. `test_elementor_request_carries_the_registered_ajax_action`
asserts the exact action string.

Same pass, defensive: `Forms::bracket_fields()` also rebuilds the nested
`$_POST['form_fields']` map that PHP's own POST parsing would have produced from
`form_fields[name]` keys, because a JSON/urlencoded payload never creates it and
JetFormBuilder's request reads it. JetFormBuilder has **no** equivalent action
guard (verified against `Jet_Form_Builder\Form_Handler::process_form()`).

### Defect 2 — the component never sent `post_id`

With the bridge fixed, the same message came back **from the browser**, while the
identical curl payload succeeded. The difference was one missing key.

Elementor resolves the form widget inside the document named by `post_id`:
`documents->get( $post_id )` → walk `get_elements_data()` → the widget. The forged
form renders the hidden `post_id` carrier (it is in the source markup) and the
bridge's existence check deliberately searches *all* posts — but the component's
JSON payload skipped `post_id` along with the other reserved keys, so the handler
was handed post `0`, found no document, and answered "the form is invalid".

Fix: `Form.astro` reads the hidden carrier into the payload
(`test/forms.test.ts` → *the component payload*). The no-JS floor was already
correct — a native POST sends the field, and `parse_payload()` reads it.

0.3.4 makes the same mistake self-describing: an Elementor submission with
`post_id` 0 is answered `400 nvoos_forge_form_post_id_required` instead of being
forwarded to a guaranteed rejection.

**Lesson.** A 200 with `ok:false` from the bridge means a *native handler ran and
rejected the submission*. Check both halves of the contract: the bridge's request
shape, and the page's payload. A curl that succeeds while the browser fails points
at the component, not the bridge.

### What to check when a forged form dies

1. `GET /wp-json/nvoos-forge/v1/health` → `formsEnabled` + `formsConfigured`.
2. The submitting origin is in Settings → Forge Bridge → Static origins.
3. Submit the form **in a browser** from the forged origin and read the inline
   status. A curl-only test can pass while the page still fails (§6, defect 2).
4. `POST …/forms/submit` with the page's own body and an `Origin` header — a
   `404 form_not_found` means neither handler resolved the identifier; a 200 with
   `ok:false` means a native handler ran and rejected it (the class of bug above).
5. Compare against the source: submit the real WP form and read the
   `admin-ajax.php` response. If the source fails too, the site is the problem.
