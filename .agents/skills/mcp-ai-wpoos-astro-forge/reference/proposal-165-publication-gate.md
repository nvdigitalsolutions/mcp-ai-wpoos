# Proposal 165 — the publication gate (managed tier)

Extracted from the skill body on 2026-11-26 to stay under the load limit.

## Proposal 165 — the publication gate (managed tier)

A forged managed site can mimic Elementor's maintenance/coming-soon behaviour:
the public sees a holding page, a signed-in manager sees the real site.

- **Two gate modules, two different questions.** `lib/gate.ts` = how the FORGE
gets into a gated source. `lib/gate-publish.ts` + `lib/gate-runtime.ts` = how
the BUILT site behaves while the CMS is gated. Do not merge them.
- **`gate.generated.json` is always written** (even with the gate off) — it is
the runtime's BOOT state. It carries the verdict plus the non-secret runtime
config; the bypass token and the session HMAC are derived at runtime.
- **`trust: 'bridge'` (default) refuses to gate on HTML inference alone.** A
cached anonymous page looks gated to a logged-out probe; only the bridge's
`/wp-json/nvoos-forge/v1/gate` is authoritative. `trust: 'probe'` opts in.
- **`honor` can decline a mode** (`coming_soon-only`, `off`): the manifest keeps
the evidence but sets `honored:false`, `mode:'off'`, `page:null`.
- **The session format is a cross-module contract:**
`<exp>.<uid>.<base64url(name)>.<hmac>` with HMAC over `'manage.v1.' + payload`.
`lib/manage-runtime.ts` emits the signer, `lib/gate-runtime.ts` emits the
verifier. Change one without the other and every manager is silently logged out
of the preview. Pinned by `tests/gate-runtime.test.ts`.
- **The static handler must not clobber the gate's headers.** A bypassed request
carries `cache-control: private, no-store` from the gate; `createStatic` only
sets cache-control when the gate has not.
- **The static handler canonicalises a missing trailing slash** (2026-11-26,
#8330). `build.format 'directory'` writes `<slug>/index.html` and nothing else,
so the un-slashed form matches no route. `createStatic` probes `<path>/index.html`
and answers 301 to the slashed URL, query string preserved and the gate headers
untouched; a genuinely missing path still falls through to the router. WordPress
301s the same form, so without this every legacy inbound link 404s on the day a
site is forged. Pinned by `tests/gate-runtime.test.ts`.
- **Gate pages are PRERENDERED** (`src/pages/{maintenance,coming-soon}.astro`)
because the front controller answers before Astro; it reads
`dist/client/<page>/index.html` and falls back to a built-in page if stripped.

Full contract (reconstructed 2026-11-26 after a tree loss):
`docs/project/proposals/165-forge-maintenance-mode-parity-eca-runbook.md`.
