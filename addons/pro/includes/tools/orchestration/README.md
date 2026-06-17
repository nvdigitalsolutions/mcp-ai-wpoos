# Orchestration Toolkit

> Pro schedule management, task plans, templates, autonomous sessions, and workflow orchestration.

## Purpose

Core Pro orchestration tools for managing scheduled executions, task plans, reusable templates, autonomous agent sessions, and the template library. These are the engine-room tools that power Pro-level workflow automation.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Aggregate Research Data | `aggregate_research_data` | Combine research results from multiple sources |
| Analyze Data Patterns | `analyze_data_patterns` | Detect patterns and trends in structured data |
| Analyze Loop Health | `analyze_loop_health` | Monitor agentic loop execution health |
| Blueprint Installer | `blueprint_installer` | Install a site blueprint from JSON definition |
| Calculate Orchestration Capacity | `calculate_orchestration_capacity` | Estimate available execution capacity |
| Check Exit Conditions | `check_exit_conditions` | Evaluate agentic loop termination criteria |
| Configure Schedule Widget Defaults | `configure_schedule_widget_defaults` | Set default display options for schedule widgets |
| Convert HTML to Markdown | `convert_html_to_markdown` | Convert HTML content to Markdown format |
| Create Pro Schedule | `create_pro_schedule` | Create a new scheduled execution |
| Create Task Plan | `create_task_plan` | Define a multi-step task execution plan |
| Create Template | `create_template` | Create a reusable workflow template |
| Delete Pro Schedule | `delete_pro_schedule` | Remove a scheduled execution |
| Detect Completion Indicators | `detect_completion_indicators` | Identify signals that a task is complete |
| Dry Run Pro Schedule | `dry_run_pro_schedule` | Simulate schedule execution without side effects |
| Extract Structured Data | `extract_structured_data` | Parse structured data from unstructured input |
| Generate Password | `generate_password` | Cryptographically secure password generation |
| Generate Research Report | `generate_research_report` | Compile research findings into a report |
| Get Schedule Latest Result | `get_schedule_latest_result` | Fetch the most recent execution result |
| Get Schedule Run History | `get_schedule_run_history` | Retrieve historical execution records |
| Get Session Status | `get_session_status` | Check autonomous session state |
| Get Task Plan | `get_task_plan` | Fetch a task plan by ID |
| Instantiate Template | `instantiate_template` | Create a new instance from a template |
| List Pro Schedules | `list_pro_schedules` | List scheduled executions |
| List Templates | `list_templates` | List available workflow templates |
| Manage Autonomous Session | `manage_autonomous_session` | Start/stop/monitor autonomous agent sessions |
| Plan Schedules from Workflow | `plan_schedules_from_workflow` | Derive schedules from a workflow definition |
| Render Schedule Result | `render_schedule_result` | Format execution results for display |
| Schedule Channel Broadcast | `schedule_channel_broadcast` | Schedule a message broadcast across channels |
| Seed Template Library | `seed_template_library` | Populate the template library with defaults |
| Update Pro Schedule | `update_pro_schedule` | Modify an existing schedule |
| Update Task Plan | `update_task_plan` | Modify a task plan |
| Verify Information | `verify_information` | Cross-reference and validate factual claims |

## Dependencies

- WordPress 6.0+
- Pro Schedule Manager (`class-wp-mcp-ai-pro-schedule-manager.php`)
- Pro Workflow Presets (`class-wp-mcp-ai-pro-workflow-presets.php`)

## Registration

Registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`. Always available when Pro is active.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [Schedule Manager: `addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php`](../../class-wp-mcp-ai-pro-schedule-manager.php)
- [Orchestration Dashboard: `addons/pro/includes/admin/class-wp-mcp-ai-orchestration-dashboard.php`](../../admin/class-wp-mcp-ai-orchestration-dashboard.php)
