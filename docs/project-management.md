# Project/Task/Events Management System Documentation

## Overview

This document describes the newly implemented Project/Task/Events Management system for WP oOS (Open Operator System). This is a **Pro Feature** that provides comprehensive project management capabilities with integrated calendar support.

## Features

### 1. Project Management
- Create, read, update, and delete projects
- Track project status (planning, active, on-hold, completed, cancelled)
- Set start and end dates for projects
- Assign multiple team members to projects

### 2. Task Management
- Create, read, update, and delete tasks
- Link tasks to projects
- Set priority levels (low, medium, high, urgent)
- Track task status (todo, in-progress, review, completed, cancelled)
- Assign tasks to specific users
- Set due dates for calendar tracking

### 3. Event Management
- Create, read, update, and delete calendar events
- Support for all-day and time-specific events
- Multiple event types (meeting, deadline, milestone, reminder, other)
- Link events to projects
- Track event location
- Manage event attendees

### 4. Calendar View
- Unified calendar view combining projects, tasks, and events
- Date range filtering
- Project-specific filtering
- User-specific filtering (assigned items)
- Optional grouping by date for calendar rendering

## Tools Implemented

### Project Tools
1. **create_project** - Creates a new project with metadata
2. **update_project** - Updates an existing project
3. **list_projects** - Lists projects with filtering options
4. **delete_project** - Permanently deletes a project

### Task Tools
5. **create_task** - Creates a new task
6. **update_task** - Updates an existing task
7. **list_tasks** - Lists tasks with filtering by project, status, priority, assignee, and due date
8. **delete_task** - Permanently deletes a task

### Event Tools
9. **create_event** - Creates a new calendar event
10. **update_event** - Updates an existing event
11. **list_events** - Lists events with date range and type filtering
12. **delete_event** - Permanently deletes an event

### Calendar Tool
13. **get_calendar_view** - Provides unified calendar view across all types

## Technical Implementation

### Storage
The system uses WordPress Custom Post Types (CPT):
- **mcp_ai_project** - Projects CPT
- **mcp_ai_task** - Tasks CPT
- **mcp_ai_event** - Events CPT

All metadata is stored using WordPress post meta for efficient querying and filtering.

### Feature Toggle
Administrators can enable/disable the feature at:
**Settings → WP oOS → Tools & Features → Project Management**

### Pro Feature
- Only available in the full/pro version (not base version)
- Checks `wp_mcp_ai_is_base_version()` function
- Feature flag: `wp_mcp_ai_enable_project_management`

### Tool Registration
All tools are registered in the extended_tools array in:
`includes/class-wp-mcp-ai-tool-registry.php`

### Tool Group
Tools are categorized under the "Project Management" group for easy discovery in the admin interface.

## Usage Examples

### Creating a Project
```json
{
  "name": "Website Redesign",
  "description": "Complete website redesign project",
  "status": "active",
  "start_date": "2025-01-01",
  "end_date": "2025-03-31",
  "assigned_to": [1, 5, 10]
}
```

### Creating a Task
```json
{
  "title": "Design homepage mockup",
  "description": "Create initial design mockups for the new homepage",
  "project_id": 123,
  "status": "in-progress",
  "priority": "high",
  "due_date": "2025-01-15",
  "assigned_to": 5
}
```

### Creating an Event
```json
{
  "title": "Project Kickoff Meeting",
  "description": "Initial meeting to discuss project scope",
  "project_id": 123,
  "start_date": "2025-01-05",
  "start_time": "10:00",
  "end_date": "2025-01-05",
  "end_time": "11:30",
  "location": "Conference Room A",
  "type": "meeting",
  "attendees": [1, 5, 10]
}
```

### Getting Calendar View
```json
{
  "start_date": "2025-01-01",
  "end_date": "2025-01-31",
  "project_id": 123,
  "group_by_date": true,
  "include_types": ["projects", "tasks", "events"]
}
```

## Calendar Support

The system provides extensive calendar support:

1. **Date-based Filtering**: All list operations support date range filtering
2. **Time-specific Events**: Events can have specific start/end times or be all-day
3. **Unified Calendar View**: Single tool to view all projects, tasks, and events
4. **Date Grouping**: Optional grouping by date for calendar rendering
5. **User Filtering**: Filter calendar items by assigned user or attendee

## Security

All tools implement proper security measures:
- Capability checks (requires appropriate WordPress capabilities)
- Input sanitization using WordPress functions
- Output escaping where applicable
- User context validation
- Multisite compatibility checks

### Capability Flags
Each tool declares its capability flags:
- **database-write**: Tools that modify data
- **destructive**: Tools that delete data (delete operations)
- **read-only**: Tools that only read data (list operations)

## Files Created

### Tool Files (13 files)
- `includes/tools/class-wp-mcp-ai-tool-create-project.php`
- `includes/tools/class-wp-mcp-ai-tool-update-project.php`
- `includes/tools/class-wp-mcp-ai-tool-delete-project.php`
- `includes/tools/class-wp-mcp-ai-tool-list-projects.php`
- `includes/tools/class-wp-mcp-ai-tool-create-task.php`
- `includes/tools/class-wp-mcp-ai-tool-update-task.php`
- `includes/tools/class-wp-mcp-ai-tool-delete-task.php`
- `includes/tools/class-wp-mcp-ai-tool-list-tasks.php`
- `includes/tools/class-wp-mcp-ai-tool-create-event.php`
- `includes/tools/class-wp-mcp-ai-tool-update-event.php`
- `includes/tools/class-wp-mcp-ai-tool-delete-event.php`
- `includes/tools/class-wp-mcp-ai-tool-list-events.php`
- `includes/tools/class-wp-mcp-ai-tool-get-calendar-view.php`

### Infrastructure Files
- `includes/project-management-init.php` - CPT registration
- Modified: `includes/admin/sections/class-wp-mcp-ai-section-tools.php` - Feature toggle
- Modified: `includes/class-wp-mcp-ai-tool-registry.php` - Tool registration
- Modified: `mcp-ai-wpoos.php` - Load initialization

## Testing

All PHP files have been syntax-checked and pass validation:
- No syntax errors in any tool files
- All classes properly implement required interfaces
- Parameter schemas are correctly defined
- Tools instantiate successfully

## Future Enhancements

Potential additions for future versions:
1. Time tracking for tasks
2. Task dependencies
3. Gantt chart visualization
4. Recurring events
5. Email notifications for deadlines
6. File attachments to projects/tasks
7. Task comments and activity log
8. Kanban board view
9. Calendar export (iCal format)
10. Integration with external calendar services

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: See `docs/` directory for more information
