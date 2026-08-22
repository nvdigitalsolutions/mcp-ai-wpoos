---
type: Skill
name: gws-tasks
version: 1.0.0
description: Google Tasks skill for managing task lists and tasks via the gws CLI. Covers creating, listing, updating, completing, moving, and deleting tasks and task lists.
license: Apache-2.0
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# Google Tasks (gws-tasks)

> **PREREQUISITE:** Read the `gws-shared` skill for auth, global flags, and security rules.

Manage Google Tasks using the `gws` CLI.

```bash
gws tasks <resource> <method> [flags]
```

## Quick Examples

```bash
# List all task lists
gws tasks tasklists list

# Create a new task list
gws tasks tasklists insert \
  --json '{"title": "Work Tasks"}'

# Get a specific task list
gws tasks tasklists get \
  --params '{"tasklist": "TASKLIST_ID"}'

# Delete a task list
gws tasks tasklists delete \
  --params '{"tasklist": "TASKLIST_ID"}'

# List tasks in a task list
gws tasks tasks list \
  --params '{"tasklist": "TASKLIST_ID", "showCompleted": false, "showHidden": false}'

# List overdue tasks (due before today)
gws tasks tasks list \
  --params '{"tasklist": "TASKLIST_ID", "dueMax": "2026-03-14T23:59:59Z", "showCompleted": false}'

# Create a task
gws tasks tasks insert \
  --params '{"tasklist": "TASKLIST_ID"}' \
  --json '{"title": "Review pull request #42", "due": "2026-03-15T17:00:00.000Z", "notes": "Check auth changes"}'

# Get a specific task
gws tasks tasks get \
  --params '{"tasklist": "TASKLIST_ID", "task": "TASK_ID"}'

# Mark a task as complete
gws tasks tasks patch \
  --params '{"tasklist": "TASKLIST_ID", "task": "TASK_ID"}' \
  --json '{"status": "completed"}'

# Reopen a completed task
gws tasks tasks patch \
  --params '{"tasklist": "TASKLIST_ID", "task": "TASK_ID"}' \
  --json '{"status": "needsAction", "completed": null}'

# Update task title and due date
gws tasks tasks patch \
  --params '{"tasklist": "TASKLIST_ID", "task": "TASK_ID"}' \
  --json '{"title": "New title", "due": "2026-03-20T17:00:00.000Z"}'

# Create a subtask (parent task)
gws tasks tasks insert \
  --params '{"tasklist": "TASKLIST_ID", "parent": "PARENT_TASK_ID"}' \
  --json '{"title": "Subtask: write tests"}'

# Move task to a different position
gws tasks tasks move \
  --params '{"tasklist": "TASKLIST_ID", "task": "TASK_ID", "previous": "PREVIOUS_TASK_ID"}'

# Delete a task
gws tasks tasks delete \
  --params '{"tasklist": "TASKLIST_ID", "task": "TASK_ID"}'

# Clear all completed tasks from a list
gws tasks tasks clear \
  --params '{"tasklist": "TASKLIST_ID"}'
```

## Key API Resources

### tasklists
`list`, `get`, `insert`, `update`, `patch`, `delete`

### tasks
`list`, `get`, `insert`, `update`, `patch`, `move`, `delete`, `clear`

## Task Status Values

| Status | Description |
|--------|-------------|
| `needsAction` | Task is open / incomplete |
| `completed` | Task is done |

## Date Format

All date/time values use RFC 3339 format:
```
"2026-03-15T17:00:00.000Z"   # UTC
"2026-03-15T12:00:00-05:00"  # Eastern time
```

## Tips

- Use `showCompleted: false` when listing tasks to see only open items
- Tasks assigned from Google Docs or Chat Spaces can be listed but not created via API
- A user can have up to 2,000 task lists and 20,000 non-hidden tasks per list

## Discovering More

```bash
gws tasks --help
gws schema tasks.tasks.list
gws schema tasks.tasks.insert
```
