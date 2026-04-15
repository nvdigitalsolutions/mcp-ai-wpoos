# Erlang C Staffing & Queue Health Tools

**Added:** April 2026 (v1.1.8)  
**Tier:** Base plugin (no Pro addon required)  
**PHP Compatibility:** 7.4+

---

## Overview

NV oOS ships four Erlang C tools that bring teletraffic-engineering science directly into the AI assistant workflow. The Erlang C formula—derived from A. K. Erlang's 1917 telephone engineering research—is the industry standard for answering the question: *"How many agents do I need to answer 80 % of contacts within 20 seconds?"*

These tools are useful for:
- **Contact centre workforce management** (voice, chat, email queues)
- **Help-desk capacity planning** (support ticket SLA modelling)
- **AI concurrency tuning** (right-sizing the plugin's own session limits)
- **Real-time operations dashboards** (live SLA alerting)

All heavy math is handled by the shared `WP_MCP_AI_Erlang_C` helper class at `includes/class-wp-mcp-ai-erlang-c.php`. No external libraries or API calls are needed.

---

## Core Concepts

| Term | Definition |
|------|-----------|
| **Arrival rate** | Contacts arriving per hour (λ) |
| **Average handle time** | Mean seconds per contact, talk + wrap (AHT) |
| **Traffic intensity** | Arrival rate × AHT / 3600 — measured in **Erlangs** |
| **Utilisation** | Traffic intensity ÷ agents; must be < 1 for the queue to be stable |
| **Service level** | Percentage of contacts answered within the target wait (e.g. 80 % in ≤ 20 s) |
| **Probability of waiting** | Erlang C(A, N) — the chance any contact has to queue |
| **Average wait time** | Expected queue time for contacts that do wait |

---

## Tool Reference

### `calculate_erlang_c` — Staffing Calculator

**Class:** `WP_MCP_AI_Tool_Calculate_Erlang_C`  
**Capability:** `edit_posts`  
**Registry group:** base tools  

The general-purpose staffing solver. Given your volume and handle time it returns the minimum agents needed to hit your SLA, plus the achieved metrics for a given agent count.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `arrival_rate` | number | ✅ | Contacts per hour |
| `avg_handle_time` | number | ✅ | Average handle time in seconds |
| `num_agents` | integer | optional | Override to check a specific headcount |
| `target_service_level_seconds` | integer | optional | Target answer time in seconds (default: 20) |
| `target_service_level_pct` | number | optional | Target service level % (default: 80) |

**Response fields:**

| Field | Description |
|-------|-------------|
| `agents_needed` | Minimum agents to meet the SLA target |
| `probability_wait` | Erlang C probability any contact waits (0–1) |
| `avg_wait_time_seconds` | Expected average queue wait in seconds |
| `utilization` | Agent utilisation ratio (should be 0.70–0.85) |
| `service_level_achieved` | Actual SLA % with `agents_needed` agents |
| `traffic_intensity_erlangs` | Raw Erlang traffic load |

**Example prompt:**
> "We handle 350 chats per hour with an average handle time of 4 minutes. What is the minimum staffing to answer 80 % of chats in under 20 seconds?"

---

### `erlang_c_concurrency_advisor` — AI Session Tuning

**Class:** `WP_MCP_AI_Tool_Erlang_C_Concurrency_Advisor`  
**Capability:** `manage_options`  
**Registry group:** base tools  

Reads the plugin's own session arrival-rate counters and transcript-duration averages, runs Erlang C, and returns a data-driven recommendation for the **Max Concurrent Sessions** setting in **Settings → NV oOS → Performance**.

This removes the guesswork from sizing your WordPress AI assistant infrastructure.

**Parameters:** none required — the tool reads live plugin telemetry automatically.

**Response fields:**

| Field | Description |
|-------|-------------|
| `recommended_concurrent_sessions` | Suggested max concurrent sessions value |
| `probability_of_waiting` | Probability a visitor would queue at current load |
| `current_arrival_rate` | Measured sessions per hour |
| `current_avg_duration_seconds` | Measured average session duration |
| `current_setting` | Current Max Concurrent Sessions value |
| `utilization` | Current session capacity utilisation |

---

### `erlang_c_staffing_advisor` — Multi-Channel Staffing

**Class:** `WP_MCP_AI_Tool_Erlang_C_Staffing_Advisor`  
**Capability:** `manage_options`  
**Registry group:** extended tools (self-disables without optional WFM endpoint)  

An enhanced staffing recommendation tool that extends the base Erlang C calculation with:

- **Multi-channel concurrency multiplier** — chat agents can handle 2–4 contacts simultaneously; voice agents handle 1. The tool applies the correct multiplier per channel type before computing required headcount.
- **Bot containment adjustment** — if an AI assistant deflects a percentage of contacts before they reach a human, that deflection rate is subtracted from the arrival rate used in the calculation.
- **Optional live WFM data pull** — configure a NICE WFM, Genesys, Verint, or Calabrio REST endpoint URL and bearer token in **Settings → NV oOS → Integrations → WFM**; the tool will pull the current shift plan to compare scheduled vs. Erlang-recommended headcount.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `arrival_rate` | number | ✅ | Total contacts per hour across all channels |
| `avg_handle_time` | number | ✅ | Average handle time in seconds |
| `channel` | string | optional | `voice`, `chat`, or `email` (default: `chat`) |
| `chat_concurrency` | integer | optional | Simultaneous chats per agent (default: 3) |
| `bot_containment_rate` | number | optional | Fraction deflected by AI (0–1, default: 0) |
| `target_service_level_seconds` | integer | optional | Target answer time (default: 20) |
| `target_service_level_pct` | number | optional | Target SLA % (default: 80) |

**Response:** a structured staffing recommendation card with `agents_needed`, channel-adjusted `effective_arrival_rate`, `bot_deflected_contacts`, `wfm_scheduled_agents` (if WFM endpoint configured), and `variance` between scheduled and recommended.

---

### `erlang_c_queue_health` — Real-Time SLA Monitoring

**Class:** `WP_MCP_AI_Tool_Erlang_C_Queue_Health`  
**Capability:** `manage_options`  
**Registry group:** extended tools  

A real-time operations monitoring tool that:

1. **Polls** a configured contact-centre REST endpoint (or reads a JetEngine CCT) for current queue depth and available agents.
2. **Runs Erlang C** to compute the live achieved service-level percentage.
3. **Fires `wp_mcp_ai_queue_alert`** when the live SLA falls below the configured threshold — allowing integrations with Slack, Teams, PagerDuty, and any webhook target.
4. **Stores a snapshot** in a JetEngine CCT for trend charting and historical reporting.

**Configure endpoint:** **Settings → NV oOS → Integrations → WFM** — set the queue stats URL, bearer token, and alert threshold %.

**Hook fired on breach:**

```php
do_action( 'wp_mcp_ai_queue_alert', $snapshot );
```

See [hooks-reference.md](../hooks-reference.md#erlang-c--queue-operations-hooks) for the full `$snapshot` schema and usage examples.

---

## Usage Scenarios

### Scenario 1: Capacity planning meeting

> **User:** "We're forecasting 500 voice calls per hour next quarter with an AHT of 4 minutes. How many agents do we need for an 80/20 service level?"

The AI calls `calculate_erlang_c` with `arrival_rate=500`, `avg_handle_time=240`, `target_service_level_seconds=20`, `target_service_level_pct=80` and returns the exact headcount with utilisation and probability of waiting.

### Scenario 2: Tuning AI session limits

> **User:** "Our site is getting slow during peak hours. Should I increase the max concurrent sessions?"

The AI calls `erlang_c_concurrency_advisor`. It reads the plugin's session counters, computes utilisation, and returns a specific recommended value for the Max Concurrent Sessions setting, along with the probability that a visitor would queue at current load.

### Scenario 3: Chat team staffing with AI deflection

> **User:** "We have 200 chat contacts per hour. Our bot deflects 30 %. Each human agent handles 3 chats simultaneously with a 3-minute AHT. How many agents do we need?"

The AI calls `erlang_c_staffing_advisor` with `arrival_rate=200`, `bot_containment_rate=0.3`, `channel=chat`, `chat_concurrency=3`, `avg_handle_time=180`. The tool computes the effective arrival rate after deflection (140 contacts/hour), applies the concurrency multiplier, and returns the adjusted headcount.

### Scenario 4: Live SLA alerting

The ops team configures the WFM endpoint in settings and sets up a WordPress cron job to call `erlang_c_queue_health` every 5 minutes. When the live service-level drops below 70 %, `wp_mcp_ai_queue_alert` fires and a custom hook sends a Slack notification. The snapshot is stored in JetEngine CCT for the weekly SLA report.

---

## Industry Standards

| Standard | Description |
|----------|-------------|
| **80/20** | Answer 80 % of contacts within 20 seconds — classic call-centre SLA |
| **90/30** | Answer 90 % within 30 seconds — common for high-value accounts |
| **Chat 90/60** | Answer 90 % of chats within 60 seconds — ICMI chat benchmark |
| **0.70–0.85** | Healthy agent utilisation range — above 0.85 creates excessive wait times |

---

## Helper Class API

Located at `includes/class-wp-mcp-ai-erlang-c.php`. Can be used directly by custom tools or integrations:

```php
use WP_MCP_AI_Erlang_C;

// Minimum agents for 80/20 SLA
$agents = WP_MCP_AI_Erlang_C::min_agents_for_service_level(
    $arrival_rate,      // calls per hour
    $avg_handle_time,   // seconds
    20,                 // target seconds
    0.80                // target fraction
);

// Probability of waiting with N agents
$p_wait = WP_MCP_AI_Erlang_C::erlang_c( $agents, $traffic_intensity );

// Average wait in seconds
$wait = WP_MCP_AI_Erlang_C::avg_wait_time( $agents, $arrival_rate, $avg_handle_time );

// Achieved service level
$sla = WP_MCP_AI_Erlang_C::service_level( $agents, $arrival_rate, $avg_handle_time, 20 );
```

---

## Related Documentation

- [`docs/reference/tools/tool-reference.md`](../reference/tools/tool-reference.md#erlang-c-queuing-theory-tools) — concise tool entries
- [`docs/hooks-reference.md`](../hooks-reference.md#erlang-c--queue-operations-hooks) — `wp_mcp_ai_queue_alert` action
- [`docs/features/LITTLES_LAW_INTEGRATION.md`](LITTLES_LAW_INTEGRATION.md) — Little's Law integration notes
