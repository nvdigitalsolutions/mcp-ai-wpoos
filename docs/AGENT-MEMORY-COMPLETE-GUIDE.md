# Agent Memory Management - Complete Guide

**Version:** 1.1.0  
**Date:** February 18, 2026  
**Status:** Production Ready

## Table of Contents

1. [Overview](#overview)
2. [Complete CRUD Operations](#complete-crud-operations)
3. [Batch Operations](#batch-operations)
4. [Audit Trail & Versioning](#audit-trail--versioning)
5. [Industry Best Practices](#industry-best-practices)
6. [API Reference](#api-reference)
7. [Examples](#examples)
8. [Best Practices](#best-practices)

---

## Overview

The Agent Memory Management system now provides enterprise-grade capabilities for managing AI agent memory/context with complete CRUD operations, batch processing, versioning, and audit trails.

### Key Capabilities

- **Full CRUD**: Create, Read, Update, Delete individual memories
- **Batch Operations**: Bulk operations on multiple memories
- **Versioning**: Track all changes with version history
- **Audit Trail**: Complete compliance-ready change tracking
- **Export/Import**: Backup and restore memory data
- **Tag Management**: Organize memories with tags
- **Health Monitoring**: Track memory usage and patterns

---

## Complete CRUD Operations

### Create (Store Memory)

**Tool:** `store_agent_context`

```json
{
  "agent_id": 123,
  "context_type": "learning",
  "context_data": {
    "title": "Machine Learning Best Practices",
    "content": "Key insights about ML optimization...",
    "importance": "high",
    "tags": ["ml", "optimization"],
    "metadata": {
      "source": "research",
      "confidence": 0.95
    }
  },
  "ttl": 2592000
}
```

### Read (Retrieve Memory)

**Tool:** `retrieve_agent_memory`

```json
{
  "agent_id": 123,
  "query": "machine learning",
  "filters": {
    "importance": ["high", "critical"],
    "context_types": ["learning", "insight"]
  },
  "limit": 10
}
```

### Update (Edit Memory)

**Tool:** `manage_context_lifecycle` with action `update`

```json
{
  "action": "update",
  "agent_id": 123,
  "context_id": "ctx_abc123",
  "options": {
    "update_data": {
      "title": "Updated Title",
      "content": "Updated content...",
      "importance": "critical",
      "tags": ["ml", "optimization", "production"],
      "metadata": {
        "reviewed": true,
        "reviewer": "expert_ai"
      }
    }
  }
}
```

**Features:**
- Selective field updates (only update what you specify)
- Metadata merging (new metadata merges with existing)
- Update tracking (last_updated timestamp, update_count)
- Index synchronization (automatic index updates)

### Delete (Remove Memory)

**Tool:** `manage_context_lifecycle` with action `delete`

```json
{
  "action": "delete",
  "agent_id": 123,
  "context_id": "ctx_abc123"
}
```

**Features:**
- Safe deletion with verification
- Automatic index cleanup
- Returns deleted context info for confirmation
- Checks expiration status

---

## Batch Operations

**Tool:** `batch_manage_memory`

### Bulk Update

Update multiple memories at once:

```json
{
  "action": "bulk_update",
  "agent_id": 123,
  "context_ids": ["ctx_1", "ctx_2", "ctx_3"],
  "updates": {
    "importance": "high",
    "add_tags": ["reviewed", "production"],
    "metadata": {
      "batch_reviewed": true,
      "review_date": "2026-02-18"
    }
  },
  "options": {
    "dry_run": false
  }
}
```

**Or use filters instead of context_ids:**

```json
{
  "action": "bulk_update",
  "agent_id": 123,
  "filters": {
    "context_types": ["learning"],
    "tags": ["ml"],
    "importance": ["medium"]
  },
  "updates": {
    "importance": "high"
  }
}
```

### Bulk Delete

Delete multiple memories matching criteria:

```json
{
  "action": "bulk_delete",
  "agent_id": 123,
  "filters": {
    "context_types": ["note"],
    "tags": ["temporary"]
  },
  "options": {
    "dry_run": true
  }
}
```

### Export Memories

Export to JSON for backup:

```json
{
  "action": "export",
  "agent_id": 123,
  "filters": {
    "importance": ["high", "critical"]
  }
}
```

**Response includes:**
```json
{
  "success": true,
  "export_data": "{\"export_version\":\"1.0\",\"agent_id\":123,...}",
  "context_count": 45
}
```

### Import Memories

Restore from JSON backup:

```json
{
  "action": "import",
  "agent_id": 123,
  "export_data": "{\"export_version\":\"1.0\",...}",
  "options": {
    "dry_run": false
  }
}
```

### Tag Management

**Add Tags:**
```json
{
  "action": "tag_add",
  "agent_id": 123,
  "context_ids": ["ctx_1", "ctx_2"],
  "tags": ["important", "reviewed"]
}
```

**Remove Tags:**
```json
{
  "action": "tag_remove",
  "agent_id": 123,
  "filters": {
    "tags": ["temporary"]
  },
  "tags": ["temporary", "draft"]
}
```

**Replace Tags:**
```json
{
  "action": "tag_replace",
  "agent_id": 123,
  "context_ids": ["ctx_1"],
  "tags": ["production", "reviewed", "final"]
}
```

### Dry Run Mode

Preview changes before applying:

```json
{
  "action": "bulk_update",
  "agent_id": 123,
  "filters": {...},
  "updates": {...},
  "options": {
    "dry_run": true
  }
}
```

**Response shows what would change without actually modifying data.**

---

## Audit Trail & Versioning

**Tool:** `memory_audit_trail`

### Version History

Get all versions of a memory:

```json
{
  "action": "get_history",
  "agent_id": 123,
  "context_id": "ctx_abc123",
  "options": {
    "limit": 50
  }
}
```

**Response:**
```json
{
  "success": true,
  "versions": {
    "1": {
      "version": 1,
      "data": {...},
      "change_type": "create",
      "timestamp": "2026-02-15 10:00:00"
    },
    "2": {
      "version": 2,
      "data": {...},
      "change_type": "update",
      "timestamp": "2026-02-16 14:30:00"
    }
  },
  "total_versions": 2
}
```

### Compare Versions

See what changed between versions:

```json
{
  "action": "compare_versions",
  "agent_id": 123,
  "context_id": "ctx_abc123",
  "versions": {
    "from": 1,
    "to": 2
  }
}
```

**Response:**
```json
{
  "success": true,
  "differences": {
    "added": {},
    "removed": {},
    "modified": {
      "importance": {
        "from": "medium",
        "to": "high"
      },
      "tags": {
        "from": ["ml"],
        "to": ["ml", "production"]
      }
    }
  }
}
```

### Rollback to Previous Version

Restore a previous version:

```json
{
  "action": "rollback",
  "agent_id": 123,
  "context_id": "ctx_abc123",
  "version": 1
}
```

**Features:**
- Saves current state before rollback
- Adds rollback metadata
- Creates new version entry
- Logs in audit trail

### Audit Log

Get complete change log:

```json
{
  "action": "get_audit_log",
  "agent_id": 123,
  "options": {
    "limit": 100,
    "date_from": "2026-02-01",
    "date_to": "2026-02-18",
    "action_type": "update"
  }
}
```

**Response:**
```json
{
  "success": true,
  "entries": [
    {
      "context_id": "ctx_abc123",
      "action": "update",
      "metadata": {...},
      "timestamp": "2026-02-18 10:00:00",
      "user_id": 1
    }
  ],
  "total_entries": 45
}
```

### Audit Statistics

Get usage analytics:

```json
{
  "action": "get_stats",
  "agent_id": 123
}
```

**Response:**
```json
{
  "success": true,
  "stats": {
    "total_events": 250,
    "by_action": {
      "create": 50,
      "update": 120,
      "delete": 30,
      "access": 50
    },
    "by_hour": {...},
    "recent_24h": 45,
    "most_active_context": {
      "context_id": "ctx_abc123",
      "events": 25
    }
  }
}
```

---

## Industry Best Practices

This implementation follows 2025-2026 RAG best practices:

### 1. Hierarchical Memory Architecture ✅

- **Short-term memory**: Session-based with transients
- **Long-term memory**: Persistent with configurable TTL
- **Context compression**: TTL-aware compression policies
- **Prioritization**: Multi-factor scoring (recency, frequency, importance)

### 2. Observability & Monitoring ✅

- **Audit trail**: Every change tracked with user and timestamp
- **Version history**: Full history with 100-version retention
- **Health metrics**: Usage patterns, access frequency, expiration tracking
- **Analytics**: Statistics on memory usage and patterns

### 3. Batch Operations ✅

- **Bulk updates**: Efficient processing of multiple memories
- **Dry-run mode**: Safe preview before applying changes
- **Export/import**: Backup and disaster recovery
- **Tag management**: Organize and categorize at scale

### 4. Security & Governance ✅

- **Audit logging**: Compliance-ready change tracking
- **Version control**: Rollback capability for data integrity
- **Access tracking**: Who accessed what and when
- **Data validation**: Input sanitization and validation

### 5. Production-Ready ✅

- **Transient storage**: WordPress built-in caching with auto-cleanup
- **Error handling**: Comprehensive error messages and recovery
- **Index consistency**: Automatic synchronization
- **Performance**: Optimized queries and caching

---

## API Reference

### manage_context_lifecycle

Enhanced with new actions:

| Action | Description | Required Parameters |
|--------|-------------|---------------------|
| `refresh` | Update TTL | context_id, options.new_ttl |
| `compress` | Apply compression | context_id |
| `merge` | Combine contexts | context_ids, options.merge_title |
| `analyze` | Health metrics | agent_id |
| `prune` | Remove unused | options.prune_threshold |
| **`update`** | **Edit memory** | **context_id, options.update_data** |
| **`delete`** | **Remove memory** | **context_id** |

### batch_manage_memory

| Action | Description | Parameters |
|--------|-------------|------------|
| `bulk_update` | Update multiple | context_ids/filters, updates |
| `bulk_delete` | Delete multiple | context_ids/filters |
| `export` | Export to JSON | filters (optional) |
| `import` | Import from JSON | export_data |
| `tag_add` | Add tags | context_ids/filters, tags |
| `tag_remove` | Remove tags | context_ids/filters, tags |
| `tag_replace` | Replace tags | context_ids/filters, tags |

### memory_audit_trail

| Action | Description | Parameters |
|--------|-------------|------------|
| `get_history` | Version history | context_id, options.limit |
| `compare_versions` | Diff versions | context_id, versions |
| `rollback` | Restore version | context_id, version |
| `get_audit_log` | Change log | options (filters) |
| `get_stats` | Analytics | agent_id |

---

## Examples

### Example 1: Update and Track Changes

```javascript
// 1. Update memory
const updateResult = await manage_context_lifecycle({
  action: 'update',
  agent_id: 123,
  context_id: 'ctx_abc123',
  options: {
    update_data: {
      importance: 'critical',
      tags: ['production', 'reviewed']
    }
  }
});

// 2. View version history
const history = await memory_audit_trail({
  action: 'get_history',
  agent_id: 123,
  context_id: 'ctx_abc123'
});

// 3. Check audit log
const auditLog = await memory_audit_trail({
  action: 'get_audit_log',
  agent_id: 123,
  options: {
    action_type: 'update',
    limit: 10
  }
});
```

### Example 2: Batch Operations Workflow

```javascript
// 1. Preview changes (dry run)
const preview = await batch_manage_memory({
  action: 'bulk_update',
  agent_id: 123,
  filters: {
    tags: ['draft']
  },
  updates: {
    importance: 'high',
    add_tags: ['ready-for-review']
  },
  options: {
    dry_run: true
  }
});

// 2. Apply changes
if (preview.success && preview.updated_count > 0) {
  const result = await batch_manage_memory({
    ...preview, // Same parameters
    options: {
      dry_run: false
    }
  });
}

// 3. Export updated memories
const backup = await batch_manage_memory({
  action: 'export',
  agent_id: 123,
  filters: {
    tags: ['ready-for-review']
  }
});

// Save export_data to file
fs.writeFileSync('memories_backup.json', backup.export_data);
```

### Example 3: Version Management

```javascript
// 1. Make updates
await manage_context_lifecycle({
  action: 'update',
  agent_id: 123,
  context_id: 'ctx_abc123',
  options: {
    update_data: {
      content: 'Updated content v1'
    }
  }
});

// Later, make more updates
await manage_context_lifecycle({
  action: 'update',
  agent_id: 123,
  context_id: 'ctx_abc123',
  options: {
    update_data: {
      content: 'Updated content v2'
    }
  }
});

// 2. Compare versions
const diff = await memory_audit_trail({
  action: 'compare_versions',
  agent_id: 123,
  context_id: 'ctx_abc123',
  versions: {
    from: 1,
    to: 2
  }
});

// 3. Rollback if needed
if (needsRollback) {
  await memory_audit_trail({
    action: 'rollback',
    agent_id: 123,
    context_id: 'ctx_abc123',
    version: 1
  });
}
```

---

## Best Practices

### 1. Memory Management

- **Use appropriate TTL**: 7-90 days based on importance
- **Set importance levels**: Critical > High > Medium > Low
- **Tag consistently**: Use standardized tag taxonomy
- **Regular cleanup**: Use prune action monthly

### 2. Batch Operations

- **Always dry-run first**: Preview changes before applying
- **Use filters effectively**: Target specific memories precisely
- **Export regularly**: Backup critical memories
- **Monitor performance**: Limit batch sizes to 100-500

### 3. Versioning & Audit

- **Keep version history**: Useful for debugging and compliance
- **Monitor audit logs**: Track unusual patterns
- **Use rollback sparingly**: Only when necessary
- **Archive old versions**: Export and store externally if needed

### 4. Performance

- **Limit results**: Use pagination (limit parameter)
- **Filter strategically**: Reduce unnecessary data retrieval
- **Cache exports**: Reuse export data when possible
- **Monitor health**: Check health metrics regularly

### 5. Security

- **Sanitize inputs**: All data is validated and sanitized
- **Check permissions**: Ensure proper authorization
- **Audit access**: Track who accesses what
- **Review changes**: Periodic audit log reviews

---

## Troubleshooting

### Memory Not Updating

**Cause**: Context has expired  
**Solution**: Check TTL and refresh if needed

### Batch Operation Fails

**Cause**: No contexts match filters  
**Solution**: Verify filters with retrieve_agent_memory first

### Version History Missing

**Cause**: Version history has 1-year TTL  
**Solution**: Export important histories regularly

### Audit Log Incomplete

**Cause**: Limited to 1000 entries  
**Solution**: Export audit logs periodically

---

## Migration Guide

### From Basic Memory to Enhanced System

1. **No changes needed**: Existing memories work automatically
2. **Optional**: Add versioning by making updates
3. **Recommended**: Export existing memories for backup
4. **Best practice**: Start using tags for organization

---

## Support

- **Documentation**: See `/docs/RAG-ENHANCED-MEMORY-MANAGEMENT.md`
- **Tool Reference**: See `/docs/tool-reference.md`
- **API Reference**: See `/docs/rest-api.md`
- **Issues**: GitHub Issues

---

**Last Updated:** February 18, 2026  
**Version:** 1.1.0  
**Status:** Production Ready
