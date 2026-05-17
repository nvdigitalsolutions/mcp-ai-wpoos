# Vault — Password Vault Manager (Pro)

## Purpose

Implements the Pro-only Password Vault subsystem — AES-256-GCM encrypted storage of credentials, TOTP secrets, and Bitwarden/Vaultwarden bidirectional sync, surfaced through CPTs, a REST controller, and three vault tools — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`addons/pro/includes/password-vault-init.php`](../password-vault-init.php) — required from `addons/pro/mcp-ai-wpoos-pro.php` inside `wp_mcp_ai_pro_init()`; initialises on `init` priority 20 |
| **Optional dependencies** | OpenSSL extension (required for AES-256-GCM — the encryption service refuses to initialise without it); a remote Bitwarden / Vaultwarden server is optional and only used when the sync service is configured |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Vault_Encryption_Service` | `class-wp-mcp-ai-vault-encryption-service.php` | every other class in this folder; the three vault tools |
| `WP_MCP_AI_Vault_Item_CPT` | `class-wp-mcp-ai-vault-item-cpt.php` | admin metaboxes, REST controller, vault tools, conflict resolver |
| `WP_MCP_AI_Vault_Folder_CPT` | `class-wp-mcp-ai-vault-folder-cpt.php` | admin UI, REST controller, vault tools |
| `WP_MCP_AI_Vault_REST_Controller` | `class-wp-mcp-ai-vault-rest-controller.php` | self-registers under namespace `mcp-ai/v1` on `rest_api_init` |
| `WP_MCP_AI_Bitwarden_Import_Export` | `class-wp-mcp-ai-bitwarden-import-export.php` | sync service, admin import/export action |
| `WP_MCP_AI_Bitwarden_Sync_Service` | `class-wp-mcp-ai-bitwarden-sync-service.php` | background sync, admin "Sync now" action |
| `WP_MCP_AI_Vault_Conflict_Resolver` | `class-wp-mcp-ai-vault-conflict-resolver.php` | sync service (Phase 4 conflict handling) |
| `WP_MCP_AI_Vault_Background_Sync` | `class-wp-mcp-ai-vault-background-sync.php` | self-registers WP-Cron handler on init |

Vault tools live in [`../tools/`](../tools/) and are registered through `wp_mcp_ai_pro_register_vault_tools()` (in `password-vault-init.php`): `WP_MCP_AI_Pro_Tool_Vault_Access` (read-only), `WP_MCP_AI_Pro_Tool_Vault_Manage` (CRUD), `WP_MCP_AI_Pro_Tool_Generate_Password`.

## Inputs / Outputs / Neighbors

- **Reads from:** ciphertext + IV + auth-tag stored in post meta on the `mcp_ai_vault_item` CPT; the per-user encryption key material derived via PBKDF2-SHA256 (100,000 iterations, OWASP minimum); optional remote Bitwarden / Vaultwarden REST API; the WP-Cron schedule for background sync.
- **Writes to:** the two vault CPTs (`mcp_ai_vault_item`, `mcp_ai_vault_folder`), encrypted post-meta payloads, sync state options, WP-Cron events.
- **Upstream callers:** the Pro admin "Password Vault" page ([`../admin/class-wp-mcp-ai-password-vault-admin.php`](../admin/class-wp-mcp-ai-password-vault-admin.php)), REST clients hitting `mcp-ai/v1/vault/*`, the three vault tools when invoked from chat / REST / CLI.
- **Downstream collaborators:** the WP REST API, `wp_remote_*` for Bitwarden sync, the WP-Cron scheduler.
- **Events fired:** standard CPT save/delete hooks; sync lifecycle events emitted by `Bitwarden_Sync_Service` for the admin progress UI.
- **Events listened to:** `init` priority 20 (subsystem init), `rest_api_init` (route registration), CPT save/delete hooks (re-encrypt on update), WP-Cron events scheduled by `Vault_Background_Sync`.

## Conventions

- **AES-256-GCM only.** All ciphertext is authenticated (auth-tag verified on decrypt). Never introduce CBC, ECB, or unauthenticated modes; never homebrew an MAC.
- **PBKDF2-SHA256 with ≥ 100,000 iterations** is the OWASP floor for key derivation here. Don't lower it; raising it requires a key-rotation migration in [`../migrations/`](../migrations/).
- **Per-user key isolation.** Encryption keys are derived per WordPress user — user A must not be able to decrypt user B's items even with DB access. New code paths must preserve this invariant; if you need a cross-user share, design it explicitly with re-encryption, not key sharing.
- **Unique random IV per encryption operation.** Never reuse IVs; never seed them deterministically. The encryption service is the only place that generates IVs.
- **TOTP follows RFC 6238** (Google Authenticator compatible). Don't fork the algorithm.
- **Never log or surface plaintext credentials.** Error messages, admin notices, REST responses, and metric labels are all subject to this rule — when in doubt, return the item id, not the content.
- **Sync conflicts go through `WP_MCP_AI_Vault_Conflict_Resolver`.** Last-write-wins is forbidden — conflicts are recorded for operator review, not silently merged.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-vault-cpt-registration.php
vendor/bin/phpunit addons/pro/tests/test-vault-metaboxes.php
vendor/bin/phpunit addons/pro/tests/test-password-vault-ajax.php
```

(The three vault tools — `vault_access`, `vault_manage`, `generate_password` — live in [`../tools/`](../tools/); their tests live alongside the other Pro tool tests in `addons/pro/tests/`.)

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — cryptographic storage, secret handling (always — this is the most security-sensitive folder in the Pro tree)
- [`.context/rest-api.md`](../../../../.context/rest-api.md) — REST permission / capability conventions
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — canonical envelope (vault tools are no exception)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro placement rationale (vault is Pro-only by policy)
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat (8.1+), two-gate sanitisation rule
- OWASP: <https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html>
- RFC 6238 (TOTP): <https://datatracker.ietf.org/doc/html/rfc6238>

## See Also

- Sibling: [`../admin/class-wp-mcp-ai-password-vault-admin.php`](../admin/) — admin surface for vault items + folders
- Sibling: [`../tools/`](../tools/) — `vault_access`, `vault_manage`, `generate_password` tools
- Sibling: [`../migrations/`](../migrations/) — required home for any key-derivation or cipher migrations
