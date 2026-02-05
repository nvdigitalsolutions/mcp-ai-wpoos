# Pro Dashboard Monitoring Page - Visual Guide

## Page Layout Overview

```
┌─────────────────────────────────────────────────────────────────┐
│ NV oOS Pro Dashboard                              [PRO] [Actions]│
├─────────────────────────────────────────────────────────────────┤
│ [ISO 27001] [Overview] [Reports] [Monitoring*] [Risk] [Multi-Fw]│
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│ Monitor security events, system health, and compliance...        │
│                                                                   │
│ ┌────────────────────────────────────────────────────────────┐  │
│ │ FILTERS: Event Type ▼ | Severity ▼ | Timeframe ▼ | Search  │  │
│ └────────────────────────────────────────────────────────────┘  │
│                                                                   │
│ ┌─────────────┬─────────────┬─────────────┬─────────────┐      │
│ │   🛡️        │   ⚠️        │   ℹ️        │   🕐        │      │
│ │ Operational │     0       │    12       │   15d 8h    │      │
│ │ Security    │ Critical    │ Total       │ System      │      │
│ │ Status      │ Events (24h)│ Events (24h)│ Uptime      │      │
│ └─────────────┴─────────────┴─────────────┴─────────────┘      │
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ [🔄 Refresh] [☑ Auto-refresh (30s)] [⬇ Export] Last: 14:32  │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│ ┌────────────┬────────────────┬──────────────────────────────┐ │
│ │ SYSTEM     │ MONITORED      │ EVENT TIMELINE (24h)         │ │
│ │ HEALTH     │ RESOURCES      │                              │ │
│ │            │                │  📊 Chart showing events/hour│ │
│ │ [✓] DB     │ ✓ File Int. 3  │                              │ │
│ │ [✓] PHP    │ ✓ Auth      8  │  ┌────┬────┬────┬────┐      │ │
│ │ [✓] WP     │ ✓ Updates   2  │  │ ▄  │ ▄▄ │ ▄  │ ▄  │      │ │
│ │ [✓] Memory │ ✓ Config    1  │  │ █  │ ██ │ █  │ █  │      │ │
│ │            │ ✓ Security  0  │  └────┴────┴────┴────┘      │ │
│ └────────────┴────────────────┴──────────────────────────────┘ │
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ REAL-TIME EVENT LOG                    [Clear Dismissed]     │ │
│ ├──────────┬────────┬──────────────────┬──────────┬─────────┤ │
│ │ Severity │ Type   │ Message          │ Time     │ Actions │ │
│ ├──────────┼────────┼──────────────────┼──────────┼─────────┤ │
│ │ [HIGH]   │ 🔒 Auth│ Failed login     │ 2m ago   │ [Dismiss]│ │
│ │ [INFO]   │ 📄 File│ Plugin updated   │ 15m ago  │ [Dismiss]│ │
│ │ [LOW]    │ ⚙️ Conf│ Settings changed │ 1h ago   │ [Dismiss]│ │
│ └──────────┴────────┴──────────────────┴──────────┴─────────┘ │
│                                                                   │
│ Showing 12 events                            [Load More]         │
└─────────────────────────────────────────────────────────────────┘
```

## Component Details

### 1. Status Metrics (Top Row)

```
┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐
│   🛡️        │  │   ⚠️        │  │   ℹ️        │  │   🕐        │
│             │  │             │  │             │  │             │
│ OPERATIONAL │  │      0      │  │     12      │  │   15d 8h    │
│             │  │             │  │             │  │             │
│ Security    │  │ Critical    │  │ Total       │  │ System      │
│ Status      │  │ Events (24h)│  │ Events (24h)│  │ Uptime      │
└─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘

Colors:
- Operational (green border-left)
- Warning (orange border-left)
- Error (red border-left)
```

### 2. Monitoring Options Bar

```
┌─────────────────────────────────────────────────────────────────┐
│ [🔄 Refresh Now] [☑ Auto-refresh (30s)] [⬇ Export Events]       │
│                                      Last updated: 14:32:15      │
└─────────────────────────────────────────────────────────────────┘

Features:
- Manual refresh with loading animation
- Auto-refresh toggle (30 second intervals)
- Export to CSV functionality
- Real-time last update timestamp
```

### 3. System Health Card

```
┌────────────────────────────┐
│ SYSTEM HEALTH [Operational]│
├────────────────────────────┤
│ ✓  Database Connection     │
│    Healthy                 │
├────────────────────────────┤
│ ✓  PHP Version             │
│    8.1.2                   │
├────────────────────────────┤
│ ✓  WordPress Version       │
│    6.4.2                   │
├────────────────────────────┤
│ ✓  Memory Usage            │
│    64M / 256M              │
└────────────────────────────┘

Indicators:
- Green ✓ = Healthy
- Orange ⚠ = Warning
- Red ✗ = Error
```

### 4. Monitored Resources

```
┌────────────────────────────┐
│ MONITORED RESOURCES        │
├────────────────────────────┤
│ ✓ File Integrity Monitoring│
│   3 events                 │
├────────────────────────────┤
│ ✓ Authentication Events    │
│   8 events                 │
├────────────────────────────┤
│ ✓ Plugin & Theme Updates   │
│   2 events                 │
├────────────────────────────┤
│ ✓ Configuration Changes    │
│   1 events                 │
├────────────────────────────┤
│ ✓ Security Alerts          │
│   0 events                 │
└────────────────────────────┘

Features:
- Real-time event counts
- Color-coded by category
- Links to filtered views
```

### 5. Event Timeline Chart

```
┌──────────────────────────────────────┐
│ EVENT TIMELINE (24h)                 │
├──────────────────────────────────────┤
│                                      │
│  Events                              │
│   10┤                                │
│    8┤     ▄▄                         │
│    6┤   ▄▄██▄▄                       │
│    4┤ ▄▄██████▄▄                     │
│    2┤▄██████████▄▄▄                  │
│    0└──────────────────────────────┘ │
│      0h  6h  12h  18h  24h           │
└──────────────────────────────────────┘

Features:
- 24-hour rolling window
- Hover tooltips
- Smooth line transitions
- Auto-updates with data
```

### 6. Event Log Table

```
┌──────────────────────────────────────────────────────────────────┐
│ REAL-TIME EVENT LOG                         [Clear Dismissed]    │
├──────────┬────────┬───────────────────────┬──────────┬──────────┤
│ Severity │ Type   │ Message               │ Time     │ Actions  │
├──────────┼────────┼───────────────────────┼──────────┼──────────┤
│ [CRITICAL]│ 🔒 Auth│ Multiple failed login │ 2m ago   │[Dismiss] │
│   (red)  │        │ attempts from IP      │          │[Details] │
├──────────┼────────┼───────────────────────┼──────────┼──────────┤
│ [HIGH]   │ 📄 File│ Unauthorized file     │ 15m ago  │[Dismiss] │
│ (orange) │        │ modification detected │          │[Details] │
├──────────┼────────┼───────────────────────┼──────────┼──────────┤
│ [MEDIUM] │ ⚙️ Conf│ Security settings     │ 1h ago   │[Dismiss] │
│ (yellow) │        │ changed by admin      │          │[Details] │
├──────────┼────────┼───────────────────────┼──────────┼──────────┤
│ [LOW]    │ 🔄 Upd │ Plugin updated to     │ 2h ago   │[Dismiss] │
│  (blue)  │        │ version 2.1.0         │          │[Details] │
├──────────┼────────┼───────────────────────┼──────────┼──────────┤
│ [INFO]   │ ℹ️ Info│ Scheduled backup      │ 3h ago   │[Dismiss] │
│  (gray)  │        │ completed             │          │[Details] │
└──────────┴────────┴───────────────────────┴──────────┴──────────┘

Showing 5 of 12 events                                 [Load More]

Features:
- Color-coded severity badges
- Event type icons
- Human-readable timestamps
- Dismiss and Details actions
- Pagination with load more
```

### 7. Event Details Modal

```
┌────────────────────────────────────────┐
│ Event Details                      [×] │
├────────────────────────────────────────┤
│                                        │
│ Severity: [CRITICAL]                   │
│                                        │
│ Type: Authentication                   │
│                                        │
│ Message:                               │
│ Multiple failed login attempts         │
│ detected from IP address 192.168.1.100 │
│                                        │
│ Timestamp:                             │
│ January 7, 2024 at 2:30 PM            │
│                                        │
│ Additional Details:                    │
│ - IP: 192.168.1.100                   │
│ - Username attempted: admin            │
│ - Attempts: 5                          │
│ - Status: IP temporarily blocked       │
│                                        │
│               [Close]                  │
└────────────────────────────────────────┘

Features:
- Click outside to close
- Full event information
- Formatted timestamp
- Additional context
```

## Color Scheme

### Severity Badges
- **Critical**: #dc3232 (red) - White text
- **High**: #ff9800 (orange) - White text
- **Medium**: #ffc107 (yellow) - Dark text
- **Low**: #2196f3 (blue) - White text
- **Info**: #e0e0e0 (gray) - Dark text

### Status Indicators
- **Operational/Healthy**: #46b450 (green)
- **Warning**: #ff9800 (orange)
- **Error**: #dc3232 (red)

### UI Elements
- **Primary**: #0073aa (WordPress blue)
- **Background**: #ffffff (white cards)
- **Border**: #e0e0e0 (light gray)
- **Text**: #1d2327 (dark gray)
- **Secondary text**: #646970 (medium gray)

## Responsive Behavior

### Desktop (>1200px)
- 4-column metrics grid
- Full table with all columns
- Side-by-side cards

### Tablet (768-1200px)
- 2-column metrics grid
- Full table with some columns
- Stacked cards

### Mobile (<768px)
- Single column metrics
- Simplified table (severity + message + actions only)
- Full-width cards
- Vertical filter layout

## Interactions

1. **Hover States**
   - Metric cards elevate slightly
   - Table rows highlight
   - Buttons show focus ring

2. **Click Actions**
   - Dismiss: Fade out row with animation
   - Details: Show modal with overlay
   - Export: Download CSV file
   - Refresh: Show loading state

3. **Real-time Updates**
   - Auto-refresh pulse animation
   - Timestamp updates every 30s
   - New events fade in
   - Event counts update

## Accessibility

- Keyboard navigation support
- ARIA labels on interactive elements
- Screen reader friendly
- Color contrast compliance (WCAG AA)
- Focus indicators on all controls

## Performance

- Efficient DOM updates
- Debounced search (300ms)
- Lazy loading with pagination
- GPU-accelerated animations
- Optimized chart rendering
