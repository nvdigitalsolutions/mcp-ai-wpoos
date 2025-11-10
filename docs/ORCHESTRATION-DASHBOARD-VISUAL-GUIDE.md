# Orchestration Dashboard Visual Guide

**Last Updated:** November 10, 2024  
**Plugin Version:** 1.0.0  
**Audience:** All Users

---

## Dashboard Overview

The Orchestration Dashboard is located at:
**WordPress Admin → Settings → WP oOS → Orchestration Tab**

---

## Dashboard Layout

```
┌─────────────────────────────────────────────────────────────────┐
│  WP oOS Settings Dashboard                            [Save]    │
├─────────────────────────────────────────────────────────────────┤
│  [General] [Assistants] [Providers] [ORCHESTRATION] [Tools]    │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓  │
│  ┃ ℹ️  About the Orchestration Layer                          ┃  │
│  ┃                                                             ┃  │
│  ┃  The WP oOS Dynamic AI Orchestration Layer extends         ┃  │
│  ┃  standard SSE and MCP implementations with sophisticated   ┃  │
│  ┃  resource management, security enforcement, and            ┃  │
│  ┃  predictive optimization.                                  ┃  │
│  ┃                                                             ┃  │
│  ┃  Key Features:                                             ┃  │
│  ┃  • Real-Time Budget Enforcement                            ┃  │
│  ┃  • Capability-Based Tool Gating                            ┃  │
│  ┃  • Predictive Optimization                                 ┃  │
│  ┃  • Distributed Orchestration                               ┃  │
│  ┃  • Cron-Based Task Management                              ┃  │
│  ┃  • Auditability & Compliance                               ┃  │
│  ┃                                                             ┃  │
│  ┃  [View Full Architecture Documentation]                    ┃  │
│  ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛  │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ ☑ Enable Dynamic Budget Management                       │  │
│  │   Automatically allocate and adjust token budgets based  │  │
│  │   on system resources and workload tier.                 │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ ☑ Enable Predictive Optimization                         │  │
│  │   Use historical usage patterns to forecast and prevent  │  │
│  │   resource exhaustion.                                   │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ ☑ Enable Capability-Based Tool Gating                    │  │
│  │   Enforce WordPress capability checks for tool access    │  │
│  │   based on user roles.                                   │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ ☑ Enable Cron-Based Task Orchestration                   │  │
│  │   Allow AI agents to create and manage scheduled         │  │
│  │   background tasks with inherited budget constraints.    │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │  Current Orchestration Status                             │  │
│  │                                                            │  │
│  │  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌─────┐│  │
│  │  │ Workload   │  │ Max Tokens │  │  Request   │  │Cron ││  │
│  │  │   Tier     │  │            │  │  Timeout   │  │Jobs ││  │
│  │  ├────────────┤  ├────────────┤  ├────────────┤  ├─────┤│  │
│  │  │            │  │            │  │            │  │     ││  │
│  │  │   Medium   │  │   4,000    │  │    60s     │  │  3  ││  │
│  │  │            │  │            │  │            │  │     ││  │
│  │  └────────────┘  └────────────┘  └────────────┘  └─────┘│  │
│  │                                                            │  │
│  │  Quick Actions                                             │  │
│  │  [Manage Cron Jobs] [View Token Manager] [Run Diagnost...]│  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                  │
│                                          [Save Changes]         │
└─────────────────────────────────────────────────────────────────┘
```

---

## Component Breakdown

### 1. Information Panel

**Location:** Top of page  
**Background:** Light blue (#f0f6fc)  
**Border:** 4px solid blue left border

```
┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
┃ ℹ️  About the Orchestration Layer              ┃
┃                                                 ┃
┃  [Descriptive text about the orchestration     ┃
┃   layer features and capabilities]             ┃
┃                                                 ┃
┃  Key Features:                                  ┃
┃  • Feature 1                                    ┃
┃  • Feature 2                                    ┃
┃  • Feature 3                                    ┃
┃                                                 ┃
┃  [View Full Architecture Documentation]        ┃
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
```

**Elements:**
- Heading: "About the Orchestration Layer"
- Description paragraph
- Bulleted list of 6 key features
- Button: "View Full Architecture Documentation" (secondary style)

**Visual Indicators:**
- Info icon (ℹ️) in heading
- Blue accent color (#2271b1)
- Rounded corners (4px border-radius)
- Padding: 1.5rem all sides

---

### 2. Configuration Toggles

**Layout:** Stacked checkboxes with descriptions

```
┌──────────────────────────────────────────────┐
│ ☑ Enable Dynamic Budget Management           │
│   Automatically allocate and adjust token    │
│   budgets based on system resources and      │
│   workload tier.                             │
└──────────────────────────────────────────────┘

State: Checked (enabled)
Checkbox: Blue when checked
Label: Bold font weight
Description: Gray text (#646970), smaller font
```

**Visual States:**

**Enabled (Checked):**
```
☑ Enable Feature Name
```

**Disabled (Unchecked):**
```
☐ Enable Feature Name
```

**Hover State:**
```
┌──────────────────────────────────────────────┐
│ ☑ Enable Feature Name                  ↖️    │  ← Cursor
│   Description text...                        │
└──────────────────────────────────────────────┘
     ↑ Light highlight background
```

---

### 3. Statistics Grid

**Layout:** 4-column responsive grid

```
┌──────────────────────────────────────────────────────┐
│  Current Orchestration Status                         │
│                                                        │
│  ┌───────┐  ┌───────┐  ┌───────┐  ┌───────┐         │
│  │ Label │  │ Label │  │ Label │  │ Label │         │
│  ├───────┤  ├───────┤  ├───────┤  ├───────┤         │
│  │       │  │       │  │       │  │       │         │
│  │ Value │  │ Value │  │ Value │  │ Value │         │
│  │       │  │       │  │       │  │       │         │
│  └───────┘  └───────┘  └───────┘  └───────┘         │
└──────────────────────────────────────────────────────┘
```

**Grid Properties:**
- Columns: `repeat(auto-fit, minmax(200px, 1fr))`
- Gap: 1rem
- Responsive: Collapses to 2 columns on tablet, 1 column on mobile

**Card Design:**
```
┌─────────────────┐
│ Workload Tier   │  ← Label (0.875rem, #646970)
├─────────────────┤
│                 │
│     Medium      │  ← Value (1.5rem, bold, #000)
│                 │
└─────────────────┘
   ↑ White background, 1px border, rounded corners
```

**Color Coding (Future Enhancement):**
- Low Tier: Orange background
- Medium Tier: Blue background  
- High Tier: Green background

---

### 4. Stat Cards Detail

#### Workload Tier Card

```
┌─────────────────┐
│ Workload Tier   │
├─────────────────┤
│                 │
│      Low        │  ← Orange if Low
│     Medium      │  ← Blue if Medium
│      High       │  ← Green if High
│                 │
└─────────────────┘
```

#### Max Tokens Card

```
┌─────────────────┐
│ Max Tokens      │
├─────────────────┤
│                 │
│     1,000       │  ← Comma-separated number
│     4,000       │
│    16,000       │
│                 │
└─────────────────┘
```

#### Request Timeout Card

```
┌─────────────────┐
│ Request Timeout │
├─────────────────┤
│                 │
│      30s        │  ← With 's' suffix
│      60s        │
│     120s        │
│                 │
└─────────────────┘
```

#### Active Cron Jobs Card

```
┌─────────────────┐
│Active Cron Jobs │
├─────────────────┤
│                 │
│       0         │  ← Integer count
│       3         │
│      12         │
│                 │
└─────────────────┘
```

---

### 5. Quick Actions Panel

**Layout:** Horizontal button group

```
┌──────────────────────────────────────────────────────┐
│  Quick Actions                                        │
│                                                        │
│  [Manage Cron Jobs] [View Token Manager] [Run Diag...│]
└──────────────────────────────────────────────────────┘
```

**Button Styles:**
```
┌────────────────────┐
│ Manage Cron Jobs   │  ← Secondary button (gray)
└────────────────────┘

┌────────────────────┐
│ View Token Manager │  ← Secondary button (gray)
└────────────────────┘

┌────────────────────┐
│ Run Diagnostics    │  ← Secondary button (gray)
└────────────────────┘
```

**Hover State:**
```
┌────────────────────┐
│ Manage Cron Jobs   │  ← Darker gray background
└────────────────────┘
      ↑ Cursor pointer
```

---

## Responsive Behavior

### Desktop (>= 1200px)

```
┌─────────────────────────────────────────────────────┐
│                                                      │
│  [Info Panel - Full Width]                          │
│                                                      │
│  [4 Checkboxes - Stacked]                           │
│                                                      │
│  [4 Stat Cards - 4 Columns]                         │
│  │Card│ │Card│ │Card│ │Card│                        │
│                                                      │
│  [3 Buttons - Horizontal]                           │
│  [Btn] [Btn] [Btn]                                  │
│                                                      │
└─────────────────────────────────────────────────────┘
```

### Tablet (768px - 1199px)

```
┌─────────────────────────────┐
│                              │
│  [Info Panel - Full Width]  │
│                              │
│  [4 Checkboxes - Stacked]   │
│                              │
│  [4 Stat Cards - 2 Columns] │
│  │Card│ │Card│               │
│  │Card│ │Card│               │
│                              │
│  [3 Buttons - Horizontal]   │
│  [Btn] [Btn] [Btn]          │
│                              │
└─────────────────────────────┘
```

### Mobile (<= 767px)

```
┌─────────────────┐
│                  │
│  [Info Panel]   │
│                  │
│  [Checkboxes]   │
│                  │
│  [Stat Cards]   │
│  │   Card    │  │
│  │   Card    │  │
│  │   Card    │  │
│  │   Card    │  │
│                  │
│  [Buttons]      │
│  [   Btn    ]   │
│  [   Btn    ]   │
│  [   Btn    ]   │
│                  │
└─────────────────┘
```

---

## PR #852 Enhanced UI (November 2025)

### Configuration Presets Selector

```
┌──────────────────────────────────────────────────────────┐
│  Configuration Presets                                    │
│                                                            │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐        │
│  │ Custom  │ │  Auto   │ │Balanced │ │Conserv. │        │
│  │ DEFAULT │ │RECOMMEND│ │         │ │         │        │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘        │
│      Blue       Green       Gray         Gray            │
│                                                            │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐        │
│  │Aggress. │ │  Dev    │ │High Traf│ │  Burst  │        │
│  │         │ │         │ │         │ │         │        │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘        │
│      Gray       Gray        Gray         Gray            │
│                                                            │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐        │
│  │Cost Opt.│ │Enterpris│ │Failsafe │ │Predict. │        │
│  │         │ │         │ │         │ │         │        │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘        │
│      Gray       Gray        Gray         Gray            │
└──────────────────────────────────────────────────────────┘
```

**Preset Card States:**

**Selected (Active):**
```
┌─────────────┐
│    Auto     │  ← Bold text
│ RECOMMENDED │  ← Badge
│             │
│  ✓ Active   │  ← Checkmark indicator
└─────────────┘
  Green border (3px)
```

**Hover:**
```
┌─────────────┐
│  Balanced   │
│             │  ← Light background
│   Click to  │
│   Apply     │
└─────────────┘
  Shadow effect
```

**Default:**
```
┌─────────────┐
│   Custom    │
│   DEFAULT   │
│             │
│  Current    │
└─────────────┘
  Blue border (2px)
```

### Slider Controls

**Health Monitoring Section:**
```
┌──────────────────────────────────────────────────┐
│  Health Monitoring Thresholds                     │
│                                                    │
│  Memory Warning Threshold                         │
│  50% ═════════●═════════ 95%     [75%]           │
│                                                    │
│  Memory Critical Threshold                        │
│  75% ═════════════●═════ 99%     [90%]           │
│                                                    │
│  Error Rate Warning Threshold                     │
│  5% ═══════●═════════════ 25%    [10%]           │
│                                                    │
│  Error Rate Critical Threshold                    │
│  10% ══════════●═════════ 50%    [20%]           │
└──────────────────────────────────────────────────┘
```

**Slider Component:**
```
Label: Memory Warning Threshold
  ↓
50% ═════════●═════════ 95%     [75%]
 ↑           ↑           ↑         ↑
Min      Current        Max    Live Value
Value    Position      Value   Display
```

**Slider States:**

**Default:**
```
═════════●═════════
```

**Hover:**
```
═════════◉═════════  ← Larger thumb
     ↖️ Cursor
```

**Dragging:**
```
═══●═══════════════  ← Active position
    ↑ Real-time value update: [62%]
```

### Health Status Banner

```
┌──────────────────────────────────────────────────────┐
│  ●  System Health: Healthy                           │
│     Memory: 45% | Errors: 0% | Avg Response: 1.2s   │
└──────────────────────────────────────────────────────┘
   Green background
   
┌──────────────────────────────────────────────────────┐
│  ⚠  System Health: Warning                           │
│     Memory: 78% | Errors: 12% | Avg Response: 3.5s  │
└──────────────────────────────────────────────────────┘
   Yellow background
   
┌──────────────────────────────────────────────────────┐
│  ✖  System Health: Critical                          │
│     Memory: 92% | Errors: 22% | Avg Response: 8.1s  │
└──────────────────────────────────────────────────────┘
   Red background
```

### Memory Usage Progress Bar

```
┌──────────────────────────────────────────────────────┐
│  Memory Usage                                         │
│  ████████████░░░░░░░░░░░░░░░░░░░░░░░░░  45%         │
└──────────────────────────────────────────────────────┘
   Green = Safe
   Yellow = Warning
   Red = Critical
```

**Color Thresholds:**
- 0-75%: Green (#10b981)
- 75-90%: Yellow (#f59e0b)
- 90-100%: Red (#ef4444)

### Predictive Insights Panel

```
┌──────────────────────────────────────────────────────┐
│  🔮 Predictive Insights                               │
│                                                        │
│  Based on current trends:                             │
│  • Token usage increasing by 15% over past 24h        │
│  • Consider switching to High tier (85% confidence)  │
│  • Recommend 4,000 → 6,000 token limit               │
└──────────────────────────────────────────────────────┘
```

---

## Color Palette

### Primary Colors

| Element | Color | Hex | Usage |
|---------|-------|-----|-------|
| Info Panel Background | Light Blue | `#f0f6fc` | Information boxes |
| Primary Accent | Blue | `#2271b1` | Borders, buttons |
| Text Primary | Black | `#000000` | Main content |
| Text Secondary | Gray | `#646970` | Descriptions |
| Border | Light Gray | `#dcdcde` | Card borders |

### Status Colors

| Status | Color | Hex | Usage |
|--------|-------|-----|-------|
| Success/Healthy | Green | `#10b981` | Positive states |
| Warning | Yellow | `#f59e0b` | Caution states |
| Critical/Error | Red | `#ef4444` | Error states |
| Info | Blue | `#2271b1` | Information |

### Preset Colors

| Preset | Border Color | Badge Color |
|--------|--------------|-------------|
| Custom (DEFAULT) | Blue (`#2271b1`) | Blue |
| Auto (RECOMMENDED) | Green (`#10b981`) | Green |
| Others | Gray (`#dcdcde`) | - |

---

## Typography

### Font Families

```css
/* Primary font stack */
font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, 
             Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
```

### Font Sizes

| Element | Size | Weight | Color |
|---------|------|--------|-------|
| Page Heading | 23px | 400 | #1d2327 |
| Section Heading | 18px | 600 | #1d2327 |
| Card Label | 14px (0.875rem) | 400 | #646970 |
| Card Value | 24px (1.5rem) | 600 | #000000 |
| Body Text | 13px | 400 | #3c434a |
| Button Text | 13px | 400 | #2271b1 |

---

## Interactive Elements

### Buttons

**Primary Button:**
```
┌──────────────┐
│ Save Changes │  Background: #2271b1
└──────────────┘  Text: White
                   Border-radius: 3px
                   Padding: 0 12px
                   Height: 30px
```

**Secondary Button:**
```
┌────────────────┐
│ Manage Cron... │  Background: White
└────────────────┘  Text: #2271b1
                     Border: 1px solid #2271b1
                     Border-radius: 3px
```

**Button Hover:**
```
┌──────────────┐
│ Save Changes │  Background: #135e96 (darker)
└──────────────┘  Cursor: pointer
```

### Checkboxes

**Unchecked:**
```
☐  Label Text
```

**Checked:**
```
☑  Label Text
```

**Hover:**
```
☐  Label Text  ← Border highlight
   ↑ Cursor pointer
```

### Links

**Default:**
```
View Documentation
───────────────────
Blue (#2271b1)
No underline
```

**Hover:**
```
View Documentation
═══════════════════
Darker blue (#135e96)
Underline appears
```

---

## Accessibility Features

### Keyboard Navigation

```
Tab Order:
1. Configuration Toggle 1
2. Configuration Toggle 2
3. Configuration Toggle 3
4. Configuration Toggle 4
5. Quick Action Button 1
6. Quick Action Button 2
7. Quick Action Button 3
8. Save Button
```

### Screen Reader Labels

```html
<!-- Stat cards have aria-label -->
<div aria-label="Workload Tier: Medium">
    <div>Workload Tier</div>
    <div>Medium</div>
</div>

<!-- Buttons have aria-label -->
<button aria-label="Manage scheduled cron jobs">
    Manage Cron Jobs
</button>
```

### Color Contrast

All text meets WCAG AA standards:
- Primary text: 11.9:1 contrast ratio
- Secondary text: 7.0:1 contrast ratio
- Button text: 4.8:1 contrast ratio

---

## Animation & Transitions

### Hover Effects

```css
/* Button hover */
transition: background-color 0.2s ease;

/* Card hover */
transition: box-shadow 0.2s ease;

/* Slider thumb */
transition: transform 0.1s ease;
```

### Loading States

**Statistics Loading:**
```
┌─────────────────┐
│ Workload Tier   │
├─────────────────┤
│                 │
│   ⟳ Loading...  │  ← Spinner icon
│                 │
└─────────────────┘
```

**Preset Application:**
```
┌─────────────┐
│    Auto     │
│ RECOMMENDED │
│             │
│  ✓ Applied  │  ← Success checkmark
└─────────────┘
  Fade-in animation (0.3s)
```

---

## Error States

### Configuration Save Error

```
┌──────────────────────────────────────────────────────┐
│  ✖  Error saving settings. Please try again.         │
└──────────────────────────────────────────────────────┘
   Red background
   White text
   Dismiss button (×)
```

### Statistics Load Error

```
┌─────────────────┐
│ Workload Tier   │
├─────────────────┤
│                 │
│  ⚠ Unavailable  │
│                 │
└─────────────────┘
   Gray background
   Gray text
```

---

## Success States

### Settings Saved

```
┌──────────────────────────────────────────────────────┐
│  ✓  Settings saved successfully.                     │
└──────────────────────────────────────────────────────┘
   Green background
   White text
   Auto-dismiss after 3 seconds
```

### Preset Applied

```
┌──────────────────────────────────────────────────────┐
│  ✓  "Balanced" preset applied. Save to confirm.      │
└──────────────────────────────────────────────────────┘
   Blue background
   White text
```

---

## Related Documentation

- [ORCHESTRATION-DASHBOARD-IMPLEMENTATION.md](ORCHESTRATION-DASHBOARD-IMPLEMENTATION.md) - Technical implementation
- [ORCHESTRATION-DASHBOARD-SUMMARY.md](ORCHESTRATION-DASHBOARD-SUMMARY.md) - User guide
- [ORCHESTRATION-DASHBOARD-FINDINGS.md](ORCHESTRATION-DASHBOARD-FINDINGS.md) - Search findings

---

**Note:** Screenshots and actual UI images will be added in future updates. This visual guide uses ASCII art and detailed descriptions to illustrate the dashboard layout and components.

---

**Maintained by:** NV Digital Solutions  
**Documentation Repository:** https://github.com/nvdigitalsolutions/wp-mcp-ai  
**License:** GPLv3 or later
