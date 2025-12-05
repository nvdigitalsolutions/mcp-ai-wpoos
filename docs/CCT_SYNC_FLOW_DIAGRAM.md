# CCT Sync Flow - Before and After Fix

## BEFORE FIX ❌

```
WordPress Admin
    │
    ├─> Add New Assistant (auto-draft created)
    │       │
    │       ├─> save_post hook fires
    │       │       │
    │       │       └─> sync_to_cct() ❌ SYNCS AUTO-DRAFT
    │       │               │
    │       │               └─> CCT Item Created (UNWANTED!)
    │       │
    │       └─> User fills in fields, saves as draft
    │               │
    │               └─> save_post hook fires
    │                       │
    │                       └─> sync_to_cct() ❌ SYNCS DRAFT
    │                               │
    │                               └─> CCT Item Created/Updated (UNWANTED!)
    │
    └─> User publishes assistant
            │
            └─> save_post hook fires
                    │
                    └─> sync_to_cct() ✅ SYNCS PUBLISHED
                            │
                            └─> CCT Item Updated (CORRECT!)

Result: CCT contains auto-drafts, drafts, AND published items 😞
```

## AFTER FIX ✅

```
WordPress Admin
    │
    ├─> Add New Assistant (auto-draft created)
    │       │
    │       ├─> save_post hook fires
    │       │       │
    │       │       └─> sync_to_cct() checks status
    │       │               │
    │       │               ├─> Status: auto-draft ❌
    │       │               └─> SKIP SYNC + delete any existing CCT item
    │       │
    │       └─> User fills in fields, saves as draft
    │               │
    │               └─> save_post hook fires
    │                       │
    │                       └─> sync_to_cct() checks status
    │                               │
    │                               ├─> Status: draft ❌
    │                               └─> SKIP SYNC + delete any existing CCT item
    │
    └─> User publishes assistant
            │
            ├─> save_post hook fires
            │       │
            │       └─> sync_to_cct() checks status
            │               │
            │               ├─> Status: publish ✅
            │               └─> SYNC TO CCT ✓
            │                       │
            │                       └─> CCT Item Created/Updated
            │
            └─> Later: User unpublishes (publish → draft)
                    │
                    └─> transition_post_status hook fires
                            │
                            └─> handle_post_status_transition()
                                    │
                                    ├─> Old status: publish, New status: draft
                                    └─> DELETE CCT ITEM ✓

Result: CCT contains ONLY published items 😊
```

## Status Handling Matrix

| Post Status | Sync to CCT? | Delete CCT? | Notes |
|------------|-------------|-------------|-------|
| auto-draft | ❌ No | ✅ Yes | Temporary post, WordPress creates automatically |
| draft | ❌ No | ✅ Yes | Work in progress, not ready for public |
| pending | ❌ No | ✅ Yes | Awaiting review |
| private | ❌ No | ✅ Yes | Private content |
| publish | ✅ Yes | ❌ No | **ONLY** published items sync to CCT |
| trash | ❌ No | ✅ Yes | Deleted content |
| future | ❌ No | ✅ Yes | Scheduled posts |

## Transition Scenarios

### Scenario 1: Publishing a Draft
```
Draft (no CCT item)
    └─> Publish (CCT item created) ✅
```

### Scenario 2: Unpublishing
```
Published (has CCT item)
    └─> Draft (CCT item deleted) ✅
```

### Scenario 3: Trashing Published
```
Published (has CCT item)
    └─> Trash (CCT item deleted) ✅
```

### Scenario 4: Draft to Pending
```
Draft (no CCT item)
    └─> Pending (still no CCT item) ✅
```

## Cleanup Process

```
┌─────────────────────────────────────────┐
│ wp mcp-ai cleanup-cct                   │
└─────────────────┬───────────────────────┘
                  │
                  ▼
    ┌─────────────────────────────────────┐
    │ Find all assistant posts with       │
    │ '_wp_mcp_ai_cct_item_id' meta       │
    └─────────────┬───────────────────────┘
                  │
                  ▼
    ┌─────────────────────────────────────┐
    │ For each post:                      │
    │   - Check post_status               │
    │   - If NOT 'publish':               │
    │     • Delete CCT item               │
    │     • Remove meta link              │
    │     • Increment counter             │
    └─────────────┬───────────────────────┘
                  │
                  ▼
    ┌─────────────────────────────────────┐
    │ Return results:                     │
    │   - cleaned: 5                      │
    │   - errors: []                      │
    └─────────────────────────────────────┘
```

## Key Benefits

1. **Clean Data**: CCT only contains published assistants
2. **Automatic**: No manual cleanup needed after unpublishing
3. **Reliable**: Status transitions are handled by WordPress hooks
4. **Recoverable**: Cleanup utility available if needed
5. **Tested**: Comprehensive test suite validates all scenarios

## Integration Points

### WordPress Hooks Used
- `save_post_mcp_ai_assistant` - Main sync trigger
- `transition_post_status` - Status change detection
- `delete_mcp_ai_assistant` - Cleanup on deletion

### JetEngine Integration
- `WP_MCP_AI_JetEngine_Assistants_CCT::get_item_handler()` - Get CCT handler
- `$handler->update_item()` - Create/update CCT items
- `$handler->delete_item()` - Remove CCT items

### Meta Keys Used
- `_wp_mcp_ai_cct_item_id` - Links CPT post to CCT item
