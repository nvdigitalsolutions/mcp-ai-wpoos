# Tools

## Purpose

Houses every AI-powered tool implementation for the Content Graph AI addon — 13 tools for text summarization, translation, sentiment analysis, entity extraction, Q&A, content generation, image analysis, categorization, semantic search, embeddings, and more.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAi\Plugin::registerAiTools()` via `nvoos_content_graph/register_tools` action |
| **Optional dependencies** | `nvoos-content-graph` (required — tools registered with core `ToolRegistry`) |

## Public Surface

All tools implement `NvoosContentGraph\Contracts\Tool` and are registered with the core's `NvoosContentGraph\ToolRegistry` — callers resolve by slug, never by class name.

| Symbol | File | Description |
|---|---|---|
| `NvoosContentGraphAi\Tools\AbstractAiTool` | `AbstractAiTool.php` | Base class for all AI tools |
| `NvoosContentGraphAi\Tools\SummarizeText` | `SummarizeText.php` | Summarize text via AI |
| `NvoosContentGraphAi\Tools\TranslateText` | `TranslateText.php` | Translate text between languages |
| `NvoosContentGraphAi\Tools\AnalyzeSentiment` | `AnalyzeSentiment.php` | Sentiment analysis |
| `NvoosContentGraphAi\Tools\ExtractEntities` | `ExtractEntities.php` | Named entity extraction |
| `NvoosContentGraphAi\Tools\QuestionAnswering` | `QuestionAnswering.php` | RAG-style question answering |
| `NvoosContentGraphAi\Tools\GenerateExcerpt` | `GenerateExcerpt.php` | AI-generated post excerpts |
| `NvoosContentGraphAi\Tools\GenerateImageAltText` | `GenerateImageAltText.php` | AI-generated alt text for images |
| `NvoosContentGraphAi\Tools\AnalyzeImage` | `AnalyzeImage.php` | Vision-model image analysis |
| `NvoosContentGraphAi\Tools\CategorizeContent` | `CategorizeContent.php` | Auto-categorize posts |
| `NvoosContentGraphAi\Tools\ContentRecommendation` | `ContentRecommendation.php` | Suggest related content |
| `NvoosContentGraphAi\Tools\ContentFreshness` | `ContentFreshness.php` | Assess content freshness/decay |
| `NvoosContentGraphAi\Tools\SemanticSearch` | `SemanticSearch.php` | Semantic/vector search |
| `NvoosContentGraphAi\Tools\CreateTextEmbeddings` | `CreateTextEmbeddings.php` | Generate text embeddings |

## Inputs / Outputs / Neighbors

- **Reads from:** Tool arguments (validated), AI provider APIs (via `ProviderRegistry`), WordPress posts/content
- **Writes to:** AI provider APIs, tool execution results
- **Upstream callers:** `NvoosContentGraph\ToolRegistry` → REST controller, AI chat `ChatService` (tool-calling loop)
- **Downstream collaborators:** `src/Contracts/ProviderClient` (AI calls), `nvoos-content-graph` core (`Contracts\Tool`, `ToolRegistry`, `Graph\Db`)
- **Events fired:** None (tools return results directly)
- **Events listened to:** None (called via registry)

## Conventions

- One tool per file — file name matches `{ToolName}.php`.
- Every tool implements `NvoosContentGraph\Contracts\Tool` (from core).
- `AbstractAiTool` provides default `edit_posts` capability and `['read-only', 'external-api']` flags.
- Tools are registered via the `nvoos_content_graph/register_tools` action.
- AI calls route through `NvoosContentGraphAi\ProviderRegistry` — tools never instantiate provider clients directly.

## Tests

```bash
vendor/bin/phpunit --filter '/Tools/'
```

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — sanitiser/escaper rules

## See Also

- Parent: [`../`](../) — src root
- Core interface: [`../../../nvoos-content-graph/src/Contracts/Tool.php`](../../../nvoos-content-graph/src/Contracts/Tool.php)
- Core tools: [`../../../nvoos-content-graph/src/Tools/`](../../../nvoos-content-graph/src/Tools/)
- Core engine bridge: [`../CoreBridge.php`](../CoreBridge.php)
