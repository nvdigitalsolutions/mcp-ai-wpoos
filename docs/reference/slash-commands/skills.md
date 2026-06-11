# /skills

> **Added in:** v2.1.0 · **Capability:** `edit_posts`

## Synopsis

```
/skills [--list] [--install=<slug>] [--show=<slug>] [--json]
```

List, inspect, and install agent skill packs. Falls back to scanning `includes/bundled-skills/` when the Skill Pack Registry is unavailable.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--list` | List available skill packs (default action) | On |
| `--install=<slug>` | Install a skill pack (`manage_options` required) | — |
| `--show=<slug>` | Show skill pack details (name, description, included skills) | — |
| `--json` | Return raw JSON output | Off |

## Examples

```
/skills
/skills --show=pdf
/skills --install=brand-guidelines
/skills --json
```

## Required Capability

`edit_posts` (list/show); `manage_options` (install)

## Notes

- Primary data source: `WP_MCP_AI_Skill_Pack_Registry::instance()`.
- Fallback: directory scan of `includes/bundled-skills/` for `SKILL.md` files.
- Status badges: ✅ installed/bundled, ⬜ available.
