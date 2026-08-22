---
type: Skill
name: design-ai-assistant-admin
description: Manage AI assistant configurations and peer-to-peer mesh network connections in the NV oOS Pro Toolkit. Covers assistant creation, model configuration, provider setup, peer discovery, mesh networking, and cross-assistant communication. Use when creating or editing AI assistants, configuring model providers, setting up peer connections, managing mesh network topology, or debugging assistant behavior.
license: Proprietary. See LICENSE.txt
metadata:
  type: Skill
---

# NV oOS Pro Toolkit AI Assistant Administration

The Pro Toolkit AI Assistant Admin system manages the complete lifecycle of AI
assistant configurations and the mesh network of peer-to-peer AI agent connections.
All entities are stored as CPT records accessible via the `toolkit_cpt` MCP tool.

The system provides two interlocking entity types: **AI Assistants** define the
personality, model, provider, and tool access for each AI agent, while **AI Peers**
establish authenticated connections between assistants across different WordPress
sites in a mesh network topology.

## WP-CLI Commands

Key `mcp-ai` WP-CLI commands for AI assistant and peer operations. Run via `terminal`:

| Command | Use for |
|---|---|
| `wp mcp-ai assistant list` | List all assistant configurations with model/provider details |
| `wp mcp-ai assistant create` | Create a new AI assistant from a JSON definition |
| `wp mcp-ai peer list` | List all mesh network peer connections |
| `wp mcp-ai peer test` | Test connectivity to a specific peer by ID or URL |
| `wp mcp-ai health` | Verify assistant configuration integrity and peer health |
| `wp mcp-ai log` | View assistant execution and peer communication logs |

## When to use this skill

Trigger when ANY of the following is true:

- Creating, editing, or deleting AI assistant configurations.
- Changing an assistant's model provider (OpenAI, Gemini, Anthropic, Ollama, etc.).
- Configuring an assistant's system prompt, temperature, max tokens, or tool access.
- Setting up or removing peer-to-peer mesh network connections between WordPress sites.
- Testing peer connectivity or diagnosing mesh network communication failures.
- Listing all assistants with their current model, provider, and status.
- Querying which tools are enabled for a specific assistant.
- Debugging assistant behavior — checking configuration, tool access, or peer routing.
- Managing assistant-specific vector store or memory settings.
- Cloning an assistant configuration for testing or staging environments.

## Mental model — assistants and peers

The AI Assistant Admin system connects two layers: configuration (assistants) and
communication (peers):

```
AI ASSISTANT ──(configured with)──→ Model + Provider
      │                                    │
      ├── System Prompt                     ├── OpenAI (gpt-4o, gpt-4o-mini)
      ├── Temperature / Max Tokens          ├── Gemini (gemini-2.5-flash)
      ├── Tool Access (enabled/disabled)    ├── Anthropic (claude-sonnet-4-20250514)
      ├── Vector Store ID                   ├── Ollama (local models)
      └── Memory Settings                   └── Cloudflare / HuggingFace

AI PEER ──(connects to)──→ Remote AI Assistant (on another WP site)
      │
      ├── Peer URL (endpoint)
      ├── Peer Name (friendly identifier)
      ├── Auth Token / API Key
      └── Connection Status (active/inactive/error)

MESH NETWORK:  Site A ←──peer──→ Site B ←──peer──→ Site C
                  │                                    │
                  └──────────peer──────────────────────┘
```

**Relationships:**
- An **AI Assistant** (`mcp_ai_assistant`) is a standalone configuration record. It
  defines one complete AI agent persona with its model, provider, system prompt, and
  enabled tools. Assistants do not directly reference peers — the mesh network routes
  queries between assistants based on peer configuration.
- An **AI Peer** (`ai_peer`) represents a bidirectional connection to a remote
  WordPress site's AI assistant. Each peer stores the remote endpoint URL, a friendly
  name, authentication credentials, and connection status. Peers form a mesh where
  any assistant can query any reachable peer's assistant.
- **Mesh routing** is automatic: when an assistant receives a query that should be
  handled by a peer, the mesh layer forwards it based on the peer configuration.

## Entity Types

All AI Assistant Admin entities are managed through the **`toolkit_cpt`** MCP tool.
Use the appropriate `post_type` slug for each entity:

| CPT Slug | Label | Supports | Key Meta Fields |
|---|---|---|---|
| `mcp_ai_assistant` | AI Assistants | title, editor | `_assistant_model`, `_assistant_provider`, `_assistant_system_prompt`, `_assistant_temperature`, `_assistant_max_tokens`, `_assistant_enabled_tools`, `_assistant_vector_store_id`, `_assistant_memory_enabled`, `_assistant_status`, `_assistant_avatar_id`, `_assistant_welcome_message` |
| `ai_peer` | AI Peers | title, editor | `_peer_url`, `_peer_name`, `_peer_api_key`, `_peer_status`, `_peer_last_seen`, `_peer_timeout`, `_peer_retry_policy` |

### AI Assistant Meta Fields (detailed)

| Field | Type | Description | Example |
|---|---|---|---|
| `_assistant_model` | string | AI model identifier | `"gpt-4o"`, `"gemini-2.5-flash"`, `"claude-sonnet-4-20250514"` |
| `_assistant_provider` | string | AI provider slug | `"openai"`, `"gemini"`, `"anthropic"`, `"ollama"` |
| `_assistant_system_prompt` | string | System-level instruction for the assistant | `"You are a helpful customer support agent..."` |
| `_assistant_temperature` | string | Creativity/temperature (0-2) | `"0.7"` |
| `_assistant_max_tokens` | string | Response token limit | `"4096"` |
| `_assistant_enabled_tools` | array | List of enabled tool slugs | `["web_search", "create_post", "toolkit_cpt"]` |
| `_assistant_vector_store_id` | string | OpenAI vector store ID for RAG | `"vs_abc123def456"` |
| `_assistant_memory_enabled` | string | Whether agent memory is active | `"1"` or `"0"` |
| `_assistant_status` | string | Operational status | `"active"`, `"inactive"`, `"draft"`, `"error"` |
| `_assistant_avatar_id` | int | Media library attachment ID for avatar | `42` |
| `_assistant_welcome_message` | string | First message shown in chat UI | `"Hello! How can I help you today?"` |

### AI Peer Meta Fields (detailed)

| Field | Type | Description | Example |
|---|---|---|---|
| `_peer_url` | string | Remote WordPress site URL | `"https://partner-site.com"` |
| `_peer_name` | string | Friendly name for the peer | `"NUGL Analytics Site"` |
| `_peer_api_key` | string | Authentication token for the peer | `"cred_xxxxx.SECRET"` |
| `_peer_status` | string | Connection status | `"active"`, `"inactive"`, `"error"`, `"pending"` |
| `_peer_last_seen` | string | ISO 8601 timestamp of last successful contact | `"2026-08-12T14:30:00Z"` |
| `_peer_timeout` | string | Request timeout in seconds | `"30"` |
| `_peer_retry_policy` | string | Retry strategy on failure | `"exponential"`, `"fixed"`, `"none"` |

## Available Tools

All AI Assistant Admin operations use the **`toolkit_cpt`** MCP tool. This is the
single interface for all assistant and peer CRUD operations.

### Discovery

```
# List all available CPT types (confirms mcp_ai_assistant and ai_peer are registered)
toolkit_cpt(action: "list_types")

# Get field schema for assistants
toolkit_cpt(action: "get_schema", post_type: "mcp_ai_assistant")

# Get field schema for peers
toolkit_cpt(action: "get_schema", post_type: "ai_peer")
```

### Reading records

```json
// List all active assistants (most recent first)
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "list_items",
    "post_type": "mcp_ai_assistant",
    "orderby": "date",
    "order": "DESC",
    "per_page": 20,
    "page": 1
  }
}

// Search assistants by name
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "list_items",
    "post_type": "mcp_ai_assistant",
    "search": "Customer Support"
  }
}

// Filter assistants by provider
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "list_items",
    "post_type": "mcp_ai_assistant",
    "filters": [
      { "key": "_assistant_provider", "value": "openai" }
    ]
  }
}

// Get a single assistant with all fields
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "get_item",
    "post_type": "mcp_ai_assistant",
    "item_id": 42
  }
}

// List all active peer connections
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "list_items",
    "post_type": "ai_peer",
    "filters": [
      { "key": "_peer_status", "value": "active" }
    ]
  }
}
```

### Creating records

```json
// Create a new AI assistant
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "create_item",
    "post_type": "mcp_ai_assistant",
    "fields": {
      "title": "Customer Support Agent",
      "_assistant_model": "gpt-4o",
      "_assistant_provider": "openai",
      "_assistant_system_prompt": "You are a helpful customer support agent for an e-commerce store. Be concise, friendly, and solution-oriented.",
      "_assistant_temperature": "0.7",
      "_assistant_max_tokens": "4096",
      "_assistant_enabled_tools": ["web_search", "woo_products", "toolkit_cpt"],
      "_assistant_status": "active",
      "_assistant_welcome_message": "Hello! I'm your support assistant. How can I help you today?",
      "_assistant_memory_enabled": "1"
    }
  }
}

// Register a new peer connection
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "create_item",
    "post_type": "ai_peer",
    "fields": {
      "title": "NUGL Analytics Site",
      "_peer_url": "https://analytics.nugl.com",
      "_peer_name": "NUGL Analytics",
      "_peer_api_key": "cred_abc123.SECRET",
      "_peer_status": "active",
      "_peer_timeout": "30",
      "_peer_retry_policy": "exponential"
    }
  }
}
```

### Updating records

```json
// Change an assistant's model or provider
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "update_item",
    "post_type": "mcp_ai_assistant",
    "item_id": 42,
    "fields": {
      "_assistant_model": "gemini-2.5-flash",
      "_assistant_provider": "gemini",
      "_assistant_temperature": "0.9"
    }
  }
}

// Disable a failing peer connection
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "update_item",
    "post_type": "ai_peer",
    "item_id": 15,
    "fields": {
      "_peer_status": "inactive"
    }
  }
}

// Add tools to an assistant
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "update_item",
    "post_type": "mcp_ai_assistant",
    "item_id": 42,
    "fields": {
      "_assistant_enabled_tools": ["web_search", "woo_products", "toolkit_cpt", "deep_research"]
    }
  }
}
```

### Deleting records

```json
// Delete an assistant (permanent)
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "delete_item",
    "post_type": "mcp_ai_assistant",
    "item_id": 42
  }
}

// Delete a peer connection
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "delete_item",
    "post_type": "ai_peer",
    "item_id": 15
  }
}
```

**⚠️ Warning:** Deleting an assistant is permanent and removes all configuration.
Consider setting `_assistant_status` to `"inactive"` instead. Deleting a peer
severs the mesh connection — all cross-site queries to that peer will fail.

### Bulk operations

```json
// Bulk create peer connections for a new mesh network
{
  "name": "toolkit_cpt",
  "arguments": {
    "action": "bulk_create",
    "post_type": "ai_peer",
    "items": [
      { "title": "Site A - Production", "_peer_url": "https://site-a.com", "_peer_name": "Site A", "_peer_status": "active" },
      { "title": "Site B - Staging", "_peer_url": "https://staging.site-b.com", "_peer_name": "Site B Staging", "_peer_status": "active" }
    ]
  }
}
```

## Common Workflows

### Creating and configuring a new AI assistant

```
1. Get schema:      get_schema(mcp_ai_assistant) — verify available fields.
2. Choose model:    Decide provider + model (check list_available_models for OpenAI).
3. Write prompt:    Craft the system prompt for the assistant's persona.
4. Select tools:    Choose which MCP tools the assistant can access.
5. Create record:   create_item(mcp_ai_assistant, with all configuration fields).
6. Verify:          get_item(mcp_ai_assistant, item_id: <new_id>) to confirm.
```

### Setting up a mesh network peer

```
1. Get schema:      get_schema(ai_peer) — verify available fields.
2. Obtain creds:    On the remote site, generate an assistant credential token.
3. Test reach:      Use fetch tool to confirm the remote site is accessible.
4. Register peer:   create_item(ai_peer, with peer_url, peer_name, api_key).
5. Verify status:   get_item(ai_peer) — check _peer_status is "active".
6. Test query:      Use query_remote_site(peer_name, prompt) to validate.
```

### Auditing all assistants

```
1. list_items(mcp_ai_assistant, per_page: 50)
   → Review each assistant's provider, model, status, and enabled tools.
   
2. For each inactive/draft assistant:
   → get_item(mcp_ai_assistant, item_id: <id>) to inspect full config.
   → Either activate or delete based on business need.

3. Check for assistants using deprecated models:
   → Filter by _assistant_model and compare against current provider offerings.
```

### Diagnosing peer connection failures

```
1. List all peers:   list_items(ai_peer) — identify any with status "error".
2. Get peer detail:  get_item(ai_peer, item_id: <id>) — check URL, timeout, api_key.
3. Test manually:    Use fetch to hit the peer URL + /wp-json/mcp-ai/v1/health.
4. Check last_seen:  If _peer_last_seen is stale (>1 hour), the peer may be down.
5. Update status:    update_item(ai_peer, _peer_status: "inactive") if unreachable.
6. Notify admin:     Log the failure so the remote site admin can investigate.
```

### Cloning an assistant for staging

```
1. Get source:       get_item(mcp_ai_assistant, item_id: <prod_id>).
2. Create clone:     create_item(mcp_ai_assistant) with same fields but title
                     suffixed with "(Staging)" and _assistant_status: "draft".
3. Adjust config:    update_item — change model/tools as needed for testing.
4. Test clone:       Send test prompts to verify behavior.
5. Promote:          When ready, update_item to set _assistant_status: "active".
```

## Critical Rules

- **Always use `get_schema` first** before creating or updating a record to verify available field keys and types.
- **API keys in peers must be valid credential tokens** — use the format `cred_xxxxx.SECRET` generated from the remote site's assistant credential system.
- **`_assistant_enabled_tools` is a JSON array** — pass it as a native array in the JSON payload, not a string.
- **Never expose `_peer_api_key` in logs or chat** — treat peer credentials with the same security as API keys.
- **Model names must match provider conventions exactly** — check `list_available_models` for OpenAI, or the provider's current documentation for others.
- **`_assistant_temperature` is a string** — use `"0.7"` not `0.7` in JSON.
- **`_assistant_max_tokens` is a string** — use `"4096"` not `4096`.
- **Inactive assistants still consume a CPT record** — clean up unused draft/inactive assistants periodically.
- **Peer URLs must include the scheme** — `"https://site.com"` not `"site.com"`.
- **Mesh network is bidirectional** — both sides need a peer record pointing to each other for full duplex communication.

## Common Mistakes

```
WRONG — creating an assistant without a system prompt
{ "action": "create_item", "post_type": "mcp_ai_assistant",
  "fields": { "title": "My Bot", "_assistant_model": "gpt-4o", "_assistant_provider": "openai" } }
// Missing _assistant_system_prompt — the assistant has no personality or instructions.

RIGHT — always include the system prompt
{ "action": "create_item", "post_type": "mcp_ai_assistant",
  "fields": { "title": "My Bot", "_assistant_model": "gpt-4o", "_assistant_provider": "openai",
  "_assistant_system_prompt": "You are a helpful assistant.", "_assistant_status": "active",
  "_assistant_temperature": "0.7", "_assistant_max_tokens": "4096" } }

WRONG — registering a peer without testing connectivity
{ "action": "create_item", "post_type": "ai_peer",
  "fields": { "title": "Remote Site", "_peer_url": "https://example.com", "_peer_status": "active" } }
// No _peer_api_key, no _peer_timeout, and no connectivity test — the peer will fail at runtime.

RIGHT — test connectivity before registering
1. Use fetch to hit https://example.com/wp-json/mcp-ai/v1/health
2. Obtain credential token from the remote site
3. create_item with _peer_api_key, _peer_timeout, and _peer_retry_policy

WRONG — passing tools as a comma-separated string
{ "fields": { "_assistant_enabled_tools": "web_search, woo_products" } }
// The tools field is parsed as a JSON array. Strings break tool resolution.

RIGHT — pass tools as a JSON array
{ "fields": { "_assistant_enabled_tools": ["web_search", "woo_products"] } }

WRONG — deleting an assistant instead of deactivating
{ "action": "delete_item", "post_type": "mcp_ai_assistant", "item_id": 42 }
// Permanent data loss. Chat history and configurations are gone.

RIGHT — deactivate to preserve configuration
{ "action": "update_item", "post_type": "mcp_ai_assistant", "item_id": 42,
  "fields": { "_assistant_status": "inactive" } }

WRONG — using a model name that doesn't match the provider
{ "fields": { "_assistant_provider": "gemini", "_assistant_model": "gpt-4o" } }
// Gemini cannot use GPT models. Provider and model must be compatible.

WRONG — setting _peer_url without https:// prefix
{ "fields": { "_peer_url": "partner-site.com" } }
// The mesh layer expects a full URL with scheme for HTTP client construction.
```

## Cross-References

- Run `mcp-ai-wpoos-plugin` for assistant credential token generation and MCP bridge setup.
- Run `design-pro-workflow-builder` to create workflows that chain multiple assistants via mesh routing.
- Run `design-pro-schedule-manager` to schedule recurring assistant health checks and peer audits.
- Run `design-vault` to securely store and retrieve peer API keys and credential tokens.
- Run `design-team-management` to assign assistants to specific teams or roles.
- Run `design-crm` if the assistant is used for CRM automation (lead scoring, deal tracking).
- Run `design-project-management` if the assistant manages projects, tasks, or sprints.
- Run `wp-security-audit` on any assistant-facing endpoint — exposed assistants are attack surfaces.
- Run `wp-security-secrets` to audit peer credential storage and API key handling.

## What This Skill Does NOT Cover

- MCP bridge tool calling mechanics — use `mcp-ai-wpoos-plugin` for tool invocation and JSON-RPC.
- Chat UI configuration (shortcodes, Elementor widgets) — use the plugin's chat UI documentation.
- Provider API key management — use the NV oOS Settings page or `design-vault` for secure storage.
- Vector store file upload and management — use `analyze_file_suitability` and the Files API tools.
- Agent memory system internals — use `recall_memory`, `retrieve_agent_memory`, `semantic_context_search`.
- Workflow builder orchestration — use `design-pro-workflow-builder` for DAG-based agent pipelines.
- Peer-to-peer protocol details (SSE, message format) — this skill covers the *configuration* of peers, not the wire protocol.
- Multi-site WordPress network administration — use `nv_oos_console_agent_remote_wp_connection` for remote site management.

## References

- NV oOS Pro Toolkit AI Assistant admin page: `/wp-admin/admin.php?page=nvoos-pro-toolkit-ai-assistant`
- Mesh Network admin page: `/wp-admin/admin.php?page=nvoos-pro-toolkit-mesh`
- `toolkit_cpt` MCP tool — the primary interface for all assistant and peer operations.
- `query_remote_site` MCP tool — send prompts to mesh network peers.
- `list_available_models` MCP tool — discover available OpenAI models for assistant configuration.
- `list_mcp_tools` MCP tool — discover available tools to assign to assistants.
