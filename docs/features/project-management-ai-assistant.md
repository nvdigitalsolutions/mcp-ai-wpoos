# Project Management AI Assistant

The Project Management AI Assistant feature provides embedded AI assistance directly within the WordPress admin edit screens for Projects, Tasks, and Events.

## Overview

When editing a project, task, or event in the WordPress admin, users can access an AI assistant metabox that provides context-aware help. The AI assistant has full awareness of the current item being edited and can:

- Answer questions about the item
- Suggest updates or improvements
- Create related items (e.g., create tasks for a project)
- Update item properties via tool calls
- List and analyze related items

## Features

### Context-Aware Assistance

The AI assistant automatically receives context about the current item:

- **For Projects**: ID, title, description, status, start/end dates, budget, and completion percentage
- **For Tasks**: ID, title, description, status, priority, project association, due date, and assigned user
- **For Events**: ID, title, description, start/end dates, location, event type, and all-day flag

### Assistant Selection

Users can select any available AI assistant from a dropdown menu in the metabox. This allows them to:

- Use different assistants for different purposes
- Choose specialized assistants configured with specific tools
- Switch assistants mid-workflow as needed

### Integrated Chat Interface

The metabox embeds the full NV oOS chat interface, providing:

- Real-time streaming responses
- Tool execution with visual feedback
- Message history (session-based)
- Markdown rendering
- Code syntax highlighting
- File attachments support (if enabled)
- Voice input/output (if configured)

## Usage

### Enabling the Feature

1. Ensure the Pro addon is installed and active
2. Navigate to **Settings → NV oOS → Features**
3. Enable **Project Management** feature
4. Save settings

### Using the AI Assistant

1. Navigate to **Projects**, **Tasks**, or **Events** in the admin menu
2. Create a new item or edit an existing one
3. Look for the **AI Assistant** metabox in the sidebar (usually positioned high on the right)
4. Select an assistant from the dropdown
5. The chat interface will appear below the selector
6. Start asking questions or requesting actions

### Example Interactions

#### For Projects

```
User: "What's the current status of this project?"
AI: "This project is currently marked as 'active' with 45% completion. 
It started on 2024-12-01 with a budget of $10,000 and is scheduled 
to end on 2025-02-28."

User: "Create 3 tasks for the remaining work"
AI: [Executes create_task tool multiple times]
"I've created the following tasks:
1. Complete frontend design (#123)
2. Implement backend API (#124)
3. User testing and feedback (#125)"
```

#### For Tasks

```
User: "Update this task's priority to urgent and assign it to user ID 5"
AI: [Executes update_task tool]
"I've updated the task:
- Priority: urgent
- Assigned to: User #5 (John Doe)"

User: "What other tasks are in this project?"
AI: [Executes list_tasks tool with project filter]
"Here are the other tasks in this project:
1. Setup development environment (Status: completed)
2. Design database schema (Status: in-progress)
3. Create API endpoints (Status: todo)"
```

#### For Events

```
User: "Find similar events scheduled this month"
AI: [Executes list_events tool with date filters]
"I found 3 similar events scheduled this month:
1. Team Meeting - Jan 15, 2025
2. Product Launch - Jan 22, 2025
3. Training Session - Jan 29, 2025"
```

## Technical Details

### Architecture

The feature consists of:

1. **Metabox Class** (`WP_MCP_AI_Project_Management_AI_Assistant_Metabox`)
   - Registers the metabox for all three CPTs
   - Handles asset enqueueing
   - Provides context data to the JavaScript layer
   - Handles AJAX requests for chat rendering

2. **JavaScript** (`admin-pm-ai-assistant.js`)
   - Manages assistant selection
   - Fetches and renders chat interface via AJAX
   - Builds context messages for the AI
   - Handles chat initialization

3. **CSS** (`admin-pm-ai-assistant.css`)
   - Styles the metabox and chat interface
   - Provides responsive design
   - Supports WordPress admin color schemes
   - Includes dark mode support

### Available Tools

The AI assistant can use all enabled project management tools:

- `create_project` - Create new projects
- `update_project` - Update project details
- `delete_project` - Delete projects
- `list_projects` - List and filter projects
- `create_task` - Create new tasks
- `update_task` - Update task details
- `delete_task` - Delete tasks
- `list_tasks` - List and filter tasks
- `create_event` - Create new events
- `update_event` - Update event details
- `delete_event` - Delete events
- `list_events` - List and filter events
- `get_calendar_view` - Get unified calendar view

Plus any other tools enabled for the selected assistant (WordPress Core tools, WooCommerce tools, etc.).

### Security

- **Nonce verification** - All AJAX requests are verified with WordPress nonces
- **Capability checks** - Users must have `edit_posts` capability and edit permission for the specific item
- **Assistant validation** - Only published assistants can be used
- **Sanitization** - All user input is sanitized before use
- **Rate limiting** - Standard NV oOS rate limits apply

### Performance

- **Lazy loading** - Chat interface is only loaded when an assistant is selected
- **Asset optimization** - Uses bundled chat assets (chat-bundle.min.js)
- **Compact template** - Uses compact chat template to minimize resource usage
- **Session-based** - Chat history is not saved to reduce database writes

## Customization

### Changing Metabox Position

To change the metabox position from sidebar to main content area:

```php
add_filter( 'wp_mcp_ai_pm_assistant_metabox_context', function( $context ) {
    return 'normal'; // Options: 'normal', 'side', 'advanced'
} );

add_filter( 'wp_mcp_ai_pm_assistant_metabox_priority', function( $priority ) {
    return 'high'; // Options: 'high', 'core', 'default', 'low'
} );
```

### Customizing Context Data

To add custom context data to the AI assistant:

```php
add_filter( 'wp_mcp_ai_pm_assistant_context_data', function( $context_data, $post ) {
    // Add custom field data
    $context_data['custom_field'] = get_post_meta( $post->ID, '_custom_field', true );
    
    return $context_data;
}, 10, 2 );
```

### Restricting to Specific Post Types

To disable the metabox for specific post types:

```php
add_filter( 'wp_mcp_ai_pm_assistant_post_types', function( $post_types ) {
    // Remove 'mcp_ai_event' to disable for events
    return array_diff( $post_types, array( 'mcp_ai_event' ) );
} );
```

## Troubleshooting

### Metabox Not Appearing

1. Verify Project Management is enabled in settings
2. Check that you're using the Pro addon
3. Ensure you're editing a project, task, or event (not viewing)
4. Verify your user role has `edit_posts` capability

### Assistant List is Empty

1. Create at least one AI assistant at **Assistants → Add New**
2. Ensure the assistant is published (not draft)
3. Check that assistants have the required project management tools enabled

### Chat Interface Fails to Load

1. Check browser console for JavaScript errors
2. Verify WordPress REST API is accessible
3. Check that nonces are being generated correctly
4. Ensure no JavaScript conflicts with other plugins

### Context Not Being Passed

1. Verify post metadata is saved (check using a plugin like "Show Current Template")
2. Check that context data is being localized (view page source for `wpMcpAiPmAssistant` object)
3. Test with browser developer tools to see AJAX requests

## Related Features

- [Project Management Tools](../tools/project-management.md)
- [Assistant Configuration](../guides/assistant-configuration.md)
- [Tool Authorization](../security/tool-authorization.md)
- [Chat Interface](./chat/chat-interface.md)

## Limitations

- Chat history is session-based and not persisted to database
- Only one assistant can be active per edit screen at a time
- Context is read-only (changes made in chat don't auto-update the edit form)
- Tool rate limits apply as configured in assistant settings

## Future Enhancements

Planned improvements for future versions:

- Two-way sync between chat and edit form
- Persistent chat history per item
- Multi-assistant collaboration
- Suggested actions based on item state
- Automated task generation from project goals
- Calendar integration for events
