---
name: nvoos-site-context
description: Per-site fact template for NV oOS fleets. The human fills in one file per managed site (store URL, brand, voice, active campaigns); you load the relevant file before acting on that site. Use whenever starting work on a site or when you need brand/store facts.
version: 1.0.0
---

# NV oOS Site Context

Fill in one copy of this template per site and keep it in your Hermes skills
directory (e.g. `~/.hermes/skills/nvoos/<site-slug>/SKILL.md`). The human owns
this file; ask before "improving" it.

## Template

```markdown
---
name: <site-slug>
description: Operating facts for the <Site Name> NV oOS site.
---

# <Site Name>

- **MCP server key:** <name in config.yaml, e.g. store_b>
- **URL:** https://...
- **What it is:** <one line, e.g. "fragrance e-commerce store">
- **Brand voice:** <tone rules for any copy you write>
- **Active campaigns:** <what is running this month>
- **Do-not-touch:** <posts, categories, products, or settings to never modify>
- **Humans to ask:** <names/roles for approvals>
```

## Usage rules

- Load the file for the site before your first tool call on it each session.
- If no file exists for a site, ask the human for the facts before doing
  anything beyond read-only discovery.
- Contradictions between this file and the human's live instruction: the live
  instruction wins; flag the contradiction.
