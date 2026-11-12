# Proposal 144 — NV oOS Cognition: Recognition and Paste Fidelity Hardening

**Status:** ✅ IMPLEMENTED (W1–W5) — wall-plug power measurement and the visual indicator check outstanding; macOS backend not compiled locally
**Date:** 2026-11-12
**Priority:** HIGH (correctness defects in a shipping release)
**Estimated effort:** 9–14 dev days
**Target release:** v0.4.0
**Relates to:** Proposal 067 (Tauri dictation companion) · Proposal 068 (macOS port) · Proposal 100 (screen OCR) · Proposal 103 (Cognition rebrand) · `examples/nvoos-dictate` · ADR 0001 (stage-2 paste strategy) · ADR 0003 (engines) · `mcp-ai-wpoos-dictation-app` skill
**Author:** NV oOS coding agent (Zed)
**Origin:** Public review of the v0.3.0 release. Three findings raised by an
external reviewer (Reddit), each reproduced in source before being accepted as
a defect.

**Implementation status (2026-11-12).** All five workstreams are implemented
and verified in the working tree: `cargo fmt --check` clean, `cargo clippy
--all-targets -- -D warnings` clean, `cargo test` green (181 lib tests + 42
integration tests), webview `tsc --noEmit && vite build` clean. W4 ships the
capture-mode rows, a Clipboard diagnostics card, and a word-level
raw-vs-cleaned diff in History. Both hardware-dependent capture tests
(`live_capture_delivers_samples`, `live_pause_resume_round_trip`) pass on the
reference machine and stay `#[ignore]`d for CI.

Phase 0 is **measured for CPU and latency** on the reference machine
(`examples/capture_probe.rs`): resident 0.38% of one core and 100 callbacks/s,
paused 0 callbacks and 0.0 ms, resume p50/p95 2 ms. Wall-plug power and the
visual indicator check remain outstanding, so `Resident` stays the default —
see `docs/capture-residency-measurements.md`. The unicode-typing escape hatch
is deliberately deferred (ADR 0007), and the macOS paste backend is unverified
locally (it is `cfg(target_os = "macos")`; CI is the first real compile).

---

## 1. Problem Statement

Three defects live in the deterministic half of the app — the half users are
told they can trust because it runs locally and needs no model. Each is a
fidelity problem: the app does something *almost* right, silently, where the
user cannot easily see or undo it.

### 1.1 Inverse text normalization over-reaches

`examples/nvoos-dictate/src-tauri/src/cleanup/rules/numbers.rs` converts every
recognised cardinal into digits, unconditionally, in every app category. The
existing test suite pins the behaviour:

| Input | Output today | Correct? |
|---|---|---|
| `my number is five two nine` | `my number is 529` | ✅ yes |
| `twenty three people` | `23 people` | ✅ yes |
| `twenty dollars` | `$20` | ✅ yes |
| `one or two things` | `1 or 2 things` | ❌ no |
| `someone said one thing` | `someone said 1 thing` | ❌ no |
| `a thousand dollars` | `a $1000` | ⚠️ malformed |

The digit-run rule (`five two nine` → `529`) is the case that earns its keep:
phone numbers, IDs, codes. The rest is recall-maximising rewriting that
mangles ordinary prose, and there is no per-application way to turn it off.
Per-app profiles exist (`cleanup/rules/per_app.rs`) but only trim trailing
punctuation, so a chat message and a document get identical treatment.

**Consequence:** the app silently edits the user's words in the one place they
expect it not to. That is a trust defect, not a cosmetic one.

### 1.2 Clipboard restore is text-only

`paste/clipboard_keystroke.rs` snapshots the clipboard with
`clipboard.get_text().ok()`. When the clipboard holds an image, a file list,
or rich content, `get_text()` returns `Err`, the snapshot is `None`, and the
transcript overwrites the clipboard with no restore path. The user's copied
image is destroyed by a dictation.

Three secondary gaps in the same file:

- **No history hygiene.** Each dictation writes one or two clipboard entries,
  so Windows clipboard history (`Win+V`) fills with transient transcripts.
- **No sequence guard.** If the user copies something else during the ~180 ms
  paste window, the restore overwrites their new copy.
- **Fixed settle time.** 180 ms is a guess; slow targets (RDP, large IDEs)
  paste the *restored* content instead of the transcript.

**Consequence:** data loss in a background operation, which is the worst
category of defect for a tool that runs on every keystroke.

### 1.3 Microphone residency is undisclosed and unconfigurable

`audio/capture.rs` holds a live `cpal` stream for the lifetime of the
`MicCapture` handle, and `dictation.rs::DictationController::spawn` opens that
handle at app start. This is required for the pre-roll ring buffer to contain
audio from before key-down.

The consequence is that the Windows microphone privacy indicator stays lit for
as long as the app runs. The source comment acknowledges this; the README does
not. There is no way to trade pre-roll for an idle-quiet microphone, and no
power measurement exists either way.

**Consequence:** a permanent "this app is listening" signal the user cannot
turn off, and no evidence for the battery claim users will reasonably ask
about.

---

## 2. Research & Industry Standards

### 2.1 Inverse text normalization: precision over recall

ITN is the spoken-to-written step of an ASR pipeline (digits, currency, dates,
times, ordinals, units). The literature is consistent on where the difficulty
lies: not in performing a rewrite, but in deciding *whether* one is warranted.

- The same spoken form requires different written forms depending on semantics.
  "Ten to one" is a ratio (`10:1`) in one sentence and an idiom in another;
  "one or two" is an approximation, not a list of integers. Rigid rule-based
  systems cannot resolve this, which is why production systems add a
  context-sensitive decision layer on top of a high-precision grammar
  ([Dual-Form ASR, arXiv](https://arxiv.org/html/2609.02901)).
- Production ITN is evaluated on **unrecoverable-error minimisation**, not raw
  conversion rate. NVIDIA NeMo's WFST + language-model rescoring stack reports
  sentence accuracy improvements specifically on ambiguous datasets
  ([NVIDIA Technical Blog](https://developer.nvidia.com/blog/text-normalization-and-inverse-text-normalization-with-nvidia-nemo/)).
- Apple frames ITN as a token-labelling problem rather than string rewriting,
  because the decision to normalise is a per-token classification
  ([Apple Machine Learning Research](https://machinelearning.apple.com/research/inverse-text-normal)).
- Practitioner guidance converges on hybrid design: deterministic patterns for
  unambiguous forms, context for ambiguous ones, and leaving the ambiguous case
  in spoken form when confidence is low
  ([Soniox ITN reference](https://soniox.com/wiki/inverse-text-normalization),
  [Gladia](https://www.gladia.io/blog/text-normalization-speech-recognition)).

**Design consequence for Cognition:** the current implementation is a
recall-maximising rewriter with no ambiguity tier. The fix is not "a better
regex"; it is a confidence-tiered policy, an idiom deny-list, and per-domain
defaults — with the ambiguous tier off by default.

### 2.2 Clipboard: the three-part contract

Windows documents the mechanism this app needs but does not use:

- `ExcludeClipboardContentFromMonitorProcessing` — place any data in this
  format to prevent **all** clipboard formats in that copy from entering
  clipboard history or syncing to other devices.
- `CanIncludeInClipboardHistory` set to a serialized `DWORD` of `0` — exclude
  from local history only.
- `CanUploadToCloudClipboard` set to `0` — exclude from cloud sync only.

These are the documented formats password managers use to keep secrets out of
clipboard history
([Microsoft Learn — Clipboard Formats](https://learn.microsoft.com/en-us/windows/win32/dataxchg/clipboard-formats)).
A dictation app has the same problem for a different reason: every utterance is
a transient write that should not colonise the user's clipboard history.

Full-fidelity restore also requires format enumeration rather than a text
read: `OpenClipboard` → `EnumClipboardFormats` → `GetClipboardData` per
format, with `CF_UNICODETEXT`, `CF_HDROP` (file lists), `CF_DIB` (bitmaps) and
`CF_HTML` handled explicitly. Delayed-rendering owners can block on
`GetClipboardData`, so the snapshot needs a bound. `GetClipboardSequenceNumber`
provides the "did someone else write since I did?" check that prevents the
restore from stomping a user's new copy.

### 2.3 Capture sessions, the privacy indicator, and power

The OS indicator reflects an **active capture session**, not an application's
intent, which is why a resident pre-roll stream keeps it lit
([Microsoft Support — Windows camera, microphone and privacy](https://support.microsoft.com/en-us/windows/privacy/windows-camera-microphone-and-privacy)).

The standard mitigation is stream-level pause rather than process-level
laziness:

- cpal exposes `StreamTrait::pause()` and documents it as useful "for saving
  energy in moments of silence" ([docs.rs/cpal](https://docs.rs/cpal)).
- A shipping desktop app reports that pausing the audio unit on drop is what
  clears the indicator, and that a failed `play()` must also stop the stream
  ([juno PR #708](https://github.com/lacymorrow/juno/pull/708)).

**Design consequence for Cognition:** the right axis is not
resident-versus-lazy-at-startup, it is resident-versus-paused-at-idle. Pausing
reuses the existing worker thread and the existing `cpal::Stream`, so the
change is a control channel and a state machine, not a capture rewrite.

---

## 3. Gap Analysis

| Area | Standard | Cognition today | Gap |
|---|---|---|---|
| ITN confidence | Tiered; ambiguous forms preserved | Single unconditional pass | **Over-normalises prose** |
| ITN scope | Per-domain policy | One policy for all apps | No rule toggles per category |
| ITN formatting | Locale-aware separators | `a $1000` | Malformed currency output |
| Clipboard snapshot | All formats, bounded | Text only | **Images and files destroyed** |
| Clipboard history | Transient writes excluded | Not set | `Win+V` pollution |
| Clipboard race | Sequence-number guard | None | Stomps a concurrent user copy |
| Clipboard timing | Adaptive / configurable | Fixed 180 ms | Wrong paste in slow targets |
| Mic idle | Paused when not capturing | Live always | **Indicator always on** |
| Power | Measured and published | Unmeasured | No basis for a claim |
| Diagnostics | Paste + capture outcomes | Level meter, hook health | Paste path is opaque |

---

## 4. Proposal

### 4.1 W1 — Tiered number policy

Replace `numbers::normalize` with a policy object and four confidence tiers.

```rust
pub struct NumberPolicy {
    pub enabled: bool,
    pub tiers: NumberTiers,          // bitflags
    pub thousands_separator: bool,
    pub idioms: &'static [&'static str],
}
```

| Tier | Rule | Example | Default |
|---|---|---|---|
| T1 | Digit runs of 3+ single digits | `five two nine` → `529` | Always on |
| T2 | Explicit unit present (currency, percent, time, measure) | `twenty dollars` → `$20` | Always on |
| T3 | Compound cardinals | `twenty three` → `23` | On |
| T4 | Bare small cardinals | `one or two` → `1 or 2` | **Off** |

T4 activates only when a numeric cue precedes the token (`page`, `line`,
`version`, `id`, `count`, `number`, `step`, `item`) or an explicit unit
follows it. A phrase deny-list runs before T3 and T4:

```
one or two · one or the other · one of · one thing · two things
ten to one · nine times out of ten · one hundred percent (without a measure)
a thousand times · one on one · ten out of ten · twenty twenty · five star
```

Per-category defaults:

| Category | Tiers | Notes |
|---|---|---|
| `Chat`, `Mail`, `Docs` | T1–T3 | Prose-first |
| `Default`, `Browser` | T1–T3 | Prose-first |
| `Code`, `Terminal` | none | Identifiers matter more than digits |

Formatting becomes policy-aware: `$1,000` in prose categories, never `$1000`.

Two invariants, both cheap and both permanent regression guards:

- **Idempotence** — `normalize(normalize(x)) == normalize(x)` across the whole
  fixture corpus.
- **Reversibility** — `CleanupResult` already carries `raw` and `stage1`.
  Surface the diff in History so a user can see exactly what a rule changed
  rather than trusting it.

### 4.2 W2 — Clipboard fidelity

Replace the text-only snapshot with a typed one and a restore guard.

```rust
enum ClipSnapshot { Empty, Text(String), Rich(Vec<FormatItem>), TooLarge, Unsupported }

struct PasteOutcome {
    backend: &'static str,
    snapshot_kind: &'static str,
    restore: RestoreResult,
    elapsed_ms: u64,
    warnings: Vec<String>,
}
```

- **Windows**: enumerate and clone all formats; special-case `CF_UNICODETEXT`,
  `CF_HDROP`, `CF_DIB`, `CF_HTML`. Restore with `EmptyClipboard` +
  `SetClipboardData` per format.
- **Size cap**: 32 MB total. Above the cap, classify as `Unsupported`, still
  paste, skip the restore, record the reason in Diagnostics. A snapshot must
  never block the paste path.
- **Sequence guard**: read `GetClipboardSequenceNumber()` immediately after
  the write; re-read before restoring. If it changed, someone else wrote to the
  clipboard — abort the restore.
- **History hygiene**: set `ExcludeClipboardContentFromMonitorProcessing` and
  `CanIncludeInClipboardHistory = 0` on the transient write so `Win+V` stays
  clean.
- **Settle time**: 250 ms default, `paste.restore_delay_ms` configurable and
  documented as the slow-target escape hatch.
- **macOS**: `NSPasteboard` `pasteboardItems` with per-item `types` and
  `data(forType:)`; restore item-by-item in `macos_cgevent.rs`.
- **Escape hatch**: `paste.preserve_clipboard = always` skips the clipboard
  and types via `KEYEVENTF_UNICODE`. Documented as slower and not universally
  compatible (Chromium surfaces ignore synthesized unicode for the paste
  accelerator — see ADR 0001).

`PasteBackend::paste()` gains a `PasteOutcome` return so the controller and
Diagnostics can report what actually happened.

**As implemented (2026-11-12).** On Windows the snapshot captures *every*
format whose payload is a global memory block — not just the text/file/image
trio above — and restores the same bytes, so HTML, RTF and private application
formats survive intact. `GlobalSize` returning zero identifies GDI-handle
formats (`CF_BITMAP`, `CF_PALETTE`, metafiles), which are counted as
`partial_formats` and surfaced in Diagnostics rather than misread as data. The
cap is 64 MiB total. macOS and Linux keep the `arboard` text/image fallback. A
Windows round-trip test (`all_formats_round_trip`, ignored by default because
it mutates the system clipboard) proves that text plus a private registered
format survive a snapshot → clobber → restore cycle byte-identically.

### 4.3 W3 — Capture modes and power

```rust
enum CaptureMode { Resident, OnDemand }
```

```rust
// AudioConfig additions
pub capture_mode: CaptureMode,   // default Resident in v0.4.0; revisit after Phase 0
pub linger_ms: u64,              // default 2000
pub resume_timeout_ms: u64,      // default 250
```

State machine, implemented as a control channel on the existing mic worker:

```
Paused --Resume--> Resuming --first callback--> Capturing
Capturing --release + transcribe--> Lingering --linger_ms--> Paused
Resuming --resume_timeout_ms--> Error(resume_timeout)  // surface, never paste silence
```

Key details:

- PTT key-down sends `Resume`, then **waits for the first real callback**
  before opening the utterance window. This is what stops OnDemand clipping
  the first syllable when resume is slower than expected.
- `pause()` is called only after `linger_ms`, which avoids thrash during
  rapid-fire dictation.
- Pre-roll is a Resident-only feature; the Settings UI says so rather than
  silently degrading.
- Replace the per-callback `Vec` allocation in `build_stream` with a scratch
  buffer captured by the closure.

### 4.4 W4 — Diagnostics and rule diff

- Capture: mode, stream state, last resume latency, total paused time.
- Paste: backend, snapshot kind, restore outcome, elapsed, warnings.
- Cleanup: `raw` → `stage1` diff, so a rule change is visible per utterance.

This workstream is what turns every future bug report into a two-line
diagnosis instead of a reproduction hunt.

### 4.5 W5 — Documentation and ADRs

README gains three sections: **Microphone and privacy** (resident vs on-demand,
what the indicator means, ring-buffer lifetime, nothing on disk), **Clipboard
behaviour** (snapshot, restore, history exclusion, escape hatch), and **Text
normalisation** (tier table, per-app defaults, how to disable). ADR 0006
records the number policy; ADR 0007 records the clipboard contract.

---

## 5. Implementation Plan

| Phase | Scope | Effort | Exit criteria |
|---|---|---|---|
| 0 | Measure: idle power, resume latency, confirm indicator clears on pause (Windows + macOS) | 0.5–1 d | Numbers recorded; OnDemand default decided |
| 1 | **W1** number policy + diff view | 2–3 d | Adversarial suite green; patch release; thread reply |
| 2 | **W2** clipboard snapshot + guard + history exclusion | 4–6 d | Round-trip matrix green; `Win+V` clean |
| 3 | **W3** capture modes + scratch buffer | 3–4 d | Indicator clears; latency and power published |
| 4 | **W5** docs, ADR 0006/0007, release notes | 1 d | README sections merged |

Phase 1 ships first: it is the cheapest, it is already acknowledged publicly,
and shipping it converts a criticism into evidence of responsiveness.

### Files touched

| Workstream | Files |
|---|---|
| W1 | `cleanup/rules/numbers.rs`, `cleanup/rules/mod.rs`, `config.rs` (`CleanupConfig`), `tests/cleanup_golden.rs`, Settings UI |
| W2 | `paste/clipboard_keystroke.rs`, `paste/macos_cgevent.rs`, `paste/backend.rs`, `config.rs` (`PasteConfig`) |
| W3 | `audio/capture.rs`, `dictation.rs`, `state.rs`, `config.rs` (`AudioConfig`) |
| W4 | `ipc.rs`, `src/views/Diagnostics.tsx`, `src/views/History.tsx` |
| W5 | `README.md`, `docs/decisions/0006-number-policy.md`, `docs/decisions/0007-clipboard-fidelity.md` |

---

## 6. Test Plan

| Area | Automated | Manual |
|---|---|---|
| Numbers | 25 adversarial cases; idempotence property over fixtures | Diff view shows the change |
| Cleanup | Existing 17 golden cases must stay green; any intentional change documented in the PR | — |
| Clipboard | Snapshot round-trip: text, image, file list, mixed, empty, oversized | Notepad, VS Code, Chrome, Slack, Excel, RDP |
| Clipboard | Sequence guard via a fake backend | `Win+V` shows no dictation entries |
| Capture | State machine: resume timeout, no-callback, rapid toggle | Indicator clears; key-up→paste timing in both modes |
| Power | — | 1-hour idle, laptop on battery, published in README |
| Regression | `live_capture_delivers_samples` still ignored-by-default, run manually | — |

---

## 7. Effort Estimation

| Workstream | Days |
|---|---|
| W1 | 2–3 |
| W2 | 4–6 |
| W3 | 3–4 |
| W4 | 1–2 |
| W5 | 0.5–1 |
| Phase 0 measurement | 0.5–1 |
| **Total** | **9–14 dev days** |

No new dependencies. W2 uses the `windows` crate (already present for the
keyboard hook and WinRT OCR). W3 uses `cpal` (already present).

---

## 8. Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Delayed-rendering clipboard owners block `GetClipboardData` | Paste stalls | Bounded snapshot; classify `Unsupported` and paste without restore |
| Snapshot of a large image exhausts memory | OOM | 32 MB cap, `TooLarge`, no restore |
| Changing number defaults changes output for existing users | Surprise | Release-note callout; `deterministic_cleanup` master switch retained |
| `pause()` behaves differently across hosts | Indicator stays lit | Phase 0 measures it before the default changes |
| macOS Secure Input blocks synthetic keystrokes | Silent no-paste | Detect `IsSecureEventInputEnabled`; warn in Diagnostics |
| Wayland restricts shortcuts and clipboard ownership | Broken UX on Linux | Documented limitation; no promise |
| Scope creep into stage-2 LLM | Loses the local-deterministic guarantee | Explicit non-goal (see §10) |

---

## 9. Privacy & Compliance Constraints

These are hard constraints, not preferences.

1. **Clipboard snapshots may contain passwords.** They live in memory only,
   are never logged, never serialised, never enter the diagnostics payload, and
   are dropped immediately after restore. A test must assert that snapshot
   content cannot reach the log sink.
2. **No power claim without a measurement.** Phase 0 produces the numbers or
   the README says nothing.
3. **The number fix stays deterministic.** It must not depend on a network
   call or an LLM. The deterministic pipeline is the fallback that guarantees a
   paste (ADR 0001) and that property is preserved.
4. **The mic indicator is disclosed, not rationalised.** The README states that
   Resident mode keeps the indicator on, before describing any benefit.
5. **Telemetry stays at none.** Diagnostics remain local JSON the user exports
   explicitly.

---

## 10. Non-Goals

- Solving ITN ambiguity with a language model. That would convert a
  deterministic pipeline into a network-dependent one.
- Automatic power-based switching between capture modes. Surprising mode
  changes are worse than a setting.
- Guaranteeing Linux/Wayland parity for global shortcuts or clipboard
  ownership.
- Changing the paste architecture away from clipboard + keystroke. The
  Chromium unicode-injection constraint in ADR 0001 still holds.

---

## 11. Success Metrics

| Metric | Target |
|---|---|
| Prose sentences altered by the number rule | 0 in the adversarial corpus |
| Digit-run normalization retained | 100% of T1 cases |
| Clipboard content destroyed by a dictation | 0 across the manual matrix |
| `Win+V` entries created per dictation | 0 (transient write excluded) |
| Indicator clear time after pause | ≤ 1 s on Windows and macOS |
| Idle power, OnDemand vs Resident | Measured and published, not asserted |
| Regressions in the golden cleanup suite | 0 |

---

## 12. Decision Required

1. **Approve the tier defaults** — T4 (bare small cardinals) off by default in
   Chat/Mail/Docs, numbers off entirely in Code/Terminal. This changes output
   for existing users.
2. **Approve the OnDemand default** after Phase 0 measurements — Resident
   keeps current latency and keeps the indicator on; OnDemand quiets the
   indicator and gives up pre-roll.
3. **Approve the clipboard contract** — 32 MB cap, sequence guard, history
   exclusion, and the `preserve_clipboard` escape hatch.

---

## 13. References

- Microsoft Learn — Clipboard Formats
  (<https://learn.microsoft.com/en-us/windows/win32/dataxchg/clipboard-formats>)
- Microsoft Support — Windows camera, microphone and privacy
  (<https://support.microsoft.com/en-us/windows/privacy/windows-camera-microphone-and-privacy>)
- cpal documentation — stream pause/play
  (<https://docs.rs/cpal>)
- juno PR #708 — pausing the audio unit clears the indicator
  (<https://github.com/lacymorrow/juno/pull/708>)
- NVIDIA NeMo — Text Normalization and Inverse Text Normalization
  (<https://developer.nvidia.com/blog/text-normalization-and-inverse-text-normalization-with-nvidia-nemo/>)
- Apple Machine Learning Research — Inverse Text Normalization as a Labeling Problem
  (<https://machinelearning.apple.com/research/inverse-text-normal>)
- Dual-Form ASR — Semantics-Aware Inverse Text Normalization
  (<https://arxiv.org/html/2609.02901>)
- Soniox — Inverse text normalization reference
  (<https://soniox.com/wiki/inverse-text-normalization>)
- Gladia — Text normalization in speech recognition
  (<https://www.gladia.io/blog/text-normalization-speech-recognition>)

---

## Appendix A — Reproduced current behaviour

Every claim in §1.1 is pinned by a unit test in
`cleanup/rules/numbers.rs`. The suite currently passes because the defect is
asserted as intended behaviour.

| Test | Assertion | Verdict |
|---|---|---|
| `digit_sequences_concatenate` | `my number is five two nine` → `my number is 529` | Keep (T1) |
| `cardinals_parse` | `twenty three people` → `23 people` | Keep (T3) |
| `cardinals_parse` | `one hundred and five` → `105` | Keep (T3) |
| `cardinals_parse` | `a thousand dollars` → `a $1000` | Fix formatting |
| `currency_and_percent` | `twenty dollars` → `$20`; `fifty percent` → `50%` | Keep (T2) |
| `leaves_ordinary_words_alone` | `someone said one thing` → `someone said 1 thing` | **Correct the assertion (T4)** |

W1 corrects the last assertion rather than working around it.
