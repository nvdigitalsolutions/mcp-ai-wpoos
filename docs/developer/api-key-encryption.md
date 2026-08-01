# API Key Encryption — Migration Guide

> **Since:** 1.2.0 · **Class:** `WP_MCP_AI_Api_Key_Store` · **Status:** Transparent (no action needed)

## Overview

NV oOS 1.2.0 introduces transparent encryption-at-rest for all third-party API keys stored in WordPress options. The `WP_MCP_AI_Api_Key_Store` wraps the existing `WP_MCP_AI_Encryption` class (AES-256-GCM with `v2:` prefix format) to encrypt keys on write and decrypt on read.

**You don't need to do anything.** On the first read of any managed key, the store detects plaintext storage and migrates it to encrypted format silently.

## Managed Keys

The following API keys are automatically encrypted:

| Option Suffix | Label |
|--------------|-------|
| `openai_api_key` | OpenAI API Key |
| `stability_api_key` | Stability AI API Key |
| `google_maps_api_key` | Google Maps API Key |
| `removebg_api_key` | remove.bg API Key |
| `yahoo_client_secret` | Yahoo OAuth Client Secret |
| `webhook_secret` | Webhook HMAC Secret |
| `pro_chat_continuation_secret` | Chat Continuation Webhook Secret |

## How It Works

### Reading Keys
```php
// Before 1.2.0 — plaintext in options
$key = get_option( 'wp_mcp_ai_openai_api_key', '' );

// After 1.2.0 — transparently decrypted
$key = wp_mcp_ai_get_api_key( 'openai_api_key', '' );
```

The helper function `wp_mcp_ai_get_api_key()`:
1. Reads the option value
2. If already encrypted (detected by `v2:` prefix + base64), decrypts and returns
3. If plaintext, encrypts it in-place, then returns the plaintext value
4. If empty, returns the default

### Writing Keys
```php
// Before 1.2.0
update_option( 'wp_mcp_ai_openai_api_key', $value );

// After 1.2.0 — automatically encrypted
wp_mcp_ai_set_api_key( 'openai_api_key', $value );
```

### Direct Option Access (Backward Compatible)

If you read `get_option( 'wp_mcp_ai_openai_api_key' )` directly, you'll get the encrypted blob. Use the helper instead. The admin settings UI saves through the repository which routes through the encrypted store automatically.

## Tool Developer Checklist

If you're writing a new tool that needs an API key:

```php
// ✅ DO — use the helper
$api_key = wp_mcp_ai_get_api_key( 'openai_api_key' );

// ❌ DON'T — returns encrypted blob after migration
$api_key = get_option( 'wp_mcp_ai_openai_api_key' );
```

## Master Key Rotation

The encryption master key can be rotated via `WP_MCP_AI_Encryption::rotate_master_key()`. This:
1. Decrypts all secrets with the old key
2. Re-encrypts with a new key
3. Atomically updates the master key option
4. Rolls back on any failure

**WP-CLI command:** (coming in 1.3.0)
```bash
wp mcp-ai rotate-encryption-key
```

## Fallback Behavior

If `WP_MCP_AI_Api_Key_Store` is not loaded (bootstrap context), `wp_mcp_ai_get_api_key()` falls back to `get_option()` for backward compatibility.

## Security Properties

- **Algorithm:** AES-256-GCM (authenticated encryption)
- **Key derivation:** Master key stored in `wp_mcp_ai_master_key` option, generated via `random_bytes(32)` + base64
- **Tamper detection:** GCM authentication tag prevents ciphertext manipulation
- **Format versioning:** `v2:` prefix allows future algorithm upgrades without breaking existing data
- **Legacy support:** Pre-existing AES-256-CBC encrypted values are still decryptable
