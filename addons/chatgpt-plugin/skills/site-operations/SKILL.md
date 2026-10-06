---
name: site-operations
description: Inspect and operate a WordPress site running NV oOS — site health, logs, users, media, and content queries. Read-first workflows for site operators and support staff.
---

Work against the connected `nvoos_site` MCP server. Answer questions with
read-only tools first: `get_site_health`, `get_system_logs_validated`,
`get_user_info`, `semantic_content_search`, `search_content_validated`,
`list_terms`, `list_taxonomies`, `search_attachments`.

Rules:

- Never call a destructive or write tool (create/update/delete/upload) without
  explicit user confirmation, including the exact IDs that will be affected.
- Quote post IDs, file paths, and error codes in your answers.
- When reporting logs, include severity and the time range inspected.
- If a tool call fails with 401/403, tell the user the connection needs to be
  re-linked or that the assistant's tool allowlist does not include the tool —
  do not retry repeatedly.
- Site-specific settings (rate limits, cost budgets, restriction registry)
  apply to every call; respect the errors they return.
