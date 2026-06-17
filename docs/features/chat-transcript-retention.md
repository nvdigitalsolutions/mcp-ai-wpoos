# Chat Transcript & Agent Memory Retention

> **Feature area:** Data Lifecycle · **Phase:** Complete (v1.1.29)  
> **Scope:** Base (Transcript) + Pro (Memory) · **Related:** `CLAUDE.md` § "Transcript & Memory Retention"

## Overview

NV oOS implements a two-tier data retention system for managing the lifecycle of chat data:

1. **Base — Chat Transcript Retention** (`WP_MCP_AI_Transcript_Retention`): Configurable TTL-based cleanup of stored chat transcripts.
2. **Pro — Agent Memory Retention** (`WP_MCP_AI_Memory_Retention`): Agent memory lifecycle management with pruning and retention windows.

Both systems enforce time-to-live (TTL) cleanup and expose admin settings for configuring retention periods.

## Chat Transcript Retention (Base)

### Configuration

Located at **Settings → NV oOS → Advanced → Data Retention**:

| Setting | Default | Description |
|---------|---------|-------------|
| Enable transcript retention | On | Master toggle |
| Transcript retention period | 30 days | How long to keep chat transcripts |
| Cleanup schedule | Daily | Cron frequency for cleanup runs |
| Exclude assistants | (none) | Assistants whose transcripts are never purged |
| Minimum messages to keep | 10 | Always keep the last N messages per conversation |

### How It Works

1. Chat transcripts are stored in the database (JetEngine CCT or WordPress options, depending on configuration).
2. A daily WordPress cron job scans for transcripts older than the configured retention period.
3. Expired transcripts are permanently deleted (or anonymized if GDPR mode is enabled).
4. A summary log is written to the NV oOS activity log.

### GDPR Considerations

When the site is configured for GDPR compliance:
- Transcripts are anonymized (user IDs removed, IP addresses scrubbed) rather than deleted.
- Anonymized transcripts are retained for the configured period for audit purposes.
- Users can request transcript deletion via the WordPress privacy tools.

## Agent Memory Retention (Pro)

### Configuration

Located at **Settings → NV oOS → Advanced → Memory Retention**:

| Setting | Default | Description |
|---------|---------|-------------|
| Enable memory retention | On | Master toggle |
| Memory retention period | 90 days | How long to keep agent memories |
| Pruning strategy | LRU | Least Recently Used (LRU) or FIFO |
| Maximum memories per agent | 500 | Cap on stored memories per assistant |
| Minimum memory relevance | 0.3 | Prune memories below this relevance score |

### How It Works

1. Agent memories are created when the chat-client memory bridge stores facts, preferences, or context.
2. A weekly cron job evaluates stored memories against retention policies.
3. Memories are pruned based on:
   - **Age:** Older than the configured retention period.
   - **Relevance:** Below the minimum relevance threshold.
   - **Capacity:** When the per-agent memory cap is exceeded (oldest/lowest relevance first).
4. Pruned memories are permanently deleted; no recovery is available.

### Memory Types

| Type | Description | Default Retention |
|------|-------------|-------------------|
| User preferences | Stated likes, dislikes, settings | 90 days |
| Conversation facts | Extracted facts from chat | 30 days |
| Context snapshots | Session context at memory creation | 7 days |
| Tool results cache | Cached tool execution results | 24 hours |
| System observations | Agent self-observations | 60 days |

## Implementation

### Base: `WP_MCP_AI_Transcript_Retention`

**File:** `includes/class-wp-mcp-ai-transcript-retention.php` (437 lines)

```php
class WP_MCP_AI_Transcript_Retention {
    public function init();
    public function get_retention_days();
    public function schedule_cleanup();
    public function run_cleanup();
    public function get_transcript_count();
    public function get_last_cleanup_time();
}
```

### Pro: `WP_MCP_AI_Memory_Retention`

**File:** `addons/pro/includes/class-wp-mcp-ai-memory-retention.php` (358 lines)

```php
class WP_MCP_AI_Memory_Retention {
    public function init();
    public function get_retention_days();
    public function get_pruning_strategy();
    public function get_max_memories_per_agent();
    public function schedule_pruning();
    public function run_pruning();
    public function get_memory_stats();
}
```

## Hooks

| Hook | Type | Description |
|------|------|-------------|
| `wp_mcp_ai_transcript_retention_days` | Filter | Override transcript retention period |
| `wp_mcp_ai_transcript_retention_exclude_assistant` | Filter | Exclude specific assistants from cleanup |
| `wp_mcp_ai_memory_retention_days` | Filter | Override memory retention period |
| `wp_mcp_ai_memory_pruning_strategy` | Filter | Change from LRU to FIFO or custom |
| `wp_mcp_ai_memory_max_per_agent` | Filter | Override per-agent memory cap |
| `wp_mcp_ai_before_transcript_cleanup` | Action | Fires before cleanup run |
| `wp_mcp_ai_after_transcript_cleanup` | Action | Fires after cleanup run |
| `wp_mcp_ai_before_memory_pruning` | Action | Fires before pruning run |
| `wp_mcp_ai_after_memory_pruning` | Action | Fires after pruning run |

## WP-CLI Commands

```bash
# Run transcript cleanup manually
wp mcp-ai retention clean-transcripts

# Run memory pruning manually (Pro only)
wp mcp-ai retention prune-memories

# View retention statistics
wp mcp-ai retention stats

# Change retention period
wp mcp-ai retention set-days --type=transcript --days=60
wp mcp-ai retention set-days --type=memory --days=180
```

## Debugging

Enable `WP_MCP_AI_DEBUG` for verbose logging:

```
[WP_MCP_AI] Transcript retention: 1,247 transcripts checked, 89 expired, 89 deleted
[WP_MCP_AI] Memory retention: 3,402 memories checked, 156 expired, 89 low-relevance, 267 pruned
```

## Related Files

- `includes/class-wp-mcp-ai-transcript-retention.php` — Base transcript retention
- `addons/pro/includes/class-wp-mcp-ai-memory-retention.php` — Pro memory retention
- `docs/features/memory/chat-client-integration.md` — Chat-client memory bridge

## See Also

- [Chat-client Memory Bridge](memory/chat-client-integration.md)
- [WP-CLI Commands](../reference/cli/wp-cli-reference.md)
