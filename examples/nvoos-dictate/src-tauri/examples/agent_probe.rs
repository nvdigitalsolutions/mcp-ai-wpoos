//! Browser-agent de-risk spike (Phase 0 of `docs/browser-agent-plan.md`).
//!
//! The de-risk step, mirroring `vision_probe`: prove that a *local* model can
//! emit a parseable, single-tool-call action through the app's existing
//! `LocalOpenAiProvider` transport (via the public `CleanupProvider::complete`
//! seam), and that a tiny synthetic "brain loop" terminates deterministically
//! against a **canned** observation. No real browser is driven here — that is
//! Phase 1's CDP module; this spike isolates the "can my local model drive a
//! tool loop at all?" question first (the browser-use local-4B lesson).
//!
//! Usage (Ollama on localhost:11434, model `qwen2.5:7b-instruct`):
//!   cargo run --release --features agent --example agent_probe -- \
//!       --provider ollama --model qwen2.5:7b-instruct \
//!       --task "click the 'Submit' button"
//!
//! Prints, per turn: the raw model reply, the parsed action (or a parse
//! failure retried once with feedback), and a final PASS/FAIL on the three
//! Phase-0 gates: (a) a tool call was emitted, (b) it parsed, (c) the loop
//! reached `done` within the step budget.

use std::env;

use nvoos_dictate_lib::cleanup::providers::lm_studio::LmStudioProvider;
use nvoos_dictate_lib::cleanup::providers::ollama::OllamaProvider;
use nvoos_dictate_lib::cleanup::providers::CleanupProvider;
use nvoos_dictate_lib::config::{LmStudioConfig, OllamaConfig};
use nvoos_dictate_lib::state::InMemorySecretStore;

/// The minimal action vocabulary the agent will own in Phase 1. Kept here
/// (not yet in `agent/`) so the spike has a concrete parse target.
#[derive(Debug, Clone, PartialEq, serde::Deserialize)]
#[serde(tag = "tool", rename_all = "snake_case")]
enum BrowserAction {
    Navigate { url: String },
    Click { ref_id: String },
    Fill { ref_id: String, value: String },
    Wait { text: String },
    Done { summary: String },
}

/// System prompt that forces one tool call per turn and bakes the exact JSON
/// shape as an in-prompt example (the highest-leverage small-model reliability
/// fix per the plan §3).
fn brain_prompt(remaining: usize) -> String {
    format!(
        "You are a browser automation agent. You are given the current page (an \
         accessibility-tree excerpt with `ref` ids), a goal, and the last step's \
         result. Decide the SINGLE next action and return ONLY a JSON object, no \
         prose, no markdown fences.\n\
         \n\
         The action object MUST use one of these exact shapes:\n\
         {{\"tool\":\"navigate\",\"url\":\"https://...\"}}\n\
         {{\"tool\":\"click\",\"ref_id\":\"e3\"}}\n\
         {{\"tool\":\"fill\",\"ref_id\":\"e2\",\"value\":\"text\"}}\n\
         {{\"tool\":\"wait\",\"text\":\"some text to wait for\"}}\n\
         {{\"tool\":\"done\",\"summary\":\"a one-line result\"}}\n\
         \n\
         Return `done` only when the goal is satisfied or is impossible. \
         You have at most {remaining} step(s) remaining."
    )
}

/// Parse + retry-with-feedback once. Returns the action and a success flag.
async fn next_action(
    brain: &dyn CleanupProvider,
    system: &str,
    user: &str,
) -> (Option<BrowserAction>, bool) {
    let raw = match brain.complete(system, user, 0.1).await {
        Ok(r) => r,
        Err(e) => {
            eprintln!("  [brain error] {e}");
            return (None, false);
        }
    };
    println!("  [raw]      {}", raw.replace('\n', " "));

    // Tolerate markdown fences: strip ```json … ``` wrappers before parse.
    let candidate = strip_fences(raw.trim());
    match serde_json::from_str::<BrowserAction>(candidate) {
        Ok(action) => (Some(action), true),
        Err(e) => {
            eprintln!("  [parse fail] {e} — retrying once with feedback");
            let feedback = "Your last reply was not valid JSON in the required shape. \
                            Return ONLY a single JSON object from the allowed set, no prose."
                .to_string();
            // Re-send the same context plus the feedback as a new user turn.
            next_action_with_feedback(brain, system, user, &feedback).await
        }
    }
}

/// A retry is a fresh completion whose user turn carries the feedback.
async fn next_action_with_feedback(
    brain: &dyn CleanupProvider,
    system: &str,
    user: &str,
    feedback: &str,
) -> (Option<BrowserAction>, bool) {
    let combined = format!("{user}\n\n{feedback}");
    match brain.complete(system, &combined, 0.1).await {
        Ok(raw) => {
            println!("  [raw]      {}", raw.replace('\n', " "));
            match serde_json::from_str::<BrowserAction>(strip_fences(raw.trim())) {
                Ok(a) => (Some(a), true),
                Err(e) => {
                    eprintln!("  [parse fail on retry] {e}");
                    (None, false)
                }
            }
        }
        Err(e) => {
            eprintln!("  [brain error on retry] {e}");
            (None, false)
        }
    }
}

/// Strip a ```json … ``` (or ```…```) wrapper, if present.
fn strip_fences(s: &str) -> &str {
    let t = s.trim();
    if let Some(rest) = t.strip_prefix("```") {
        let rest = rest.strip_prefix("json").unwrap_or(rest);
        let rest = rest.strip_prefix("JSON").unwrap_or(rest);
        let rest = rest.trim_start();
        if let Some(end) = rest.rfind("```") {
            return rest[..end].trim();
        }
        return rest.trim();
    }
    t
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let mut provider = String::from("ollama");
    let mut model = String::new();
    let mut base_url: Option<String> = None;
    let mut task = String::from("click the 'Submit' button");
    let mut steps = 4u32;

    let mut args = env::args().skip(1);
    while let Some(a) = args.next() {
        match a.as_str() {
            "--provider" => provider = args.next().unwrap_or_default(),
            "--model" => model = args.next().unwrap_or_default(),
            "--base-url" => base_url = args.next(),
            "--task" => task = args.next().unwrap_or_default(),
            "--steps" => steps = args.next().and_then(|s| s.parse().ok()).unwrap_or(4),
            _ => {}
        }
    }

    let store = InMemorySecretStore::default();
    let brain: Box<dyn CleanupProvider> = match provider.as_str() {
        "ollama" => {
            let cfg = OllamaConfig {
                base_url: base_url.unwrap_or_else(|| "http://localhost:11434/v1".into()),
                model: model.clone(),
                load_timeout_ms: 120_000,
            };
            Box::new(OllamaProvider::new(&cfg, &store)?)
        }
        "lm_studio" => {
            let cfg = LmStudioConfig {
                base_url: base_url.unwrap_or_else(|| "http://localhost:1234/v1".into()),
                model: model.clone(),
                load_timeout_ms: 120_000,
            };
            Box::new(LmStudioProvider::new(&cfg, &store)?)
        }
        other => {
            eprintln!("unknown provider '{other}' (use ollama | lm_studio)");
            std::process::exit(2);
        }
    };

    println!(
        "agent_probe: provider={} task=\"{}\" max_steps={steps}",
        provider, task
    );

    // Canned observation chain — a simplified login flow. Each turn the model
    // sees the *next* observation reflecting the "result" of its prior action
    // if it matched; this is deliberate: the spike proves call/parse/terminate,
    // not DOM fidelity (that is Phase 1's job).
    let canned = [
        "url: /login\n\
         [e1] textbox \"Email\", editable\n\
         [e2] textbox \"Password\", editable\n\
         [e3] button \"Submit\"",
        "url: /login  (Email field now holds a value)\n\
         [e1] textbox \"Email\", editable\n\
         [e2] textbox \"Password\", editable (value set)\n\
         [e3] button \"Submit\"",
        "url: /dashboard\n\
         [e4] heading \"Welcome\"",
    ];

    let mut emitted_tool_call = false;
    let mut parsed_ok = false;
    let mut terminated = false;
    let mut history: Vec<String> = Vec::new();

    for turn in 0..steps {
        let remaining = steps - turn;
        let obs = canned
            .get(turn as usize)
            .copied()
            .unwrap_or("(no further observation)");
        let user = format!(
            "Goal: {task}\n\nCurrent page:\n{obs}\n\nPrevious steps:\n{}",
            if history.is_empty() {
                "(none)".to_string()
            } else {
                history.join("\n")
            }
        );
        let system = brain_prompt(remaining as usize);

        println!("\n--- turn {} ({} step(s) left) ---", turn + 1, remaining);
        let (action, ok) = next_action(brain.as_ref(), &system, &user).await;
        emitted_tool_call |= action.is_some();
        parsed_ok |= ok;

        match action {
            Some(BrowserAction::Done { summary }) => {
                println!("  [done]     {summary}");
                terminated = true;
                break;
            }
            Some(a) => {
                println!("  [action]   {a:?}");
                history.push(format!("step {}: {:?}", turn + 1, a));
            }
            None => {
                println!("  [no action] stopping");
                break;
            }
        }
    }

    println!("\n=== Phase-0 gates ===");
    println!(
        "(a) tool call emitted: {}",
        if emitted_tool_call { "PASS" } else { "FAIL" }
    );
    println!(
        "(b) action parsed:     {}",
        if parsed_ok { "PASS" } else { "FAIL" }
    );
    println!(
        "(c) reached done:      {}",
        if terminated { "PASS" } else { "FAIL" }
    );

    if emitted_tool_call && parsed_ok && terminated {
        println!("OVERALL: PASS");
        Ok(())
    } else {
        println!("OVERALL: FAIL (local model needs a better/tool-tuned model, or a prompt tweak)");
        // Exit non-zero so CI/scripts can gate on it.
        std::process::exit(1);
    }
}
