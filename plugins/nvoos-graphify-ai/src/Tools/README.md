# Tools

## Purpose

Houses every AI-powered tool implementation for the Graphify AI addon — 13 tools for text summarization, translation, sentiment analysis, entity extraction, Q&A, content generation, image analysis, categorization, semantic search, embeddings, and more.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-graphify-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosGraphifyAi\Plugin::registerAiTools()` via `nvoos_graphify/register_tools` action |
| **Optional dependencies** | `nvoos-graphify` (required — tools registered with core `ToolRegistry`) |

## Public Surface

All tools implement `NvoosGraphify\Contracts\Tool` and are registered with the core's `NvoosGraphify\ToolRegistry` — callers resolve by slug, never by class name.

| Symbol | File | Description |
|---|---|---|
| `NvoosGraphifyAi\Tools\AbstractAiTool` | `AbstractAiTool.php` | Base class for all AI tools |
| `NvoosGraphifyAi\Tools\SummarizeText` | `SummarizeText.php` | Summarize text via AI |
| `NvoosGraphifyAi\Tools\TranslateText` | `TranslateText.php` | Translate text between languages |
| `NvoosGraphifyAi\Tools\AnalyzeSentiment` | `AnalyzeSentiment.php` | Sentiment analysis |
| `NvoosGraphifyAi\Tools\ExtractEntities` | `ExtractEntities.php` | Named entity extraction |
| `NvoosGraphifyAi\Tools\QuestionAnswering` | `QuestionAnswering.php` | RAG-style question answering |
| `NvoosGraphifyAi\Tools\GenerateExcerpt` | `GenerateExcerpt.php` | AI-generated post excerpts |
| `NvoosGraphifyAi\Tools\GenerateImageAltText` | `GenerateImageAltText.php` | AI-generated alt text for images |
| `NvoosGraphifyAi\Tools\AnalyzeImage` | `AnalyzeImage.php` | Vision-model image analysis |
| `NvoosGraphifyAi\Tools\CategorizeContent` | `CategorizeContent.php` | Auto-categorize posts |
| `NvoosGraphifyAi\Tools\ContentRecommendation` | `ContentRecommendation.php` | Suggest related content |
| `NvoosGraphifyAi\Tools\ContentFreshness` | `ContentFreshness.php` | Assess content freshness/decay |
| `NvoosGraphifyAi\Tools\SemanticSearch` | `SemanticSearch.php` | Semantic/vector search |
| `NvoosGraphifyAi\Tools\CreateTextEmbeddings` | `CreateTextEmbeddings.php` | Generate text embeddings |

## Inputs / Outputs / Neighbors

- **Reads from:** Tool arguments (validated), AI provider APIs (via `ProviderRegistry`), WordPress posts/content
- **Writes to:** AI provider APIs, tool execution results
- **Upstream callers:** `NvoosGraphify\ToolRegistry` → REST controller, AI chat `ChatService` (tool-calling loop)
- **Downstream collaborators:** `src/Contracts/ProviderClient` (AI calls), `nvoos-graphify` core (`Contracts\Tool`, `ToolRegistry`, `Graph\Db`)
- **Events fired:** None (tools return results directly)
- **Events listened to:** None (called via registry)

## Conventions

- One tool per file — file name matches `{ToolName}.php`.
- Every tool implements `NvoosGraphify\Contracts\Tool` (from core).
- `AbstractAiTool` provides default `edit_posts` capability and `['read-only', 'external-api']` flags.
- Tools are registered via the `nvoos_graphify/register_tools` action.
- AI calls route through `NvoosGraphifyAi\ProviderRegistry` — tools never instantiate provider clients directly.

## Tests

```bash
vendor/bin/phpunit --filter '/Tools/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — sanitiser/escaper rules

## See Also

- Parent: [`../`](../) — src root
- Core interface: [`../../nvoos-graphify/src/Contracts/Tool.php`](../../nvoos-graphify/src/Contracts/Tool.php)
- Core tools: [`../../nvoos-graphify/src/Tools/`](../../nvoos-graphify/src/Tools/)
- Provider registry: [`../ProviderRegistry.php`](../ProviderRegistry.php)
