//! Browser agent (dictated web control) — Phase 1.
//!
//! Turns a dictated instruction into a completed browser task: a local (or
//! `nvoos`) LLM is the *brain* (via `CleanupProvider::complete`, §5a); an
//! embedded CDP driver is the *hands* (§7, Option A — no Node/Docker sidecar).
//! This module owns the action vocabulary, the percept (observation)
//! serialization, the per-action verdict model, the ReAct loop, and the
//! safety gate (§4/§4a).
//!
//! Feature wiring mirrors `vision`: the module is **always compiled** so
//! `AppState` can hold the controller and `Settings.agent` is type-stable, but
//! the `chromiumoxide`-dependent driver is gated behind the `agent` cargo
//! feature. Without it, `available()` is false and the IPC surface reports
//! "not compiled".

pub mod agent_loop;
#[cfg(feature = "agent")]
pub mod browser;
pub mod controller;
#[cfg(feature = "agent")]
pub mod driver;
pub mod observe;
pub mod safety;
pub mod tools;

use serde::{Deserialize, Serialize};

/// Whether the browser-agent driver is compiled into this build.
pub fn available() -> bool {
    cfg!(feature = "agent")
}

/// The single action the brain may request per turn. Names are a superset of
/// the plugin's `web_browser` enum (§4a): the plugin's `navigate | click |
/// type | extract | screenshot | submit | pdf` names are kept verbatim, with
/// agent-only verbs (`wait`, `fill`, `scroll`, `hover`, `done`) added.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(tag = "tool", rename_all = "snake_case")]
pub enum BrowserAction {
    /// Navigate the page to a URL.
    Navigate { url: String },
    /// Click the element with the given ref (backendNodeId-derived).
    Click { ref_id: String },
    /// Type into the focused element (kept as the plugin's `type` verb).
    Type { text: String },
    /// Fill (clear + set) the element with the given ref.
    Fill { ref_id: String, value: String },
    /// Extract repeating records / text from the page.
    Extract { selector: Option<String> },
    /// Capture a screenshot.
    Screenshot,
    /// Submit the current form.
    Submit,
    /// Render the current page to PDF (CDP `Page.printToPDF`).
    Pdf { format: String, landscape: bool },
    /// Wait for text/selector to appear.
    Wait { text: String },
    /// Scroll the page (or element into view).
    Scroll {
        direction: String,
        amount: Option<u32>,
    },
    /// Hover over the element with the given ref.
    Hover { ref_id: String },
    /// End the run with a one-line summary.
    Done { summary: String },
}

/// A per-action *verdict* — never just `ok`. `ok:true` means "the command
/// ran", not "the page complied"; this closed set lets the loop branch and
/// recover (§3). Mirrors `chrome-agent`'s verdict model.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Verdict {
    /// The page moved; the action landed as expected.
    Changed,
    /// A new document loaded (all refs are stale — re-observe).
    Navigated,
    /// Another element (cookie banner, modal) caught the click.
    Intercepted,
    /// A submit emptied a field that held a value.
    ValuesLost,
    /// Delivered, but the tree was unchanged.
    NoEffect,
    /// Nothing could be compared — **never retry** (the action may have landed).
    Unknown,
}

/// One step of an agent run: the action taken and the verdict read back.
#[derive(Debug, Clone, Serialize)]
pub struct AgentStep {
    /// 1-based turn index.
    pub turn: u32,
    /// The action the brain requested.
    pub action: BrowserAction,
    /// The verdict read back after dispatch.
    pub verdict: Verdict,
    /// Human-readable detail (e.g. the `intercepted_by` element).
    pub detail: String,
}

/// The outcome of a completed (or halted) run, surfaced to the webview and
/// the audit log (§4.6).
#[derive(Debug, Clone, Serialize, Default)]
pub struct RunResult {
    /// Whether the brain terminated with `done`.
    pub completed: bool,
    /// The `done` summary, or the halt reason when not completed.
    pub summary: String,
    /// Every step taken, with verdicts.
    pub steps: Vec<AgentStep>,
    /// The page state independently re-read after the run (verify, don't
    /// trust the model's `done` flag — §5).
    pub final_url: Option<String>,
}
