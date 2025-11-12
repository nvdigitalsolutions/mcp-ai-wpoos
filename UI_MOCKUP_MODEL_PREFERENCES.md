# Token Manager UI - Model Preferences Column

## Before Change

```
Token Limits by Tool Table
+------------------+-------------+------------+------------------+-------------+
| Tool Name        | Tool Slug   | Multiplier | Effective Limits | Total Users |
+------------------+-------------+------------+------------------+-------------+
| Crawl4AI Scraper | run_crawl.. | [2.0]×     | F: 100,000       | 5           |
|                  |             |            | P: 400,000       |             |
|                  |             |            | E: 2,000,000     |             |
+------------------+-------------+------------+------------------+-------------+
| Search Content   | search_c... | [1.5]×     | F: 75,000        | 12          |
|                  |             |            | P: 300,000       |             |
|                  |             |            | E: 1,500,000     |             |
+------------------+-------------+------------+------------------+-------------+
```

## After Change

```
Token Limits by Tool Table
+------------------+-------------+-----------------------------+------------+------------------+
| Tool Name        | Tool Slug   | Preferred Model             | Multiplier | Effective Limits |
+------------------+-------------+-----------------------------+------------+------------------+
| Crawl4AI Scraper | run_crawl.. | [▼ GPT-4o Mini           ] | [2.0]×     | F: 100,000       |
|                  |             |                             |            | P: 400,000       |
|                  |             |                             |            | E: 2,000,000     |
+------------------+-------------+-----------------------------+------------+------------------+
| Search Content   | search_c... | [▼ Claude 3.5 Sonnet     ] | [1.5]×     | F: 75,000        |
|                  |             |                             |            | P: 300,000       |
|                  |             |                             |            | E: 1,500,000     |
+------------------+-------------+-----------------------------+------------+------------------+
| General Tools    | general_... | [▼ Default               ] | [1.0]×     | F: 50,000        |
|                  |             |                             |            | P: 200,000       |
|                  |             |                             |            | E: 1,000,000     |
+------------------+-------------+-----------------------------+------------+------------------+
```

## Model Selection Dropdown Example

When clicking a model dropdown, the user sees:

```
┌─────────────────────────────────────────┐
│ Default (use assistant/global setting)  │ ← Selected by default
├─────────────────────────────────────────┤
│ OpenAI                                  │ ← Group header
│   GPT-4o                                │
│   GPT-4o Mini                           │ ← Could be selected
│   GPT-4 Turbo                           │
│   GPT-4                                 │
│   GPT-3.5 Turbo                         │
│   o1 Preview                            │
│   o1 Mini                               │
├─────────────────────────────────────────┤
│ Anthropic (Claude)                      │ ← Group header
│   Claude 3.5 Sonnet                     │
│   Claude 3.5 Haiku                      │
│   Claude 3 Opus                         │
├─────────────────────────────────────────┤
│ Google Gemini                           │ ← Group header
│   Gemini 2.0 Flash (Experimental)       │
│   Gemini 1.5 Pro                        │
│   Gemini 1.5 Flash                      │
├─────────────────────────────────────────┤
│ Ollama (Local)                          │ ← Only if configured
│   llama2                                │
└─────────────────────────────────────────┘
```

## Full Table Layout (with all columns)

```
+------------+----------+------------------+--------+-----------+----------+---------+----------+----------+
| Tool Name  | Slug     | Preferred Model  | Multi. | Effective | Users    | Requests| Tokens  | Usage %  |
+------------+----------+------------------+--------+-----------+----------+---------+----------+----------+
| Crawl4AI   | run_c... | [GPT-4o Mini  ▼] | 2.0×   | F: 100k   | 5        | 1,234   | 456,789 | ████ 45% |
|            |          |                  |        | P: 400k   |          |         |         |          |
|            |          |                  |        | E: 2M     |          |         |         |          |
+------------+----------+------------------+--------+-----------+----------+---------+----------+----------+
| Search     | search.. | [Claude 3.5 S ▼] | 1.5×   | F: 75k    | 12       | 5,678   | 234,567 | ██   23% |
|            |          |                  |        | P: 300k   |          |         |         |          |
|            |          |                  |        | E: 1.5M   |          |         |         |          |
+------------+----------+------------------+--------+-----------+----------+---------+----------+----------+
```

## Key UI Features

1. **Model Dropdown**: 
   - Full width select (max-width: 250px)
   - Grouped by provider (OpenAI, Anthropic, Gemini, Ollama, LM Studio)
   - "Default" option always available at top
   - Only shows providers with configured API keys

2. **Column Position**: 
   - Placed to the LEFT of "Multiplier" column (as requested)
   - Width: 15% of table width
   - Contains tooltip icon (ℹ) explaining the feature

3. **Save Behavior**:
   - Changes saved when clicking "Save All Tool Settings" button
   - AJAX submission with real-time feedback
   - Page reload on successful save to show updated values

4. **Validation**:
   - Only valid model IDs accepted
   - "default" is always valid
   - Changes detected before save (prevents unnecessary DB writes)

## Column Width Distribution

| Column           | Width | Description                           |
|------------------|-------|---------------------------------------|
| Tool Name        | 18%   | Display name of the tool              |
| Tool Slug        | 12%   | Technical identifier                  |
| Preferred Model  | 15%   | NEW - Model selection dropdown        |
| Multiplier       | 8%    | Token limit multiplier input          |
| Effective Limits | 12%   | Calculated limits by tier             |
| Total Users      | 8%    | Number of users using this tool       |
| Total Requests   | 8%    | Total API requests made               |
| Tokens Used      | 9%    | Total tokens consumed                 |
| Usage %          | 10%   | Visual usage percentage bar           |

Total: 100%
