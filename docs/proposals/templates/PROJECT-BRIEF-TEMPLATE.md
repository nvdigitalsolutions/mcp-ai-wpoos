# [Feature Name] — Project Brief

**Date:** YYYY-MM-DD
**Phase:** 1 — Discovery
**Author:** [Name / Agent: nv-oos-analyst]
**Status:** Draft / Approved / Rejected
**Proposal File:** `docs/proposals/[FEATURE]-PROJECT-BRIEF.md`

---

## Problem Statement

> What problem does this solve? Who experiences it? What is the current workaround?

[Description of the problem]

---

## Target Users

- **Site administrators** who need to [action]
- **Developers** who need to [action]
- **End users** who want to [action]

---

## WordPress Ecosystem Context

### Related Plugins/Solutions
| Solution | Approach | Limitation |
|---------|---------|-----------|
| [Plugin Name] | [How it handles this] | [Why NV oOS should implement it differently] |

### WordPress Core Features Leveraged
- [List WordPress APIs, hooks, or features that will be used]

### NV oOS Components Affected
- [ ] Tool registry (new tools needed)
- [ ] REST API (new endpoints needed)
- [ ] Chat UI (frontend changes needed)
- [ ] Admin settings (new settings needed)
- [ ] Database schema (CPT/CCT/options changes needed)
- [ ] External API integration

---

## Feasibility Assessment

| Dimension | Assessment | Notes |
|-----------|-----------|-------|
| Technical complexity | Low / Medium / High | [Explanation] |
| Security considerations | [List security implications] | |
| Third-party dependencies | [List external APIs or plugins needed] | |
| Base vs Pro placement | Base / Pro | [Rationale] |
| Estimated stories | [Number] | [Rough breakdown] |

---

## Security Implications

- [ ] Handles user credentials or API keys: [Yes/No — if Yes, describe encryption strategy]
- [ ] Accesses external services: [Yes/No — if Yes, list services]
- [ ] Processes user-uploaded content: [Yes/No — if Yes, describe validation]
- [ ] Exposes new REST endpoints: [Yes/No — if Yes, describe auth model]
- [ ] Requires new capabilities: [Yes/No — if Yes, list capabilities]

---

## Competitive Alternatives

| Alternative | Approach | Why NV oOS Is Different |
|------------|---------|------------------------|
| [Name] | [Description] | [Differentiation] |

---

## Recommendations

**Proceed to PRD:** Yes / No

**Key risks:**
1. [Risk 1]
2. [Risk 2]

**Key assumptions:**
1. [Assumption 1]
2. [Assumption 2]

---

## Analyst Sign-off Checklist

- [ ] Problem statement is clear and specific
- [ ] Target users identified with concrete use cases
- [ ] WordPress ecosystem context researched
- [ ] Feasibility assessment complete (complexity, security, dependencies)
- [ ] Base vs Pro placement recommended with rationale
- [ ] Security implications enumerated
- [ ] Recommendation to proceed is stated
- [ ] All factual claims verified via `verify_information`

---

*Next step: If approved, the Product Manager (nv-oos-product-manager) creates the PRD using `docs/proposals/templates/PRD-TEMPLATE.md`.*
