# Traits

## Purpose

Shared PHP traits for Pro addon toolkits — reusable behaviour that crosses toolkit boundaries without inheritance coupling.

## Tier

| | |
|---|---|
| **Distribution** | Pro addon |
| **PHP target** | 8.1+ |
| **License** | Proprietary |
| **Loaded by** | Explicit `require_once` in consuming classes |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_CRM_Relevance_Search` | `trait-wp-mcp-ai-relevance-search.php` | CRM + Healthcare search tools |

## Conventions

- One trait per file, named `trait-wp-mcp-ai-{name}.php`.
- Traits are NOT autoloaded — consuming classes must `require_once` them explicitly.
- Each trait must declare `@subpackage Traits` in its file header.

## Tests

```bash
vendor/bin/phpunit --filter '/Relevance|TFIDF/'
```

## See Also

- Parent: [`../`](../) — pro includes root
- Consumers: [`../tools/crm/`](../tools/crm/), [`../tools/healthcare/`](../tools/healthcare/)
