# NV oOS Complete — Checkout Enhancement & Compliance Plan

**Scope:** the purchase modal only (`content-graph-commerce.js` + its PHP
config in `src/Admin/SettingsPage.php`). The vendor-side storefront
settings page (`addons/checkout-api`) is intentionally out of scope for UI
changes — everything below can ship without touching it.

**Audience:** first-time digital-product seller. The plan separates
(1) launch-critical compliance, (2) modal conversion & trust enhancements,
(3) post-purchase excitement — each mapped to the exact files involved.

> **Implementation status (2026-09-10):** Phases 1–3 are implemented
> (price block, trust list, "what's included" block, roadmap line, EU
> withdrawal consent note, success-screen checklist + support line) in
> `content-graph-commerce.js`, `SettingsPage.php`, `Payments.php`,
> `Schema.php`, and `content-graph-admin.css`. **License scope decision
> applied: 1 year of updates + email support** — reflected in the modal
> copy and in `docs/legal/TERMS-OF-SERVICE.md` §7.1 (must be republished
> at nvdigitalsolutions.com before launch). Phase 4 (Stripe Tax, welcome
> emails) remains an owner action. §2 owner checklist remains
> launch-critical.

---

## 1. Research findings — what the industry actually does

Sourced from Freemius (the de-facto standard for WP plugin commerce),
Stripe's own documentation, EU consumer-law references, and checkout
CRO/trust-signal research. Full sources in §7.

| Topic | Finding | Consequence for us |
|---|---|---|
| **Licensing clarity** | Freemius's selling guide: buyers expect the license scope to be explicit — what updates, how many sites, what support, for how long ([freemius.com](https://freemius.com/selling-wordpress-plugins-guide/)) | The modal must state in one line what the $49 buys: "One-time payment — includes updates for the 1.x series + email support" (exact terms are the owner's decision, §2.1) |
| **Lifetime vs renewal** | Lifetime licenses are easy to sell but hard to sustain; data shows lifetime buyers don't cost more to support, but vendors who stay healthy renew on *visible value* ([wpproducttalk.com](https://wpproducttalk.com/blog/lifetime-license-support/), [benryan.com.au](https://benryan.com.au/blog/wordpress-plugin-subscription-vs-lifetime)) | A one-time price is a fine launch model. State it plainly; plan renewals around a future *v2 major release*, not now |
| **Trust signals** | The #1 checkout trust signal for digital goods is a *specific, benefit-framed* money-back guarantee ("Try it risk-free for 30 days, love it or full refund") — not a generic badge ([rework.com](https://resources.rework.com/libraries/ecommerce-growth/trust-signals-social-proof), [Mailchimp](https://mailchimp.com/resources/trust-signals/)) | We already have a 30-day guarantee in the consent text — surface it prominently near the price and re-frame it as a benefit |
| **Security note** | "Card data never touches this server / processed by Stripe" is a proven checkout reassurance ([Stripe](https://docs.stripe.com/payments/elements)) | Already present (`secure_note`). Keep it; add the guarantee + instant delivery next to it |
| **Tax disclosure** | EU digital services are VAT-charged in the buyer's country (OSS); the price shown must be clear about tax, and Stripe Tax can compute/collect it ([stripe.com/tax](https://stripe.com/tax), [docs.stripe.com/tax/checkout](https://docs.stripe.com/tax/checkout)) | Add "VAT may be added at checkout based on your country" under the price; enable **Stripe Tax** (automatic tax calculation) in the vendor Stripe dashboard |
| **EU 14-day withdrawal** | For digital content, the buyer's EU right of withdrawal is lost only if they *expressly consent to immediate delivery* **and** *acknowledge losing the right* when performance begins ([europa.eu](https://europa.eu/youreurope/citizens/consumers/shopping/returns/index_en.htm), [eccnet.eu](https://www.eccnet.eu/consumer-rights/what-are-my-consumer-rights/shopping-rights/cooling-period)) | Add one consent sentence covering immediate delivery + withdrawal acknowledgment. The money-back guarantee remains on top of it (exactly how Meta words it ([meta.com](https://www.meta.com/legal/quest/eu-consumer-right-of-withdrawal-information/))) |
| **Stripe seller duties** | Stripe requires verified, accurate business information and a complete receipt for every transaction; sellers must offer support contact info ([support.stripe.com](https://support.stripe.com/questions/business-information-requirements-to-use-stripe), [Stripe Services Agreement](https://stripe.com/legal/ssa-services-terms)) | We already send `receipt_email` — confirm "email receipts for customers" is ON in the Stripe dashboard, business details verified, and a support address exists in public business info |
| **Post-purchase onboarding** | A structured welcome sequence in the first days after purchase measurably reduces refunds and support load ([Salesforce](https://www.salesforce.com/commerce/post-purchase-experience/), [MoEngage](https://www.moengage.com/blog/post-purchase-customer-experience/)) | Upgrade the success screen to a "what happens next" checklist and point buyers at release notes; add a welcome email later (§5) |
| **Dark patterns are illegal** | Fake scarcity/countdowns, invented "was $99" anchors, and misleading "limited time" claims breach the EU Unfair Commercial Practices Directive and FTC rules; misleading omissions count too | §6 — hard rules for the modal copy |

---

## 2. Launch-critical compliance checklist (do before going live)

These are owner actions, not code — but the modal must match them.

1. **Publish real policy pages.** The consent links point at
   `https://nvdigitalsolutions.com/terms-of-service` and
   `/refund-policy` (client defaults in `Payments::termsUrl()` /
   `refundPolicyUrl()`, vendor defaults in the checkout addon). Both pages
   must exist, and the refund page must actually say what the consent
   checkbox promises: **"30-day money-back guarantee."** If the real policy
   is narrower, change the consent copy — never the other way round.
2. **Decide and document the license scope** (one line, shown in the modal):
   - Sites covered (1 site? unlimited personal?)
   - Updates window ("updates for the 1.x series", "1 year of updates", …)
   - Support channel and response expectation
   This is the #1 source of "wrong idea" refunds. Put it in the Terms page
   *and* mirror it in the modal's "What you get" block.
3. **Stripe dashboard:** business verification complete (legal name, address,
   website, support email), **"Email customers for successful payments" ON**
   (the modal already passes `receipt_email`), statement descriptor set
   (already supported via `statement_descriptor` on the vendor side).
4. **Taxes:** enable **Stripe Tax** with automatic calculation and set the
   price's tax behavior to *inclusive* (buyers see the final amount before
   paying). Until then, the modal shows the VAT-may-apply note (§4).
   For EU consumer sales above the €10,000 OSS threshold, register for the
   **EU VAT OSS scheme** — the country/address capture already in the modal
   is exactly the evidence OSS returns need.
5. **Receipt + support trail:** every buyer must be able to reach you —
   include a support email in the success screen and in Stripe's public
   business info.
6. **Honesty about "what's coming."** The roadmap teaser (§4.3) must not
   promise a release date. Phrase ecosystem access as *included with this
   license when it launches* — no dates, no "beta soon" unless true.

---

## 3. What the modal looks like today

`assets/js/content-graph-commerce.js` builds the modal with safe DOM
construction (`el()`/`linkEl()`, no innerHTML for user data):

```
┌─ Get NV oOS Complete ───────────────×─┐
│  price label (e.g. "$49.00")          │
│  "Payments processed securely…"       │
│  Email for receipt and refunds        │
│  Country (VAT) [+ EU address]         │
│  [Stripe Payment Element]             │
│            [Cancel]  [Pay]            │
└───────────────────────────────────────┘
```
Consent checkbox renders *inside* the Payment Element area once Stripe
loads; success state replaces it with license key + ZIP download + reload.

All strings are already centralized in the `i18n` array of
`wp_localize_script( 'nvoos-content-graph-commerce', … )` in
`src/Admin/SettingsPage.php` (~L543–591) — new copy goes there, new markup
goes in `openModal()` / `renderConsent()` / `renderSuccess()`, styles in
`assets/css/content-graph-admin.css`.

---

## 4. Modal enhancement plan (phased, mapped to code)

### Phase 1 — Trust & clarity (highest ROI, ~1 day)

**4.1 Price block clarity** — `openModal()` currently renders a bare
`config.price_label`. Wrap it in a small block:

```
$49.00 — one-time payment, no subscription
[VAT may be added at checkout based on your country.]  ← until Stripe Tax
```

- New i18n keys: `price_one_time`, `price_vat_note`, `price_license_scope`
  (the license-scope sentence from §2.2, shown right under the price).
- Implementation: append a `div.nvoos-cg-price-block` in `openModal()`
  containing the three lines; keep `price_label` as the first line.

**4.2 Trust row** — directly under the price block:

```
✓ Try it risk-free — 30-day money-back guarantee
✓ Instant download + automatic install
🔒 Card details are processed by Stripe and never touch this server
```

- The guarantee must exactly match the Refund Policy page (§2.1).
- Reuse the existing `secure_note` string as the third item; add
  `trust_guarantee`, `trust_instant` keys. Render as a `<ul class="nvoos-cg-trust">`.
- This is the single highest-converting addition per the research
  (benefit-framed guarantee at the decision point).

### Phase 2 — Value & excitement (~2 days)

**4.3 "What you get" block** — new section between the trust row and the
email field (`openModal()`, before `renderEmailRow()`):

```
Included in NV oOS Complete:
• The full NV oOS plugin — base + Pro (1,500+ tools & integrations)
• Updates for the current 1.x series
• Email support from the developer
• Access to the NV oOS Content Graph ecosystem when it launches —
  included at no extra cost
```

- i18n keys: `includes_title`, `includes_1`, `includes_2`, `includes_3`,
  `includes_roadmap`. Build from an array to keep the JS generic
  (map over `config.i18n.includes` if you prefer one key with an array).
- **Honesty rule:** only list features that exist today. The ecosystem line
  says *access is included* — no dates, no "soon".

**4.4 Roadmap teaser / "funded by owners"** — one line under the list:

```
Your purchase directly funds the next features. Owners like you shape
the roadmap — share what you'd build next at {roadmap link}.
```

- `roadmap_url` filter (pattern: `nvoos_content_graph/payments/roadmap_url`,
  alongside the existing `terms_url`/`refund_policy_url` filters in
  `Payments.php`); default to the public GitHub discussions/releases page.
- Only add this if you genuinely act on the requests — it is a promise.
- i18n: `roadmap_funded`, rendered only when `roadmap_url` is non-empty.

**4.5 Consent — EU withdrawal acknowledgment** — `renderConsent()`:
append one sentence to the checkbox label when the buyer selected an EU
country (the country select is already tracked in `isEuSelected()`):

```
Delivery starts immediately. By downloading, you acknowledge that you
lose your EU right of withdrawal for this digital content.
```

- i18n: `terms_eu_withdrawal`. Render it as a second line under the
  consent label (`renderConsent()` builds a row — add a
  `p.nvoos-cg-terms-sub` node), shown only for EU selections.
- The 30-day money-back guarantee sentence stays in the main consent text —
  the two are complementary, not contradictory (Meta's model).

### Phase 3 — Post-purchase excitement (~1 day)

**4.6 Success screen "what happens next"** — `renderSuccess()` currently
shows title, license key, manual ZIP, reload. Add a checklist before the
buttons:

```
You're all set! 🎉
[license key …]

What happens next:
1. A receipt is on its way to {email}.
2. NV oOS Complete is installed and activated.
3. Your license key is saved — keep it safe.
4. Watch the changelog for updates — and your Content Graph
   ecosystem access arrives with the launch, at no extra cost.
```

- i18n: `success_steps_title`, `success_step_1..4` (or a single
  `success_steps` array). Keep step 1 honest: only shown when a receipt
  email was provided.
- Add `changelog_url` link (reuse the `fallback_url` filter pattern).
- Add a **support line**: "Questions? {support email}" — compliance §2.5
  and refund-prevention in one move.

### Phase 4 — After launch (~1 week, owner + Stripe)

- Enable Stripe Tax; switch the VAT note to the final-amount display.
- Welcome email sequence (3 emails): receipt/license confirm → "getting
  the most out of NV oOS" → "what's coming / tell us what to build".
  Stripe's receipt already goes out automatically; the sequence is a
  follow-up you send from your own inbox/tool.

---

## 5. Proposed copy (ready to paste into the i18n array)

*Implemented — final wording (license scope: 1 year of updates; support:
email only, from the developer):*

```php
'price_one_time'    => __( 'One-time payment — no subscription', 'nvoos-content-graph' ),
'price_subject_change' => __( 'Introductory price — prices are subject to change.', 'nvoos-content-graph' ),
'price_vat_note'    => __( 'VAT may be added at checkout based on your country.', 'nvoos-content-graph' ),
'price_license_scope' => __( 'Includes 1 year of updates and email support on this site.', 'nvoos-content-graph' ),
'trust_guarantee'   => __( 'Try it risk-free — 30-day money-back guarantee', 'nvoos-content-graph' ),
'trust_instant'     => __( 'Instant download and automatic install', 'nvoos-content-graph' ),
'includes_title'    => __( 'Included in NV oOS Complete', 'nvoos-content-graph' ),
'includes_full'     => __( 'The full NV oOS plugin — base + Pro', 'nvoos-content-graph' ),
'includes_updates'  => __( '1 year of updates', 'nvoos-content-graph' ),
'includes_support'  => __( 'Email support directly from the developer', 'nvoos-content-graph' ),
'includes_roadmap'  => __( 'Access to the NV oOS Content Graph ecosystem when it launches — included at no extra cost', 'nvoos-content-graph' ),
'roadmap_funded'    => __( 'Your purchase directly funds the next features. Owners like you shape the roadmap — tell us what to build next.', 'nvoos-content-graph' ),
'roadmap_link_label' => __( 'Share your ideas', 'nvoos-content-graph' ),
'terms_eu_withdrawal' => __( 'Delivery starts immediately. By downloading, you acknowledge that you lose your EU right of withdrawal for this digital content.', 'nvoos-content-graph' ),
'success_steps_title' => __( 'What happens next', 'nvoos-content-graph' ),
'success_step_receipt' => __( 'A receipt is on its way to your email.', 'nvoos-content-graph' ),
'success_step_installed' => __( 'NV oOS Complete is installed and activated.', 'nvoos-content-graph' ),
'success_step_license' => __( 'Your license key is saved — keep it safe.', 'nvoos-content-graph' ),
'success_step_roadmap' => __( 'Watch the changelog for updates — your Content Graph ecosystem access arrives with the launch, at no extra cost.', 'nvoos-content-graph' ),
'changelog_link'    => __( 'View changelog', 'nvoos-content-graph' ),
'support_line'      => sprintf(
    /* translators: %s: support email address. */
    __( 'Questions? Email %s', 'nvoos-content-graph' ),
    \NvoosContentGraph\Commerce\Payments::supportEmail()
),
```

*The support email, roadmap URL, and changelog URL are filterable in
`Payments.php` (`nvoos_content_graph/payments/support_email|roadmap_url|
changelog_url`). The roadmap line renders only when a roadmap URL is set.*

---

## 6. Hard rules — what the modal must never do

Because this is the first public sale and the #1 goal is "no wrong idea":

- ❌ No fake countdowns, "only 2 left", or invented anchor pricing
  ("was $99") — misleading commercial practices under EU UCPD / FTC rules.
- ❌ No release dates or "coming soon in beta" for the ecosystem unless
  real. The included-access wording in §4.3/§5 is the safe ceiling.
- ❌ No testimonials unless real buyers said them.
- ❌ No "lifetime updates" wording — the license scope (§2.2) must say
  exactly what updates are covered.
- ✅ The guarantee, license scope, and consent copy must always match the
  published Terms / Refund Policy pages verbatim in substance.

---

## 7. Sources

- Freemius — Selling WordPress Plugins guide:
  https://freemius.com/selling-wordpress-plugins-guide/
- Freemius — Pricing page best practices:
  https://freemius.com/blog/pricing-page-best-practices-wordpress-plugins-themes/
- Stripe — Tax for Checkout/Elements:
  https://docs.stripe.com/tax/checkout · https://stripe.com/tax
- Stripe — Business information requirements:
  https://support.stripe.com/questions/business-information-requirements-to-use-stripe
- Stripe — Services Agreement (receipt duty):
  https://stripe.com/legal/ssa-services-terms
- Your Europe — Returns & right of withdrawal:
  https://europa.eu/youreurope/citizens/consumers/shopping/returns/index_en.htm
- ECC-Net — Cooling-off period for digital content:
  https://www.eccnet.eu/consumer-rights/what-are-my-consumer-rights/shopping-rights/cooling-period
- Meta — EU withdrawal waiver as a reference implementation:
  https://www.meta.com/legal/quest/eu-consumer-right-of-withdrawal-information/
- Rework — Trust signals & social proof:
  https://resources.rework.com/libraries/ecommerce-growth/trust-signals-social-proof
- Mailchimp — Trust signals that convert:
  https://mailchimp.com/resources/trust-signals/
- Salesforce — Post-purchase experience best practices:
  https://www.salesforce.com/commerce/post-purchase-experience/
- MoEngage — Post-purchase customer experience:
  https://www.moengage.com/blog/post-purchase-customer-experience/
- WP Product Talk — Lifetime license support data:
  https://wpproducttalk.com/blog/lifetime-license-support/
- Ben Ryan — Subscription vs lifetime licenses:
  https://benryan.com.au/blog/wordpress-plugin-subscription-vs-lifetime

---

## 8. Rollout order

1. ✅ **Owner:** §2 checklist (policies live, Stripe verification, receipts, tax) — *still open, launch-critical.*
2. ✅ **Code:** Phase 1 (trust + price clarity) — *implemented.*
3. ✅ **Code:** Phase 2 (value block, roadmap teaser, EU consent) — *implemented.*
4. ✅ **Code:** Phase 3 (success screen) — *implemented.*
5. ⬜ **Owner:** Phase 4 (Stripe Tax, welcome emails) — *first weeks after launch.*
