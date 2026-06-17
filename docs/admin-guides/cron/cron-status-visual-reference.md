# Cron Status Display - Visual Reference

## UI Component Location

The cron status badge appears in the chat controls area, between the quota monitor and the control buttons:

```
┌─────────────────────────────────────────────────────────────────┐
│  Chat Interface                                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Messages appear here...                                        │
│                                                                  │
├─────────────────────────────────────────────────────────────────┤
│  [  Type your message here...                                  ]│
│                                                                  │
│  📎 Attach   🎤 Transcribe   ➤ Send                            │
├─────────────────────────────────────────────────────────────────┤
│  Quota: 50%          [Jobs: ⏳ 2 ✓ 5]        💾 📤 📋 ➕      │
│  ▲                   ▲                        ▲                 │
│  Quota Monitor       Cron Status              Control Buttons   │
└─────────────────────────────────────────────────────────────────┘
```

## Display States

### Hidden State (No Jobs)
When there are no cron jobs, the badge is completely hidden:

```
Quota: 50%                             💾 📤 📋 ➕
```

### Pending Jobs Only
When there are pending jobs scheduled to run:

```
Quota: 50%   [Jobs: ⏳ 2]             💾 📤 📋 ➕
                    ▲
                    Blue badge with hourglass emoji
                    Shows number of pending jobs
```

### Completed Jobs Only
When there are completed jobs:

```
Quota: 50%   [Jobs: ✓ 5]              💾 📤 📋 ➕
                    ▲
                    Green badge with checkmark
                    Shows number of completed jobs
```

### Both Pending and Completed
When there are both types of jobs:

```
Quota: 50%   [Jobs: ⏳ 2 ✓ 5]        💾 📤 📋 ➕
                    ▲      ▲
                    Blue   Green
                    Pending Completed
```

## Color Coding

### Light Mode
```
┌────────────────────────────────┐
│ Jobs: ⏳ 2  ✓ 5               │  <- Gray background
│       ▲      ▲                 │
│       Blue   Green             │
└────────────────────────────────┘
```

**Pending Badge (Blue):**
- Background: `#2271b1` (WordPress admin blue)
- Text: `#fff` (white)
- Icon: ⏳ (hourglass)

**Completed Badge (Green):**
- Background: `#00a32a` (success green)
- Text: `#fff` (white)
- Icon: ✓ (checkmark)

### Dark Mode
```
┌────────────────────────────────┐
│ Jobs: ⏳ 2  ✓ 5               │  <- Dark gray background
│       ▲      ▲                 │
│       Blue   Green             │
└────────────────────────────────┘
```

**Colors adjust for dark mode:**
- Container: `#1e1e1e` (dark background)
- Badges: Slightly lighter blue/green for better contrast

## Mobile View

On smaller screens, the layout adjusts:

```
┌───────────────────────────────┐
│ Chat Interface                │
├───────────────────────────────┤
│                               │
│ Messages...                   │
│                               │
├───────────────────────────────┤
│ [  Message...              ] │
│                               │
│ 📎  🎤  ➤                    │
├───────────────────────────────┤
│ Quota: 50%                   │
│ Jobs: ⏳ 2 ✓ 5              │
│ 💾 📤 📋 ➕                   │
└───────────────────────────────┘
      ▲
      Status badge stacks
      below quota monitor
```

## Update Behavior

### Initial Load
```
1. Chat loads
2. Status badge hidden (default)
3. After 500ms delay:
   - Fetch cron status from API
   - If jobs exist: Show badge with counts
   - If no jobs: Keep badge hidden
```

### Polling Updates
```
Every 30 seconds:
1. Fetch latest status from API
2. Update badge counts
3. Show/hide badge based on job existence

[Jobs: ⏳ 2 ✓ 5]
       ↓ (30 seconds)
[Jobs: ⏳ 1 ✓ 6]  <- One job completed
       ↓ (30 seconds)
[Jobs: ✓ 7]       <- Last pending job completed
       ↓ (retention period)
(badge hidden)     <- All jobs pruned
```

## Tooltips

Hovering over each badge shows a tooltip:

```
[Jobs: ⏳ 2 ✓ 5]
        ▲
        │
        └─ "Pending jobs" (on hover)
        
[Jobs: ⏳ 2 ✓ 5]
              ▲
              │
              └─ "Completed jobs" (on hover)
```

## Accessibility

### Screen Reader Announcement
```
<div role="status" aria-live="polite" aria-atomic="true">
  Jobs: 2 pending, 5 completed
</div>

When status changes, screen readers announce:
"Jobs: 1 pending, 6 completed"
```

### High Contrast Mode
```
┌────────────────────────────────┐
│ Jobs: ⏳ 2  ✓ 5               │  <- Border added
│  ┌──────┐  ┌──────┐            │
│  │ ⏳ 2 │  │ ✓ 5 │            │  <- Borders on badges
│  └──────┘  └──────┘            │
└────────────────────────────────┘
```

## API Response to Display Mapping

### API Response:
```json
{
  "counts": {
    "pending": 2,
    "completed": 5,
    "total": 7
  }
}
```

### Display:
```
[Jobs: ⏳ 2 ✓ 5]
        │    │
        │    └─ counts.completed
        └────── counts.pending
```

## CSS Classes

### Structure:
```html
<div class="wp-mcp-ai-chat__cron-status">
  <span class="wp-mcp-ai-chat__cron-status-label">Jobs:</span>
  <span class="wp-mcp-ai-chat__cron-status-pending wp-mcp-ai-chat__cron-status-pending--active">
    <span class="wp-mcp-ai-chat__cron-status-count">2</span>
  </span>
  <span class="wp-mcp-ai-chat__cron-status-completed wp-mcp-ai-chat__cron-status-completed--done">
    <span class="wp-mcp-ai-chat__cron-status-count">5</span>
  </span>
</div>
```

### Class Variations:
- `.wp-mcp-ai-chat__cron-status-pending--active` - Applied when count > 0
- `.wp-mcp-ai-chat__cron-status-completed--done` - Applied when count > 0

## User Flow Example

### Creating a Job
```
User types: "Create a cron job to email me daily"

1. AI processes request
2. Executes create_cron_job tool
3. Job created in WordPress
4. Next polling cycle (max 30s):
   - API returns: {pending: 1, completed: 0}
   - Badge appears: [Jobs: ⏳ 1]
```

### Job Execution
```
After 24 hours, job runs:

1. WordPress executes the job
2. Job removed from cron schedule
3. Next polling cycle:
   - API returns: {pending: 0, completed: 1}
   - Badge updates: [Jobs: ✓ 1]
```

### Job Retention
```
After 24 hours (default retention):

1. Cron manager prunes old jobs
2. Next polling cycle:
   - API returns: {pending: 0, completed: 0}
   - Badge hides (no jobs)
```

## Performance Impact

### Initial Load
- 0ms: Chat loads
- 500ms: Cron status initializes
- 500-1000ms: First API call
- Badge appears/hides based on data

### Runtime
- Every 30 seconds: API call (~100-200ms)
- Minimal CPU: Simple DOM updates
- Minimal memory: Small cache (~1KB)

### Network
- Request size: ~100 bytes
- Response size: ~500 bytes (10 jobs)
- Bandwidth: ~17 bytes/second average

## Browser DevTools View

### Network Tab
```
GET /wp-json/mcp-ai/v1/cron-status?limit=10
Status: 200 OK
Size: 487 bytes
Time: 125ms
Frequency: Every 30s
```

### Console (with logging)
```
[Cron Status] Polling started for container: wp-mcp-ai-chat-123
[Cron Status] Status updated: {pending: 2, completed: 5}
[Cron Status] Badge shown
... (30 seconds) ...
[Cron Status] Status updated: {pending: 1, completed: 6}
[Cron Status] Badge updated
```

---

This visual reference shows how the cron status display integrates seamlessly into the existing chat interface while maintaining a clean, minimal design.
