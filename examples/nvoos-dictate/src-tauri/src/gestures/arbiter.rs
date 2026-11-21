//! Ambiguity arbitration — M6 of Proposal 153 §7.3.
//!
//! When the stabiliser cannot separate two poses, a local LLM is asked to pick
//! from a **closed** set of already-allowed actions. The model is never
//! trusted: this module is the rulebook that decides what its answer is
//! *allowed* to mean, and it can only ever narrow the outcome.
//!
//! The request carries no pixels — candidates, scores and coarse context only.
//! Sending a frame here would be an unexplained egress of camera data, which
//! the whole feature exists to avoid.
//!
//! **Status:** the pure decision core is implemented and tested; the network
//! call and the router wiring are not yet enabled (the stabiliser does not
//! currently publish an ambiguous band for the caller to react to). Keeping the
//! rulebook here, ahead of the wiring, means the safety contract is reviewable
//! and testable on its own.

use serde::{Deserialize, Serialize};

use crate::gestures::{ActionId, GestureId, Safety};

/// One candidate the stabiliser could not separate.
#[derive(Debug, Clone, Copy, PartialEq, Serialize)]
pub struct Candidate {
    pub gesture: GestureId,
    pub score: f32,
}

/// Coarse context handed to the model. Deliberately no pixels, no transcript,
/// no window titles — only what is needed to disambiguate a pose.
#[derive(Debug, Clone, Default, PartialEq, Serialize)]
pub struct ArbiterContext {
    pub overlay_open: bool,
    pub dictation_active: bool,
    pub app_category: Option<String>,
    pub last_action: Option<ActionId>,
    pub ms_since_last_action: Option<u64>,
}

/// What the model was asked, as a value (used to build the request body and to
/// assert in tests that no image field can appear).
#[derive(Debug, Clone, Serialize)]
pub struct ArbiterRequest {
    pub candidates: Vec<Candidate>,
    pub context: ArbiterContext,
    pub allowed_actions: Vec<ActionId>,
}

/// The model's reply, after parsing.
#[derive(Debug, Clone, PartialEq, Deserialize)]
pub struct ArbiterReply {
    /// `None` when the model answered `"none"` or gave nothing usable.
    pub action: Option<ActionId>,
    pub reason: String,
}

/// What the caller may actually do.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Decision {
    /// Execute this action now (it is `Safe`).
    Act(ActionId),
    /// Execute this action, but through the normal guarded flow: the pose hold
    /// is still required. Arbitration can never short-circuit confirmation.
    ActGuarded(ActionId),
    /// Do nothing.
    Ignore(IgnoreReason),
}

/// Why arbitration produced no action. Logged as a counter, never shown to the
/// user — an ambiguous pose is not an error the person needs to handle.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum IgnoreReason {
    /// The model named an action that is not in the allowed set.
    NotAllowed,
    /// The model named no action and the fallback was not a safe candidate.
    NoSafeFallback,
    /// The model named an action the router has no gesture mapping for.
    UnknownAction,
}

/// The three rejection rules from §7.3, in one place.
///
/// * an action outside `allowed` becomes `none`;
/// * a guarded action is **never** promoted to safe — it stays `ActGuarded`;
/// * the T0 fallback is used only when it is itself allowed **and** `Safe`;
/// * everything else is dropped.
pub fn resolve(reply: &ArbiterReply, allowed: &[ActionId], fallback: Option<ActionId>) -> Decision {
    if let Some(action) = reply.action {
        if !allowed.contains(&action) {
            return Decision::Ignore(IgnoreReason::NotAllowed);
        }
        return match action.safety() {
            Safety::Safe => Decision::Act(action),
            Safety::Guarded => Decision::ActGuarded(action),
        };
    }
    match fallback {
        Some(action) if allowed.contains(&action) && action.safety() == Safety::Safe => {
            Decision::Act(action)
        }
        _ => Decision::Ignore(IgnoreReason::NoSafeFallback),
    }
}

/// Build the request value. Kept as a constructor so a test can assert the
/// serialised body has no image-like key.
pub fn request(
    candidates: Vec<Candidate>,
    context: ArbiterContext,
    allowed: &[ActionId],
) -> ArbiterRequest {
    ArbiterRequest {
        candidates,
        context,
        allowed_actions: allowed.to_vec(),
    }
}

/// System prompt for the arbiter. Restates the closed vocabulary so a model
/// that has never seen this schema cannot invent an action name.
pub fn prompt() -> &'static str {
    "You disambiguate a hand gesture from two or three candidates recognised by \
     a deterministic classifier. You cannot see the camera. Choose exactly one \
     action from the supplied allowed_actions list, or \"none\". Never invent an \
     action name. Prefer \"none\" when the context does not clearly favour one \
     candidate. Reply with ONLY a JSON object: {\"action\": \"<allowed id or \
     none>\", \"reason\": \"<12 words max>\"}."
}

/// Parse the model's reply defensively: strict JSON first, then the first
/// balanced `{…}` span (verbose models wrap their answer in fences), then a
/// `none` fallback so a model that rambles cannot actuate anything.
pub fn parse_reply(raw: &str) -> ArbiterReply {
    fn from_value(value: &serde_json::Value) -> Option<ArbiterReply> {
        let action = match value.get("action") {
            Some(serde_json::Value::String(name)) => {
                if name.eq_ignore_ascii_case("none") {
                    None
                } else {
                    Some(ActionId::parse(name)?)
                }
            }
            Some(serde_json::Value::Null) | None => None,
            _ => return None,
        };
        let reason = value
            .get("reason")
            .and_then(|v| v.as_str())
            .unwrap_or_default()
            .to_string();
        Some(ArbiterReply { action, reason })
    }

    let trimmed = raw.trim();
    if let Some(reply) = serde_json::from_str::<serde_json::Value>(trimmed)
        .ok()
        .as_ref()
        .and_then(from_value)
    {
        return reply;
    }
    if let (Some(start), Some(end)) = (trimmed.find('{'), trimmed.rfind('}')) {
        if end > start {
            if let Some(reply) = serde_json::from_str::<serde_json::Value>(&trimmed[start..=end])
                .ok()
                .as_ref()
                .and_then(from_value)
            {
                return reply;
            }
        }
    }
    ArbiterReply {
        action: None,
        reason: String::new(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const SAFE: [ActionId; 3] = [
        ActionId::DismissOverlay,
        ActionId::NextItem,
        ActionId::PreviousItem,
    ];

    fn reply(action: Option<ActionId>) -> ArbiterReply {
        ArbiterReply {
            action,
            reason: "test".into(),
        }
    }

    #[test]
    fn an_allowed_safe_action_is_executed() {
        let decision = resolve(&reply(Some(ActionId::NextItem)), &SAFE, None);
        assert_eq!(decision, Decision::Act(ActionId::NextItem));
    }

    #[test]
    fn an_action_outside_the_allowlist_is_dropped() {
        let decision = resolve(&reply(Some(ActionId::TogglePreview)), &SAFE, None);
        assert_eq!(decision, Decision::Ignore(IgnoreReason::NotAllowed));
    }

    #[test]
    fn arbitration_cannot_promote_a_guarded_action_to_safe() {
        let allowed = [ActionId::StopDictation, ActionId::NextItem];
        let decision = resolve(&reply(Some(ActionId::StopDictation)), &allowed, None);
        assert_eq!(
            decision,
            Decision::ActGuarded(ActionId::StopDictation),
            "a guarded action must still go through the confirmation hold"
        );
    }

    #[test]
    fn the_fallback_is_used_only_when_it_is_allowed_and_safe() {
        assert_eq!(
            resolve(&reply(None), &SAFE, Some(ActionId::DismissOverlay)),
            Decision::Act(ActionId::DismissOverlay)
        );
        // Not in the allowlist.
        assert_eq!(
            resolve(&reply(None), &SAFE, Some(ActionId::ScrollUp)),
            Decision::Ignore(IgnoreReason::NoSafeFallback)
        );
        // Guarded: a fallback must never skip confirmation.
        assert_eq!(
            resolve(
                &reply(None),
                &[ActionId::ToggleDictation],
                Some(ActionId::ToggleDictation)
            ),
            Decision::Ignore(IgnoreReason::NoSafeFallback)
        );
    }

    #[test]
    fn no_reply_and_no_fallback_is_a_no_op() {
        assert_eq!(
            resolve(&reply(None), &SAFE, None),
            Decision::Ignore(IgnoreReason::NoSafeFallback)
        );
    }

    #[test]
    fn the_request_carries_no_pixels() {
        let body = serde_json::to_string(&request(
            vec![Candidate {
                gesture: GestureId::ClosedFist,
                score: 0.63,
            }],
            ArbiterContext::default(),
            &SAFE,
        ))
        .expect("serialises");
        for forbidden in ["image", "data:image", "base64", "rgb", "frame", "landmark"] {
            assert!(
                !body.to_lowercase().contains(forbidden),
                "arbiter request must not carry {forbidden}: {body}"
            );
        }
    }

    #[test]
    fn parses_a_plain_json_reply() {
        let parsed = parse_reply(r#"{"action":"next_item","reason":"swipe"}"#);
        assert_eq!(parsed.action, Some(ActionId::NextItem));
        assert_eq!(parsed.reason, "swipe");
    }

    #[test]
    fn parses_a_none_reply_as_no_action() {
        assert_eq!(
            parse_reply(r#"{"action":"none","reason":"unclear"}"#).action,
            None
        );
        assert_eq!(parse_reply(r#"{"action":"NONE"}"#).action, None);
    }

    #[test]
    fn parses_a_fenced_reply() {
        let parsed = parse_reply(
            "Sure!\n```json\n{\"action\":\"dismiss_overlay\",\"reason\":\"fist\"}\n```\n",
        );
        assert_eq!(parsed.action, Some(ActionId::DismissOverlay));
    }

    #[test]
    fn an_invented_action_name_degrades_to_no_action() {
        // `delete_everything` is unrepresentable, so parsing must not coerce it.
        assert_eq!(
            parse_reply(r#"{"action":"delete_everything"}"#).action,
            None
        );
        assert_eq!(parse_reply(r#"{"action":"launch_missiles"}"#).action, None);
    }

    #[test]
    fn garbage_degrades_to_no_action() {
        assert_eq!(parse_reply("I think it is probably a fist?").action, None);
        assert_eq!(parse_reply("").action, None);
        assert_eq!(parse_reply("{oops").action, None);
    }

    #[test]
    fn parsing_then_resolving_is_closed_under_the_allowlist() {
        // The full path from an untrusted string to a decision.
        let allowed = [ActionId::NextItem];
        for raw in [
            r#"{"action":"next_item"}"#,
            r#"{"action":"scroll_up"}"#,
            r#"{"action":"toggle_dictation"}"#,
            r#"{"action":"rm -rf /"}"#,
            "no json here",
        ] {
            let decision = resolve(&parse_reply(raw), &allowed, None);
            match decision {
                Decision::Act(a) | Decision::ActGuarded(a) => assert_eq!(a, ActionId::NextItem),
                Decision::Ignore(_) => {}
            }
        }
    }
}
