# Migrating from Basic Workflow Editor to Pro Workflow Builder

## Overview

This guide helps you transition from the basic workflow editor to the new Pro Workflow Builder with its visual interface and advanced features.

## What's Changed

### Basic Workflow Editor (Old)

- **Text-based interface** - Edit workflows through forms
- **Step-by-step configuration** - Add steps one at a time
- **Limited visualization** - Text list of steps
- **Basic validation** - Simple checks

### Pro Workflow Builder (New)

- **Visual canvas** - Drag-and-drop node-based interface
- **Real-time preview** - See workflow structure instantly
- **Advanced node types** - 10+ specialized nodes
- **Comprehensive validation** - Real-time error detection
- **Modern UI** - Industry-standard interface following 2026 best practices

## Migration Process

### Step 1: Understanding Node Types

Map your old workflow steps to new node types:

| Old Step Type | New Node Type | Notes |
|--------------|---------------|-------|
| Trigger/Start | Trigger Node | Still required to start workflow |
| Command Step | Action Node | Same slash command execution |
| - | Tool Node | NEW - Direct MCP tool access |
| - | Agent Node | NEW - AI agent integration |
| Conditional | Condition Node | Enhanced with visual branching |
| Loop | Loop Node | Improved iteration support |
| - | Parallel Node | NEW - Concurrent execution |
| - | Delay Node | NEW - Timing control |
| - | Approval Node | NEW - Human approval gates |
| - | Merge Node | NEW - Combine parallel results |

### Step 2: Accessing Your Workflows

1. **Old Location:** NV oOS → Workflows
2. **New Location:** NV oOS → Pro Workflows

Both interfaces are available during the transition period.

### Step 3: Converting a Simple Workflow

**Old Workflow:**
```
Name: User Notification
Steps:
1. Command: /fetch-user
   Params: {"user_id": "{{user_id}}"}
2. Command: /send-notification
   Params: {"message": "Hello {{user.name}}"}
```

**New Workflow:**

1. Open Pro Workflow Builder
2. Add Trigger node (labeled "Start")
3. Add Action node (labeled "Fetch User")
   - Command: `/fetch-user`
   - Params: `{"user_id": "{{user_id}}"}`
4. Add Action node (labeled "Send Notification")
   - Command: `/send-notification`
   - Params: `{"message": "Hello {{user.name}}"}`
5. Connect: Trigger → Fetch User → Send Notification
6. Save as "User Notification"

### Step 4: Converting Conditional Logic

**Old Workflow:**
```
1. Command: /check-status
2. If result.status === "active":
   - Command: /process-active
   Else:
   - Command: /process-inactive
```

**New Workflow:**

1. Add Trigger node
2. Add Action node (labeled "Check Status")
   - Command: `/check-status`
3. Add Condition node (labeled "Status Check")
   - Expression: `result.status === "active"`
4. Add Action node on true path (labeled "Process Active")
   - Command: `/process-active`
5. Add Action node on false path (labeled "Process Inactive")
   - Command: `/process-inactive`
6. Connect nodes appropriately
7. Save

### Step 5: Converting Loops

**Old Workflow:**
```
1. Command: /fetch-items
2. Loop over items:
   - Command: /process-item
     Params: {"item": "{{current_item}}"}
```

**New Workflow:**

1. Add Trigger node
2. Add Action node (labeled "Fetch Items")
   - Command: `/fetch-items`
3. Add Loop node (labeled "Process Each Item")
   - Items: `{{previous.results}}`
4. Add Action node (labeled "Process Item")
   - Command: `/process-item`
   - Params: `{"item": "{{current_item}}"}`
5. Connect: Trigger → Fetch Items → Loop → Process Item
6. Save

## New Capabilities

### 1. Parallel Execution

Not possible in old editor, now easy:

```
Trigger → Parallel
          ├─ Action A (Fetch Data 1)
          ├─ Action B (Fetch Data 2)
          └─ Action C (Fetch Data 3)
          → Merge → Process Combined Data
```

**Benefits:**
- Faster execution
- Efficient resource usage
- Simultaneous operations

### 2. Human Approval Gates

Add human oversight to critical operations:

```
Trigger → Action (Prepare Report)
        → Approval (Manager Review)
        → Action (Send Report)
```

**Use Cases:**
- Financial transactions
- Content publication
- User management
- System changes

### 3. Timing Control

Add delays between steps:

```
Trigger → Action (Send Email)
        → Delay (Wait 5 minutes)
        → Action (Send Follow-up)
```

**Benefits:**
- Rate limiting
- Scheduled execution
- Waiting for external processes

### 4. Visual Debugging

See workflow structure at a glance:
- Color-coded nodes
- Connection visualization
- Mini-map for navigation
- Zoom and pan

### 5. Real-time Validation

Catch errors before execution:
- Missing configurations
- Disconnected nodes
- Invalid parameters
- Circular dependencies

## Best Practices for Migration

### 1. Start Simple

- Migrate simple workflows first
- Test thoroughly
- Build confidence with the new interface

### 2. Take Advantage of New Features

- Add error handling with conditions
- Use parallel execution where appropriate
- Add approval gates for sensitive operations
- Implement delays for rate limiting

### 3. Improve Naming

The visual interface makes good naming more important:
- Use clear, descriptive labels
- Organize nodes logically
- Group related operations

### 4. Test Before Deploying

- Use the test button
- Verify each node configuration
- Check validation errors
- Review execution flow

## Troubleshooting Migration

### Issue: Can't find my old workflows

**Solution:** Old workflows are still in the basic editor (NV oOS → Workflows). They need to be manually recreated in the Pro Workflow Builder.

### Issue: Different parameter format

**Solution:** The Pro builder uses JSON for all parameters. Convert your parameters to valid JSON:

```
Old: user_id=123, action=update
New: {"user_id": 123, "action": "update"}
```

### Issue: Workflow is too complex

**Solution:** Break large workflows into smaller, manageable pieces:
1. Identify logical sections
2. Create separate workflows for each section
3. Use Action nodes to trigger sub-workflows

### Issue: Node won't connect

**Solution:** 
- Drag from bottom handle (source) to top handle (target)
- Ensure connection doesn't create a cycle
- Check node compatibility

## Feature Comparison

| Feature | Basic Editor | Pro Builder |
|---------|-------------|-------------|
| Visual Interface | ❌ | ✅ |
| Drag & Drop | ❌ | ✅ |
| Node Types | 3 | 10+ |
| Parallel Execution | ❌ | ✅ |
| Conditional Branching | Limited | Full |
| Human Approval | ❌ | ✅ |
| Timing Control | ❌ | ✅ |
| Real-time Validation | Basic | Advanced |
| Mini-map | ❌ | ✅ |
| Zoom & Pan | ❌ | ✅ |
| Templates | ❌ | Coming Soon |
| Version Control | ❌ | Coming Soon |
| Debugging Mode | ❌ | Coming Soon |

## Timeline

### Phase 1 (Current)
- Pro Workflow Builder available
- Basic editor still supported
- Migration encouraged

### Phase 2 (3 months)
- Both editors available
- Pro builder recommended
- Migration tools released

### Phase 3 (6 months)
- Pro builder default
- Basic editor deprecated
- Auto-migration tools

### Phase 4 (9 months)
- Pro builder only
- Basic editor removed
- Full migration complete

## Support

Need help with migration?

- **Documentation:** `/docs/pro-workflow-builder.md`
- **Video Tutorials:** Coming soon
- **Community:** [NV Digital Community](https://nvdigitalsolutions.com/community)
- **Support:** [Submit a ticket](https://nvdigitalsolutions.com/support)

## Feedback

We value your input! Please share:

- Migration challenges
- Feature requests
- UI/UX suggestions
- Success stories

Contact: support@nvdigitalsolutions.com

## Conclusion

The Pro Workflow Builder represents a significant upgrade in workflow automation capabilities. While there's a learning curve, the benefits of visual design, advanced features, and industry-standard interface make it worthwhile.

Take your time, start with simple workflows, and gradually explore the new features. Your automation workflows will be more powerful and maintainable than ever before.

Happy workflow building!

---

**Next Steps:**
1. Read the [Pro Workflow Builder Guide](/docs/pro-workflow-builder.md)
2. Try the [Interactive Tutorial](#) (Coming soon)
3. Join the [Community Forum](https://nvdigitalsolutions.com/community)
