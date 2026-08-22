---
type: Skill
name: design-vault
description: Manage encrypted vault storage for sensitive information — passwords, secure notes, payment cards, digital identities, and folder organization. Use when storing, retrieving, or organizing credentials, managing secrets, or working with encrypted data.
license: Proprietary. See LICENSE.txt
metadata:
  type: Skill
---

# NV oOS Pro Toolkit Vault

The Pro Toolkit Vault provides encrypted storage for sensitive data built on
WordPress Custom Post Types. It manages: **folder organization → item storage →
encrypted persistence**. All entities are stored as CPT records accessible via the
`toolkit_cpt` MCP tool. Data at rest is encrypted; the `toolkit_cpt` interface
handles encryption/decryption transparently.

## WP-CLI Commands

Key `mcp-ai` WP-CLI commands for vault operations. Run via `terminal`:

| Command | Use for |
|---|---|
| `wp mcp-ai cron` | Check vault maintenance cron (key rotation reminders, stale item alerts) |
| `wp mcp-ai queue` | Inspect vault import/export background jobs |
| `wp mcp-ai health` | Verify vault encryption status, check for unencrypted legacy items |
| `wp mcp-ai log` | View vault access and modification logs |

## When to use this skill

Trigger when ANY of the following is true:

- Storing or retrieving passwords, API keys, or access tokens.
- Managing secure notes with sensitive information (server configs, recovery codes, private keys).
- Storing payment card information in PCI-compliant encrypted storage.
- Creating and managing digital identity records (credentials, account details).
- Organizing vault items into folders for logical grouping.
- Searching the vault for specific credentials by title, type, or folder.
- Auditing vault contents — checking for stale items, unencrypted legacy records.
- Migrating secrets from external password managers into the Pro Toolkit Vault.
- Encrypting any sensitive data that should never appear in plaintext in the database.

## Mental model — Vault entities and structure

The vault is organized around two entity types in a hierarchical structure:

```
VAULT FOLDER ──(contains)──→ VAULT ITEMS
     │                           │
     ├── title (folder name)     ├── title (item label)
     └── parent folder           ├── content (encrypted payload)
                                 └── author (owner)
```

**Relationships:**
- A **Vault Folder** (`mcp_vault_folder`) is a lightweight organizational container.
  Folders can be nested (parent/child) for hierarchical organization.
- A **Vault Item** (`mcp_vault_item`) holds the encrypted payload. Each item
  belongs to exactly one folder (via standard WordPress taxonomy or parent reference).
- The **`content`** field of a vault item contains the encrypted secret payload.
  When you read via `get_item`, the content is automatically decrypted. When you
  write via `create_item` or `update_item`, the content is automatically encrypted.
- The **`author`** field tracks who created the vault item for access control
  and audit trails.

**Vault items can store any of these common secret types:**

| Type | Typical Content | Example Title |
|---|---|---|
| Password | Username + password | `"AWS IAM Admin"` |
| Secure Note | Free-form sensitive text | `"Production DB Connection String"` |
| Payment Card | Card number, expiry, CVV | `"Company Visa - 4242"` |
| Digital Identity | Account credentials, 2FA seeds | `"GitHub Personal Access Token"` |
| API Key | Key + endpoint + permissions | `"Stripe Live Secret Key"` |

## Vault Entity Types

All vault entities are managed through the **`toolkit_cpt`** MCP tool. Use the
appropriate `post_type` slug for each entity:

| CPT Slug | Label | Purpose | Supported Fields |
|---|---|---|---|
| `mcp_vault_item` | Vault Items | Encrypted storage for passwords, notes, cards, identities | `title`, `content`, `author` |
| `mcp_vault_folder` | Vault Folders | Organizational folders for grouping vault items | `title`, `content` (optional description) |

### Vault Item Fields (detailed)

| Field | Type | Description | Example |
|---|---|---|---|
| `title` | string | Descriptive label for the secret | `"Production MySQL Admin"`, `"Stripe Live Secret Key"` |
| `content` | string | Encrypted secret payload (auto-encrypted/decrypted) | The actual credential data — encrypted at rest |
| `author` | integer | WordPress user ID who owns the item | `1` |
| `status` | string | Post status | `"publish"`, `"draft"` |

### Vault Folder Fields (detailed)

| Field | Type | Description | Example |
|---|---|---|---|
| `title` | string | Folder name | `"Production Credentials"`, `"Payment Cards"` |
| `content` | string | Optional folder description | `"Secrets for AWS, GCP, and Azure production environments"` |
| `status` | string | Post status | `"publish"`, `"draft"` |

## Available Tools

All vault operations use the **`toolkit_cpt`** MCP tool. This is the single
interface for all vault CRUD operations.

### Discovery

```
# List all available vault post types
toolkit_cpt(action: "list_types")

# Get field schema for vault items or folders
toolkit_cpt(action: "get_schema", post_type: "mcp_vault_item")
toolkit_cpt(action: "get_schema", post_type: "mcp_vault_folder")
```

### Reading records

```json
// List all vault items
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "list_items",
    "post_type": "mcp_vault_item",
    "orderby": "title",
    "order": "ASC",
    "per_page": 50,
    "page": 1
  }
}

// Search vault items by title
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "list_items",
    "post_type": "mcp_vault_item",
    "search": "Stripe"
  }
}

// List all vault folders
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "list_items",
    "post_type": "mcp_vault_folder",
    "orderby": "title",
    "order": "ASC",
    "per_page": 50
  }
}

// Get a single vault item (content is auto-decrypted)
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "get_item",
    "post_type": "mcp_vault_item",
    "item_id": 123
  }
}

// Get a single folder
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "get_item",
    "post_type": "mcp_vault_folder",
    "item_id": 10
  }
}
```

### Creating records

```json
// Create a vault folder
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "create_item",
    "post_type": "mcp_vault_folder",
    "fields": {
      "title": "Production Credentials",
      "content": "Secrets for AWS, GCP, and Azure production environments. Restricted to DevOps team.",
      "status": "publish"
    }
  }
}

// Store a password in the vault
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "create_item",
    "post_type": "mcp_vault_item",
    "fields": {
      "title": "AWS IAM Admin - Production",
      "content": "Access Key: AKIAIOSFODNN7EXAMPLE\nSecret Key: wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY\nRegion: us-east-1",
      "author": 1,
      "status": "publish"
    }
  }
}

// Store a payment card
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "create_item",
    "post_type": "mcp_vault_item",
    "fields": {
      "title": "Company Visa - Ending 4242",
      "content": "Card Number: 4242424242424242\nExpiry: 12/28\nCVV: 123\nName on Card: Acme Corp",
      "author": 1,
      "status": "publish"
    }
  }
}

// Store an API key as a secure note
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "create_item",
    "post_type": "mcp_vault_item",
    "fields": {
      "title": "Stripe Live Secret Key",
      "content": "Key: sk_demo_YOUR_KEY_HERE\nEnvironment: Production\nPermissions: Read/Write\nRotated: 2026-07-15",
      "author": 1,
      "status": "publish"
    }
  }
}
```

### Updating records

```json
// Update a vault item (rotate a credential)
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "update_item",
    "post_type": "mcp_vault_item",
    "item_id": 123,
    "fields": {
      "title": "AWS IAM Admin - Production (Rotated Aug 2026)",
      "content": "Access Key: AKIAIOSFODNN7UPDATED\nSecret Key: updatedSecretKeyValueHere12345\nRegion: us-east-1"
    }
  }
}

// Rename a folder
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "update_item",
    "post_type": "mcp_vault_folder",
    "item_id": 10,
    "fields": {
      "title": "Production & Staging Credentials"
    }
  }
}
```

### Deleting records

```json
// Delete a vault item (permanently removes the encrypted secret)
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "delete_item",
    "post_type": "mcp_vault_item",
    "item_id": 123
  }
}
```

**⚠️ Critical Warning:** Deleting a vault item permanently destroys the
encrypted secret with no recovery. Before deleting, verify:
- The credential has been rotated (old value is no longer valid anywhere).
- No automation or integration still references this vault item.
- A backup or migration record exists if the secret may be needed for audit.

## Common Workflows

### Initial vault setup

```
1. Plan folder structure:  Define your organizational hierarchy.
2. Create folders:         create_item(mcp_vault_folder) for each top-level category
                           (e.g., "Production", "Staging", "Payment Cards", "Identities").
3. Create sub-folders:     For more granular grouping (e.g., "Production/AWS", "Production/GCP").
4. Migrate secrets:        Import existing credentials into vault items under the correct folders.
5. Verify encryption:      wp mcp-ai health to confirm all items are encrypted at rest.
```

### Credential retrieval workflow

```
1. Search vault:       list_items(mcp_vault_item, search: "<service or key name>")
2. Identify item:      Review search results to find the matching vault item ID.
3. Retrieve secret:    get_item(mcp_vault_item, item_id: <id>) → content is auto-decrypted.
4. Use credential:     Apply the secret in the target system.
5. Do NOT log/cache:   Never write decrypted content to logs, transcripts, or unencrypted storage.
```

### Credential rotation

```
1. Retrieve current:   get_item(mcp_vault_item, item_id: <id>)
2. Generate new:       Create new credential in the target service (AWS, Stripe, etc.).
3. Update vault:       update_item(mcp_vault_item, item_id: <id>,
                         fields: { "title": "<Name> (Rotated <Month Year>)",
                                   "content": "<new encrypted payload>" })
4. Update consumers:   Update any system that references this credential.
5. Verify old revoked: Confirm the previous credential is no longer active.
```

### Vault audit

```
1. List all items:       list_items(mcp_vault_item, per_page: 100)
2. Review by author:     Find items owned by deactivated users or former team members.
3. Check staleness:      Flag items not updated in 90+ days for rotation review.
4. Empty folders:        list_items(mcp_vault_folder) → cross-reference with vault items
                         to find empty folders that can be archived.
5. Legacy items:         wp mcp-ai health to find any unencrypted legacy records
                         and re-save them to trigger encryption.
```

### Secrets migration (from external password manager)

```
1. Export from source:   Export secrets from the external manager (CSV, JSON).
2. Parse export:         Extract title, content, and folder path for each secret.
3. Create folders:       For each unique folder path: create_item(mcp_vault_folder).
4. Bulk import items:    Use bulk_create(mcp_vault_item, items: [...]) for batch import.
5. Verify:               Spot-check 3-5 items with get_item to confirm encryption + content.
6. Secure export file:   Delete the unencrypted export file after successful verification.
```

## Critical Rules

- **Content is auto-encrypted at rest** — when you create or update a vault item via `toolkit_cpt`, the
  `content` field is encrypted before storage. When you read, it's decrypted. You never
  handle plaintext storage directly.
- **Never log decrypted content** — after retrieving a vault item, do not include the
  decrypted `content` in chat responses, activity logs, error messages, or console output.
- **Always use `get_schema` first** before creating or updating a record to verify
  available field keys and types.
- **`author` should reflect the true owner** — set it to the WordPress user ID who is
  responsible for the credential, not the ID of the agent performing the operation.
- **Rotate credentials on a schedule** — vault items should have their content updated
  whenever the underlying credential changes. Set a calendar reminder for 90-day rotations.
- **Deleting is permanent and irreversible** — there is no trash/undo for encrypted data.
  Consider archiving via `status: "draft"` instead of deleting, to preserve an audit
  footprint (even if the encrypted content is no longer accessible).
- **Vault folders are organizational only** — they do not inherit encryption or access
  control. Security is at the item level.

## Common Mistakes

```
WRONG — logging decrypted vault content in a response
// After get_item, responding with: "Here's your key: sk_demo_YOUR_KEY_HERE..."
// This leaks the secret into chat transcripts and logs.

RIGHT — reference the item by title and ID only
// "Retrieved vault item #123 ('Stripe Live Secret Key'). Ready to use."

WRONG — deleting a vault item without confirming rotation
{ "action": "delete_item", "post_type": "mcp_vault_item", "item_id": 123 }
// If any system still uses this credential, it will break.

RIGHT — rotate before deleting, then verify
1. update_item(mcp_vault_item, item_id: 123, fields: { ... new rotated content ... })
2. Verify: get_item to confirm new content is stored correctly.
3. Then — and only then — delete if truly obsolete.

WRONG — storing secrets in the title field
{ "title": "sk_demo_YOUR_KEY_HERE" }
// The title is NOT encrypted. Secrets in the title are exposed in listing views.

RIGHT — use title for a descriptive label, content for the secret
{ "title": "Stripe Live Secret Key",
  "content": "Key: sk_demo_YOUR_KEY_HERE" }

WRONG — using vault items for non-sensitive data
// Storing public URLs, non-sensitive config values, or documentation.
// This clutters the vault and dilutes audit focus on actual secrets.

RIGHT — use vault only for truly sensitive data
// Passwords, API keys, tokens, private keys, payment cards, identity documents.
// Non-sensitive data belongs in regular posts, Paper Store, or configuration files.

WRONG — bulk_create without verifying encryption
// Mass importing secrets without spot-checking that encryption is working.
// A single misconfigured import could leave plaintext secrets in the database.

RIGHT — always verify after bulk import
1. bulk_create(mcp_vault_item, items: [...])
2. Pick 3 random items: get_item to confirm content decrypts correctly.
3. wp mcp-ai health to confirm no unencrypted legacy items were created.
```

## Cross-References

- Run `wp-security-secrets` to audit the plugin for hardcoded secrets that should be migrated into the vault.
- Run `wp-security-audit` on any code that retrieves vault items — ensure decrypted content is never exposed in responses or logs.
- Run `design-pro-workflow-builder` to automate credential rotation workflows and expiry alerts.
- Run `design-pro-schedule-manager` to schedule recurring vault audits and rotation reminders.
- Run `design-communications` if credentials need to be shared securely with team members (encrypted sharing, not plaintext messaging).

## What This Skill Does NOT Cover

- Encryption algorithm and key management details — the vault uses the WordPress
  cryptography layer transparently. For key rotation or algorithm changes, consult
  the plugin's security documentation.
- Access control and permission configuration — use WordPress capabilities to
  control who can read, create, update, or delete vault items.
- Sharing secrets between users — the vault is designed for individual or
  application-level secret storage. Secure sharing requires an additional sharing
  layer or integration.
- External secrets management (HashiCorp Vault, AWS Secrets Manager, Azure Key Vault) —
  use those services' native APIs for enterprise secrets orchestration.
- Password strength validation and generation — use dedicated password tooling or
  WordPress password strength APIs. The vault stores what you give it.
- Automatic credential injection into running applications — the vault is a
  storage layer. Application integration (CI/CD pipelines, environment variable
  injection) is a separate concern.
