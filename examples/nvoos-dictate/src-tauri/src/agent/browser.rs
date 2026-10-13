//! CDP helper functions: build the observation from the accessibility tree and
//! construct screenshot params. Shareable by the driver; feature-gated.
//!
//! The observation collects every `AxNode` that has a role/name and maps it to
//! the stable-`backendNodeId` `Element` shape the brain reads (chrome-agent's
//! `nN` convention, §3/§7). Sensitive values are masked (§4.4) before they
//! ever enter the observation.

use chromiumoxide::cdp::browser_protocol::accessibility::GetFullAxTreeParams;
use chromiumoxide::cdp::browser_protocol::dom::{
    BackendNodeId, GetBoxModelParams, ResolveNodeParams,
};
use chromiumoxide::cdp::browser_protocol::page::CaptureScreenshotFormat;
use chromiumoxide::cdp::js_protocol::runtime::CallFunctionOnParams;
use chromiumoxide::page::{Page, ScreenshotParams};

use crate::agent::observe::Element;
use crate::agent::safety::mask_sensitive;
use crate::error::{AppError, AppResult};

/// Collect interactive/editable/named elements from the CDP accessibility tree.
pub async fn collect_elements(page: &Page) -> AppResult<Vec<Element>> {
    // The accessibility command lives under `chromeoxide::cdp::…::accessibility`.
    let cmd = GetFullAxTreeParams::builder().build();
    let resp = page
        .execute(cmd)
        .await
        .map_err(|e| AppError::Other(format!("getFullAXTree: {e}")))?;

    let mut out = Vec::new();
    for node in &resp.result.nodes {
        let role = node.role.as_ref().and_then(ax_str);
        let name = node.name.as_ref().and_then(ax_str).unwrap_or_default();
        // Skip nodes with neither a role nor a name (pure structural noise).
        if role.is_none() && name.is_empty() {
            continue;
        }
        let ref_id = node
            .backend_dom_node_id
            .map(|b| format!("n{}", b.inner()))
            .unwrap_or_default();
        if ref_id.is_empty() {
            // No backend node id (no DOM identity) — cannot be targeted.
            continue;
        }
        let editable = role.as_deref().is_some_and(|r| {
            r == "textbox"
                || r == "textField"
                || r == "searchbox"
                || r == "combobox"
                || r == "spinbutton"
        });
        let interactive = editable
            || role.as_deref().is_some_and(|r| {
                r == "button"
                    || r == "link"
                    || r == "checkbox"
                    || r == "radio"
                    || r == "menuitem"
                    || r == "tab"
                    || r == "option"
                    || r == "slider"
            });

        let raw_value = node.value.as_ref().and_then(ax_str);
        // Mask sensitive field values before they reach the observation.
        let value = raw_value.map(|v| {
            let field_name = if name.is_empty() {
                role.clone().unwrap_or_default()
            } else {
                name.clone()
            };
            match mask_sensitive(&field_name, &v) {
                Some(redacted) => redacted.to_string(),
                None => v,
            }
        });

        out.push(Element {
            r#ref: ref_id,
            role: role.unwrap_or_else(|| "unknown".into()),
            name,
            interactive,
            editable,
            value,
            href: None, // href is resolved separately when needed (rare in v1).
        });
    }
    Ok(out)
}

/// A stable, cheap digest of the page's visible state: the URL plus a hash of
/// every accessibility node's identity/role/name/value. Used for the §3
/// `NoEffect` verdict: if the signature is unchanged after a delivered action,
/// the action had no observable effect.
pub async fn state_signature(page: &Page, url: &str) -> AppResult<u64> {
    use std::hash::{Hash, Hasher};
    let cmd = GetFullAxTreeParams::builder().build();
    let resp = page
        .execute(cmd)
        .await
        .map_err(|e| AppError::Other(format!("getFullAXTree (signature): {e}")))?;
    let mut hasher = std::collections::hash_map::DefaultHasher::new();
    url.hash(&mut hasher);
    for node in &resp.result.nodes {
        let role = node.role.as_ref().and_then(ax_str);
        let name = node.name.as_ref().and_then(ax_str);
        let value = node.value.as_ref().and_then(ax_str);
        node.backend_dom_node_id
            .as_ref()
            .map(|b| b.inner())
            .hash(&mut hasher);
        role.hash(&mut hasher);
        name.hash(&mut hasher);
        value.hash(&mut hasher);
    }
    Ok(hasher.finish())
}

/// Extract the string value from an `AxValue` (which wraps `Option<Value>`).
fn ax_str(value: &chromiumoxide::cdp::browser_protocol::accessibility::AxValue) -> Option<String> {
    value.value.as_ref().and_then(|v| match v {
        serde_json::Value::String(s) => Some(s.clone()),
        serde_json::Value::Number(n) => Some(n.to_string()),
        serde_json::Value::Bool(b) => Some(b.to_string()),
        _ => None,
    })
}

/// PNG screenshot params (full-page off for a stable viewport capture).
pub fn screenshot_params() -> ScreenshotParams {
    ScreenshotParams::builder()
        .format(CaptureScreenshotFormat::Png)
        .build()
}

/// Parse a stable ref (`n42`) back into a `BackendNodeId`.
pub fn parse_ref(ref_id: &str) -> Option<BackendNodeId> {
    let num: i64 = ref_id.strip_prefix('n')?.parse().ok()?;
    if num < 0 {
        return None; // DOM backend node ids are non-negative
    }
    Some(BackendNodeId::new(num))
}

/// Resolve a stable ref to the element's clickable center (CSS px), via
/// `DOM.getBoxModel`. This is the chrome-agent/agent-browser approach: click by
/// coordinate derived from the accessibility tree, which works across canvas /
/// a11y-poor / SPA pages where a CSS selector would be fragile or absent.
///
/// The `content` quad is `[x1,y1, x2,y2, x3,y3, x4,y4]`; the center is the
/// average of the four points.
pub async fn resolve_center(page: &Page, ref_id: &str) -> AppResult<(f64, f64)> {
    let backend = parse_ref(ref_id)
        .ok_or_else(|| AppError::Other(format!("invalid element ref: {ref_id}")))?;
    let params = GetBoxModelParams::builder()
        .backend_node_id(backend)
        .build();
    let resp = page
        .execute(params)
        .await
        .map_err(|e| AppError::Other(format!("DOM.getBoxModel({ref_id}): {e}")))?;
    let quad = resp.result.model.content.inner();
    if quad.len() != 8 {
        return Err(AppError::Other(format!(
            "unexpected quad length for {ref_id}: {}",
            quad.len()
        )));
    }
    let (mut sx, mut sy) = (0.0f64, 0.0f64);
    for p in quad.as_chunks::<2>().0 {
        sx += p[0];
        sy += p[1];
    }
    Ok((sx / 4.0, sy / 4.0))
}

/// Call a JavaScript function `fn_body` (returning a JSON value) on the DOM
/// node identified by a stable ref, via `DOM.resolveNode` → `Runtime.callFunctionOn`.
/// Returns the by-value result, or `None` if the node cannot be resolved (a
/// stale ref after navigation).
pub async fn call_on_ref(page: &Page, ref_id: &str, fn_body: &str) -> Option<serde_json::Value> {
    let backend = parse_ref(ref_id)?;
    let resolve = ResolveNodeParams::builder()
        .backend_node_id(backend)
        .build();
    let resolved = page.execute(resolve).await.ok()?;
    let object_id = resolved.result.object.object_id.clone()?;
    let call = CallFunctionOnParams::builder()
        .function_declaration(fn_body)
        .object_id(object_id)
        .return_by_value(true)
        .await_promise(true)
        .build()
        .ok()?;
    let result = page.execute(call).await.ok()?;
    result.result.result.value
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_stable_ref() {
        assert!(parse_ref("n42").is_some());
        assert!(parse_ref("n0").is_some());
        assert!(parse_ref("not-a-ref").is_none());
        assert!(parse_ref("").is_none());
        assert!(parse_ref("n-1").is_none()); // negative ids are invalid
    }
}
