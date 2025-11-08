# UI Visual Mockup - Auth0 Setup Page with Checkbox

## Before This PR

```
┌─────────────────────────────────────────────────────────────────┐
│ Auth0 GitHub Bridge - 1-Click Setup                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Current Configuration                                       │ │
│ │                                                             │ │
│ │ Auth0 Domain:     example.auth0.com                         │ │
│ │ Audience:         https://api.example.com                   │ │
│ │ Bridge Status:    [Disabled]  ← Read-only badge            │ │
│ │                                                             │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Step 1: Auto-Configure from Auth0 Token                    │ │
│ │                                                             │ │
│ │ [Token textarea]                                            │ │
│ │                                                             │ │
│ │ [ Auto-Configure ]                                          │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

## After This PR

```
┌─────────────────────────────────────────────────────────────────┐
│ Auth0 GitHub Bridge - 1-Click Setup                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Current Configuration                                       │ │
│ │                                                             │ │
│ │ Auth0 Domain:                example.auth0.com              │ │
│ │                                                             │ │
│ │ Audience:                    https://api.example.com        │ │
│ │                                                             │ │
│ │ Enable Auth0 GitHub Bridge:                                 │ │
│ │   ☑ Resolve Auth0 GitHub identities into WordPress users   │ │
│ │     ↑                                                       │ │
│ │     Interactive checkbox - saves on click!                  │ │
│ │                                                             │ │
│ │   Maps Auth0 GitHub identities to WordPress users for REST  │ │
│ │   auditing and assistant scoping.                           │ │
│ │                                                             │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Step 1: Auto-Configure from Auth0 Token                    │ │
│ │                                                             │ │
│ │ [Token textarea]                                            │ │
│ │                                                             │ │
│ │ [ Auto-Configure ]                                          │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

## Interaction Flow

### When User Clicks Checkbox

```
User clicks checkbox
        ↓
JavaScript intercepts change event
        ↓
AJAX request sent with nonce + enabled state
        ↓
Server validates (capabilities + nonce)
        ↓
Setting saved to wp_mcp_ai_settings option
        ↓
Success response sent back
        ↓
Green notice appears at top of page:
┌─────────────────────────────────────────────┐
│ ✓ Auth0 GitHub bridge enabled successfully! │
└─────────────────────────────────────────────┘
        ↓
Notice auto-dismisses after 3 seconds
```

### On Error

```
AJAX request fails or server error
        ↓
Checkbox automatically reverts to previous state
        ↓
Alert shown to user:
┌─────────────────────────────────────────────┐
│ ⚠ Failed to update setting. Please try again│
└─────────────────────────────────────────────┘
```

## Key Improvements

1. **✅ One-Click Toggle**: No need to navigate to Settings page
2. **✅ Live Save**: No page refresh required
3. **✅ Immediate Feedback**: Success/error messages
4. **✅ User-Friendly**: Clear labels and descriptions
5. **✅ Secure**: Capability checks and nonce verification
6. **✅ Robust**: Error handling with state revert

## Location in WordPress Admin

```
WordPress Admin Dashboard
  └─ WP oOS (menu)
      └─ Auth0 Setup (submenu item)
          └─ Current Configuration (card/section)
              └─ Enable Auth0 GitHub Bridge (checkbox)
```

## Technical Details

- **HTML Element ID**: `enable-auth0-github-bridge`
- **Form Field Name**: `enable_auth0_github_bridge`
- **Settings Key**: `wp_mcp_ai_settings['enable_auth0_github_bridge']`
- **AJAX Action**: `wp_mcp_ai_toggle_auth0_bridge`
- **Required Capability**: `manage_options`
- **Nonce**: `wp-mcp-ai-auth0-setup`
