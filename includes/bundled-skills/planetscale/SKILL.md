---
type: Skill
name: planetscale
description: Design schemas and write queries for PlanetScale serverless MySQL using branch-based workflows. Ensures index coverage, avoids foreign key anti-patterns, and treats every schema change as a reviewable, reversible deploy request.
license: MIT
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# PlanetScale Database Skills

Use this skill for any work involving PlanetScale (or MySQL-compatible / Postgres databases) to ensure schemas scale, queries use indexes, and every change goes through a safe branching workflow.

## Prerequisites

```bash
# Install PlanetScale CLI
brew install planetscale/tap/pscale   # macOS
# or: https://github.com/planetscale/cli#installation

# Authenticate
pscale auth login
```

Install the agent skill:

```bash
npx skills add planetscale/agent-skill
```

## Core Workflow: Branch Every Change

PlanetScale's branching model maps directly to git. Never modify the production schema directly.

```bash
# 1. Create a branch for the feature
pscale branch create mydb add-user-prefs

# 2. Connect to the branch
pscale connect mydb add-user-prefs --port 3309

# 3. Apply schema changes (via your ORM or raw SQL)
mysql -h 127.0.0.1 -P 3309 -u root < migration.sql

# 4. Open a deploy request
pscale deploy-request create mydb add-user-prefs

# 5. Review and merge in the PlanetScale dashboard (or CLI)
pscale deploy-request deploy mydb <deploy-request-number>
```

## Schema Design Principles

### Always include these on new tables

```sql
CREATE TABLE user_preferences (
  id          VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
  user_id     VARCHAR(36) NOT NULL,
  theme       ENUM('light', 'dark', 'system') DEFAULT 'system',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_id (user_id)   -- index every foreign-key-like column
);
```

### PlanetScale conventions

- **No foreign key constraints** — PlanetScale disables them for horizontal scale. Use application-level integrity instead.
- **VARCHAR(36) UUIDs** — prefer over auto-increment INT for distributed systems
- **Always index** columns used in WHERE, JOIN ON, or ORDER BY clauses
- Use `ENUM` for small, stable value sets (avoids join tables for simple statuses)

## Index-Aware Query Writing

Before writing a query, identify the WHERE and ORDER BY columns and ensure a covering index exists.

```sql
-- BAD: full table scan at scale
SELECT * FROM orders WHERE status = 'pending' AND created_at > '2026-01-01';

-- GOOD: only needed columns, index covers the filter
SELECT id, user_id, total, created_at
FROM orders
WHERE status = 'pending'
  AND created_at > '2026-01-01';

-- Required index
ALTER TABLE orders ADD INDEX idx_status_created (status, created_at);
```

**Always explain**: after creating a query, state the index it relies on and estimated impact at scale.

## Query Checklist

After writing any query:

- [ ] Does `SELECT *` appear? Replace with specific columns
- [ ] Does the WHERE clause have an index? Create one if not
- [ ] Is there an ORDER BY without a matching index? Add one
- [ ] Is this a JOIN? Ensure both join columns are indexed
- [ ] Could this return millions of rows? Add LIMIT or pagination

## Branching Cheat Sheet

```bash
pscale branch list mydb                         # List branches
pscale branch create mydb <branch-name>         # Create branch
pscale branch delete mydb <branch-name>         # Delete branch
pscale connect mydb <branch-name> --port 3309  # Local connection
pscale deploy-request create mydb <branch>      # Open deploy request
pscale deploy-request list mydb                 # List open requests
pscale deploy-request deploy mydb <number>      # Merge to production
```

## Migrations Best Practices

- **One concern per migration** — don't combine unrelated changes
- **Additive first** — add new columns/tables before removing old ones
- **Backfill separately** — data migrations run after schema migrations
- **Test rollback** — ensure dropping the column is safe before merging

## Connection Strings

```bash
# Local development (branch connection)
DATABASE_URL=mysql://root:@127.0.0.1:3309/mydb

# Production (PlanetScale connection string from dashboard)
DATABASE_URL=mysql://username:password@host/mydb?ssl={"rejectUnauthorized":true}
```

## Tips

- Estimate query time at scale (e.g., "~2ms with index vs ~8s without at 10M rows")
- Use `EXPLAIN` to verify index usage before committing a query
- In PlanetScale, schema changes are non-blocking (online DDL) — still test on a branch first
- Set up Insights in the PlanetScale dashboard to catch slow queries in production
