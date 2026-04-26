# Password Vault Manager

> WordPress-native password vault with **AES-256-GCM** encryption, folder organization,
> Bitwarden import/export, and conflict resolution.

| | |
|---|---|
| **Activation** | Auto-loaded with the Pro add-on |
| **Admin location** | NV oOS → Password Vault |
| **Custom Post Types** | Vault Item, Vault Folder |
| **Encryption** | AES-256-GCM (per-item key encryption) |

---

## What it provides

| Component | Class |
|---|---|
| Vault item CPT | `WP_MCP_AI_Vault_Item_CPT` |
| Vault folder CPT | `WP_MCP_AI_Vault_Folder_CPT` |
| Encryption service | `WP_MCP_AI_Vault_Encryption_Service` |
| Bitwarden import / export | `WP_MCP_AI_Bitwarden_Import_Export` |
| Bitwarden sync | `WP_MCP_AI_Bitwarden_Sync_Service` |
| Conflict resolver | `WP_MCP_AI_Vault_Conflict_Resolver` |

### AI tools

- `vault_access` — read a secret by id or name (capability-gated)
- `vault_manage` — create / update / delete a secret

These tools let an assistant look up credentials on demand without exposing them in
prompts or logs.

---

## Why a built-in vault

- Toolkits routinely need credentials (Stripe, OpenAI, social-media OAuth tokens,
  authority e-portal logins, etc.).
- Storing them in plain `wp_options` is a security regression versus a vault.
- Storing them outside WordPress means one more system to manage.
- The vault keeps secrets inside the WordPress database, encrypted at rest, with audit
  trails and per-capability access.

---

## Activation

The vault is loaded automatically with the Pro add-on. Visit **NV oOS → Password Vault**
to begin adding items, or import an existing Bitwarden export from
**Vault → Import / Export**.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/docs/PASSWORD_VAULT_README.md`](../PASSWORD_VAULT_README.md) — full guide
