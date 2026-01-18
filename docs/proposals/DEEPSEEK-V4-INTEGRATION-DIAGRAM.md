# DeepSeek V4 Orchestration - Profession CPT Integration Architecture

**Date:** January 17, 2026  
**Purpose:** Visual guide for integrating multi-agent orchestration with existing Profession and Team CPT systems

---

## Current Architecture (As-Is)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         PROFESSION CPT SYSTEM                            │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  Profession CPT (mcp_ai_profession) - 200+ Seeded Professions     │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  Meta Fields (13):                                                 │ │
│  │  • Category (advisory, creative, technical, etc.)                 │ │
│  │  • Expertise (array)                                              │ │
│  │  • Default Tools (array)                                          │ │
│  │  • Role Description (system prompt)                               │ │
│  │  • Knowledge Base (markdown)                                      │ │
│  │  • Playbooks (behavioral guidelines)                              │ │
│  │  • Warnings (guardrails)                                          │ │
│  │  • Memory Files, Vector Store, MIME Types, Region, Datasets      │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                    ↓                                      │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  Profession Services Layer                                         │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  • WP_MCP_AI_Profession_Service (CRUD + transform)                │ │
│  │  • WP_MCP_AI_Profession_Playbook_Loader (global→category→prof)   │ │
│  │  • WP_MCP_AI_Profession_Knowledge_Base_Loader (JSON + TXT)       │ │
│  │  • WP_MCP_AI_Profession_Tool_Recommender (3-tier algorithm)      │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                    ↓                                      │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  Team CPT (mcp_ai_team) - Profession Aggregation                  │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  • Team Members (array of profession IDs)                         │ │
│  │  • Team Description                                                │ │
│  │  • Default Provider/Model/Temperature                             │ │
│  │  → Deploys as multiple individual assistants                      │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│               AGENT ORCHESTRATION (PARTIALLY IMPLEMENTED)                │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  Agent Roles (includes/agents/)                                    │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  ✅ Planner (100%) - Task decomposition                           │ │
│  │  ⚠️  Executor (40%) - Tool execution (stubs)                      │ │
│  │  ✅ Critic (95%) - Result validation                              │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                    ↓                                      │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  Agent Team Orchestrator (includes/services/)                      │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  ✅ Team Composition (5 templates)                                │ │
│  │  ⚠️  Workflow Execution (placeholders)                            │ │
│  │  ✅ Communication Service (delegation + aggregation)              │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                    ↓                                      │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  Agent Coordination Tools (NOT IMPLEMENTED)                        │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  ❌ create_agent_team                                             │ │
│  │  ❌ delegate_to_agent                                             │ │
│  │  ❌ aggregate_agent_results                                       │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘

PROBLEM: These two systems exist in parallel but don't integrate!
```

---

## Proposed Architecture (To-Be)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    INTEGRATED PROFESSION-AGENT SYSTEM                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  ENHANCED Profession CPT (mcp_ai_profession)                         │   │
│  ├──────────────────────────────────────────────────────────────────────┤   │
│  │  Existing Meta Fields (13):                                          │   │
│  │  ✅ Category, Expertise, Default Tools, Role Description, etc.      │   │
│  │                                                                       │   │
│  │  NEW Orchestration Meta Fields (7):                                  │   │
│  │  🆕 agent_role                 → planner|executor|critic|specialist │   │
│  │  🆕 task_patterns              → JSON workflow templates            │   │
│  │  🆕 decision_criteria          → JSON condition→action mappings     │   │
│  │  🆕 orchestration_rules        → JSON coordination rules            │   │
│  │  🆕 quality_metrics            → JSON success criteria              │   │
│  │  🆕 tool_execution_order       → JSON tool chains + dependencies    │   │
│  │  🆕 confidence_thresholds      → JSON escalation rules              │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                     ↓                                         │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  ENHANCED Profession Services Layer                                  │   │
│  ├──────────────────────────────────────────────────────────────────────┤   │
│  │  Existing Services:                                                  │   │
│  │  ✅ Profession Service (CRUD + transform)                           │   │
│  │  ✅ Playbook Loader, Knowledge Base Loader, Tool Recommender        │   │
│  │                                                                       │   │
│  │  NEW Methods:                                                        │   │
│  │  🆕 get_profession_for_agent_role($slug, $role)                     │   │
│  │  🆕 get_professions_by_agent_role($role)                            │   │
│  │  🆕 transform_profession_for_orchestration($profession)             │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                     ↓                                         │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  ENHANCED Team CPT (mcp_ai_team)                                     │   │
│  ├──────────────────────────────────────────────────────────────────────┤   │
│  │  Existing Fields:                                                    │   │
│  │  ✅ Team Members (profession IDs)                                   │   │
│  │  ✅ Team Description, Default Provider/Model/Temperature            │   │
│  │                                                                       │   │
│  │  NEW Orchestration Fields:                                           │   │
│  │  🆕 team_workflow                → JSON workflow definition         │   │
│  │  🆕 planner_profession_id        → ID of planner profession         │   │
│  │  🆕 executor_profession_ids      → Array of executor IDs            │   │
│  │  🆕 critic_profession_id         → ID of critic profession          │   │
│  │                                                                       │   │
│  │  NEW Behavior:                                                       │   │
│  │  → Deploys as COORDINATED multi-agent team (not individual)         │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                     ↓                                         │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  COMPLETE Agent Orchestration Layer                                  │   │
│  ├──────────────────────────────────────────────────────────────────────┤   │
│  │  Agent Roles:                                                        │   │
│  │  ✅ Planner (100%) - Reads task_patterns from profession           │   │
│  │  🔄 Executor (100%) - Uses tool_execution_order + decision_criteria│   │
│  │  ✅ Critic (100%) - Applies quality_metrics for validation         │   │
│  │                                                                       │   │
│  │  Agent Team Orchestrator:                                            │   │
│  │  🔄 Compose team from professions with agent_role filtering         │   │
│  │  🔄 Execute workflow using orchestration_rules from professions     │   │
│  │  ✅ Communication Service (delegation + aggregation)                │   │
│  │                                                                       │   │
│  │  Agent Coordination Tools:                                           │   │
│  │  🆕 create_agent_team (reads team_workflow from Team CPT)          │   │
│  │  🆕 delegate_to_agent (uses orchestration_rules for routing)       │   │
│  │  🆕 aggregate_agent_results (applies quality_metrics)              │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                               │
└─────────────────────────────────────────────────────────────────────────────┘

SOLUTION: Professions define agent behavior, Teams orchestrate agent workflows!
```

---

## Data Flow: Multi-Agent Workflow Execution

### Example: Research Team Workflow

```
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 1: User Creates Research Team                                      │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  User Action: Create Team "AI Research Team"                            │
│               └─> Selects professions:                                   │
│                   • project_manager (agent_role: planner)               │
│                   • data_scientist (agent_role: executor)               │
│                   • content_writer (agent_role: executor)               │
│                   • technical_editor (agent_role: critic)               │
│                                                                           │
│  System Action: Team CPT stores:                                         │
│                 planner_profession_id: 123 (project_manager)            │
│                 executor_profession_ids: [124, 125]                     │
│                 critic_profession_id: 126 (technical_editor)            │
│                 team_workflow: {                                         │
│                   "type": "research",                                    │
│                   "steps": [                                             │
│                     {"role": "planner", "action": "decompose"},         │
│                     {"role": "executor", "action": "research", "parallel": true}, │
│                     {"role": "executor", "action": "analyze"},          │
│                     {"role": "executor", "action": "write"},            │
│                     {"role": "critic", "action": "validate"}            │
│                   ]                                                      │
│                 }                                                        │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 2: User Queries Team                                               │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  User: "Create a comprehensive market analysis on AI coding assistants" │
│                                                                           │
│  → Query routed to Team → Orchestrator loads team_workflow              │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 3: Planner Decomposes Task                                         │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  Orchestrator invokes: project_manager profession (planner role)        │
│                        └─> Reads task_patterns from profession meta     │
│                                                                           │
│  Planner Output:                                                         │
│  {                                                                       │
│    "subtasks": [                                                         │
│      {                                                                   │
│        "id": "research-1",                                               │
│        "description": "Gather data on top 10 AI coding assistants",    │
│        "assign_to": "data_scientist",                                   │
│        "tools": ["web_search", "crawl4ai"],                             │
│        "dependencies": []                                                │
│      },                                                                  │
│      {                                                                   │
│        "id": "analysis-1",                                               │
│        "description": "Analyze pricing, features, market share",        │
│        "assign_to": "data_scientist",                                   │
│        "tools": ["analyze_data", "create_chart"],                       │
│        "dependencies": ["research-1"]                                    │
│      },                                                                  │
│      {                                                                   │
│        "id": "writing-1",                                                │
│        "description": "Write comprehensive report with findings",       │
│        "assign_to": "content_writer",                                   │
│        "tools": ["save_post"],                                          │
│        "dependencies": ["analysis-1"]                                    │
│      }                                                                   │
│    ],                                                                    │
│    "success_criteria": {                                                 │
│      "completeness": "All 10 assistants covered",                       │
│      "accuracy": "Sources cited",                                        │
│      "quality": "Executive summary + detailed analysis"                 │
│    }                                                                     │
│  }                                                                       │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 4: Orchestrator Delegates to Executors                             │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  Subtask "research-1" → delegated to data_scientist (executor)          │
│                         └─> Reads tool_execution_order from profession  │
│                         └─> Reads decision_criteria for tool selection  │
│                                                                           │
│  Executor Actions:                                                       │
│  1. Invokes web_search("top AI coding assistants 2026")                 │
│     → Result: List of 10 assistants                                     │
│  2. Invokes crawl4ai(assistant_websites)                                │
│     → Result: Pricing + feature data                                    │
│  3. Checks confidence_threshold (0.85) - PASSED                         │
│  4. Returns structured result                                            │
│                                                                           │
│  Subtask "analysis-1" → delegated to data_scientist (executor)          │
│                         └─> Waits for research-1 (dependency)           │
│                         └─> Uses decision_criteria: "if dataset > 5MB → aggregate" │
│                                                                           │
│  Executor Actions:                                                       │
│  1. Invokes analyze_data(research_results)                              │
│     → Result: Market share percentages, pricing tiers                   │
│  2. Invokes create_chart("market share pie chart")                      │
│     → Result: Chart image URL                                            │
│  3. Returns analysis + visualization                                     │
│                                                                           │
│  Subtask "writing-1" → delegated to content_writer (executor)           │
│                        └─> Waits for analysis-1 (dependency)            │
│                        └─> Uses task_patterns for report structure      │
│                                                                           │
│  Executor Actions:                                                       │
│  1. Generates report outline (from task_pattern)                         │
│  2. Writes executive summary                                             │
│  3. Writes detailed analysis sections                                    │
│  4. Embeds chart images                                                  │
│  5. Invokes save_post(report_content)                                   │
│     → Result: Post ID 789                                                │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 5: Critic Validates Results                                        │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  Orchestrator invokes: technical_editor profession (critic role)        │
│                        └─> Reads quality_metrics from profession meta   │
│                                                                           │
│  Critic Validation:                                                      │
│  {                                                                       │
│    "completeness_check": {                                               │
│      "all_10_assistants": true,     // PASS                             │
│      "executive_summary": true,     // PASS                             │
│      "detailed_analysis": true,     // PASS                             │
│      "visualizations": true,        // PASS                             │
│      "sources_cited": true          // PASS                             │
│    },                                                                    │
│    "accuracy_check": {                                                   │
│      "data_consistency": 0.95,      // PASS (> 0.85 threshold)          │
│      "citation_validity": 0.92      // PASS                             │
│    },                                                                    │
│    "quality_check": {                                                    │
│      "readability": 0.88,           // PASS                             │
│      "structure": 0.90,             // PASS                             │
│      "professional_tone": 0.91      // PASS                             │
│    },                                                                    │
│    "overall_score": 0.91,           // PASS (> 0.70 threshold)          │
│    "validation": "APPROVED",                                             │
│    "feedback": [                                                         │
│      "Excellent coverage of market landscape",                          │
│      "Consider adding competitive positioning matrix"                   │
│    ]                                                                     │
│  }                                                                       │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 6: Orchestrator Returns Final Result                               │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  To User: "✅ Market analysis complete! Here's your comprehensive       │
│            report on AI coding assistants:                               │
│                                                                           │
│            📄 Report: [Link to Post 789]                                │
│            📊 Market Share Chart: [Image]                                │
│            ⭐ Quality Score: 91/100 (Approved by technical_editor)      │
│                                                                           │
│            Key Findings:                                                 │
│            • GitHub Copilot leads with 45% market share                 │
│            • Average pricing: $20-30/month                               │
│            • Enterprise adoption growing 150% YoY                        │
│                                                                           │
│            Next Steps: [Editor's recommendations]"                       │
│                                                                           │
│  Execution Metrics:                                                      │
│  • Total time: 47 seconds                                                │
│  • Subtasks: 3 (all successful)                                          │
│  • Tools invoked: 6 (web_search, crawl4ai, analyze_data,               │
│                      create_chart, save_post, validate)                 │
│  • Token usage: 12,450 tokens                                            │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Key Integration Points

### 1. Profession Meta → Agent Behavior

| Profession Meta Field | Agent Role Usage |
|---|---|
| `agent_role` | Orchestrator filters professions by role when composing teams |
| `task_patterns` | Planner uses to generate subtasks with structure |
| `decision_criteria` | Executor evaluates conditions to select tools/actions |
| `orchestration_rules` | Communication Service routes delegation based on rules |
| `quality_metrics` | Critic applies metrics during validation |
| `tool_execution_order` | Executor follows dependency chain for tool invocation |
| `confidence_thresholds` | All roles escalate when confidence < threshold |

### 2. Team CPT → Workflow Orchestration

| Team Field | Orchestrator Usage |
|---|---|
| `planner_profession_id` | Loads profession → invokes as planner agent |
| `executor_profession_ids` | Maps subtasks → appropriate executor professions |
| `critic_profession_id` | Loads profession → invokes as validator |
| `team_workflow` | Defines execution order (sequential/parallel) |

### 3. Tools → Agent Coordination

| Tool | Agent Invocation |
|---|---|
| `create_agent_team` | AI models can compose teams dynamically from available professions |
| `delegate_to_agent` | AI models can delegate subtasks to specific profession-agents |
| `aggregate_agent_results` | AI models can combine outputs from multiple agents |

---

## Migration Path: Existing Professions → Agent Roles

### Automatic Role Assignment Algorithm

```php
function assign_default_agent_role( $profession ) {
    // Rules based on category + expertise
    
    if ( has_expertise( $profession, ['project management', 'coordination'] ) ) {
        return 'planner';
    }
    
    if ( has_expertise( $profession, ['editing', 'reviewing', 'quality'] ) ) {
        return 'critic';
    }
    
    if ( in_category( $profession, ['advisory', 'consulting'] ) ) {
        return 'planner';
    }
    
    if ( in_category( $profession, ['technical', 'creative', 'healthcare'] ) ) {
        return 'executor';
    }
    
    return 'generalist'; // Default fallback
}
```

### Example Assignments for Seeded Professions

| Profession | Category | → Agent Role | Rationale |
|---|---|---|---|
| `project_manager` | Advisory | planner | Specializes in task decomposition |
| `data_scientist` | Technical | executor | Performs analysis tasks |
| `content_writer` | Creative | executor | Creates content |
| `technical_editor` | Creative | critic | Validates quality |
| `software_architect` | Technical | planner | Designs system structure |
| `qa_engineer` | Technical | critic | Tests and validates |
| `business_consultant` | Advisory | planner | Strategic planning |
| `graphic_designer` | Creative | executor | Visual creation |
| `legal_reviewer` | Legal | critic | Compliance validation |
| `physician` | Healthcare | executor | Medical tasks |

---

## User Experience: Team Builder UI Mockup

```
┌───────────────────────────────────────────────────────────────────────┐
│ Create Multi-Agent Team                                               │
├───────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  Team Name: [AI Research Team                               ]         │
│  Team Type: [Research & Analysis ▾]                                   │
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │ Team Composition (Drag professions into roles)                 │  │
│  ├─────────────────────────────────────────────────────────────────┤  │
│  │                                                                  │  │
│  │  [Planner Role] ────────────────────────────────────────────    │  │
│  │   📋 Project Manager                               [Remove]     │  │
│  │   → Decomposes tasks, coordinates team                          │  │
│  │                                                                  │  │
│  │  [Executor Roles] (Can have multiple) ─────────────────────     │  │
│  │   🔬 Data Scientist                                [Remove]     │  │
│  │   → Handles research and analysis tasks                         │  │
│  │                                                                  │  │
│  │   ✍️ Content Writer                                [Remove]     │  │
│  │   → Creates written content                                     │  │
│  │                                                                  │  │
│  │   [+ Add Executor]                                              │  │
│  │                                                                  │  │
│  │  [Critic Role] (Optional) ──────────────────────────────────    │  │
│  │   📝 Technical Editor                              [Remove]     │  │
│  │   → Validates quality and completeness                          │  │
│  │                                                                  │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                                                         │
│  Available Professions (Filter by agent role):                         │
│  [All ▾] [Planners ▾] [Executors ▾] [Critics ▾]                       │
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │ 📋 Project Manager (Planner)               [Drag to Team →]    │  │
│  │ 🔬 Data Scientist (Executor)               [Drag to Team →]    │  │
│  │ ✍️ Content Writer (Executor)               [Drag to Team →]    │  │
│  │ 📝 Technical Editor (Critic)               [Drag to Team →]    │  │
│  │ 🎨 Graphic Designer (Executor)             [Drag to Team →]    │  │
│  │ ... (196 more professions)                                      │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                                                         │
│  Advanced Settings (Optional):                                         │
│  ☐ Custom Workflow (Edit JSON)                                        │
│  ☐ Override Individual Profession Settings                            │
│                                                                         │
│  [Cancel]                                    [Save Team] [Test Team]  │
│                                                                         │
└───────────────────────────────────────────────────────────────────────┘
```

---

## Benefits of Integration

### For Users
1. **Simple Team Creation** - Select professions, system assigns roles automatically
2. **Powerful Orchestration** - Multi-agent workflows without manual coordination
3. **Quality Assurance** - Built-in validation through critic agents
4. **Specialized Expertise** - Right agent for each subtask
5. **Transparent Execution** - See which agent did what

### For Developers
1. **Clean Architecture** - Single source of truth (Profession CPT)
2. **Extensible** - Add new agent roles easily
3. **Testable** - Mock professions with orchestration configs
4. **Maintainable** - Orchestration logic in metadata, not code
5. **Backwards Compatible** - Existing professions work as generalist agents

### For the System
1. **Scalable** - Handle complex multi-step workflows
2. **Efficient** - Parallel executor execution where possible
3. **Reliable** - Validation prevents low-quality outputs
4. **Intelligent** - Decision criteria enable autonomous operation
5. **Observable** - Track metrics per agent role

---

**Document Version:** 1.0  
**Last Updated:** January 17, 2026  
**Related Documents:**
- [DEEPSEEK-V4-IMPLEMENTATION-STATUS.md](./DEEPSEEK-V4-IMPLEMENTATION-STATUS.md) - Detailed status report
- [DEEPSEEK-V4-ORCHESTRATION-ENHANCEMENTS.md](./DEEPSEEK-V4-ORCHESTRATION-ENHANCEMENTS.md) - Original proposal
