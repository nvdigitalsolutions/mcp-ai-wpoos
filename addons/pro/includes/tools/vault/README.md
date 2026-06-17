# Password Vault Toolkit

> Secure credential storage with AES-256-GCM encryption.

## Purpose

Tools for storing and retrieving encrypted credentials (API keys, passwords, tokens) for use by other toolkits and integrations.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Vault Access | `vault_access` | Retrieve a securely stored credential |
| Vault Manage | `vault_manage` | Create, update, or delete vault entries |

## Dependencies

- WordPress 6.0+
- OpenSSL (for AES-256-GCM encryption)

## Security

All vault entries are encrypted at rest using AES-256-GCM. The encryption key is derived from `wp_mcp_ai_vault_key` (auto-generated on first use). Credentials are never logged or exposed in tool responses — only the entry label and metadata are returned.

## Registration

Loaded by `password-vault-init.php` in `addons/pro/includes/`. Always enabled when Pro is active.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [Vault infrastructure: `addons/pro/includes/vault/`](../../vault/)
