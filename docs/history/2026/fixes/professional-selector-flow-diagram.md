# Professional Selector Model Loading - Flow Diagram

## Before Fix (Broken for Logged-in Users)

```
┌─────────────────────────────────────────────────────────────────┐
│ User Action: Select AI Provider in Professional Selector Widget │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ JavaScript (professional-selector.js)                           │
│ Sends AJAX request:                                             │
│   - action: 'wp_mcp_ai_get_models_for_provider'                 │
│   - nonce: wp_create_nonce('wp-mcp-ai-professional-selector')   │
│   - provider: 'openai' (or other)                               │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
         ┌───────────────┴────────────────┐
         │                                │
         │ Logged-in User?                │
         │                                │
    ┌────┴────┐                      ┌───┴────┐
    │   YES   │                      │   NO   │
    └────┬────┘                      └───┬────┘
         │                               │
         ▼                               ▼
┌────────────────────────┐    ┌─────────────────────────┐
│ WordPress routes to:   │    │ WordPress routes to:    │
│ wp_ajax_* hook         │    │ wp_ajax_nopriv_* hook   │
└────────┬───────────────┘    └────────┬────────────────┘
         │                              │
         ▼                              ▼
┌──────────────────────────────┐  ┌──────────────────────────────┐
│ ❌ MISSING!                  │  │ ✅ Registered Handler        │
│ No wp_ajax hook registered   │  │ Professional Selector        │
│                              │  │ Shortcode::handle_get_       │
│ Falls through to admin       │  │ models_for_provider()        │
│ handler instead              │  │                              │
└────────┬─────────────────────┘  └────────┬─────────────────────┘
         │                                 │
         ▼                                 ▼
┌──────────────────────────────┐  ┌──────────────────────────────┐
│ Admin AJAX Handler           │  │ ✅ Success                   │
│ check_ajax_referer(          │  │ Returns model list           │
│   'wp-mcp-ai-model-selector' │  │                              │
│ )                            │  │                              │
│                              │  │                              │
│ ❌ NONCE MISMATCH!           │  └──────────────────────────────┘
│ Expected: model-selector     │
│ Received: professional-sel.  │
└────────┬─────────────────────┘
         │
         ▼
┌──────────────────────────────┐
│ ❌ 403 Forbidden             │
│ "Failed to load             │
│ configuration. Please       │
│ try again."                 │
└──────────────────────────────┘
```

## After Fix (Works for All Users)

```
┌─────────────────────────────────────────────────────────────────┐
│ User Action: Select AI Provider in Professional Selector Widget │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ JavaScript (professional-selector.js)                           │
│ Sends AJAX request:                                             │
│   - action: 'wp_mcp_ai_get_models_for_provider'                 │
│   - nonce: wp_create_nonce('wp-mcp-ai-professional-selector')   │
│   - provider: 'openai' (or other)                               │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
         ┌───────────────┴────────────────┐
         │                                │
         │ Logged-in User?                │
         │                                │
    ┌────┴────┐                      ┌───┴────┐
    │   YES   │                      │   NO   │
    └────┬────┘                      └───┬────┘
         │                               │
         ▼                               ▼
┌────────────────────────┐    ┌─────────────────────────┐
│ WordPress routes to:   │    │ WordPress routes to:    │
│ wp_ajax_* hook         │    │ wp_ajax_nopriv_* hook   │
└────────┬───────────────┘    └────────┬────────────────┘
         │                              │
         ▼                              ▼
┌──────────────────────────────┐  ┌──────────────────────────────┐
│ ✅ NEW! Registered Handler   │  │ ✅ Registered Handler        │
│ Professional Selector        │  │ Professional Selector        │
│ Shortcode::handle_get_       │  │ Shortcode::handle_get_       │
│ models_for_provider()        │  │ models_for_provider()        │
│                              │  │                              │
│ ✅ NONCE MATCH!              │  │ ✅ NONCE MATCH!              │
│ check_ajax_referer(          │  │ check_ajax_referer(          │
│   'wp-mcp-ai-professional-'  │  │   'wp-mcp-ai-professional-'  │
│   'selector'                 │  │   'selector'                 │
│ )                            │  │ )                            │
└────────┬─────────────────────┘  └────────┬─────────────────────┘
         │                                 │
         └──────────────┬──────────────────┘
                        │
                        ▼
           ┌────────────────────────┐
           │ Model Service          │
           │ get_models_for_        │
           │ provider()             │
           └────────┬───────────────┘
                    │
                    ▼
           ┌────────────────────────┐
           │ ✅ Success             │
           │ wp_send_json_success(  │
           │   models: [...]        │
           │ )                      │
           └────────┬───────────────┘
                    │
                    ▼
           ┌────────────────────────┐
           │ Model dropdown         │
           │ populated with         │
           │ available models       │
           └────────────────────────┘
```

## Key Changes

### Code Change
**File**: `includes/class-wp-mcp-ai-professional-selector-shortcode.php`

**Line 46 (Before)**:
```php
// Add nopriv hook for model selector (frontend access).
add_action( 'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider', array( $this, 'handle_get_models_for_provider' ) );
```

**Lines 45-47 (After)**:
```php
// Add hooks for model selector (both logged-in and frontend access).
add_action( 'wp_ajax_wp_mcp_ai_get_models_for_provider', array( $this, 'handle_get_models_for_provider' ) );
add_action( 'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider', array( $this, 'handle_get_models_for_provider' ) );
```

### Impact
- **Before**: Only guest users (not logged in) could load models in the professional selector
- **After**: Both logged-in and guest users can load models successfully
- **Benefit**: All users can use the professional selector widget in Elementor or shortcode form

## Testing Verification

### Manual Test Steps
1. ✅ Add professional selector shortcode to a page: `[mcp_ai_professional_selector]`
2. ✅ As a logged-in user (any role), select a professional
3. ✅ Select an AI provider (e.g., "OpenAI")
4. ✅ Verify model dropdown populates without errors
5. ✅ Check browser console - no 403 errors
6. ✅ Log out and test as guest (if `allow_guests="true"`)

### Automated Tests
```bash
vendor/bin/phpunit tests/test-professional-selector-model-loading.php
```

**Test Coverage**:
- ✅ Hook registration for logged-in users
- ✅ Hook registration for guest users  
- ✅ Handler mapping verification
- ✅ Nonce validation
- ✅ Parameter validation
- ✅ Permission checks
- ✅ Admin handler non-interference
