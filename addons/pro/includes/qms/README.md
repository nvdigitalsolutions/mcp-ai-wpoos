# QMS

## Purpose

Implements ISO 9001:2015 Clause 7.5 ("Documented Information") on top of the Document Generation toolkit — registers the `manage_qms` capability, the `mcp_ai_doc_record` controlled-document CPT, classification taxonomies, the draft → in_review → approved → released → superseded/obsolete state-machine workflow, retention/disposition rules, an immutable audit log, and a bridge that ties controlled-document lifecycle events to PARA Area review timestamps.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/mcp-ai-wpoos-pro.php` → `wp_mcp_ai_pro_init()` `require_once`s `qms/class-wp-mcp-ai-qms-init.php`, which loads the seven classes and calls each one's `::init()`. **Feature-flagged**: `WP_MCP_AI_QMS_Capabilities::is_enabled()` requires Pro mode, the Document Generation toolkit to be enabled (`enable_document_generation_toolkit`), **and** the `enable_qms_compliance` setting to be on. Per the v1.1.13 CHANGELOG note, QMS is "feature-flagged and pending a separate review window" — leave it off by default. |
| **Optional dependencies** | PARA ([`../para/`](../para/)) — the `WP_MCP_AI_QMS_PARA_Bridge` is a no-op when the PARA Area CPT is not loaded. Document Generation toolkit (templates) is the upstream content source for controlled documents. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_QMS_Capabilities` (`CAP = manage_qms`, `::init()`, `::is_enabled()`) | `class-wp-mcp-ai-qms-capabilities.php` | All QMS tools, REST controllers, admin pages, the other classes in this folder |
| `WP_MCP_AI_QMS_Audit_Log` (`::init()`, append/query helpers) | `class-wp-mcp-ai-qms-audit-log.php` | `Workflow`, retention, tools |
| `WP_MCP_AI_QMS_Taxonomy` (`::init()`) | `class-wp-mcp-ai-qms-taxonomy.php` | `Doc_Record_CPT`, tools |
| `WP_MCP_AI_QMS_Doc_Record_CPT` (`POST_TYPE`, `STATUS_DRAFT`/`STATUS_IN_REVIEW`/`STATUS_APPROVED`/`STATUS_RELEASED`/`STATUS_SUPERSEDED`/`STATUS_OBSOLETE` constants, `::init()`) | `class-wp-mcp-ai-qms-doc-record-cpt.php` | `Workflow`, `Retention`, tools, tests |
| `WP_MCP_AI_QMS_Workflow` (`::init()`, `::allowed_transitions()`, `::transition()`) | `class-wp-mcp-ai-qms-workflow.php` | QMS tools, tests |
| `WP_MCP_AI_QMS_Retention` (`::init()`) | `class-wp-mcp-ai-qms-retention.php` | Scheduled retention sweep; consults disposition meta |
| `WP_MCP_AI_QMS_PARA_Bridge` (`::init()`) | `class-wp-mcp-ai-qms-para-bridge.php` | Listens to workflow transitions; updates `_para_last_reviewed` on linked PARA Areas |

The full per-record meta-key contract is documented inline at the top of [`class-wp-mcp-ai-qms-doc-record-cpt.php`](class-wp-mcp-ai-qms-doc-record-cpt.php) (document_id, revision, status, owner/reviewer/approver IDs, dates, retention, signatures, content_hash, supersedes chain) — those keys form a stable wire contract for audit consumers.

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_document_generation_toolkit`, `enable_qms_compliance`); the `wp_mcp_ai_qms_capability_roles` filter; controlled-document meta; PARA Area posts (via the bridge).
- **Writes to:** the `mcp_ai_doc_record` CPT and its `_qms_*` meta, the audit log store, QMS taxonomy term relationships, and `_para_last_reviewed` on linked PARA Areas. Capability changes are persisted to roles via `add_cap()` on `init`.
- **Upstream callers:** [`../mcp-ai-wpoos-pro.php`](../../mcp-ai-wpoos-pro.php), QMS tools under [`../tools/document-generation/`](../tools/document-generation/), document-generation REST controllers.
- **Downstream collaborators:** WordPress core CPT/taxonomy APIs, the PARA Area CPT when present.
- **Events fired:** before/after state-transition action hooks emitted by `WP_MCP_AI_QMS_Workflow::transition()` (every transition is also written to the audit log).
- **Events listened to:** `init` (capability + CPT + taxonomy registration at priority 30+), `user_has_cap` (grant `manage_qms` to admins), workflow-transition hooks (audit log + PARA bridge), retention cron hooks.

## Conventions

- **Feature flag is mandatory.** Every entry point gates on `WP_MCP_AI_QMS_Capabilities::is_enabled()`. Never register the CPT, taxonomy, or capability unconditionally — this subsystem ships disabled.
- **Workflow transitions are the only mutation path for `_qms_status`.** Direct meta writes bypass the audit log and break Clause 7.5 traceability. Tools must call `WP_MCP_AI_QMS_Workflow::transition()`.
- **Allowed transitions are an enum, not free-text.** Add new states by editing the `STATUS_*` constants and `allowed_transitions()` together; never introduce ad-hoc status strings.
- **Capability gating uses `WP_MCP_AI_QMS_Capabilities::CAP`.** Every privileged action must check that capability via the standard WordPress capability API — never invent ad-hoc role checks. (Mechanics: [`.context/security-checklist.md`](../../../../.context/security-checklist.md).)
- **Audit-log records are append-only.** Treat the audit-log writer as immutable infrastructure; never offer an "edit" path.
- This folder is the registration + state-machine + audit core. Tool `execute()` bodies live under [`../tools/document-generation/`](../tools/document-generation/).

## Tests

```bash
vendor/bin/phpunit tests/qms/
```

Existing suites: [`tests/qms/test-qms-workflow.php`](../../../../tests/qms/test-qms-workflow.php) (allowed-transition matrix, terminal states), [`tests/qms/test-qms-audit-log.php`](../../../../tests/qms/test-qms-audit-log.php) (append + query semantics). Retention sweep and PARA bridge coverage is light — those paths are exercised through manual QA per the v1.1.13 review window.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — capability gating + nonce rules
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro-only feature gating
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — canonical envelope used by QMS tools
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat policy, two-gate sanitisation rule

## See Also

- Sibling integration: [`../para/`](../para/) — the PARA bridge updates `_para_last_reviewed` on Area posts when documents are released
- QMS tools: [`../tools/document-generation/`](../tools/document-generation/)
- Toolkit bootstrap: [`../document-generation-toolkit-init.php`](../document-generation-toolkit-init.php)
- Boot wiring: [`../../mcp-ai-wpoos-pro.php`](../../mcp-ai-wpoos-pro.php) (`wp_mcp_ai_pro_init()`)
- Standards reference: ISO 9001:2015 §7.5 Documented Information
