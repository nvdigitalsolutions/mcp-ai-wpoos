# Proposal 130 — Implementation plan (tracker & measurement capture)

Companion to `130-forge-tracking-measurement-capture-proposal.md`.
Planning date 2026-11-08. Repo: `examples/nvoos-astro-forge`.

---

## 1. Scope of this plan

Ships **P0 (detect) + P1 (safe emit) + P2 (first-party banner)** from the
proposal, plus the `verify --tracking` gate and the MCP surface. P3 (external
CMP hand-off), P4 (full commerce event map) and P5 (server-side) remain
declared seams with working defaults, not full implementations.

Deliberately **not** in this pass:

- a browser probe is implemented as an optional, fail-soft Playwright step
  (`--probe`) — it is not on any CI path;
- Partytown is accepted as a config value but degrades to deferred loading
  (no dependency is added to generated projects);
- `purchase` is never emitted by the forge while `purchaseSource:
  'wordpress'` (the default), and the checkout consent handoff is emitted on
  external checkout links only when `consent.checkoutUrl` is set.

---

## 2. Additional research that changed the design (2026-11-08)

| Finding | Source | Effect on the plan |
|---|---|---|
| GA4 regional collection hits (`region1..N.google-analytics.com`) are **blocked** unless CSP uses `https://*.google-analytics.com` | Google Tag Platform CSP guide; CentralCSP; therobinlord | CSP host table uses wildcards, not single hosts |
| `www.googletagmanager.com` is needed in `img-src` **and** `connect-src`, not just `script-src` | Google CSP guide | CSP emitter maps per-directive, not a flat host list |
| WCAG **2.2 SC 2.4.11 Focus Not Obscured** applies to consent banners | Secure Privacy; Kukie; cookie-script | banner reserves body space while open; no fixed bar covering focused content |
| `aria-modal="true"` on a **non-blocking** banner misleads screen readers | Kukie (WAI-ARIA dialog guidance) | banner is a non-modal `role="region"`; the *preferences* panel is a native `<dialog>` (browser-managed focus trap) |
| European Accessibility Act in force **28 June 2025** → banners must meet WCAG 2.2 AA | Kukie (2025) | a11y is a P2 acceptance criterion, not a nice-to-have |
| Escape should only dismiss if a default consent state exists | Kukie | non-modal banner: Escape does nothing; the preferences `<dialog>` closes with Escape without saving |
| Equal prominence for accept/reject | EDPB/CNIL/CPPA restatements | same button class, no colour asymmetry, reject listed first in DOM order |

---

## 3. File-by-file

### 3.1 `lib/types.ts` (forge)

Append the `ForgeTracker` / `ForgeConsent` / `ForgeVerification` /
`ForgeTracking` contracts (proposal §2.2) and add `tracking?: ForgeTracking`
to `SiteProfile`.

### 3.2 `lib/tracking.ts` (new, forge-only, ~430 lines)

Exports:

| Export | Role |
|---|---|
| `TRACKING_VENDORS` | the fingerprint table (data, not branches) |
| `scanTrackingHtml(input)` | htmlparser2 walk → raw signals (script srcs, inline JS, meta, noscript) |
| `probeTracking(url, opts)` | optional Playwright probe (requests + globals + cookie **names**) |
| `analyzeTracking(opts)` | fetch homepage + representative pages → `TrackingReport` |
| `trackingFromReport(report, opts)` | report → `ForgeTracking` (all trackers `enabled: false`) |
| `mergeTrackingDraft(existing, fresh)` | operator decisions survive `--write` |
| `renderTrackingReport(report)` | console summary |
| `consentCategoryFor(category)` | tracker category → banner category |

Detection precedence per vendor: **probe request (high) → script src (high)
→ inline call shape (medium) → probe global (medium) → cookie name
(medium)**. A vendor with no extractable id is still reported, downgraded to
`low`, with a `notes` entry — never dropped.

### 3.3 `lib/tracking-audit.ts` (new, forge-only, pure)

`auditTracking(tracking, html, { distFiles })` → findings:

| code | severity | rule |
|---|---|---|
| `verification-meta-missing` | fail | every configured token has its `<meta name=… content=…>` |
| `verification-file-missing` | fail | every `verification.files[].path` exists in `dist/` |
| `consent-default-missing` | fail | trackers exist ⇒ `id="nvoos-consent-default"` present |
| `tag-before-consent` | fail | consent script index < first vendor `<script src>` index |
| `tracker-in-static-html` | fail | `delivery: 'deferred'` + basic ⇒ no vendor `<script src=…>` in the static HTML |
| `purchase-owned-by-wordpress` | fail | `purchaseSource === 'wordpress'` and the emitted events JSON has `purchase: true` |
| `csp-host-missing` / `csp-host-stale` | fail / warn | declared hosts ⊇ enabled vendors; no host with zero vendors |
| `deprecated-tracker` | fail | UA- enabled |
| `inline-basic-conflict` | warn | `delivery: 'inline'` with `strategy: 'basic'` |
| `gtm-gtag-double` | warn | GTM and GA4 both enabled (GTM wins) |
| `partytown-unavailable` | warn | Partytown requested; deferred used instead |

Pure over inputs (no fs) so it unit-tests without a build.

### 3.4 `lib/scaffold.ts`

- `renderTrackingGenerated(profile)` → `src/lib/tracking.generated.ts`
  (interface + `export const tracking`), deterministic via the existing
  `tsString` style.
- `renderTrackingEnvLines(profile)` → appended to `.env.example`
  (`PUBLIC_<VENDOR>_<IDKEY>`, e.g.
  `PUBLIC_GOOGLE_ANALYTICS_4_MEASUREMENT_ID=G-…`) with a comment showing
  the vendor and id key; ids are public identifiers, never secrets.
- verification files → `public/<path>` (mkdir first).
- `public/_headers` → the CSP (130 §2.11), per-directive from the enabled
  vendors + the WordPress origin, **Report-Only** unless
  `consent.enforceCsp` (an enforcing policy that misses a host breaks a live
  site). Written only when a tracker is enabled.
- `ForgeManifest.tracking` = `{ enabled, vendors: string[], consentMode,
  hasVerification }` (no tokens, no ids).
- the generated files join the drift list (`nvoos.generated.json`).
- **No `tracking` block ⇒ the stub file is left untouched** and renders
  nothing, so the built HTML is unchanged (regression pin).

### 3.5 Template

| File | Notes |
|---|---|
| `src/lib/tracking.generated.ts` | inert stub (`enabled: false`) |
| `src/lib/consent.ts` | pure: `CONSENT_KEY`, `CONSENT_VERSION`, `defaultDecision`, `parseDecision`, `isGranted`, `gtagConsentMap`, `vendorConsentCategory` |
| `src/components/Tracking.astro` | verification meta + config JSON + consent bootstrap + loader; renders nothing when disabled |
| `src/components/ConsentBanner.astro` | non-modal region + native `<dialog>` preferences; `mode: 'forge'` only |
| `src/copy/en.ts` | `consent.*` strings (the type gate then covers new locales) |
| `src/layouts/Base.astro` | import `Tracking` right after the meta block; `ConsentBanner` before `</body>` |
| `src/styles/global.css` | banner styles + `html[data-consent-open] body` padding (SC 2.4.11) |

Ordering inside `<head>` (the invariant):

```
meta → verification meta → #nvoos-tracking-config (JSON)
     → #nvoos-consent-default (inline gtag defaults + stored decision + GPC)
     → #nvoos-tracking-events (JSON)
     → loader (inline; deferred unless strategy=advanced)
```

### 3.6 CLI (`scripts/forge.mjs`, plain JS)

- `analyze --tracking [--tracking-pages n] [--probe]`
- merges into `profile.tracking` through `mergeProfileDraft`
- `verify --tracking [--strict-tracking]`
- `--strict-tracking` promotes `warn` findings to failures.

### 3.7 MCP (`mcp/tools/tracking.ts`)

- `tracking_analyze` (read) — registered site **or** open-analyze URL, same
  SSRF policy as `design_apply`.
- `tracking_apply` (write) — merges the report into the registered profile;
  never enables a tracker.
- registered in `mcp/registry.ts`.

---

## 4. Test matrix

| Suite | Covers |
|---|---|
| `tests/tracking.test.ts` | per-vendor positives; prose/comment negatives; id validation; dedupe; UA deprecation; report→config (`enabled: false`); merge preserves operator `enabled` |
| `tests/tracking-audit.test.ts` | every finding code, incl. ordering (`consent before tag`), deferred static-HTML purity, purchase ownership, CSP superset/stale |
| `tests/consent.test.ts` | template `consent.ts`: defaults denied, GPC forces ad-denied, storage round-trip/version bump/corrupt JSON, category map |
| `tests/scaffold-tracking.test.ts` | `renderTrackingGenerated` shape + determinism; env lines; verification file paths; disabled profile leaves the stub inert |

E2E additions are **out of this pass** (the fixture would need a CMP stub and
the suite is a 3-minute gate); the ordering logic is covered by the pure
audit test against a hand-built HTML document.

---

## 5. Risks handled in this pass

- **Ordering regressions** → the audit's string-index assertion is the
  contract; the loader is inline and the defaults precede it by construction.
- **Double pageviews (GTM + gtag)** → GTM wins at emission; audit warns.
- **Banner blocking focus** → non-modal region + body padding.
- **Consent storage drift** between the inline script and `consent.ts` →
  `CONSENT_KEY`/`CONSENT_VERSION` pinned by a unit test; both files carry the
  keep-in-sync comment (theme-policy precedent).
- **Layout change for profiles without tracking** → `Tracking.astro` emits
  nothing; acceptance test compares the built head for a no-tracking profile.

---

## 6. Rollout

1. Land the forge libs + template + CLI + MCP + tests (this pass).
2. Pilot: run `analyze <pilot> --tracking --probe` and review the report
   before enabling anything.
3. Enable GA4 + verification only (P1) on one site; confirm Realtime +
   Search Console.
4. Enable the banner + Meta (P2) once the client's policy links exist.
5. P3/P4 as separate proposals-of-record against 130 §2.5/§2.8.

---

## 7. Status

| Item | State |
|---|---|
| `lib/tracking.ts` detector | shipped |
| `lib/tracking-audit.ts` gate | shipped |
| `tracking` profile block + manifest | shipped |
| scaffold emission + verification files | shipped |
| template loader + consent module + banner | shipped |
| `analyze --tracking` / `verify --tracking` | shipped |
| MCP `tracking_analyze` / `tracking_apply` | shipped |
| unit tests (detector, audit, consent, scaffold) | shipped |
| e2e assertion | **shipped** — fixture homepage carries GA4 + Meta + CookieYes + a verification meta + a `<noscript>` pixel; the new block scaffolds, installs, checks, builds and runs axe on the banner |
| external-CMP full hand-off (P3) | seam only |
| commerce event map (P4) | seam only |
| server-side (P5) | declaration only |

### 7.1 Validation evidence (2026-11-08)

- `npm run typecheck` clean; `npm test` **516/516** (50 new:
  detector 16, audit 13, consent 11, scaffold 10).
- `npm run test:e2e` **31/31** (scaffold → `astro check` → build → axe → SSR
  → wizard → **tracking capture**), 327s — the existing inert path is
  unchanged and the new block covers detection + emission + axe.
- Tracking-enabled scaffold (GA4 + Meta enabled, `mode: forge`, verification
  meta + file, deferred) against the hermetic fixture: `npm run check`
  **0 errors**, `npm run build` green.
- Built `dist/index.html` inspected: verification meta present, config JSON
  present, `#nvoos-consent-default` at index 881 (early), events JSON
  `purchase:false`, loader + banner present, **zero vendor `<script src>`**,
  `dist/google<token>.html` copied.
- `forge verify --tracking --strict-tracking` → exit **0** on the clean
  build; exit **1** after injecting a vendor tag before the consent block
  (`tag-before-consent`, `tracker-in-static-html`).
- `dist/_headers` ships the Report-Only CSP with the enabled vendors' hosts
  and the WordPress origin; `enforceCsp` flips it to enforcing (unit-pinned).
- Detector integration probe over a real HTTP page: GA4 id, Meta id,
  CookieYes CMP, Google + Bing verification tokens all detected; prose
  mentions ignored; `<noscript>` pixel warned and not rebuilt; draft block
  `external-cmp` + all trackers disabled.

**Unrelated pre-existing issue found while smoke-testing the npm artifact:**
`node dist/forge.mjs --help` throws `Dynamic require of "path" is not
supported` because `lib/wireframes.ts` → `lib/sanitize.ts` → `sanitize-html`
→ postcss is bundled into an ESM build by `prepare-dist.mjs` (esbuild cannot
shim the dynamic `require`). Investigated 2026-11-08 and **not fixed here**:
the documented `createRequire` banner fix corrupts the bundle (esbuild emits
the banner before the hashbang, splitting the shebang line), and a second
dynamic require (`../data/patch.json`) surfaces once the first is bypassed.
The real fix is a bundling-strategy change (`packages: 'external'` so the
CLI's declared `dependencies` install alongside it, or CJS output) — a
packaging-track decision, not a 130 change. The change was reverted; the
bundle is byte-identical to before.
