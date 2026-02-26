# Mesh Peer Connection Testing - Visual Guide

## What You'll See

### Before: No Way to Test

```
┌─────────────────────────────────────────────────────────────────────┐
│ Mesh Peer Sites                                                     │
├─────────────────────────────────────────────────────────────────────┤
│ Name              Site URL                   API Key        Actions │
├─────────────────────────────────────────────────────────────────────┤
│ Production Site   https://prod.example.com   mesh_abc123   [Remove]│
│                                                                      │
│ Staging Site      https://stage.example.com  mesh_xyz789   [Remove]│
└─────────────────────────────────────────────────────────────────────┘

❌ Problem: No way to verify if connections work!
```

### After: Test Button Added

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Mesh Peer Sites                                                          │
├──────────────────────────────────────────────────────────────────────────┤
│ Name              Site URL                   API Key        Actions     │
├──────────────────────────────────────────────────────────────────────────┤
│ Production Site   https://prod.example.com   mesh_abc123   [Test] [Remove]│
│                                                                           │
│ Staging Site      https://stage.example.com  mesh_xyz789   [Test] [Remove]│
└──────────────────────────────────────────────────────────────────────────┘

✅ Solution: Test button for each peer!
```

---

## Testing Flow

### Step 1: Click Test Button

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Name              Site URL                   API Key        Actions     │
├──────────────────────────────────────────────────────────────────────────┤
│ Production Site   https://prod.example.com   mesh_abc123   [🔄 Testing...] [Remove]│
│                                                             ^^^^^^^^^^
│                                                             Spinner active
└──────────────────────────────────────────────────────────────────────────┘
```

### Step 2A: Success Result

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Name              Site URL                   API Key        Actions     │
├──────────────────────────────────────────────────────────────────────────┤
│ Production Site   https://prod.example.com   mesh_abc123   [Test] [Remove]│
├──────────────────────────────────────────────────────────────────────────┤
│ ┌────────────────────────────────────────────────────────────────────┐  │
│ │ ✅ Connection test successful! (Production Site)                   │  │
│ │    • Site is reachable                                             │  │
│ │    • Federation enabled                                            │  │
│ │    • Authentication successful                                     │  │
│ └────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘

Auto-dismisses after 5 seconds
```

### Step 2B: Failure Result

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Name              Site URL                   API Key        Actions     │
├──────────────────────────────────────────────────────────────────────────┤
│ Staging Site      https://stage.example.com  mesh_xyz789   [Test] [Remove]│
├──────────────────────────────────────────────────────────────────────────┤
│ ┌────────────────────────────────────────────────────────────────────┐  │
│ │ ❌ Connection test failed.                                         │  │
│ │    API key authentication failed. Please verify the API key is     │  │
│ │    correct.                                                        │  │
│ └────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘

Auto-dismisses after 7 seconds
```

---

## Success States

### Fully Healthy Connection

```
✅ Connection test successful! (Production Server)
   • Site is reachable
   • Federation enabled
   • Authentication successful

→ AI Peers CPT Status: ● Healthy (green)
```

### Partially Working (Degraded)

```
✅ Connection test successful! (Development Site)
   • Site is reachable
   • Federation enabled
   • Authentication failed

→ AI Peers CPT Status: ● Degraded (yellow)
```

### Without API Key

```
✅ Connection test successful! (Partner Site)
   • Site is reachable
   • Federation enabled
   • Authentication not tested (no API key)

→ AI Peers CPT Status: ● Degraded (yellow)
```

---

## Error States

### 1. Site Not Reachable

```
❌ Site is not reachable: Connection timed out

Possible causes:
- Wrong URL
- DNS issues
- Firewall blocking
- Site offline
```

### 2. Federation Not Enabled

```
❌ Well-known endpoint not accessible: 
   Well-known endpoint returned status 404

Possible causes:
- Plugin not installed
- Federation not enabled
- Permalink issues
```

### 3. Authentication Failed

```
❌ API key authentication failed. 
   Please verify the API key is correct.

Possible causes:
- Wrong API key
- Key expired
- Key from wrong site
```

### 4. Plugin Not Installed

```
❌ MCP endpoint not found. The remote site may not 
   have the plugin installed.

Possible causes:
- Plugin not installed
- REST API disabled
- Permalink structure not set
```

### 5. Invalid URL

```
❌ Invalid peer URL.

Possible causes:
- Malformed URL
- Missing protocol (http/https)
- Typos in URL
```

---

## AI Peers Integration

### Before Test

```
┌─────────────────────────────────────────────────────────────────────┐
│ AI Peers                                                            │
├─────────────────────────────────────────────────────────────────────┤
│ Title               Type         Health      Capabilities  Regions  │
├─────────────────────────────────────────────────────────────────────┤
│ Production Site     [MESH]       ● Unknown   2 tools       global   │
│                    (purple)      (gray)                             │
└─────────────────────────────────────────────────────────────────────┘

Health Status: Unknown (never tested)
Last Check: Never
```

### After Successful Test

```
┌─────────────────────────────────────────────────────────────────────┐
│ AI Peers                                                            │
├─────────────────────────────────────────────────────────────────────┤
│ Title               Type         Health      Capabilities  Regions  │
├─────────────────────────────────────────────────────────────────────┤
│ Production Site     [MESH]       ● Healthy   2 tools       global   │
│                    (purple)      (green)                            │
└─────────────────────────────────────────────────────────────────────┘

Health Status: Healthy ✅
Last Check: 2 mins ago
```

### After Failed Test

```
┌─────────────────────────────────────────────────────────────────────┐
│ AI Peers                                                            │
├─────────────────────────────────────────────────────────────────────┤
│ Title               Type         Health      Capabilities  Regions  │
├─────────────────────────────────────────────────────────────────────┤
│ Staging Site        [MESH]       ● Down      0 tools       global   │
│                    (purple)      (red)                              │
└─────────────────────────────────────────────────────────────────────┘

Health Status: Down ❌
Last Check: 1 min ago
```

---

## Browser Console (Developer View)

### Successful Test

```javascript
// Console output when test succeeds
Mesh peer test results: {
  success: true,
  url: "https://prod.example.com",
  reachable: true,
  wellknown: true,
  authenticated: true,
  site_name: "Production Server",
  capabilities: ["query_remote_site", "distributed_processing"],
  message: "Connection test successful!",
  details: {
    reachability: {
      status: "success",
      message: "Site is reachable."
    },
    wellknown: {
      status: "success",
      message: "Federation well-known endpoint accessible."
    },
    authentication: {
      status: "success",
      message: "API key authentication successful."
    }
  }
}
```

### Failed Test

```javascript
// Console output when test fails
Mesh peer test error: {
  responseJSON: {
    code: "unreachable",
    message: "Site is not reachable: Connection timed out",
    data: { status: 400 }
  },
  status: 400,
  statusText: "Bad Request"
}
```

---

## Page Locations

### 1. Mesh Settings Page (Primary)

```
URL: /wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh

Navigation:
WordPress Admin → Settings → Advanced → Federation & Mesh tab

┌─────────────────────────────────────────────────────────────┐
│ NV oOS Settings                                             │
├─────────────────────────────────────────────────────────────┤
│ [General] [Providers] [Tools] [Advanced] [Security]        │
│                                   ^^^^^^^^                   │
│                                                              │
│ Advanced Settings                                            │
│ ┌───────────────────────────────────────────────────────┐  │
│ │ [Performance Monitoring]                               │  │
│ │ [Performance]                                          │  │
│ │ [Data Management]                                      │  │
│ │ [Federation & Mesh]  ← You are here                   │  │
│ │ [System]                                               │  │
│ │ [Settings Management]                                  │  │
│ └───────────────────────────────────────────────────────┘  │
│                                                              │
│ Mesh Peer Sites                                              │
│ [Add Peer Site]                                              │
│ ┌────────────────────────────────────────────────────────┐ │
│ │ Name    URL              API Key         Actions       │ │
│ │ ────────────────────────────────────────────────────── │ │
│ │ Prod    https://...      mesh_abc...     [Test][Remove]│ │
│ └────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### 2. AI Peers List (Status View)

```
URL: /wp-admin/edit.php?post_type=ai_peer

Navigation:
WordPress Admin → AI Peers

┌─────────────────────────────────────────────────────────────┐
│ AI Peers                                           Add New   │
├─────────────────────────────────────────────────────────────┤
│ [All (2)] [Publish (2)]                                     │
│                                                              │
│ □ Title          Type    Health    Capabilities   Regions   │
│ ────────────────────────────────────────────────────────────│
│ □ Prod Site      MESH    ●Healthy  2 tools        global    │
│ □ Stage Site     MESH    ●Down     0 tools        global    │
└─────────────────────────────────────────────────────────────┘

Click on peer name to view full details and test history
```

### 3. AI Peer Edit Page (Details View)

```
URL: /wp-admin/post.php?post={id}&action=edit&post_type=ai_peer

Navigation:
WordPress Admin → AI Peers → [Click peer name]

┌─────────────────────────────────────────────────────────────┐
│ Edit AI Peer                                                │
├─────────────────────────────────────────────────────────────┤
│ Title: Production Site                                      │
│                                                              │
│ ┌─────────────────────────────────────────────────────────┐│
│ │ Peer Information                                        ││
│ │                                                          ││
│ │ Connection Type:   [MESH]                               ││
│ │                    (purple badge)                        ││
│ │                    This peer was configured manually via ││
│ │                    mesh networking settings.             ││
│ │                                                          ││
│ │ Site URL:          https://prod.example.com             ││
│ │                                                          ││
│ │ Well-Known URL:    —                                    ││
│ │                    (Not set for mesh peers)              ││
│ └─────────────────────────────────────────────────────────┘│
│                                                              │
│ ┌─────────────────────────────────────────────────────────┐│
│ │ Health Status                                           ││
│ │                                                          ││
│ │ Status:       ● Healthy                                 ││
│ │ Last Check:   2 minutes ago                             ││
│ └─────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────┘
```

---

## Comparison: Mesh vs Remote Sites

### Current: Mesh in Advanced Settings

```
Location: Settings → Advanced → Federation & Mesh

Pros:
✅ Available in base plugin
✅ Grouped with federation settings
✅ Straightforward configuration

Cons:
❌ Separate from other remote connections
❌ Different UI than Remote Sites
```

### Future: Mesh in Remote Sites (Pro)

```
Location: NV oOS Pro → Remote Sites

Pros:
✅ All connections in one place
✅ Consistent UI/UX
✅ Unified testing interface
✅ Better for many connections

Cons:
❌ Requires Pro addon
❌ More complex (many connection types)
```

**Recommendation**: Support both! 
- Base users: Use Advanced Settings
- Pro users: Choose either location
- Auto-sync between both

---

## Mobile Responsive

### Desktop View

```
┌─────────────────────────────────────────────────────────────┐
│ Name          URL                 API Key       Actions     │
│ Prod Site     https://prod...     mesh_abc...   [Test][Remove]│
└─────────────────────────────────────────────────────────────┘
```

### Tablet View

```
┌──────────────────────────────────────────────────────┐
│ Name          URL              API Key    Actions   │
│ Prod Site     https://prod...  mesh_...   [T][R]    │
└──────────────────────────────────────────────────────┘
```

### Mobile View (Stacked)

```
┌───────────────────────────────┐
│ Production Site               │
│ URL: https://prod.example.com │
│ Key: mesh_abc123...           │
│ [Test Connection] [Remove]    │
├───────────────────────────────┤
│ ✅ Healthy - 2 mins ago       │
└───────────────────────────────┘
```

---

## Keyboard Navigation

```
Tab Order:
1. Name field
2. URL field
3. API Key field
4. [Test] button  ← Can activate with Enter/Space
5. [Remove] button
6. Next peer's Name field
...
```

**Accessibility:**
- ✅ Keyboard accessible
- ✅ ARIA labels on buttons
- ✅ Screen reader friendly
- ✅ Focus indicators
- ✅ Status messages announced

---

## Summary

### Before This Feature
```
User adds mesh peer → ❓ Does it work? → No way to know
```

### After This Feature
```
User adds mesh peer → Click [Test] → ✅ Instant feedback → Save with confidence
```

### Impact
- ⏱️ **Faster setup**: Catch config errors immediately
- 🐛 **Easier debugging**: Specific error messages
- ✅ **More confidence**: Verify before saving
- 📊 **Better monitoring**: Health status tracking
- 🔧 **Easier support**: Test results help troubleshooting
