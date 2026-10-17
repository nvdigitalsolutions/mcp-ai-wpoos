//! Action vocabulary helpers: the safety buckets (§4/§4a) and the brain prompt.
//!
//! Pure, always-compiled, unit-testable. The safety classification mirrors the
//! plugin's `get_capability_flags()` triad so a desktop-run and an
//! assistant-driven run cannot disagree about what is safe:

use crate::agent::BrowserAction;

/// The three safety buckets each action maps to (mirrors the plugin's
/// `read-only` / `external-api` / destructive-write capability flags, §4a).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SafetyClass {
    /// Reads the page only — always allowed, no confirmation.
    ReadOnly,
    /// Makes outbound requests / crosses origins — origin-guarded (§4.3).
    External,
    /// Mutates external state irreversibly — requires human confirmation (§4.2).
    Destructive,
}

/// Classify an action into its safety bucket.
pub fn classify(action: &BrowserAction) -> SafetyClass {
    match action {
        // Reads.
        BrowserAction::Extract { .. } | BrowserAction::Screenshot => SafetyClass::ReadOnly,
        // External / side-effecting but recoverable.
        BrowserAction::Navigate { .. }
        | BrowserAction::Wait { .. }
        | BrowserAction::Scroll { .. } => SafetyClass::External,
        // Mutations that need confirmation.
        BrowserAction::Click { .. }
        | BrowserAction::Type { .. }
        | BrowserAction::Fill { .. }
        | BrowserAction::Hover { .. }
        | BrowserAction::Submit
        | BrowserAction::Pdf { .. } => SafetyClass::Destructive,
        // Terminal — never dispatched itself.
        BrowserAction::Done { .. } => SafetyClass::ReadOnly,
    }
}

/// The system prompt that forces **one tool call per turn** and bakes the exact
/// JSON shape as in-prompt examples (the highest-leverage small-model
/// reliability fix per §3).
pub fn brain_prompt(remaining: usize) -> String {
    format!(
        "You are a browser automation agent. You are given the current page (an \
         accessibility-tree excerpt with `ref` ids), a goal, and the last step's \
         result (its verdict). Decide the SINGLE next action and return ONLY a \
         JSON object, no prose, no markdown fences.\n\
         \n\
         The action object MUST use one of these exact shapes:\n\
         {{\"tool\":\"navigate\",\"url\":\"https://...\"}}\n\
         {{\"tool\":\"click\",\"ref_id\":\"e3\"}}\n\
         {{\"tool\":\"type\",\"text\":\"some text\"}}\n\
         {{\"tool\":\"fill\",\"ref_id\":\"e2\",\"value\":\"text\"}}\n\
         {{\"tool\":\"extract\",\"selector\":null}}\n\
         {{\"tool\":\"screenshot\"}}\n\
         {{\"tool\":\"submit\"}}\n\
         {{\"tool\":\"pdf\",\"format\":\"A4\",\"landscape\":false}}\n\
         {{\"tool\":\"wait\",\"text\":\"some text to wait for\"}}\n\
         {{\"tool\":\"scroll\",\"direction\":\"down\",\"amount\":null}}\n\
         {{\"tool\":\"hover\",\"ref_id\":\"e3\"}}\n\
         {{\"tool\":\"done\",\"summary\":\"a one-line result\"}}\n\
         \n\
         Return `done` only when the goal is satisfied or is impossible. \
         You have at most {remaining} step(s) remaining."
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn classifies_reads_and_destructive() {
        assert_eq!(
            classify(&BrowserAction::Extract { selector: None }),
            SafetyClass::ReadOnly
        );
        assert_eq!(classify(&BrowserAction::Screenshot), SafetyClass::ReadOnly);
        assert_eq!(
            classify(&BrowserAction::Click {
                ref_id: "e1".into()
            }),
            SafetyClass::Destructive
        );
        assert_eq!(classify(&BrowserAction::Submit), SafetyClass::Destructive);
        assert_eq!(
            classify(&BrowserAction::Navigate {
                url: "https://x".into()
            }),
            SafetyClass::External
        );
    }

    #[test]
    fn prompt_bakes_single_call_and_budget() {
        let p = brain_prompt(5);
        assert!(p.contains("SINGLE next action"));
        assert!(p.contains("5 step(s) remaining"));
        assert!(p.contains("\"tool\":\"click\""));
    }
}
