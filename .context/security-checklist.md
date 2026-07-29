# NV oOS Security Checklist

> **GSD Context File** — Load this at the start of every AI development session.
> This checklist must be applied to **every code change** without exception.
> Last reviewed: July 2026.

---

## Pre-Implementation Security Review

Before writing any code, confirm:
- [ ] Authentication method identified (WordPress Nonce / Bearer token / Guest token)
- [ ] Required capabilities defined for each operation
- [ ] Data flow mapped (what user input reaches the database/API)
- [ ] Third-party API credentials storage confirmed (encrypted meta, never plain text)
- [ ] File upload requirements identified (MIME validation needed?)

---

## Input Sanitization (Required for All User Input)

> **Tools — the two-gate rule (Unix Theory Compliance §2.6, Phase P6):** Every
> `$arguments` value is sanitised at the **top of `execute()`** before any
> business logic (Gate 1), and every value returned in the canonical-envelope
> `data` array is escaped on its way out (Gate 2). The repo enforces the
> highest-risk Gate-1 violations via the PHPCS sniff
> `WPMCPAI.Tools.SanitizeAtEntry`. See the codification document at
> [`docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md`](../docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md)
> for the canonical sanitiser/escaper allow-list and the sniff's scope.

### Use the Right Function

| Input Type | Function |
|-----------|---------|
| General string | `sanitize_text_field()` |
| Multiline text | `sanitize_textarea_field()` |
| Integer | `absint()` or `intval()` |
| Float | `(float)` cast + range check |
| Email | `sanitize_email()` |
| URL (for storage) | `esc_url_raw()` |
| HTML content | `wp_kses_post()` or `wp_kses( $data, $allowed )` |
| Slug/key | `sanitize_key()` |
| File name | `sanitize_file_name()` |
| SQL value | `$wpdb->prepare()` — never string-concatenate |
| Array/JSON | Sanitize each value individually |

### Common Mistakes to Avoid
- ❌ `$_POST['data']` without sanitization
- ❌ `$_GET['id']` passed directly to a query
- ❌ `json_decode( $_POST['json'] )` without sanitizing values
- ✅ `absint( $_POST['id'] )` before any use
- ✅ `sanitize_text_field( wp_unslash( $_POST['name'] ) )`

---

## Output Escaping (Required for All Output)

### Use the Right Function

| Output Context | Function |
|---------------|---------|
| Plain text in HTML | `esc_html()` |
| HTML attribute value | `esc_attr()` |
| URL in href/src/action | `esc_url()` |
| Inline JavaScript string | `esc_js()` |
| JSON output | `wp_json_encode()` |
| HTML content (trusted) | `wp_kses_post()` |
| Translation strings | `esc_html__()`, `esc_attr__()` |

### Common Mistakes to Avoid
- ❌ `echo $variable;` — always escape
- ❌ `echo get_option( 'wp_mcp_ai_title' );` — escape output
- ✅ `echo esc_html( get_option( 'wp_mcp_ai_title' ) );`
- ✅ `echo esc_url( $url );`

---

## Capability Checks

Every privileged operation must check capability BEFORE execution:

```php
// In tool execute() methods:
if ( ! current_user_can( $this->get_required_capability() ) ) {
    return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
}

// In REST endpoint permission_callback:
public function check_permissions() {
    return current_user_can( 'edit_posts' );
}

// In AJAX handlers:
if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
    wp_die();
}
```

### Capability Reference

| Operation | Required Capability |
|-----------|-------------------|
| Read public content | (none — open) |
| Read own content | `read` |
| Create/edit posts | `edit_posts` |
| Manage plugin settings | `manage_options` |
| Delete content | `delete_posts` |
| Manage users | `manage_options` |
| Custom admin ops | `manage_options` |

---

## Nonce Verification

All state-changing requests (POST, AJAX, form submissions) MUST verify a nonce:

```php
// Generate nonce (in template/form):
wp_nonce_field( 'wp_mcp_ai_action', 'nonce' );
// Or via localization:
'nonce' => wp_create_nonce( 'wp_mcp_ai_action' )

// Verify in AJAX handler:
check_ajax_referer( 'wp_mcp_ai_action', 'nonce' );

// Verify in form handler:
if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wp_mcp_ai_action' ) ) {
    wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos' ) );
}
```

### REST API Authentication
REST endpoints use `permission_callback` — never skip it:

```php
array(
    'methods'             => WP_REST_Server::READABLE,
    'callback'            => array( $this, 'handle_request' ),
    'permission_callback' => array( $this, 'check_permissions' ),
)
```

---

## File Upload Security

When handling file uploads:

```php
// Validate MIME type:
$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif' );
$file_type = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
if ( ! in_array( $file_type['type'], $allowed_types, true ) ) {
    return new WP_Error( 'invalid_type', __( 'File type not allowed.', 'mcp-ai-wpoos' ) );
}

// Use wp_handle_upload() — never move files manually:
$uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );
```

---

## API Key & Credential Storage

- ✅ Store encrypted via `WP_MCP_AI_Encryption` helper (if available)
- ✅ Store in WordPress options with `wp_mcp_ai_` prefix
- ❌ Never log API keys (not even partial keys in error messages)
- ❌ Never commit credentials to source control
- ❌ Never expose API keys in REST API responses or JS globals

---

## Database Security

```php
// Always use $wpdb->prepare() for dynamic queries:
$results = $wpdb->get_results(
    $wpdb->prepare(
        'SELECT * FROM %i WHERE user_id = %d AND status = %s',
        $wpdb->prefix . 'my_table',
        $user_id,
        $status
    )
);

// Never:
$wpdb->query( "SELECT * FROM $table WHERE id = $id" ); // ❌ SQL injection risk
```

---

## External HTTP Requests

```php
// Always use wp_safe_remote_get/post for user-provided URLs — never curl directly:
$response = wp_safe_remote_post(
    esc_url_raw( $api_url ),
    array(
        'timeout' => 30,
        'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        'body'    => wp_json_encode( $data ),
    )
);

if ( is_wp_error( $response ) ) {
    return $response;
}

$body = wp_remote_retrieve_body( $response );
$data = json_decode( $body, true );
```

---

## Advanced Patterns (Added July 2026)

### HMAC-Signed Policy Tokens

When server-controlled configuration crosses a client boundary (e.g. shortcode attrs → JS → AJAX handler), use an HMAC-signed policy token instead of sending raw config values:

```php
// Server — generate token:
$payload = wp_json_encode( array(
    'assistant'          => $assistant_id,
    'allow_sensitive'    => false,
    'exp'                => time() + HOUR_IN_SECONDS,
) );
$policy_token = base64_encode( $payload . '|' . wp_hash( $payload ) );

// Client — send token (never reconstruct raw attrs)
// Server — verify token:
list( $json, $hmac ) = explode( '|', base64_decode( $policy_token ), 2 );
if ( ! hash_equals( wp_hash( $json ), $hmac ) ) {
    return new WP_Error( 'invalid_token' );
}
$data = json_decode( $json, true );
if ( $data['exp'] < time() ) {
    return new WP_Error( 'expired_token' );
}
```

Reference: `class-wp-mcp-ai-professional-selector-shortcode.php`.

### Path Traversal Prevention (realpath Containment)

Before any recursive filesystem operation:

```php
$resolved = realpath( $target_path );
$base     = realpath( wp_upload_dir()['basedir'] );
if ( false === $resolved || 0 !== strpos( $resolved, $base ) ) {
    // Log security event, abort operation
    return new WP_Error( 'path_traversal' );
}
```

Reference: `class-wp-mcp-ai-optional-components.php` (ZIP validation), `class-wp-mcp-ai-pro-privacy.php` (directory deletion).

### Admin-Post CSRF Protection

`admin-post.php` endpoints must verify a nonce:

```php
// In the handler at entry:
check_admin_referer( 'wp_mcp_ai_{toolkit}_sync' );

// In the inline JS that builds the URL:
url += '&_wpnonce=' + '<?php echo wp_create_nonce( 'wp_mcp_ai_{toolkit}_sync' ); ?>';
```

Reference: `class-wp-mcp-ai-shopify-sync-toolkit-settings-page.php`, `class-wp-mcp-ai-ezuite-toolkit-settings-page.php`, `class-wp-mcp-ai-flowhub-toolkit-settings-page.php`.

---

## Security Infrastructure (v1.1.42+)

The plugin ships 7 security infrastructure classes in `includes/security/`. When working near REST dispatching, error handling, or destructive operations, reference these classes for canonical patterns:

### Request Guard

`WP_MCP_AI_Request_Guard::wrap_dispatch()` hooks into `rest_dispatch_request` (WP >= 6.5 signature: 5 params: `$result, $wp_rest_server, $request, $route, $handler`). Provides:
- SSE connection slot limiting
- JSON depth enforcement
- Request body size enforcement
- Error verbosity filtering (Safe/Moderate/Debug tiers)
- Asset version stripping (`?ver=` query string removal)

### Security Posture

`WP_MCP_AI_Security_Posture` computes a 0-100 weighted score from 21 signals. Cached (5-minute TTL). Filter: `wp_mcp_ai_security_posture_signals`.

### Destructive Ops Gate

`WP_MCP_AI_Destructive_Ops_Gate` enforces confirmation gates for irreversible operations (bulk delete, mass email, etc.).

### Other Guards

- `WP_MCP_AI_URL_Guard` — URL validation/sanitization before outbound requests
- `WP_MCP_AI_Concurrency_Guard` — prevents overlapping destructive operations
- `WP_MCP_AI_Cost_Tracker` — per-operation cost estimation and budget enforcement
- `WP_MCP_AI_Api_Key_Store` — encrypted at-rest API key storage

Reference: `includes/security/README.md`, `docs/operations/production-hardening-guide.md`.

---

## ABSPATH Guard (Every Non-Root PHP File)

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

---

## Per-Commit Security Checklist

Before every commit, verify:
- [ ] All user input sanitized with appropriate function
- [ ] All output escaped with appropriate function
- [ ] Capability check present before every privileged operation
- [ ] Nonce verified for every state-changing request
- [ ] No credentials or API keys in source code
- [ ] ABSPATH guard on every new PHP file (except root plugin file)
- [ ] `$wpdb->prepare()` used for all dynamic database queries
- [ ] External HTTP requests use `wp_remote_*` functions
- [ ] File uploads use `wp_handle_upload()` with MIME validation
- [ ] `permission_callback` defined for every new REST endpoint
