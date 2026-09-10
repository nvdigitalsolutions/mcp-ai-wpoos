# Legal & Launch Compliance Checklist — NV Digital Solutions

**Last updated:** September 10, 2026

Master checklist for selling NV oOS paid products as NV Digital Solutions
(US). Track document status, launch gates, and recurring obligations in one
place. The source of truth for every policy is `docs/legal/` in this repo.

---

## 1. Document Inventory

| Document | File | Status | Published URL |
|---|---|---|---|
| Terms of Service | [TERMS-OF-SERVICE.md](TERMS-OF-SERVICE.md) | ✅ Drafted (2026-09-09, updated 09-10) | `/terms-of-service` |
| Refund Policy | [REFUND-POLICY.md](REFUND-POLICY.md) | ✅ Drafted (2026-09-09) | `/refund-policy` |
| Acceptable Use Policy | [ACCEPTABLE-USE-POLICY.md](ACCEPTABLE-USE-POLICY.md) | ✅ Drafted (2026-09-10) | `/acceptable-use-policy` |
| Privacy Policy | [PRIVACY-POLICY.md](PRIVACY-POLICY.md) | ✅ Drafted (2026-09-10) | `/privacy-policy` |
| Clickwrap & consent recording | [CLICKWRAP-IMPLEMENTATION.md](CLICKWRAP-IMPLEMENTATION.md) | ✅ Guide ready (2026-09-10) | — (internal) |
| Warranty / Safe Use Notice | [`WARRANTY.md`](../../WARRANTY.md) | ✅ Live in repo, referenced by ToS | `/wpoos/warranty` |
| Plugin privacy guide (WP admin) | `includes/class-wp-mcp-ai-privacy.php` | ✅ Implemented | — (auto-renders in WP admin) |

---

## 2. Pre-Launch Gates (Must Complete Before First Sale)

### Legal review (attorney)
- [x] **Governing-law state filled in** — ToS §14.1 now specifies Florida.
- [x] **Business entity confirmed** — registered as a Florida LLC.
- [ ] Confirm B2B vs. B2C classification and whether **FDUTPA** (Florida
      Deceptive and Unfair Trade Practices Act) or other state consumer laws
      require changes to the ToS (esp. refund and limitation sections).
- [ ] Confirm the LLC's operating agreement is in place and that sales
      contracts are executed in the LLC's name (not personally).
- [ ] Review the limitation-of-liability and AI-disclosure language in the ToS
      against the insurer's policy wording (see §4).
- [ ] Confirm whether a GDPR **Data Processing Agreement** is needed for the
      checkout service (order data of EU customers). If sold as Stripe-only
      with no own-card-data handling, document that reasoning.
- [ ] Confirm PCI scope: card data must never touch NV Digital servers
      (Stripe Elements only).
- [ ] Verify the insurance carriers quote Florida filings (some cyber and
      tech E&O carriers restrict coverage or pricing in FL — see §4).

### Florida-specific compliance
- [ ] **FIPA breach notification** — under the Florida Information Protection
      Act, a breach affecting 500+ Florida residents requires notice to the
      Florida Department of Legal Affairs within **30 days**. Confirm the
      incident-response plan reflects this.
- [ ] **FDUTPA review** — Florida's consumer-protection statute applies to
      consumer transactions and allows fee-shifting; have counsel confirm
      the ToS disclaimer/limitation sections hold up for any B2C sales.
- [ ] **LLC formalities** — keep business funds, bank accounts, and contracts
      strictly separate from personal ones to preserve liability protection.

### Website & checkout
- [ ] All four policies published as pages at their canonical URLs.
- [ ] WooCommerce terms checkbox enabled → ToS page (Settings → Advanced).
- [ ] Checkbox label customized to name ToS + Refund + AUP + Privacy (§3.1 of
      [CLICKWRAP-IMPLEMENTATION.md](CLICKWRAP-IMPLEMENTATION.md)).
- [ ] Consent-recording mu-plugin installed (§3.2) and tested with a real order.
- [ ] Privacy policy page set (Settings → Accounts & Privacy).
- [ ] Footer links to all policies on every page.
- [ ] Checkout never asks for card data outside Stripe's elements.
- [ ] Refund process tested end-to-end (request → Stripe refund → license revocation).
- [ ] License-revocation behavior verified in the checkout API + plugin.

### Product surface
- [ ] Plugin privacy guide content (WordPress Privacy → Policy Guide) reviewed
      for accuracy against the customer-facing Privacy Policy.
- [ ] AI chat UI shows the AI notice/labeling (EU AI Act Art. 50 alignment)
      — already implemented; verify on a fresh install.
- [ ] `WARRANTY.md` linked from the plugin's readme and the sales page.
- [ ] Staging safety recommendation surfaced at first-run / docs (backups,
      least privilege) — exists in WARRANTY.md; link from sales page.

---

## 3. Recurring Compliance Calendar

| Cadence | Task |
|---|---|
| Every policy change | Bump version options (`nvds_terms_version`, etc.), publish dated copy, commit to `docs/legal/`, update this checklist |
| Annually | Re-review all policies against current law (FTC AI rules, state privacy laws, CCPA amendments) |
| Annually | Re-verify insurer's AI-exclusion posture at renewal (see §4) |
| On major feature release | Re-assess whether new capabilities (e.g., new destructive tools, new data flows) require policy updates |
| Quarterly | Confirm dated policy archives remain live and canonical URLs redirect correctly |

---

## 4. Insurance Cross-Reference (US)

Protection layers work together: **limited-liability terms + business entity +
E&O/cyber insurance.** Contract terms reduce what a plaintiff can win; the
entity protects personal assets; insurance pays defense costs regardless.

| Coverage | Recommendation | Notes |
|---|---|---|
| Tech E&O / Professional Liability | Required | ~$76–$111/mo typical for small software firms; ~$2,049/yr median at $1M limit |
| Cyber Liability | Required | ~$129–$145/mo small-business median; range $1,500–$15,000/yr by limit |
| BOP (GL + property) | Recommended | ~$400–$2,000/yr; often required by processors/landlords |
| Media/IP liability | Recommended (AI angle) | Endorsement or standalone; confirm AI-generated-content claims covered |

**Florida note:** FL is a hard-market state for some carriers — a few cyber
and tech E&O underwriters restrict limits, require higher deductibles, or
do not write Florida risks at all. Confirm the carrier has an approved FL
filing before relying on any quote.

**Critical underwriting question for every quote:** *"Does this policy
exclude claims arising from generative AI or AI software development?"*
Watch for ISO forms **CG 40 47 / CG 40 48** (2026 generative-AI exclusions).
Providers to compare: Embroker, Vouch, Insureon/TechInsurance (brokers);
Coalition, At-Bay, Cowbell (cyber-first carriers; Coalition bundles tech E&O).

---

## 5. Known Gaps & Placeholders

- [ ] Support/abuse email inboxes (`abuse@`, `privacy@`) must exist and be monitored.
- [ ] If subscriptions launch later: ToS §5 and Refund Policy §5.3 must be updated, and recurring-billing disclosures (FTC Negative Option Rule) added.
- [ ] If the checkout service ever stores customer content (not just order data), the Privacy Policy §3 "What We Do Not Collect" must be revisited and a DPA drafted.

---

## 6. References Used

- Clickwrap enforceability: Ironclad 6-component standard; Practical Law/Westlaw checklist (affirmative action, no pre-check, records).
- Limitation of liability: industry-standard cap of fees paid in prior 12 months; exclusion of indirect/consequential damages (TermsFeed, Koley Jessen, Galkin Law).
- AI terms: FTC clear-and-conspicuous disclosure standard; ISO CG 40 47/48 generative-AI exclusions; Sprintlaw/toslawyer AI ToS requirements.
- Privacy: CCPA/CPRA rights catalog; GDPR lawful-basis and rights catalog; controller/processor distinction (self-hosted product = customer is controller).
