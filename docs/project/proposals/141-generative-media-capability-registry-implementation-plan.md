# Proposal 141 — Implementation plan (generative-media capability registry)

Companion to [`141-generative-media-capability-registry-proposal.md`](./141-generative-media-capability-registry-proposal.md).
Status: **complete** (2026-11-11), all phases green.

---

## 1. Scope

In:

| File | Kind | Purpose |
|---|---|---|
| `includes/data/media-model-catalog.json` | new | 12-model capability registry |
| `includes/class-wp-mcp-ai-media-model-catalog.php` | new | loader, query, validate, build, recommend |
| `includes/class-wp-mcp-ai-prompt-craft.php` | new | deterministic prompt assembly + identity lock |
| `includes/tools/class-wp-mcp-ai-tool-media-model-capabilities.php` | new | `media_model_capabilities` tool |
| `includes/tools/class-wp-mcp-ai-tool-enhance-media-prompt.php` | new | `enhance_media_prompt` tool |
| `includes/class-wp-mcp-ai-tool-registry.php` | edit | register both tools |
| `includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php` | edit | derive schema enums from the registry (P7) |
| `includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php` | edit | derive schema enums from the registry (P8) |
| `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php` | edit | derive schema enums from the registry (P9) |
| `includes/tools/class-wp-mcp-ai-tool-generate-omni-video.php` | edit | derive schema enums from the registry (P9) |
| `includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php` | edit | derive schema enums from the registry (P9) |
| `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php` | edit | add both to the `media_generation` preset |
| `tests/tools/.coverage-manifest.txt` | regen | via `bin/generate-tool-coverage-manifest.sh` |
| `includes/data/README.md`, `includes/tools/README.md` | edit | folder README accuracy |
| `tests/test-media-model-catalog.php` | new | 19 tests |
| `tests/test-higgsfield-video-catalog-parity.php` | new | 8 tests |
| `tests/test-higgsfield-image-catalog-parity.php` | new | 8 tests |
| `tests/test-media-tool-catalog-parity.php` | new | 8 tests (Veo / Omni / Sora) |
| `tests/test-prompt-craft.php` | new | 15 tests |
| `tests/test-tool-media-model-capabilities.php` | new | 13 tests |
| `tests/test-tool-enhance-media-prompt.php` | new | 15 tests |
| `tests/fixtures/media-model-catalog-fixture.json` | new | string-duration + fixed-param fixture |

Out (deliberate, see proposal §4): merging into `model-catalog.json`, rewriting media-tool **payload construction**, any provider call, any new option/table/credential.

### 1.1 Ordering

```
P1 registry JSON ──▶ P2 catalog service ──▶ P4 tools ──▶ P5 registration
                            │                              │
                            └──▶ P3 prompt craft ──────────┘
                                                           ▼
                                              P6 drift/validation gates
                                                           ▼
                                    P7 video-tool schema derivation
                                                           ▼
                                    P8 image-tool schema derivation
                                                           ▼
                                    P9 Google/OpenAI schema derivation
```

P4 depends on P2 (validate/recommend) and P3 (style enum in the capabilities schema).

## 2. Phases

### P1 — registry data — **done**

12 entries: 2 Higgsfield image (`soul-2`, `soul-cinema`), 5 Higgsfield video (`cinema-studio-4.0`, `seedance-2.5`, `seedance-2.0`, `wan-3.0`, `kling-3.0`), 3 Google (`omni`, `veo-3.1`, `veo-2.0`), 2 OpenAI (`sora-2`, `sora-2-pro`).

Every value is **transcribed from code already in the tree**, not from vendor marketing:

| Source | Fields taken |
|---|---|
| `WP_MCP_AI_Tool_Generate_Higgsfield_Video::VIDEO_MODELS` | duration min/max, resolutions, aspects, `refs.*` |
| `WP_MCP_AI_Tool_Generate_Higgsfield_Image::IMAGE_MODELS` + `ALLOWED_ASPECT_RATIOS` | endpoints, resolutions, aspects, batch sizes |
| `generate_veo_video` / `generate_omni_video` / `generate_sora_video` schemas | duration min/max, aspect enums, resolution enums, `maxItems` |

Selection metadata that no tool declared (`tier`, `styles`, `audio`) was authored conservatively and documented in the file's `field_notes`.

### P2 — catalog service — **done**

Static, no singleton, no I/O beyond one `file_get_contents` + `json_decode` per request. Public surface as designed in proposal §3.2, plus `normalize_id()` (see trap 1).

Semantics locked by test:

- empty `resolutions` / `aspect_ratios` / `batch_sizes` ⇒ **the model exposes no such parameter** ⇒ validation drops the argument;
- out-of-enum but numeric-bounded (`duration`) ⇒ **clamp + warning**;
- out-of-enum closed set (`aspect_ratio`, `batch_size`) ⇒ **drop + record**;
- too many reference images ⇒ **truncate + warning**;
- unknown keys ⇒ **forwarded untouched** (the owning tool validates its own args);
- `build_payload()` additionally coerces `duration` per `duration.type` and injects `fixed`.

### P3 — prompt craft — **done**

`CAMERAS` (6), `LENSES` (11), `FOCAL_LENGTHS` (6), `APERTURES` (3), `STYLES` (9 + `none` passthrough), `QUALITY_TAGS` (3).

`compose()` order: base → style fragments → camera clause (camera, lens, focal, aperture) → quality tags → extra tags, de-duplicated case-insensitively.

`build_identity_lock()` numbers continuously across characters then items, so ordinals match send order; singular vs plural grammar is handled (`Reference image 1 shows…` / `Reference images 1-2 show…`).

### P4 — tools — **done**

`media_model_capabilities`: actions `list` / `describe` / `recommend` / `validate`. Read-only, offline, `read` capability flag only — no `external-api`, no `requires-credentials`, no `consumes-tokens`. `validate` is the operationally important one: a free pre-flight before a paid generation.

`enhance_media_prompt`: deterministic assembly; returns `enhanced_prompt`, `base_prompt`, `reference_instructions`, `reference_slot_count`, `applied[]`, `deterministic: true`. Zero tokens, zero network.

Both implement `WP_MCP_AI_Tool_Usage_Guidance_Interface` so the agentic loop can route to them, and both return the canonical envelope (never `success => false`).

### P5 — registration — **done**

- Both classes added to `WP_MCP_AI_Tool_Registry::load_default_tools()`, adjacent to the Higgsfield block.
- Both slugs added to the `media_generation` preset in `WP_MCP_AI_Tool_Presets_Helper::get_presets()`.
- `tests/tools/.coverage-manifest.txt` regenerated: **exactly 2 insertions, zero churn** — confirming the committed manifest was in sync before this change.

### P6 — gates — **done**

| Gate | Command | Result |
|---|---|---|
| Unit — catalog | `phpunit tests/test-media-model-catalog.php` | pass (19) |
| Unit — prompt craft | `phpunit tests/test-prompt-craft.php` | pass (15) |
| Tool — capabilities | `phpunit tests/test-tool-media-model-capabilities.php` | pass (13) |
| Tool — enhance prompt | `phpunit tests/test-tool-enhance-media-prompt.php` | pass (15) |
| Combined | all four files, one invocation | **62 tests / 522 assertions, 0 warnings** |
| Preset accounting | `phpunit tests/test-assistant-tool-presets.php` | **9 tests / 2,498 assertions**, all green |
| Registry contract | `phpunit tests/test-tool-registry-coverage.php` | **6 tests / 3,275 assertions**, green; none of the 45 pre-existing warnings reference the new tools |
| WPCS | `phpcs` on all 8 changed/new PHP files | clean |
| PHP 7.4–8.4 | `phpcs --standard=PHPCompatibilityWP` | clean |

Run in `oos-wp` (WP 6.9, PHP 8.3.31) with `--no-coverage`.

### P7 — video-tool schema derivation — **done**

`generate_higgsfield_video` no longer keeps its own copy of the model capability
lists. `get_parameters_schema()` now publishes values derived from the registry:

| Schema field | Before | After |
|---|---|---|
| `model.enum` | `array_keys( self::VIDEO_MODELS )` | `get_supported_models()` — the **intersection** of the const and the registry, in const order |
| `aspect_ratio.enum` | `self::ALLOWED_ASPECT_RATIOS` | `get_allowed_aspect_ratios()` — first-seen union across served models |
| `resolution.enum` | `self::ALLOWED_RESOLUTIONS` | `get_allowed_resolutions()` — same |
| `duration.minimum` / `.maximum` | literal `2` / `30` | `get_duration_bounds()` — min/max across served models |

`get_configured_defaults()` validates the saved `higgsfield_video_resolution`,
`…_aspect_ratio` and `…_duration` settings against the same derived values, so a
settings page that offers a value the schema rejects is no longer possible.

**Behaviour is unchanged.** The derived unions reproduce `ALLOWED_ASPECT_RATIOS`
(`16:9, 4:3, 1:1, 3:4, 9:16, 21:9, adaptive`) and `ALLOWED_RESOLUTIONS`
(`480p, 720p, 1080p, 4k`) in the same order, the bounds are still 2-30s, and the
model enum is still the same five ids in the same order — asserted by
`test-higgsfield-video-catalog-parity.php`, not assumed.

**Containment on divergence.** `get_supported_models()` returns the intersection,
so a registry entry the tool cannot serve is never advertised, and the schema
degrades to the const when the registry file is missing. That is deliberate: an
unservable model in the schema means `get_model_definition()` silently falls back
to the default model and the user gets a different model than they asked for.

**Both failure directions were watched before the phase was accepted:**

| Probe | Failed | Passed (containment) |
|---|---|---|
| Inject a registry-only `ghost-model` | `test_registry_advertises_no_unservable_model` | the other 7, incl. `test_schema_model_enum_matches_const_exactly` |
| Delete `kling-3.0` from the registry | `test_every_declared_model_is_registered` + `test_schema_model_enum_matches_const_exactly` | the remaining 5 |

The registry file was then restored byte-identically (md5 verified against the
pre-probe checksum).

Re-validated after restoration:

| Gate | Result |
|---|---|
| Six media suites in one invocation (parity + video tool + catalog + prompt craft + both new tools) | **85 tests / 615 assertions**, 0 warnings |
| Preset accounting | 9 tests / 2,498 assertions |
| Registry contract | 6 tests / 3,275 assertions |
| WPCS + PHPCompatibilityWP 7.4–8.4 on both changed files | clean |

### P8 — image-tool schema derivation — **done**

Same treatment for `generate_higgsfield_image`, which had **four** hardcoded
lists rather than three — including a `resolution` enum that was a bare literal
in the schema, and a `batch_size` list duplicated between the schema and the
payload clamp.

| Schema field | Before | After |
|---|---|---|
| `model.enum` | `array_keys( self::IMAGE_MODELS )` | `get_supported_models()` — const ∩ registry, const order |
| `aspect_ratio.enum` | `self::ALLOWED_ASPECT_RATIOS` | `get_allowed_aspect_ratios()` — first-seen union |
| `resolution.enum` | literal `array( '720p', '1080p' )` | `get_allowed_resolutions()` — union, fallback derived from `IMAGE_MODELS` |
| `batch_size.enum` | literal `array( 1, 4 )` | `get_allowed_batch_sizes()` — union, fallback `ALLOWED_BATCH_SIZES` |

Two cleanups fell out of the derivation:

- `const ALLOWED_BATCH_SIZES = array( 1, 4 )` now names the values that were
  duplicated as two separate literals (schema enum + `build_request_payload()`
  clamp). Both read the constant.
- The resolution fallback is computed from `IMAGE_MODELS` rather than restated,
  so a workflow's resolutions are still declared in exactly one place.

**Behaviour is unchanged** — the derived unions reproduce
`9:16, 16:9, 4:3, 3:4, 1:1, 2:3, 3:2`, `720p, 1080p` and `1, 4` in the same
order, and the model enum is still `soul-2, soul-cinema`.

The image parity suite adds a check the video one does not need: **per-model
field parity** (endpoint, resolutions, batch sizes). The schema publishes
unions, but the payload is built per model — so a union agreeing while
`soul-cinema`'s endpoint silently changed would still send requests to the wrong
place.

**Three probes, all watched failing:**

| Probe | Failed | Passed (containment) |
|---|---|---|
| Registry-only `ghost-image` | `test_registry_advertises_no_unservable_model` | the other 7, incl. the schema-enum test |
| `soul-2` endpoint rewritten in the registry | `test_per_model_fields_match_the_registry` | the other 7 |
| `soul-cinema` deleted from the registry | const→registry, per-model fields, schema enum | the other 5 |

The registry was restored byte-identically between probes and afterwards (md5
verified each time).

Re-validated after restoration:

| Gate | Result |
|---|---|
| Eight media suites in one invocation | **105 tests / 677 assertions**, 0 warnings |
| Registry contract | 6 tests / 3,275 assertions; none of the 45 pre-existing warnings reference these tools |
| WPCS + PHPCompatibilityWP 7.4–8.4 on both changed files | clean |

### P9 — Google/OpenAI schema derivation — **done**

`generate_veo_video`, `generate_omni_video` and `generate_sora_video` had no
per-model const at all — the schema literals were the only declaration. They now
derive from the registry, with the literal retained as the no-registry fallback.

| Tool | Derived fields |
|---|---|
| Veo | `model.enum`, `aspect_ratio.enum`, `resolution.enum`, `duration.min/max` |
| Omni | `aspect_ratio.enum`, `resolution.enum`, `duration.min/max`, `reference_images.maxItems` |
| Sora | `model.enum`, `aspect_ratio.enum`, `size.enum`, `duration.min/max` |

**The registry gained a real fact, not a workaround.** `omni` is owned by
`generate_omni_video` but is also selectable through `generate_veo_video`. A
single-valued `tool` field could not express that, so entries may now carry an
optional `tools[]`. `get_models( array( 'tool' => $slug ) )` matches either
field; `tool` remains the owner and still decides which tool's capability unions
the model contributes to. Registry version → `1.1.0`.

**Veo needed two model sets.** It forwards `aspect_ratio` to the service
*unclamped*, so folding Omni's 16:9 / 9:16 into the enum would advertise values
the native Veo path rejects — turning a schema value into a provider-side error
instead of a graceful fallback. Hence:

- `get_supported_models()` → `omni, veo-3.1, veo-2.0` (the `model` enum)
- `get_capability_models()` → `veo-3.1, veo-2.0` (the aspect/resolution/duration unions)

Both are consts (`MODELS`, `NATIVE_MODELS`) intersected with the registry, so the
split is declared and testable rather than inferred.

**No validated-tool drift.** Both Veo and Sora have `_validated` siblings;
neither declares Symfony `Choice`/`Range` constraints for these fields, so the
base-tool derivation cannot contradict them (verified by grep, and the validated
suites pass).

**Four probes, all watched failing:**

| Probe | Failed |
|---|---|
| `omni.tools` removed | `tools`-field assertion, Veo model enum |
| `veo-3.1` gains `16:9` | the widening guard (16:9 must be absent) **and** the Veo aspect enum |
| `sora-2-pro` deleted | Sora model enum |
| `omni.max_reference_images` 5→3 | Omni `maxItems` |

The registry was restored byte-identically between probes (md5 verified), then
re-validated:

| Gate | Result |
|---|---|
| Four parity suites, one invocation | **44 tests / 439 assertions**, 0 warnings |
| Existing tool suites (Sora, Omni, Veo duration ×2, Veo validated, Veo 1080p) | **46 tests / 176 assertions**, all green |
| Registry contract | 6 tests / 3,275 assertions |
| WPCS + PHPCompatibilityWP 7.4–8.4 | clean |

> **Noted, not changed:** the Sora `duration` description reads "5-20 for sora-2,
> 5-60 for sora-2-pro", but the schema bounds are 5-60 for both and nothing
> enforces a per-model ceiling. The registry mirrors the enforced bounds (5-60),
> so behaviour is unchanged. If sora-2 really caps at 20, that is a separate fix
> in the service layer, not a schema-derivation one.

## 3. Traps hit, and what they teach

**1. `sanitize_key()` silently mangles dotted model ids.** `cinema-studio-4.0` → `cinema-studio-40`, so `get_model()` missed and every downstream lookup returned "unknown model". Four tests failed on this single root cause. Fix: a dedicated `normalize_id()` (`[^a-z0-9._-]`) used for all lookups, mirroring `sanitize_key()`'s class plus dot. **Rule: never run a registry key through `sanitize_key()` unless you have proven the key alphabet excludes dots.** The test now asserts `normalize_id( $id ) === $id` for every entry, so a future id with a dot cannot regress silently.

**2. Drop records read a key they had just unset.** In both the `batch_size` and `seed` branches the code did `unset( $params[ $x ] )` and then passed `$params[ $x ]` into the drop record — an "Undefined array key" warning, and a `null` value in the agent-facing `dropped[]` payload. Caught only because PHPUnit surfaces PHP warnings (`--display-warnings`), not by the assertions as first written. Fixed by capturing `$raw_value` before mutating; the tests now assert the **reported value** equals what the caller sent, which is the actual contract the agent reads.

**3. The committed `vendor/` is a `--no-dev` install.** `vendor/bin/phpunit` dies with `Class "PHPUnit\TextUI\Application" not found` — the package sources are on disk but composer's autoload maps contain zero `PHPUnit` entries (`grep -c PHPUnit vendor/composer/autoload_psr4.php` → `0`). Per the test-suite playbook the remediation is a full `composer install`, which rewrites the shared worktree's vendor tree while other agents have uncommitted work. Used instead a throwaway classmap/PSR-4 autoloader (scan every `vendor/*/*/composer.json`, honour `autoload.psr-4` + `autoload.classmap` + `autoload.files`) that boots PHPUnit offline without touching `vendor/`. Deleted after the runs. **PHPUnit's own package declares `autoload.classmap: ["src/"]`, and its layout is *not* PSR-4** (`PHPUnit\Event\DispatchingEmitter` lives at `src/Event/Dispatcher/DispatchingEmitter.php`) — a naive PSR-4 guess gets you `TextUI\Application` and then fails at the first `Event\*` class.

**4. Single-file PHPUnit args need a basename that ends with the class short name.** `tests/test-prompt-craft.php` (class `Test_Prompt_Craft`) prints `Class test-prompt-craft cannot be found…` and executes nothing, because `TestSuiteLoader` compares lower-cased `str_ends_with( shortName, basename )` and never normalises `-` to `_`. Directory scans downgrade this to a warning, which is why CI is unaffected. Ran class-named temp copies and deleted them.

None of these were spec problems; all four would have shipped as silent production bugs (the first two) or as an unrunnable validation story (the last two).

## 4. Deliberate non-goals, restated

- `generate_gemini_image` and `generate_cloudflareai_image` still publish literal enums and have no registry entries. Every media tool that takes a **model id** is now converted; these two need their constraints declared somewhere reviewable before they can be derived.
- **Payload construction is untouched.** The converted tools derive their *schema* from the registry but still build requests from their own declarations, which carry data the registry does not model (`audio_field`, `audio_values`, `creative` and the per-kind `refs` map for Higgsfield video; `has_style` for images).
- No model was added whose constraints could not be traced to code in this repo. Gemini image and CloudflareAI image are therefore absent from the registry even though they are registered tools — adding them requires first declaring their constraints somewhere reviewable.
- `recommend()` is advisory. Every candidate carries `reasons[]`, the response sets `advisory: true`, and `tier: 'any'` / `style: 'any'` are expressible.

## 5. Follow-ups (not in this pass)

1. **Convert the remaining media tools' schemas.** `generate_gemini_image` and `generate_cloudflareai_image` still publish literal enums, and neither has registry entries yet — their constraints need declaring somewhere reviewable before they can be derived. Every media tool that takes a model id is now converted.
2. **Route payload construction through `build_payload()`.** The registry already models `fixed`, `duration.type` and reference caps. Moving the Higgsfield video tool's request building onto it would retire the const entirely — a behavioural refactor that needs its own proposal and a provider-level test.
3. **`cancel_after` / server-side deadline.** The one capability fal (`X-Fal-Request-Start-Timeout`) and Replicate (`Cancel-After: 5s–24h`) both have and NV oOS does not: a provider-side deadline so a timed-out job stops billing. Warrants its own proposal.
4. **Cost pre-flight.** `media_model_capabilities` returns limits but not price. Adding a `credits` field per model would let `validate` answer "what will this cost?" alongside "will it be accepted?".
5. **Gemini image / CloudflareAI image entries** once their constraints are declared in a reviewable place.
