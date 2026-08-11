# Orchestration & Harness Layer Reference

**Version:** 1.1.51
**Last Updated:** August 11, 2026
**Status:** Comprehensive audit completed

## Overview

NV oOS implements a multi-layered AI orchestration and LLM harnessing subsystem that wraps every AI provider call with configurable safety, reasoning, retrieval, and evaluation layers. This document serves as the authoritative reference for the current implementation and its alignment with 2026 industry standards.

## Harness Layers (A–J)

All layers are **opt-in per assistant** via the Harness Profile metabox. Default is OFF (behaviour-preserving).

### Layer A — Prompt Cue Library
- **File:** `includes/harness/class-wp-mcp-ai-prompt-cue-library.php`
- **Function:** Injects contextual cues into the chat pipeline before the LLM call
- **Status:** ✅ Complete

### Layer B — Reasoning Trace
- **File:** `includes/harness/class-wp-mcp-ai-reasoning-trace.php`
- **Function:** Best-of-N sampling with configurable samples (max 8) and iteration tracking
- **Status:** ✅ Complete

### Layer C — Tool Router Harness
- **File:** `includes/harness/class-wp-mcp-ai-tool-router-harness.php`
- **Function:** Fixed vs scored tool routing with preset weight matrix [-5, 5]
- **Status:** ✅ Complete

### Layer D — Retrieval Harness
- **File:** `includes/harness/class-wp-mcp-ai-retrieval-harness.php`
- **Function:** RAG with configurable k-nearest neighbors and citation requirements
- **Status:** ✅ Complete

### Layer E — Self-Refine Loop
- **File:** `includes/harness/class-wp-mcp-ai-self-refine-loop.php`
- **Function:** Iterative refinement with configurable max iterations (max 4)
- **Status:** ✅ Complete

### Layer F — Memory Scoping
- **File:** `includes/harness/class-wp-mcp-ai-harness-profile.php` (config)
- **Function:** Task-class memory routing, PII filtering, MemPalace integration
- **Status:** ✅ Complete

### Layer G — Evaluation
- **Files:** `includes/harness/class-wp-mcp-ai-harness-trace-store.php`, `trace-capture.php`, `search-engine.php`, `population.php`, `auto-deploy.php`, `eval-scheduler.php`
- **Function:** Trace storage/capture, harness search engine, population engine, auto-deploy pipeline, eval scheduling with cron
- **Measurement:** 3 metrics classes, 3 observer classes, OTel exporter (437 lines), counterfactual runner
- **Status:** ✅ Complete

### Layer H — Curriculum Export (Pro)
- **File:** `addons/pro/includes/harness/class-wp-mcp-ai-tool-export-fine-tune-curriculum.php`
- **Function:** Exports harness traces as fine-tuning curriculum
- **Status:** ✅ Complete (Pro only)

### Layer I — Guardrails (Input Safety)
- **File:** `includes/harness/class-wp-mcp-ai-guardrails.php`
- **Function:** Prompt injection detection, jailbreak prevention, off-topic diversion blocking
- **Modes:** block | warn | log
- **Strictness:** low | medium | high
- **OWASP Coverage:** LLM01 (Prompt Injection) ✅
- **Status:** ⚠️ Input-only — no output content safety

### Layer J — Necessity Gate
- **File:** `includes/harness/class-wp-mcp-ai-necessity-gate.php`
- **Function:** 3-tier gating: safe-tool allowlist → necessity classifier → irreversibility gate
- **Escalation:** Max 3 consecutive / 20 total denials before human escalation (Claude Code-inspired)
- **Status:** ✅ Complete

## Orchestration System

### Multi-Agent Coordination
- **Roles:** Planner, Executor, Critic, Specialist
- **Team composition:** Automated assembly based on task requirements
- **Execution modes:** single, sequential, parallel, swarm
- **Files:** `includes/professions/`, `includes/agents/`, `includes/admin/class-wp-mcp-ai-admin-multi-agent-dashboard.php`

### Workflow Engine
- **V1:** `execute_workflow` tool — linear task execution
- **V2:** `includes/class-wp-mcp-ai-workflow-engine-v2.php` — graph-aware, feature-flagged off by default
- **DAG Builder:** `includes/admin/class-wp-mcp-ai-admin-dag-builder.php`
- **Run Timeline:** `includes/admin/class-wp-mcp-ai-admin-run-timeline.php`
- **Workflow Triggers:** `includes/admin/class-wp-mcp-ai-admin-workflow-triggers.php`

### HITL (Human-in-the-Loop)
- **Approval Queue:** `includes/class-wp-mcp-ai-approval-queue.php`
- **REST Controller:** `includes/rest/class-wp-mcp-ai-rest-approval-controller.php`
- **Admin Page:** `includes/admin/class-wp-mcp-ai-admin-approvals.php`
- **CLI:** `includes/cli/class-wp-mcp-ai-cli-approval-command.php`

### Background Jobs
- **8 inline-async-tick consumers:** transcript mining, async tool executor, SaaS Apply, Crawl4AI, Docs Hub rebuild, Graphify reindex, Harness eval, Gemini Veo polling
- **Dead Letter Queue:** Custom DB table `mcp_ai_dead_letters` with retry/dismiss/audit
- **Circuit Breaker (Pro):** `addons/pro/includes/class-wp-mcp-ai-circuit-breaker.php`
- **Execution Logger (Pro):** `addons/pro/includes/class-wp-mcp-ai-execution-logger.php`

## Protocol Support

| Protocol | Implementation | Compliance |
|---|---|---|
| **MCP** (Model Context Protocol) | Stateless core (2026-07-28), Streamable HTTP, `server/discover`, `_meta` headers, TTL/cache-scope, legacy shim | ✅ Full |
| **A2A** (Agent-to-Agent) | 7 classes: agent card, client, task manager, webhook handler, well-known discovery, message translator, push notifications | ✅ Full |
| **ACP** (Agent Client Protocol) | Server, JSON-RPC dispatcher, session bridge, transport for Zed/JetBrains | ✅ Core |
| **OAuth 2.1** | PKCE, hierarchical scopes, token management UI, browser-based login, Resource Indicators | ✅ Full |
| **MCP Apps** (SEP-1865) | Per-assistant remote MCP server connections (up to 10), JSON-RPC tool bridging | ✅ Full |

## Security Infrastructure

| Component | File | Lines |
|---|---|---|
| Request Guard | `includes/security/class-wp-mcp-ai-request-guard.php` | ~300 |
| Security Posture | `includes/security/class-wp-mcp-ai-security-posture.php` | ~400 |
| Destructive Ops Gate | `includes/security/class-wp-mcp-ai-destructive-ops-gate.php` | ~200 |
| URL Guard | `includes/security/class-wp-mcp-ai-url-guard.php` | ~150 |
| Concurrency Guard | `includes/security/class-wp-mcp-ai-concurrency-guard.php` | ~150 |
| Cost Tracker | `includes/security/class-wp-mcp-ai-cost-tracker.php` | ~200 |
| API Key Store | `includes/security/class-wp-mcp-ai-api-key-store.php` | ~250 |
| CSP Headers | `includes/security/class-wp-mcp-ai-csp-headers.php` | ~150 |
| Audit Logger | `includes/security/class-wp-mcp-ai-audit-logger.php` | ~200 |
| Rate Limit Manager | `includes/class-wp-mcp-ai-rate-limit-manager.php` | ~150 |

## Observability

| Component | Lines |
|---|---|
| Chat Turn Metrics + Observer | 213 + 521 |
| SSE Metrics + Observer | 202 + 384 |
| Tool Execution Observer | 358 |
| Stock Metrics | 210 |
| OTel Exporter | 437 |
| Token Tracking Database | ~300 |
| Usage Tracker | ~250 |
| Nefarious Usage Monitor | ~676 |

## 2026 Industry Standards Alignment

### OWASP LLM Top 10 Coverage

| # | Risk | Coverage | Implementation |
|---|---|---|---|
| LLM01 | Prompt Injection | ✅ Full | Layer I Guardrails (3 modes, 3 strictness) |
| LLM02 | Sensitive Information Disclosure | ⚠️ Partial | PII filter on inputs only |
| LLM03 | Supply Chain | ❌ Missing | v1.1.51 — added model integrity verification |
| LLM04 | Data Poisoning | ❌ Missing | No training data validation |
| LLM05 | Improper Output Handling | ❌ Missing | v1.1.51 — added output guardrails |
| LLM06 | Excessive Agency | ✅ Full | Layer J Necessity Gate + Destructive Ops Gate |
| LLM07 | System Prompt Leakage | ⚠️ Partial | Guardrails detect role-change attacks |
| LLM08 | Vector Weakness | ⚠️ Partial | No RAG-specific poison detection |
| LLM09 | Misinformation / Overreliance | ❌ Missing | v1.1.51 — added citation verification |
| LLM10 | Unbounded Consumption | ✅ Full | Concurrency Guard + Rate Limiter + Cost Ceiling |

### EU AI Act Readiness (August 2, 2026)

| Article | Requirement | Coverage |
|---|---|---|
| Art. 13 | Transparency & Disclosure | ✅ SGI compliance infrastructure, chat transparency UI |
| Art. 14 | Human Oversight | ✅ HITL Approval Queue, Necessity Gate escalation |
| Art. 15 | Accuracy, Robustness, Cybersecurity | ✅ 10 security classes, 21 posture signals |
| Art. 50 | AI-Generated Content Labeling | ⚠️ Chat transparency badges exist, C2PA missing |
| Art. 52 | High-Risk Classification | ⚠️ No explicit risk classification framework |

### Protocol Standards

| Standard | NV oOS |
|---|---|
| MCP Streamable HTTP (2026) | ✅ Stateless core, `server/discover` |
| A2A Agent Card Discovery | ✅ Well-known endpoint, agent cards |
| OAuth 2.1 + Resource Indicators | ✅ Full implementation |
| OpenTelemetry GenAI Conventions | ⚠️ OTel exporter present, conventions need alignment |
| C2PA Content Credentials | ❌ Not implemented |

---

## v1.1.51 Gap Remediation Summary

The following gaps were identified and addressed in v1.1.51:

| # | Gap | Resolution | Type |
|---|---|---|---|
| 1 | Output Content Safety (LLM05) | New `WP_MCP_AI_Output_Guardrail` class | Code |
| 2 | EU AI Act Compliance Mapping | New `EU_AI_ACT_2026.md` document | Docs |
| 3 | Content Provenance (C2PA) | Placeholder infrastructure + roadmap | Code |
| 4 | Sensitive Info in Outputs (LLM02) | Enhanced PII filter to scan outputs | Code |
| 5 | Automated Red Teaming | `composer run security:red-team` + CI workflow | Infra |
| 6 | Hallucination/Citation Verification | Enhanced Retrieval Harness with factuality checks | Code |
| 7 | Durable Execution GA | Workflow Engine V2 graduated from feature-flag | Code |
| 8 | Progressive Model Rollout | Canary deployment infrastructure | Code |
| 9 | Verifiable Execution | Cryptographic attestation placeholder | Code |
| 10 | Model Supply Chain Security | Model integrity verification | Code |
| 11 | OTel GenAI Conventions | Span attribute alignment | Code |
| 12 | Multi-Modal Safety | Image generation safety classifier | Code |
| 13 | Structured Output Enforcement | JSON Schema validation layer | Code |
| 14 | Prompt Caching Strategy | Universal semantic caching layer | Code |
| 15 | A2A Protocol Verification | Spec compliance audit document | Docs |

## Related Documents

- [Security Posture](../operations/security/SECURITY_POSTURE.md)
- [Compliance Traceability](../operations/compliance/TRACEABILITY.md)
- [SGI Transparency & Compliance](../features/sgi-transparency-compliance.md)
- [Abilities API](../features/abilities-api.md)
- [Architecture Overview](../developer/architecture/ARCHITECTURE.md)
