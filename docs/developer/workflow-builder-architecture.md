# Pro Workflow Builder - Visual Architecture

## System Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    WordPress Admin Interface                     │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                     Pro Workflow Builder                  │   │
│  │                (NV oOS Pro → Pro Workflows)               │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       React Application                          │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                    WorkflowBuilder                        │  │
│  │  ┌────────────────────────────────────────────────────┐  │  │
│  │  │              WorkflowToolbar                        │  │  │
│  │  │  [Workflow Name] [Test] [Save]                     │  │  │
│  │  └────────────────────────────────────────────────────┘  │  │
│  │  ┌──────┬──────────────────────────────┬─────────────┐  │  │
│  │  │      │                              │             │  │  │
│  │  │ Work │      ReactFlow Canvas       │  Properties │  │  │
│  │  │ flow │  ┌──────────────────────┐   │    Panel    │  │  │
│  │  │ Side │  │    [Trigger Node]    │   │             │  │  │
│  │  │ bar  │  │         ▼            │   │  Node:      │  │  │
│  │  │      │  │    [Action Node]     │   │  Action     │  │  │
│  │  │ Node │  │         ▼            │   │             │  │  │
│  │  │ Pal- │  │   [Condition Node]   │   │  Label:     │  │  │
│  │  │ ette │  │    ◆ ╱         ╲     │   │  [______]   │  │  │
│  │  │      │  │  True     False      │   │             │  │  │
│  │  │ Trig │  │   ▼          ▼       │   │  Command:   │  │  │
│  │  │ ger  │  │  [A1]      [A2]      │   │  [______]   │  │  │
│  │  │      │  │                      │   │             │  │  │
│  │  │ Actn │  │  [Mini-map]          │   │  Params:    │  │  │
│  │  │ Tool │  │  [Controls]          │   │  {JSON}     │  │  │
│  │  │ Agnt │  └──────────────────────┘   │             │  │  │
│  │  │ Cond │                              │  [Delete]   │  │  │
│  │  │ Loop │                              │             │  │  │
│  │  │ Para │                              │             │  │  │
│  │  │ Dely │                              │             │  │  │
│  │  │ Aprv │                              │             │  │  │
│  │  │ Mrge │                              │             │  │  │
│  │  └──────┴──────────────────────────────┴─────────────┘  │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      AJAX Communication                          │
│                                                                   │
│  ┌──────────────────────────┐    ┌────────────────────────┐    │
│  │   Frontend (React)       │◄──►│  Backend (PHP)         │    │
│  │                          │    │                        │    │
│  │  • Save workflow         │    │  • AJAX handlers       │    │
│  │  • Load workflow         │    │  • Validation          │    │
│  │  • Delete workflow       │    │  • Security checks     │    │
│  │  • Get templates         │    │  • Options storage     │    │
│  └──────────────────────────┘    └────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    WordPress Options Table                       │
│                                                                   │
│  wp_mcp_ai_pro_workflows => [                                   │
│    'workflow-id-1' => {                                          │
│      id, name, description,                                      │
│      nodes: [...],                                               │
│      edges: [...],                                               │
│      created_at, updated_at                                      │
│    }                                                             │
│  ]                                                               │
└─────────────────────────────────────────────────────────────────┘
```

## Component Hierarchy

```
WorkflowBuilder (ReactFlowProvider)
│
├── WorkflowToolbar
│   ├── Name Input
│   ├── Validation Badge
│   ├── Test Button
│   └── Save Button
│
├── WorkflowSidebar
│   ├── Tabs (Nodes | Templates)
│   ├── Node Palette
│   │   ├── Triggers Category
│   │   ├── Actions Category
│   │   ├── Logic Category
│   │   └── Control Category
│   └── Templates List
│
├── ReactFlow Canvas
│   ├── Background
│   ├── Controls (Zoom/Pan)
│   ├── MiniMap
│   ├── Nodes
│   │   ├── TriggerNode
│   │   ├── ActionNode
│   │   ├── ToolNode
│   │   ├── AgentNode
│   │   ├── ConditionNode
│   │   ├── LoopNode
│   │   ├── ParallelNode
│   │   ├── DelayNode
│   │   ├── ApprovalNode
│   │   └── MergeNode
│   ├── Edges (Connections)
│   └── Validation Panel
│
└── WorkflowPropertiesPanel
    ├── Node Header
    ├── Label Input
    ├── Type Display
    ├── Node-specific Config
    │   ├── Action: Command, Params
    │   ├── Condition: Expression
    │   ├── Loop: Items
    │   └── Delay: Duration
    └── Delete Button
```

## Data Flow

```
┌──────────────────┐
│  User Action     │
│  (Drag, Click,   │
│   Configure)     │
└────────┬─────────┘
         ▼
┌──────────────────┐
│  React State     │
│  • nodes         │
│  • edges         │
│  • selectedNode  │
└────────┬─────────┘
         ▼
┌──────────────────┐
│  Event Handler   │
│  (useCallback)   │
└────────┬─────────┘
         ▼
┌──────────────────┐
│  State Update    │
│  (setNodes,      │
│   setEdges)      │
└────────┬─────────┘
         ▼
┌──────────────────┐
│  Re-render       │
│  (React)         │
└────────┬─────────┘
         ▼
┌──────────────────┐
│  DOM Update      │
│  (Visual Change) │
└──────────────────┘
```

## Save Workflow Flow

```
┌─────────────┐
│ User clicks │
│ Save button │
└──────┬──────┘
       ▼
┌─────────────────┐
│ Validate        │
│ • Trigger?      │
│ • Connected?    │
│ • Config set?   │
│ • No cycles?    │
└──────┬──────────┘
       ├─ Errors ──► Show validation errors
       │
       ▼ Valid
┌─────────────────┐
│ Prepare data    │
│ • Serialize     │
│ • Add metadata  │
└──────┬──────────┘
       ▼
┌─────────────────┐
│ AJAX Request    │
│ POST to:        │
│ admin-ajax.php  │
└──────┬──────────┘
       ▼
┌─────────────────┐
│ PHP Handler     │
│ • Check nonce   │
│ • Check cap     │
│ • Sanitize      │
└──────┬──────────┘
       ▼
┌─────────────────┐
│ Save to Options │
│ update_option() │
└──────┬──────────┘
       ▼
┌─────────────────┐
│ Return Success  │
│ wp_send_json_   │
│ success()       │
└──────┬──────────┘
       ▼
┌─────────────────┐
│ Update UI       │
│ • Clear errors  │
│ • Show message  │
└─────────────────┘
```

## Node Type System

```
BaseNode (Component)
├── Props
│   ├── data
│   │   ├── label
│   │   └── config
│   ├── type
│   ├── icon
│   └── color
│
├── Handles
│   ├── Target (top)
│   └── Source (bottom)
│
└── Content
    ├── Header
    │   ├── Icon
    │   └── Type
    └── Body
        ├── Label
        └── Details

Specialized Nodes
│
├── TriggerNode (extends BaseNode)
│   └── Config: event type
│
├── ActionNode (extends BaseNode)
│   └── Config: command, params
│
├── ConditionNode (custom)
│   ├── Config: expression
│   └── Handles: source-true, source-false
│
├── LoopNode (extends BaseNode)
│   └── Config: items
│
└── ... (other types)
```

## Validation System

```
validateWorkflow(nodes, edges)
│
├── Check: Has nodes?
│   └── Error: "Must have at least one node"
│
├── Check: Has trigger?
│   └── Error: "Must have a trigger node"
│
├── For each node:
│   ├── Check: Connected?
│   │   └── Error: "Node not connected"
│   │
│   └── Check: Config set?
│       └── Error: "Missing required config"
│
└── Check: Cycles?
    └── DFS algorithm
        └── Error: "Circular dependencies"
```

## File Organization

```
mcp-ai-wpoos/
│
├── src/workflow-builder/          # React source
│   ├── index.jsx                  # Entry point
│   │
│   ├── components/                # Main components
│   │   ├── WorkflowBuilder.jsx
│   │   ├── WorkflowSidebar.jsx
│   │   ├── WorkflowToolbar.jsx
│   │   └── WorkflowPropertiesPanel.jsx
│   │
│   ├── nodes/                     # Node components
│   │   ├── index.js               # Registry
│   │   ├── BaseNode.jsx           # Base
│   │   ├── TriggerNode.jsx
│   │   ├── ActionNode.jsx
│   │   ├── ToolNode.jsx
│   │   ├── AgentNode.jsx
│   │   ├── ConditionNode.jsx
│   │   ├── LoopNode.jsx
│   │   ├── ParallelNode.jsx
│   │   ├── DelayNode.jsx
│   │   ├── ApprovalNode.jsx
│   │   └── MergeNode.jsx
│   │
│   ├── utils/                     # Utilities
│   │   └── workflowHelpers.js
│   │
│   ├── styles/                    # Styles
│   │   └── workflow-builder.css
│   │
│   └── README.md                  # Component docs
│
├── addons/pro/                    # Pro addon
│   ├── includes/admin/
│   │   └── class-wp-mcp-ai-pro-workflow-builder-page.php
│   │
│   ├── build/workflow-builder/    # Built assets
│   │   ├── index.js               # Compiled
│   │   ├── index.css              # Compiled
│   │   └── index.asset.php        # Deps
│   │
│   └── mcp-ai-wpoos-pro.php       # Pro entry
│
└── docs/                          # Documentation
    ├── pro-workflow-builder.md
    ├── workflow-migration-guide.md
    └── WORKFLOW_BUILDER_IMPLEMENTATION.md
```

## Build Pipeline

```
Source Files (src/workflow-builder/)
         ▼
  wp-scripts build
         ▼
    Webpack/Babel
    ├── Transpile JSX → JS
    ├── Bundle modules
    ├── Minify code
    ├── Extract CSS
    └── Generate assets manifest
         ▼
Build Output (addons/pro/build/workflow-builder/)
    ├── index.js (bundled)
    ├── index.css (compiled)
    └── index.asset.php (deps)
         ▼
WordPress (enqueue)
    ├── wp_enqueue_script('mcp-ai-pro-workflow-builder')
    ├── wp_enqueue_style('mcp-ai-pro-workflow-builder')
    └── wp_localize_script(data)
         ▼
Browser (render)
    └── <div id="mcp-ai-pro-workflow-builder-root">
```

## Technology Stack

```
┌─────────────────────────────────────────┐
│           Frontend Stack                 │
├─────────────────────────────────────────┤
│  • React 18.2.0                         │
│  • ReactFlow 11.10.4                    │
│  • @wordpress/element 5.0.0             │
│  • @wordpress/i18n 4.0.0                │
│  • Modern JavaScript (ES6+)             │
│  • CSS3 (Flexbox, Grid)                 │
└─────────────────────────────────────────┘
          ▼
┌─────────────────────────────────────────┐
│           Build Tools                    │
├─────────────────────────────────────────┤
│  • @wordpress/scripts 31.4.0            │
│  • Webpack 5                            │
│  • Babel                                │
│  • ESLint                               │
└─────────────────────────────────────────┘
          ▼
┌─────────────────────────────────────────┐
│           Backend Stack                  │
├─────────────────────────────────────────┤
│  • PHP 7.4+                             │
│  • WordPress 6.0+                       │
│  • WordPress AJAX API                   │
│  • WordPress Options API                │
└─────────────────────────────────────────┘
```

This visual documentation shows the complete architecture of the Pro Workflow Builder implementation.
