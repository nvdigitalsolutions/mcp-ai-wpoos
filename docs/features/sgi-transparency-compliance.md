# SGI Transparency & Compliance

**Version:** 1.1.45+
**Category:** Base feature (infrastructure) + Pro (UI components)
**Proposal:** [017-sgi-transparency-compliance](../project/proposals/017-sgi-transparency-compliance.md)

## Overview

The SGI (Safe Generative Intelligence) Transparency & Compliance system provides infrastructure for AI transparency, traceability, and responsible AI disclosure. It implements the SGI framework for documenting AI-generated content, model usage, and decision provenance.

## Components

### AI Transparency Infrastructure (Base)

- **Chat Transparency CSS** (`assets/css/chat-transparency.css`) — visual indicators for AI-generated content
- **Transparency Metadata Hooks** — filters for injecting model/provider info into AI responses
- **Content Provenance** — tracking which model, provider, and timestamp generated each response

### Chat Transparency UI (Pro)

- Transparency badges on AI-generated messages showing model name
- Provider disclosure in chat footer
- "How was this generated?" expandable details
- Token usage and cost transparency

### SGI Compliance Framework

The SGI framework aligns with emerging AI regulation requirements:

| Principle | Implementation |
|---|---|
| **Disclosure** | All AI-generated content labeled with model + provider |
| **Traceability** | Audit logs link responses to model versions and timestamps |
| **Consent** | Guest token + consent gate on public chat surfaces |
| **Safety** | Necessity Gate (Layer J) scores destructive operations |
| **Privacy** | API keys encrypted at rest; PHI auto-redaction for healthcare |
| **Accountability** | Security posture dashboard with 21 signals A-F grading |

## Configuration

### Enable Transparency Features

```php
// Enable AI transparency badges in chat
define( 'WP_MCP_AI_TRANSPARENCY_ENABLED', true );

// Show detailed model info in transparency panel
define( 'WP_MCP_AI_TRANSPARENCY_DETAILED', true );

// Log all AI decisions for audit
define( 'WP_MCP_AI_TRANSPARENCY_AUDIT_LOG', true );
```

### Filters

```php
// Modify the transparency badge content
add_filter( 'wp_mcp_ai_transparency_badge', function( $badge, $response ) {
    $badge['model'] = $response['model'];
    $badge['provider'] = $response['provider'];
    return $badge;
}, 10, 2 );

// Add custom compliance metadata
add_filter( 'wp_mcp_ai_transparency_metadata', function( $meta ) {
    $meta['compliance_framework'] = 'SGI-v1';
    return $meta;
} );
```

## Audit Trail

All AI operations are logged to the security audit trail with:
- Timestamp
- Model name and version
- Provider
- User/session context
- Tool/task performed
- Input token count
- Output token count
- Cost estimate

Access via **Tools → Security → Audit Log**.

## Related

- [Proposal 017: SGI Transparency Compliance](../project/proposals/017-sgi-transparency-compliance.md)
- [Implementation Plan](../project/proposals/017-sgi-transparency-compliance-implementation-plan.md)
- [Security Posture](../operations/security/SECURITY_POSTURE.md)
- [Compliance Traceability](../operations/compliance/TRACEABILITY.md)
- [Security Audit 2026-04](../operations/compliance/SECURITY_AUDIT_2026_04.md)
