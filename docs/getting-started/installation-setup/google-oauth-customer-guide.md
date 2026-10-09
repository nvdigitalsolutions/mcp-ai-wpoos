# Connecting Google to Your Own Site — Customer Guide

> **Who this is for:** NV oOS site owners who connect their own Gmail, Drive, or Calendar account using their own Google Cloud credentials.
> **Companion to:** [google-oauth-setup.md](google-oauth-setup.md) (step-by-step credential setup) and [google-oauth-production-verification-plan.md](google-oauth-production-verification-plan.md) (full verification reference).

## The model: your credentials, your project

NV oOS does not ship a shared Google app. You create your **own Google Cloud project** with your **own OAuth Client ID and Secret**, so from Google's perspective **you are the developer of your own app**. That means:

- Your app's verification status is yours alone — no relationship to NV Digital or other sites.
- You are the data controller for the Google data your site processes, and your privacy policy must say so.

## Why does my connection reset every 7 days?

A Google Cloud project's OAuth consent screen defaults to **Testing** publishing status. In Testing, Google expires refresh tokens **after 7 days** — that is the weekly "connection broke, reconnect" symptom. Fixing it means choosing the right publishing path below.

## Pick your path

| Your situation | Recommended path | Cost | Verification |
|---|---|---|---|
| You alone (or a few people you know) connect your own mailbox | **Personal-use exception** | Free | One-time submission, typically days |
| Everyone is on one Google Workspace domain | **Internal** consent screen | Free | None |
| Many unrelated Google accounts use your site | Full public verification | $$$ incl. CASA security assessment, annual | Weeks |

### Path 1 — Personal-use exception (typical solo operator)

You are the only user of your own app, which is exactly what Google's personal-use exception covers. To claim it:

1. In Google Cloud Console → **APIs & Services → OAuth consent screen**:
   - Set **User type = External**.
   - Fill in: app name, your support email, developer contact email.
   - In **Data Access**, add the scopes your integration needs (see [Scope recommendations](#scope-recommendations)).
2. Add yourself as a test user and complete a test connection from NV oOS (see [google-oauth-setup.md](google-oauth-setup.md)).
3. Submit the app for verification from the consent screen. In the questionnaire, answer honestly that **the app is only for you / a small set of users you know personally** (personal-use exception). No demo video, no security assessment.
4. When Google approves the exception, set **Publishing status = In production**.
5. Revoke the old grant at [myaccount.google.com/permissions](https://myaccount.google.com/permissions), then reconnect once — the new refresh token is long-lived.

Limits: a user cap applies (Google does not publish the number; treat ~100 accounts as the ceiling) and Google can re-review if usage looks like a public product. If you grow past that, move to Path 3.

### Path 2 — Internal (everyone on one Workspace domain)

If every account that will authorize is in your Google Workspace organization:

1. Create the project **inside your organization** (a project under "No organization" cannot be made Internal).
2. OAuth consent screen → **User type = Internal**.
3. Add scopes and save. Done — no verification, no user cap, no 7-day expiry, for any account in your org.

### Path 3 — Full public verification

If unrelated Google accounts will authorize against your site, your app is a public product and needs Google's full pipeline: brand verification, app review with a demo video, and — because Gmail read is a **restricted** scope and NV oOS processes data on your server — an annual CASA Tier 2 security assessment by a Google-approved lab. See the [Production Verification Plan](google-oauth-production-verification-plan.md), Section 5, for the complete checklist.

## Scope recommendations

Request the narrowest set that covers your features:

| Integration | Scope | Google class | Notes |
|---|---|---|---|
| Google Drive | `drive.file` (default) | Non-sensitive | Per-file access; **no verification needed**. Full-Drive read is opt-in via the `wp_mcp_ai_google_drive_oauth_scope` filter and requires Path 3. |
| Google Calendar | Minimal profile (default) | Non-sensitive | NV oOS manages its own dedicated calendar; **no verification needed**. |
| Google Calendar | Standard / Full profiles | Sensitive | Requires sensitive-scope review (~3–5 business days); choose only if you need your existing calendars. |
| Gmail | `gmail.readonly` | **Restricted** | Reading mail is the one scope that forces Path 3 for multi-user use; Paths 1–2 avoid the assessment. |
| Google Chat | Service account `chat.bot` | Exempt | No user OAuth at all — nothing to verify. |
| Google Analytics (GA4) | Service account (JSON key) | Exempt | No consent-screen scope — do not add `analytics.readonly` to the consent screen; GA4 works without it. |

## Privacy policy template

Google requires a publicly accessible privacy policy on the **same domain** as your site's home page, and for restricted scopes it must satisfy Google's **Limited Use** requirements. Adapt this:

> **Google user data disclosure**
> Our site connects to Google services (Gmail, Drive, and/or Calendar) at your request to enable [describe feature: e.g. "searching your email", "storing reports in Drive"]. Access is granted by you through Google's consent screen and is limited to the scopes you approve. Data retrieved from Google is used **only** to provide these features, is stored on our servers for as long as the connection is active, and is **not** used for advertising, sold to third parties, or reviewed by humans except to maintain security, fix errors, or where you request support. You may revoke access at any time: disconnect the integration in NV oOS (which also revokes the token with Google) or remove the app at [myaccount.google.com/permissions](https://myaccount.google.com/permissions). To delete stored Google data, [describe: e.g. "use the integration's delete/clear action or contact support at <email>"].

## Troubleshooting

- **"This app is blocked"** — you published to production before verification, or requested a restricted scope with no exception. Return to Testing status or complete Path 3.
- **`redirect_uri_mismatch`** — the Authorized redirect URI in Google Cloud Console must match the one shown in NV oOS exactly.
- **No refresh token returned** — revoke the app under your Google Account permissions and reconnect with `prompt=consent` (NV oOS always sends it).
