# MCP Endpoint Implementation - Visual Guide

## Overview
This document provides a visual guide to the MCP endpoint implementation with SSE as the default transport.

---

## Endpoint Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    GET /mcp-ai/v1/mcp                           │
│                                                                 │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │                    Check Request                          │ │
│  └───────────────────────────────────────────────────────────┘ │
│                              │                                  │
│              ┌───────────────┴────────────────┐                │
│              │                                │                │
│              ▼                                ▼                │
│  ┌──────────────────────┐        ┌──────────────────────┐     │
│  │ ?discovery=true      │        │ Accept:              │     │
│  │      OR              │        │ application/json     │     │
│  │ Accept: app/json     │        │      OR              │     │
│  │                      │        │ No specific Accept   │     │
│  └──────────────────────┘        └──────────────────────┘     │
│              │                                │                │
│              │ YES                            │ NO (DEFAULT)   │
│              ▼                                ▼                │
│  ┌──────────────────────┐        ┌──────────────────────┐     │
│  │  Return Discovery    │        │  Establish SSE       │     │
│  │  JSON Response       │        │  Connection          │     │
│  └──────────────────────┘        └──────────────────────┘     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                   POST /mcp-ai/v1/mcp                           │
│                                                                 │
│                  ┌──────────────────────┐                       │
│                  │  JSON-RPC 2.0        │                       │
│                  │  Protocol Handler    │                       │
│                  └──────────────────────┘                       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                  GET /mcp-ai/v1/no-sse                          │
│                                                                 │
│                  ┌──────────────────────┐                       │
│                  │  Return Assistant    │                       │
│                  │  Directory (JSON)    │                       │
│                  └──────────────────────┘                       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Request/Response Examples

### Example 1: Default SSE Connection (Most Common)
```bash
GET /wp-json/mcp-ai/v1/mcp
Authorization: Bearer cred_xxxxx.SECRET
```

**Response:**
```
HTTP/1.1 200 OK
Content-Type: text/event-stream
Access-Control-Allow-Origin: *

data: {"event": "connected", "timestamp": 1234567890}

data: {"event": "assistant_available", "id": 123, "name": "Support Bot"}

data: {"event": "keepalive"}
```

---

### Example 2: Discovery Information
```bash
GET /wp-json/mcp-ai/v1/mcp?discovery=true
Authorization: Bearer cred_xxxxx.SECRET
```

**Response:**
```json
{
  "name": "WP oOS MCP Server",
  "version": "1.0.0",
  "protocolVersion": "2024-11-05",
  "capabilities": {
    "sse": {
      "enabled": true,
      "default": true,
      "note": "GET /mcp defaults to SSE. Add ?discovery=true for JSON."
    },
    "tools": { "listChanged": true },
    "resources": { "subscribe": true, "listChanged": true },
    "prompts": { "listChanged": true }
  },
  "transports": {
    "sse": {
      "endpoint": "https://site.com/wp-json/mcp-ai/v1/mcp",
      "methods": ["GET"],
      "default": true,
      "note": "Default transport - GET /mcp establishes SSE"
    },
    "jsonrpc": {
      "endpoint": "https://site.com/wp-json/mcp-ai/v1/mcp",
      "methods": ["POST"],
      "note": "POST with JSON-RPC 2.0 payload"
    }
  },
  "endpoints": {
    "mcp": "https://site.com/wp-json/mcp-ai/v1/mcp",
    "no-sse": "https://site.com/wp-json/mcp-ai/v1/no-sse",
    "assistants": "https://site.com/wp-json/mcp-ai/v1/assistants",
    "chat": "https://site.com/wp-json/mcp-ai/v1/chat",
    "tools": "https://site.com/wp-json/mcp-ai/v1/tools"
  },
  "usage": {
    "sse_default": "GET /mcp (default - establishes SSE stream)",
    "discovery": "GET /mcp?discovery=true (returns this JSON)",
    "no_sse": "GET /no-sse (assistant directory without SSE)",
    "jsonrpc": "POST /mcp (JSON-RPC 2.0 protocol)"
  }
}
```

---

### Example 3: JSON-RPC Request (Backward Compatible)
```bash
POST /wp-json/mcp-ai/v1/mcp
Content-Type: application/json
Authorization: Bearer cred_xxxxx.SECRET

{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {
    "protocolVersion": "2024-11-05",
    "clientInfo": {
      "name": "LM Studio",
      "version": "0.3.0"
    }
  }
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {
      "tools": { "listChanged": true },
      "resources": { "subscribe": true, "listChanged": true },
      "prompts": { "listChanged": true }
    },
    "serverInfo": {
      "name": "WP oOS",
      "version": "1.0.0"
    },
    "instructions": "This is a WordPress site..."
  }
}
```

---

### Example 4: Non-SSE Directory
```bash
GET /wp-json/mcp-ai/v1/no-sse
Authorization: Bearer cred_xxxxx.SECRET
```

**Response:**
```json
{
  "assistants": [
    {
      "id": 123,
      "title": "Support Bot",
      "slug": "support-bot",
      "provider": "openai",
      "model": "gpt-4",
      "tools": ["search_content", "create_post"],
      "is_default": true
    }
  ],
  "total": 1
}
```

---

## Client Configuration Examples

### LM Studio (Recommended)
```json
{
  "mcpServers": {
    "wordpress-site": {
      "url": "https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_kzccaed1apcf.dmqOJAVDpmAUdHJ2Sq5QHMuNbWg2FZHe"
      },
      "timeout": 30000
    }
  }
}
```

**What Happens:**
1. ✅ LM Studio sends GET to `/mcp`
2. ✅ Server establishes SSE connection (default)
3. ✅ Real-time updates stream automatically
4. ✅ JSON-RPC requests work via POST

---

### Claude Desktop
```json
{
  "mcpServers": {
    "wp-oos": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer your_token_here"
      }
    }
  }
}
```

---

### JavaScript EventSource (Browser)
```javascript
// Establish SSE connection (default behavior)
const mcpUrl = 'https://your-site.com/wp-json/mcp-ai/v1/mcp';
const evtSource = new EventSource(mcpUrl, {
  headers: {
    'Authorization': 'Bearer your_token_here'
  }
});

evtSource.onmessage = (event) => {
  const data = JSON.parse(event.data);
  console.log('Received:', data);
};

evtSource.onerror = (error) => {
  console.error('SSE Error:', error);
};
```

---

### JavaScript Fetch (Discovery)
```javascript
// Get discovery information
const response = await fetch(
  'https://your-site.com/wp-json/mcp-ai/v1/mcp?discovery=true',
  {
    headers: {
      'Authorization': 'Bearer your_token_here',
      'Accept': 'application/json'
    }
  }
);

const discovery = await response.json();
console.log('Server Info:', discovery.serverInfo);
console.log('Available Endpoints:', discovery.endpoints);
console.log('Transports:', discovery.transports);
```

---

## Comparison Table

| Feature | Before | After |
|---------|--------|-------|
| **Default GET /mcp** | Discovery JSON | **SSE Stream** |
| **Get Discovery** | GET /mcp | GET /mcp?discovery=true |
| **SSE Endpoint** | GET /sse | GET /mcp (default) |
| **Non-SSE Directory** | GET /assistants | GET /no-sse |
| **JSON-RPC** | POST /mcp | POST /mcp (unchanged) |
| **Accept Header** | Ignored | Used for content negotiation |

---

## Decision Tree for Clients

```
┌─────────────────────────────────────────┐
│  What do you want to do?               │
└─────────────────────────────────────────┘
                 │
    ─────────────┴─────────────
    │                          │
    ▼                          ▼
┌─────────────┐          ┌─────────────┐
│ Stream      │          │ Request/    │
│ Real-time   │          │ Response    │
│ Updates     │          │ Only        │
└─────────────┘          └─────────────┘
     │                         │
     ▼                         ▼
GET /mcp              ┌────────┴────────┐
(SSE default)         │                 │
                      ▼                 ▼
               ┌────────────┐    ┌────────────┐
               │ MCP        │    │ Discovery  │
               │ Protocol   │    │ Info       │
               └────────────┘    └────────────┘
                      │                 │
                      ▼                 ▼
               POST /mcp         GET /mcp
               (JSON-RPC)        ?discovery=true
```

---

## Error Scenarios

### 1. Missing Authentication
```bash
GET /wp-json/mcp-ai/v1/mcp
# No Authorization header
```

**Response:**
```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": { "status": 401 }
}
```

---

### 2. Invalid Token
```bash
GET /wp-json/mcp-ai/v1/mcp
Authorization: Bearer invalid_token
```

**Response:**
```json
{
  "code": "rest_forbidden",
  "message": "Invalid or expired token.",
  "data": { "status": 403 }
}
```

---

### 3. Server Not Ready
```bash
GET /wp-json/mcp-ai/v1/mcp
Authorization: Bearer cred_xxxxx.SECRET
```

**Response (SSE):**
```
HTTP/1.1 503 Service Unavailable
Content-Type: text/event-stream

data: {"error": "server_unavailable", "message": "MCP server not ready"}
```

---

## Testing Checklist

- [ ] GET /mcp returns SSE stream
- [ ] GET /mcp?discovery=true returns JSON
- [ ] GET /mcp with Accept: application/json returns JSON
- [ ] POST /mcp with JSON-RPC works
- [ ] GET /no-sse returns JSON directory
- [ ] CORS headers include GET method
- [ ] Authentication required for all requests
- [ ] LM Studio can connect successfully
- [ ] Backward compatibility maintained
- [ ] Error handling works correctly

---

## Quick Reference

| Endpoint | Purpose | Default Response |
|----------|---------|------------------|
| `GET /mcp` | **Main endpoint** | **SSE stream** |
| `GET /mcp?discovery=true` | Server info | JSON |
| `POST /mcp` | MCP protocol | JSON-RPC |
| `GET /no-sse` | Directory (no SSE) | JSON |
| `GET /assistants` | Assistant list | JSON or SSE |
| `POST /chat` | Chat messages | JSON or SSE |
| `POST /tools` | Execute tools | JSON |

---

## Support

For issues or questions:
1. Check the changelog: `MCP_GET_SUPPORT_CHANGELOG.md`
2. Run manual tests: `bash bin/test-mcp-get-support.sh`
3. Review documentation: `docs/mcp-endpoint.md`
4. Open an issue on GitHub

---

**Last Updated:** November 14, 2025  
**Version:** 1.1.0+  
**MCP Protocol:** 2024-11-05
