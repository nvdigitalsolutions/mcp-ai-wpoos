# `/persona` — AI Profession / Persona Switcher

> **Pro Required:** Yes  
> **Since:** 2.1.0  
> **Capability:** `edit_posts`  
> **Aliases:** `profile`, `assistant`

## Synopsis

```
/persona [<slug>] [--list] [--show=<slug>] [--reset] [--json]
```

Switch the AI assistant's profession/persona or browse available personas.

## Flags

| Flag | Description |
|------|-------------|
| `<slug>` (positional) | Slug of the persona to load |
| `--list` | List all available personas |
| `--show=<slug>` | Show full details for a persona |
| `--reset` | Reset to the default persona |
| `--assistant-id=<n>` | Override assistant ID (falls back to context) |
| `--json` | Return JSON envelope |

## Examples

```bash
# Load the "developer" persona
/persona developer

# Load using an alias
/profile seo-expert

# List all available personas
/persona --list

# Show details for a persona
/persona --show=legal-assistant

# Reset to default
/persona --reset

# JSON output
/persona developer --json
```

## How It Works

1. The command resolves the `WP_MCP_AI_Profession_Service` via DI container first, falling back to direct instantiation.
2. For a positional slug: calls `$service->load_profession( $slug, $assistant_id )`.
3. On success, fires `do_action( 'wp_mcp_ai_persona_loaded', $slug, $assistant_id, $context )`.
4. `--list` calls `$service->list_professions()`.
5. `--reset` calls `$service->reset_profession( $assistant_id )`.

## Notes

- Requires `WP_MCP_AI_Profession_Service` and `WP_MCP_AI_Profession_Repository`.
- Returns `service_unavailable` if either class is missing.
- Guest requests are blocked.
- Requires Pro addon.
