# Deployment Troubleshooting and Security Checklist

This guide captures the most common issues surfaced while testing the MCP REST
layer and the JetEngine proxy tools. Use it during staging deployments and when
triaging production incidents.

## REST API Context Parameter Issues

### Problem
WordPress REST API `context` parameter (e.g., `?context=edit`) is not processed correctly, which can break:
- Block editor (Gutenberg)
- WooCommerce admin panels
- Plugin operations that require edit context
- Any WordPress core or plugin functionality relying on REST API context parameter

### Root Causes
1. **Caching layers** (Cloudflare, Nginx, Apache) caching REST API responses
2. **WAF/Security plugins** stripping query strings from `/wp-json/` requests
3. **Server configurations** not preserving query parameters in rewrites
4. **CDN/Proxy** caching dynamic REST API responses

### Diagnosis
1. Check if REST API returns 200 OK with `?context=edit`:
   ```bash
   curl -I "https://yoursite.com/wp-json/wp/v2/posts/1?context=edit" \
     -H "Authorization: Bearer YOUR_TOKEN"
   ```

2. Verify query string is preserved:
   ```bash
   # Should show context=edit in request
   curl -v "https://yoursite.com/wp-json/wp/v2/types?context=edit"
   ```

3. Check for caching headers:
   ```bash
   # Should see Cache-Control: no-store, no-cache
   curl -I "https://yoursite.com/wp-json/wp/v2/posts/1?context=edit"
   ```

### Server Configuration Fixes

#### Cloudflare
1. **Create Page Rule** for `/wp-json/*`:
   - Cache Level: Bypass
   - Disable Rocket Loader
   - Disable Email Obfuscation
   - Security Level: Medium or Low
   - Browser Integrity Check: Off

2. **Cache Settings**:
   - Go to Caching → Configuration
   - Add Cache Rule: `URI Path contains /wp-json/` → Bypass cache

3. **Transform Rules** (ensure query strings preserved):
   - Go to Rules → Transform Rules
   - Verify no rules strip query parameters from `/wp-json/` paths

4. **WAF Rules**:
   - Go to Security → WAF
   - Add Exception: Skip all rules for `/wp-json/*` paths

#### Nginx
Add this configuration block to your site's Nginx config:

```nginx
# WordPress REST API - no caching, preserve query strings
location ~* ^/wp-json/ {
    # Prevent caching
    add_header Cache-Control "no-store, no-cache, must-revalidate, max-age=0" always;
    add_header Pragma "no-cache" always;
    add_header Expires "0" always;
    
    # Preserve query strings in rewrites
    try_files $uri $uri/ /index.php?$args;
    
    # Pass to PHP-FPM
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;  # Adjust PHP version as needed
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param QUERY_STRING $query_string;
}
```

#### Apache (.htaccess)
Ensure your `.htaccess` file preserves query strings:

```apache
<IfModule mod_rewrite.c>
RewriteEngine On

# REST API - prevent caching
<FilesMatch "^(wp-json)">
    <IfModule mod_headers.c>
        Header set Cache-Control "no-store, no-cache, must-revalidate, max-age=0"
        Header set Pragma "no-cache"
        Header set Expires "0"
    </IfModule>
</FilesMatch>

# Preserve query strings (QSA flag)
RewriteRule ^wp-json/(.*)$ /index.php?rest_route=/$1 [QSA,L]

# Standard WordPress rules
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
```

**Important**: The `[QSA]` flag (Query String Append) is crucial to preserve query parameters.

#### LiteSpeed
Add to your `.htaccess`:

```apache
# LiteSpeed - no cache for REST API
<IfModule LiteSpeed>
    # Disable cache for /wp-json/ paths
    RewriteCond %{REQUEST_URI} ^/wp-json/ [NC]
    RewriteRule .* - [E=Cache-Control:no-cache]
</IfModule>
```

### WordPress Plugin Configurations

#### WP Rocket
1. Go to Settings → WP Rocket → Advanced Rules
2. Add to "Never Cache URL(s)":
   ```
   /wp-json/(.*)
   ```
3. Add to "Never Cache Cookies":
   ```
   wordpress_logged_in_
   ```

#### W3 Total Cache
1. Go to Performance → Page Cache
2. Add to "Never cache the following pages":
   ```
   /wp-json/
   ```

#### WP Super Cache
1. Go to Settings → WP Super Cache → Advanced
2. Add to "Rejected URLs":
   ```
   /wp-json/
   ```

#### LiteSpeed Cache
1. Go to LiteSpeed Cache → Cache → Excludes
2. Add to "Do Not Cache URIs":
   ```
   /wp-json/
   ```

### Verification

After applying fixes, verify with:

```bash
# Test 1: Context parameter is processed
curl "https://yoursite.com/wp-json/wp/v2/posts?context=edit" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  | jq '.[] | keys' | grep -E "(content|excerpt)"

# Expected: Should show full content fields (content.raw, etc.)

# Test 2: No caching headers present
curl -I "https://yoursite.com/wp-json/wp/v2/posts/1?context=edit" \
  | grep -i "cache-control"

# Expected: Cache-Control: no-store, no-cache, must-revalidate, max-age=0

# Test 3: Query string preserved in redirects
curl -I -L "https://yoursite.com/wp-json/wp/v2/types?context=edit" \
  | grep -E "(Location|HTTP)"

# Expected: No redirects or redirects preserve ?context=edit
```

## Connectivity health checks

- Run `wp mcp-ai remote https://example.com/wp-json/mcp-ai/v1 --token=...` from any WordPress instance with WP-CLI access to confirm the remote REST namespace is reachable. The command exercises the `/assistants` directory, issues a `/chat` probe, surfaces the assistant count, reports the detected token scope, and records any REST error codes returned by the remote server so you can distinguish connectivity issues from permission problems at a glance.【F:includes/class-wp-mcp-ai-cli-command.php†L137-L280】【F:includes/class-wp-mcp-ai-remote-tester.php†L29-L331】
- Repeat the probe with `--guest-token`, `--nonce`, or `--assistant-id` when you need to mirror the authentication mode used by front-end chat surfaces or scoped assistant credentials. The CLI forwards these values to the appropriate headers and query parameters, letting you validate guest access and same-origin flows without writing throwaway scripts.【F:includes/class-wp-mcp-ai-cli-command.php†L147-L242】【F:includes/class-wp-mcp-ai-remote-tester.php†L194-L309】
- Adjust `--timeout`, `--verify-ssl`, or `--user-agent` when debugging slow networks, certificate mismatches, or upstream firewalls that inspect client signatures; the remote tester honours all three controls before issuing the GET request.【F:includes/class-wp-mcp-ai-cli-command.php†L156-L242】【F:includes/class-wp-mcp-ai-remote-tester.php†L36-L67】【F:includes/class-wp-mcp-ai-remote-tester.php†L207-L214】
- When you do not have WP-CLI access, call the `probe_remote_mcp` assistant tool from any administrator-managed chat. It wraps the same remote tester with arguments for bearer tokens, guest tokens, nonces, and assistant hints so you can validate production deployments in-place.【F:includes/tools/class-wp-mcp-ai-tool-probe-remote-mcp.php†L12-L164】
- Use the `probe_chat` tool on the live site to confirm the local REST controller can load a specific assistant, sanitise messages, and acknowledge probe requests without contacting the language model provider.【F:includes/tools/class-wp-mcp-ai-tool-probe-chat.php†L12-L178】【F:includes/class-wp-mcp-ai-rest.php†L1710-L1752】

## Authentication failures

1. **Missing credentials (401)** – The REST layer now returns the
   `wp_mcp_ai_missing_credentials` error whenever the `Authorization` header or
   `X-WP-Nonce` header is absent. Instruct remote assistants to either attach an
   Auth0 bearer token or a valid WordPress nonce generated with
   `wp_create_nonce( 'wp_rest' )`.
2. **Invalid nonce (401)** – The REST API validates the nonce before checking
   capabilities. Refresh the WordPress session to obtain a fresh nonce and
   resubmit the call.
3. **Insufficient capability (403)** – WordPress users must retain the
   `edit_posts` capability to interact with MCP endpoints. If a request comes
   from a lower-privileged role (Subscriber, Customer, etc.), upgrade the role or
   create a dedicated integration account with the required permissions.
4. **Invalid or revoked assistant credentials (401)** – The REST layer returns
   `wp_mcp_ai_invalid_token` when the credential secret no longer matches the
   stored hash and `wp_mcp_ai_revoked_token` as soon as an administrator revokes
   it from the *API Credentials* meta box. Generate a new credential and update
   the integration whenever either error appears.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L483-L595】【F:includes/class-wp-mcp-ai-credentials.php†L94-L297】
5. **Assistant scope mismatch (403)** – A credential can only access the
   assistant that issued it. If you see `wp_mcp_ai_assistant_scope_mismatch`,
   remove any overriding `assistant_id` parameter or mint a dedicated credential
   for the requested assistant.【F:includes/class-wp-mcp-ai-rest.php†L316-L444】【F:includes/class-wp-mcp-ai-rest.php†L1282-L1321】
6. **JWT validation** – When relying on Auth0, ensure the tenant domain matches
   the MCP configuration and that the hosting environment exposes the PHP
   OpenSSL extension so the JWKS signature check can complete.

## JetEngine CRUD proxy issues

1. **Routes unavailable** – If JetEngine is disabled the MCP layer returns
   `wp_mcp_ai_jetengine_missing`. Activate the JetEngine plugin before exposing
   CRUD tools to remote assistants.
2. **Missing `instance` parameter** – All operations except `search_posts`
   require the JetEngine instance key. Ensure assistants include
   `params.instance` in their payloads.
3. **Item identifiers** – `get_item`, `edit_item`, and `delete_item` require the
   `id` argument. Sanitization trims whitespace, so pass the raw identifier rather
   than relying on padding.
4. **Permission mismatches** – JetEngine continues to enforce its native
   capability checks. If the MCP request originates from a user lacking
   `manage_options`, the JetEngine controller will reject the call. Align the
   assistant’s `user_id` context with an administrator or provide custom
   permissions through JetEngine filters.
5. **Transport fallbacks** – The proxy first attempts an internal REST dispatch.
   If a route is missing it falls back to an HTTP request. Review the WordPress
   debug log for `wp_mcp_ai_jetengine_route_unavailable` notices when diagnosing
   connectivity problems.
6. **JetEngine API compatibility (v3.3+)** – If you encounter the error
   `Call to undefined method Jet_Engine\...\Item_Handler::query_items()`,
   ensure you're using WP oOS version 1.0.0+ which includes the compatibility
   layer for JetEngine 3.3+. The plugin automatically detects and uses the
   correct API. See [jetengine-api-compatibility.md](jetengine-api-compatibility.md)
   for details.

## Security recommendations

- **Enforce least privilege** – Limit MCP access to roles with the minimal
  `edit_posts` capability required by the REST layer. For assistants that only
  need JetEngine CRUD access, consider creating a dedicated role with scoped
  capabilities.
- **Rotate credentials** – Reissue assistant tokens on a regular cadence and
  revoke unused credentials to reduce exposure from leaked secrets.
- **Mandate TLS** – Serve all MCP endpoints over HTTPS. JetEngine proxy requests
  inherit the site URL, so downgrading to HTTP exposes credential tokens and
  nonce values.
- **Audit logs** – Enable the plugin’s logging features and forward the
  resulting entries (`wp_mcp_ai_authenticated_with_credential`,
  `wp_mcp_ai_jetengine_*`) into your central logging pipeline for continuous
  monitoring.
- **Protect uploads** – Assistants can attach memory files to chat sessions.
  Ensure the WordPress uploads directory inherits hardened permissions and
  disable public indexing to prevent disclosure of sensitive documents.

Keep this document alongside the existing authentication reference so new team
members can quickly diagnose environment regressions.
