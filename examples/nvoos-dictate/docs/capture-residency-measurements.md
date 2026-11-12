# Capture residency measurements (Proposal 144 §4.3)

**Date:** 2026-11-12
**Machine:** reference PC (Windows, debug build)
**Device:** `Microphone (DL-Dock)` — 48 kHz, 2 channels
**Harness:** `src-tauri/examples/capture_probe.rs`

## Command

```bash
cd src-tauri
cargo run --example capture_probe -- 25 10
```

The harness warms the device up, then measures two 25-second windows — one with
the stream resident, one with it paused — followed by ten pause/resume cycles.

## Results

| Metric | Resident | Paused |
|---|---|---|
| Callbacks in 25 s window | 2501 | **0** |
| Callbacks per second | 100.0 | 0.0 |
| Process CPU time | 93.8 ms | **0.0 ms** |
| CPU duty cycle | 0.38% | 0.00% |

Resume latency: **p50 2 ms · p95 2 ms** (n = 10).

## Interpretation

- Pausing genuinely stops the stream: zero callbacks, not merely a stopped
  sink. That is the condition that ends the OS capture session, which is what
  drives the microphone-in-use indicator.
- The resident stream costs about 0.38% of one core at idle on this device —
  small, but not nothing, and it never stops.
- Resume is effectively free here (2 ms p50), two orders of magnitude inside
  the 250 ms `resume_timeout_ms` budget and the ~1.5 s key-up-to-paste budget.

## What this does not establish

- **Wall-plug power.** CPU duty cycle is not energy draw. Battery figures need
  `powercfg /energy` or an external meter over a much longer window, and
  probably a second machine with a different audio stack.
- **The indicator itself.** The harness proves callbacks stop; the tray icon is
  visual state. Confirm by eye with `audio.capture_mode = on_demand` before
  making any public claim about the icon.
- **Other hardware.** One device on one machine. A USB dock, a Bluetooth
  headset and a laptop's built-in array can all behave differently.

## Consequence for the default

`Resident` remains the default. The measurement removes the CPU objection to
`OnDemand` but does not settle the trade-off, which is now purely about losing
the pre-roll window. That is an owner decision, not a measurement.
