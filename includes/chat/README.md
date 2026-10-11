# Chat

## Purpose

Houses the Chat Profile subsystem (proposal 015): the server-side profile
catalogue, the trust boundary that resolves which profile governs a request,
and the per-user persistence used by every chat surface (Pro SPA, legacy
shortcode chat, REST clients, guests).

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (eagerly, before `rest_api_init`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Chat_Profile_Registry` | `class-wp-mcp-ai-chat-profile-registry.php` | Gate, manager, REST controller, SPA runtime localization |
| `WP_MCP_AI_Chat_Profile_Manager` | `class-wp-mcp-ai-chat-profile-manager.php` | REST handlers, REST controller, gate, async executor, WP-CLI, user profile screen |

### `WP_MCP_AI_Chat_Profile_Registry`

Static catalogue of profiles. Built-ins: `write` (full access, non-restrictive,
fail-safe floor) and `read-only` (gates write/state-changing/destructive
flags). Selection eligibility (downgrade/upgrade policy) lives here.

```php
$profile = WP_MCP_AI_Chat_Profile_Registry::get_profile( 'read-only' );
$profile->blocks_tool( 'update_post', array( 'write' ) ); // true

$selectable = WP_MCP_AI_Chat_Profile_Registry::list_selectable_for_user( $user_id );
```

**Filter:** `wp_mcp_ai_chat_profiles` — receives the definition array; Pro
addons register additional profiles here.

### `WP_MCP_AI_Chat_Profile_Manager`

Trust boundary. Resolves the governing profile server-side
(per-user meta `wp_mcp_ai_chat_profile` → site default → `write`; guests →
`guest_chat_profile`, read-only by default) and never trusts the client: a
client-sent profile is honoured only as a per-request override by capability
holders and is otherwise dropped and audit-logged.

```php
$slug    = WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $user_id, $requested );
$profile = WP_MCP_AI_Chat_Profile_Manager::resolve( $user_id, $requested );

$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $user_id, 'read-only' );
// true | WP_Error (invalid 400 / forbidden 403 / disabled 403)
```

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`chat_profile_enabled`,
  `default_chat_profile`, `guest_chat_profile`), user meta
  `wp_mcp_ai_chat_profile`.
- **Writes to:** user meta `wp_mcp_ai_chat_profile` (deleted when the site
  default is selected).
- **Upstream callers:** `WP_MCP_AI_Read_Only_Profile_Gate`,
  `WP_MCP_AI_REST_Chat_Profile_Controller`, the REST tool-execution handlers,
  the async executor, WP-CLI, the user-profile screen.
- **Enforcement:** `includes/security/class-wp-mcp-ai-read-only-profile-gate.php`
  (throws `WP_MCP_AI_Chat_Profile_Blocked`; deny → ask → allow precedence).
- **Capability:** `wp_mcp_ai_change_chat_profile` mapped to `manage_options`
  via `map_meta_cap` in `WP_MCP_AI_Chat_Profile_Manager::register()`.

## Conventions

- Classes in this folder never execute tools — they only describe and
  resolve permissions.
- Profiles are resolved once per request (static cache); invalidate with
  `reset_cache()` after writes.
- The `write` profile must always exist: it is the non-restrictive fail-safe.

## Tests

```bash
vendor/bin/phpunit tests/test-chat-profile.php
vendor/bin/phpunit tests/security/test-chat-profile-gate.php
vendor/bin/phpunit tests/rest/test-chat-profile-controller.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md)
- [`.context/security-checklist.md`](../../.context/security-checklist.md)
- [`docs/project/proposals/015-chat-profile-read-only-mode-implementation-plan.md`](../../docs/project/proposals/015-chat-profile-read-only-mode-implementation-plan.md)
