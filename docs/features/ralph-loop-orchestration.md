# Ralph Loop CCT Migration & Orchestration

> Circuit breaker, execution logger, and CCT migration tools for safe cross-environment JetEngine data operations.

## Overview

The Ralph Loop orchestration tools provide a robust framework for migrating JetEngine Custom Content Type (CCT) data between WordPress environments with built-in safety mechanisms. The system uses a **circuit breaker** pattern to prevent cascading failures and an **execution logger** for detailed step-by-step tracking.

## Components

### Circuit Breaker

The circuit breaker pattern prevents cascading failures by monitoring operation health and automatically stopping execution when failure thresholds are exceeded.

**Configuration:**
- **Failure threshold** — Number of consecutive failures before opening the circuit (default: 5)
- **Timeout** — How long the circuit stays open before attempting a half-open test (default: 60 seconds)
- **Half-open limit** — Number of test operations allowed in half-open state (default: 1)

**States:**
- **Closed** — Normal operation, requests pass through
- **Open** — Failures exceeded threshold, requests are rejected immediately
- **Half-Open** — Testing if the system has recovered

### Execution Logger

The execution logger provides detailed tracking of every migration step:

- **Step-level tracking** — Each phase of a multi-step operation is logged
- **Timing data** — Start time, end time, and duration for each step
- **Error capture** — Full error details with stack traces
- **State snapshots** — Before/after state comparisons

### CCT Migration Tools

Tools for moving JetEngine CCT data between environments:

- **Schema migration** — Copy CCT structure (fields, types, relationships)
- **Data migration** — Transfer CCT records with field mapping
- **Incremental sync** — Delta-only updates for ongoing replication
- **Dry-run mode** — Preview changes before executing

### Orchestration Tools

Coordination tools for complex multi-step migration workflows:

- **Workflow definition** — Define ordered sequences of migration steps
- **Dependency management** — Ensure prerequisites are met before each step
- **Rollback support** — Undo completed steps on failure
- **Progress tracking** — Real-time status of long-running migrations

## Usage Scenarios

### Development → Staging → Production

```
[Dev Environment] ──(CCT Migration)──▶ [Staging] ──(CCT Migration)──▶ [Production]
                                         │
                                    Circuit Breaker
                                    Execution Logger
```

### Disaster Recovery

```
[Backup CCT Dump] ──(Restore Tool)──▶ [Recovery Environment]
                                         │
                                    Validation Checks
                                    Data Integrity Verification
```

### Multi-site Sync

```
[Site A CCTs] ─┐
[Site B CCTs] ─┼──(Orchestration)──▶ [Central Hub]
[Site C CCTs] ─┘
```

## Safety Features

| Feature | Description |
|---|---|
| **Circuit Breaker** | Prevents cascading failures across migration steps |
| **Dry-Run Mode** | Preview changes without writing data |
| **Rollback** | Revert completed steps on failure |
| **Validation** | Schema and data integrity checks before migration |
| **Rate Limiting** | Controlled throughput to avoid overwhelming target systems |
| **Timeout Protection** | Configurable per-step timeouts |
| **Conflict Detection** | Identify and resolve duplicate records |

## Configuration

```php
// wp-config.php or custom code
define( 'WP_MCP_AI_CIRCUIT_BREAKER_THRESHOLD', 5 );     // failures before open
define( 'WP_MCP_AI_CIRCUIT_BREAKER_TIMEOUT', 60 );       // seconds before half-open
define( 'WP_MCP_AI_MIGRATION_BATCH_SIZE', 100 );         // records per batch
define( 'WP_MCP_AI_MIGRATION_TIMEOUT_PER_STEP', 300 );   // seconds per step
```

## WP-CLI Commands

```bash
# Run a CCT migration
wp mcp-ai migrate cct --source=dev --target=staging --cct=inventory

# Dry-run migration
wp mcp-ai migrate cct --source=dev --target=staging --cct=inventory --dry-run

# Check circuit breaker status
wp mcp-ai circuit-breaker status

# Reset circuit breaker
wp mcp-ai circuit-breaker reset

# View execution log
wp mcp-ai execution-log --migration-id=123
wp mcp-ai execution-log --migration-id=123 --format=json

# Rollback migration
wp mcp-ai migrate rollback --migration-id=123
```

## See Also

- [Sync Log Manager](sync-log-manager.md)
- [JetEngine CCT Documentation](https://crocoblock.com/knowledge-base/features/custom-content-type/)
- [Pro Schedule Manager](pro-schedule-manager.md)
