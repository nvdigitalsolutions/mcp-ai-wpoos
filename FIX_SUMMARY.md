# Fix Summary: Auth0 Configuration & REST Assistant Creation

## Issues Fixed

### 1. Auth0 Configuration Being Removed When Saving REST API Settings

**Problem:** When saving settings in the Authentication tab, checkbox values from inactive subtabs were being set to `false`, causing data loss:
- Saving "REST API Capabilities" subtab → Auth0 checkboxes (`enable_auth0_github_bridge`) were set to false
- Saving "Auth0 Configuration" subtab → REST API checkboxes (`rest_enable_assistant_create`, `rest_enable_assistant_delete`) were set to false

**Root Cause:** The `sanitize()` method in `class-wp-mcp-ai-section-authentication.php` was processing checkboxes from the active subtab and setting them to `false` if not present in the input, regardless of whether that subtab was actually being saved.

**Fix:** Modified the `sanitize()` method to check `$_POST['subtab']` to determine if the current subtab is actually the one being saved. Checkboxes are now only processed (and set to false if unchecked) when `$submitted_subtab === $active_subtab`. This prevents cross-subtab checkbox pollution.

**Files Changed:**
- `includes/admin/sections/class-wp-mcp-ai-section-authentication.php`

### 2. POST /wp-json/mcp-ai/v1/assistants Endpoint Not Working

**Problem:** The REST route was registered for POST requests, but it was calling the wrong handler (`handle_assistants_index` which is for GET requests), so creating assistants via REST API didn't work.

**Solution:** Implemented complete POST /assistants functionality:

**Features Added:**
- New `handle_assistant_create()` method in `WP_MCP_AI_REST` class
- Creates WordPress post of type `mcp_ai_assistant`
- Validates and saves all assistant metadata:
  - `title` (required) - Assistant name
  - `description` (optional) - Assistant description
  - `provider` (optional) - AI provider (openai, gemini, ollama)
  - `model` (optional) - Model identifier (e.g., gpt-4, gemini-pro)
  - `temperature` (optional) - Temperature setting (0.0 to 2.0)
  - `system_prompt` (optional) - System prompt for the assistant
  - `tools` (optional) - Array of tool slugs to enable
  - `status` (optional) - Post status (publish, draft, private)
- Returns 201 Created with Location header
- Fires `wp_mcp_ai_rest_assistant_created` action hook
- Protected by `rest_enable_assistant_create` setting (must be enabled in Settings → Authentication → REST API Capabilities)
- Requires authentication (WordPress nonce, Auth0 bearer token, or assistant credentials)

**Files Changed:**
- `includes/class-wp-mcp-ai-rest.php` - Added `handle_assistant_create()` method
- `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php` - Updated route with proper args and delegation

## How to Test

### Testing Auth0 Configuration Fix

1. Go to Settings → WP oOS → Authentication
2. Navigate to "Auth0 Configuration" subtab
3. Enter Auth0 domain and audience, save
4. Navigate to "REST API Capabilities" subtab
5. Check "Enable REST Assistant Creation", save
6. Navigate back to "Auth0 Configuration" subtab
7. **Expected:** Auth0 domain and audience are still saved (previously would be lost)
8. Navigate back to "REST API Capabilities" subtab
9. **Expected:** "Enable REST Assistant Creation" is still checked (previously would be unchecked)

### Testing POST /assistants Endpoint

**Prerequisites:**
- Enable "REST Assistant Creation" in Settings → WP oOS → Authentication → REST API Capabilities

**Test 1: Create with Minimal Data**
```bash
curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/assistants" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"title":"Test Assistant"}'
```

**Expected Response:** 201 Created
```json
{
  "id": 123,
  "title": "Test Assistant",
  "slug": "test-assistant",
  "status": "publish",
  "provider": "",
  "model": "",
  "temperature": null,
  "tools": [],
  "tool_count": 0,
  ...
}
```

**Test 2: Create with Full Data**
```bash
curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/assistants" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "title": "Content Writing Assistant",
    "description": "An AI assistant for creating blog posts",
    "provider": "openai",
    "model": "gpt-4",
    "temperature": 0.7,
    "system_prompt": "You are a helpful WordPress content writing assistant.",
    "tools": ["search_content", "save_post", "web_search"],
    "status": "publish"
  }'
```

**Test 3: Verify Setting Protection**
- Disable "REST Assistant Creation" in settings
- Attempt to create assistant via API
- **Expected:** 403 Forbidden with error code `rest_assistant_create_disabled`

## Automated Tests

Comprehensive PHPUnit test suite added in `tests/test-rest-assistant-create.php`:
- `test_create_blocked_when_setting_disabled` - Verifies setting enforcement
- `test_create_succeeds_when_setting_enabled` - Tests successful creation
- `test_create_with_minimal_data` - Tests minimal required data
- `test_create_with_tools` - Tests tool assignment
- `test_create_with_system_prompt` - Tests system prompt
- `test_create_with_draft_status` - Tests custom post status
- `test_create_returns_400_for_missing_title` - Tests validation
- `test_create_requires_authentication` - Tests auth requirement
- `test_create_fires_action_hook` - Tests action hook
- `test_create_sets_location_header` - Tests Location header

Run tests:
```bash
composer test -- --filter test_rest_assistant
```

## Security Considerations

1. **Setting Protection:** The POST endpoint respects the `rest_enable_assistant_create` setting and returns 403 if disabled
2. **Authentication Required:** All requests must be authenticated (WordPress nonce, Auth0 token, or credentials)
3. **Input Sanitization:** All input is properly sanitized using WordPress functions
4. **Capability Check:** Uses existing permission checking infrastructure
5. **No Data Loss:** The checkbox fix prevents accidental data loss when saving unrelated settings

## Backward Compatibility

- ✅ No breaking changes to existing endpoints
- ✅ GET /assistants continues to work as before
- ✅ Settings structure unchanged
- ✅ Existing functionality preserved

## API Documentation

### POST /wp-json/mcp-ai/v1/assistants

Creates a new assistant.

**Authentication:** Required (WordPress nonce, Auth0 bearer token, or assistant credentials)

**Request Body:**
```json
{
  "title": "string (required)",
  "description": "string (optional)",
  "provider": "openai|gemini|ollama (optional)",
  "model": "string (optional)",
  "temperature": 0.0-2.0 (optional),
  "system_prompt": "string (optional)",
  "tools": ["array of tool slugs (optional)"],
  "status": "publish|draft|private (optional, default: publish)"
}
```

**Response:** 201 Created
```json
{
  "id": 123,
  "title": "Assistant Name",
  "slug": "assistant-name",
  "status": "publish",
  "provider": "openai",
  "model": "gpt-4",
  "temperature": 0.7,
  "tools": ["tool1", "tool2"],
  "tool_count": 2,
  "description": "...",
  "updated_at": "2025-01-16T14:30:00Z",
  "permalink": "https://..."
}
```

**Headers:**
- `Location: /wp-json/mcp-ai/v1/assistants/123`

**Errors:**
- `400` - Missing required field (title)
- `401` - Authentication required
- `403` - Setting disabled or insufficient permissions
- `500` - Server error creating assistant
