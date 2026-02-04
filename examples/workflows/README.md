# Workflow Export/Import Examples

This directory contains example workflow files that demonstrate the various orchestration features available in NV oOS.

## Available Examples

### 1. Content Pipeline (`content-pipeline.yml`)
A comprehensive content workflow that:
- Checks for draft posts
- Cleans content
- Optimizes performance
- Publishes ready posts
- Syncs documentation

### 2. Site Health Check (`site-health-check.yml`)
Parallel execution workflow that:
- Runs performance optimization
- Cleans up content
- Syncs documentation
All in parallel for faster execution

### 3. Autonomous Content Review (`autonomous-content-review.yml`)
Loop-based workflow that:
- Repeatedly reviews content
- Checks quality score
- Continues until quality threshold is met
- Maximum 5 iterations with early exit

### 4. Conditional Publishing (`conditional-publishing.yml`)
Conditional branching workflow that:
- Checks draft count
- If > 5 drafts: publish them
- If ≤ 5 drafts: notify admin
- Demonstrates decision-making logic

### 5. Complex DAG Workflow (`complex-dag.yml`)
Dependency-based workflow that:
- Analyzes content (root task)
- Processes content & checks performance (parallel, depend on analyze)
- Finalizes workflow (depends on both parallel tasks)
- Demonstrates directed acyclic graph execution

## How to Use

### Import a Workflow

```bash
# Copy the example file to the exports directory
cp examples/workflows/content-pipeline.yml wp-content/uploads/mcp-ai-wpoos/workflows/exports/

# Import it with a custom name
/workflow my-content-pipeline --import --file=content-pipeline.yml
```

### Export Your Own Workflow

```bash
# Export a built-in workflow
/workflow parallel-checks --export

# Export will create a timestamped file in:
# wp-content/uploads/mcp-ai-wpoos/workflows/exports/
```

### Customize a Workflow

1. Export a workflow to get the YAML file
2. Edit the YAML file to customize steps, parameters, conditions
3. Import it with a new name
4. Run your custom workflow

## Workflow File Format

```yaml
metadata:
  exported_at: '2026-02-04 12:00:00'
  plugin_version: '1.2.2'
  export_format: '1.0'

workflow:
  name: My Custom Workflow
  description: What this workflow does
  steps:
    - task: next-task
      params:
        type: drafts
        limit: 5
    - task: clean-content
      params:
        limit: 5
```

## Tips

1. **Always use `--dry-run` first** to test workflows without making changes
2. **Start with simple workflows** and gradually add complexity
3. **Use meaningful step names** when working with dependencies
4. **Set appropriate timeouts** for parallel steps
5. **Add `continue_on_error: true`** for resilient workflows

## Support

For questions or issues:
- **Documentation**: `/docs/WORKFLOW_ORCHESTRATION_QUICK_REFERENCE.md`
- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
