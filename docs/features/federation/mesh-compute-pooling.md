# Mesh Compute Pooling with WP oOS

**Version:** 1.0.0  
**Last Updated:** November 6, 2025

## Overview

WP oOS supports **mesh networking** — a distributed architecture that allows multiple WordPress sites to pool their AI compute resources, share budget allocations, and coordinate workloads across a network of trusted peers. This feature works with **both anonymous and authenticated users**, as the pooling happens **server-to-server**, not per visitor.

This document explains how mesh compute pooling works, how it supports different user types, and how to configure it for your deployment.

---

## Table of Contents

- [Key Concept: Server-to-Server Pooling](#key-concept-server-to-server-pooling)
- [How It Works](#how-it-works)
- [Authentication Models](#authentication-models)
  - [Mesh Authentication (Server-to-Server)](#mesh-authentication-server-to-server)
  - [End-User Authentication](#end-user-authentication)
- [Use Cases](#use-cases)
- [Configuration](#configuration)
- [Security Considerations](#security-considerations)
- [FAQ](#faq)

---

## Key Concept: Server-to-Server Pooling

**Mesh requests are authenticated between sites, not by end-user identity.**

When a visitor (anonymous or authenticated) interacts with an assistant on Site A, and that assistant needs to query Site B for additional compute or data:

1. **Site A's backend assistant** (running under privileged context) makes the mesh request
2. The request uses **mesh authentication** (`X-WP-MCP-AI-Mesh-Key` header with a shared secret)
3. Site B validates the mesh key and processes the request
4. The result flows back to Site A's assistant, then to the end-user

**The end-user never directly calls the mesh**. They interact with a frontend assistant, which coordinates backend mesh operations on their behalf.

This architecture enables:
- ✅ **Anonymous visitors** can benefit from distributed compute without authentication
- ✅ **Authenticated users** can access pooled resources with proper attribution
- ✅ **Centralized governance** via capability-based tool access
- ✅ **Budget pooling** across 100+ sites with shared allocation strategies
- ✅ **Audit trails** for compliance and security monitoring

---

## How It Works

### Architecture Diagram

```
┌──────────────────────────────────────────────────────────────┐
│                    End-User Layer                            │
│                                                              │
│  Anonymous Visitor          Authenticated User               │
│  (guest token)              (WordPress login / bearer token) │
└─────────────────┬──────────────────┬────────────────────────┘
                  │                  │
                  ▼                  ▼
┌──────────────────────────────────────────────────────────────┐
│                    Site A (Frontend)                          │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐ │
│  │  Public Assistant (capability: public or edit_posts)   │ │
│  │  • Handles user chat interaction                       │ │
│  │  • Runs under privileged backend context               │ │
│  │  • Can invoke mesh tools (if user is admin)            │ │
│  └────────────────────────────────────────────────────────┘ │
│                          │                                   │
│                          │ Mesh Request                      │
│                          │ (X-WP-MCP-AI-Mesh-Key: secret)    │
│                          ▼                                   │
└──────────────────────────────────────────────────────────────┘
                           │
          ┌────────────────┼────────────────┐
          │                │                │
          ▼                ▼                ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│   Site B        │ │   Site C        │ │   Site D        │
│  (Peer Node)    │ │  (Peer Node)    │ │  (Peer Node)    │
│                 │ │                 │ │                 │
│  Validates mesh │ │  Validates mesh │ │  Validates mesh │
│  key & processes│ │  key & processes│ │  key & processes│
│  request        │ │  request        │ │  request        │
└─────────────────┘ └─────────────────┘ └─────────────────┘
```

### Step-by-Step Flow

1. **User sends chat message** to Site A
   - Anonymous user: includes `X-WP-MCP-AI-Guest` header with guest token
   - Authenticated user: includes `X-WP-Nonce` or `Authorization: Bearer` token

2. **Site A validates user permission**
   - Guest token validated against assistant's public access settings
   - WordPress nonce validated for logged-in users
   - Bearer token validated for Auth0/JWT authentication

3. **Assistant processes message**
   - If the assistant needs to query remote sites, it invokes the `query_remote_site` tool
   - Tool execution requires `manage_options` capability (admin-level)
   - Backend assistant runs under privileged context, NOT end-user context

4. **Mesh request sent to Site B**
   - Request includes `X-WP-MCP-AI-Mesh-Key` header with shared secret
   - Mesh authentication is **independent of end-user identity**
   - Site B validates mesh key (not user credentials)

5. **Site B processes request**
   - Mesh key validation succeeds → request allowed
   - Assistant on Site B executes the query
   - Result returned to Site A

6. **Site A returns response**
   - Combined result sent back to end-user
   - User attribution maintained for audit logs
   - Budget usage tracked per-site and optionally per-user

---

## Authentication Models

### Mesh Authentication (Server-to-Server)

**Purpose:** Secure inter-site communication for compute pooling

**Implementation:**
```php
// Mesh request from Site A to Site B
$headers = array(
    'Content-Type'         => 'application/json',
    'X-WP-MCP-AI-Mesh-Key' => $peer_site_api_key, // Shared secret
);

$response = wp_remote_post(
    'https://site-b.com/wp-json/mcp-ai/v1/chat',
    array(
        'headers' => $headers,
        'body'    => wp_json_encode( $chat_payload ),
    )
);
```

**Key Points:**
- Mesh keys are generated per-site when mesh networking is enabled
- Keys are stored in WordPress options and validated at REST API boundary
- Mesh authentication **bypasses user-level capability checks**
- Calls without valid mesh keys are rejected with `wp_mcp_ai_invalid_mesh_key` error
- Mesh must be explicitly enabled in **Settings → WP oOS → Mesh Network**

**Security:**
- Mesh keys are 40+ character random strings prefixed with `mesh_`
- Keys are transmitted via HTTPS only (enforced by WordPress)
- Invalid keys result in immediate rejection
- Mesh can be disabled to prevent all inter-site calls

### End-User Authentication

**Purpose:** Control which end-users can access assistants

#### Anonymous Users (Guest Tokens)

**Use Case:** Public chat interfaces where users don't need to log in

**Implementation:**
```javascript
// Frontend chat request with guest token
fetch('/wp-json/mcp-ai/v1/chat', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-MCP-AI-Guest': guestToken, // Temporary token
    },
    body: JSON.stringify({
        assistant_id: 123,
        messages: [{ role: 'user', content: 'Hello' }]
    })
});
```

**Key Points:**
- Guest tokens are generated per-assistant and time-limited
- Enable via `wp_mcp_ai_chat_capability` filter returning `'public'`
- Tokens are validated against assistant ID and expiration
- Guest users can still benefit from **mesh pooling** (backend handles coordination)
- Capability checks enforced at REST boundary prevent tool abuse

**Configuration:**
```php
// Make specific assistant public
add_filter( 'wp_mcp_ai_chat_capability', function( $capability, $assistant_id, $context ) {
    if ( 123 === $assistant_id && 'rest' === $context ) {
        return 'public'; // Allow anonymous access
    }
    return $capability;
}, 10, 3 );
```

#### Authenticated Users (WordPress/Bearer Tokens)

**Use Case:** Logged-in users with WordPress accounts or OAuth integration

**Implementation:**

**Option 1: WordPress Nonce (Same-Origin)**
```javascript
// Requires user to be logged into WordPress
fetch('/wp-json/mcp-ai/v1/chat', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce, // WordPress REST nonce
    },
    body: JSON.stringify({ /* ... */ })
});
```

**Option 2: Bearer Token (Cross-Origin / Mobile)**
```javascript
// Auth0 or assistant-issued credential
fetch('/wp-json/mcp-ai/v1/chat', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer cred_xxxxx.SECRET', // Assistant credential or Auth0 token
    },
    body: JSON.stringify({ /* ... */ })
});
```

**Key Points:**
- Authenticated users have identity attribution in audit logs
- User ID available in `$context['user_id']` for tool execution
- Capability-based access control enforced per tool
- **Mesh pooling still works** — backend coordinates on user's behalf
- Per-user quotas can be implemented via custom filters

---

## Use Cases

### Use Case 1: Anonymous Public Chat with Mesh Pooling

**Scenario:** 100 public-facing WordPress sites share compute across a mesh. Visitors are anonymous but can still access pooled AI resources.

**Configuration:**
- Each site has mesh enabled with peer sites configured
- Public assistants marked with `capability: 'public'`
- Guest tokens issued for anonymous chat
- Backend assistants run with `manage_options` capability
- Mesh requests authenticated via shared keys

**Benefit:**
- Anonymous users get distributed compute benefits
- No login required for frontend experience
- Backend handles privileged mesh coordination
- Budget pooled across all 100 sites
- Individual site failures don't cascade

### Use Case 2: Authenticated Users with Identity Attribution

**Scenario:** Enterprise deployment where each user has a WordPress account. Compliance requires tracking who accessed which AI tools.

**Configuration:**
- Users log in via WordPress or Auth0
- Assistant capability set to `edit_posts` or custom capability
- Mesh pooling enabled across enterprise sites
- Tool execution logs include user IDs
- Per-user quotas enforced via custom budget manager

**Benefit:**
- Full audit trail with user attribution
- Compliance-ready for SOC 2, GDPR, etc.
- Per-user resource governance
- Mesh pooling distributes load across sites
- Identity preserved across mesh calls (via context)

### Use Case 3: Hybrid Model (Public + Authenticated)

**Scenario:** Public website with optional login. Anonymous users get basic features, authenticated users get premium tools.

**Configuration:**
- Guest tokens for anonymous users
- WordPress login for premium features
- Assistant A (public): Limited tools, guest access
- Assistant B (premium): Full tools, requires `edit_posts`
- Both assistants can use mesh pooling
- Tool-level capability checks prevent abuse

**Benefit:**
- Flexible access model
- Anonymous users still benefit from mesh
- Authenticated users get enhanced capabilities
- Same infrastructure serves both user types
- Mesh pooling reduces per-site load

---

## Configuration

### Step 1: Enable Mesh Networking

1. Log in to WordPress admin on Site A
2. Navigate to **Settings → WP oOS**
3. Scroll to **Mesh Network** section
4. Check **Enable Mesh Networking**
5. Click **Save Changes**
6. Copy the generated **Inbound API Key** (shown once)

### Step 2: Add Peer Sites

1. In **Mesh Network** section, click **Add Peer Site**
2. Enter:
   - **Name**: Friendly identifier (e.g., "Site B")
   - **URL**: Full site URL (e.g., `https://site-b.com`)
   - **API Key**: The inbound key from Site B
3. Repeat for each peer site
4. Click **Save Changes**

### Step 3: Configure Assistant Access

**For Public/Anonymous Access:**
```php
// In theme functions.php or custom plugin
add_filter( 'wp_mcp_ai_chat_capability', function( $capability, $assistant_id, $context ) {
    // Make assistant ID 123 public
    if ( 123 === $assistant_id && 'rest' === $context ) {
        return 'public';
    }
    return $capability;
}, 10, 3 );
```

**For Authenticated Access:**
- Default behavior: `edit_posts` capability required
- Customize via filter above to use custom capabilities
- Bearer tokens automatically mapped to WordPress users (if configured)

### Step 4: Enable Mesh Tools for Assistant

1. Edit the assistant that should use mesh pooling
2. Scroll to **Available Tools** meta box
3. Enable **Query Remote Site** tool
4. Save assistant

**Note:** The `query_remote_site` tool requires `manage_options` capability and will only execute when mesh is enabled.

### Step 5: Test Mesh Request

**WP-CLI Test:**
```bash
wp mcp-ai chat \
    --assistant-id=123 \
    --message="Query Site B for latest products"
```

**REST API Test:**
```bash
curl -X POST https://site-a.com/wp-json/mcp-ai/v1/chat \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: YOUR_NONCE" \
    -d '{
        "assistant_id": 123,
        "messages": [
            {
                "role": "user",
                "content": "Query peer site SiteB for product data"
            }
        ]
    }'
```

---

## Security Considerations

### Mesh Key Security

- **Store securely**: Mesh keys should be treated like database passwords
- **HTTPS required**: Never transmit keys over unencrypted connections
- **Rotate regularly**: Regenerate keys periodically for security
- **Limit exposure**: Only share keys with trusted peer sites
- **Monitor usage**: Enable logging to track mesh requests

### Capability Gating

The `query_remote_site` tool requires **`manage_options`** capability:

```php
// Excerpt from WP_MCP_AI_Tool_Query_Remote_Site::execute()
// Source: includes/tools/class-wp-mcp-ai-tool-query-remote-site.php
$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
    return new WP_Error(
        'wp_mcp_ai_forbidden',
        __( 'You do not have permission to query remote sites.', 'wp-mcp-ai' )
    );
}
// ... rest of implementation
```

**This means:**
- End-users (anonymous or authenticated) **cannot directly invoke mesh tools**
- Only backend assistants running under admin context can coordinate mesh
- Prevents unauthorized cross-site queries
- Maintains security even with public assistants

### Guest Token Limitations

- Guest tokens are time-limited (configurable per-assistant)
- Tokens are validated against specific assistant IDs
- Tools still require appropriate capabilities (enforced at tool layer)
- Guest users cannot directly access `manage_options` tools
- Backend assistant handles privileged operations on their behalf

### Audit Logging

Enable logging to track mesh usage:

1. Navigate to **Settings → WP oOS**
2. Check **Enable Logging**
3. Review logs via:
   - **Settings → WP oOS → Logs** tab
   - WP-CLI: `wp option get wp_mcp_ai_recent_activity --format=json`

**Logged information includes:**
- User ID (if authenticated) or "guest" designation
- Assistant ID used for request
- Tools invoked during session
- Mesh peers contacted
- Timestamps and response codes
- Error messages for failed requests

---

## FAQ

### Can anonymous users benefit from mesh compute pooling?

**Short answer:** Yes—with Mesh you can pool compute across sites even if the end-users themselves are anonymous. The pooling happens **server-to-server**, not per visitor.

**Long answer:**

Mesh requests are authenticated between sites, not by end-user identity. Peer calls use the `X-WP-MCP-AI-Mesh-Key` header and a shared key; the code explicitly rejects bad keys and disables Mesh when turned off, which proves the trust is inter-site, not per visitor.

Public/anonymous visitors can still use assistants safely by:
- Marking specific assistants as **public** via the `wp_mcp_ai_chat_capability` filter so unauthenticated requests pass without a nonce
- Embedding an assistant that issues **guest tokens** (`X-WP-MCP-AI-Guest`), which the REST layer accepts for anonymous chats

Either way, capability checks are still enforced at the boundary by default, and calls without any credentials are rejected.

Cross-site "pooling" tools remain privileged. The Mesh tool that actually fans out work—`query_remote_site`—requires an admin-level capability and refuses to run if Mesh is off or a peer isn't configured (so anonymous users can't directly call it). The intended pattern is: a **backend assistant** (running under a privileged account) receives the anonymous user's prompt and then coordinates remote work across the mesh on their behalf.

### Does mesh pooling work with authenticated users?

**Yes.** Authenticated users (WordPress login or bearer tokens) can access assistants that use mesh pooling. The mesh coordination happens server-to-server, regardless of whether the end-user is anonymous or authenticated.

**Benefits for authenticated users:**
- User identity preserved in audit logs
- Per-user quotas can be enforced
- Full compliance trail for enterprise deployments
- Same mesh pooling benefits as anonymous users
- Tool access governed by user capabilities

### Can I enforce per-user quotas across the mesh?

**Yes, optionally.** The plugin can map bearer tokens to WordPress users (Auth0/JWT integrations provided), so you can attribute usage to a "service user" or to real identities for stricter governance if needed.

**Implementation approaches:**

1. **Custom budget filter:**
```php
add_filter( 'wp_mcp_ai_token_budget', function( $budget, $context ) {
    $user_id = $context['user_id'] ?? 0;
    if ( $user_id ) {
        $user_quota = get_user_meta( $user_id, 'ai_token_quota', true );
        if ( $user_quota ) {
            return min( $budget, $user_quota );
        }
    }
    return $budget;
}, 10, 2 );
```

2. **Per-user rate limiting:**
```php
// Track usage in user meta
add_action( 'wp_mcp_ai_chat_complete', function( $context, $usage ) {
    $user_id = $context['user_id'] ?? 0;
    if ( $user_id ) {
        $current_usage = (int) get_user_meta( $user_id, 'ai_tokens_used', true );
        update_user_meta( $user_id, 'ai_tokens_used', $current_usage + $usage['total_tokens'] );
    }
}, 10, 2 );
```

3. **Enterprise integration:** Use Auth0 custom claims to embed quota data in bearer tokens, validated at REST boundary.

### How do I track which user made which mesh request?

Enable logging and check the `created_by` or `user_id` field in audit logs:

```bash
# Via WP-CLI
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.tool == "query_remote_site")'
```

**Audit trail includes:**
- User ID (0 for guest users)
- Assistant ID
- Timestamp
- Peer site queried
- Request payload (if logging level is verbose)
- Response status

### What happens if a peer site goes down?

The `query_remote_site` tool handles errors gracefully:

```php
// Returns WP_Error on failure
if ( is_wp_error( $response ) ) {
    return new WP_Error(
        'wp_mcp_ai_remote_request_failed',
        sprintf(
            __( 'Failed to connect to peer site "%1$s": %2$s', 'wp-mcp-ai' ),
            $peer_name,
            $response->get_error_message()
        )
    );
}
```

**Failure handling:**
- Error returned to assistant's tool call
- Assistant can retry with different peer
- Frontend user receives graceful error message
- Other peers unaffected (isolated failure)
- Logs capture failure for monitoring

**Best practices:**
- Configure multiple peer sites for redundancy
- Implement retry logic in assistant prompts
- Monitor peer health via external tools
- Set appropriate request timeouts

### Can I use mesh pooling with local AI models (Ollama)?

**Yes.** Mesh networking works with OpenAI, Gemini, and Ollama models. Each peer site can use different providers:

- Site A: OpenAI GPT-4
- Site B: Ollama llama3
- Site C: Google Gemini Pro

The mesh request simply forwards the chat payload to the peer site's `/chat` endpoint. The peer site uses its configured provider to generate the response.

**Benefit:** Mix cloud and local models across mesh for cost optimization and data sovereignty.

### How many peer sites can I connect?

**Technical limit:** No hard limit in the code. The `mesh_peer_sites` setting stores an array of peer configurations.

**Practical considerations:**
- Each peer adds network latency
- Budget pools share token allocation
- More peers = more complex monitoring
- Typical deployments: 5-50 peer sites
- Enterprise deployments: 100+ sites with load balancing

**Performance tips:**
- Use geographic proximity for lower latency
- Implement peer health checks
- Distribute workload evenly across peers
- Cache frequently accessed data locally

### Is mesh networking required to use WP oOS?

**No.** Mesh networking is an **optional feature**. You can use WP oOS as a standalone AI assistant system without enabling mesh.

**Default behavior (mesh disabled):**
- Assistants work normally with local tools
- No cross-site coordination
- Simpler configuration
- Lower network complexity

**When to enable mesh:**
- Need to pool budget across multiple sites
- Want distributed compute for resilience
- Have specialized tools on different sites
- Enterprise multi-site deployment
- Load balancing requirements

---

## Related Documentation

- [MCP Server Authentication](mcp-server-authentication.md) - Authentication reference including Auth0, bearer tokens, and nonces
- [Simple JWT Login Integration](authentication.md) - Optional JWT authentication integration
- [Tool Reference](tool-reference.md) - All 65+ built-in tools including `query_remote_site`
- [REST API Documentation](rest-api.md) - REST endpoint specifications
- [Orchestration Layer Architecture](ORCHESTRATION-LAYER-ARCHITECTURE.md) - Distributed orchestration design
- [Remote Client Setup](remote-client-setup.md) - Connecting external MCP clients
- [Best Practices](BEST_PRACTICES.md) - Usage recommendations

---

**Maintained by:** NV Digital Solutions  
**Repository:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos  
**License:** GPLv3 or later  
**Support:** https://nvdigitalsolutions.com/support
