# Phase 5 Quick Reference: State Management & Memory

**Last Updated:** January 29, 2026  
**Phase Status:** ✅ Complete (100%)

---

## Overview

Phase 5 provides AI agents with persistent memory and intelligent context management:
- **3 Tools**: `store_agent_context`, `retrieve_agent_memory`, `prioritize_context`
- **1 Service**: `WP_MCP_AI_Agent_Context_Manager`
- **Storage**: WordPress transients (1 hour - 1 year TTL)
- **Automatic**: Daily pruning via WP Cron

---

## Quick Tool Usage

### 1. Store Agent Context

Store important information for future retrieval:

```json
{
  "tool": "store_agent_context",
  "arguments": {
    "agent_id": 123,
    "context_type": "learning",
    "context_data": {
      "title": "User prefers bullet points",
      "content": "User consistently requests concise, bulleted responses instead of long paragraphs.",
      "importance": "high",
      "tags": ["preference", "communication"]
    },
    "ttl": 2592000
  }
}
```

**Context Types:**
- `learning` - Knowledge gained from interactions
- `fact` - Factual information
- `preference` - User preferences
- `pattern` - Recurring patterns
- `workflow` - Process workflows
- `decision` - Important decisions made
- `result` - Task results
- `insight` - Insights discovered
- `note` - General notes
- `generic` - Uncategorized

**Importance Levels:**
- `critical` - Must always be remembered
- `high` - Very important
- `medium` - Moderately important (default)
- `low` - Nice to have

**TTL Options:**
- `3600` - 1 hour (minimum)
- `86400` - 1 day
- `604800` - 1 week
- `2592000` - 30 days (recommended)
- `31536000` - 1 year (maximum)

### 2. Retrieve Agent Memory

Search and retrieve stored contexts:

```json
{
  "tool": "retrieve_agent_memory",
  "arguments": {
    "agent_id": 123,
    "query": "user communication preferences",
    "filters": {
      "context_types": ["learning", "preference"],
      "importance": ["high", "critical"],
      "after_date": "2026-01-01"
    },
    "limit": 10
  }
}
```

**Filters:**
- `context_types` - Array of context types
- `tags` - Array of tags (any match)
- `importance` - Array of importance levels
- `after_date` - YYYY-MM-DD format
- `before_date` - YYYY-MM-DD format

**Response:**
```json
{
  "success": true,
  "contexts": [
    {
      "context_id": "ctx_abc123xyz",
      "context_type": "learning",
      "title": "User prefers bullet points",
      "content": "...",
      "importance": "high",
      "tags": ["preference", "communication"],
      "relevance_score": 0.85,
      "stored_at": "2026-01-15 10:30:00"
    }
  ],
  "count": 5
}
```

### 3. Prioritize Context

Select optimal contexts within token budget:

```json
{
  "tool": "prioritize_context",
  "arguments": {
    "context_items": [...],
    "token_budget": 5000,
    "current_task": {
      "query": "analyze customer satisfaction",
      "keywords": ["satisfaction", "customer", "feedback"],
      "type": "analysis"
    },
    "strategy": "balanced"
  }
}
```

**Strategies:**
- `balanced` - Equal weights (40% relevance, 40% importance, 20% recency)
- `relevance` - Focus on task match (70% relevance, 20% importance, 10% recency)
- `importance` - Focus on critical contexts (20% relevance, 70% importance, 10% recency)
- `recency` - Focus on recent contexts (20% relevance, 20% importance, 60% recency)

**Custom Weights:**
```json
{
  "weights": {
    "relevance": 0.5,
    "importance": 0.3,
    "recency": 0.2
  }
}
```

**Response:**
```json
{
  "success": true,
  "prioritized": [...],
  "count": 12,
  "total_tokens": 4800,
  "budget": 5000,
  "budget_used_pct": 96.0,
  "excluded_count": 8
}
```

---

## Service API (PHP)

### Get Service Instance

```php
$manager = wp_mcp_ai_get_agent_context_manager();
```

### Store Context

```php
$result = $manager->store_context(
    $agent_id = 123,
    $context_type = 'learning',
    $context_data = array(
        'title' => 'User prefers concise answers',
        'content' => '...',
        'importance' => 'high',
        'tags' => array('preference')
    ),
    $ttl = DAY_IN_SECONDS * 30
);
```

### Retrieve Context

```php
$context = $manager->retrieve_context($agent_id, $context_id);
```

### Search Contexts

```php
$contexts = $manager->search_contexts(
    $agent_id,
    $filters = array('context_types' => array('learning')),
    $limit = 10
);
```

### Recover Session

```php
$session = $manager->recover_session($agent_id, $session_id);
// Returns: array with 'contexts', 'contexts_by_type', 'context_count'
```

### Get Statistics

```php
$stats = $manager->get_context_stats($agent_id);
// Returns: total_count, by_type, by_importance, expired_count
```

### Clear All Contexts

```php
$result = $manager->clear_agent_contexts($agent_id);
// Returns: array('success' => true, 'deleted' => 25)
```

### Prioritize Contexts

```php
$prioritized = $manager->prioritize_context(
    $context_items,
    $token_budget = 5000,
    $current_task = array('query' => '...'),
    $weights = array()
);
```

---

## Common Workflows

### Workflow 1: Learning User Preferences

**Step 1:** AI recognizes pattern in user behavior
```json
{
  "tool": "store_agent_context",
  "arguments": {
    "agent_id": 123,
    "context_type": "learning",
    "context_data": {
      "title": "User prefers code examples",
      "content": "User repeatedly asks for code examples when discussing programming concepts.",
      "importance": "high",
      "tags": ["preference", "code", "teaching"]
    }
  }
}
```

**Step 2:** AI retrieves preference in future interactions
```json
{
  "tool": "retrieve_agent_memory",
  "arguments": {
    "agent_id": 123,
    "query": "how to explain programming",
    "filters": {
      "tags": ["teaching", "code"]
    }
  }
}
```

**Result:** AI provides code examples automatically

### Workflow 2: Managing Token Budget

**Step 1:** Retrieve all potentially relevant contexts
```json
{
  "tool": "retrieve_agent_memory",
  "arguments": {
    "agent_id": 123,
    "filters": {
      "importance": ["high", "critical"]
    },
    "limit": 50
  }
}
```

**Step 2:** Prioritize within token budget
```json
{
  "tool": "prioritize_context",
  "arguments": {
    "context_items": [...50 contexts...],
    "token_budget": 4000,
    "current_task": {
      "query": "help user with task",
      "type": "assistance"
    },
    "strategy": "balanced"
  }
}
```

**Result:** Top 15 most relevant contexts selected

### Workflow 3: Session Continuity

**User leaves and returns 3 days later:**

```php
// Recover all stored context
$manager = wp_mcp_ai_get_agent_context_manager();
$session = $manager->recover_session($agent_id = 123);

// AI now has access to:
// - Previous learnings
// - User preferences  
// - Important facts
// - Workflow patterns
```

**Result:** AI remembers previous conversations and preferences

---

## Best Practices

### 1. Choose Appropriate Context Types
- Use `learning` for patterns you discover
- Use `preference` for user choices
- Use `fact` for objective information
- Use `workflow` for processes

### 2. Set Proper Importance
- `critical` - Essential information (e.g., safety preferences)
- `high` - Very important (e.g., communication style)
- `medium` - Useful to remember (default)
- `low` - Nice to have (e.g., minor preferences)

### 3. Use Tags Effectively
- Add multiple relevant tags
- Keep tags short and descriptive
- Use consistent tag names
- Examples: `preference`, `technical`, `business`, `urgent`

### 4. Set Appropriate TTL
- Short-term (1-7 days): Temporary facts, session-specific info
- Medium-term (30 days): User preferences, recent learnings
- Long-term (1 year): Critical preferences, important facts

### 5. Leverage Prioritization
- Always use `prioritize_context` when you have many contexts
- Match strategy to task type:
  - `relevance` for specific tasks
  - `importance` for critical operations
  - `recency` for evolving situations
  - `balanced` for general use

### 6. Query Effectively
- Use specific keywords in queries
- Provide context_types filters when you know what you need
- Use importance filters to focus on critical info
- Combine filters for precise results

---

## Debugging & Monitoring

### Check Context Statistics

```php
$stats = wp_mcp_ai_get_agent_context_manager()->get_context_stats(123);
print_r($stats);
```

### View All Contexts for Agent

```php
$contexts = wp_mcp_ai_get_agent_context_manager()->search_contexts(
    $agent_id = 123,
    $filters = array(),
    $limit = 50,
    $include_expired = true
);
```

### Check Cron Status

```php
// Check if pruning is scheduled
$timestamp = wp_next_scheduled('wp_mcp_ai_prune_expired_contexts');
echo "Next prune: " . date('Y-m-d H:i:s', $timestamp);

// Manually trigger pruning
do_action('wp_mcp_ai_prune_expired_contexts');
```

### Clear Test Data

```php
$manager = wp_mcp_ai_get_agent_context_manager();
$result = $manager->clear_agent_contexts($agent_id = 123);
echo "Deleted {$result['deleted']} contexts";
```

---

## Troubleshooting

### Issue: Contexts Not Being Retrieved

**Possible Causes:**
1. Contexts expired (check TTL)
2. Wrong agent_id
3. Filters too restrictive

**Solution:**
```php
// Check if contexts exist
$stats = $manager->get_context_stats($agent_id);
if ($stats['total_count'] === 0) {
    echo "No contexts found for this agent\n";
}

// Try with expired contexts included
$contexts = $manager->search_contexts(
    $agent_id,
    array(),
    50,
    $include_expired = true
);
```

### Issue: Prioritization Returns Too Few Contexts

**Possible Causes:**
1. Token budget too small
2. Contexts have large content
3. Strategy not optimal for task

**Solution:**
```json
{
  "token_budget": 10000,
  "strategy": "importance"
}
```

### Issue: Cron Not Running

**Solution:**
```php
// Check WordPress cron status
if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
    echo "WordPress cron is disabled\n";
    echo "Set up system cron to call wp-cron.php\n";
}

// Manually run pruning
WP_MCP_AI_Agent_Context_Manager::get_instance()->prune_expired_contexts();
```

---

## Performance Tips

### 1. Limit Context Count
Keep active contexts under 100 per agent for optimal performance.

### 2. Use Filters
Always use filters when retrieving contexts to reduce processing.

### 3. Set Appropriate TTL
Don't set unnecessarily long TTLs. Use 30 days as default.

### 4. Leverage Prioritization
Use `prioritize_context` to reduce token usage and improve relevance.

### 5. Monitor Expired Contexts
Check stats periodically and manually prune if needed:
```php
$stats = $manager->get_context_stats($agent_id);
if ($stats['expired_count'] > 10) {
    $manager->prune_expired_contexts();
}
```

---

## Related Documentation

- **Complete Implementation:** `docs/proposals/PHASE-5-COMPLETION-REPORT.md`
- **Phase 5 Proposal:** `docs/proposals/DEEPSEEK-V4-ORCHESTRATION-ENHANCEMENTS.md` (lines 879-1064)
- **Overall Status:** `docs/proposals/DEEPSEEK-V4-STATUS-AND-ROADMAP.md`
- **Tool Reference:** `docs/tool-reference.md` (search for "store_agent_context", "retrieve_agent_memory", "prioritize_context")

---

**Version:** 1.0  
**Date:** January 29, 2026  
**Status:** ✅ Production Ready
