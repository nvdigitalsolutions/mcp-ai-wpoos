# Google OAuth Production Submission — Implementation Plan & Checklist

> **Status:** Proposal — awaiting decision on audience (Step 1)
> **Companion to:** [google-oauth-setup.md](google-oauth-setup.md)
> **Last researched:** October 2026 (Google docs current as of Aug–Sep 2026)

---

## 1. Why you are here (the 7-day problem, precisely)

Your OAuth consent screen is in **Testing** publishing status. Google documents that Testing status carries three restrictions:

1. **Refresh tokens expire after 7 days** — this is the reset you are seeing.
2. A user cap applies (unverified apps limited to ~100 accounts).
3. Every user sees the "unverified app" warning screen.

Moving the app to **In production** is the only way to get long-lived refresh tokens. But for the scopes NV oOS currently requests, publishing is not a toggle — Google **blocks** restricted scopes outright until verification (and, for server-side data handling, a third-party security assessment) is completed. This document is the plan for doing that correctly.

> ⚠️ **Critical caveat:** If your project requests *restricted* scopes (Gmail read, Drive read-only — both currently requested by NV oOS), simply flipping "In production" will make the app **"This app is blocked"** for any Google account that has not yet granted consent. Verification must come first.

---

## 2. Scope inventory — what NV oOS actually requests today

Source: code audit of `includes/` and `addons/pro/` (October 2026) cross-referenced against Google's published scope classifications.

| Integration | Scopes in code | Google classification | Verification burden |
|---|---|---|---|
| Gmail (base + Pro) | `gmail.readonly` | **Restricted** | Full review + **CASA Tier 2 security assessment, annual** |
| Drive (base) | `drive.readonly` + `drive.metadata.readonly` | **Restricted** (both) | Full review + **CASA Tier 2, annual** |
| Drive (Pro Remote Sites) | `drive.file` + `drive.readonly` | `drive.file` = **non-sensitive**; `drive.readonly` = **restricted** | CASA (only because of `drive.readonly`) |
| Calendar — Minimal profile | `calendar.app.created` + `calendar.calendarlist.readonly` | Non-sensitive | **None** |
| Calendar — Standard profile | `calendar.events` + `calendar.freebusy` + `calendar.calendarlist.readonly` | Sensitive | Sensitive-scope review (~3–5 business days) |
| Calendar — Full profile | `calendar` + `calendar.settings.readonly` | Sensitive | Sensitive-scope review (~3–5 business days) |
| Google Chat | Service account `chat.bot` (primary); optional user-OAuth `chat.messages` + `chat.spaces.readonly` | Service account = exempt ("service-owned data"); OAuth scopes: verify in console | **None** for the service-account path |
| Google Analytics (GA4) | `analytics.readonly` | Sensitive | Sensitive-scope review (~3–5 business days) |
| Graphify Drive driver | `drive.metadata.readonly` or `drive.readonly` | Restricted | CASA if published with these |

**Headline:** Gmail read and Drive read-only are *restricted* scopes. As long as the product requests them for server-side use, Google requires the full restricted-scope pipeline: brand verification → app review (demo video + justification) → **CASA Tier 2 security assessment by a Google-approved lab**, renewed every 12 months.

Sources:
- [Choose Gmail API scopes](https://developers.google.com/gmail/api/auth/scopes) (2026-09-10): `gmail.readonly` listed under **Restricted**; `gmail.send` under Sensitive.
- [Choose Google Drive API scopes](https://developers.google.com/drive/api/guides/api-specific-auth) (2026-09-03): `drive.file` is **non-sensitive** and Google explicitly recommends migrating to it; `drive.readonly`, `drive.metadata.readonly`, `drive.metadata` are **restricted**.
- [Sensitive scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification) and [Restricted scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification) (2026-08-19).

---

## 3. Decision 1 — Which deployment are you submitting? (do this first)

**Key structural fact:** every NV oOS site uses its **own** client ID/secret in its **own** Google Cloud project (customers bring their own credentials). Google's verification is scoped to a *project's OAuth consent screen*, so each deployment is evaluated on its own. Your testing client, a customer's testing client, and a customer's production client are three separate "apps" to Google — nothing transfers between them.

That splits this plan into two independent tracks:

### Track A — NV Digital's own deployment (your small team)

You do **not** need the public-product pipeline for this. Two clean options:

- **A1. Internal (recommended if you are on Google Workspace).** If the Google accounts used for ops live on a Workspace domain (e.g. `@nvdigitalsolutions.com`), create the production project **inside that organization** (a project under "No organization" cannot be made Internal) and set the OAuth consent screen user type to **Internal**. Result: no verification, no CASA, no 7-day refresh-token expiry, no user cap, no unverified-app warnings — for any account in your org. This is the "small team" answer Google designed for, with zero self-declaration.
- **A2. Personal-use exception (if the accounts are personal Gmail).** Google's **personal use** exception covers "you and a few users known personally to you." For restricted scopes it is claimed through the verification submission itself (the questionnaire includes the exception); when granted you get long-lived tokens, no CASA, no fee. Honest fit: founder-scale ops where the only authorizers are people you know. Limits: a user cap (community-reported ~100 accounts) and Google can re-review usage that looks public.
- **A3. Stay in Testing (current band-aid).** Only for genuine dev/staging work — the 7-day expiry persists by design.

> ⚠️ Company-internal ops is a gray area for "personal use" (Google's wording assumes personal accounts, not business operations). If you are on Workspace, **A1 is strictly better**: cleaner, durable, no re-review risk. Use A2 only for personal-Gmail accounts.

### Track B — Customer deployments (their own projects)

Customers configure their own GCP projects, so Google's burden is *theirs* — but their experience is your support burden. Realistic customer profiles:

- **Solo/small operators connecting their own mailbox** — the typical NV oOS user. Their own project + their own Gmail is the textbook **personal-use exception** shape. They still submit the verification form and claim the exception for `gmail.readonly`, then publish to production. Ship them a step-by-step guide (Section 7).
- **Customer on Google Workspace** — point them at the **Internal** consent screen; nothing else needed.
- **Multi-user / multi-account customer** — the only case that genuinely needs the full Path B pipeline (CASA etc.) on *their* project. Rare for this product; document the pointer and offer paid onboarding rather than designing around it.

**Recommendation:**
- NV Digital ops → **A1** if Workspace, else **A2**.
- Product/docs work → a customer-facing "Connect Google to your site" guide covering the personal-use exception submission and the Internal option, plus the Section 4 scope reductions (Drive → `drive.file`, Calendar → Minimal) so customers hit restricted scopes only for Gmail read.
- Revisit Track B only if the roadmap ever has many customers sharing one credential set — an "official NV oOS Google app" model exists in the industry (WP Mail SMTP ships its own verified Gmail app) but makes NV Digital the data processor with Limited Use obligations and a per-site redirect-URI support workflow.

---

## 4. Decision 2 — Scope minimization (do this regardless of path)

Industry standard (and Google's explicit guidance): request the narrowest scope; every restricted scope you drop removes weeks of review and annual cost.

- [ ] **Migrate Drive to `drive.file` (non-sensitive).** Google's Drive page explicitly recommends this migration and states many apps need no code change beyond scope string + Google Picker for file selection. NV oOS currently has known scope drift (base: `drive.readonly drive.metadata.readonly`; Pro: `drive.file drive.readonly`) — unify both on `drive.file` (or `drive.file` + Picker). **This alone removes Drive from the CASA surface.**
- [ ] **Default Calendar to the Minimal profile** (`calendar.app.created` + `calendar.calendarlist.readonly`) — zero verification. Only users who opt into Standard/Full trigger sensitive-scope review (3–5 days, no CASA).
- [ ] **Keep Google Chat on the service account** (`chat.bot`) — already exempt. Avoid declaring user-OAuth Chat scopes unless a feature needs them.
- [ ] **Gmail:** there is *no* non-restricted Gmail read scope. Decide honestly:
  - Read mail is core → keep `gmail.readonly`, accept CASA (Path B), or claim personal-use exception (Path A).
  - Only sending is needed → switch to `gmail.send` (**sensitive** only — no CASA).
- [ ] **GA4:** `analytics.readonly` is sensitive (3–5 day review). Drop it from the consent screen if analytics can use a service account or API key instead.
- [ ] **Declare exactly the scopes you ship.** In Console → APIs & Services → OAuth consent screen → **Data Access**, add every scope the code requests and remove every scope it does not.

---

## 5. Phase plan (full production submission — multi-user Path B)

> Only required for multi-user customer deployments or a future official shared app. NV Digital's own ops (Track A) and solo-customer deployments (personal-use exception) skip Phases 4–5 and use this section as reference only.

### Phase 0 — Project hygiene (Week 1, ~1 day)
- [ ] Create **separate GCP projects**: one dev/test project (keep it in Testing status) and one production project. Google explicitly recommends this; verification applies to a project, not a client.
- [ ] Attach **billing** to the production project (required for some APIs and for assessment tooling).
- [ ] Enable the exact APIs used: Gmail, Drive, Calendar, Google Chat, Analytics (Data) — nothing more.
- [ ] Keep **Owner/Editor** accounts and the **developer contact email** current — Google emails these for every review step and the annual recertification.
- [ ] OAuth consent screen → user type **External**; fill app name, user support email, app domain, authorized domains (the WordPress host domains), developer contact emails.
- [ ] Delete any OAuth clients not going to production.

### Phase 1 — Brand verification (Week 1, minutes–2 business days)
Google runs brand verification first; nothing else can proceed without it.

- [ ] **App name** — matches your public brand (e.g. "NV oOS"), no "Google" in the name.
- [ ] **Logo** — 128×128 up to 512×512 (uploaded in the OAuth Branding page).
- [ ] **Application home page** — publicly accessible (no login wall), clearly describes the app's functionality, links to the privacy policy (and ToS if you have one). Must be on the verified domain. Play Store / Facebook links do not qualify.
- [ ] **Privacy policy** — hosted on the **same domain** as the home page; must disclose how the app accesses, uses, stores and shares Google user data, and comply with [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy) and the **Limited Use** requirements (for restricted scopes): no advertising on Google data, no selling it, no transferring it except to operate the feature, no human review except for security/bugs/user consent.
- [ ] **Verify the authorized domain in Google Search Console** using an account that is Owner/Editor on the GCP project.
- [ ] In Console → **OAuth Branding** → save as Draft Branding → **Verify Branding** → when status is **Ready to publish**, click **Publish branding**. ⚠️ *Compliant results are valid only 7 days — publish promptly.*

### Phase 2 — OAuth client configuration (Week 1, ~1 hour)
- [ ] Client type **Web application**.
- [ ] **Authorized JavaScript origins** — the WP admin origins only (no trailing slash), e.g. `https://bots.nvdigital.solutions`.
- [ ] **Authorized redirect URIs** — exact-match list for every callback the plugin uses:
  - `https://<site>/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback`
  - `https://<site>/wp-admin/admin.php?wp_mcp_ai_oauth=google_drive_callback`
  - `https://<site>/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback` (Pro Remote Sites)
  - `...&oauth_handler=google_drive_oauth_callback` and `google_calendar_oauth_callback` (Pro)
  - ⚠️ Keep the `wp_mcp_ai_oauth=` / `oauth_handler=` parameter names — do not use `action=` (Google reserves it).
- [ ] Keep `access_type=offline` + `prompt=consent` in the code (already present) so refresh tokens are issued.
- [ ] One production client per surface (or one client with all redirect URIs registered — either is acceptable; document the choice).

### Phase 3 — Data Access declaration (Week 1)
- [ ] In the OAuth consent screen → **Data Access** (Verification Center), add the final scope list from Section 4.
- [ ] The console classifies each scope automatically (non-sensitive / sensitive / restricted) — this is your ground truth; reconcile against the code, not assumptions.
- [ ] Remove every scope the code does not request. Reviewers check the *code*, not just the form.

### Phase 4 — Sensitive / restricted scope verification (Weeks 1–2)
- [ ] **Per-scope justification** in the Verification Center: what the feature does, and why a narrower scope is insufficient. (Template in Google's docs: "My app will use `<scope>` to `<action>` on `<screen>` so users can `<benefit>`.")
- [ ] **Demo video** (YouTube, **Unlisted**, English):
  - Full consent flow as a user experiences it, from your own UI.
  - Show the consent screen displaying the correct **App Name**.
  - Show the browser **address bar containing your OAuth client ID** on the consent screen.
  - Demonstrate, per scope, the actual functionality the scope enables (e.g. Gmail search tool returning results, Drive file listing).
  - If multiple client IDs exist, show data access on each.
- [ ] Select the **permitted application type** for Gmail restricted scopes — for NV oOS this is **task automation platform** (an AI operator acting on the user's own mailbox). Do not misclassify; if unsure, leave unselected and let Google's team classify.
- [ ] Provide up to three **documentation links** describing the feature.
- [ ] Complete the **Data Safety declaration** if the console prompts for it (data types collected, purposes, sharing).
- [ ] **Expect the 3–5 business day window** for sensitive scopes; restricted scopes take several weeks end-to-end including the assessment below.

### Phase 5 — CASA Tier 2 security assessment (Weeks 2–6, only if any restricted scope remains)
Required because NV oOS stores tokens/accesses Google data from its own (third-party) server.

- [ ] Select a **Google-empanelled security assessor** (e.g. TAC Security, Leviathan, Bishop Fox — see the App Defense Alliance CASA program list).
- [ ] Scope the assessment to the restricted scopes you keep (each restricted scope must be covered; adding one later = reassessment).
- [ ] Expect a **DAST scan** of the running application (SAST may be waived for web apps; community reports vary).
- [ ] Remediate all findings before the **Letter of Assessment (LOA)** is issued.
- [ ] Budget: community-reported pricing is roughly **US$400–1,000** (e.g. $540 via TAC Security, Dec 2025–Jan 2026) with a **~1 month** elapsed timeline. Budget for remediation time on top.
- [ ] **Annual recertification** — revalidation every 12 months from the LOA date; Google emails you; keep the same assessor engaged.
- [ ] **Limited Use compliance** in code and docs: no ads/selling/transfer of Google data; user data deletion on disconnect (NV oOS already calls the revocation endpoint on disconnect for Calendar — verify Gmail/Drive paths do the same and document it).

### Phase 6 — Publish & cutover (Week 6+)
- [ ] After approval: OAuth consent screen → **Publishing status → In production**.
- [ ] Have every existing account **re-authorize once** (revoke first via `myaccount.google.com/permissions` if the consent screen changed) to bank long-lived refresh tokens.
- [ ] Remove the test accounts no longer needed from the Testing project.
- [ ] Keep the dev/test project in Testing status permanently for staging.
- [ ] Monitor the **Verification Center** status page and the support/developer email inboxes — Google pauses reviews waiting on replies.

### Phase 7 — Ongoing compliance
- [ ] Annual CASA recertification (restricted scopes) + Google's annual re-verification email.
- [ ] **Any change re-triggers review:** new scope, new redirect URI client, consent-screen branding changes, app type change. Plan releases around this.
- [ ] Token hygiene: 100 refresh tokens per (user × client) ceiling; never re-run the OAuth start URL on every page load (NV oOS already gates this behind an explicit Connect click — keep it that way).
- [ ] Security hygiene for the assessed site: TLS, patched WordPress/plugins, no refresh tokens in logs (the plugin's audit logger and sensitive-field masking should be re-verified against Gmail/Drive token storage).
- [ ] Privacy policy must stay current with actual data practices.

---

## 6. Recommended end-to-end timeline (Path B)

| Phase | Elapsed |
|---|---|
| 0–2 — Project, brand, clients, data access | 2–4 business days (mostly waiting on brand verification) |
| 4 — Sensitive scope review | 3–5 business days |
| 5 — CASA Tier 2 (restricted scopes) | ~3–6 weeks incl. remediation |
| 6 — Publish + re-auth | 1 day |
| **Total (with Gmail read)** | **~5–8 weeks** |
| **Total (after Drive → `drive.file`, no restricted scopes — Path A exception)** | **~1–2 weeks, no CASA** |

---

## 7. NV oOS engineering changes needed (repo work)

- [ ] **Unify Drive scopes on `drive.file`** across all four flows (base Gmail/Drive in `includes/integrations/class-wp-mcp-ai-oauth-manager.php` + `includes/admin/class-wp-mcp-ai-admin-settings.php`; Pro Remote Sites in `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`; the Content Graph port in `plugins/nvoos-content-graph-ai-platform/src/Integrations/OAuthManager.php`). This resolves the documented scope drift and removes Drive from the restricted set. Where reading arbitrary files is still required, switch selection to Google Picker (per Google's guidance) or accept CASA for that surface only.
- [ ] **Add a "Publishing status" admin warning for Gmail and Drive** (the Calendar footer already ships one for the 7-day Testing expiry — extend the same pattern; see `docs/developer/architecture/integrations/google-calendar-connection.md` → Troubleshooting).
- [ ] **Verify token revocation on disconnect for Gmail and Drive** (Calendar does this; check the base flows) — this is a Limited Use requirement Google checks.
- [ ] **Confirm refresh tokens are stored per (site, account), masked in admin, and never logged** — the settings sensitive-field allowlist already covers the client secrets; audit the refresh-token options/transients with the same lens.
- [ ] **Update docs:** `docs/features/integrations/GOOGLE_DRIVE_CONNECTION_SETUP.md`, `REMOTE_CONNECTIONS_GUIDE.md`, `docs/reference/EXTERNAL_SERVICES.md`, and `docs/getting-started/installation-setup/google-oauth-setup.md` to reflect the production scope set and this verification plan (link from the setup guide).
- [ ] **Add a privacy-policy template / Limited Use disclosure** to the plugin docs for site owners who connect their own Google accounts (each NV oOS operator's site is effectively its own data controller).
- [ ] **Ship a customer-facing "Connect Google to your own site" guide** — covers: the bring-your-own-credentials model, the personal-use exception submission for solo operators (step-by-step, including how to answer the verification questionnaire), the Workspace **Internal** option, and the per-integration scope recommendations from Section 4.

---

## 8. Risk register (common rejection reasons)

| Risk | Mitigation |
|---|---|
| Rejected: privacy policy not same-domain / doesn't describe Google data use | Host policy on the app's verified domain; enumerate each scope and its purpose; Limited Use clauses |
| Rejected: home page behind login / not obviously the app | Public landing page describing the integration |
| Rejected: scopes in code ≠ scopes declared | Phase 3 reconciliation against the code audit in Section 2 |
| Rejected: demo video missing client ID in address bar or not in English | Follow the 4-point video checklist in Phase 4 exactly |
| Blocked: published to production before verification with restricted scopes | Sequence: verify → approve → *then* publish |
| CASA lab finds vulnerabilities (outdated WP, missing headers, token leakage) | Pre-scan with a DAST tool before engaging the lab; patch host sites |
| Annual recertification missed → scopes revoked | Owner/Editor contacts + calendar reminder 2 months before LOA anniversary |
| Scope creep after approval re-triggers full review | Freeze scope set per release; stage new scopes in the dev project first |

---

## 9. Sources

- [Sensitive scope verification — Google Identity](https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification) (updated 2026-08-19)
- [Restricted scope verification — Google Identity](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification) (updated 2026-08-19)
- [OAuth App Verification Help Center](https://support.google.com/cloud/answer/13463073) / [Verification requirements](https://support.google.com/cloud/answer/13464321) / [Security Assessment (CASA)](https://support.google.com/cloud/answer/13465431) — Google Cloud Console Help
- [Choose Gmail API scopes](https://developers.google.com/gmail/api/auth/scopes) (2026-09-10) — `gmail.readonly` = Restricted
- [Choose Google Drive API scopes](https://developers.google.com/drive/api/guides/api-specific-auth) (2026-09-03) — `drive.file` = Non-sensitive; migration recommended
- [OAuth 2.0 Scopes for Google APIs](https://developers.google.com/identity/protocols/oauth2/scopes) (2026-09-14)
- [Configure the OAuth consent screen](https://developers.google.com/workspace/guides/configure-oauth-consent) — Google Workspace
- [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy) / [OAuth 2.0 Policies](https://developers.google.com/identity/protocols/oauth2/policies)
- Industry reports (cost/timeline datapoints): [TAC Security CASA experience, r/SaaS](https://www.reddit.com/r/SaaS/comments/1q84d0n/), [Tier 2 write-ups](https://www.reddit.com/r/googlecloud/comments/1i1dgtm/), [Mailneo CASA post-mortem](https://www.mailneo.co/blog/casa-tier-2-certification), [CASA overview (Deepstrike)](https://deepstrike.io/blog/google-casa-security-assessment-2025)

---

## 10. Open decisions (needed before execution)

1. **NV Digital's Workspace status:** are the ops Google accounts on a Workspace domain? Yes → production project as **Internal** (no review at all). No → personal-use exception submission (A2).
2. **Customer guide scope:** which customer profiles do we document (minimum: personal-use exception + Workspace-Internal)?
3. **Gmail read:** keep `gmail.readonly` (covered by personal-use / Internal paths) or reduce product surfaces to `gmail.send` (sensitive only) where reading is not core?
4. **Official shared app (future):** decide whether NV Digital ever offers a pre-verified shared OAuth app for customers — trades CASA + Limited Use obligations for customer convenience. Out of scope for now.
5. **Branding domain + assessor:** only needed if a public multi-user path (Track B) is ever taken.

---

## 11. Implementation status (2026-10-09)

**Done (code):**
- [x] Drive scopes unified on non-sensitive `drive.file` across all four flows — base (`includes/admin/class-wp-mcp-ai-admin-settings.php`, `includes/integrations/class-wp-mcp-ai-oauth-manager.php` incl. new `get_google_drive_scopes()` helper), Pro Remote Sites (`addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`), and the Content Graph port (`plugins/nvoos-content-graph-ai-platform/src/Integrations/OAuthManager.php`). The previously documented `wp_mcp_ai_google_drive_oauth_scope` filter is now actually implemented — full-Drive scopes are opt-in for verified/CASA projects.
- [x] Calendar default profile → **Minimal** (non-sensitive, no verification) in `includes/google/class-wp-mcp-ai-google-calendar-scopes.php`, `includes/admin/class-wp-mcp-ai-admin-settings-base.php`, and the CG port (`GoogleCalendarScopes.php`). Existing saved profiles are untouched.
- [x] Gmail and Drive disconnect handlers now revoke tokens upstream (best effort, non-fatal) via the shared `WP_MCP_AI_Google_OAuth_Service::revoke()` — Limited Use deletion requirement.
- [x] Publishing-status admin warnings added to the Gmail and Drive footers in `includes/admin/sections/class-wp-mcp-ai-section-integrations.php` (mirrors the Calendar warning): 7-day Testing expiry + per-integration verification guidance.
- [x] `tests/test-league-oauth2-no-approval-prompt.php` fixture updated to the new default scope.

**Done (docs):**
- [x] Customer guide shipped: `docs/getting-started/installation-setup/google-oauth-customer-guide.md` (paths 1–3, scope table, privacy-policy template, troubleshooting).
- [x] Updated: `GOOGLE_DRIVE_CONNECTION_SETUP.md`, `REMOTE_CONNECTIONS_GUIDE.md`, `EXTERNAL_SERVICES.md`, `oauth-compliance.md`, `google-oauth-setup.md` (new 7-day-expiry troubleshooting section + links).

**Deliberately not changed:** Graphify's Drive driver keeps broad metadata scopes (the feature requires reading all Drive metadata — it would break on `drive.file`); Google Chat stays on the service account; Gmail keeps `gmail.readonly`.

**Decided (2026-10-09):**
- NV Digital ops project → Workspace **Internal** consent screen (Section 3, Track A1).
- GA4 → already service-account based (`WP_MCP_AI_Analytics_GA4_Adapter` uses a service-account JSON key + JWT bearer flow; its `analytics.readonly` is the service-account assertion scope, not a consent-screen scope). **Do not add `analytics.readonly` to the OAuth consent screen Data Access** — GA4 keeps working without it (service-owned data exception).

**NV Digital Internal execution checklist (console, ~30 minutes):**
- [ ] Confirm the ops Google accounts are on the Workspace domain.
- [ ] Create the production GCP project **inside the Workspace organization** (a "No organization" project cannot be made Internal).
- [ ] Enable the Gmail, Drive, and Calendar APIs (GA4 optional; Chat service account as before).
- [ ] OAuth consent screen → User type = **Internal**; add only the scopes NV oOS actually requests (Gmail `gmail.readonly`, Drive `drive.file`, Calendar Minimal profile).
- [ ] Optional: on this Internal project the `wp_mcp_ai_google_drive_oauth_scope` filter can restore `drive.readonly` with **no verification and no CASA** — internal apps are exempt from scope verification.
- [ ] Create the OAuth clients, register the redirect URIs, then reconnect each account once → long-lived refresh tokens (no 7-day expiry).
