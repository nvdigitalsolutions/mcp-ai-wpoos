# Manage sign-in — three doors, five error codes

Extracted from the skill body on 2026-11-26.

## Manage sign-in (Proposal 165, hardened 2026-11-26)

Three doors, tried in order. Each failure has its own `?error=` code, and the
code names the layer — read it before touching the credential.

| Code | Meaning | Fix |
|---|---|---|
| `config` | no signing key, so no session can be minted | set `NVOOS_MANAGE_SECRET` (or the gate secret); a scaffolded app otherwise provisions `.forge-gate-secret` (0600) itself |
| `origin` | the browser Origin is not a host the app believes it serves | `security.allowedDomains` + the `x-forwarded-host` pin (below) |
| `credentials` | WordPress rejected the credential | wrong password, or app passwords disabled by a security plugin |
| `permission` | authenticated, but no `edit_posts` / `manage_astro` | wrong account |
| `upstream` | the CMS did not answer, or outran the client cap | slow CMS vs the 8 s timeout |

- **Two credential planes** (already documented in `lib/gate.ts`): WordPress
  accepts HTTP Basic only for Application Passwords, and the ACCOUNT password
  needs `wp-login.php`. The route tries Basic, then wp-login, and probes
  `edit.php` (200 = may edit posts) for the capability.
- **The signing key is resolved twice** — `src/lib/manage-session.ts` signs and
  `forge-gate/runtime.mjs` verifies. Keep the recipe and the provisioning
  byte-identical, or every session silently fails to verify. Both provision
  only when `gate.generated.json` exists, so library consumers and unit tests
  keep failing closed.
- **Behind a proxy, `security.checkOrigin` is the trap.** Astro compares the
  browser Origin with `Astro.url`, and `Astro.url` honours `x-forwarded-host`
  only when `security.allowedDomains` matches it. The scaffold names the public
  origin (apex + www) and `server.js` pins the header from `ASTRO_SITE`.
- **Never set `checkOrigin: false`.** `api/forms/submit.ts` carries no origin
  guard of its own, so that would drop CSRF protection from the public contact
  form. The scaffold keeps the check and makes it correct instead.
- **The bridge needs nothing for this.** `POST /wp-json/nvoos-commerce/v1/auth/login`
  (`wp_signon`, email + password), `GET /auth/me` (`canManage` = `manage_astro`)
  and `GET /auth/nonce` already exist — do not add a sign-in endpoint.
