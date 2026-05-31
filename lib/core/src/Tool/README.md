# Tools

## Purpose

Framework-agnostic AI tool implementations — the canonical tool surface for the oOS engine. Every tool extends `AbstractTool` and injects only domain interfaces (`ContentStoreInterface`, `SettingsStoreInterface`, PSR-18 `HttpClientInterface`, etc.). Zero WordPress references.

## Tier

| | |
|---|---|
| **Distribution** | `oos/core` Composer package |
| **PHP target** | 8.1+ |
| **Loaded by** | `includes/bootstrap/oos-bridge.php` via `ToolRegistry::register()` |

## Public Surface

The folder's external contract is the **tool slug** registered with `Oos\Core\Application\Tool\ToolRegistry`.

### Base classes

| Symbol | File | Purpose |
|---|---|---|
| `AbstractTool` | `AbstractTool.php` | Canonical envelope helpers (`success`, `collection`, `emptyResult`), param sanitizers, error shortcuts |
| `AbstractHuggingFaceTool` | `AbstractHuggingFaceTool.php` | Shared HuggingFace API base (auth headers, GET helper) |
| `AbstractClientSideTool` | `AbstractClientSideTool.php` | Client-side Transformers.js tools (server validates params only) |

### Tool categories

| Category | Count | Examples |
|---|---|---|
| **External API / public data** | 14 | `WebSearchTool`, `GetGdacsEventsTool`, `GetOpenMeteoForecastTool`, `GeocodeAddressTool` |
| **HuggingFace Datasets** | 11 | `HuggingFaceDatasetSearchTool`, `HuggingFaceDatasetGetRowsTool` |
| **OpenAI API** | 4 | `GetModelInformationTool`, `ModerateContentTool`, `CreateTextEmbeddingsTool` |
| **Client-side AI** | 6 | `ClientAnalyzeSentimentTool`, `ClientTranslateTextTool` |
| **Content CRUD** | 6 | `GetPostTool`, `CreatePostTool`, `UpdatePostTool`, `DeletePostTool` |
| **Crawl/Research** | 3 | `RunCrawl4AiJobTool`, `DeepResearchTool` |
| **Admin/Skills** | 4 | `GetSiteSummaryTool`, `LoadSkillTool`, `ListSkillsTool` |

## Conventions

- One tool per file, one responsibility per tool.
- Every tool extends `AbstractTool` and injects only domain contracts via constructor.
- `execute()` returns `$this->success(...)` or calls `$this->errors->create(...)`.
- No WordPress functions, no `WP_Error`, no `get_post`, no `get_option`.

## Tests

```bash
vendor/bin/phpunit tests/test-tool-get-post.php
vendor/bin/phpunit tests/test-tool-web-search.php
```

## Also Load

- [`../Domain/Contract/ToolInterface.php`](../Domain/Contract/ToolInterface.php)
- [`../Domain/Contract/ContentStoreInterface.php`](../Domain/Contract/ContentStoreInterface.php)
- [`AbstractTool.php`](AbstractTool.php)
- [`docs/proposals/cross-platform-extraction-architecture.md`](../../../../docs/proposals/cross-platform-extraction-architecture.md)
