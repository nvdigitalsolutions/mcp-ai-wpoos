# Proposal 118 — Vision-verified rebuilds for the Astro Forge

Status: implemented (2026-11-06) · PR against `alpha-working` · Forge half only

## Problem evidence (the jayainc session)

The forge's only verification layer was the 114 language-parity gate —
`forge verify` re-extracts the BUILT site's CSS bundle and scores it against
the source design (palette roles, type families, scales, concept). On
jayainc that gate was **green while the site looked wrong**: the palette was
faithful (same accent, same grays) but the rebuild had flattened the layout
(product cards lost their captions, icon features became paragraphs, the
hero photo and the navy bands were gone). Parity of extracted language ≠
visual parity. Proposal 117 fixed the known losses structurally, but the
verification gap remains for every future flattening: the forge cannot SEE
its own output.

Three related frictions surfaced in the same session:

1. **Image hosting for vision is the hard part.** The session tried to feed
   screenshots to an external assistant's vision via WordPress.com mShots
   (blocked, 403) and `gh gist` (binaries refused) — and a placeholder
   thumbnail produced a hallucinated generic inventory. The lesson: images
   must travel **in the request body as base64 data URLs**, never as hosted
   URLs; every OpenAI-compatible vision API accepts that form.
2. **The forge must not take a browser dependency.** Playwright adds
   hundreds of MB and platform browsers for one optional feature; the forge
   package stays lean. Captures therefore come through a contract (endpoint
   or dropped artifacts), not a bundled browser.
3. **`analyze --write` clobbered hand-tuned profiles.** Re-running the
   design analysis drafts a NEW profile; writing it over `sites/jayainc.json`
   would have destroyed the curated collections/tokens/frontPage. The merge
   behavior is now the default.

## Design

### §1 `lib/vision.ts` — the vision layer (zero deps)

- `visionConfig(env)` — `NVOOS_FORGE_VISION_BASE_URL` (default
  `https://api.openai.com/v1`), `NVOOS_FORGE_VISION_KEY`,
  `NVOOS_FORGE_VISION_MODEL` (default `gpt-4o-mini`). Undefined without a
  key → callers skip.
- `visionRun` / `visionCompare` — one chat-completions request carrying the
  prompt + images as **data-URL `image_url` parts**, `response_format:
  json_object`, bounded timeout. Returns a typed outcome
  (`{ ok, verdict }` / `{ ok: false, error }`) — **never throws, never fails
  a build**.
- `parseVerdict` — JSON parse with fenced-JSON and prose fallbacks.
- `COMPARE_PROMPT` — the strict-JSON side-by-side review contract:
  fidelity 0–100, layout/palette/typography match flags, a concrete
  divergences list ("do not invent divergences").

### §2 `lib/capture.ts` — the screenshot contract (browser-free)

- Seam 1: `NVOOS_FORGE_CAPTURE_URL` — a capture service accepting
  `POST /capture { url, width, label }` → `{ image }` (data URL or
  `{ base64, mime }`). Covers REMOTE sources only (a remote endpoint cannot
  reach the local build).
- Seam 2: dropped artifacts — `<target>/verify-artifacts/<label>.{png,jpg,jpeg,webp}`
  placed by the operator, an agent with browser tooling, or a CI step.
  Works for localhost builds; deterministic; zero network.
- `normalizeImagePayload`, `readArtifact`, `listArtifacts` round out the
  contract. Missing artifacts yield `undefined` — advisory by contract.

### §3 `analyze --write` merges by default

`mergeProfileDraft(existing, fresh)` — hand-tuned fields survive
(collections incl. excludes/caps, `frontPage`, `tokens`, `forms`, name/url);
the ANALYZED blocks refresh (`design`, `vocabulary`, `inventory`,
`wireframes`); missing fields fill from the draft. `--fresh` forces a clean
draft. `analyze --write` now reports `MERGED into` vs `written to`.

### §4 `forge verify --visual` — the visual layer

After the deterministic parity gate: resolve the vision config (skip with a
precise instruction when unset), obtain the source screenshot (endpoint →
dropped artifact) and the built screenshot (dropped artifact; `--serve
<port>` boots `astro preview` detached so the operator/agent can capture;
`--wait <s>` polls for the drops), run `visionCompare`, print the fidelity +
divergences, write `verify-artifacts/visual-audit.json`.

Gating stays opt-in: `--strict-visual [--min-fidelity 70]` fails the
command on a missing comparison or a low score. The default run remains
advisory — vision is a review layer, not a build gate.

## Boundaries (deliberate)

- Vision NEVER runs inside the build — scaffolds/builds stay deterministic,
  offline-capable, hermetic for CI/e2e.
- The forge package stays browser-free and LLM-dependency-free (global
  fetch only; any OpenAI-compatible endpoint, including Ollama/LM Studio
  locally or a bridged NV oOS assistant).
- The vision verdict informs the operator; the classifier stays
  deterministic (vision does not silently re-shape content).

## Future

- Baseline diffing (source screenshot committed per site → drift detection
  without a vision model) — a pixel/structural distance pass.
- Chrome extraction (header/footer/hero overlay from the rendered source) —
  the remaining structural gap, independent of vision.

## Verification

367 unit tests (new: vision request shaping + fail-soft contract, capture
endpoint/artifacts, merge semantics) · tsc clean · the e2e exit gate is
unaffected (the new paths are verify-time only) · CLI smoke: parity PASS
86% on the rebuilt jayainc, `--visual` fails soft without credentials.
