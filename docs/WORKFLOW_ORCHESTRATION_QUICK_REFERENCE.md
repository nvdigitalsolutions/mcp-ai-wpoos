# Workflow Orchestration Enhancements - Quick Reference

## Overview

The `/workflow` slash command now supports advanced orchestration capabilities for building powerful, intelligent automation workflows.

## New Features (v1.2.1)

### 1. Parallel Execution ⚡

Execute multiple independent steps concurrently for 5-10x performance improvement.

**Syntax:**
```yaml
steps:
  - parallel:
      - task: clean-content
        name: content-check
        timeout: 30
        output_var: content_result
      - task: optimize-perf
        name: perf-check
        timeout: 30
        output_var: perf_result
    continue_on_error: true
```

**Features:**
- Per-step timeout control (default 60s)
- Overall 5-minute hard limit per parallel block
- Duration tracking for each step
- Sub-step numbering (1.1, 1.2, etc.)

---

### 2. Conditional Branching 🔀

Route workflow execution based on runtime conditions.

**Syntax:**
```yaml
steps:
  - task: next-task
    params: { type: 'drafts', limit: 10 }
    output_var: draft_count
  
  - condition: '{{draft_count}} > 5'
    then:
      - task: ship
        params: { limit: 5, publish: true }
    else:
      - task: notify_admin
        params:
          subject: 'Low Draft Count'
          message: 'Only a few drafts ready.'
```

**Supported Operators:**
- **Numeric**: `>`, `<`, `>=`, `<=`, `==`, `!=`
- **String**: `==`, `!=`, `contains`
- **Special**: `empty`, `not_empty`

**Features:**
- Context variable replacement using `{{var}}`
- Both `then` and `else` branches are optional
- Branch numbering (1.then.1, 1.else.1, etc.)

---

### 3. Loop Control 🔄

Enable autonomous self-healing cycles with intelligent exit detection.

**Syntax:**
```yaml
steps:
  - repeat_until: '{{quality_score}} >= 8'
    max_iterations: 5
    steps:
      - task: clean-content
        params: { limit: 3, dry-run: true }
        output_var: content_result
      - task: next-task
        params: { type: 'drafts', limit: 3 }
        output_var: draft_result
      - task: wait
        params: { seconds: 2 }
```

**Features:**
- **Dual exit detection**: Exits when condition is met OR max iterations reached
- **Context preservation**: Workflow context updated across iterations
- **Safety limits**: Default max iterations is 10
- **Iteration tracking**: Reports "Loop completed: 3 of 5 iterations"
- **Step numbering**: Hierarchical format (1.loop.1.1, 1.loop.2.1, etc.)

**Ralph Wiggum Pattern:**
This enables continuous self-healing cycles for autonomous workflows.

---

### 4. Step Dependencies (DAG) 📊

Support complex workflows with explicit task dependencies.

**Syntax:**
```yaml
steps:
  - name: analyze
    task: next-task
    params: { type: 'drafts', limit: 5 }
  
  - name: process_content
    task: clean-content
    depends_on: [analyze]
    params: { limit: 5 }
  
  - name: check_perf
    task: optimize-perf
    depends_on: [analyze]
    params: { phases: '1,2' }
  
  - name: finalize
    task: notify_admin
    depends_on: [process_content, check_perf]
    params:
      subject: 'Workflow Complete'
      message: 'All tasks completed!'
```

**Execution Order:** `analyze` → (`process_content` + `check_perf`) → `finalize`

**Features:**
- **Topological sorting**: Automatically resolves execution order (Kahn's algorithm)
- **Circular detection**: Detects and reports circular dependencies
- **Named steps**: Access step results via `{{step_name}}` in context
- **Flexible patterns**: Supports linear, parallel (fan-out/fan-in), and diamond patterns

---

## Built-in Workflow Templates

View all templates: `/workflow --list`

### New Templates

1. **parallel-checks** - Parallel site checks (content, perf, docs)
2. **conditional-publish** - Conditional publishing based on draft count
3. **autonomous-audit** - Loop-based autonomous content audit
4. **dependency-workflow** - Complex DAG workflow demonstration

### Usage Examples

```bash
# List all workflows
/workflow --list

# Show workflow definition
/workflow parallel-checks --show

# Run workflow in dry-run mode
/workflow parallel-checks --dry-run

# Run workflow (live execution)
/workflow parallel-checks
```

---

## Combining Features

You can combine all features in a single workflow:

```yaml
name: Advanced Multi-Pattern Workflow
steps:
  # Sequential step
  - name: init
    task: next-task
    params: { type: 'drafts', limit: 10 }
    output_var: draft_count
  
  # Conditional branch
  - condition: '{{draft_count}} > 5'
    then:
      # Parallel execution
      - parallel:
          - name: clean
            task: clean-content
            timeout: 30
          - name: perf
            task: optimize-perf
            timeout: 30
        continue_on_error: true
    else:
      # Loop control
      - repeat_until: '{{quality_score}} >= 7'
        max_iterations: 3
        steps:
          - task: clean-content
            params: { limit: 3 }
  
  # DAG dependencies
  - name: finalize
    task: notify_admin
    depends_on: [init]
    params:
      subject: 'Workflow Complete'
```

---

## Context Variables

**Access step results in subsequent steps:**

```yaml
steps:
  - task: next-task
    output_var: my_result
  
  - task: notify_admin
    params:
      message: 'Found {{my_result}} items'
```

**Named steps automatically add to context:**

```yaml
steps:
  - name: check_drafts
    task: next-task
  
  - condition: '{{check_drafts}} > 5'
    then: ...
```

---

## Error Handling

**Continue on error:**
```yaml
steps:
  - task: risky-operation
    continue_on_error: true
```

**Parallel block:**
```yaml
- parallel:
    - task: step1
    - task: step2
  continue_on_error: true  # Continue even if one fails
```

---

## Performance Tips

1. **Use parallel execution** for independent tasks (5-10x faster)
2. **Use conditional branching** to skip unnecessary steps
3. **Set appropriate timeouts** for parallel steps (default 60s)
4. **Use dry-run mode** to test workflows safely
5. **Limit max iterations** for loops to prevent runaway execution

---

## Debugging

**View workflow definition:**
```bash
/workflow my-workflow --show
```

**Test in dry-run mode:**
```bash
/workflow my-workflow --dry-run
```

**Check execution results:**
- Step numbering shows execution path
- Status icons: ✅ completed, ⚠️ warning, ❌ failed, ⏭️ skipped
- Duration displayed for timed steps

---

## Custom Workflows

Create custom workflows in: `/wp-content/uploads/mcp-ai/workflows/`

**Example file:** `my-workflow.yml`
```yaml
name: My Custom Workflow
description: Brief description of what this workflow does
steps:
  - task: next-task
    params: { type: 'drafts', limit: 5 }
```

**Use your workflow:**
```bash
/workflow my-workflow
```

---

## Security & Safety

- ✅ Capability validation before execution
- ✅ Timeout enforcement (per-step + overall)
- ✅ Max iteration limits (default 10)
- ✅ Circular dependency detection
- ✅ Dry-run mode for safe testing
- ✅ Error handling with continue_on_error

---

## Support

For issues or questions:
- View documentation: `docs/SLASH_COMMANDS_GUIDE.md`
- Check examples: Built-in templates via `/workflow --list`
- Test workflows: Always use `--dry-run` first

---

**Version:** 1.2.1  
**Status:** Production Ready  
**Backward Compatible:** Yes - All existing workflows work unchanged
