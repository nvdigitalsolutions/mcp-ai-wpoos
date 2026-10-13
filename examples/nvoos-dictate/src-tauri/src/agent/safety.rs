//! Safety gate (§4/§4a): origin allowlist, sensitive-field masking, and the
//! approval decision that decides whether a proposed action may run without a
//! human confirmation.
//!
//! Pure and always-compiled so the rules are unit-testable independent of the
//! CDP driver. The origin guard mirrors the plugin's `is_internal_url` and the
//! media worker's `safe-url.js` SSRF guard: private/loopback/link-local targets
//! are blocked, and non-HTTP(S) schemes are rejected.

use crate::agent::tools::{classify, SafetyClass};
use crate::agent::BrowserAction;

/// A parsed origin: scheme + host (normalized lowercase, port stripped where
/// the scheme default applies). Kept minimal — the agent only needs to decide
/// same-origin vs cross-origin and public vs private.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Origin {
    pub scheme: String,
    pub host: String,
}

/// Parse `scheme://host[:port]` out of a URL. Returns `None` for anything that
/// is not an absolute HTTP(S) URL (relative refs, `data:`, `file:`, etc.).
pub fn parse_origin(url: &str) -> Option<Origin> {
    let rest = url
        .strip_prefix("http://")
        .or_else(|| url.strip_prefix("https://"))?;
    let scheme = if url.starts_with("https://") {
        "https"
    } else {
        "http"
    };
    let host = rest.split(['/', '?', '#', ':']).next()?.to_string();
    if host.is_empty() {
        return None;
    }
    Some(Origin {
        scheme: scheme.to_string(),
        host: host.to_ascii_lowercase(),
    })
}

/// Whether an origin is private/loopback/link-local (the SSRF-guard set).
fn is_private_host(host: &str) -> bool {
    if host == "localhost" {
        return true;
    }
    // IPv6 loopback / link-local.
    if host.starts_with('[') && host.ends_with(']') {
        let inner = &host[1..host.len() - 1];
        return inner == "::1" || inner.starts_with("fe80");
    }
    let parts: Vec<&str> = host.split('.').collect();
    if parts.iter().any(|p| p.is_empty()) {
        return true; // malformed
    }
    // IPv4 dotted quad.
    if parts.len() == 4 && parts.iter().all(|p| p.chars().all(|c| c.is_ascii_digit())) {
        if let (Ok(a), Ok(b)) = (parts[0].parse::<u8>(), parts[1].parse::<u8>()) {
            // 10.x, 127.x, 169.254.x, 172.16-31.x, 192.168.x, 0.x
            return a == 10
                || a == 127
                || (a == 169 && b == 254)
                || (a == 172 && (16..=31).contains(&b))
                || (a == 192 && b == 168)
                || a == 0;
        }
    }
    false
}

/// Whether a private host is *explicitly* allowlisted (by exact host match).
/// Used by `approve` so a private target is never allowed by an empty/default
/// allowlist — the user must name it. Wildcards are deliberately NOT matched
/// for private hosts (a `*.local` wildcard must not legitimize loopback).
pub fn private_explicitly_allowed(host: &str, allow: &[String]) -> bool {
    !allow.is_empty() && allow.iter().any(|pat| pat.as_str() == host)
}

/// The approval decision for a proposed action, given the task's starting
/// origin and the user's allowlist.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Approval {
    /// Safe to run without asking.
    Allow,
    /// Requires an explicit human Approve/Deny before dispatch.
    RequiresConfirmation,
    /// Blocked outright (private origin, non-HTTP scheme).
    Deny,
}

/// Decide whether `action` may run. Returns the reason alongside the decision
/// for surfacing in the overlay.
pub fn approve(
    action: &BrowserAction,
    start_origin: Option<&Origin>,
    allow: &[String],
) -> (Approval, String) {
    // Origin checks apply to navigation only; other actions stay on-page.
    if let BrowserAction::Navigate { url } = action {
        let target = match parse_origin(url) {
            Some(o) => o,
            None => {
                return (
                    Approval::Deny,
                    format!("not an absolute HTTP(S) URL: {url}"),
                )
            }
        };
        if is_private_host(&target.host) {
            // Private/loopback is denied outright unless EXPLICITLY allowlisted.
            // An empty allowlist means "all public hosts" — it never legitimizes
            // a private address.
            if !private_explicitly_allowed(&target.host, allow) {
                return (
                    Approval::Deny,
                    format!("blocked private/loopback origin: {}", target.host),
                );
            }
        }
        if let Some(start) = start_origin {
            if target.host != start.host {
                return (
                    Approval::RequiresConfirmation,
                    format!(
                        "cross-origin navigation to {} (from {})",
                        target.host, start.host
                    ),
                );
            }
        }
        return (Approval::Allow, String::new());
    }

    // Non-navigation actions: gate on the safety class.
    match classify(action) {
        SafetyClass::ReadOnly => (Approval::Allow, String::new()),
        SafetyClass::External => (Approval::Allow, String::new()),
        SafetyClass::Destructive => (
            Approval::RequiresConfirmation,
            format!("{:?}", action).chars().take(80).collect(),
        ),
    }
}

/// Mask a sensitive field value so it never reaches the observation (and thus
/// never the model). Mirrors §4.4: password/SSN/card/account fields return a
/// fixed marker, their real value is hidden.
pub fn mask_sensitive(field_name: &str, _value: &str) -> Option<&'static str> {
    let n = field_name.to_ascii_lowercase();
    let sensitive = [
        "password",
        "passwd",
        "secret",
        "token",
        "cvv",
        "cardnumber",
        "card",
    ]
    .iter()
    .any(|k| n.contains(k))
        || (n.contains("ssn"))
        || (n.contains("account") && n.contains("number"));
    sensitive.then_some("[redacted]")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_and_normalizes_origin() {
        assert_eq!(
            parse_origin("https://Example.COM/path?q=1"),
            Some(Origin {
                scheme: "https".into(),
                host: "example.com".into()
            })
        );
        assert_eq!(parse_origin("relative/path"), None);
        assert_eq!(parse_origin("file:///x"), None);
    }

    #[test]
    fn private_hosts_are_detected() {
        assert!(is_private_host("localhost"));
        assert!(is_private_host("127.0.0.1"));
        assert!(is_private_host("192.168.1.10"));
        assert!(is_private_host("10.0.0.5"));
        assert!(is_private_host("169.254.1.1"));
        assert!(is_private_host("172.16.0.1"));
        assert!(!is_private_host("example.com"));
        assert!(!is_private_host("8.8.8.8"));
    }

    #[test]
    fn navigation_guards_apply() {
        let start = parse_origin("https://example.com").unwrap();
        // Same origin → allow.
        let (d, _) = approve(
            &BrowserAction::Navigate {
                url: "https://example.com/next".into(),
            },
            Some(&start),
            &[],
        );
        assert_eq!(d, Approval::Allow);
        // Cross origin → confirmation.
        let (d, _) = approve(
            &BrowserAction::Navigate {
                url: "https://other.com".into(),
            },
            Some(&start),
            &[],
        );
        assert_eq!(d, Approval::RequiresConfirmation);
        // Private → deny.
        let (d, _) = approve(
            &BrowserAction::Navigate {
                url: "http://192.168.1.1".into(),
            },
            Some(&start),
            &[],
        );
        assert_eq!(d, Approval::Deny);
    }

    #[test]
    fn destructive_needs_confirmation() {
        let (d, _) = approve(&BrowserAction::Submit, None, &[]);
        assert_eq!(d, Approval::RequiresConfirmation);
    }

    #[test]
    fn sensitive_fields_are_masked() {
        assert_eq!(mask_sensitive("password", "hunter2"), Some("[redacted]"));
        assert_eq!(mask_sensitive("cardNumber", "4242"), Some("[redacted]"));
        assert_eq!(mask_sensitive("email", "a@b.co"), None);
    }
}
