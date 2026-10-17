//! Observation serialization (§3): the compact, index-numbered element list
//! the brain reasons over. Refs are `backendNodeId`-derived (chrome-agent's
//! `nN` convention) so they stay stable across snapshots *of the same page* —
//! not positional (`@e2`), which breaks when the tree shifts.
//!
//! Feature-gated: building a real observation requires the CDP tree, but the
//! serializer is pure (given an in-memory `Element` list) and unit-tested here.

use serde::Serialize;

/// A single observed element, in the shape the brain reads.
#[derive(Debug, Clone, Serialize)]
pub struct Element {
    /// Stable ref (backendNodeId), e.g. `n42`.
    pub r#ref: String,
    /// ARIA role or tag-derived role.
    pub role: String,
    /// Accessible name / visible text.
    pub name: String,
    /// Whether the element is interactive (focusable / clickable).
    pub interactive: bool,
    /// Whether it is an editable input.
    pub editable: bool,
    /// The current value (already masked for sensitive fields, §4.4).
    pub value: Option<String>,
    /// Link href, when the element is an anchor.
    pub href: Option<String>,
}

/// A full observation: page identity plus the filtered element list.
#[derive(Debug, Clone, Serialize)]
pub struct Observation {
    pub url: String,
    pub title: String,
    pub elements: Vec<Element>,
}

impl Observation {
    /// Render the observation as the compact text block the brain receives.
    /// Aggressively filters: only interactive/editable/named elements are kept
    /// (the token-budget win measured by chrome-agent, §7).
    pub fn to_prompt_fragment(&self) -> String {
        let mut out = String::new();
        out.push_str(&format!("url: {}\ntitle: {}\n", self.url, self.title));
        for el in &self.elements {
            let kind = if el.editable {
                "input"
            } else if el.interactive {
                "button/link"
            } else {
                continue; // drop inert structural nodes entirely
            };
            let value = el.value.as_deref().unwrap_or("");
            let val = if value.is_empty() {
                String::new()
            } else {
                format!(", value={value}")
            };
            let href = el
                .href
                .as_deref()
                .map(|h| format!(", href={h}"))
                .unwrap_or_default();
            out.push_str(&format!(
                "[{}] {} \"{}\"{}{}{}\n",
                el.r#ref, kind, el.name, val, href, ""
            ));
        }
        out
    }
}

/// Build a synthetic observation from a raw list (used by tests and Phase-0
/// parity; the real driver fills this from the CDP accessibility tree).
pub fn from_elements(url: &str, title: &str, elements: Vec<Element>) -> Observation {
    Observation {
        url: url.to_string(),
        title: title.to_string(),
        elements,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn prompt_fragment_keeps_interactive_and_drops_inert() {
        let obs = from_elements(
            "https://example.com/login",
            "Login",
            vec![
                Element {
                    r#ref: "n1".into(),
                    role: "heading".into(),
                    name: "Sign in".into(),
                    interactive: false,
                    editable: false,
                    value: None,
                    href: None,
                },
                Element {
                    r#ref: "n2".into(),
                    role: "textbox".into(),
                    name: "Email".into(),
                    interactive: false,
                    editable: true,
                    value: None,
                    href: None,
                },
                Element {
                    r#ref: "n3".into(),
                    role: "button".into(),
                    name: "Submit".into(),
                    interactive: true,
                    editable: false,
                    value: None,
                    href: None,
                },
            ],
        );
        let frag = obs.to_prompt_fragment();
        assert!(frag.contains("[n2] input \"Email\""));
        assert!(frag.contains("[n3] button/link \"Submit\""));
        // The non-interactive heading is dropped.
        assert!(!frag.contains("Sign in"));
        assert!(frag.contains("url: https://example.com/login"));
    }
}
