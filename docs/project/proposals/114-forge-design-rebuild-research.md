# Proposal 114 — Research: Rebuilding Customer Sites with a Design System (not Replicating Them)

**Date:** 2026-11-05
**Relates to:** `114-forge-design-rebuild-proposal.md`
**Method:** web research via Brave Search API (2026-11-05) + the repo's prior 098 research. Each finding is quoted/paraphrased, sourced, and mapped to a design decision in the proposal.

---

## 1. Design tokens are now a real standard — DTCG 2025.10

**Finding.** The W3C Design Tokens Community Group shipped the first stable version of
the Design Tokens specification, **2025.10**, on **2025-10-28**, backed by 40+
organizations including Adobe, Figma, Google, Microsoft, Shopify, and Salesforce.
The format module standardizes `$value`, `$type`, `$description`, `$deprecated`, and
`{ref}` alias syntax (Source: [w3.org/community/design-tokens announcement](https://www.w3.org/community/design-tokens/2025/10/28/design-tokens-specification-reaches-first-stable-version/), [designtokens.org](https://www.designtokens.org/), [codercops — Design Tokens in 2026](https://blog.codercops.com/blog/design-tokens-2026-w3c-format-guide)).

> "The DTCG JSON format unlocks interoperability and theming between your tools." — designtokens.org

**Decision map.** The rebuild pipeline's `design` profile block (§3.3) stays a
DTCG-shaped document — it is the same format the ADS (095) and the forge's token layer
already speak. No parallel format.

---

## 2. The industry token architecture: semantic-layer theming over constant primitives

**Finding.** The dominant pattern across 2025–2026 sources is identical and matches the
ADS architecture:

> "The DTCG Format Module 2025.10 standardises the JSON format… Theming works by
> swapping semantic token values; primitives stay constant." — [Sujeet, Design Tokens and Theming Architecture](https://sujeet.pro/articles/design-tokens-and-theming)

> "Keep designers and engineers aligned by syncing tokens from Figma (Tokens Studio /
> Figma Tokens plugin) into the token repository, then run the build pipeline to
> produce artifacts consumers use." — [dev.to/beefedai, Design Token Architecture for Scalable Theming](https://dev.to/beefedai/design-token-architecture-for-scalable-theming-2o0d)

> "The format question is the DTCG spec… Style Dictionary has read DTCG natively since
> v4." — [designtokens.substack, Build a Token Pipeline in 5 Steps](https://designtokens.substack.com/p/build-a-token-pipeline-in-5-steps)

Style Dictionary is the reference build system: hierarchical token tree (category →
primitive), transforms source JSON into platform artifacts ([github.com/style-dictionary](https://github.com/style-dictionary/style-dictionary)).

**Decision map.** (a) The forge template keeps the manifest JSON as the **single source
of truth** and emits generated artifacts (`design.generated.ts` + CSS) drift-gated in CI
(§3.3) — the velocity `tokens.generated.ts` discipline. (b) Brand/theme = semantic
overrides; the template's `--forge-*` layer expands to type roles/scales/spacing/radii
but the *component CSS* never hardcodes brand values.

---

## 3. Accessibility: fix it at the token level, gate it in CI

**Finding.** 2026 accessibility guidance is unambiguous that contrast enforcement
belongs on tokens and combinations, not on page-by-page audits:

> "Fix it where it lives: in a handful of design tokens, checked against their real
> backgrounds in every theme, then gated in CI so it can't creep back." — [A11yFlow, Color Contrast WCAG 2.2: A Developer's Fix Guide](https://www.a11yflow.dev/blog/color-contrast-wcag-developer-guide)

> "Run automated checks on primitives and stories… Test token combinations too, since a
> compliant color or spacing value can become [non-compliant in combination]." — [figr.design, Design System Accessibility](https://figr.design/blog/design-system-accessibility)

> "Start with an accessibility audit focused on high-traffic templates, then add
> automated checks in your CI to stop regressions." — [accessibility-test.org, Color Contrast in WCAG 2.2](https://accessibility-test.org/blog/support/advanced-guides/color-contrast-in-wcag-2-2-testing-and-fixes-that-actually-work/)

WCAG 2.2 thresholds: 4.5:1 text (SC 1.4.3), 3:1 non-text/UI components (SC 1.4.11)
([accessibilityassistant.com](https://accessibilityassistant.com/blog/accessibility-insights/how-to-apply-wcag-22-colour-contrast-accessibility/)).

**Decision map.** `contrastAudit()` (§3.5) checks every rendered token pair across
light/dark/high-contrast modes, emits `design-audit.json` as a build artifact, and gains
a `--strict-contrast` CI gate. The one-token-fix suggestion comes from the source's own
`unassigned` pool (the `#7a7a7a → #444444` jayainc example, §6).

---

## 4. Visual verification during migration: approved baselines, not source matching

**Finding.** The 2026 visual-regression toolchain compares against approved baselines to
catch regressions; the migration rule of thumb is coverage of high-traffic UI:

> "A good rule of thumb: screenshot the 20% of your UI that users interact with 80% of
> the time." — [Bug0, Best Visual Regression Testing Tools 2026](https://bug0.com/knowledge-base/visual-regression-testing-tools)

> "Visual testing… captures screenshots of your web app, compares them against approved
> baselines, and flags pixel-level or structural differences as regressions. The 2026
> toolchain spans Playwright's built-in toHaveScreenshot(), Percy and Chromatic for
> component review, Applitools and Wopee.io for AI-assisted diffing, and engines like
> pixelmatch and ODiff underneath." — [Wopee.io, Visual Testing: The Complete Guide](https://wopee.io/blog/ultimate-guide-to-visual-testing/)

> "Chromatic is strongest when visual coverage starts from Storybook stories and
> design-system components." — [desplega.ai, Visual Regression Testing: Chromatic vs Playwright vs Percy](https://www.desplega.ai/blog/deep-dive-7-visual-regression-testing-ui-bugs)

**Decision map.** `forge verify` (§3.6) splits verification: (a) **design-language
parity** — a `design_compare`-style distance score between the source's extracted
language and the built site's, because pixel-matching a third-party site is the cloning
boundary; (b) **regression baselines** — Playwright `toHaveScreenshot` baselines over
the high-traffic routes, committed per site and compared on every rebuild.

---

## 5. Headless migration playbooks: content-first, URL-preserving, design-refreshing

**Finding.** Agency practice for WordPress → headless migrations converges on a
well-defined discipline:

> "The pattern is well-trodden: export WordPress content via the REST API or XML, map
> post types, categories and ACF fields to a [new] schema, run the redirect plan across
> slugs that almost never line up perfectly, and rebuild the JSON-LD schema…" — [Robotostudio, CMS migration](https://robotostudio.com/services/cms-migration)

> "Organizations moving from monolithic platforms often forget that built-in user roles
> don't transfer automatically to a decoupled architecture… all need to be
> reimplemented in the frontend and API layer." — [Pagepro, Migration from WordPress to Headless CMS](https://pagepro.co/blog/migration-from-wordpress-to-headless-cms/)

> "That means preserving high-value URLs where possible, implementing page-level 301
> redirects, maintaining or improving content, and launching on a fast, SEO-friendly
> front end." — [gitnation, CMS Migration Services: Top 11 Agencies](https://gitnation.com/contents/hand-picked-headless-cms-migration-services-2026-best-agencies-reviewed)

> "Headless is about flexibility and workflows, not guaranteed speed." — [focusreactive, CMS Migration: Costs, SEO & Content Modeling](https://focusreactive.com/blog/cms-migration/)

**Decision map.** The forge already owns the URL/SEO half (redirects map, JSON-LD
rebuild, sitemap — 096/098). This proposal supplies the missing **content-model** half
(§3.2 semantic sections — the "map post types/fields to a new schema" step) and the
**design-refresh** half (§3.3–3.4). The focusreactive warning maps to the proposal's
non-goal of chasing pixel parity instead of measurable quality.

---

## 6. The transformative posture (098's research, restated for the forge context)

**Finding** (sources first cited in `098-design-language-import-research.md`):

> "Describe and tokenize the page, rebuild in your own stack from the tokens and
> structure, and never re-host the original's images or fonts. Use what you pull as a
> starting system and make the design your own." — uiscanner

> "A wrong role assignment is worse than a missing one." — dev.to/hugonaili, on the
> unassigned-pool discipline

> "AI-assisted rewriting on top of capture is what moves a project from 'clone' toward
> 'inspired by'." — CopyWeb, on the legal boundary

> "Recreate look and feel without copying text, images, or layouts." — Koder.ai

> Design systems evolve; token extraction should be re-runnable and diffable. —
> Element Armory

**Decision map.** The rebuild pipeline inherits 098's contract wholesale: fail-loud
roles + unassigned pool (§3.3), mandatory adaptation brief with keep/replace/remix
(§3.3), no source CSS/markup/assets output (§3.8), and re-runnable `design_compare`
drift detection across rebuilds (§3.6).

---

## 6. Forms on static frontends: bridge to the native handler, then harden

**Finding** (web research 2026-11-05, the JetFormBuilder + Elementor surfaces):

> “How to execute after-submit form actions without the actual form? Try Formless
> Actions Endpoints Addon to add, update, and delete users, post data, and **submit
> forms from another site via REST API**.” — [Crocoblock, JetFormBuilder Formless Actions
> Endpoints](https://jetformbuilder.com/addons/formless-actions-endpoints/)

> “The Forms API allows developers to filter content, validate data, alter webhooks,
> execute custom code and much more.” — [Elementor Developers — Forms
> API](https://developers.elementor.com/docs/hooks/forms); Elementor's maintainers point
> third-party (headless) consumers at custom code on `elementor_pro/forms/new_record`
> rather than a REST submit route ([GitHub discussion
> #24198](https://github.com/orgs/elementor/discussions/24198)).

**Decision map.** One bridge route (`POST …/forms/submit`) that dispatches the plugin's
NATIVE handler in-process — JFB's formless endpoint or Elementor's `Ajax_Handler`/
`Form_Record` — instead of reimplementing validation/storage/mail. This is the repo's own
110 checkout-proxy pattern (the bridge runs the Woo Store API in-process for `/checkout`),
applied to forms: plugin behavior stays identical, the static site stays behavior-free.

**Finding** (spam + cross-origin hardening):

> “Low spam, brochure site: Honeypot plus timestamp may be enough. Moderate spam, lead
> form: Turnstile is my default. Score-based workflows already exist: reCAPTCHA v3 can
> fit.” — [Static Forms 2026](https://www.staticforms.dev/blog/spam-email-bot)

> “Trusting a reCAPTCHA v3 score without verifying its action or hostname… using only
> CSS to hide a predictable honeypot field.” — [3zero — Honeypot vs reCAPTCHA vs
> Turnstile](https://www.3zerodigital.com/blog/how-to-protect-your-forms-from-spam-bots-honeypot-vs-google-recaptcha-vs-cloudflare-turnstile-2025-comparison/)

> “Confirm every text field runs through server-side sanitization, not just client-side
> validation.” — [ivyforms — WordPress Form
> Security](https://ivyforms.com/blog/wordpress-form-security/)

**Decision map.** The bridge's spam ladder is the industry floor: honeypot + timestamp
always (silent discard), score captcha opt-in with `action` + `hostname` pinned, every
field server-side sanitized, origin allowlist + rate limits for the cross-origin path
(§3.9 steps 1–2). GDPR: consent passes through; submissions stay on WordPress.

## 7. Findings not adopted (and why)

| Finding | Source | Not adopted because |
|---|---|---|
| Hosted visual-review platforms (Percy/Chromatic SaaS workflows) | desplega, Bug0 | The repo's hermetic, fixture-server discipline runs Playwright locally/CI with zero third-party services — baselines are repo artifacts |
| Figma/Tokens Studio sync as the authoring loop | dev.to/beefedai | The forge's authoring surface is the operator profile JSON + 098 extraction; no Figma dependency exists in the stack |
| AI-assisted diffing (Applitools/Wopee) | Wopee | Deterministic scoring first; AI only after pinned fixtures (098 Q5 posture) |
| Replatforming for speed | focusreactive | Out of scope — the forge's value is the headless workflow, not a performance claim |

## 8. Source list

1. W3C DTCG — https://www.w3.org/community/design-tokens/2025/10/28/design-tokens-specification-reaches-first-stable-version/
2. designtokens.org — https://www.designtokens.org/
3. CODERCOPS — https://blog.codercops.com/blog/design-tokens-2026-w3c-format-guide
4. Style Dictionary — https://github.com/style-dictionary/style-dictionary
5. Sujeet Jaiswal — https://sujeet.pro/articles/design-tokens-and-theming
6. designtokens.substack — https://designtokens.substack.com/p/build-a-token-pipeline-in-5-steps
7. dev.to/beefedai — https://dev.to/beefedai/design-token-architecture-for-scalable-theming-2o0d
8. A11yFlow — https://www.a11yflow.dev/blog/color-contrast-wcag-developer-guide
9. figr.design — https://figr.design/blog/design-system-accessibility
10. accessibility-test.org — https://accessibility-test.org/blog/support/advanced-guides/color-contrast-in-wcag-2-2-testing-and-fixes-that-actually-work/
11. accessibilityassistant.com — https://accessibilityassistant.com/blog/accessibility-insights/how-to-apply-wcag-22-colour-contrast-accessibility/
12. Wopee.io — https://wopee.io/blog/ultimate-guide-to-visual-testing/
13. Bug0 — https://bug0.com/knowledge-base/visual-regression-testing-tools
14. desplega.ai — https://www.desplega.ai/blog/deep-dive-7-visual-regression-testing-ui-bugs
15. Robotostudio — https://robotostudio.com/services/cms-migration
16. Pagepro — https://pagepro.co/blog/migration-from-wordpress-to-headless-cms/
17. focusreactive — https://focusreactive.com/blog/cms-migration/
18. gitnation — https://gitnation.com/contents/hand-picked-headless-cms-migration-services-2026-best-agencies-reviewed
18. Repo prior art: `docs/project/proposals/098-design-language-import-research.md` (uiscanner, dev.to/hugonaili, CopyWeb, Koder.ai, Law StackExchange, Superdesign, ExportToFigma, Element Armory)
19. JetFormBuilder Formless Actions Endpoints — https://jetformbuilder.com/addons/formless-actions-endpoints/
20. JetFormBuilder endpoint setup — https://jetformbuilder.com/features/how-to-setup-formless-actions-endpoints/
21. Crocoblock AI-builder stack — https://crocoblock.com/blog/wordpress-ai-builders-and-wordpress-crocoblock-combo/
22. Elementor Forms API — https://developers.elementor.com/docs/hooks/forms
23. Elementor headless-submit discussion #24198 — https://github.com/orgs/elementor/discussions/24198
24. wphelpforums — new_record sync — https://wphelpforums.com/threads/how-to-sync-elementor-form-submissions-to-a-third-party-api-using-elementor_pro-forms-new_record.7365/
25. Static Forms 2026 spam guide — https://www.staticforms.dev/blog/spam-email-bot
26. 3zero — honeypot vs reCAPTCHA vs Turnstile — https://www.3zerodigital.com/blog/how-to-protect-your-forms-from-spam-bots-honeypot-vs-google-recaptcha-vs-cloudflare-turnstile-2025-comparison/
27. ivyforms — WordPress form security — https://ivyforms.com/blog/wordpress-form-security/
28. Bit Form — spam prevention — https://bit-form.com/blog/prevent-spam-form-submissions-in-wordpress/
