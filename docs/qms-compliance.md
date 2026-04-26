# QMS Compliance — ISO 9001:2015 Clause 7.5

The Open Operator System Pro plugin includes an opt-in Quality Management
System (QMS) layer that adds ISO 9001:2015 Clause 7.5 ("Documented
Information") controls to the Document Generation Toolkit.

## Enabling

QMS is layered on top of the Document Generation Toolkit and is opt-in:

1. Enable the **Document Generation Toolkit** under *Settings → NV oOS → Toolkits*.
2. Enable **QMS / Compliance** in the same settings panel
   (`enable_qms_compliance = true`).

QMS is gated by the Pro feature flag `WP_MCP_AI_PRO_VERSION`. When disabled,
no QMS code paths execute and there is no overhead.

## Data model

| Component | Where it lives |
|----------|---------------|
| Controlled-document records | `mcp_ai_doc_record` CPT |
| Document type taxonomy | `mcp_ai_qms_doc_type` (Policy, Procedure, Work Instruction, Form, Record, External) |
| Audit trail | Custom table `{$wpdb->prefix}wp_mcp_ai_qms_audit` |
| Capability | `manage_qms` (granted to Editor and Administrator by default; filterable) |

### Per-record post meta

| Key | Purpose |
|-----|---------|
| `_qms_document_id` | Stable controlled-doc ID (e.g. `SOP-001`) |
| `_qms_revision` | Semantic revision (`1.0`, `1.1`, `2.0`) |
| `_qms_status` | `draft` / `in_review` / `approved` / `released` / `superseded` / `obsolete` |
| `_qms_owner_id`, `_qms_reviewer_ids`, `_qms_approver_ids` | Workflow participants |
| `_qms_effective_date`, `_qms_next_review_date` | Lifecycle dates |
| `_qms_retention_years`, `_qms_disposition` | Retention policy (`archive` or `destroy`) |
| `_qms_external_origin` | `{ source, identifier }` for clause 7.5.3 (b) |
| `_qms_change_reason`, `_qms_change_summary` | Per-revision change log |
| `_qms_signatures` | Array of e-signature records |
| `_qms_content_hash` | SHA-256 of the controlled content |
| `_qms_supersedes`, `_qms_superseded_by` | Revision pointers |

## State machine

```
   ┌────────┐ submit  ┌───────────┐ approve ┌──────────┐ release ┌──────────┐
──▶│ draft  │────────▶│ in_review │────────▶│ approved │────────▶│ released │
   └────────┘◀────────└───────────┘         └──────────┘         └──────────┘
        │                  │                     │                     │
        │ obsolete         │ obsolete            │ obsolete            │ supersede
        ▼                  ▼                     ▼                     ▼
   ┌──────────┐                                                 ┌─────────────┐
   │ obsolete │◀───────────────────────────────────────────────▶│ superseded  │
   └──────────┘                                                 └─────────────┘
```

Pre-conditions enforced by `WP_MCP_AI_QMS_Workflow::check_state_preconditions()`:

| Target state | Pre-condition |
|-------------|--------------|
| `in_review` | At least one reviewer assigned |
| `approved` | At least one approver assigned |
| `released` | An e-signature with intent `approved` exists (filterable) |

## Electronic signatures (21 CFR Part 11-friendly)

Signing requires the user's WordPress password (re-prompt). The signature is
SHA-256 hashed against the document content hash, binding the signature to the
exact content at sign time. Each signature row contains:

```
{ user_id, user_login, role, intent, timestamp, ip, content_hash, signature_hash }
```

`intent` is one of `reviewed`, `approved`, or `witnessed`.

## Audit trail

Every state transition, signature, release, and disposition writes a row to
`{$wpdb->prefix}wp_mcp_ai_qms_audit`. The same table is shared with PARA via
the `subsystem` column (`qms` or `para`). Rows include before/after content
hashes, IP, user agent, and arbitrary JSON metadata.

## Retention & disposition

A daily WP-Cron sweep (`wp_mcp_ai_qms_retention_sweep`) evaluates
`_qms_effective_date + _qms_retention_years`. When elapsed, the record
auto-transitions to `obsolete`. When PARA is enabled, obsolete records are
also moved to the Archives bucket via the QMS↔PARA bridge.

## Tools

| Tool | Capability flag | Purpose |
|------|----------------|---------|
| `qms_create_controlled_document` | `manage_qms` | Create a new draft record. |
| `qms_submit_for_review` | `manage_qms` | draft → in_review |
| `qms_approve_document` | `manage_qms` + assigned approver | in_review → approved |
| `qms_release_document` | `manage_qms` | approved → released |
| `qms_supersede_document` | `manage_qms` | released → superseded |
| `qms_mark_obsolete` | `manage_qms` | * → obsolete |
| `qms_sign_document` | `manage_qms` + role match | Apply e-signature |
| `qms_list_controlled_documents` | `manage_qms` | Master document register |
| `qms_get_audit_trail` | `manage_qms` | Read audit log |
| `qms_schedule_review` | `manage_qms` | Create PM task for periodic review |

## Hooks

### Actions

- `wp_mcp_ai_qms_before_state_transition( int $post_id, string $from, string $to, array $context )`
- `wp_mcp_ai_qms_after_state_transition( int $post_id, string $from, string $to, array $context )`
- `wp_mcp_ai_qms_document_signed( int $post_id, array $signature )`
- `wp_mcp_ai_qms_audit_logged( int $row_id, array $row )`
- `wp_mcp_ai_qms_review_due_for_record( int $post_id )`

### Filters

- `wp_mcp_ai_qms_capability_roles` — which roles get `manage_qms`.
- `wp_mcp_ai_qms_grant_to_admins` — auto-grant to `manage_options` users.
- `wp_mcp_ai_qms_require_release_signature` — gate release on e-signature.

## Cross-toolkit integration with PARA

When both subsystems are enabled, the `WP_MCP_AI_QMS_PARA_Bridge` ensures:

- Obsolete documents are auto-archived in PARA.
- Released documents linked to a PARA Area (`_qms_linked_area_id` meta)
  refresh that Area's `_para_last_reviewed` timestamp.
- The audit log is shared (`subsystem` column).
