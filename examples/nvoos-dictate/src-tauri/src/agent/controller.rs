//! `AgentController`: owns the CDP `Driver` on a dedicated `nvoos-agent` worker
//! thread (the `Browser`/`Handler` are `!Sync`, so they cannot live in `AppState`
//! — the same ownership rule as `cpal::Stream` and `nokhwa::Camera`), runs the
//! ReAct loop against a `CleanupProvider` brain, and publishes the result as a
//! pollable snapshot. It also owns the async Approve/Deny approval slot and the
//! cooperative stop flag (§4).

use std::sync::{Arc, Mutex};

use crate::agent::agent_loop::RunOptions;
#[cfg(feature = "agent")]
use crate::agent::agent_loop::{run, StepExecutor};
#[cfg(feature = "agent")]
use crate::agent::observe::Observation;
#[cfg(feature = "agent")]
use crate::agent::Verdict;
use crate::agent::{available, RunResult};
use crate::cleanup::providers::CleanupProvider;
use crate::error::{AppError, AppResult};

/// Pollable state published by the controller (mirrors `VisionSnapshot`).
#[derive(Default, Clone, serde::Serialize)]
pub struct AgentSnapshot {
    /// Whether a run is currently executing.
    pub running: bool,
    /// The last completed run's result, if any.
    pub last: Option<RunResult>,
    /// The last error, if the run failed hard.
    pub last_error: Option<String>,
}

/// A pending destructive-action approval awaiting a human answer.
#[derive(Debug, Clone, serde::Serialize)]
pub struct PendingApproval {
    /// The action the model proposed (serializable for the overlay).
    pub action: serde_json::Value,
    /// A short human-readable description of what is about to happen.
    pub description: String,
}

/// The pending-approval slot: the surfaced action + the one-shot async answer
/// channel the worker awaits.
pub type ApprovalSlot = Arc<Mutex<Option<(PendingApproval, tokio::sync::oneshot::Sender<bool>)>>>;

/// The resident browser-agent controller.
pub struct AgentController {
    pub snapshot: Arc<Mutex<AgentSnapshot>>,
    /// The in-flight approval (action + description) plus the one-shot async
    /// answer channel the worker awaits. `None` when nothing awaits approval.
    pending: ApprovalSlot,
    /// Cooperative stop flag: flipped by `stop()`, polled by the loop between
    /// steps (§4.6 kill switch).
    stop: Arc<std::sync::atomic::AtomicBool>,
}

impl AgentController {
    /// A controller with no worker started yet. Safe to construct in every
    /// build (the driver is only touched on `run`).
    pub fn new() -> Self {
        Self {
            snapshot: Arc::new(Mutex::new(AgentSnapshot::default())),
            pending: Arc::new(Mutex::new(None)),
            stop: Arc::new(std::sync::atomic::AtomicBool::new(false)),
        }
    }

    /// Whether the driver is compiled into this build.
    pub fn available(&self) -> bool {
        available()
    }

    /// The action+description currently awaiting a human answer, if any.
    pub fn pending_approval(&self) -> Option<PendingApproval> {
        self.pending
            .lock()
            .unwrap()
            .as_ref()
            .map(|(p, _)| p.clone())
    }

    /// Answer the pending approval. Returns false if there is nothing pending
    /// (no-op), and clears the slot regardless.
    pub fn respond_approval(&self, approve: bool) -> bool {
        let mut guard = self.pending.lock().unwrap();
        match guard.take() {
            Some((_pending, tx)) => {
                let _ = tx.send(approve);
                true
            }
            None => false,
        }
    }

    /// Set the cooperative stop flag. The running loop checks it between steps
    /// and halts. Safe to call from any thread (it is an idempotent atomic
    /// write).
    pub fn stop(&self) {
        self.stop.store(true, std::sync::atomic::Ordering::SeqCst);
    }

    /// Start an agent run on a worker thread. Returns immediately; poll
    /// `snapshot()` for progress and the result. Without the `agent` feature
    /// this returns a clear "not compiled" error.
    pub fn start(&self, brain: Arc<dyn CleanupProvider>, opts: RunOptions) -> AppResult<()> {
        self.start_inner(brain, opts)
    }

    #[cfg(feature = "agent")]
    fn start_inner(&self, brain: Arc<dyn CleanupProvider>, opts: RunOptions) -> AppResult<()> {
        {
            let sn = self.snapshot.lock().unwrap();
            if sn.running {
                return Err(AppError::Other(
                    "an agent run is already in progress".into(),
                ));
            }
        }
        // Reject an empty task before we pay for a browser launch.
        if opts.task.trim().is_empty() {
            return Err(AppError::Other("agent task must not be empty".into()));
        }

        // Reset the stop flag for the fresh run, and publish it to the worker.
        self.stop.store(false, std::sync::atomic::Ordering::SeqCst);

        let snapshot = self.snapshot.clone();
        let pending = self.pending.clone();
        let stop = self.stop.clone();

        std::thread::Builder::new()
            .name("nvoos-agent".into())
            .spawn(move || {
                let rt = match tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                {
                    Ok(rt) => rt,
                    Err(e) => {
                        let mut sn = snapshot.lock().unwrap();
                        sn.running = false;
                        sn.last_error = Some(format!("runtime: {e}"));
                        return;
                    }
                };

                rt.block_on(async {
                    {
                        let mut sn = snapshot.lock().unwrap();
                        sn.running = true;
                        sn.last = None;
                        sn.last_error = None;
                    }

                    let result = run_inner(&brain, &opts, &pending, &stop).await;

                    let mut sn = snapshot.lock().unwrap();
                    match result {
                        Ok(r) => sn.last = Some(r),
                        Err(e) => sn.last_error = Some(e.to_string()),
                    }
                    // Clear any stale pending approval left behind by a halt.
                    pending.lock().unwrap().take();
                    stop.store(false, std::sync::atomic::Ordering::SeqCst);
                    sn.running = false;
                });
            })
            .map_err(|e| AppError::Other(format!("could not spawn agent worker: {e}")))?;

        Ok(())
    }

    #[cfg(not(feature = "agent"))]
    fn start_inner(&self, _brain: Arc<dyn CleanupProvider>, _opts: RunOptions) -> AppResult<()> {
        Err(AppError::Unsupported(
            "browser-agent driver is not compiled into this build".into(),
        ))
    }

    /// A copy of the current snapshot.
    pub fn snapshot(&self) -> AgentSnapshot {
        self.snapshot.lock().unwrap().clone()
    }
}

impl Default for AgentController {
    fn default() -> Self {
        Self::new()
    }
}

/// The actual driver-backed run. Feature-gated: without the `agent` feature the
/// non-agent `start_inner` returns before this is ever reached.
#[cfg(feature = "agent")]
async fn run_inner(
    brain: &Arc<dyn CleanupProvider>,
    opts: &RunOptions,
    pending: &ApprovalSlot,
    stop: &Arc<std::sync::atomic::AtomicBool>,
) -> AppResult<RunResult> {
    let driver = crate::agent::driver::Driver::launch(None, None, true, "about:blank").await?;
    let exec = DriverExecutor { driver };

    // The async Approve/Deny gate (§4.2): publish the proposed action to the
    // `pending` slot and await the UI's answer over a oneshot channel.
    let pending = pending.clone();
    let confirmation_required = opts.confirmation_required;
    let confirm = move |action: &crate::agent::BrowserAction| {
        let action = action.clone();
        let pending = pending.clone();
        Box::pin(async move {
            if confirmation_required {
                let (tx, rx) = tokio::sync::oneshot::channel::<bool>();
                {
                    let mut guard = pending.lock().unwrap();
                    *guard = Some((
                        PendingApproval {
                            action: serde_json::to_value(&action)
                                .unwrap_or(serde_json::Value::Null),
                            description: describe_action(&action),
                        },
                        tx,
                    ));
                }
                rx.await.unwrap_or(false)
            } else {
                true
            }
        }) as crate::agent::agent_loop::BoxFuture<'_, bool>
    };

    run(brain.as_ref(), &exec, opts, &confirm, Some(stop)).await
}

/// A short human-readable description of a proposed action, for the overlay.
#[cfg(feature = "agent")]
fn describe_action(action: &crate::agent::BrowserAction) -> String {
    use crate::agent::BrowserAction::*;
    match action {
        Navigate { url } => format!("Navigate to {url}"),
        Click { ref_id } => format!("Click element {ref_id}"),
        Type { text } => format!("Type \"{text}\""),
        Fill { ref_id, value } => format!("Fill {ref_id} with \"{value}\""),
        Extract { .. } => "Extract page content".into(),
        Screenshot => "Capture a screenshot".into(),
        Submit => "Submit the form".into(),
        Pdf { format, .. } => format!("Print the page to {format}"),
        Wait { text } => format!("Wait for \"{text}\""),
        Scroll { direction, .. } => format!("Scroll {direction}"),
        Hover { ref_id } => format!("Hover element {ref_id}"),
        // `Done` is handled before the approval gate, so it never reaches
        // `describe_action` — omitted to avoid an unreachable arm.
        Done { .. } => unreachable!("done is handled before approval"),
    }
}

/// Adapter: implements `agent_loop::StepExecutor` over the concrete
/// chromiumoxide `Driver`. Feature-gated because it references the driver.
#[cfg(feature = "agent")]
struct DriverExecutor {
    driver: crate::agent::driver::Driver,
}

#[cfg(feature = "agent")]
#[async_trait::async_trait]
impl StepExecutor for DriverExecutor {
    async fn observe(&self) -> AppResult<Observation> {
        self.driver.observe().await
    }
    async fn navigate(&self, url: &str) -> AppResult<Verdict> {
        let before = self.driver.current_url().await.unwrap_or_default();
        self.driver.navigate(url).await?;
        let after = self.driver.current_url().await.unwrap_or_default();
        Ok(if !after.is_empty() && after != before {
            Verdict::Navigated
        } else {
            Verdict::Changed
        })
    }
    async fn click(&self, ref_id: &str) -> AppResult<Verdict> {
        self.driver.click_ref(ref_id).await
    }
    async fn type_text(&self, text: &str) -> AppResult<Verdict> {
        self.driver.insert_text(text).await?;
        Ok(Verdict::Changed)
    }
    async fn fill(&self, ref_id: &str, value: &str) -> AppResult<Verdict> {
        self.driver.fill_ref(ref_id, value).await
    }
    async fn extract(&self, _selector: Option<&str>) -> AppResult<String> {
        self.driver.extract_text().await
    }
    async fn screenshot(&self) -> AppResult<Vec<u8>> {
        self.driver.screenshot().await
    }
    async fn submit(&self) -> AppResult<Verdict> {
        self.driver.press_enter().await
    }
    async fn pdf(&self, format: &str, landscape: bool) -> AppResult<Vec<u8>> {
        self.driver.pdf(format, landscape).await
    }
    async fn wait_text(&self, text: &str) -> AppResult<()> {
        // 15 s is the v1 self-healing budget (the loop's per-step timeout can
        // be tightened once `AgentConfig` lands).
        self.driver.wait_text(text, 15_000).await
    }
    async fn scroll(&self, direction: &str, amount: Option<u32>) -> AppResult<Verdict> {
        let px = amount.unwrap_or(400) as f64;
        let dy = match direction {
            "up" => -px,
            _ => px,
        };
        self.driver.scroll_page(dy).await
    }
    async fn hover(&self, ref_id: &str) -> AppResult<Verdict> {
        self.driver.hover_ref(ref_id).await
    }
    async fn current_url(&self) -> AppResult<String> {
        self.driver.current_url().await
    }
}
