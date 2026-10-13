# Browser Agent (Dictated Web Control) — Enhancement Plan

**Goal:** dictate an instruction ("book that flight", "fill out this form with X",
"find the support phone number") and have a *local* LLM drive a real browser to
carry it out, with the dictation app as the host and safety/observability layer.

**Status:** Plan only. This is the research-and-design deliverable; no code has
been written.

---

## 1. Does it make sense? — Yes, with one sharpened definition

The instinct is sound and it maps directly onto machinery the app *already has*:

- A first-class **local LLM transport** (`LocalOpenAiProvider` → LM Studio /
  Ollama) — the "brain" for the agent, no cloud required.
- A **Stage-2 provider dispatch** pattern (`cleanup/providers`) that shows how to
  add a new local-model consumer cleanly.
- A **feature-gated module + worker-thread** convention (`camera`, `vision`) that
  is exactly how a `!Send`/`!Sync` browser driver must be hosted.
- An **IPC + Settings-tab + Diagnostics** convention to surface a new subsystem.
- A **safety/observability culture** (no facial recognition, destructive-ops
  gates, golden backstops) that transfers directly to browser automation.

The one thing to sharpen: **the app is currently an MCP *server*** (it serves
dictation *tools to* AI clients). This feature is the *opposite* direction — the
app *drives* a browser *using* an LLM. They can coexist, but keep the namespaces
separate: the agent is not an MCP surface; it is an internal module.

---

## 2. The critical architectural decision — which "perception" layer?

Industry consensus (the "three architectures" taxonomy) is that this choice
determines everything downstream. Three options:

| Approach | What the LLM sees | Cost/action | Auth | Anti-bot | Best for |
|---|---|---|---|---|---|
| **Vision** (screenshot → coords) | Pixels + bounding boxes | Very high (1k+ tokens/shot) | Fresh browser, no session | Highly detectable | Breadth; canvas/Electron |
| **Accessibility tree** (AXTree → refs) | Roles/labels + element refs | Low (~500 tokens) | Fresh browser | Detectable | Modern A11Y sites, scraping |
| **Runtime DOM** (extension, live session) | Compressed live DOM + inferred semantics | Low | **User's real session** | **Invisible (it *is* the user)** | Personal/work tools, real apps |

**Recommendation — hybrid, leaning on Accessibility-tree *and* Runtime DOM,
with Vision as an opt-in fallback.**

Reasoning:

1. Cheap & reliable is the default. The LLM reasons over a *filtered,
   index-numbered* element list (`[12] button "Submit"`), not screenshots. This
   is what Playwright MCP and browser-use converged on, and it keeps context
   small — critical because local models are context-hungry (see §5).
2. Auth matters for the user's stated goal. "What to do on a website" for a
   *personal* dictation app typically means *their* sites (email, banking, work
   tools). A fresh headless profile starts logged out. Options:
   - **Playwright headless with a persistent profile** (`--user-data-dir` → a
     Chromium profile dir): sessions persist, but still a *separate* automated
     browser (detectable, and a second set of passwords to maintain).
   - **CDP attach to the user's running Chrome** (`--remote-debugging-port` over
     the Chrome DevTools Protocol): drives the *user's actual* session via
     Playwright's `connectOverCDP`. This is the runtime-DOM ideal and the
     lowest-friction for authenticated work, at the cost of requiring Chrome to
     be launched with a debugging flag.
   - **Browser extension** (native runtime-DOM): the cleanest anti-bot +
     auth-native story, but a much larger scope (a whole extension + a
     WS/stdio bridge back into the Rust app).

   **Plan: ship Headless(+persistent profile) first (self-contained, no external
   deps), then CDP attach as Phase 2 for "operate *my* logged-in session", and
   treat the extension as a stretch/Phase-4.**

3. Vision is the fallback only. Keep a single "screenshot + describe" tool so
   the agent can recover when the tree/dome is empty (canvas apps, broken A11Y).
   It reuses the existing `describe_crop`-style multimodal path
   (`openai_shape::build_vision_body` + `LocalOpenAiProvider::describe_crop`).

---

## 3. The agent loop (ReAct, but with local-model hardening)

```
loop (budgeted):
  1. observe  → serialize current page (compact element index / filtered DOM)
  2. reason   → send task + observation + last N turns + one-shot tool schema
                to the local LLM; ask it to return EXACTLY ONE tool call
  3. act      → parse the tool call; execute it in the browser
  4. reflect  → append action + result to history
  stop on:  "done" tool call  |  budget exhausted  |  safety halt
```

Hardening required *specifically because the brain is a small local model*
(these are the documented failure modes from browser-use / local-LLM agent
postmortems):

- **Single-tool-call-per-turn.** Small models loop and hallucinate multi-call
  JSON. Force one action per response; the loop supplies iteration.
- **Concrete in-prompt examples of the exact action JSON shape.** Qwen-class
  models famously return `{"navigate": "google.com"}` (flat) instead of
  `{"navigate": {"url": "..."}}` (nested). Bake one correct example per tool
  into the system prompt — this is the single highest-leverage reliability fix.
- **Native structured outputs / JSON mode** where the local server supports it
  (`response_format: { type: "json_object" }`), with a *defensive parser* that
  strips markdown fences (``` ```json … ``` ```) — the app already tolerates
  fenced JSON in `parse_label_response`; reuse that tolerance.
- **Retry-with-feedback on parse failure**: feed the raw output + "that was not
  valid, here is the exact shape" back once before erroring out.
- **Max-steps + per-step + total timeouts** (mirror `wait_for_llm_ms` /
  `load_timeout_ms`). A hung local model must never wedge the app (the
  browser-use local-4B run that "never cleared step one" is the canonical trap).
- **Context budgeting**: filter the observable tree aggressively (drop inert
  `<div>`/`<span>`, style/class noise); a single rendered page can be ~10K
  tokens, and Ollama defaults to a 4K context below 24 GiB VRAM. Set a minimum
  ≥32K context (recommend 64K) in the Ollama/LM Studio guidance.

**Perception (observation) serialization** should expose, per element:
`index | role | accessible-name | tag | is-visible | is-editable | optional
value | href`, plus page `url` and `title`. Refs must be **backendNodeId- or
DOM-node-identity-derived, not positional** — `chrome-agent` uses Chrome's
`backendNodeId` (printed `n82`) specifically so a uid stays valid across
snapshots *of the same page* and the agent can inspect once then act several
times. Positional refs (`@e2`) break the moment the tree shifts; a navigable-
action ref (`nN`) survives until a real document navigation. **Re-snapshot on
any navigation; never after a no-op.**

Two more loop-hardening rules distilled from the `chrome-agent` verdict model
(the single most important reliability idea the reference impls surface):

- **Retain a per-action *verdict*, never just `ok`.** `ok:true` means "the
  command ran", **not** "the page complied". Every mutating action must read
  the page back and report a closed-set verdict the *loop* can branch on
  (e.g. `changed` / `navigated` / `intercepted` / `values_lost` / `no_effect` /
  `unknown`), plus the reason. The canonical trap: a click that hit an
  overlay, or a form that submitted and cleared itself, both look like
  success. `intercepted` (another element — a cookie banner, a modal — caught
  the click) and `values_lost` (a submit emptied the field) are the two the
  agent must be taught to recover from. On `unknown`, **never retry** (the
  first action may have already landed) — re-observe instead.
- **Separate the brain from the hands.** `chrome-agent` has a deliberate
  "no built-in model" stance: the caller (the LLM) supplies the reasoning;
  the tool supplies *observations, assertions, structured outputs, and
  measured verdicts*. This keeps the agent loop testable and lets the same
  executor serve a local 7B brain *or* the `nvoos` brain (§5a) unchanged.

---

## 4. Safety model (non-negotiable, matches the app's existing posture)

Industry standard is clear: **use the LLM as a *planner*, never an unrestricted
driver**, and gate the irreversible actions. Translate to this app:

1. **Read-only by default.** The agent starts without destructive tools in its
   schema. Read (observe/extract/scroll/focus/hover) is always on.
2. **Destructive / irreversible action classes require human confirmation:**
   - form **submit** (POST), button/click on `input[type=submit]` or anything
     with a `confirm`-like hint
   - any **purchase / payment / card field** (pattern-match on field
     `name`/`autocomplete`/label: `card`, `cvv`, `password`, `ssn`, `account`)
   - **file upload** and **download**
   - **navigation to a different origin** than the task's starting origin
   - **account deletion / permission grant** pages
   These surface through the existing overlay/IPC as an explicit "Approve /
   Deny" prompt (the app already has an overlay + push-to-talk UX).
3. **Origin allowlist per task.** The task declares a starting origin; crossing
   it mid-run halts for approval (not whole-session, just the boundary step).
4. **Sensitive-field blocker.** If an `extract` would read a password/SSN/card
   value, mask it (return `[redacted]`) in the observation — never ship the
   value to the model.
5. **Secret isolation.** The agent never gets the app's NV oOS credential or
   keychain tokens. Browser profile creds live in the browser profile, not in
   `config.json`.
6. **Audit + kill switch.** Every action is appended to a run log
   (`agents.runs` table or the existing diagnostics JSON), and there is a
   hard **Stop** that tears the browser driver down and halts the loop
   (already the pattern for the hook exit-safety + restart-capture controls).

> **Decision 2 (confirm):** safety capability flags should mirror the
> plugin's `get_capability_flags()` triad — see §4a.

---

## 4a. Plugin alignment — NV oOS (base + `addons/pro`) as the contract parent

NV oOS = the base plugin (`mcp-ai-wpoos`) + the Pro addon (`addons/pro`). The
plugin is PHP/WordPress; the dictate app is pure Rust. **Nothing is ported
line-for-line** — but the plugin already owns the *concept* of browser
automation via `addons/pro/includes/tools/capture/class-wp-mcp-ai-tool-web-browser.php`,
a single-shot LLM tool (not an agent) that proxies to a Playwright service
with a local HTTP-fetch fallback. To keep the two surfaces coherent, the
agent adopts two contracts from it:

1. **Action vocabulary derives from the plugin's `web_browser` enum.**
   The plugin's schema declares `navigate | screenshot | pdf | extract |
   click | type | submit`. The Rust `agent/tools.rs` enum is a **superset**:
   keep those names verbatim and add agent-only verbs (`wait`, `fill`,
   `scroll`, `hover`, `done`). Do not rename `type`→`fill` or `click`→`tap` —
   a dictated task must mean the same thing whether it reaches the plugin
   tool or the desktop agent. **`pdf` is in-scope for v1** (§7, via
   `Page.printToPDF`); only `merge`/`watermark`/`extract` (document ops, not
   browser actions) are deferred.
2. **Safety taxonomy mirrors the plugin's `get_capability_flags()`.** The
   base tool distinguishes `read-only` (reads data, no external mutation),
   `external-api` (makes outbound HTTP), and destructive-write classes, and
   is rate-limited + origin-guarded (`is_internal_url`). The app's
   `safety.rs` uses the **same three buckets** as its action classes, and
   its origin allowlist (§4.3) blocks the same private/loopback origins the
   plugin's `is_internal_url` blocks — so an assistant-driven run and a
   desktop-driven run can't disagree about what is safe.

**Explicitly NOT ported:** the Playwright-service-proxy pattern (the app
embeds CDP directly — no external service), `lib/core`'s tool-registry / SSE /
token infrastructure (the app has `rmcp` + its own storage/latency surfaces),
and any PHP.

---

## 5. Local-model constraints to plan *around* (from research)

- Small models (1–7B) **mostly fail at agentic multi-step tasks**; 7–8B
  tool-calling-tuned models (Qwen2.5/3-8B-Instruct, Llama-3.1-8B, DeepSeek
  tool-calling) are the realistic floor. The app should expose the provider +
  model + "recommended for agent" hint in Settings, and *default* the agent
  toggle OFF.
- **VRAM / context**: budget 4–8 GB VRAM beyond the model for agent context.
  64K context recommended. Surface `ollama ps` equivalent (processor split +
  allocated context) so a user can see *why* it's slow.
- **Verify, don't trust the `done` flag.** `is_done()` = "agent emitted a done
  action", not "task succeeded". The run result must include (a) the executed
  action list, (b) a **deterministic re-read** of the destination (re-query the
  page state independently of the model), and (c) a plain-language summary the
  user can sanity-check. The model is the writer, never the only reader.
- Keep the **hosted/`nvoos` provider as an escape hatch** for hard tasks — the
  `CleanupProvider` dispatch already supports `nvoos`; the agent should too.

---

## 5a. Agent brain providers — local **or** NV oOS (explicit reuse points)

The browser agent needs a *reasoning backend* that turns an observation into a
single tool call. This is the same role the Stage-2 polish provider plays, so
the agent should reuse the exact same machinery rather than invent a new LLM
path. Two supported brains, one trait:

### Shared shape

`NvoosProvider` and `LocalOpenAiProvider` already speak a common dialect via
`openai_shape` / the `/v1/chat`-style contract. The agent introduces a thin
**`AgentBrain` trait** that both satisfy:

```rust
async fn next_action(&self, prompt: &str + obs, history) -> AppResult<BrowserAction>;
```

Concretely the reuse is:

- **Local brain** → `LocalOpenAiProvider` (LM Studio `http://localhost:1234/v1`
  / Ollama `http://localhost:11434/v1`). Reuses `build_chat_body` for the loop
  and `build_vision_body` + `describe_crop` for the screenshot fallback. No
  network egress.
- **NV oOS brain** → `NvoosClient::polish(..)` under a purposely-generic prompt
  (it is currently typed for *transcript cleanup*; the agent repurposes it as
  the general chat path — same bearer contract, same
  `POST /wp-json/mcp-ai/v1/chat` endpoint, same `extract_reply_text` parser).

### Exact `NvoosClient` reuse points

| Method | Endpoint | Agent use |
|---|---|---|
| `test_connection()` | `GET …/assistants` | the Settings "Test connection" button (credential accepted?) |
| `polish(..)` | `POST …/chat` | the **brain**: session prompt + observation + history → next action |
| `archive_transcript(..)` | `POST …/chat-transcripts` | (optional) persist the run summary + action log back to the site |

### Why this matters for the user's goal

A 7B local model is the honest *floor*, not the ceiling. The agent's Settings
should expose the same **provider picker** the polish pipeline already has
(`disabled` | `lm_studio` | `ollama` | `nvoos`), so:

- **privacy-sensitive / easy tasks** → local brain (page content never leaves
  the machine);
- **hard multi-step tasks** → `nvoos` brain (your site's hosted GPT/Gemini/
  DeepSeek, via the `polish_provider`/`polish_model` overrides already threaded
  as `options.provider` / `options.model`).

### The one hard boundary

The NV oOS site is a **brain**, never the **hands**. It cannot drive
Playwright/CDP for you — the browser runs only on the machine with the browser
installed (this app's own `agent/` module). And because turning on the `nvoos`
brain means *observation text and page content get uploaded to your remote
site*, the §4 safety rules must treat it identically to a local brain:
**sensitive-field masking (`[redacted]`) and secret isolation apply before the
observation ever reaches either provider** — no password/SSN/card value may
ship to the local model *or* to the site.

---

## 6. Proposed phased implementation (maps to app conventions)

### Phase 0 — De-risk spike (like `vision_probe.rs`) — ✅ DONE (2026-10-13)
`examples/agent_probe.rs`, feature `agent`:
- Drive a local Ollama/LM-Studio model through the *existing*
  `LocalOpenAiProvider` to produce one tool call from a canned observation.
- Assert: (a) a tool call is emitted (not prose), (b) the action JSON shape
  parses, (c) the loop reaches `done` within the step budget.
- Isolates the "can my local model drive a tool loop at all?" question before
  any browser code is written (mirrors the browser-use local-4B lesson).
- **Shipped seam:** added `LocalOpenAiProvider::complete(system, user, temp)`
  (generic prompt→text, the agent's building block) and a matching
  `CleanupProvider::complete` trait method (default `NotConfigured`;
  `lm_studio` + `ollama` forward; `nvoos`/`openai_compat` pending §5a).
  `summarize` now delegates to `complete`. The spike runs through the *public*
  `OllamaProvider`/`LmStudioProvider` → `CleanupProvider::complete` path — the
  same seam Phase 1's `AgentBrain` will call.
- **Live run (reference PC, 2026-10-13):** `cargo run --features agent --example
  agent_probe -- --provider ollama` — the `--provider`/`--model`/`--task`/
  `--steps` flags map to the decisions in §8. Run it against a real Ollama
  model (e.g. `qwen2.5:7b-instruct`) to establish the baseline; the spike
  prints raw reply + parsed action per turn and exits non-zero unless all
  three gates pass (CI-scriptable).

### Phase 1 — Browser driver module (`src-tauri/src/agent/`) — ✅ CORE SHIPPED (2026-10-13)
New module `agent/` (feature `agent`), mirroring `vision/` layout:
- `mod.rs` (always-compiled types: `BrowserAction`, `Verdict`, `AgentStep`,
  `RunResult`, `available()`),
- `browser.rs` — Playwright driver (see §7), owned on an `nvoos-agent` worker
  thread (Playwright's Rust type / the bundled Node process + crash-prone
  Chromium are non-Sync; same rule as `cpal::Stream` and `nokhwa::Camera`),
- `observe.rs` — compact element-index serialization (filtered DOM/AXTree),
- `loop.rs` — the ReAct loop with budget/retry/stop,
- `tools.rs` — the action schema (**names derive from the plugin's
  `web_browser` enum per §4a**: navigate, click, type, extract, screenshot,
  submit; plus agent-only wait/fill/scroll/hover/done),
- `prompt.rs` — system prompt + per-tool one-shot JSON examples,
- `safety.rs` — origin allowlist (§4a: block the plugin's `is_internal_url`
  set), destructive-action classification (`read-only`/`external-api`/
  destructive three buckets per §4a), sensitive-field masking, approval
  gateway.

**Shipped this session:** `mod.rs` (types + gating), `tools.rs` (safety buckets
+ brain prompt), `safety.rs` (origin guard with SSRF-style private-host deny +
approval decision + field masking), `observe.rs` (stable-`backendNodeId`
`Element` list + token-budget prompt fragment), and — behind `feature = agent` —
`driver.rs` + `browser.rs` (chromiumoxide 0.9 driver: launch/attach, navigate,
observe via `getFullAXTree`, click/type by selector, `pdf` via `PrintToPdfParams`,
screenshot, `current_url` for the post-run re-read). Dep `chromiumoxide` 0.9
(default features only, no `fetcher`) + `futures` (StreamExt for the Handler).

**Shipped (loop + controller, 2026-10-13):** `agent_loop.rs` (the ReAct loop:
observe→reason→parse→safety-gate→confirm→dispatch→verdict→repeat, with fence-
tolerant parse + one retry-with-feedback + post-run deterministic re-read; a
testable `StepExecutor` trait + `RunOptions`/`ConfirmFn` seam; unit-tested
against a scripted brain + fake executor). `controller.rs` (`AgentController`:
owns the `!Sync` `Driver` on an `nvoos-agent` worker thread with its own tokio
runtime, exposes a pollable `AgentSnapshot` + `available()`, mirrors
`VisionController`). The `DriverExecutor` adapter bridges `StepExecutor` → the
real `Driver` for the actions it can genuinely do (`navigate`/`observe`/
`screenshot`/`pdf`/`current_url`) and returns honest `Unsupported` errors for
the ref-based DOM interactions still pending.

**Shipped (ref→coordinate resolution + read-back, 2026-10-13):** the loop now
actually drives the DOM. `browser.rs` gains `parse_ref` (stable `n42` →
`backendNodeId`) and `resolve_center` (`DOM.getBoxModel` → center of the
`content` quad — the chrome-agent coordinate-targeting path that works across
canvas/SPA/a11y-poor pages). `driver.rs` gains the input layer: `click_ref` /
`hover_ref` (press+release / move at center), `fill_ref` (click→`Input.insertText`),
`insert_text` (IME path), `press_enter` (rawKeyDown+keyUp, form submit), and
`scroll_page` (`mouseWheel` delta), plus `ref_exists` read-back for the
`Changed` vs stale-ref verdict. `DriverExecutor` now wires `click`/`type_text`/
`fill`/`submit`/`scroll`/`hover` to the real driver; only `extract` and
`wait_text` remain `Unsupported` (content extraction + self-healing wait are
thin, self-contained follow-ups).

**NOT yet shipped (next):** `AgentConfig` in `Settings`, the `agent_*` IPC
surface, and the Settings/Diagnostics/overlay approval UI.

**Shipped (driver close-out, 2026-10-13):** the three remaining driver
follow-ups are done — `extract` (page `innerText` via `Runtime.evaluate`),
`wait_text` (self-healing poll of innerText + title, 15 s budget), and `pdf`
paper-format mapping (`A4`/`Letter`/`Legal` → Chrome inches via `paper_inches`).
`DriverExecutor` now wires every `StepExecutor` action to the real driver — the
agent loop is end-to-end engine-complete. Remaining work is purely
wiring/UI: `AgentConfig` + IPC + approval UI.

**Shipped (config + IPC + UI, 2026-10-13):** `AgentConfig` (enabled/provider/
model/max_steps/headless/persistent_profile_path/confirmation_required, DEFAULT
OFF) wired into `Settings`; `AppState` holds the resident `AgentController` and
gains `agent_brain()` (provider → boxed `CleanupProvider`) + `start_agent_run()`
(settings → `RunOptions` → controller). IPC: `agent_available` / `agent_run` /
`agent_status`, plus `agent{_available,_running}` on `StatusInfo` and an `agent`
block on `get_diagnostics` (new `AgentDiagnostics`). Frontend: `agent`
sub-tab in Settings, a `BrowserAgentPanel` on Home (instruction input + run +
poll), an agent card on Diagnostics, and the `ipc.ts` types/api. All gates
green (fmt/clippy/tsc/vite + 97/99 tests).

**NOT yet shipped (next):** the overlay **Approve/Deny** confirmation hook (the
loop's `ConfirmFn` is currently a pass-through auto-approve in
`DriverExecutor`'s call site — §4.2 needs an IPC round-trip), `agent_stop` (a
real stop channel in `AgentController`), and the optional `nvoos` brain (§5a).

**Shipped (overlay Approve/Deny, 2026-10-13):** the §4.2 human-in-the-loop gate
is wired end-to-end. `ConfirmFn` is now **async** (`BoxFuture<bool>`), so the
loop can block on a real approval. `AgentController` gains an `ApprovalSlot`
(the surfaced `PendingApproval { action, description }` + a one-shot
`send::await` answer channel) with `pending_approval()` / `respond_approval()`;
the worker's `run_inner` now builds the confirm closure from that slot,
publishing the action + a `describe_action()` summary and awaiting the UI's
answer (auto-approve when `confirmation_required` is off). IPC adds
`agent_pending` / `agent_respond` (main + overlay windows). The Home
`BrowserAgentPanel` polls `agent_pending` while running and shows Approve/Deny
buttons that call `agent_respond`. Gates green (fmt/clippy/tsc/vite + 98/100
tests).

**NOT yet shipped (next):** `agent_stop` (a real stop channel so a run can be
interrupted mid-flight), and the optional `nvoos` brain (§5a).

**Shipped (agent_stop + nvoos brain, 2026-10-13):** `agent_stop` — a cooperative
stop flag (`Arc<AtomicBool>`) on `AgentController` that the loop polls between
steps (§4.6 kill switch); `stop()` + IPC `agent_stop` + a Stop button on the
Home panel; a `stop_flag_halts_between_steps` unit test. **`nvoos` brain (§5a)**
— `NvoosProvider` now implements `complete()` (routing the agent's system +
observation through `NvoosClient::polish`), `nvoos` is exposed as a pub module
with an `AppState::nvoos_provider()` builder, and `agent_brain()` routes
`nvoos` → the hosted provider. The Settings agent-provider dropdown gains the
`NV oOS (hosted)` option. Gates green (fmt/clippy/tsc/vite + 99/101 tests).

**Remaining (stretch):** the macOS port parity (agent runs are Windows-first),
release signing/updater feed, and live end-to-end testing against a real
Chrome + local/hosted model (the `agent`-feature exe path remains untested
live).

**Shipped (verdict read-back + dead-code cleanup, 2026-10-13):** the §3 verdict
model is now *produced*, not just declared. `StepExecutor`'s mutating methods
return `AppResult<Verdict>`; `dispatch()` forwards the executor's verdict
instead of fabricating `Changed`. The driver produces real verdicts: `click`
runs a hit-test (`DOM.resolveNode`→`call_on_ref` vs `document.elementFromPoint`)
→ `Intercepted` when a covering element tops the target's center, `Unknown` on
a stale ref; `fill` reads the input value back → `ValuesLost` when the write
did not stick; `navigate`/`submit` compare the URL → `Navigated` vs `Changed`.
New `call_on_ref` helper (§3 read-back seam). Dead code removed: `click_selector`,
`type_selector`, `ref_exists`; `BrowserAction::Scroll.ref_id` dropped (unused);
the `describe_action::Done` arm is now an explicit `unreachable!`. Tests: +
`fill_verdicts_are_recorded` (ValuesLost surfaced), and `NoEffect` is now
produced via a **state-signature diff** — `browser.rs::state_signature` hashes
the URL + full a11y tree (`backendNodeId`/role/name/value) before/after, so
`click`/`hover`/`scroll` report `NoEffect` when the dispatched action changed
nothing (the §3 "delivered but nothing happened" case). Gates green (fmt/clippy
both features/tsc/vite + 101/103 tests). **Every `Verdict` variant is now
producible.**

Config: `AgentConfig { provider, model, enabled, max_steps, headless,
persistent_profile_path, confirmation_required: bool }` + `Default`, wired into
`Settings`, gated OFF by default.

IPC: `agent_available`, `agent_connect`, `agent_run(instruction)`,
`agent_stop`, `agent_approve(run_id, step)`, `agent_status`, `get_agent_runs`,
plus `agent://step` progress events. Diagnostics: an `agent` block
(provider, model, driver health, last run steps/errors).

### Phase 2 — CDP attach ("drive my logged-in session")
Add a "Connect to running Chrome" mode: launch/point Chrome with
`--remote-debugging-port`, `connectOverCDP`. Reuses the whole `agent/` loop;
changes only `browser.rs` acquisition. Unlocks the authenticated "book *my*
flight / check *my* accounts" use cases.

### Phase 3 — UI
- A "Browser agent" Settings tab (provider/model, headless vs CDP, confirmation
  toggle, max-steps).
- Dictation routing: a mode / target so "Open the site and …" can route to the
  agent instead of the paste path (reuse the `AppCategory::from_process_name`
  target-app idea with an explicit "browser agent" target).
- A live step overlay (what the agent is doing right now + Approve/Deny/Stop).

### Phase 4 (stretch) — Extension-based runtime-DOM perception
Best anti-bot/auth profile, largest scope (native messaging + a WS/stdin
bridge). Only if CDP proves insufficient in practice.

---

## 7. Driver integration strategy (Rust-critical decision — RESOLVED: Option A)

The app is a **pure-Rust Tauri** binary with a **Node/Vite** layer already
present for the frontend. The sidecar-vs-Rust fork was resolved (2026-10-13):
**Option A — embedded CDP, pure Rust, no Node sidecar.** The agent loop's
per-turn observe→act cycle needs low latency and a persistent session, which a
remote HTTP sidecar is structurally wrong for; and adding a Node/Docker
runtime contradicts the app's demonstrated "no sidecar" appetite (no FFmpeg,
in-Rust decode). Document verbs (`merge`/`watermark`/`extract`) stay deferred
behind the §7a media-worker seam.

Three ways to get driving capability:

| Option | Pros | Cons | Verdict |
|---|---|---|---|
| `playwright` Rust crate (community, drives Node) | Rust-idiomatic, in-tree | Mature but wraps a Node subprocess; heavier than a thin CDP client; API churn | **Fallback** |
| **Direct CDP over a WebSocket** (self-rolled client) | No Node, no external dep, full control; Playwright/Chromium both speak CDP | You re-implement session/context/input + waiting logic | **Recommended for the driver core** |

**Recommendation:** use `chromiumoxide` (or an equivalent async CDP client) for
the driver core — it is pure-Rust, no Node subprocess, no external executable,
and fits the app's "no sidecar binary" appetite (they already avoided FFmpeg and
use in-Rust decode). Reserve the community `playwright` crate only if the CDP
client can't cover an edge case (e.g. PDF export). Playwright's *value* is its
auto-wait/selector ergonomics and trace tooling; CDP gives the raw capability
that the agent loop actually needs (navigate, evaluate, input, snapshot the
accessibility/DOM). The agent's own `observe.rs` replaces Playwright's
snapshot layer anyway.

Key CDP facts to encode early (all cross-verified against `chrome-agent` and
Vercel `agent-browser`, two pure-Rust CDP executors):

- **Accessibility tree** via `Accessibility.getFullAXTree` (or DOM via
  `DOM.getDocument` + `Runtime.evaluate` for filtered structural perception).
  `chrome-agent` measures the win: a repeating-list page extracts to 30 records
  in **~1.5K tokens vs ~5.6K for the full a11y tree vs ~8.7K for raw HTML** —
  so the `observe.rs` serializer should ship an `extract`-style
  repeating-record detector, not just a raw tree dump.
- **Stable refs from `backendNodeId`** (chrome-agent's `nN` convention), not
  positional indices (§3).
- **Input** via `Input.dispatchKeyEvent`/`dispatchMouseEvent`, or
  `Runtime.evaluate` with element refs for fill/click (less brittle typing).
- **Hit-test before dispatch** (`Input.dispatchMouseEvent` with a probe, or
  `DOM.getBoxModel` + a point check) so `intercepted` is detectable — this is
  what makes the cookie-banner/overlay recovery possible.
- **Self-healing waits**: prefer selector/text/URL/JS-condition waits over
  `networkidle` (SSE/websockets/polling defeat it), and prefer auto-wait
  behaviour so the agent isn't asked to manage timing.
- **Persistent sessions** via a `--user-data-dir` profile (headless) or
  `connectOverCDP` to a live Chrome (Phase 2). `agent-browser` ships
  `--profile <name|path>` (persistent state) and `--auto-connect` (discover a
  running Chrome); mirror both as `AgentConfig` acquisition modes.
- **Anti-bot reality check**: `--stealth` CDP patches clear *light*
  fingerprinting (navigator.webdriver etc.) but do **not** beat managed
  Turnstile / DataDome / Kasada — only attaching to a **real installed Chrome
  via `--connect`** (or `--copy-cookies` from the user's real profile) does
  that. So the honest positioning is: headless = general automation;
  **CDP-attach Phase 2 = the authenticated, bot-resistant path**, not an
  afterthought.
- **WebSocket dependency**: add `tokio-tungstenite` (or `fastwebsockets`) — the
  app already has tokio + reqwest; a WS client is the one net-new primitive.

**Reuse-vs-build update:** rather than re-implement CDP handshakes, input
synthesis, and accessibility-tree walking from scratch, treat `chromiumoxide`
as the base and grow the agent-specific surface (stable-uid `observe.rs`,
verdict read-back, `extract` record detection) on top — the same
"adapter + domain layer" split the app already uses for STT engines.

**Browser-binary acquisition (sub-decision under Option A):** the agent needs a
Chromium it controls, distinct from Tauri's WebView2. Three sub-options, in
order of preference:
- **Attach to system Chrome/Chromium** (launch with a `--user-data-dir` and
  `--remote-debugging-port`, or `connectOverCDP` to a running instance) — zero
download, and it is the Phase-2 anti-bot path anyway (`chrome-agent`/`agent-browser`
both target this).
- **Chrome-for-Testing** download + registry entry (like the STT model registry
  and `agent-browser install`) — deterministic version, self-contained, but adds
  a ~700 MB download + version-management surface.
- **Reuse the Tauri WebView2** (via its CDP endpoint) — smallest footprint but
  the least controllable (no per-agent profile/isolation).

**v1 default:** attach to a *discoverable* system Chrome (system Chrome →
Chrome-for-Testing fallback), because it is self-contained yet anti-bot-ready;
the acquisition logic is a small `browser::acquisition` module so it can be
swapped without touching the loop.

**`pdf` is IN v1 (CONFIRMED).** Chromium natively renders PDF over CDP via
`Page.printToPDF` (format/landscape/margins), so a self-rolled CDP driver is
NOT the weak point my earlier §7 note implied. `pdf` ships as a first-class
`BrowserAction::Pdf { format, landscape }` in Phase 1. **Deferred to a later
phase:** `merge`, `watermark`, and `extract` — those are *document* operations,
not browser actions, and are the natural seam for the optional media-worker
sidecar tier (§7a).

---

## 7a. Media-worker sidecar — investigated, deferred (not a UI bug)

The plugin ships `addons/media-worker/` (v3.4.0), a Node.js Docker sidecar
with 12 route groups including `POST /api/browser/{screenshot,pdf}` (sandboxed
Puppeteer) and `POST /api/pdf/{extract,render,generate,merge,watermark}`.

- **It is a *WordPress-plugin* sidecar, not a dictate-app service.** A full
  grep of `examples/nvoos-dictate/` shows zero references to the worker,
  `/api/browser`, or `/api/pdf`. There is no "missing settings entry" — the
  integration was simply never built into the app.
- **Decision (v1): the agent drives the browser via embedded CDP (§7); `pdf`
  uses `Page.printToPDF`.** The worker's Puppeteer screenshot/PDF routes are
  NOT wired into the app in v1 — introducing a Docker sidecar dependency into
  a desktop dictation binary contradicts the app's "no sidecar" appetite
  (no FFmpeg, in-Rust decode).
- **Optional future (Phase 3+), two-tier like the worker's own "additive
  routing":** if a user configures a media-worker URL, route the *document*
  verbs (`merge`/`watermark`/`extract`/`generate-from-html`) to `/api/pdf/*`
  behind the worker's `X-Site-Token` auth; the local `Page.printToPDF` path
  stays as the no-worker fallback. Reuse the SSRF-guard semantics from the
  worker's `utils/safe-url.js` as the model for the agent's origin allowlist
  (§4.3 / §4a).

---

## 8. Open questions to resolve before coding

1. **Which local model is the target?** Recommend pinning a baseline (e.g.
   Qwen2.5-7B-Instruct via Ollama) for the Phase-0 spike, and documenting a
   "known-good" list, since model choice dominates reliability.
2. **Headless vs CDP as the v1 default?** Self-contained headless ships without
   user setup; CDP unlocks real accounts. Recommend: headless ships as default,
   CDP is an opt-in toggle from day one.
3. **Rust CDP client choice** (`chromiumoxide` vs self-rolled) — decide in the
   Phase-0 spike against a real end-to-end task.
4. **Approval UX granularity** — per destructive action vs per run; recommend
   per-action for submits/payments, per-run for the rest.
5. **Scope the "dictation → agent" handoff** — is the whole instruction a
   single agent run, or does a single PTT hold map to one *step* (multi-hold
   conversational driving)? Lean single-instruction-per-run for v1.
6. **Plugin-alignment (CONFIRMED, §4a)** — action vocabulary derives from the
   plugin's `web_browser` enum; safety buckets mirror `get_capability_flags()`.
   No other decisions are outstanding here.
7. **`pdf` in v1 (CONFIRMED, §7/§7a)** — via CDP `Page.printToPDF`; the
   media-worker sidecar (plugin Docker service) is deferred to an optional
   Phase-3 tier for `merge`/`watermark`/`extract` only. The app ships no
   worker integration in v1.
8. **Driver = embedded CDP, pure Rust (CONFIRMED, §7)** — no Node/Docker
   sidecar; `chromiumoxide` + `tokio-tungstenite` as the base, browser
   acquisition via system Chrome → Chrome-for-Testing fallback.

---

## 9. Reused vs net-new (summary)

**Reuse (already exists):**
- `LocalOpenAiProvider` + `openai_shape` (chat + vision bodies, fenced-JSON
  tolerance) — the agent's local-LLM transport.
- `NvoosClient` (`test_connection` / `polish` / `archive_transcript`) — the
  agent's hosted brain + Settings connection test + run archiving (§5a).
- `CleanupProvider` dispatch pattern — how to add an `agent` consumer/provider
  (the `AgentBrain` trait mirrors it).
- `vision`/`camera` module layout, worker-thread ownership, feature gating,
  IPC + `get_diagnostics` + Settings-tab conventions.
- Overlay + push-to-talk UX for the Approve/Deny surface.
- `error::AppError`/`AppResult`, keychain `SecretStore`, atomic/file hardening
  utilities.

**Net-new:**
- A CDP WebSocket client dep (`chromiumoxide` or `tokio-tungstenite`) — the one
  genuinely new external primitive.
- `agent/` module (browser driver, observe serializer with stable uids +
  `extract` record detection, ReAct loop, verdict read-back, safety gate).
- `AgentBrain` trait + `nvoos`/`local` brain adapters.
- `AgentConfig` + agent IPC commands + Settings/Diagnostics surface.
- Agent prompt engineering + per-tool JSON examples + run-verification layer.

**Reference implementations to crib from (both Apache/MIT, pure Rust, CDP):**
- `sderosiaux/chrome-agent` — the verdict model, `backendNodeId` uids,
  `--stealth` vs `--connect` taxonomy, `extract` record detection, stdin/stdio
  `pipe`/`batch` mode. Closest match to this app's "no built-in model" stance.
- `vercel-labs/agent-browser` — `snapshot -i` / `@eN` ref workflow, `--profile`
  auth persistence, domain allowlist + action-policy + `--confirm-actions`
  safety surface, prompt-injection content-boundary discipline.

---

## References (research, checked 2026-10-13)

- "Runtime Snapshots #16 — The Three Architectures of Browser Agents" (DEV /
  e2llm) — the perception-layer taxonomy used for §2.
- "Browser Use with local LLMs: what actually works" (ego/lite blog) — concrete
  local-model failure modes (flat-vs-nested schema, model timeouts, context
  budgeting, verify-don't-trust-`done`) used in §3/§5.
- Playwright MCP field guides & BrowserStack "Modern Test Automation with AI"
  — accessibility-tree grounding, deterministic element refs, "LLM as planner".
- OWASP "AI Agent Security Cheat Sheet" + explainx/DEV guardrail write-ups —
  human-in-the-loop for high-risk actions, read/write separation, origin
  allowlisting used in §4.
- insiderllm/Mozilla/local-LLM agent guides — small-model agent failure rates
  and VRAM/context budgeting used in §5.
- `sderosiaux/chrome-agent` README (fetched raw, 2026-10-13) — verdict model,
  `backendNodeId` uids, `--stealth` vs `--connect`, token budgets, `pipe`/`batch`.
- `vercel-labs/agent-browser` README (fetched raw, 2026-10-13) — `@eN` ref
  workflow, profile/auth persistence, `--allowed-domains` / `--confirm-actions` /
  content-boundary discipline.
- `docs/lm-studio-integration-plan.md` (this app) — the Stage-2 provider
  architecture this plan extends.
- `src-tauri/src/nvoos/{client.rs,mod.rs}` and
  `cleanup/providers/nvoos.rs` (this app) — the exact `NvoosClient` contract
  (§5a) and the bearer/segment-response dialect it reuses.
- `addons/pro/includes/tools/capture/class-wp-mcp-ai-tool-web-browser.php`
  (base+pro plugin, inspected 2026-10-13) — the `web_browser` action enum and
  `get_capability_flags()`/`is_internal_url` contracts adopted in §4a.
- `.context/media-worker.md` + `addons/media-worker/src/routes/{browser,pdf}.js`
  (inspected 2026-10-13) — the plugin sidecar with `/api/browser/*` +
  `/api/pdf/*`; investigated in §7a and deferred for v1.
