# P6 — Sanitize-at-Entry / Escape-at-Exit Codification (May 2026)

> **Status:** ✅ Landed (May 2026)
> **Proposal:** [Unix Theory Compliance Enhancement Proposal §2.6](../UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#26-robustness-sanitize-at-entry-escape-at-exit)
> **Companion phase:** P1 (canonical envelope) used the same shape — narrow sniff + codification doc + cross-link from `.context/`

---

## 1. The Two-Gate Rule

Every tool's `execute()` method MUST satisfy both gates. The rule is repo-wide, not just for new code.

### Gate 1 — Sanitize at Entry

Every value pulled from `$arguments` is sanitised at the top of `execute()` **before** any business logic runs.

```php
public function execute( $arguments, $context ) {
    $post_id   = isset( $arguments['post_id'] )   ? absint( $arguments['post_id'] )                       : 0;
    $title     = isset( $arguments['title'] )     ? sanitize_text_field( wp_unslash( $arguments['title'] ) ) : '';
    $body_html = isset( $arguments['body_html'] ) ? wp_kses_post( wp_unslash( $arguments['body_html'] ) ) : '';
    $tag_list  = isset( $arguments['tags'] )      ? array_map( 'sanitize_key', (array) $arguments['tags'] ) : array();
    // ↑ Gate 1 complete. Business logic begins below.
    …
}
```

**Required when** `$arguments[...]` is:

- assigned to a local variable, or
- passed to **any** function that is not itself a sanitiser/escaper, or
- interpolated into a string (double-quoted, HEREDOC, or `.` concatenation), or
- inserted into a database (must go through `$wpdb->prepare()` with placeholders).

**Not required when** `$arguments[...]` is the operand of a key/type check:
`isset()`, `empty()`, `array_key_exists()`, `is_array()`, `count()`, `unset()`.

### Gate 2 — Escape at Exit

Every value returned in the canonical-envelope `data` array — **and** every value written into a database, HTTP response header, redirect URL, or rendered HTML — is escaped/prepared regardless of where it came from. Trust nothing about upstream context.

```php
return $this->format_success_response(
    sprintf( __( 'Updated post %d.', 'mcp-ai-wpoos' ), $post_id ),
    array(
        'post_id' => (int) $post_id,
        'title'   => esc_html( $title ),
        'url'     => esc_url_raw( $url ),     // raw URL for JSON; esc_url for HTML.
    )
);
```

---

## 2. Canonical Sanitiser / Escaper Allow-list

This list is what the [`WPMCPAI.Tools.SanitizeAtEntry`](#3-phpcs-sniff) sniff treats as a "safe wrapper". Use these by name; do not invent ad-hoc helpers.

### Sanitisers (Gate 1)

| Input type | Function |
|------------|----------|
| Positive integer / ID | `absint( $x )` |
| Signed integer | `intval( $x )` / `(int) $x` |
| Float | `floatval( $x )` / `(float) $x` |
| Boolean | `(bool) $x` / `rest_sanitize_boolean( $x )` |
| Single-line text | `sanitize_text_field( wp_unslash( $x ) )` |
| Multi-line text | `sanitize_textarea_field( wp_unslash( $x ) )` |
| Email | `sanitize_email( $x )` |
| Option key / slug | `sanitize_key( $x )` |
| File name | `sanitize_file_name( $x )` |
| Username | `sanitize_user( $x )` |
| Hex colour | `sanitize_hex_color( $x )` |
| HTML class | `sanitize_html_class( $x )` |
| MIME type | `sanitize_mime_type( $x )` |
| Rich HTML (post body) | `wp_kses_post( wp_unslash( $x ) )` |
| Restricted HTML | `wp_kses( $x, $allowed_tags )` |
| Plain text from HTML | `wp_strip_all_tags( $x )` |
| URL (storage) | `esc_url_raw( $x )` |
| Array of scalars | `array_map( 'sanitize_text_field', (array) $x )` |
| JSON | `wp_unslash` → `json_decode` → sanitise each value individually |

### Escapers (Gate 2)

| Output context | Function |
|----------------|----------|
| HTML body | `esc_html( $x )` |
| HTML attribute | `esc_attr( $x )` |
| URL in HTML | `esc_url( $x )` |
| `<textarea>` | `esc_textarea( $x )` |
| Inline JS | `esc_js( $x )` |
| JSON payload | `wp_json_encode( $x )` |
| SQL value | `$wpdb->prepare( $sql, $x )` (placeholders) |

### Always pair with `wp_unslash()` for `$_POST` / `$_GET` / `$_REQUEST`

The REST plumbing already unslashes `$arguments` before handing them to `execute()`, so tools normally do not need `wp_unslash` for tool input. The exception is when a tool also reads `$_POST` directly (rare and discouraged).

---

## 3. PHPCS Sniff `WPMCPAI.Tools.SanitizeAtEntry`

A narrow, high-signal sniff at [`phpcs/WPMCPAI/Sniffs/Tools/SanitizeAtEntrySniff.php`](../../../phpcs/WPMCPAI/Sniffs/Tools/SanitizeAtEntrySniff.php) flags the two highest-risk Gate-1 violations:

1. **Direct interpolation** of `$arguments[...]` (or `$args[...]`) inside a double-quoted string or HEREDOC.
2. **String concatenation** `'...' . $arguments[...]` where the surrounding call is not in the safe-wrapper allow-list.

### Why so narrow?

These two patterns are the classic SQL-injection / HTML-injection / SSRF vector. Detecting every sanitiser-less read of `$arguments` produces too many false positives (e.g. legitimate `?? array()` fallbacks, key-existence checks, defensive copies). The repo-wide enforcement of the broader Gate-1 rule belongs to human code review — see the checklist in [`.context/security-checklist.md`](../../../.context/security-checklist.md).

### Scope

The sniff only triggers inside paths matching `/includes/tools/` or `/addons/pro/includes/tools/`. Helpers, services, REST controllers, and admin pages have their own validation conventions and are out of scope.

### Severity

Warning severity **5**, mirroring the [P1 canonical-envelope sniff](./P2-capability-fence-audit-2026-05.md):

- `composer run lint` (default `--warning-severity=5`) → **visible**.
- `composer run lint:base` (`--warning-severity=8`) → **silent** (does not break CI).

This lets PR reviewers see new violations on touched lines without forcing a repo-wide cleanup PR.

### What it allows

```php
// All of these are clean:
$id   = absint( $arguments['id'] );
$slug = sanitize_key( $arguments['slug'] );
$ok   = isset( $arguments['key'] );
$sql  = $wpdb->prepare( 'SELECT * FROM x WHERE id = %d', $arguments['id'] );
$url  = esc_url( 'https://x.test/' . $arguments['endpoint'] );
```

### What it warns on

```php
// Warned: direct interpolation.
$sql = "SELECT * FROM wp_posts WHERE id = {$arguments['id']}";

// Warned: concatenation without a safe wrapper.
$url = 'https://api.example.com/' . $arguments['endpoint'];
```

---

## 4. Baseline Findings

Running the sniff against `includes/tools/` (255 base tool files) at default severity surfaced exactly **2 warnings**, both in the same file:

| File | Lines | Risk | Disposition |
|------|-------|------|-------------|
| `includes/tools/orchestration/class-wp-mcp-ai-tool-create-task-plan.php` | 143, 144 | Plan name + goal interpolated raw into markdown output | **Real smell.** Pre-existing; out of scope for this PR. Tracked for cleanup in a follow-up. The interpolated values flow into the canonical-envelope `data` field which downstream surfaces may render as HTML. |

That's a 0.78% hit rate — well below noise threshold. Future tool authors will see the warning on PR review without flooding the codebase.

### Reproducing the baseline

```bash
vendor/bin/phpcs \
    --standard=phpcs.xml.dist \
    --sniffs=WPMCPAI.Tools.SanitizeAtEntry \
    --warning-severity=5 --error-severity=1 \
    includes/tools/
```

---

## 5. Acceptance Criteria

- [x] Two-gate rule formally codified with a canonical sanitiser/escaper allow-list.
- [x] `WPMCPAI.Tools.SanitizeAtEntry` PHPCS sniff implemented at warning severity 5.
- [x] Sniff scoped to tool implementations (`includes/tools/`, `addons/pro/includes/tools/`).
- [x] Sniff is silent under `composer run lint:base` (warning severity 8) so CI is unaffected.
- [x] `phpcs.xml.dist` references the sniff alongside the P1 envelope sniff.
- [x] Baseline run produces 2 warnings (real smells) across 255 base tool files.
- [x] `.context/security-checklist.md` cross-links this codification document and the P6 sniff.
- [x] [`CLAUDE.md` Tool Implementation Pattern](../../../CLAUDE.md#tool-implementation-pattern) cross-links the two-gate rule.

---

## 6. Related Documents

- [`docs/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md`](../UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md) — Parent proposal (P6 row in §3)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — Repo-wide security checklist (cross-links here)
- [`.context/conventions.md`](../../../.context/conventions.md) — Naming + style conventions
- [`docs/proposals/audits/P5-action-split-audit-2026-05.md`](./P5-action-split-audit-2026-05.md) — Action-split audit (Part 1, May 2026)
- [`docs/proposals/audits/P2-capability-fence-audit-2026-05.md`](./P2-capability-fence-audit-2026-05.md) — Capability-fence audit (May 2026)
- [`phpcs/WPMCPAI/Sniffs/Tools/SanitizeAtEntrySniff.php`](../../../phpcs/WPMCPAI/Sniffs/Tools/SanitizeAtEntrySniff.php) — Sniff implementation
- [`phpcs/WPMCPAI/Sniffs/Tools/CanonicalReturnEnvelopeSniff.php`](../../../phpcs/WPMCPAI/Sniffs/Tools/CanonicalReturnEnvelopeSniff.php) — Companion P1 sniff
