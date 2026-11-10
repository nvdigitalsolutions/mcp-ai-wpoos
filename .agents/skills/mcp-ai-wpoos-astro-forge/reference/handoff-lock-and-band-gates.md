# Per-band verification + the handoff lock (2026-11-10)

Two capabilities added after the virtuallyjamaica rebuild: a gate that judges
**each band** (the page-level gates all passed while bands rendered empty and
mis-painted), and a **handoff lock** so custom development can take a forged
site over without the next regenerate destroying it.

## 1. `verify` runs a per-band audit (`lib/band-audit.ts`)

Runs by DEFAULT in `verify` (opt out with `--no-bands`). Output: a table plus
`<target>/verify-artifacts/bands.json`, and a `bands` entry in the scorecard.

For every band the profile declares (Elementor sections, or the promoted
outline), six independent checks:

| check | question | needs |
|---|---|---|
| presence | did the band render? (by id, or positionally for outlines) | built HTML |
| surface | does it carry the baked bg / photo / padding? | profile `design.sections` |
| content | is it non-empty? (visible chars, or media/controls) | built HTML |
| facts | does every declared band fact leave its marker? | `lib/fact-audit.ts` |
| text | does the band's source copy survive? | `analyze --text` per-band baseline |
| geometry | is the box within tolerance? | `analyze --geometry` baseline |

Statuses are `pass | warn | fail | skip`. **A check that cannot be evaluated is
SKIPPED, never passed** — silently widening a threshold to force green is the
failure mode every visual-regression guide warns about. A photo-only band is
legitimately empty (the surface IS the band), so `content` passes on media even
with zero copy.

Flags: `--bands-warn` (report, do not fail), `--bands-json <path>`.
`forge status` prints the same table beside the ownership record.

**Why per band, not per page:** the industry guidance (Percy/BrowserStack,
Playwright, Chromatic) is consistent — capture and judge a SECTION at a time;
component-scoped failures are smaller, faster and far more actionable than one
full-page diff, and a per-section baseline is what makes "the words went
missing" visible at all. The baseline is the committed profile (the same
single-source-of-truth pattern as a `tokens:check` CI gate).

Workflow when a band fails:

```sh
node scripts/forge.mjs verify sites/<site>.json --target ./<site>-frontend --minimal
# inspect verify-artifacts/bands.json, fix the band, re-run
```

The per-band `text` check only runs when `analyze --text` recorded per-band
baselines. Outline-only sources frequently have none (`text.sections: []`) —
the check says `skip`; the page-level `verify --text` still covers their copy.

## 2. The handoff lock (`lib/lock.ts`, `forge lock|unlock|status`)

A forged project is FORGE-OWNED: `scaffold --overwrite` regenerates it and
`npm run check` fails on any drift. That is correct while the forge is the
author and wrong the moment a developer takes the site over.

After sync, transfer ownership explicitly:

```sh
forge lock  sites/<site>.json --target ./<site>-frontend \
            --by "Developer name" --reason "custom dev handoff"
forge status sites/<site>.json --target ./<site>-frontend     # who owns it + drift + bands
forge unlock sites/<site>.json --target ./<site>-frontend --yes
```

`forge.lock.json` (project root) records `version, generator, site, source,
lockedAt, lockedBy, reason, fingerprint` — `fingerprint` is sha256 of every file
the forge manages at handoff (it reads `nvoos.generated.json`'s file list, else
walks the tree). The profile also gets `locked: { at, by, reason }` so CI can
refuse to re-forge (`--no-profile` keeps it project-only).

What changes while locked:

| surface | forge-owned | LOCKED |
|---|---|---|
| `scaffold --overwrite` | regenerates | **refuses** (names the owner) — `--unlock` to force |
| project `npm run check` | fails on any drift | reports what changed since handoff, exits 0 (`--strict-lock` restores failure) |
| `forge status` | "forge-owned" | owner record + edited/added/removed lists |
| `forge unlock` | n/a | interactive confirm, `--yes` required non-interactively |
| CI (`forge-sites.yml`) | builds + mirrors | **skips** the site (`::warning` + run-summary line); `workflow_dispatch` `force_unlock: true` overrides |

**Two guards, two carriers.** `scaffold()` refuses when the TARGET carries
`forge.lock.json` *or* when the PROFILE carries `locked`. The CI runner scaffolds
into a fresh temp target every run, so `forge.lock.json` is never present there —
the committed profile is the carrier (`forge lock` writes `locked: { at, by,
reason }` unless `--no-profile`). `forge deploy` and `forge wizard` honour the
same guard and both accept `--unlock`.

**Skip, do not abort.** `forge-sites.yml` loops over sites under
`set -euo pipefail`; failing on a locked site would abort every site after it.
The workflow therefore warns, records the name, and `continue`s — then prints a
`Skipped (locked for hand-editing)` line and a run-summary section, so a green
job that deployed nothing is visible as such rather than mysterious.

The probe is defensive by construction: `$(node -e '…' 2>/dev/null || true)`
accepts `locked: { at, by, reason }` **and** `locked: true`, prints nothing for
an unlocked/broken/missing profile (a malformed profile fails loudly later at the
scaffold step), and can never abort the run from the probe itself.

Design rules that matter:

- **Fail closed.** A corrupt `forge.lock.json` still blocks regeneration
  (`readLock` returns a record with `reason: 'lock file is unreadable'`).
- **The fingerprint is provenance, not permission.** Hand-edits are expected
  after handoff; the lock exists so an operator can diff them and so the next
  regenerate cannot silently destroy them.
- **Unlock is destructive and explicit.** Non-TTY runs must pass `--yes`.
- The lock is the codegen-ownership convention (Go's `// Code generated … DO
  NOT EDIT`, scaffolders that declare ownership and refuse to overwrite
  user-owned files, lockfiles only the owning tool updates) applied at the
  whole-project level: ownership is DECLARED, regeneration is fail-closed, and
  the user-owned state is never overwritten silently.

## 3. Staging origins: `ASTRO_SITE` ≠ `WP_SITE_URL`

A staging deploy exposed the two-origin rule the hard way.

| variable | meaning | staging value |
|---|---|---|
| `WP_SITE_URL` | where WordPress lives (content source) | the CMS host — unchanged |
| `ASTRO_SITE` | public origin of the BUILT site (canonical, `og:url`, sitemap, absolute links) | the host the build is served on |

A build served on a staging host must self-canonical to that host, or every
canonical points at a domain you are not on. And a staging host that
self-canonicals **must not be indexable** — otherwise Google attributes the
preview domain as a duplicate of the production site.

`NVOOS_FORGE_NOINDEX=1` (added 2026-11-10) closes both:

- `<meta name="robots" content="noindex, nofollow">` (Base.astro, per page)
- `robots.txt` → `User-agent: * / Disallow: /`

It is deployment-level and read at render time, so the same artifact stays
indexable in production when the flag is unset. Verified on the Cloudways
staging host: canonical + `og:url` + sitemap `<loc>` all followed `ASTRO_SITE`,
and robots flipped to `Disallow`.

Cutover checklist: set `ASTRO_SITE` to the production origin, drop
`NVOOS_FORGE_NOINDEX`, rebuild, then repoint DNS. Leave the profile's
`deploy.publicOrigin` at production and pass the staging origin per environment
(`FORGE_SITE_URL` in the emitted pipeline) — one profile, two deploys.

## 4. Industry basis

- Playwright visual comparisons: `threshold` 0.2 (per-pixel YIQ),
  `maxDiffPixelRatio` 0.01, seed the baseline, `updateSnapshots: none` in CI,
  mask rather than loosen (playwright.dev/docs/test-snapshots).
- Percy / BrowserStack: keep captures focused on components/sections; review
  baselines like code reviews.
- Chromatic / ScreenshotEngine: component-level captures are smaller, faster
  and give clearer failures than full-page diffs.
- Token governance: one source of truth + a CI drift gate (`tokens:check`)
  fails on any drift (Style Dictionary / design-token CI practice).
- Codegen ownership: declared ownership, generated-only regeneration, never
  overwrite user-owned files.
