---
type: Skill
name: mcp-ai-wpoos-spa-rest-contract
description: Audit NV oOS console SPA clients (addons/pro/assets/spa-v2/src/api/*.ts) against their WordPress REST controllers and fix contract drift — phantom fields the server never returns, params the server ignores, routes/methods that don't exist, envelope mismatches ({success,data} vs direct object vs bare array), field-name drift (message/sender vs content/contact_name), and settings gates (rest_enable_assistant_create). Covers the audit path (config key → PHP emission → route → handler → response shape), fix decision rules (client-align vs server-extend vs promote-the-gatherer), the known-good baseline from the 2026-10-21 audit, the parallel sub-agent delegation pattern with disjoint write scopes, and the full validation matrix. Use when a console page shows blank or wrong data, when adding a console surface, or when asked to "audit the REST contracts".
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "2.4.0"
  plugin-version-tested: "2.4.0"
  last-updated: "2026-10-21"
---

# NV oOS Console SPA ↔ REST Contract Audit

Playbook for keeping the console SPA clients (`addons/pro/assets/spa-v2/src/api/*.ts`)
in contract parity with the WordPress REST controllers they consume. Canonized
from the 2026-10-21 audit (PR #7166) that found **nine** mismatches, including
the assistants edit form being completely broken (no GET/PUT single route;
the form read fields the directory never returned).

## When to use this skill

- A console page renders blank/wrong/empty fields (the classic symptom)
- Adding or porting a console surface (Proposal 084) — audit before shipping
- Asked to "audit the REST contracts", "check for mismatches", or "continue
  the contract parity pass"
- Reviewing a new `api/*.ts` client against its controller

## The bug classes (all real, from the 2026-10-21 audit)

1. **Phantom fields** — client reads what the server never returns.
   `assistants.ts` read `system_prompt` + `capabilities` from the directory
   summary, which only has `tools`/`description`. Symptom: form fields always
   empty.
2. **Ignored params** — client sends what the server ignores. The assistant
   form posted `capabilities`; the create handler accepts `tools`.
3. **Missing routes/methods** — client called `GET /assistants/{id}` /
   `PUT /assistants/{id}` / `GET /slash-commands/list` — none registered.
   Symptom: 404 toast on save.
4. **Envelope mismatch** — create returns the directory summary directly
   (201) but the client unwrapped `{success,data}` → `{}`. Also: bare-array
   list responses vs `{items}` envelopes vs `{success,data}` wrappers — all
   three exist across the codebase; check each.
5. **Field-name drift** — chat-channels client typed `message`/`sender`;
   server emits `content`/`contact_name`. The vitest contract test baked in
   the phantom fields, so it passed while the UI rendered blank.
6. **Settings gates** — `rest_enable_assistant_create` (default **off**) 403s
   the console "Add Assistant" until enabled in Settings → NV oOS →
   Authentication. Always check the permission callback for settings gates.
7. **Query-string typing** — `filters` sent as a JSON query string vs server
   arg `type => 'object'` → WP 400 `rest_invalid_type` (sanitize runs before
   validate; without a `sanitize_callback` a string never passes object
   validation).

## Audit path per client (4 hops, verify every hop)

1. Client: endpoint key + methods + request fields + parsed response fields.
2. Config emission: `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php`
   (what URL the key actually gets; note `$is_admin && ! $guest` localization).
3. Route: `includes/rest/*.php`, `addons/pro/includes/rest/*.php`,
   `includes/class-wp-mcp-ai-rest.php` — method + permission + arg schemas.
4. Handler: the exact response array. Quote decisive lines; don't trust the
   docblock.

## Fix decision rules

- **Server is the source of truth** when other consumers exist (chat drawer,
  MCP clients, wp-admin) — align the client and update its vitest contract
  test to the real field names.
- **Server-extend** when the seam is cheap and admin-only: add the field to
  the existing response (teams `description`, presets `icon`, records
  research `title`), or add the route when the console legitimately needs it
  (assistants GET/PUT — full record incl. system prompt, `manage_options`-
  gated; PUT accepts the create handler's field names).
- **Promote the gatherer** — never re-implement a query in a controller;
  expose the wp-admin page's private gatherer as a public static seam.
- **Client-derive** for presentational data with no server source (tools
  `category` from the slug prefix; verb-prefix set falls back to "Core").
- REST callbacks return `rest_ensure_response(...)` (or WP_Error) — raw
  arrays break `->get_data()` consumers and are wrong for WP core.

## Known-good baseline (2026-10-21 audit)

Verified contract-clean: settings, security, diagnostics, operations,
token-manager (credentials controller), orchestration-stats, pro-ops,
schedule-manager (+ presets), portability, approvals, hitl, chatProfile,
memory, threads, transcripts, toolShortcuts, slashCommands (chat drawer),
triggers, okf, workflow-builder, workflow-presets, records list, media
(wp/v2), config envelope. Re-audit only if one of these changes.

## Parallel delegation pattern

Split the client list into disjoint groups and spawn read-only audit
sub-agents; keep one coordinator for fixes + final gates.

- Disjoint **write** scopes if agents fix too; never let two agents edit the
  same file (e.g. the big `includes/class-wp-mcp-ai-rest.php`).
- Never run Docker PHPUnit concurrently (shared `wordpress_test` DB) — the
  coordinator runs the PHPUnit matrix at the end.
- Never run bundle rebuilds concurrently (shared `assets/dist/` +
  `dist/`) — coordinator rebuilds once after all spa-v2 edits.
- Agents verify with `php -l`, per-file phpcs, narrow `npx vitest run <file>`,
  `npm run typecheck`; they do NOT commit, push, or touch dist/.

## Validation matrix (coordinator)

- phpcs: `phpcbf <files>` then `phpcs --standard=phpcs.xml.dist
  --error-severity=1 --warning-severity=8 --report=summary <files>`; EXIT 0
  clean. Ignore false-positive warnings on `meta_key`/`tax_query`/
  `self::POST_TYPE` string literals. phpcbf edits the bind-mounted file —
  re-run `php -l` on the host afterwards.
- PHPUnit on **both** WP 6.9 and WP 7.1 (Docker commands in
  `mcp-ai-wpoos-test-suite`; use the `mcp-ai-wpoos-vendor` volume).
- spa-v2: `npm run typecheck`, `npx vitest run` (full), `npm run lint:a11y`,
  then `npm run build` (esbuild).
- Console: `npx tsc --noEmit`, `npx vitest run`, `npm run build` (vite) in
  `examples/nvoos-complete-velocity`; commit the hashed dist renames.
- Tests that need real rows must create real posts (tools validate via
  `get_post()`); zero-network envelopes are the norm.

## Gotchas

- Contract tests that bake in phantom fields **mask** real drift — rewrite
  them from the server's actual response, not the client's types.
- `_fields` filtering is applied after caching in the assistants/tools list
  handlers; don't assume a filtered key exists in the cached shape.
- Two token-manager controllers coexist (`/token-manager/*` used by the SPA
  vs `/users/{id}/token-tier`) — don't patch the wrong one.
