//! The ReAct loop (§3): observe → reason → act → reflect, hardened for a
//! local-model brain.
//!
//! Pure and always-compiled: it depends only on the `CleanupProvider` brain
//! (its `complete()` seam), the `BrowserAction` vocabulary, the `safety`
//! approval gate, and a small `Driver` trait object so it stays testable
//! without a real browser. The concrete `chromiumoxide` driver is injected by
//! the feature-gated `controller`.

use std::future::Future;
use std::pin::Pin;

use crate::agent::observe::Observation;
use crate::agent::safety::{approve, Approval, Origin};
use crate::agent::tools::brain_prompt;
use crate::agent::{BrowserAction, RunResult, Verdict};
use crate::cleanup::providers::CleanupProvider;
use crate::error::{AppError, AppResult};

/// The browser-facing surface the loop needs. Implemented by `driver::Driver`
/// (feature-gated); a fake impl backs the unit tests.
#[async_trait::async_trait]
pub trait StepExecutor: Send + Sync {
    /// Re-observe the page (stable-node-id element list).
    async fn observe(&self) -> AppResult<Observation>;
    /// Navigate the page. Returns `Navigated` when the document changed,
    /// otherwise `Changed`.
    async fn navigate(&self, url: &str) -> AppResult<Verdict>;
    /// Click by stable ref (`nN`). Produces a real verdict: `Changed` on
    /// success, `Intercepted` when another element covers the target, `Unknown`
    /// when the ref no longer resolves (stale — re-observe).
    async fn click(&self, ref_id: &str) -> AppResult<Verdict>;
    /// Type into the focused element.
    async fn type_text(&self, text: &str) -> AppResult<Verdict>;
    /// Fill (clear + set) an element by ref. `ValuesLost` when the value did
    /// not stick (a submit cleared the field), `Unknown` on a stale ref.
    async fn fill(&self, ref_id: &str, value: &str) -> AppResult<Verdict>;
    /// Extract text/records from the page.
    async fn extract(&self, selector: Option<&str>) -> AppResult<String>;
    /// Capture a screenshot (bytes; the controller decides where to put it).
    async fn screenshot(&self) -> AppResult<Vec<u8>>;
    /// Submit the current form. `Navigated` when the page changed, else
    /// `Changed`.
    async fn submit(&self) -> AppResult<Verdict>;
    /// Render the current page to PDF.
    async fn pdf(&self, format: &str, landscape: bool) -> AppResult<Vec<u8>>;
    /// Wait for text to appear.
    async fn wait_text(&self, text: &str) -> AppResult<()>;
    /// Scroll.
    async fn scroll(&self, direction: &str, amount: Option<u32>) -> AppResult<Verdict>;
    /// Hover by ref.
    async fn hover(&self, ref_id: &str) -> AppResult<Verdict>;
    /// The current URL (post-run deterministic re-read, §5).
    async fn current_url(&self) -> AppResult<String>;
}

/// Options for a single run.
#[derive(Debug, Clone)]
pub struct RunOptions {
    /// The dictated instruction.
    pub task: String,
    /// Maximum brain turns before halting.
    pub max_steps: usize,
    /// The task's starting origin (already set by the driver's initial nav).
    pub start_origin: Option<Origin>,
    /// User allowlist for private hosts (normally empty).
    pub allowlist: Vec<String>,
    /// Whether destructive actions require human confirmation (§4.2). When
    /// `false`, destructive actions are auto-approved (read-only/test mode).
    pub confirmation_required: bool,
}

/// A boxed `bool` future (so callers can return either style of async).
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// The async confirmation hook: returns `true` to allow the action, `false` to
/// deny. Async so an implementation can block on a human Approve/Deny
/// round-trip (overlay → IPC → reply). Decoupled from Tauri so the loop is
/// unit-testable.
pub type ConfirmFn<'a> = dyn Fn(&BrowserAction) -> BoxFuture<'a, bool> + 'a;

impl Default for RunOptions {
    fn default() -> Self {
        Self {
            task: String::new(),
            max_steps: 10,
            start_origin: None,
            allowlist: Vec::new(),
            confirmation_required: true,
        }
    }
}

/// Strip a ```json … ``` (or ```…```) fence, then return the first balanced
/// `{…}` span — the defensive parse the local models need (§3; already proven
/// by the `agent_probe` spike and `parse_label_response`).
fn extract_json(raw: &str) -> &str {
    let t = raw.trim();
    let t = if let Some(rest) = t.strip_prefix("```") {
        let rest = rest.strip_prefix("json").unwrap_or(rest);
        let rest = rest.strip_prefix("JSON").unwrap_or(rest);
        rest.trim_start()
    } else {
        t
    };
    if let (Some(start), Some(end)) = (t.find('{'), t.rfind('}')) {
        if end > start {
            return &t[start..=end];
        }
    }
    t
}

/// Parse one brain reply into a `BrowserAction`, tolerating fences and trailing
/// prose. `None` on an unparseable reply.
pub fn parse_action(raw: &str) -> Option<BrowserAction> {
    serde_json::from_str::<BrowserAction>(extract_json(raw)).ok()
}

/// Run the ReAct loop to completion. Returns the `RunResult` (or an error only
/// for hard infrastructure failures — a model that never produces `done` is a
/// `completed == false` result with its steps, not an `Err`).
pub async fn run(
    brain: &dyn CleanupProvider,
    exec: &dyn StepExecutor,
    opts: &RunOptions,
    confirm: &ConfirmFn<'_>,
    stop: Option<&std::sync::atomic::AtomicBool>,
) -> AppResult<RunResult> {
    let mut result = RunResult::default();
    let start_origin = opts.start_origin.clone();

    // Seed the first observation from the already-navigated page.
    let mut observation = exec.observe().await?;

    for _turn in 0..opts.max_steps {
        // Cooperative stop check (§4.6 kill switch): halt between steps.
        if stop.is_some_and(|s| s.load(std::sync::atomic::Ordering::SeqCst)) {
            result.summary = "stopped by user".into();
            break;
        }

        let remaining = opts.max_steps.saturating_sub(result.steps.len());
        let prompt = brain_prompt(remaining);
        let user = format!(
            "Goal: {task}\n\nCurrent page:\n{page}\n",
            task = opts.task,
            page = observation.to_prompt_fragment()
        );

        let raw = brain
            .complete(&prompt, &user, 0.1)
            .await
            .map_err(|e| AppError::Other(format!("brain: {e}")))?;

        let action = match parse_action(&raw) {
            Some(a) => a,
            None => {
                // One retry with feedback (§3), then halt.
                let feedback = "Your last reply was not valid JSON in the required shape. \
                                Return ONLY a single JSON object from the allowed set.";
                let combined = format!("{user}\n\n{feedback}");
                let raw2 = brain
                    .complete(&prompt, &combined, 0.1)
                    .await
                    .map_err(|e| AppError::Other(format!("brain (retry): {e}")))?;
                match parse_action(&raw2) {
                    Some(a) => a,
                    None => {
                        result.summary = "brain could not produce a valid action".into();
                        break;
                    }
                }
            }
        };

        // Terminal action.
        if let BrowserAction::Done { summary } = &action {
            result.completed = true;
            result.summary = summary.clone();
            break;
        }

        // Safety gate (§4): decide approval before dispatch.
        let (decision, reason) = approve(&action, start_origin.as_ref(), &opts.allowlist);
        match decision {
            Approval::Deny => {
                result.summary = format!("action blocked by safety gate: {reason}");
                break;
            }
            Approval::RequiresConfirmation
                if opts.confirmation_required && !confirm(&action).await =>
            {
                result.summary = format!("action denied by the user: {reason}");
                break;
            }
            _ => {}
        }

        // Dispatch + read the verdict.
        let (verdict, detail) = dispatch(exec, &action).await?;
        result.steps.push(crate::agent::AgentStep {
            turn: (result.steps.len() + 1) as u32,
            action,
            verdict,
            detail,
        });

        // Re-observe for the next turn.
        observation = exec.observe().await?;
    }

    // Post-run deterministic re-read (§5): confirm where we ended up,
    // independent of the model's `done` claim.
    result.final_url = exec.current_url().await.ok();

    if !result.completed && result.summary.is_empty() {
        result.summary = format!(
            "stopped after {} step(s) without `done`",
            result.steps.len()
        );
    }

    Ok(result)
}

/// Dispatch a single action to the executor and map the outcome to a
/// `(Verdict, detail)` pair. The verdict now comes from the executor's own
/// read-back (§3): `navigate`/`submit` report `Navigated` on a document change,
/// `click` reports `Intercepted`/`Unknown` on a covered or stale ref, `fill`
/// reports `ValuesLost` when the value did not stick.
async fn dispatch(exec: &dyn StepExecutor, action: &BrowserAction) -> AppResult<(Verdict, String)> {
    let r = match action {
        BrowserAction::Navigate { url } => {
            let verdict = exec.navigate(url).await?;
            (verdict, String::new())
        }
        BrowserAction::Click { ref_id } => {
            let verdict = exec.click(ref_id).await?;
            (verdict, ref_id.clone())
        }
        BrowserAction::Type { text } => {
            let verdict = exec.type_text(text).await?;
            (verdict, String::new())
        }
        BrowserAction::Fill { ref_id, value } => {
            let verdict = exec.fill(ref_id, value).await?;
            (verdict, ref_id.clone())
        }
        BrowserAction::Extract { selector } => {
            let text = exec.extract(selector.as_deref()).await?;
            let detail = if text.len() > 120 {
                format!("{}…", &text[..120])
            } else {
                text
            };
            (Verdict::Changed, detail)
        }
        BrowserAction::Screenshot => {
            let _ = exec.screenshot().await?;
            (Verdict::Changed, String::new())
        }
        BrowserAction::Submit => {
            let verdict = exec.submit().await?;
            (verdict, String::new())
        }
        BrowserAction::Pdf { format, landscape } => {
            let _ = exec.pdf(format, *landscape).await?;
            (Verdict::Changed, String::new())
        }
        BrowserAction::Wait { text } => {
            exec.wait_text(text).await?;
            (Verdict::Changed, text.clone())
        }
        BrowserAction::Scroll { direction, amount } => {
            let verdict = exec.scroll(direction, *amount).await?;
            (verdict, direction.clone())
        }
        BrowserAction::Hover { ref_id } => {
            let verdict = exec.hover(ref_id).await?;
            (verdict, ref_id.clone())
        }
        BrowserAction::Done { .. } => unreachable!("done handled before dispatch"),
    };
    Ok(r)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::agent::observe::from_elements;
    use std::sync::atomic::{AtomicUsize, Ordering};

    #[test]
    fn parses_action_from_fenced_reply() {
        let raw = "```json\n{\"tool\":\"click\",\"ref_id\":\"n3\"}\n```";
        assert_eq!(
            parse_action(raw),
            Some(BrowserAction::Click {
                ref_id: "n3".into()
            })
        );
        let raw = "I will click it now: {\"tool\":\"done\",\"summary\":\"ok\"}";
        assert!(matches!(
            parse_action(raw),
            Some(BrowserAction::Done { .. })
        ));
        assert_eq!(parse_action("no json here"), None);
    }

    struct FakeExec {
        clicks: std::sync::Arc<AtomicUsize>,
        url: std::sync::Mutex<String>,
        /// What `fill` reports (lets tests exercise `ValuesLost`/`Unknown`).
        fill_verdict: Verdict,
        /// What `click` reports (lets tests exercise `NoEffect`/`Intercepted`).
        click_verdict: Verdict,
    }

    #[async_trait::async_trait]
    impl StepExecutor for FakeExec {
        async fn observe(&self) -> AppResult<Observation> {
            Ok(from_elements("https://example.com", "T", vec![]))
        }
        async fn navigate(&self, url: &str) -> AppResult<Verdict> {
            let changed = *self.url.lock().unwrap() != url;
            *self.url.lock().unwrap() = url.to_string();
            Ok(if changed {
                Verdict::Navigated
            } else {
                Verdict::Changed
            })
        }
        async fn click(&self, _ref_id: &str) -> AppResult<Verdict> {
            self.clicks.fetch_add(1, Ordering::SeqCst);
            Ok(self.click_verdict.clone())
        }
        async fn type_text(&self, _text: &str) -> AppResult<Verdict> {
            Ok(Verdict::Changed)
        }
        async fn fill(&self, _ref_id: &str, _value: &str) -> AppResult<Verdict> {
            Ok(self.fill_verdict.clone())
        }
        async fn extract(&self, _selector: Option<&str>) -> AppResult<String> {
            Ok(String::new())
        }
        async fn screenshot(&self) -> AppResult<Vec<u8>> {
            Ok(Vec::new())
        }
        async fn submit(&self) -> AppResult<Verdict> {
            Ok(Verdict::Changed)
        }
        async fn pdf(&self, _format: &str, _landscape: bool) -> AppResult<Vec<u8>> {
            Ok(Vec::new())
        }
        async fn wait_text(&self, _text: &str) -> AppResult<()> {
            Ok(())
        }
        async fn scroll(&self, _direction: &str, _amount: Option<u32>) -> AppResult<Verdict> {
            Ok(Verdict::Changed)
        }
        async fn hover(&self, _ref_id: &str) -> AppResult<Verdict> {
            Ok(Verdict::Changed)
        }
        async fn current_url(&self) -> AppResult<String> {
            Ok(self.url.lock().unwrap().clone())
        }
    }

    struct ScriptBrain {
        script: Vec<&'static str>,
        idx: AtomicUsize,
    }

    #[async_trait::async_trait]
    impl CleanupProvider for ScriptBrain {
        fn id(&self) -> &'static str {
            "script"
        }

        async fn polish(
            &self,
            _text: &str,
            _category: crate::config::AppCategory,
        ) -> AppResult<(String, crate::cleanup::providers::PolishMeta)> {
            unreachable!("script brain is not a polish provider")
        }

        async fn complete(&self, _s: &str, _u: &str, _t: f64) -> AppResult<String> {
            let i = self.idx.fetch_add(1, Ordering::SeqCst);
            Ok(self
                .script
                .get(i)
                .copied()
                .unwrap_or("{\"tool\":\"done\",\"summary\":\"done\"}")
                .to_string())
        }
    }

    #[tokio::test]
    async fn loop_iterates_and_terminates_on_done() {
        let brain = ScriptBrain {
            script: vec![
                "{\"tool\":\"click\",\"ref_id\":\"n3\"}",
                "{\"tool\":\"done\",\"summary\":\"submitted\"}",
            ],
            idx: AtomicUsize::new(0),
        };
        let exec = FakeExec {
            clicks: std::sync::Arc::new(AtomicUsize::new(0)),
            url: std::sync::Mutex::new("https://example.com".into()),
            fill_verdict: Verdict::Changed,
            click_verdict: Verdict::Changed,
        };
        let opts = RunOptions {
            task: "click submit".into(),
            max_steps: 5,
            confirmation_required: false,
            ..RunOptions::default()
        };
        let result = run(&brain, &exec, &opts, &|_| Box::pin(async { true }), None)
            .await
            .unwrap();
        assert!(result.completed);
        assert_eq!(result.summary, "submitted");
        assert_eq!(result.steps.len(), 1);
        assert_eq!(exec.clicks.load(Ordering::SeqCst), 1);
        assert_eq!(result.final_url.as_deref(), Some("https://example.com"));
    }

    #[tokio::test]
    async fn destructive_confirmation_can_deny() {
        let brain = ScriptBrain {
            script: vec![
                "{\"tool\":\"submit\"}",
                "{\"tool\":\"done\",\"summary\":\"x\"}",
            ],
            idx: AtomicUsize::new(0),
        };
        let exec = FakeExec {
            clicks: std::sync::Arc::new(AtomicUsize::new(0)),
            url: std::sync::Mutex::new("https://example.com".into()),
            fill_verdict: Verdict::Changed,
            click_verdict: Verdict::Changed,
        };
        let opts = RunOptions {
            task: "submit".into(),
            max_steps: 5,
            confirmation_required: true,
            ..RunOptions::default()
        };
        let result = run(&brain, &exec, &opts, &|_| Box::pin(async { false }), None)
            .await
            .unwrap();
        assert!(!result.completed);
        assert!(result.summary.contains("denied"));
        assert!(result.steps.is_empty());
    }

    #[tokio::test]
    async fn async_confirm_round_trip_approves() {
        // Prove the async hook path: the closure returns a future that resolves
        // true, and the loop proceeds past a destructive click.
        let brain = ScriptBrain {
            script: vec![
                "{\"tool\":\"click\",\"ref_id\":\"n3\"}",
                "{\"tool\":\"done\",\"summary\":\"ok\"}",
            ],
            idx: AtomicUsize::new(0),
        };
        let exec = FakeExec {
            clicks: std::sync::Arc::new(AtomicUsize::new(0)),
            url: std::sync::Mutex::new("https://example.com".into()),
            fill_verdict: Verdict::Changed,
            click_verdict: Verdict::Changed,
        };
        let opts = RunOptions {
            task: "click".into(),
            max_steps: 5,
            confirmation_required: true,
            ..RunOptions::default()
        };
        let result = run(
            &brain,
            &exec,
            &opts,
            &|a| {
                let a = a.clone();
                Box::pin(async move { matches!(a, BrowserAction::Click { .. }) })
            },
            None,
        )
        .await
        .unwrap();
        assert!(result.completed);
        assert_eq!(result.steps.len(), 1);
    }

    #[tokio::test]
    async fn stop_flag_halts_between_steps() {
        // The loop runs a long script, but a pre-set stop flag halts it before
        // the first action is dispatched.
        let brain = ScriptBrain {
            script: vec![
                "{\"tool\":\"click\",\"ref_id\":\"n3\"}",
                "{\"tool\":\"done\",\"summary\":\"should not reach\"}",
            ],
            idx: AtomicUsize::new(0),
        };
        let exec = FakeExec {
            clicks: std::sync::Arc::new(AtomicUsize::new(0)),
            url: std::sync::Mutex::new("https://example.com".into()),
            fill_verdict: Verdict::Changed,
            click_verdict: Verdict::Changed,
        };
        let opts = RunOptions {
            task: "click".into(),
            max_steps: 5,
            confirmation_required: false,
            ..RunOptions::default()
        };
        let stop = std::sync::atomic::AtomicBool::new(true);
        let result = run(
            &brain,
            &exec,
            &opts,
            &|_| Box::pin(async { true }),
            Some(&stop),
        )
        .await
        .unwrap();
        assert!(!result.completed);
        assert_eq!(result.summary, "stopped by user");
        assert!(result.steps.is_empty());
        assert_eq!(exec.clicks.load(Ordering::SeqCst), 0);
    }

    #[tokio::test]
    async fn fill_verdicts_are_recorded() {
        // A `fill` whose read-back reports `ValuesLost` (a submit cleared the
        // field) must surface that verdict in the step, not a fabricated
        // `Changed`.
        let brain = ScriptBrain {
            script: vec![
                "{\"tool\":\"fill\",\"ref_id\":\"n2\",\"value\":\"x\"}",
                "{\"tool\":\"done\",\"summary\":\"done\"}",
            ],
            idx: AtomicUsize::new(0),
        };
        let exec = FakeExec {
            clicks: std::sync::Arc::new(AtomicUsize::new(0)),
            url: std::sync::Mutex::new("https://example.com".into()),
            fill_verdict: Verdict::ValuesLost,
            click_verdict: Verdict::Changed,
        };
        let opts = RunOptions {
            task: "fill".into(),
            max_steps: 5,
            confirmation_required: false,
            ..RunOptions::default()
        };
        let result = run(&brain, &exec, &opts, &|_| Box::pin(async { true }), None)
            .await
            .unwrap();
        assert_eq!(result.steps.len(), 1);
        assert_eq!(result.steps[0].verdict, Verdict::ValuesLost);
    }

    #[tokio::test]
    async fn no_effect_verdict_is_recorded() {
        // A click whose state-diff reads `NoEffect` (nothing changed) surfaces
        // that verdict in the step.
        let brain = ScriptBrain {
            script: vec![
                "{\"tool\":\"click\",\"ref_id\":\"n3\"}",
                "{\"tool\":\"done\",\"summary\":\"done\"}",
            ],
            idx: AtomicUsize::new(0),
        };
        let exec = FakeExec {
            clicks: std::sync::Arc::new(AtomicUsize::new(0)),
            url: std::sync::Mutex::new("https://example.com".into()),
            fill_verdict: Verdict::Changed,
            click_verdict: Verdict::NoEffect,
        };
        let opts = RunOptions {
            task: "click".into(),
            max_steps: 5,
            confirmation_required: false,
            ..RunOptions::default()
        };
        let result = run(&brain, &exec, &opts, &|_| Box::pin(async { true }), None)
            .await
            .unwrap();
        assert_eq!(result.steps.len(), 1);
        assert_eq!(result.steps[0].verdict, Verdict::NoEffect);
    }
}
