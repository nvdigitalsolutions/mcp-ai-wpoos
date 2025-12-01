# Cron Status Endpoint Fix

**Issue**: PR #1215 broke the cron status service on the chat widget/client  
**Error**: `GET https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/cron-status?limit=10 net::ERR_NAME_NOT_RESOLVED`  
**Fixed**: November 15, 2025

## Problem Summary

The recent PR #1215 inadvertently introduced code that builds the cron-status endpoint URL from `config.messagesEndpoint`, which can point to external URLs, causing `ERR_NAME_NOT_RESOLVED` errors.

## Solution

Use `window.wpMcpAiChat.restUrl` (always local) instead of `config.messagesEndpoint` (can be external) to build the cron status endpoint URL.

## Changes

**File**: `assets/js/chat.js` (lines 9015-9024)

**Before**:
```javascript
const restUrl = config.messagesEndpoint || '';
const cronStatusEndpoint = restUrl.replace(/\/chat(-client)?$/, '/cron-status');
```

**After**:
```javascript
const restUrl = (window.wpMcpAiChat && window.wpMcpAiChat.restUrl) || '';
const cronStatusEndpoint = restUrl ? restUrl + '/cron-status' : '';
if (!cronStatusEndpoint) return; // Safety check
```

## Test Results

✅ All 5 scenarios tested successfully:
1. Normal WordPress setup
2. Cross-domain configuration (the bug - now fixed)
3. Private IP WordPress (192.168.2.222)
4. Localhost setup
5. Missing global config (graceful degradation)

## Security

✅ CodeQL: 0 alerts found  
✅ No vulnerabilities introduced  
✅ Maintains separation of concerns

## Impact

- **Affects**: Chat widget cron status polling
- **Severity**: Medium (breaks functionality for cross-domain setups)
- **Fix Type**: Client-side JavaScript URL construction

## Network Support

✅ Private IPs (192.168.x.x)  
✅ Loopback addresses (127.0.0.1, localhost)  
✅ Public domains  
✅ Cross-domain configurations  
✅ Local LLM setups (Ollama, LM Studio)

---

**Commits**:
- b240fd0: Fix cron status endpoint to use global restUrl
- 36c2ede: Add safety check to prevent polling if endpoint unavailable
