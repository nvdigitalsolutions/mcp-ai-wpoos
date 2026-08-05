# Proposal 017: SGI Transparency & AI Content Labelling Compliance (August 2026)

**Date:** 2026-08-05
**Status:** Draft
**Author:** AI-assisted compliance analysis (Zed)
**Based on:** Full plugin audit performed 2026-08-05 against `alpha-working`
**Implementation plan:** `017-sgi-transparency-compliance-implementation-plan.md` (companion)
**Regulatory references:** EU AI Act Article 50 · India IT Rules 2026 (SGI) · California SB 942 · New York S.8420 · TAKE IT DOWN Act

---

## 1. Executive Summary

Starting **August 2, 2026**, voluntary AI transparency ends. The EU AI Act Article 50, India's SGI amendment to IT Rules 2026, and several US state laws impose mandatory AI content labelling and transparency obligations with penalties up to **€15M or 3% of global turnover**.

A complete audit of the base plugin and Pro addon was performed on 2026-08-05. **The plugin currently has zero infrastructure for AI transparency compliance.** While the codebase has strong security architecture (ISO 27001 information labelling, security audit logger, CSP headers, encryption), none of these address the three pillars of SGI/Article 50 compliance:

| Requirement | EU AI Act Art. 50 | Plugin State |
|---|---|---|
| **Explicit Labelling** — AI disclosure badge, watermark, provenance headers | §50(1) + §50(2) | ❌ None |
| **Hard Consent** — Pre-interaction notification that user is chatting with AI | §50(1) | ❌ None |
| **Generation Logs** — Immutable, cryptographically verifiable provenance records | §50(2) + Code of Practice | ❌ None |

This proposal recommends building a new **Transparency Infrastructure** across three subsystems (labelling, consent, provenance) in three phases, with Phase 1 targeting the August 2 deadline.

### Regulatory Timeline

| Date | What Applies |
|---|---|
| **2 August 2026** | Article 50(1) — chatbot self-identification, §50(4) — visible labelling |
| **9 June 2026** | New York S.8420 — synthetic performer disclosure (already in force) |
| **2 December 2026** | Article 50(2) — machine-readable watermarking (grace period for legacy systems) |

**Recommendation:** Implement all three phases. Phase 1 immediately (visible disclosure + consent), Phase 2 within 30 days (provenance logging), Phase 3 by December 2026 (watermarking).

---

## 2. Regulatory Landscape

### 2.1 EU AI Act Article 50 (effective 2 August 2026)

**Article 50(1) — AI interaction disclosure:**
- Providers of chatbots, virtual assistants, and AI agents must design them so users are informed they are interacting with AI **at the time of first interaction**.
- Disclosure must be "clear and distinguishable" and conform to accessibility requirements.
- AI agents fall within scope; where the provider cannot predict whether the agent will interact with a human, it must disclose in every situation.

**Article 50(2) — Machine-readable marking:**
- Providers of generative AI systems must mark outputs in machine-readable format and ensure they are detectable as artificially generated.
- A standardised EU "AI" label is being developed through the Code of Practice.
- Does NOT apply where the system performs only assistive functions (grammar correction) or does not substantially alter input data.

**Article 50(4) — Deepfake and public-interest text labelling:**
- Deployers creating deepfakes must disclose the content is artificially generated.
- Deployers publishing AI-generated text on matters of public interest must disclose, unless subject to human review with editorial responsibility.

**Penalties:** Up to €15,000,000 or 3% of total worldwide annual turnover, whichever is higher.

### 2.2 India IT Rules 2026 — SGI (Synthetically Generated Information)

- All AI-generated content must be labelled.
- Users must disclose when they upload synthetic content.
- Platforms must take down illegal synthetic media within **3 hours**.
- Due diligence obligations include user awareness, labelling, and watermarking.

### 2.3 United States

**California SB 942 (effective 2 August 2026):**
- Covered providers (>1M monthly CA users) must offer free public AI detection tool with API.
- Latent disclosure: machine-readable provenance record in every output.
- Manifest disclosure: users must be able to apply visible, permanent labels.

**New York S.8420 (effective 9 June 2026):**
- Conspicuous disclosure for AI-generated digital replicas of human performers in advertisements.
- Personal liability for corporate directors/officers who fail to comply.

**TAKE IT DOWN Act (federal):**
- 48-hour takedown requirement for nonconsensual intimate imagery (real or synthetic).
- Criminal penalties for knowing publication with intent to harm.

---

## 3. Plugin Gap Analysis

### 3.1 What Exists (and why it's insufficient)

| Component | Current Purpose | AI Transparency Gap |
|---|---|---|
| `class-wp-mcp-ai-information-labelling.php` | ISO 27001 data classification (Public/Internal/Confidential/Restricted) | Data sensitivity, not AI content provenance |
| `class-wp-mcp-ai-privacy.php` | Standard WP privacy policy, data export/erasure | No AI-specific disclosure, no consent, no provenance |
| `class-wp-mcp-ai-security-audit-logger.php` | Records security events (failed caps, SSRF blocks, rate limits) | Security events only; not generation provenance records |
| `class-wp-mcp-ai-logger.php` | General debug/error logging | Mutable, not cryptographically verifiable |
| `class-wp-mcp-ai-chat-transcript-recorder.php` | Persists chat transcripts to JetEngine CCT | No provenance metadata, no content hashes, no machine-readable marking |
| `class-wp-mcp-ai-csp-headers.php` | Content-Security-Policy headers | Good security baseline; no AI-content headers |
| `assets/js/chat.js` | Frontend chat UI | No AI disclosure badge, no consent modal, no visual label |
| `class-wp-mcp-ai-admin-settings-base.php` | 250+ settings | **Zero transparency settings** |

### 3.2 Specific Deficiencies

1. **No visible AI disclosure** — Users are not informed they are interacting with AI (Article 50(1) violation).
2. **No consent mechanism** — No pre-interaction notification or acknowledgment.
3. **No provenance headers** — REST API responses carry no `X-AI-Generated` or similar headers.
4. **No content watermarking** — Generated files have no C2PA or machine-readable metadata.
5. **No immutable generation logs** — Transcripts exist but are mutable, unverifiable, and have no hash chain.
6. **No admin compliance controls** — Site operators cannot configure disclosure messages, consent requirements, or log retention.
7. **Privacy policy outdated** — Does not mention AI-generated content or labelling practices.

---

## 4. Proposed Architecture

### 4.1 Three-Layer Transparency Infrastructure

```
┌─────────────────────────────────────────────────┐
│                  User-Facing Layer                │
│  ┌──────────────┐  ┌──────────────┐              │
│  │ AI Disclosure │  │Consent Modal │              │
│  │   Banner      │  │ (pre-chat)   │              │
│  └──────┬───────┘  └──────┬───────┘              │
│         │                  │                      │
├─────────┼──────────────────┼──────────────────────┤
│         ▼                  ▼                      │
│  ┌──────────────────────────────────────────┐    │
│  │     Transparency Service (central)        │    │
│  │  • disclosure rendering                   │    │
│  │  • header injection                       │    │
│  │  • consent coordination                   │    │
│  └──────────────────────────────────────────┘    │
│                                                  │
│  ┌──────────────┐  ┌──────────────────────┐     │
│  │Consent Manager│  │Generation Provenance │     │
│  │  • record     │  │  • hash chain        │     │
│  │  • verify     │  │  • immutable log      │     │
│  │  • revoke     │  │  • admin viewer       │     │
│  └──────┬───────┘  └──────────┬───────────┘     │
│         │                      │                  │
├─────────┼──────────────────────┼──────────────────┤
│         ▼                      ▼                  │
│  ┌──────────────┐  ┌──────────────────────┐     │
│  │ Consent Log  │  │ Provenance DB Table   │     │
│  │ (security    │  │ wp_mcp_ai_gen_log     │     │
│  │  audit log)  │  │ (hash-chain integrity)│     │
│  └──────────────┘  └──────────────────────┘     │
│                  Storage Layer                    │
└─────────────────────────────────────────────────┘
```

### 4.2 New Files

| File | Purpose |
|---|---|
| `includes/class-wp-mcp-ai-transparency-service.php` | Central transparency controller — disclosure rendering, header injection, consent coordination |
| `includes/class-wp-mcp-ai-consent-manager.php` | Consent collection, recording, verification, revocation |
| `includes/class-wp-mcp-ai-generation-provenance.php` | Immutable generation provenance with hash-chain integrity |
| `includes/admin/class-wp-mcp-ai-admin-transparency-settings.php` | Admin transparency configuration page |
| `assets/css/chat-transparency.css` | AI disclosure and consent modal styling |

### 4.3 Modified Files

| File | Change |
|---|---|
| `includes/class-wp-mcp-ai-shortcode.php` | Inject AI disclosure banner into shortcode output |
| `includes/class-wp-mcp-ai-chat-bubble-frontend.php` | Add disclosure to bubble chat interface |
| `includes/class-wp-mcp-ai-rest.php` | Add transparency headers to REST responses |
| `includes/class-wp-mcp-ai-chat-transcript-recorder.php` | Hook provenance logging into transcript recording |
| `includes/class-wp-mcp-ai-privacy.php` | Update privacy policy content for AI transparency |
| `includes/admin/class-wp-mcp-ai-admin-settings-base.php` | New transparency settings defaults |
| `includes/bootstrap/loader.php` | Load new transparency classes |
| `assets/js/chat.js` | AI disclosure banner + consent modal rendering |

---

## 5. Phased Delivery

### Phase 1 — Visible Disclosure & Consent (target: Aug 2, 2026)

- AI disclosure banner in chat UI
- Pre-interaction consent modal
- `X-AI-Generated` and provenance headers on REST responses
- Admin settings for disclosure configuration
- Updated privacy policy content
- **Estimated effort:** ~3 days

### Phase 2 — Provenance Logging (target: Sep 2026)

- Custom `wp_mcp_ai_gen_log` database table
- Hash-chain integrity for all generation events
- Admin generation log viewer
- REST endpoint for compliance audit
- **Estimated effort:** ~5 days

### Phase 3 — Watermarking (target: Dec 2026)

- C2PA-compatible metadata embedding
- Machine-readable output marking
- Public detection/verification endpoint
- **Estimated effort:** ~5 days

---

## 6. Acceptance Criteria

1. Chat UI displays "AI Assistant" disclosure badge before any interaction — per Article 50(1).
2. First-time visitors see consent modal before sending first message.
3. All REST API chat responses include `X-AI-Generated: true` and `X-AI-Provider` headers.
4. Admin can configure disclosure message, consent requirement, and log retention.
5. Generation provenance records are written for every AI chat interaction.
6. Hash chain is verifiable — tampering with any record breaks the chain.
7. Privacy policy includes AI transparency sections.
8. All new code passes `composer run lint` and `composer run lint:compat` with zero errors.
