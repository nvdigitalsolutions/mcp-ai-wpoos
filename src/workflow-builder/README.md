# Pro Workflow Builder

Modern visual workflow builder for NV oOS WordPress plugin, implementing 2026 industry best practices from n8n, Zapier, Make, Vellum, and CrewAI.

## Features

### ✅ Phase 1 Complete - Core Visual Workflow Builder

- **React-based UI** using ReactFlow for node-based visual editing
- **Drag-and-drop interface** for building workflows intuitively
- **10+ Node Types**:
  - **Trigger** - Start workflow execution
  - **Action** - Execute slash commands
  - **Tool** - Use MCP tools
  - **Agent** - Call AI agents
  - **Condition** - Branch logic based on conditions
  - **Loop** - Iterate over items
  - **Parallel** - Run multiple actions simultaneously
  - **Delay** - Wait for specified time
  - **Approval** - Human-in-the-loop approval gates
  - **Merge** - Combine parallel execution outputs

- **Node Properties Panel** - Configure each node with:
  - Custom labels
  - Commands and parameters
  - Conditional expressions
  - Loop configurations
  - Timing settings

- **Visual Flow Canvas** with:
  - Zoom and pan controls
  - Mini-map for navigation
  - Background grid
  - Color-coded nodes by type
  - Animated connections

- **Workflow Validation** - Real-time validation checking:
  - Required trigger node
  - Connected nodes
  - Required configurations
  - Circular dependency detection

- **Save/Load System** - Workflows stored in WordPress options
- **PHP Admin Integration** - Seamless integration with WordPress admin

### 🚧 Phase 2 - Pro Features (Planned)

- Workflow templates based on 8 multi-agent patterns
- Undo/redo functionality
- Workflow versioning
- Variables and data mapping
- Advanced conditional branching
- Nested loop support

### 🔮 Phase 3 - Advanced Capabilities (Planned)

- Workflow debugging mode
- Execution history and audit trails
- Metrics dashboard
- Import/export workflows
- AI-assisted workflow suggestions
- Template marketplace

## Architecture

### Frontend (React + ReactFlow)

```
src/workflow-builder/
├── index.jsx                      # Main entry point
├── components/
│   ├── WorkflowBuilder.jsx        # Main component
│   ├── WorkflowSidebar.jsx        # Node palette
│   ├── WorkflowToolbar.jsx        # Top toolbar
│   └── WorkflowPropertiesPanel.jsx # Node editor
├── nodes/
│   ├── index.js                   # Node registry
│   ├── BaseNode.jsx               # Base node component
│   ├── ActionNode.jsx
│   ├── TriggerNode.jsx
│   ├── ConditionNode.jsx
│   ├── LoopNode.jsx
│   ├── ParallelNode.jsx
│   ├── DelayNode.jsx
│   ├── ApprovalNode.jsx
│   ├── ToolNode.jsx
│   ├── AgentNode.jsx
│   └── MergeNode.jsx
├── utils/
│   └── workflowHelpers.js         # Validation & utilities
└── styles/
    └── workflow-builder.css       # Modern UI styles
```

### Backend (PHP)

```
addons/pro/includes/admin/
└── class-wp-mcp-ai-pro-workflow-builder-page.php
```

**Key Features:**
- Admin page registration
- AJAX handlers for save/load/delete
- Workflow validation
- Integration with pattern templates
- WordPress options storage

## Building

### Development Build

```bash
npm run build:workflow
```

This builds the React app to `addons/pro/build/workflow-builder/`.

### Full Production Build

```bash
npm run build:full
```

Builds all assets including the workflow builder.

## Installation

The Pro Workflow Builder is part of the Pro addon and loads automatically when:

1. Pro addon is enabled (not in `WP_MCP_AI_BASE_VERSION` mode)
2. User has `manage_options` capability

Access via: **WordPress Admin → NV oOS → Pro Workflows**

## Usage

### Creating a Workflow

1. Navigate to **NV oOS → Pro Workflows**
2. Click **New Workflow** in the sidebar
3. Drag nodes from the palette onto the canvas
4. Connect nodes by dragging from output handle to input handle
5. Click nodes to configure their properties
6. Save the workflow

### Node Configuration

**Action Node:**
- Command: Slash command to execute (e.g., `/search`)
- Parameters: JSON object with command parameters

**Condition Node:**
- Expression: JavaScript-like expression (e.g., `result.status === 'success'`)
- Creates true/false branches

**Loop Node:**
- Items: Array to iterate over (e.g., `{{previous.results}}`)
- Executes connected nodes for each item

**Delay Node:**
- Duration: Wait time in seconds

### Workflow Validation

The builder automatically validates:
- ✓ At least one trigger node exists
- ✓ All nodes are connected
- ✓ Required configurations are set
- ✓ No circular dependencies

Validation errors appear in the top-right corner.

## Best Practices (from 2026 Industry Standards)

### 1. Iterative Design
- Start simple, test frequently
- Build workflows incrementally
- Use the test button to validate before deployment

### 2. Human-in-the-Loop
- Use Approval nodes for critical decisions
- Add delays for rate limiting
- Implement error handling with conditions

### 3. Transparency
- Use descriptive node labels
- Document complex logic
- Review execution logs

### 4. Modularity
- Break complex workflows into smaller workflows
- Reuse workflow patterns
- Use templates for common scenarios

### 5. Security
- Validate all inputs
- Use secure credential storage
- Implement approval gates for sensitive operations

## Technical Details

### Dependencies

- `react` ^18.2.0
- `react-dom` ^18.2.0
- `reactflow` ^11.10.4
- `@wordpress/element` ^5.0.0
- `@wordpress/i18n` ^4.0.0

### State Management

The workflow builder uses React hooks for state:
- `useNodesState` - Manages workflow nodes
- `useEdgesState` - Manages node connections
- `useState` - UI state (selected node, validation errors)
- `useCallback` - Memoized event handlers

### Data Structure

**Workflow:**
```json
{
  "id": "workflow-id",
  "name": "My Workflow",
  "description": "Workflow description",
  "nodes": [
    {
      "id": "trigger-123456",
      "type": "trigger",
      "position": { "x": 100, "y": 100 },
      "data": {
        "label": "Start",
        "config": {}
      }
    }
  ],
  "edges": [
    {
      "id": "edge-123",
      "source": "trigger-123456",
      "target": "action-789012"
    }
  ],
  "created_at": 1706832000,
  "updated_at": 1706832100
}
```

### AJAX Endpoints

- `wp_mcp_ai_save_pro_workflow` - Save workflow
- `wp_mcp_ai_load_pro_workflow` - Load workflow by ID
- `wp_mcp_ai_delete_pro_workflow` - Delete workflow
- `wp_mcp_ai_get_workflow_templates` - Get pattern templates

## Roadmap

### v2.1 - Template Integration
- Load workflow templates based on 8 multi-agent patterns
- One-click template instantiation
- Customizable template parameters

### v2.2 - Execution Engine
- Execute workflows from the builder
- Real-time execution monitoring
- Step-by-step debugging

### v2.3 - Advanced Features
- Variables and data mapping UI
- Subworkflow support
- Workflow sharing and collaboration

### v2.4 - Intelligence
- AI-powered workflow suggestions
- Automatic optimization recommendations
- Pattern recognition and templates

## Contributing

When adding new node types:

1. Create node component in `src/workflow-builder/nodes/`
2. Extend `BaseNode` or create custom component
3. Register in `nodes/index.js`
4. Add to node palette in `WorkflowSidebar.jsx`
5. Add configuration UI in `WorkflowPropertiesPanel.jsx`
6. Add validation rules in `utils/workflowHelpers.js`

## Support

For issues or questions:
- GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- Documentation: `/docs/` directory

## License

GPLv3 or later

## Credits

Built following 2026 industry standards from:
- n8n (workflow automation)
- Zapier (integration platform)
- Make (visual automation)
- Vellum (AI workflow orchestration)
- CrewAI (multi-agent systems)
- ReactFlow (node-based UI library)

---

**NV Digital Solutions** - Open Operator System (NV oOS)
