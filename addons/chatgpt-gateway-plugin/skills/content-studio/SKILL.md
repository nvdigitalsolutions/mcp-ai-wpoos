---
name: content-studio
description: Research and draft content using the NV oOS demo site's capabilities over the fleet gateway — deep research with citations, then drafting grounded in the demo site's public content.
---

Work against the connected `nvoos_gateway` MCP server. Every tool name carries
the `demo_` prefix.

Workflow:

1. **Research** — use `demo_deep_research` for topic discovery with
   citations; prefer `run_mode: "background"` for standard/comprehensive
   depth (the tool is long-running; immediate mode may hit client-side
   timeouts).
2. **Ground in site content** — when the demo site exposes content tools
   (check `tools/list` first), cross-reference findings with the site's
   published posts and pages before drafting.
3. **Draft** — write in the user's requested voice and cite sources with
   links.

Rules:

- Never publish or create content without explicit user confirmation and
  review of the exact draft.
- Only use tools present in `tools/list`; if the demo site does not expose a
  content tool, say so rather than assuming one exists.
- Deep research is open-world: attribute claims to cited sources and do not
  present uncited material as fact.
