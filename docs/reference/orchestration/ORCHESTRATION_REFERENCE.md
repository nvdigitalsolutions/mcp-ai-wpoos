# NV oOS Orchestration Layer — Complete Reference

**Last Updated:** April 16, 2026
**Plugin Version:** 1.2.0
**Since:** 1.0.0 (core), 1.1.0 (multi-agent), 1.1.1 (presets/reasoning/load-balancing), 1.2.0 (PSO optimizer)

> **Not the same as the LLM harness.** This document covers the *infrastructure* layer — how requests are routed, executed, budgeted, and monitored. For the per-assistant *epistemic* layer (cues, reasoning traces, retrieval-with-provenance, self-refine, memory scoping), see [`docs/llm-harness.md`](llm-harness.md), which includes a side-by-side comparison and an interaction diagram. The short version: the harness sits on top of orchestration. Orchestration is always on; the harness is opt-in per assistant.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Workflow Presets](#2-workflow-presets)
3. [Resource Presets](#3-resource-presets)
4. [PSO Adaptive Optimization](#4-pso-adaptive-optimization)
5. [Tool Execution Orchestrator](#5-tool-execution-orchestrator)
6. [Tool Load Balancer](#6-tool-load-balancer)
7. [Reasoning Controller](#7-reasoning-controller)
8. [Multi-Agent System](#8-multi-agent-system)
9. [Health Monitoring](#9-health-monitoring)
10. [Budget Enforcement](#10-budget-enforcement)
11. [Hooks & Filters Reference](#11-hooks--filters-reference)
12. [Data Storage Reference](#12-data-storage-reference)
13. [Admin UI Reference](#13-admin-ui-reference)
14. [Service Files Index](#14-service-files-index)

---

## 1. Architecture Overview

The NV oOS orchestration layer transforms WordPress's request-based PHP architecture into an intelligent, policy-aware AI execution environment. It manages every aspect of tool execution, model selection, resource budgeting, and multi-agent coordination.

### Service Hierarchy

```
Chat Request
  │
  ├── Language Model Router (9 providers)
  │     └── Reasoning Controller (Phase 3)
  │           └── Task complexity detection → reasoning mode activation
  │
  ├── Agentic Loop (class-wp-mcp-ai-rest.php)
  │     ├── Tool Execution Orchestrator (sync/async routing)
  │     │     ├── Tool Load Balancer (caching, strategy-based routing)
  │     │     ├── Tool Load Monitor (capacity tracking)
  │     │     ├── Tool Chain Predictor (next-tool prediction)
  │     │     └── Tool Async Executor (cron-based execution)
  │     │
  │     ├── Agent Team Orchestrator (multi-agent coordination)
  │     │     ├── Agent Communication Service (inter-agent messaging)
  │     │     ├── Agent Context Manager (shared context)
  │     │     └── Enhanced Workflow Coordinator (parallel tasks, dependencies)
  │     │
  │     ├── PSO Optimizer Service (adaptive parameter tuning)
  │     │     └── Fitness evaluation → velocity update → position update
  │     │
  │     └── Budget Enforcement Service (token/timeout limits)
  │
  ├── Health Monitoring
  │     ├── Orchestration Health Service (metrics, status, degradation)
  │     ├── Async Health Monitor (stuck job detection)
  │     └── Efficiency Monitor (performance analytics)
  │
  └── Preset System
        ├── Orchestration Presets (10 workflow presets)
        └── Orchestration Preset Service (13 resource presets)
```

### Implementation Phases

| Phase | Name | Since | Services |
|-------|------|-------|----------|
| 1 | Core Orchestration | 1.0.0 | Tool Execution Orchestrator, Async Executor, Async Health Monitor |
| 1.5 | Multi-Agent | 1.1.0 | Agent Communication, Agent Team Orchestrator, Workflow Coordinator |
| 2 | Load Balancing | 1.1.1 | Tool Load Monitor, Tool Load Balancer, Tool Chain Predictor, Efficiency Monitor |
| 3 | Reasoning | 1.1.1 | Reasoning Controller, Code Optimizer |
| 4 | Presets | 1.1.1 | Orchestration Presets, Orchestration Preset Service |
| 4.5 | Budget | 1.1.1 | Budget Enforcement Service, Orchestration Health Service |
| 5 | Context | 1.1.1 | Agent Context Manager |
| 5.5 | Vectors | 1.1.1 | Vector Context Service |
| 6 | PSO Optimization | 1.2.0 | PSO Optimizer Service |

---

## 2. Workflow Presets

**Class:** `WP_MCP_AI_Orchestration_Presets`
**File:** `includes/services/class-wp-mcp-ai-orchestration-presets.php`
**Since:** 1.1.1

Workflow presets configure the AI behaviour profile — how agents collaborate, what tools are preferred, and which orchestration patterns are active. These are distinct from resource presets (§3) which control token budgets and health thresholds.

### Option Keys

| Option | Description |
|--------|-------------|
| `wp_mcp_ai_active_preset` | Currently active workflow preset key |
| `wp_mcp_ai_custom_presets` | Array of user-created custom presets |
| `wp_mcp_ai_orchestration_settings` | Runtime settings applied by the active preset |

### Built-in Presets

#### 2.1 Research & Analysis

| Key | `research` |
|-----|------------|
| **Category** | `research` |
| **Best for** | Multi-source research and synthesis tasks |

| Setting | Value |
|---------|-------|
| `enable_agent_teams` | `true` |
| `primary_agent_role` | `researcher` |
| `reasoning_mode` | `enabled` |
| `load_balancing` | `quality-optimized` |
| `enable_caching` | `true` |
| `enable_prediction` | `true` |
| `tool_preferences` | `web_search`, `crawl4ai`, `analyze_content`, `semantic_search` |
| `max_delegation_depth` | `3` |

#### 2.2 Code Generation

| Key | `code_generation` |
|-----|-------------------|
| **Category** | `development` |
| **Best for** | Coding tasks with validation and security scanning |

| Setting | Value |
|---------|-------|
| `reasoning_mode` | `enabled` |
| `code_validation` | `enabled` |
| `security_scanning` | `enabled` |
| `context_optimization` | `code-aware` |
| `load_balancing` | `quality-optimized` |
| `tool_preferences` | `analyze_code_sequence`, `validate_reasoning_chain`, `enable_reasoning_mode` |
| `temperature` | `0.3` |

#### 2.3 Multi-Agent Collaboration

| Key | `multi_agent` |
|-----|---------------|
| **Category** | `collaboration` |
| **Best for** | Complex tasks requiring multiple perspectives and validation |

| Setting | Value |
|---------|-------|
| `enable_agent_teams` | `true` |
| `team_composition` | `auto` |
| `delegation_enabled` | `true` |
| `result_aggregation` | `consensus` |
| `enable_critic_role` | `true` |
| `tool_preferences` | `create_agent_team`, `delegate_to_agent`, `aggregate_agent_results`, `execute_workflow` |
| `max_team_size` | `5` |

#### 2.4 Speed Optimized

| Key | `speed_optimized` |
|-----|-------------------|
| **Category** | `performance` |
| **Best for** | Fastest response time with standard quality |

| Setting | Value |
|---------|-------|
| `load_balancing` | `speed-optimized` |
| `enable_caching` | `true` |
| `cache_aggressive` | `true` |
| `enable_prediction` | `true` |
| `parallel_execution` | `aggressive` |
| `skip_validation` | `true` |
| `temperature` | `0.7` |

#### 2.5 Quality Optimized

| Key | `quality_optimized` |
|-----|---------------------|
| **Category** | `quality` |
| **Best for** | Highest quality output with reasoning and multi-step verification |

| Setting | Value |
|---------|-------|
| `load_balancing` | `quality-optimized` |
| `reasoning_mode` | `enabled` |
| `enable_verification` | `true` |
| `enable_critic_role` | `true` |
| `tool_preferences` | `validate_reasoning_chain`, `enable_reasoning_mode` |
| `temperature` | `0.2` |
| `require_confidence` | `0.8` |

#### 2.6 Autonomous Workflow

| Key | `autonomous_workflow` |
|-----|----------------------|
| **Category** | `orchestration` |
| **Best for** | Self-managing continuous AI operations |

| Setting | Value |
|---------|-------|
| `enable_agent_teams` | `true` |
| `delegation_enabled` | `true` |
| `reasoning_mode` | `enabled` |
| `enable_task_planning` | `true` |
| `enable_health_monitoring` | `true` |
| `enable_exit_detection` | `true` |
| `enable_session_management` | `true` |
| `tool_preferences` | `create_task_plan`, `manage_autonomous_session`, `check_exit_conditions`, `analyze_loop_health`, `detect_completion_indicators` |
| `max_delegation_depth` | `5` |
| `max_loop_iterations` | `50` |

#### 2.7 Supervisor Pattern

| Key | `supervisor_pattern` |
|-----|---------------------|
| **Category** | `orchestration` |
| **Best for** | Quality control with single coordination point |

| Setting | Value |
|---------|-------|
| `enable_agent_teams` | `true` |
| `primary_agent_role` | `supervisor` |
| `delegation_enabled` | `true` |
| `result_aggregation` | `supervisor-reviewed` |
| `enable_critic_role` | `true` |
| `enable_verification` | `true` |
| `tool_preferences` | `create_agent_team`, `delegate_to_agent`, `aggregate_agent_results`, `validate_workflow`, `verify_information` |
| `max_team_size` | `5` |
| `max_delegation_depth` | `3` |

#### 2.8 Pipeline Pattern

| Key | `pipeline_pattern` |
|-----|-------------------|
| **Category** | `orchestration` |
| **Best for** | Sequential multi-step processing with clear stage boundaries |

| Setting | Value |
|---------|-------|
| `enable_agent_teams` | `true` |
| `pipeline_mode` | `true` |
| `sequential_execution` | `true` |
| `stage_validation` | `true` |
| `enable_caching` | `true` |
| `tool_preferences` | `execute_workflow`, `create_task_plan`, `detect_completion_indicators`, `extract_structured_data` |
| `max_pipeline_stages` | `10` |

#### 2.9 Swarm Pattern

| Key | `swarm_pattern` |
|-----|----------------|
| **Category** | `orchestration` |
| **Best for** | Parallel autonomous agents for search, analysis, and data collection |

| Setting | Value |
|---------|-------|
| `enable_agent_teams` | `true` |
| `parallel_execution` | `aggressive` |
| `delegation_enabled` | `true` |
| `result_aggregation` | `parallel-merge` |
| `enable_caching` | `true` |
| `tool_preferences` | `create_agent_team`, `delegate_to_agent`, `aggregate_agent_results`, `web_search`, `deep_research`, `aggregate_research_data` |
| `max_team_size` | `10` |
| `max_delegation_depth` | `2` |

#### 2.10 Adaptive Optimization (PSO)

| Key | `pso_adaptive` |
|-----|----------------|
| **Category** | `optimization` |
| **Best for** | Self-tuning orchestration that learns from each conversation |
| **Since** | 1.2.0 |

| Setting | Default | Description |
|---------|---------|-------------|
| `enable_pso_optimizer` | `true` | Activates the PSO optimizer service |
| `pso_inertia_start` | `0.9` | Initial inertia weight (exploration) |
| `pso_inertia_end` | `0.4` | Final inertia weight (exploitation) |
| `pso_learning_rate_personal` | `1.5` | Cognitive learning coefficient (c₁) |
| `pso_learning_rate_social` | `2.0` | Social learning coefficient (c₂) |
| `pso_update_frequency` | `10` | Conversations between PSO updates |
| `enable_caching` | `true` | Enable tool result caching |
| `enable_prediction` | `true` | Enable tool chain prediction |
| `load_balancing` | `adaptive` | PSO-driven adaptive load balancing |

See [§4 PSO Adaptive Optimization](#4-pso-adaptive-optimization) for the full algorithm description.

### Preset Recommendation Engine

The `recommend_preset_for_task()` method matches keywords in task descriptions to recommend appropriate presets:

| Preset | Keywords | Confidence |
|--------|----------|------------|
| `research` | research, analyze, study, investigate | 0.80 |
| `code_generation` | code, program, script, function, class | 0.85 |
| `multi_agent` | complex, multiple, team, collaborate | 0.75 |
| `speed_optimized` | quick, fast, urgent, asap | 0.70 |
| `quality_optimized` | quality, accurate, precise, thorough | 0.80 |
| `autonomous_workflow` | autonomous, continuous, loop, self-managing, unattended | 0.85 |
| `supervisor_pattern` | supervis*, oversee, manage, coordinat*, assign, delegate | 0.80 |
| `pipeline_pattern` | pipeline, sequential, stage, step-by-step, refine, transform | 0.80 |
| `swarm_pattern` | parallel, swarm, simultaneous, bulk, mass, gather, scrape | 0.75 |
| `pso_adaptive` | optimiz/se, adaptive, self-tune, learn, improve, evolve, tune, pso | 0.80 |

### Custom Presets

Custom presets are stored in the `wp_mcp_ai_custom_presets` option and support full CRUD operations:

```php
$presets = new WP_MCP_AI_Orchestration_Presets();

// Create custom preset.
$presets->create_custom_preset( 'my_research', array(
    'name'        => 'My Research Preset',
    'description' => 'Custom research configuration',
    'category'    => 'custom',
    'settings'    => array(
        'enable_agent_teams' => true,
        'reasoning_mode'     => 'enabled',
        'max_team_size'      => 3,
    ),
) );

// Apply preset (stores in options + fires 'wp_mcp_ai_preset_applied' action).
$presets->apply_preset( 'my_research' );

// Export / Import.
$export = $presets->export_preset( 'my_research' );
$presets->import_preset( $export );

// Delete.
$presets->delete_custom_preset( 'my_research' );
```

**Filter:** `wp_mcp_ai_orchestration_presets` — Modify the list of available presets.

---

## 3. Resource Presets

**Class:** `WP_MCP_AI_Orchestration_Preset_Service`
**File:** `includes/services/class-wp-mcp-ai-orchestration-preset-service.php`
**Since:** 1.0.0

Resource presets control health monitoring thresholds, token budgets, timeout policies, and predictive analytics sensitivity. These are infrastructure-focused, unlike workflow presets (§2) which configure AI behaviour.

### Auto-Detection

The **Auto** preset detects the best configuration based on server resources:

| Server Memory | Detected Preset |
|---------------|----------------|
| ≥ 1 GB | Performance (aggressive) |
| 256 MB – 1 GB | Balanced |
| < 256 MB | Conservative |

### Settings Matrix

All 13 resource presets configure the same settings. The table below shows every setting with its value across all presets.

#### Health Thresholds

| Setting | Balanced | Conservative | Performance | Development | High Traffic | Burst | Cost Opt | Enterprise | Failsafe | Predictive | Design Pro |
|---------|----------|-------------|-------------|-------------|-------------|-------|----------|------------|----------|------------|------------|
| `memory_warning_threshold` | 70 | 60 | 80 | 85 | 75 | 78 | 65 | 72 | 55 | 70 | 75 |
| `memory_critical_threshold` | 85 | 75 | 90 | 95 | 88 | 90 | 80 | 87 | 70 | 85 | 88 |
| `error_rate_warning_threshold` | 5 | 5 | 8 | 15 | 6 | 8 | 5 | 5 | 5 | 5 | 6 |
| `error_rate_critical_threshold` | 10 | 8 | 15 | 25 | 12 | 15 | 10 | 10 | 8 | 10 | 12 |

#### Budget Allocation (percentage of maximum)

| Setting | Balanced | Conservative | Performance | Development | High Traffic | Burst | Cost Opt | Enterprise | Failsafe | Predictive | Design Pro |
|---------|----------|-------------|-------------|-------------|-------------|-------|----------|------------|----------|------------|------------|
| `high_priority_budget` | 100 | 100 | 100 | 100 | 100 | 100 | 100 | 100 | 100 | 100 | 100 |
| `medium_priority_budget` | 75 | 75 | 100 | 100 | 85 | 80 | 65 | 85 | 70 | 75 | 90 |
| `low_priority_budget` | 50 | 50 | 75 | 100 | 60 | 50 | 40 | 65 | 45 | 50 | 70 |
| `critical_health_reduction` | 50 | 40 | 65 | 75 | 55 | 45 | 40 | 50 | 35 | 50 | 55 |
| `warning_health_reduction` | 75 | 65 | 85 | 90 | 80 | 70 | 60 | 75 | 55 | 70 | 80 |

#### Token Limits (by workload tier)

| Setting | Balanced | Conservative | Performance | Development | High Traffic | Burst | Cost Opt | Enterprise | Failsafe | Predictive | Design Pro |
|---------|----------|-------------|-------------|-------------|-------------|-------|----------|------------|----------|------------|------------|
| `low_tier_max_tokens` | 2,000 | 1,000 | 4,000 | 4,000 | 2,000 | 2,000 | 1,000 | 2,000 | 1,000 | 2,000 | 4,000 |
| `medium_tier_max_tokens` | 8,000 | 4,000 | 16,000 | 16,000 | 8,000 | 8,000 | 4,000 | 8,000 | 4,000 | 8,000 | 16,000 |
| `high_tier_max_tokens` | 32,000 | 16,000 | 64,000 | 64,000 | 32,000 | 32,000 | 16,000 | 32,000 | 16,000 | 32,000 | 64,000 |

#### Per-Call & Per-Session Limits

| Setting | Balanced | Conservative | Performance | Development | High Traffic | Burst | Cost Opt | Enterprise | Failsafe | Predictive | Design Pro |
|---------|----------|-------------|-------------|-------------|-------------|-------|----------|------------|----------|------------|------------|
| `enable_per_call_limits` | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `per_call_token_limit` | 10,000 | 5,000 | 25,000 | 50,000 | 8,000 | 15,000 | 3,000 | 12,000 | 2,000 | 10,000 | 20,000 |
| `enable_per_session_limits` | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `per_session_token_limit` | 50,000 | 25,000 | 150,000 | 200,000 | 40,000 | 75,000 | 15,000 | 60,000 | 10,000 | 50,000 | 100,000 |

#### Predictive Analytics

| Setting | Balanced | Conservative | Performance | Development | High Traffic | Burst | Cost Opt | Enterprise | Failsafe | Predictive | Design Pro |
|---------|----------|-------------|-------------|-------------|-------------|-------|----------|------------|----------|------------|------------|
| `prediction_confidence_threshold` | 40 | 50 | 30 | 20 | 35 | 40 | 45 | 35 | 55 | 25 | 35 |
| `prediction_safety_buffer` | 15 | 20 | 10 | 10 | 15 | 20 | 20 | 15 | 25 | 12 | 15 |

### Choosing a Resource Preset

| Scenario | Recommended Preset |
|----------|-------------------|
| Shared hosting, limited RAM | **Conservative** |
| Standard production site | **Balanced** (default) |
| Dedicated server, plenty of RAM | **Performance** |
| Local development / staging | **Development** |
| High-traffic production site | **High Traffic** |
| Unpredictable traffic spikes | **Burst Workload** |
| Minimise API spend | **Cost Optimized** |
| SLA-bound deployment | **Enterprise** |
| Mission-critical, cannot fail | **Failsafe** |
| ML-optimised resource management | **Predictive-First** |
| Image/video generation workflows | **Design Professional** |
| Keep current settings | **Custom** |
| Let the plugin decide | **Auto** |

---

## 4. PSO Adaptive Optimization

**Class:** `WP_MCP_AI_PSO_Optimizer_Service`
**File:** `includes/services/class-wp-mcp-ai-pso-optimizer-service.php`
**Since:** 1.2.0

Particle Swarm Optimization (PSO) automatically tunes orchestration parameters by treating each assistant as a particle in a swarm. The swarm converges on optimal settings through collective learning over many conversations.

### PSO Formula

```
V_i^{t+1} = W · V_i^t + c₁ · U₁ · (pb_i − P_i^t) + c₂ · U₂ · (gb − P_i^t)
P_i^{t+1} = P_i^t + V_i^{t+1}
```

Where:
- **W** = inertia weight (decays linearly from 0.9 → 0.4 over 100 conversations)
- **c₁** = cognitive/personal learning coefficient = 1.5
- **c₂** = social/global learning coefficient = 2.0
- **U₁, U₂** = random factors in [0, 1]
- **pb_i** = personal best position for particle _i_ (best configuration this assistant has found)
- **gb** = global best position across all assistants
- **P_i** = current position (current parameter vector)
- **V_i** = current velocity (rate of change), clamped to ±0.5

### Hyper-Parameters

| Constant | Value | Description |
|----------|-------|-------------|
| `INERTIA_WEIGHT_START` | `0.9` | Initial inertia (favours exploration) |
| `INERTIA_WEIGHT_END` | `0.4` | Final inertia (favours exploitation) |
| `INERTIA_DECAY_CONVERSATIONS` | `100` | Conversations over which inertia decays |
| `C1` | `1.5` | Cognitive learning coefficient |
| `C2` | `2.0` | Social learning coefficient |
| `UPDATE_FREQUENCY` | `10` | Conversations between PSO updates |
| `V_MAX` | `0.5` | Maximum velocity magnitude |
| `MAX_TOOLS_PER_ITERATION` | `5.0` | Normalisation cap for iteration efficiency |
| `FITNESS_BASE_WEIGHT` | `0.4` | Base weight in fitness function |
| `FITNESS_CACHE_WEIGHT` | `0.3` | Cache efficiency weight in fitness function |
| `FITNESS_ITERATION_WEIGHT` | `0.3` | Iteration efficiency weight in fitness function |

### Search Space (7 Dimensions)

Each assistant's position is a 7-dimensional vector:

| # | Dimension | Min | Max | Default | What It Controls |
|---|-----------|-----|-----|---------|-----------------|
| 0 | `model_quality_weight` | 0.0 | 1.0 | 0.5 | Preference for advanced model vs cost-effective model |
| 1 | `temperature_offset` | −0.3 | 0.3 | 0.0 | Adjustment to base temperature |
| 2 | `async_threshold` | 0.0 | 1.0 | 0.5 | When to prefer async execution |
| 3 | `capacity_weight` | 0.0 | 1.0 | 0.5 | How much server capacity influences routing |
| 4 | `max_iterations_factor` | 0.5 | 2.0 | 1.0 | Multiplier on base agentic loop iteration count |
| 5 | `cache_aggressiveness` | 0.0 | 1.0 | 0.5 | How aggressively to cache tool results |
| 6 | `cost_sensitivity` | 0.0 | 1.0 | 0.5 | How much cost influences routing decisions |

### Fitness Function

Fitness is calculated from `wp_mcp_ai_agentic_metrics` data after each agentic loop:

```
fitness = speed_score × (0.4 + 0.3 × cache_efficiency + 0.3 × iteration_efficiency)
```

Where:
- `speed_score = 1 / duration` (faster = better)
- `cache_efficiency = cache_hits / (cache_hits + cache_misses)` (default 0.5 if no cache ops)
- `iteration_efficiency = min(1.0, tool_executions / iterations / 5.0)` (more tools per iteration = better, capped at 5)

**Filter:** `wp_mcp_ai_pso_fitness_score` — Override the computed fitness score.

### State Storage

| Meta/Option Key | Type | Description |
|-----------------|------|-------------|
| `_wp_mcp_ai_pso_position` | Post meta | Current position vector (array of 7 floats) |
| `_wp_mcp_ai_pso_velocity` | Post meta | Current velocity vector (array of 7 floats) |
| `_wp_mcp_ai_pso_pbest` | Post meta | Personal best position (array of 7 floats) |
| `_wp_mcp_ai_pso_pbest_fit` | Post meta | Personal best fitness score (float) |
| `_wp_mcp_ai_pso_samples` | Post meta | Conversation count since last update (int) |
| `wp_mcp_ai_pso_global_best` | Option | Global best: `{position, fitness, assistant_id, updated_at}` |

### Hooks

| Hook | Type | Parameters | Description |
|------|------|-----------|-------------|
| `wp_mcp_ai_pso_fitness_score` | Filter | `$fitness, $metrics, $assistant_id` | Override computed fitness |
| `wp_mcp_ai_pso_velocity_update` | Filter | `$velocity, $position, $pbest, $gbest, $assistant_id` | Override velocity calculation |
| `wp_mcp_ai_pso_inertia_weight` | Filter | `$weight, $conversation_count` | Override inertia weight |
| `wp_mcp_ai_pso_coefficients` | Filter | `$coefficients` | Override c₁/c₂ coefficients |

### Lifecycle

1. **Initialisation** — On first conversation, a particle is initialised with default position, zero velocity, and default personal best.
2. **Sampling** — After each agentic loop, metrics are recorded and the sample counter increments.
3. **Update** — Every `UPDATE_FREQUENCY` conversations (default 10), the PSO velocity equation runs: inertia pulls the particle forward, cognitive component pulls toward personal best, social component pulls toward global best.
4. **Bounds enforcement** — Position is clamped to dimension min/max, velocity clamped to ±V_MAX.
5. **Best tracking** — If the new fitness exceeds personal best, the personal best updates. If it exceeds global best, the global best updates.
6. **Integration** — The `max_iterations_factor` dimension directly filters `wp_mcp_ai_max_agentic_iterations`, dynamically adjusting loop depth.

### Programmatic Access

```php
$pso = wp_mcp_ai_get_pso_optimizer_service();

// Get all current parameters for an assistant.
$params = $pso->get_all_parameters( $assistant_id );
// → [ 'model_quality_weight' => 0.62, 'temperature_offset' => -0.05, ... ]

// Get swarm summary.
$summary = $pso->get_swarm_summary();
// → [ 'global_best' => [...], 'total_conversations' => 150, 'current_inertia' => 0.65, ... ]

// Reset a particle (e.g., after changing assistant config).
$pso->reset_particle( $assistant_id );
```

---

## 5. Tool Execution Orchestrator

**Class:** `WP_MCP_AI_Tool_Execution_Orchestrator`
**File:** `includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php`
**Since:** 1.0.0

Routes tool execution between synchronous and asynchronous modes based on capability flags and system capacity.

### Async Capability Flags

Tools with any of these capability flags are routed to async execution:

| Flag | Description |
|------|-------------|
| `long-running` | Tool takes significant time to execute |
| `may-timeout` | Tool might exceed PHP timeout |
| `async` | Tool explicitly requests async execution |

### Capacity Thresholds

| Constant | Value | Action |
|----------|-------|--------|
| `CAPACITY_THRESHOLD_CRITICAL` | 15% | Always queue to async |
| `CAPACITY_THRESHOLD_WARNING` | 30% | Consider async execution |
| `UTILIZATION_THRESHOLD_HIGH` | 0.85 (85%) | High server utilization threshold |

### Decision Flow

```
Tool Request
  ├── Has async capability flag? → Queue to Async Executor
  ├── Force async parameter? → Queue to Async Executor
  ├── Capacity < 15% (critical)? → Queue to Async Executor
  ├── Capacity < 30% (warning) + high utilization? → Queue to Async Executor
  └── Otherwise → Execute synchronously via Tool Registry
```

### Architecture Separation

| Component | Responsibility |
|-----------|---------------|
| Tool Execution Orchestrator | Decides sync vs async (this class) |
| Tool Registry | Executes tools synchronously |
| Tool Async Executor | Manages cron-based async execution |
| Tool Load Monitor | Provides capacity/utilization metrics |

---

## 6. Tool Load Balancer

**Class:** `WP_MCP_AI_Tool_Load_Balancer`
**File:** `includes/services/class-wp-mcp-ai-tool-load-balancer.php`
**Since:** 1.1.1

Provides intelligent tool execution routing with result caching, strategy-based routing, and performance optimisation.

### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `CACHE_KEY_PREFIX` | `wp_mcp_ai_tool_result_` | Prefix for cached tool results |
| `CACHE_GROUP` | `mcp_ai_tool_results` | WordPress object cache group |
| `DEFAULT_CACHE_TTL` | `900` (15 min) | Default cache time-to-live |

### Cacheable Tools

The following tools have deterministic output and are eligible for result caching:

| Tool Slug | Description |
|-----------|-------------|
| `get_recent_posts` | Fetch recent posts |
| `search_content` | Content search |
| `get_post` | Get single post |
| `list_categories` | List all categories |
| `get_user_info` | Get user information |
| `get_term` | Get taxonomy term |
| `list_tags` | List all tags |
| `get_site_info` | Site information |
| `list_users` | List site users |
| `get_option` | Get WordPress option |

### Load Balancing Strategies

| Strategy | Behaviour |
|----------|-----------|
| `speed-optimized` | Prefer cached results, parallel execution, skip validation |
| `quality-optimized` | Prefer sync execution, full validation, reasoning mode |
| `adaptive` | PSO-driven dynamic routing based on learned parameters |
| (default) | Standard routing with moderate caching |

---

## 7. Reasoning Controller

**Class:** `WP_MCP_AI_Reasoning_Controller`
**File:** `includes/services/class-wp-mcp-ai-reasoning-controller.php`
**Since:** 1.1.1

Detects when enhanced reasoning benefits a task and activates chain-of-thought prompting.

### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `REASONING_THRESHOLD` | `0.7` | Weighted score needed to activate reasoning |
| `HISTORY_LIMIT` | `500` | Maximum reasoning task records tracked |
| `METRICS_CACHE_TTL` | `3600` (1 hr) | Cache TTL for quality metrics |

### Complexity Trigger Weights

The reasoning controller scores each incoming task across five dimensions:

| Trigger | Weight | Detection Method |
|---------|--------|-----------------|
| `multi_step` | `0.30` | Task requires multiple sequential steps |
| `logical_complexity` | `0.25` | Task involves logical reasoning, comparisons, conditionals |
| `code_generation` | `0.20` | Task involves writing, analysing, or debugging code |
| `domain_expertise` | `0.15` | Task requires specialised knowledge |
| `verification_needed` | `0.10` | Task output needs fact-checking or validation |

**Activation rule:** If `Σ(trigger_score × weight) ≥ 0.7`, reasoning mode is activated.

### Storage Keys

| Option Key | Description |
|------------|-------------|
| `wp_mcp_ai_reasoning_quality_metrics` | Aggregate quality metrics |
| `wp_mcp_ai_reasoning_history` | History of reasoning task outcomes |

---

## 8. Multi-Agent System

### Agent Team Orchestrator

**Class:** `WP_MCP_AI_Agent_Team_Orchestrator`
**File:** `includes/services/class-wp-mcp-ai-agent-team-orchestrator.php`
**Since:** 1.1.0

Manages team composition, task decomposition, and coordinated execution. Teams are structured with three core roles:

| Role | Purpose |
|------|---------|
| **Planner** | Decomposes complex tasks into sub-tasks |
| **Executors** | Specialised agents that perform sub-tasks |
| **Critic** | Validates and quality-checks aggregated results |

#### Team Templates (Task Types)

| Task Type | Description |
|-----------|-------------|
| `research` | Multi-source research and synthesis |
| `content` | Content creation and editing |
| `ecommerce` | E-commerce operations and analysis |
| `development` | Software development tasks |
| `analysis` | Data analysis and reporting |
| `creative` | Creative tasks (design, copy) |
| `technical` | Technical operations and debugging |
| `generic` | General-purpose team |

### Agent Communication Service

**Class:** `WP_MCP_AI_Agent_Communication_Service`
**File:** `includes/services/class-wp-mcp-ai-agent-communication-service.php`
**Since:** 1.1.0

Provides inter-agent messaging for team coordination. Agents can share context, delegate tasks, and report results.

### Enhanced Workflow Coordinator

**Class:** `WP_MCP_AI_Enhanced_Workflow_Coordinator`
**File:** `includes/services/class-wp-mcp-ai-enhanced-workflow-coordinator.php`
**Since:** 1.1.1

| Constant | Value | Description |
|----------|-------|-------------|
| `STATE_OPTION_KEY` | `wp_mcp_ai_workflow_states` | Persisted workflow states |
| `MAX_PARALLEL_TASKS` | `3` | Maximum simultaneous task executions |
| `INITIALIZED_TIMEOUT` | `300` (5 min) | Timeout for stuck initialised workflows |

Workflows progress through states: `initialized` → `running` → `completed` | `failed`.

### Agent Context Manager

**Class:** `WP_MCP_AI_Agent_Context_Manager`
**File:** `includes/services/class-wp-mcp-ai-agent-context-manager.php`
**Since:** 1.1.1 (Phase 5)

Manages shared context across agents in a team, enabling context propagation and state sharing during multi-step workflows.

### Orchestration Tools

| Tool | Slug | Description |
|------|------|-------------|
| Create Agent Team | `create_agent_team` | Creates specialised multi-agent teams |
| Delegate to Agent | `delegate_to_agent` | Delegates subtasks to team members |
| Aggregate Agent Results | `aggregate_agent_results` | Merges results from multiple agents |
| Execute Workflow | `execute_workflow` | Executes predefined workflows |
| Validate Workflow | `validate_workflow` | Validates workflow configurations |
| Check Workflow Health | `check_workflow_health` | Monitors workflow execution health |
| Visualise Workflow Metrics | `visualize_workflow_metrics` | Renders workflow performance data |

---

## 9. Health Monitoring

### Orchestration Health Service

**Class:** `WP_MCP_AI_Orchestration_Health_Service`
**File:** `includes/services/class-wp-mcp-ai-orchestration-health-service.php`
**Since:** 1.1.1

Provides real-time health status with graceful degradation. Returns status label, icon, and detailed metrics. Uses transient caching for performance.

**Health statuses:** `healthy`, `warning`, `critical`, `degraded`.

### Async Health Monitor

**Class:** `WP_MCP_AI_Async_Health_Monitor`
**File:** `includes/services/class-wp-mcp-ai-async-health-monitor.php`
**Since:** 1.0.0

| Constant | Value | Description |
|----------|-------|-------------|
| `MAX_REASONABLE_DURATION` | `600` (10 min) | Maximum expected async task duration |
| `STUCK_JOB_THRESHOLD` | `300` (5 min) | Time to consider a pending job stuck |

Detects and reports:
- Stuck jobs (pending longer than threshold)
- Long-running tasks (exceeding max duration)
- Failed jobs
- Missing cron hooks

### Efficiency Monitor

**Class:** `WP_MCP_AI_Efficiency_Monitor`
**File:** `includes/services/class-wp-mcp-ai-efficiency-monitor.php`
**Since:** 1.1.1

Tracks orchestration efficiency metrics including tool execution times, cache hit rates, and async queue depth.

---

## 10. Budget Enforcement

**Class:** `WP_MCP_AI_Orchestration_Budget_Enforcement_Service`
**File:** `includes/services/class-wp-mcp-ai-orchestration-budget-enforcement-service.php`
**Since:** 1.1.1

Applies resource preset settings (§3) to enforce token and timeout limits via WordPress filters.

### Behaviour

| Budget Management | Token Limits | Timeouts |
|-------------------|-------------|----------|
| **Enabled** (default) | Tier-based limits from resource preset | Tier-based with `max_execution_time` cap |
| **Disabled** | 128,000 tokens (unlimited mode) | High-tier timeout unconditionally |

### Filters Used

| Filter | Priority | Effect |
|--------|----------|--------|
| `wp_mcp_ai_resource_max_tokens` | 5 | Enforces tier-based token limits |
| `wp_mcp_ai_resource_request_timeout` | 5 | Enforces tier-based timeout limits |

### Override Filter

When budget management is disabled, the unlimited token limit can be further customised:

```php
add_filter( 'wp_mcp_ai_resource_max_tokens_unlimited', function( $max_tokens ) {
    return 256000; // Custom unlimited ceiling.
} );
```

### Setting

Toggle via: `enable_budget_management` (Settings Registry, default: `true`).

---

## 11. Hooks & Filters Reference

### Actions

| Action | Parameters | Fired When |
|--------|-----------|------------|
| `wp_mcp_ai_preset_applied` | `$settings` (array) | Workflow preset applied |
| `wp_mcp_ai_agentic_metrics` | `$metrics` (array) | After agentic loop completes |

### Filters

| Filter | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `wp_mcp_ai_orchestration_presets` | `$presets` | array | Modify available workflow presets |
| `wp_mcp_ai_resource_max_tokens` | `$max_tokens, $tier` | int | Override tier-based token limits |
| `wp_mcp_ai_resource_request_timeout` | `$timeout, $tier, $max_execution_time, $ignore` | int | Override tier-based timeouts |
| `wp_mcp_ai_resource_max_tokens_unlimited` | `$max_tokens` | int | Override unlimited token ceiling (default: 128,000) |
| `wp_mcp_ai_max_agentic_iterations` | `$max_iterations, $assistant` | int | Override agentic loop depth |
| `wp_mcp_ai_pso_fitness_score` | `$fitness, $metrics, $assistant_id` | float | Override PSO fitness score |
| `wp_mcp_ai_pso_velocity_update` | `$velocity, $position, $pbest, $gbest, $assistant_id` | array | Override PSO velocity |
| `wp_mcp_ai_pso_inertia_weight` | `$weight, $conversation_count` | float | Override PSO inertia weight |
| `wp_mcp_ai_pso_coefficients` | `$coefficients` | array | Override PSO c₁/c₂ |
| `wp_mcp_ai_team_task_requirements` | `$requirements` | array | Enhance team task requirements |

### AJAX Actions

| Action | Capability | Description |
|--------|-----------|-------------|
| `wp_mcp_ai_apply_orchestration_preset` | `manage_options` | Apply workflow preset |
| `wp_mcp_ai_apply_preset` | `manage_options` | Apply resource preset (legacy) |
| `wp_mcp_ai_reseed_teams` | `manage_options` | Reseed agent teams |
| `wp_mcp_ai_seed_orchestration` | `manage_options` | Seed orchestration data |
| `wp_mcp_ai_run_orchestration_seeder` | `manage_options` | Run orchestration seeder |
| `wp_mcp_ai_get_orchestration_stats` | `manage_options` | Fetch orchestration statistics |
| `wp_mcp_ai_get_recent_workflows` | `manage_options` | List recent workflows |
| `wp_mcp_ai_execute_workflow` | `manage_options` | Execute a workflow |
| `wp_mcp_ai_restart_workflow` | `manage_options` | Restart a failed workflow |
| `wp_mcp_ai_deploy_team` | `manage_options` | Deploy an agent team |
| `wp_mcp_ai_create_team_from_modal` | `manage_options` | Create team from modal UI |

---

## 12. Data Storage Reference

### WordPress Options

| Option Key | Type | Description |
|------------|------|-------------|
| `wp_mcp_ai_active_preset` | string | Active workflow preset key |
| `wp_mcp_ai_custom_presets` | array | User-created custom presets |
| `wp_mcp_ai_orchestration_settings` | array | Runtime orchestration settings |
| `wp_mcp_ai_pso_global_best` | array | PSO global best: `{position, fitness, assistant_id, updated_at}` |
| `wp_mcp_ai_workflow_states` | array | Active workflow states |
| `wp_mcp_ai_reasoning_quality_metrics` | array | Reasoning quality metrics |
| `wp_mcp_ai_reasoning_history` | array | Reasoning task history (max 500) |

### Post Meta (per assistant)

| Meta Key | Type | Description |
|----------|------|-------------|
| `_wp_mcp_ai_pso_position` | array | PSO current position vector (7 floats) |
| `_wp_mcp_ai_pso_velocity` | array | PSO current velocity vector (7 floats) |
| `_wp_mcp_ai_pso_pbest` | array | PSO personal best position (7 floats) |
| `_wp_mcp_ai_pso_pbest_fit` | float | PSO personal best fitness score |
| `_wp_mcp_ai_pso_samples` | int | Conversation count since last PSO update |
| `_wp_mcp_ai_team_orchestration_mode` | string | Team orchestration mode |
| `_wp_mcp_ai_profession_orchestration_rules` | array | Profession-specific orchestration rules |

### Object Cache

| Cache Key | Group | TTL | Description |
|-----------|-------|-----|-------------|
| `wp_mcp_ai_tool_result_{hash}` | `mcp_ai_tool_results` | 900s | Cached tool results |
| `wp_mcp_ai_pso_total_conversations` | `wp_mcp_ai_pso` | 300s | Swarm conversation count |

---

## 13. Admin UI Reference

### Orchestration Dashboard

**Page:** NV oOS → Orchestration
**Slug:** `mcp-ai-orchestration`
**Class:** `WP_MCP_AI_Admin_Orchestration_Dashboard`
**File:** `includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php`

Features:
- Recent workflows table (sortable, with status badges)
- Workflow progress bars with percentage
- Continue/Restart action buttons
- Memory statistics
- Performance charts
- Orchestration seeder

**Assets:**
- `assets/css/admin-orchestration-dashboard.css`
- `assets/js/admin-orchestration-dashboard.js`
- `assets/css/admin-monitor-shared.css`

### Orchestration Settings Tab

**Section ID:** `orchestration`
**Class:** `WP_MCP_AI_Section_Orchestration`
**File:** `includes/admin/sections/class-wp-mcp-ai-section-orchestration.php`

Located in **Settings → NV oOS → Orchestration** tab. Contains:
- Health status indicator
- Load monitoring content
- Performance statistics
- Resource preset selector (13 presets)
- Workflow preset management
- Budget management toggle

### Team Management

| Page | Class | Description |
|------|-------|-------------|
| Add Team | `WP_MCP_AI_Add_Team_Page` | Create new agent team |
| Team Settings | `WP_MCP_AI_Admin_Team_Settings` | Manage team configuration |
| Create Team Button | `WP_MCP_AI_Admin_Create_Team_Button` | Quick-create from assistant list |

---

## 14. Service Files Index

All orchestration service files live in `includes/services/`:

| File | Class | Phase | Since |
|------|-------|-------|-------|
| `class-wp-mcp-ai-tool-execution-orchestrator.php` | `WP_MCP_AI_Tool_Execution_Orchestrator` | 1 | 1.0.0 |
| `class-wp-mcp-ai-tool-async-executor.php` | `WP_MCP_AI_Tool_Async_Executor` | 1 | 1.0.0 |
| `class-wp-mcp-ai-async-health-monitor.php` | `WP_MCP_AI_Async_Health_Monitor` | 1 | 1.0.0 |
| `class-wp-mcp-ai-agent-communication-service.php` | `WP_MCP_AI_Agent_Communication_Service` | 1.5 | 1.1.0 |
| `class-wp-mcp-ai-agent-team-orchestrator.php` | `WP_MCP_AI_Agent_Team_Orchestrator` | 1.5 | 1.1.0 |
| `class-wp-mcp-ai-enhanced-workflow-coordinator.php` | `WP_MCP_AI_Enhanced_Workflow_Coordinator` | 1.5 | 1.1.1 |
| `class-wp-mcp-ai-tool-load-monitor.php` | `WP_MCP_AI_Tool_Load_Monitor` | 2 | 1.1.1 |
| `class-wp-mcp-ai-tool-load-balancer.php` | `WP_MCP_AI_Tool_Load_Balancer` | 2 | 1.1.1 |
| `class-wp-mcp-ai-tool-chain-predictor.php` | `WP_MCP_AI_Tool_Chain_Predictor` | 2 | 1.1.1 |
| `class-wp-mcp-ai-efficiency-monitor.php` | `WP_MCP_AI_Efficiency_Monitor` | 2 | 1.1.1 |
| `class-wp-mcp-ai-reasoning-controller.php` | `WP_MCP_AI_Reasoning_Controller` | 3 | 1.1.1 |
| `class-wp-mcp-ai-code-optimizer.php` | `WP_MCP_AI_Code_Optimizer` | 3 | 1.1.1 |
| `class-wp-mcp-ai-orchestration-presets.php` | `WP_MCP_AI_Orchestration_Presets` | 4 | 1.1.1 |
| `class-wp-mcp-ai-orchestration-preset-service.php` | `WP_MCP_AI_Orchestration_Preset_Service` | 4 | 1.0.0 |
| `class-wp-mcp-ai-orchestration-health-service.php` | `WP_MCP_AI_Orchestration_Health_Service` | 4.5 | 1.1.1 |
| `class-wp-mcp-ai-orchestration-budget-enforcement-service.php` | `WP_MCP_AI_Orchestration_Budget_Enforcement_Service` | 4.5 | 1.1.1 |
| `class-wp-mcp-ai-agent-context-manager.php` | `WP_MCP_AI_Agent_Context_Manager` | 5 | 1.1.1 |
| `class-wp-mcp-ai-vector-context-service.php` | `WP_MCP_AI_Vector_Context_Service` | 5.5 | 1.1.1 |
| `class-wp-mcp-ai-pso-optimizer-service.php` | `WP_MCP_AI_PSO_Optimizer_Service` | 6 | 1.2.0 |
| `class-wp-mcp-ai-async-tool-orchestrator.php` | `WP_MCP_AI_Async_Tool_Orchestrator` | 1 | 1.0.0 |
| `class-wp-mcp-ai-tool-profiler.php` | `WP_MCP_AI_Tool_Profiler` | 2 | 1.1.1 |
| `class-wp-mcp-ai-function-call-validator.php` | `WP_MCP_AI_Function_Call_Validator` | 1 | 1.0.0 |
| `class-wp-mcp-ai-orchestration-renderer.php` | `WP_MCP_AI_Orchestration_Renderer` | 4 | 1.1.1 |

### Registration (services-init.php)

Services are loaded in `includes/services-init.php`. The load order follows the phase numbering above. Key accessor functions:

| Function | Returns |
|----------|---------|
| `wp_mcp_ai_get_async_tool_orchestrator()` | `WP_MCP_AI_Async_Tool_Orchestrator` instance |
| `wp_mcp_ai_get_pso_optimizer_service()` | `WP_MCP_AI_PSO_Optimizer_Service` instance |

---

## Related Documentation

| Document | Path |
|----------|------|
| Architecture Overview | `docs/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md` |
| Dashboard Implementation | `docs/ORCHESTRATION_DASHBOARD_IMPLEMENTATION_SUMMARY.md` |
| Workflow Quick Reference | `docs/WORKFLOW_ORCHESTRATION_QUICK_REFERENCE.md` |
| Budget Enforcement | `docs/architecture/orchestration/orchestration-budget-enforcement.md` |
| Multi-Agent Implementation | `docs/MULTI_AGENT_ORCHESTRATION_IMPLEMENTATION.md` |
| Dashboard Visual Guide | `docs/visual-guides/orchestration/ORCHESTRATION-DASHBOARD-VISUAL-GUIDE.md` |
| Hooks Reference | `docs/DEVELOPER_HOOKS_REFERENCE.md` |
| DeepSeek V4 Usage Guide | `docs/DEEPSEEK-V4-USAGE-GUIDE.md` |
