# CRM Quality

Address verification and outreach-quality tools.

## Purpose

Holds tools that decide whether a lead is reachable before it enters a
sequence. The first member is `verify_lead_email`, which infers a domain
address pattern from published addresses and verifies one candidate.

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Verify_Lead_Email` | `class-wp-mcp-ai-tool-verify-lead-email.php` | Pro tool registry |

## Neighbours

- `../class-wp-mcp-ai-crm-deliverability.php` — status lifecycle, pattern inference, verification, readiness.
- `../compliance/` — consent, DNC, sending-domain checks.
- `../class-wp-mcp-ai-crm-pre-send-gate.php` — consumes the address status.

## Also Load

- [`../README.md`](../README.md)
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md)
- [`../../../../.context/tool-registry.md`](../../../../.context/tool-registry.md)
