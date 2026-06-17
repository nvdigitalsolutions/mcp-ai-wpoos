# Application Layer

## Purpose

The orchestration layer — bridges domain contracts to infrastructure services. Contains the agentic loop (`ChatOrchestrator`), provider routing (`ProviderRouter`), tool lifecycle management (`ToolRegistry`), and agent skill discovery (`SkillRegistry`).

These classes are the "brain" of the oOS engine — they coordinate providers, tools, events, and streaming but contain zero framework-specific code.

## Subdirectories

| Folder | Purpose |
|---|---|
| `Chat/` | Agentic loop — `ChatOrchestrator` (handleChat + SSE streaming) |
| `Provider/` | Provider routing — `ProviderRouter` (12-provider registration + model selection) |
| `Tool/` | Tool lifecycle — `ToolRegistry` (register, execute, disable, build definitions) |
| `Skill/` | Agent skills — `SkillRegistry` (SKILL.md parsing, progressive disclosure catalogue) |

## Also Load

- [`../Domain/Contract/README.md`](../Domain/Contract/README.md) — interfaces consumed here
- [`../Infrastructure/Provider/README.md`](../Infrastructure/Provider/README.md) — provider clients registered by the router
- [`../Infrastructure/Streaming/README.md`](../Infrastructure/Streaming/README.md) — SSE handler used by the orchestrator
- [`../Infrastructure/Cost/README.md`](../Infrastructure/Cost/README.md) — cost calculation
- [`docs/proposals/cross-platform-extraction-architecture.md`](../../../../docs/proposals/cross-platform-extraction-architecture.md)
