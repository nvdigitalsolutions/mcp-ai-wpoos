# Quick Test Guide - Cloudflare System Prompt Issue

## Problem
Assistant #331 (Cloudflare + Qwen model) gives generic responses instead of following YAAD-RELIEF persona.

## What We Need
Logs to confirm whether the `system` field is in the HTTP request to Cloudflare API.

## Quick Test (3 Steps)

### Step 1: Enable Logging
WordPress Admin → Settings → NV oOS → ☑ **Enable Logging** → Save Changes

### Step 2: Send Test Message
1. Go to chat interface with assistant #331
2. Send: "what are some things you can do"
3. Wait for response (even if generic)

### Step 3: Get Logs
```bash
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event == "cloudflare_request")'
```

## What to Look For

In the `cloudflare_request` event, check these fields:

```json
{
  "event": "cloudflare_request",
  "data": {
    "has_system_field": ???,          ← Should be true
    "system_field_length": ???,       ← Should be ~5000
    "system_field_preview": "???"     ← Should show "# System Instructions"
  }
}
```

## Share Results

Please share the entire `cloudflare_request` event JSON, especially:
- `has_system_field`
- `system_field_length`  
- `system_field_preview`

This will tell us if the system prompt makes it to the API or not.

## Expected Fix Time

Once we have logs: **< 30 minutes** to implement appropriate fix.

## If Logs Show System Field Present

The issue is with Cloudflare/model, not our code. Quick fixes:
1. Try different model: `@cf/meta/llama-3.2-3b-instruct`
2. Or: `@cf/qwen/qwen2.5-14b-instruct`

## If Logs Show System Field Missing/Empty

We'll fix the code to ensure system prompt reaches the API properly.
