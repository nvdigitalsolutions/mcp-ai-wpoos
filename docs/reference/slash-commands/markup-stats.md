# /markup-stats

> **Added in:** v2.1.0 · **Capability:** `manage_options`

## Synopsis

```
/markup-stats [--verbose|-v] [--json] [--reset]
```

Show aggregate markup telemetry counters tracked by `WP_MCP_AI_Markup_Telemetry`.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--verbose` / `-v` | Show per-tool and per-mode breakdown (all rows) | Off (top 5) |
| `--json` | Return raw JSON data | Off |
| `--reset` | Reset all telemetry counters (`manage_options` required) | Off |

## Examples

```
/markup-stats
/markup-stats --verbose
/markup-stats --json
/markup-stats --reset
```

## Required Capability

`manage_options`

## Notes

- Counters are stored in a persistent WordPress option; they survive restarts.
- `--reset` is irreversible. Only administrators can execute it.
