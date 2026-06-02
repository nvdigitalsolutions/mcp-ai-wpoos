# Project Management Manual Interface User Guide

## Overview

The NV oOS plugin provides a complete manual management interface for managing projects, tasks, and events through the WordPress admin dashboard. This interface is enhanced with AI-powered features to streamline project management workflows.

## Accessing the Interface

After enabling Project Management in **Settings → NV oOS → Enable Project Management**, three new menu items appear in the WordPress admin:

- **Projects** - Manage all projects
- **Tasks** - Manage all tasks
- **Events** - Manage all calendar events

## Managing Projects

### Creating a Project

1. Navigate to **Projects → Add New**
2. Enter a project title
3. Fill in the project details in the metabox:
   - **Status**: Planning, Active, On Hold, Completed, or Cancelled
   - **Start Date**: Project start date
   - **End Date**: Project end date
   - **Team Members**: Select multiple users assigned to the project
4. Add a description in the main editor
5. Click **Publish**

### AI-Assisted Project Creation

The AI Assistant metabox provides several quick actions:

#### Generate Description
- Click **Generate Description** to have AI create a professional project description based on the title
- The AI considers the project title and automatically creates a clear, actionable description
- The generated description appears in the main editor

#### Suggest Tasks
- Click **Suggest Tasks** to have AI recommend tasks needed to complete the project
- AI analyzes the project title and description
- Returns 5 specific task suggestions
- Click **Create These Tasks** to automatically create all suggested tasks

#### Analyze Project
- Click **Analyze Project** to get AI-powered insights
- AI analyzes:
  - Overall progress based on task completion
  - Potential risks or blockers
  - Actionable recommendations
- Results display in the AI Assistant panel

### Project List View

The Projects list displays:
- **Status** - Color-coded status badge
- **Start Date** - Project start date
- **End Date** - Project end date
- **Team Members** - Assigned users
- **Author** - Project creator
- **Date** - Creation date

**Sortable Columns**: Click column headers to sort by status, dates, etc.

**Bulk Operations**: Select multiple projects and choose from:
- **AI: Generate Descriptions** - Auto-generate descriptions for projects without them
- **AI: Analyze Selected** - Analyze multiple projects at once
- **AI: Optimize & Improve** - Enhance titles and descriptions

## Managing Tasks

### Creating a Task

1. Navigate to **Tasks → Add New**
2. Enter a task title
3. Fill in the task details:
   - **Status**: To Do, In Progress, Review, Completed, or Cancelled
   - **Priority**: Low, Medium, High, or Urgent
   - **Project**: Link to a parent project (optional)
   - **Due Date**: Task deadline
   - **Assigned To**: User responsible for the task
4. Add task details in the description
5. Click **Publish**

### AI-Assisted Task Management

#### Generate Description
- Click **Generate Description** to create a detailed task description
- AI analyzes the task title and creates actionable steps

#### Estimate Duration
- Click **Estimate Duration** to get AI time estimates
- AI considers task complexity and provides realistic timeframes
- Helps with project planning and resource allocation

### Task List View

The Tasks list displays:
- **Status** - Color-coded status badge (To Do, In Progress, etc.)
- **Priority** - Color-coded priority badge (Low to Urgent)
- **Project** - Linked parent project
- **Due Date** - Deadline (overdue dates highlighted in red)
- **Assigned To** - Responsible user
- **Author** - Task creator
- **Date** - Creation date

**Quick Filters**: Filter tasks by status, priority, or related project

**Bulk Operations**: Select multiple tasks for bulk AI processing

## Managing Events

### Creating an Event

1. Navigate to **Events → Add New**
2. Enter an event title
3. Fill in the event details:
   - **Event Type**: Meeting, Deadline, Milestone, Reminder, or Other
   - **All-day event**: Check if event spans full day
   - **Start Date**: Event start date
   - **Start Time**: Event start time (hidden for all-day events)
   - **End Date**: Event end date
   - **End Time**: Event end time (hidden for all-day events)
   - **Location**: Event location or meeting link
   - **Related Project**: Link to a project (optional)
   - **Attendees**: Select multiple users attending
4. Add event details in the description
5. Click **Publish**

### AI-Assisted Event Planning

#### Generate Description
- Click **Generate Description** to create event details
- AI creates a clear event overview

#### Suggest Agenda
- Click **Suggest Agenda** to get AI-generated meeting agendas
- AI considers event title, type, and attendees
- Creates structured agenda items
- Agenda appears in the event description

### Event List View

The Events list displays:
- **Type** - Color-coded event type badge
- **Start Date** - Event start date and time
- **End Date** - Event end date and time
- **Location** - Event location
- **Project** - Related project link
- **Author** - Event creator
- **Date** - Creation date

**Calendar View Integration**: Events can be displayed in calendar format using the `get_calendar_view` AI tool

## AI Features

### AI Assistant Metabox

The AI Assistant appears on all project, task, and event edit screens with context-aware actions:

**For Projects:**
- Generate Description
- Suggest Tasks
- Analyze Project

**For Tasks:**
- Generate Description
- Estimate Duration

**For Events:**
- Generate Description
- Suggest Agenda

### Bulk AI Operations

Available in all list views (Projects, Tasks, Events):

1. Select multiple items using checkboxes
2. Choose **Bulk Actions** dropdown
3. Select an AI operation:
   - **AI: Generate Descriptions** - Creates descriptions for items without them
   - **AI: Analyze Selected** - Analyzes all selected items and stores insights
   - **AI: Optimize & Improve** - Enhances titles and descriptions for better clarity
4. Click **Apply**
5. View results in admin notice

**Note**: AI operations require at least one AI assistant to be configured in the system. Create an assistant at **AI Assistants → Add New** if needed.

## Permissions

Project management features respect WordPress capabilities:

- **View**: Users with `read` capability
- **Create/Edit**: Users with `edit_posts` capability
- **Delete**: Users with `delete_posts` capability
- **Assign to Others**: Users with `edit_others_posts` capability

## Best Practices

### Project Organization
1. Create projects before tasks for better organization
2. Use meaningful, descriptive titles
3. Set realistic start and end dates
4. Assign appropriate team members

### Task Management
1. Break down projects into specific, actionable tasks
2. Set priorities to help team focus
3. Use due dates for accountability
4. Link tasks to projects for context

### Event Planning
1. Use event types consistently
2. Include location/meeting links
3. Add attendees for notifications
4. Link events to relevant projects

### Using AI Effectively
1. Start with clear, descriptive titles
2. Use "Generate Description" for quick drafts
3. Review and refine AI suggestions
4. Use "Analyze Project" regularly for insights
5. Leverage bulk operations for efficiency

## Troubleshooting

### AI Features Not Working
- Ensure at least one AI assistant is created and published
- Check AI provider API keys in settings
- Verify user has sufficient permissions
- Check browser console for JavaScript errors

### Metaboxes Not Appearing
- Verify Project Management is enabled in settings
- Check user capabilities
- Ensure plugin is fully activated

### Bulk Operations Timing Out
- Process items in smaller batches (10-20 at a time)
- Check server PHP execution time limits
- Monitor AI API rate limits

## Integration with AI Tools

The manual interface seamlessly integrates with existing AI tools:

- **create_project**, **update_project**, **list_projects**, **delete_project** - Project CRUD
- **create_task**, **update_task**, **list_tasks**, **delete_task** - Task CRUD
- **create_event**, **update_event**, **list_events**, **delete_event** - Event CRUD
- **get_calendar_view** - Calendar integration

Both manual and AI-driven workflows work together, allowing teams to manage projects through the interface while AI assistants can also create, update, and query the same data.

## Related Documentation

- [Project Management AI Tools](../tool-reference.md#project-management-tools)
- [AI Assistant Configuration](../guides/user/ai-assistants.md)
- [REST API for Project Management](../rest-api.md#project-management-endpoints)
