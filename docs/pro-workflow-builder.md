# Pro Workflow Builder Guide

## Overview

The Pro Workflow Builder is a modern visual workflow automation tool that brings enterprise-grade workflow capabilities to WordPress. Built with React and ReactFlow, it implements 2026 industry best practices from leading platforms like n8n, Zapier, Make, Vellum, and CrewAI.

## Table of Contents

1. [Introduction](#introduction)
2. [Getting Started](#getting-started)
3. [Core Concepts](#core-concepts)
4. [Node Types](#node-types)
5. [Building Workflows](#building-workflows)
6. [Best Practices](#best-practices)
7. [Advanced Features](#advanced-features)
8. [Integration](#integration)
9. [Troubleshooting](#troubleshooting)

## Introduction

### Why Visual Workflows?

Traditional command-based automation requires technical knowledge and can be error-prone. Visual workflow builders provide:

- **Intuitive Interface** - Drag-and-drop makes automation accessible to non-technical users
- **Visual Logic** - See the flow of execution at a glance
- **Debugging** - Identify issues quickly with visual inspection
- **Collaboration** - Team members can understand workflows without code
- **Reusability** - Templates and patterns speed up development

### Key Features

- ✅ **Node-based UI** - Connect actions visually
- ✅ **10+ Node Types** - Comprehensive workflow building blocks
- ✅ **Real-time Validation** - Catch errors before execution
- ✅ **Drag & Drop** - Intuitive workflow construction
- ✅ **Zoom & Pan** - Navigate complex workflows easily
- ✅ **Mini-map** - Overview of large workflows
- ✅ **Properties Panel** - Configure nodes in detail
- ✅ **Save/Load** - Persistent workflow storage

## Getting Started

### Prerequisites

- NV oOS Pro addon enabled
- WordPress admin access with `manage_options` capability
- Modern web browser (Chrome, Firefox, Safari, Edge)

### Accessing the Builder

1. Log in to WordPress admin
2. Navigate to **NV oOS Pro → Pro Workflows**
3. Click **New Workflow** to start building

### Your First Workflow

Let's create a simple workflow that sends a notification:

1. **Add Trigger Node**
   - Drag "Trigger" from sidebar to canvas
   - This starts the workflow

2. **Add Action Node**
   - Drag "Action" from sidebar
   - Connect Trigger's bottom handle to Action's top handle

3. **Configure Action**
   - Click the Action node
   - Set Command: `/notify`
   - Set Parameters: `{"message": "Hello World"}`

4. **Save Workflow**
   - Click "Save" in toolbar
   - Name your workflow: "Hello World"

## Core Concepts

### Nodes

Nodes are the building blocks of workflows. Each node performs a specific function:

- **Execution Nodes** - Perform actions (Action, Tool, Agent)
- **Control Nodes** - Control flow (Condition, Loop, Parallel)
- **Utility Nodes** - Support functions (Delay, Approval, Merge)

### Connections (Edges)

Connections define the flow of execution:

- **Source Handle** (bottom) - Where execution exits
- **Target Handle** (top) - Where execution enters
- **Animated Lines** - Show the connection path

### Properties

Each node has configurable properties:

- **Label** - Display name
- **Configuration** - Node-specific settings
- **Type** - Cannot be changed after creation

### Validation

The builder validates workflows in real-time:

- **Required Nodes** - Must have a trigger
- **Connections** - All nodes must be connected
- **Configuration** - Required fields must be filled
- **Logic** - No circular dependencies

## Node Types

### Trigger Node

**Purpose:** Start workflow execution

**Icon:** ⚡

**Color:** Green (#10b981)

**Configuration:**
- Event type (manual, scheduled, webhook)
- Trigger conditions

**Best Practices:**
- Every workflow needs exactly one trigger
- Place at the top of the canvas
- Use descriptive labels

### Action Node

**Purpose:** Execute a slash command

**Icon:** ▶

**Color:** Blue (#3b82f6)

**Configuration:**
- `command` - Slash command to execute (e.g., `/search`)
- `params` - JSON object with parameters

**Example:**
```json
{
  "command": "/search",
  "params": {
    "query": "WordPress plugins",
    "limit": 10
  }
}
```

**Best Practices:**
- Test commands separately first
- Use valid JSON for parameters
- Handle errors with condition nodes

### Tool Node

**Purpose:** Execute an MCP tool

**Icon:** 🔧

**Color:** Cyan (#0891b2)

**Configuration:**
- `tool_name` - Name of the MCP tool
- `arguments` - Tool-specific arguments

**Example:**
```json
{
  "tool_name": "create_post",
  "arguments": {
    "title": "New Post",
    "content": "Post content here"
  }
}
```

### Agent Node

**Purpose:** Call an AI agent

**Icon:** 🤖

**Color:** Purple (#8b5cf6)

**Configuration:**
- `agent_id` - ID of the AI agent
- `prompt` - Prompt for the agent
- `context` - Additional context

**Best Practices:**
- Use for complex reasoning tasks
- Provide clear, specific prompts
- Set appropriate timeouts

### Condition Node

**Purpose:** Branch workflow based on conditions

**Icon:** ◆

**Color:** Orange (#f59e0b)

**Configuration:**
- `expression` - Conditional expression

**Example:**
```javascript
result.status === "success"
```

**Output Handles:**
- **Left (30%)** - True path (green)
- **Right (70%)** - False path (red)

**Expression Syntax:**
- JavaScript-like expressions
- Access previous results with `result.*`
- Operators: `===`, `!==`, `>`, `<`, `>=`, `<=`, `&&`, `||`

### Loop Node

**Purpose:** Iterate over a collection

**Icon:** 🔄

**Color:** Purple (#8b5cf6)

**Configuration:**
- `items` - Array to iterate (e.g., `{{previous.results}}`)

**Best Practices:**
- Limit loop iterations
- Use delays to prevent rate limiting
- Handle errors within loops

### Parallel Node

**Purpose:** Execute multiple branches simultaneously

**Icon:** ⇉

**Color:** Pink (#ec4899)

**Use Cases:**
- Fetch data from multiple sources
- Process items concurrently
- Independent operations

**Best Practices:**
- Use with Merge node to combine results
- Set appropriate timeouts
- Consider rate limits

### Delay Node

**Purpose:** Wait for a specified time

**Icon:** ⏱

**Color:** Indigo (#6366f1)

**Configuration:**
- `duration` - Wait time in seconds

**Use Cases:**
- Rate limiting
- Waiting for external processes
- Scheduled delays

### Approval Node

**Purpose:** Human-in-the-loop approval gate

**Icon:** ✓

**Color:** Teal (#14b8a6)

**Configuration:**
- `approvers` - List of users who can approve
- `timeout` - Maximum wait time
- `message` - Approval request message

**Best Practices:**
- Use for sensitive operations
- Set reasonable timeouts
- Provide clear approval criteria

### Merge Node

**Purpose:** Combine outputs from parallel branches

**Icon:** ⊕

**Color:** Gray (#6b7280)

**Configuration:**
- `strategy` - How to merge (all, any, first)

**Strategies:**
- **All** - Wait for all branches
- **Any** - Continue with first result
- **First** - Use first successful result

## Building Workflows

### Basic Workflow Pattern

```
Trigger → Action → Condition
                    ├─ True → Action
                    └─ False → Action
```

### Sequential Pipeline

```
Trigger → Action 1 → Action 2 → Action 3 → Action 4
```

**Use Case:** Multi-step data processing

### Parallel Execution

```
Trigger → Parallel
          ├─ Action A
          ├─ Action B
          └─ Action C
          → Merge → Action
```

**Use Case:** Fetch data from multiple sources

### Conditional Branching

```
Trigger → Action → Condition
                    ├─ Success → Notify Success
                    └─ Failure → Retry → Notify Failure
```

**Use Case:** Error handling

### Iterative Processing

```
Trigger → Fetch Items → Loop
                         └─ Process Item → Condition
                                           ├─ Valid → Save
                                           └─ Invalid → Log Error
```

**Use Case:** Batch processing

## Best Practices

### 1. Design Principles

**Keep It Simple**
- Start with basic workflows
- Add complexity gradually
- Break large workflows into smaller ones

**Use Descriptive Labels**
- Name nodes clearly (e.g., "Fetch User Data", not "Action 1")
- Describe what each step does
- Future you will thank you

**Error Handling**
- Always add error branches
- Use conditions to catch failures
- Log errors appropriately

### 2. Performance Optimization

**Minimize API Calls**
- Batch operations when possible
- Cache repeated queries
- Use parallel execution wisely

**Rate Limiting**
- Add delays between requests
- Respect API limits
- Use queues for high volume

**Timeout Management**
- Set reasonable timeouts
- Handle timeout errors
- Don't let workflows hang

### 3. Security Best Practices

**Credential Management**
- Never hardcode secrets
- Use WordPress password vault
- Rotate credentials regularly

**Input Validation**
- Validate all user inputs
- Sanitize data before processing
- Use type checking

**Approval Gates**
- Require approval for sensitive operations
- Log all approvals
- Set appropriate permissions

### 4. Testing & Debugging

**Test Incrementally**
- Test each node individually
- Build workflows step by step
- Use the test button frequently

**Validation Checks**
- Review validation errors
- Fix issues before saving
- Test edge cases

**Documentation**
- Document complex logic
- Add comments in configurations
- Maintain a workflow catalog

## Advanced Features

### Workflow Variables

Access data from previous nodes:

```javascript
// In condition expression
{{previous.result.status}}

// In action parameters
{
  "user_id": "{{trigger.user_id}}",
  "data": "{{action_1.result}}"
}
```

### Dynamic Configurations

Use templates in node configurations:

```json
{
  "title": "Report for {{current_date}}",
  "recipients": ["{{admin_email}}"],
  "content": "{{generated_content}}"
}
```

### Workflow Templates

Pre-built patterns for common scenarios:

1. **Data Pipeline** - ETL workflows
2. **Notification System** - Multi-channel alerts
3. **Content Generation** - AI-powered content
4. **User Onboarding** - Automated onboarding
5. **Report Generation** - Scheduled reports

## Integration

### With Slash Commands

Workflows can execute any slash command:

```javascript
// In Action node
{
  "command": "/your-command",
  "params": {
    "arg1": "value1"
  }
}
```

### With MCP Tools

Use Tool nodes to access 519 built-in tools:

```javascript
{
  "tool_name": "create_wordpress_post",
  "arguments": {
    "title": "New Post",
    "content": "Content here"
  }
}
```

### With AI Agents

Agent nodes integrate with AI assistants:

```javascript
{
  "agent_id": "content-writer",
  "prompt": "Write a blog post about {{topic}}",
  "context": {
    "tone": "professional",
    "length": "1000 words"
  }
}
```

### REST API

Manage workflows programmatically:

```php
// Save workflow
wp_ajax_action('wp_mcp_ai_save_pro_workflow', [
  'workflow' => json_encode($workflow_data),
  'nonce' => wp_create_nonce('mcp_ai_pro_workflow_builder')
]);

// Load workflow
wp_ajax_action('wp_mcp_ai_load_pro_workflow', [
  'workflow_id' => 'my-workflow',
  'nonce' => wp_create_nonce('mcp_ai_pro_workflow_builder')
]);
```

## Troubleshooting

### Common Issues

**Workflow won't save**
- Check validation errors (top-right corner)
- Ensure all required fields are filled
- Verify JSON syntax in parameters

**Nodes won't connect**
- Drag from bottom handle (source) to top handle (target)
- Check if connection creates a cycle
- Ensure valid connection type

**Properties panel won't open**
- Click directly on the node
- Avoid clicking on the canvas
- Try refreshing the page

**Test button doesn't work**
- Save the workflow first
- Check validation errors
- Ensure trigger node exists

### Debug Mode

Enable debug mode to see detailed logs:

```php
// In wp-config.php
define('WP_MCP_AI_DEBUG', true);
```

### Browser Console

Check browser console for errors:
1. Press F12 to open DevTools
2. Go to Console tab
3. Look for red error messages

### Support

Need help? Check:
- [Documentation](/docs/)
- [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- [Community Forum](https://nvdigitalsolutions.com/community)

## Conclusion

The Pro Workflow Builder brings enterprise-grade automation to WordPress. By following industry best practices and providing an intuitive visual interface, it makes complex AI workflows accessible to everyone.

Start building your workflows today and unlock the full potential of NV oOS!

---

**Next Steps:**
- Explore the [Workflow Templates](/docs/workflow-templates.md)
- Review [API Documentation](/docs/api-reference.md)
- Join the [Community](https://nvdigitalsolutions.com/community)
