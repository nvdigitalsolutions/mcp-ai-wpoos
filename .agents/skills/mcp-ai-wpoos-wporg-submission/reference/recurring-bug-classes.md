# Recurring bug classes in standalone WordPress plugins

These are the defects that pass **Plugin Check, PHPCS, `php -l` and a green
unit-test suite** and only surface in a real WordPress request. Every one of
them has shipped in an NV oOS standalone plugin at least once. Run this sweep
before *every* submission, and again after any change to storage, redirects,
JSON import, or admin markup.

Origin: the `nvoos-design-system` 1.0.0 pass (2026-11-28), where a reviewer
report (enqueue / sanitise / escape / prefix / direct-AI-provider) was fixed
and a full Docker QA then found four more defects nobody had flagged.

---

## The 10-minute sweep

**Automated:** `python bin/audit-plugin-bug-classes.py addons/<slug> [more…]`
— runs checks 1–8 below and prints `OK` / `REVIEW` per class with file:line
evidence. It is a *signal* scanner: every `REVIEW` needs a human look, and it
deliberately knows about the common false positives (email/export builders
that legitimately print `<style>`, JSON download handlers that `echo $json`,
REST routes whose `sanitize_callback` already cleaned the input, activation
pages that write a literal shortcode, `phpcs:ignore … EscapeOutput`
annotations, class-only files without an `ABSPATH` guard).

Fleet baseline (2026-11-28, 38 plugin dirs): 19 signals, 0 crashes — the
classes only bite the plugins that store rich HTML, redirect after an action,
or parse untrusted JSON. Use the signal count to decide depth:

| Tier | Plugins | Depth |
|---|---|---|
| **A — live on wp.org** | docs-hub, content-graph, forge-bridge | static sweep every release + full Docker QA before every upload |
| **B — submitting** | design-system | one full Docker QA pass (the checklist below) before the first upload |
| **C — internal / Pro addons** (27) | `addons/pro`, document-editor, media-studio, … | static sweep once as a baseline; fix by severity when the plugin is next touched, not repo-wide |
| **D — examples / demos** (14) | `examples/*` | boot smoke only — they do not ship |

`embedded` and `page-agent` are **checked but not wp.org-bound** (internal
builds, no directory submission planned). They were swept in the same
2026-11-28 baseline and their results are kept as reference points:
`embedded` 1 benign signal (a clamped float on an untrusted dimension),
`page-agent` clean (0 signals). Do not move them to Tier B without a
deliberate decision to submit.

Raw commands, when you want them by hand:

```sh
cd addons/<slug>

# 1. KSES-sensitive writes (custom post types storing markup)
grep -rn "wp_insert_post\|wp_update_post" includes/ --include="*.php"

# 2. Redirects that must carry user feedback
grep -rn "wp_safe_redirect\|wp_redirect" includes/ --include="*.php"
grep -rn "add_settings_error" includes/ --include="*.php" | wc -l

# 3. Arithmetic / numeric casts on untrusted input
grep -rn "0 + \|(float) \|(int) " includes/ --include="*.php"

# 4. Raw asset output and inline handlers
grep -rn "<style\|<script" includes/ --include="*.php"
grep -rn "on\(submit\|click\|change\|load\|error\)=" includes/ --include="*.php"

# 5. Unescaped echo
grep -rn "echo \$" includes/ --include="*.php" | grep -v "esc_\|wp_kses\|wp_json"

# 6. json_decode sites (each must validate before use)
grep -rn "json_decode" includes/ --include="*.php"

# 7. Hook drift: code vs README
grep -rhoE "(apply_filters|do_action)\(\s*'[a-z0-9_]+'" includes/ \
  | sed -E "s/.*'([a-z0-9_]+)'/\1/" | sort -u > /tmp/hooks-code.txt
grep -oE '`(nvoos_nds_|nds_)[a-z0-9_]+`' README.md | tr -d '`' | sort -u > /tmp/hooks-docs.txt
comm -23 /tmp/hooks-code.txt /tmp/hooks-docs.txt   # hooks missing from docs

# 8. Direct-access guard on every runtime PHP file
for f in $(find . -name "*.php" -not -path "./tests/*"); do
  grep -q "defined( 'ABSPATH' )" "$f" || echo "MISSING GUARD: $f"
done   # uninstall.php uses WP_UNINSTALL_PLUGIN instead — that is correct

# 9. Every admin-post handler has both checks
grep -c "current_user_can( 'manage_options' )" includes/class-*-plugin.php
grep -c "check_admin_referer" includes/class-*-plugin.php   # counts must match handlers
```

---

## 1. KSES silently strips markup from custom-post-type content

**Symptom.** A seeder or importer stores HTML; later the layout is broken, CSS
blocks are gone, `<!DOCTYPE>` is missing, audit scores drop, email clients
render an unstyled fragment. Looks like "the template file is wrong".

**Why static gates miss it.** KSES runs as a *filter* inside
`wp_insert_post()` (`content_save_pre` → `wp_filter_post_kses`), only when
`current_user_can( 'unfiltered_html' )` is false. That is **every front-end
request** (where an `init`-hooked seeder runs), **cron**, and **WP-CLI**. Unit
tests often run as an administrator on single site, where the capability *is*
present — so the suite is green.

**Detection.** Compare a stored post's content with the source file:

```sh
wp eval '$p = get_page_by_path( "letterhead", OBJECT, "nvoos_nds_email" );
  echo strlen( $p->post_content ), " ", ( false !== stripos( $p->post_content, "<style" ) ? "style ok" : "STYLE STRIPPED" );'
```

**Fix.** Validate with your own allow/deny validator first, then lift KSES for
the single write:

```php
$safe = My_Validator::validate( $html );      // rejects scripts/frames/handlers
if ( is_wp_error( $safe ) ) { return $safe; }

$lifted = false;
if ( ! current_user_can( 'unfiltered_html' ) ) {
    kses_remove_filters();
    $lifted = true;
}
$post_id = wp_insert_post( wp_slash( $postarr ), true );
if ( $lifted ) {
    kses_init_filters();
}
```

**Regression test.** `wp_set_current_user( 0 )`, call the write path with a
document containing `<!DOCTYPE>`, `<style>` and VML, assert all three survive
*and* that `wp_kses_post()` still strips `<script>` afterwards.

**Never** "fix" this by requiring `unfiltered_html`, and never store the HTML
in an option instead — the preview and the editor both need a post.

---

## 2. Admin notices vanish across `admin-post.php` redirects

**Symptom.** Activate / save / import / delete "does nothing" — the action
actually ran (state changed) but no message appears, so the user retries and
double-applies.

**Why static gates miss it.** `add_settings_error()` only appends to the
in-request `$wp_settings_errors` global. Core's `settings_errors()` merges the
`settings_errors` transient **only when `$_GET['settings-updated']` is set**
(see `get_settings_errors()` in `wp-admin/includes/template.php`). A plain
`wp_safe_redirect()` therefore drops every message.

**Detection.** Submit a form that always fails (e.g. import a template with
`<script>`) and check the landing page for a `.notice`.

**Fix.** Carry notices yourself, user-scoped:

```php
// after the action, before redirect
$errors = get_settings_errors();
if ( ! empty( $errors ) ) {
    set_transient( self::NOTICE_KEY . '_' . get_current_user_id(), $errors, 5 * MINUTE_IN_SECONDS );
}
wp_safe_redirect( $redirect );
exit;

// on the destination page
foreach ( self::consume_admin_notices() as $notice ) {
    wp_admin_notice( $notice['message'], array( 'type' => $notice['type'], 'dismissible' => true ) );
}
```

Also: call `settings_errors()` **once** per page — it deletes the core
transient on first use, so a second scoped call renders nothing.

**Regression test.** Set the transient for user 1, assert `consume()` returns
it and clears it; assert user 2 sees an empty array.

---

## 3. Arithmetic on untrusted JSON crashes (or silently corrupts)

**Symptom.** A malformed DTCG/JSON import throws
`TypeError: Unsupported operand types: int + string` and white-screens the
admin, or a colour silently becomes `#ff0101`.

**Why.** `0 + 'wide'` is a fatal `TypeError` in PHP 8. `(float) array()` is
**not** an error — it returns `1.0`. So the same unchecked input either kills
the request or produces a wrong value, depending on the cast style.

**Detection.** Feed every importer a document where each numeric field is
`"x"`, `[]`, `{}` and `null`, and assert a clean `WP_Error`/error entry rather
than a fatal.

**Fix.** One helper, used everywhere before any cast:

```php
private static function numeric_or_null( $value ) {
    if ( is_int( $value ) || is_float( $value ) ) { return (float) $value; }
    if ( is_string( $value ) && is_numeric( trim( $value ) ) ) { return (float) trim( $value ); }
    return null;
}
```

Then branch to an error string. Do the same for array components
(`$color['components'][0]`), alpha channels, dimension/duration values, and
`$entry['$value']` (guard with `is_scalar()` before `(string)`).

**Regression test.** Non-numeric component, non-numeric alpha, non-numeric
dimension, non-numeric duration — each asserts `''` value **plus** a non-empty
error list.

---

## 4. The same CSS class on a container and its control

**Symptom.** A preview pane renders at 300 px, or doubles its border, or the
layout jumps only on some pages. Works in isolation, breaks inside the real
wrapper.

**Why.** `.foo { width:100%; height:640px; border:1px }` applied to **both**
`<div class="foo">` and the `<iframe class="foo">` inside it. The outer box
takes the iframe's dimensions and the iframe takes the outer box's.

**Detection.** For every class that sets `width`, `height`, `border` or
`overflow` in the admin CSS, count how many element types use it:

```sh
grep -n "width\|height\|border\|overflow" assets/css/admin.css
grep -rn 'class="nvoos-nds-preview' includes/    # parent + child?
```

**Fix.** One class per role: `.nvoos-nds-preview-frame` (container) and
`.nvoos-nds-preview-iframe` (control), each with only its own rules.

**Also check** `srcdoc` previews: `esc_attr()` the document, keep
`sandbox=""` (empty = maximum restriction), and give the iframe an explicit
height so it cannot collapse.

---

## 5. Empty-state forms (render assumes a resolved record)

**Symptom.** On a fresh install a settings box shows an empty heading and a
form that, when submitted, replies "record not found". Or a preview area is a
blank grey rectangle.

**Why.** `render_*()` methods assume an active template / selected post / saved
option exists. "Default off" features make that the *normal* first-run state.

**Detection.** Deactivate → delete the plugin's options → reload every admin
tab and look for empty headings, orphan forms, or text areas with no context.

**Fix.** Every renderer that depends on a record gets an explicit branch:

```php
if ( ! $active ) {
    echo '<p class="description">' . esc_html__( 'No template is active yet…', '…' ) . '</p>';
    return;
}
```

**Regression test.** Delete the option, render the tab into a buffer, assert
the explanatory copy is present and the form action is absent.

---

## 6. Silent no-ops

**Symptom.** "Import succeeded" with nothing imported; "saved" with no change.

**Fix.** When an operation completes without errors but changed nothing, emit a
warning ("No recognised tokens were found in the document; nothing was
changed."). Do not rely on the absence of errors to mean success.

---

## 7. Docs drift (hooks, options, scripts)

**Symptom.** README documents a filter that no longer exists, or a `bin/`
script that was deleted; integrators copy it and nothing happens.

**Detection.** The hook diff in the sweep. Also grep the docs for `bin/` and
option names and confirm each file/key exists.

**Rule.** If a hook is renamed, it ships in the same release **and** appears in
the Upgrade Notice. If a script is replaced (e.g. PHP → Python), update the
README in the same commit.

---

## 8. Enqueue compliance (the reviewer's favourite finding)

- No hand-rolled `<style id="…">` or `<script>` in PHP. Register a handle
  (`wp_register_style( 'handle', false )`) and `wp_add_inline_style()` on
  `wp_enqueue_scripts` / `admin_enqueue_scripts`.
- No inline event handlers. Put the message in a `data-*` attribute
  (`data-nvoos-nds-confirm="…"` with `esc_attr__( … )`) and bind it from an
  already-enqueued script. Inline handlers are not a PCP finding but a human
  reviewer will raise them, and the copy is untranslatable.
- Grep the CSS a second time after refactors: a selector that only exists in
  the stylesheet (because the class was renamed) is invisible to every gate.

---

## 9. Features that mutate user output must default to off

Email wrapping, content rewriting, header injection, redirects. A design /
branding plugin must not silently rebrand every outgoing email on activation.
Ship the feature off, require an explicit choice (template **and** delivery
mode), and say so in the readme and the reviewer note. Then the "does it
change my site on activation?" answer is a one-liner.

---

## 10. Destructive QA operations (process safety)

- **Never `wp plugin uninstall` a bind-mounted plugin.** WordPress deletes
  `wp-content/plugins/<slug>` recursively — with a bind mount that is the
  **host source tree**. It destroyed a working directory mid-session. Instead:
  `cp -r` the plugin into the container volume, or exercise the cleanup by
  calling it from `wp eval-file`.
- Keep the source of truth in git **and** stage a ZIP-shaped snapshot before
  any destructive run. The recovery path in that incident was the tree staged
  by the Plugin Check script minutes earlier.
- Tear down scratch DBs, volumes and containers in the same session, and
  verify with `docker ps -a | grep <slug>`.

---

## Where these belong in the pipeline

| Gate | Catches |
|---|---|
| `php -l`, PHPCS | syntax, escaping, prefixes, nonces |
| PHPUnit | conversions, sanitisation, storage contracts |
| Plugin Check | readme/header/packaging, direct AI providers, hidden files |
| **Real request QA (Docker + WP-CLI + browser)** | **all ten classes above** |
| Multisite QA | per-site options, network writes, network-wide uninstall |
