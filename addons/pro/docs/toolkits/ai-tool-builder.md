# AI Tool Builder Toolkit

> Meta-toolkit for building new NV oOS tools end-to-end: scaffolding, parameter and logic
> generation, schema validation, security analysis, performance benchmarking, compliance
> checks, refactoring, tests, and documentation generation.

| | |
|---|---|
| **Activation setting** | `enable_ai_tool_builder_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → AI Tool Builder |
| **Tools** | 11 |

---

## Tools

| Tool slug | Purpose |
|---|---|
| `generate_tool_scaffold` | Create boilerplate for a new tool |
| `generate_tool_parameters` | Suggest parameter schema |
| `generate_tool_logic` | Generate the `execute()` body |
| `validate_tool_schema` | Check parameters against OpenAI schema rules |
| `analyze_tool_security` | Scan a tool class for unsafe patterns |
| `check_tool_compliance` | Compliance / coding-standards check |
| `benchmark_tool_performance` | Measure runtime, memory, token cost |
| `refactor_tool_code` | Suggest refactors to existing tools |
| `generate_tool_tests` | Generate PHPUnit tests |
| `generate_tool_documentation` | Generate docblocks / README entries |

Tool source: `addons/pro/includes/tools/ai-tool-builder/`.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **AI Tool Builder Toolkit** under **NV oOS → Settings → Pro Features**.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/ai-tool-builder/README.md`](../../includes/tools/ai-tool-builder/README.md)
- [Architect Agent Toolkit](architect-agent.md) — gives the assistant filesystem / git
  access so it can actually write the scaffolded files
