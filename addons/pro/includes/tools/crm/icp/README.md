# ICP (Ideal Customer Profile) Module

> Data-driven Ideal Customer Profile scoring engine for the NV oOS Pro CRM Toolkit.
> **Phase G deployed.** Profile management, 7-dimension scoring, fit+intent separation, behavioral decay, negative scoring, and tiered routing.

## Module map

| Module | File | Purpose |
|---|---|---|
| Profile data store | `class-wp-mcp-ai-icp-profile.php` | CRUD for ICP definitions (WordPress options) |
| Scoring engine | `class-wp-mcp-ai-icp-scorer.php` | 7-dimension 0-100 scoring with decay |
| Score computation tool | `class-wp-mcp-ai-tool-compute-icp-score.php` | MCP tool: compute ICP scores |
| Profile management tool | `class-wp-mcp-ai-tool-manage-icp-profile.php` | MCP tool: manage ICP profiles |
| Admin page | `../../admin/class-wp-mcp-ai-icp-admin-page.php` | Admin UI under NV CRM |

## The 7 Scoring Dimensions

| Dimension | Weight | Type | Decay |
|---|---|---|---|
| Firmographic Fit | 25% | Fit (stable) | None |
| Technographic Fit | 20% | Fit (stable) | Slow |
| Intent Signals | 15% | Intent (volatile) | Fast |
| Engagement Activity | 15% | Intent (volatile) | Fast |
| Buying Triggers | 10% | Intent (volatile) | Time-boxed |
| Economic Outcome | 10% | Fit (stable) | None |
| Negative Signals | 5% | Disqualifier | Always-on |

## Storage
ICP profiles stored in `wp_mcp_ai_icp_profiles` option (autoload=no).

## Also Load
- [`.context/conventions.md`](../../../../../.context/conventions.md)
- [`../README.md`](../README.md) — parent CRM toolkit index
