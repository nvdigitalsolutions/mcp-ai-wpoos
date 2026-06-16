# Wellness

## Purpose

Houses 6 Phase C cross-cutting Health & Wellness tools: member allergy checks, health timeline retrieval, prescription-to-record linking, prescription interaction verification (RxNorm-aligned), visit summary generation, and duplicate member merging.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Unified healthcare bootstrap via Pro tool registry |
| **Optional dependencies** | `enable_health_wellness_management` setting must be enabled |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Check_Member_Allergies` | `class-wp-mcp-ai-tool-check-member-allergies.php` | tool registry |
| `WP_MCP_AI_Tool_Get_Health_Timeline` | `class-wp-mcp-ai-tool-get-health-timeline.php` | tool registry |
| `WP_MCP_AI_Tool_Link_Prescription_To_Record` | `class-wp-mcp-ai-tool-link-prescription-to-record.php` | tool registry |
| `WP_MCP_AI_Tool_Verify_Prescription_Interactions` | `class-wp-mcp-ai-tool-verify-prescription-interactions.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Visit_Summary` | `class-wp-mcp-ai-tool-generate-visit-summary.php` | tool registry |
| `WP_MCP_AI_Tool_Merge_Duplicate_Members` | `class-wp-mcp-ai-tool-merge-duplicate-members.php` | tool registry |
| `WP_MCP_AI_Tool_Get_Recent_Health_Appointments` | `class-wp-mcp-ai-tool-get-recent-health-appointments.php` | tool registry |
| `WP_MCP_AI_Tool_Send_Appointment_Followup` | `class-wp-mcp-ai-tool-send-appointment-followup.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_health_wellness_management`), `mcp_ai_allergy`, `mcp_ai_prescription`, `mcp_ai_med_record`, `mcp_ai_member` CPTs
- **Writes to:** member CPTs (merge), audit ledger
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Healthcare_Audit`, `WP_MCP_AI_Healthcare_Engine`
- **Events fired:** `wp_mcp_ai_healthcare_after_merge_members`, `wp_mcp_ai_healthcare_before_phi_access`, `wp_mcp_ai_healthcare_after_phi_access`
- **Events listened to:** `wp_mcp_ai_healthcare_interaction_pairs`, `wp_mcp_ai_healthcare_rxnorm_lookup`, `wp_mcp_ai_healthcare_member_child_meta_map`

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Drug interaction verification uses an RxNorm-aligned offline registry, extensible via `wp_mcp_ai_healthcare_rxnorm_lookup`.
- `is_available()` gates on the `enable_health_wellness_management` setting.
- All read/write operations on PHI data are audited via `WP_MCP_AI_Healthcare_Audit`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/healthcare/wellness/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Healthcare toolkit
