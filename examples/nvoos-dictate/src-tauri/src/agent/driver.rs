//! CDP driver (§7, Option A): wraps a chromiumoxide `Browser` + `Page` for the
//! operations the agent loop needs. Pure Rust, no Node sidecar.
//!
//! Feature-gated (`agent` = `dep:chromiumoxide`). The `Browser`/`Handler` are
//! not `Sync`, so the driver lives on its own task/thread in the controller
//! (§6 Phase 1) — the same worker-ownership rule as `cpal::Stream` and
//! `nokhwa::Camera`.

use chromiumoxide::browser::{Browser, BrowserConfig};
use chromiumoxide::cdp::browser_protocol::input::{
    DispatchKeyEventParams, DispatchKeyEventType, DispatchMouseEventParams, DispatchMouseEventType,
    InsertTextParams, MouseButton,
};
use chromiumoxide::cdp::browser_protocol::page::PrintToPdfParams;
use chromiumoxide::page::Page;
use futures::StreamExt;

use crate::agent::browser::resolve_center;
use crate::agent::Verdict;
use crate::error::{AppError, AppResult};

/// A connected browser session (one `Browser` + one `Page`). Constructed for a
/// single agent run; `close()` tears it down.
pub struct Driver {
    browser: Browser,
    handler: tokio::task::JoinHandle<()>,
    page: Page,
}

impl Driver {
    /// Launch (or attach to) a browser and open `start_url`. `executable` is
    /// an optional Chrome/Chromium path; `None` lets chromiumoxide discover a
    /// system install. Headless by default (v1); `--headed` is a debug toggle.
    pub async fn launch(
        executable: Option<std::path::PathBuf>,
        user_data_dir: Option<std::path::PathBuf>,
        headless: bool,
        start_url: &str,
    ) -> AppResult<Self> {
        let mut cfg = BrowserConfig::builder();
        if let Some(exe) = executable {
            cfg = cfg.chrome_executable(exe);
        }
        if let Some(dir) = user_data_dir {
            cfg = cfg.user_data_dir(dir);
        }
        if !headless {
            cfg = cfg.with_head();
        }

        let (browser, mut handler) = Browser::launch(
            cfg.build()
                .map_err(|e| AppError::Other(format!("could not build browser config: {e}")))?,
        )
        .await
        .map_err(|e| AppError::Other(format!("could not launch chrome: {e}")))?;

        // Poll the handler on a dedicated task (chromiumoxide requires it).
        let handle = tokio::spawn(async move {
            while let Some(h) = handler.next().await {
                if h.is_err() {
                    break;
                }
            }
        });

        let page = browser
            .new_page(start_url)
            .await
            .map_err(|e| AppError::Other(format!("could not open page: {e}")))?;

        Ok(Self {
            browser,
            handler: handle,
            page,
        })
    }

    /// The current page URL (used for the post-run deterministic re-read, §5).
    pub async fn current_url(&self) -> AppResult<String> {
        let url = self
            .page
            .url()
            .await
            .map_err(|e| AppError::Other(format!("could not read url: {e}")))?
            .unwrap_or_default();
        Ok(url)
    }

    /// The current page title.
    pub async fn title(&self) -> AppResult<String> {
        let t = self
            .page
            .get_title()
            .await
            .map_err(|e| AppError::Other(format!("could not read title: {e}")))?
            .unwrap_or_default();
        Ok(t)
    }

    /// Navigate the page and wait for the load event.
    pub async fn navigate(&self, url: &str) -> AppResult<()> {
        self.page
            .goto(url)
            .await
            .map(|_| ())
            .map_err(|e| AppError::Other(format!("navigate failed: {e}")))
    }

    /// Click an element by stable ref (`n42`) — resolved to a coordinate via
    /// `DOM.getBoxModel`, then dispatched as a press+release mouse click. The
    /// verdict reflects a hit-test + state diff: `Intercepted` when another
    /// element covers the target's center (a cookie banner / modal), `Unknown`
    /// when the ref no longer resolves (stale — re-observe), `NoEffect` when the
    /// page state was unchanged after the click, else `Changed`.
    pub async fn click_ref(&self, ref_id: &str) -> AppResult<Verdict> {
        let (x, y) = match resolve_center(&self.page, ref_id).await {
            Ok(p) => p,
            Err(_) => return Ok(Verdict::Unknown),
        };
        if !self.is_topmost(ref_id, x, y).await? {
            return Ok(Verdict::Intercepted);
        }
        let before = self.page_sig().await?;
        self.mouse_event(
            DispatchMouseEventType::MousePressed,
            x,
            y,
            MouseButton::Left,
            1,
        )
        .await?;
        self.mouse_event(
            DispatchMouseEventType::MouseReleased,
            x,
            y,
            MouseButton::Left,
            1,
        )
        .await?;
        let after = self.page_sig().await?;
        Ok(if after == before {
            Verdict::NoEffect
        } else {
            Verdict::Changed
        })
    }

    /// Hover over an element by stable ref. `Unknown` on a stale ref,
    /// `NoEffect` when the page did not change, else `Changed`.
    pub async fn hover_ref(&self, ref_id: &str) -> AppResult<Verdict> {
        let (x, y) = match resolve_center(&self.page, ref_id).await {
            Ok(p) => p,
            Err(_) => return Ok(Verdict::Unknown),
        };
        let before = self.page_sig().await?;
        self.mouse_event(
            DispatchMouseEventType::MouseMoved,
            x,
            y,
            MouseButton::None,
            0,
        )
        .await?;
        let after = self.page_sig().await?;
        Ok(if after == before {
            Verdict::NoEffect
        } else {
            Verdict::Changed
        })
    }

    /// Fill an element by stable ref: click to focus, insert text, then read
    /// the input's value back. `ValuesLost` when the write did not stick (a
    /// submit cleared the field), `Unknown` on a stale ref, `Intercepted` when
    /// the focus click was covered, else `Changed`.
    pub async fn fill_ref(&self, ref_id: &str, value: &str) -> AppResult<Verdict> {
        let click = self.click_ref(ref_id).await?;
        if click != Verdict::Changed {
            return Ok(click);
        }
        self.insert_text(value).await?;
        let held =
            crate::agent::browser::call_on_ref(&self.page, ref_id, "function(){return this.value}")
                .await
                .and_then(|v| v.as_str().map(str::to_string));
        match held {
            Some(v) if v == value => Ok(Verdict::Changed),
            Some(_) | None => Ok(Verdict::ValuesLost),
        }
    }

    /// Whether the target is the topmost element at its own center (a real
    /// hit-test). `false` means something else would receive the click.
    async fn is_topmost(&self, ref_id: &str, x: f64, y: f64) -> AppResult<bool> {
        let target = crate::agent::browser::call_on_ref(
            &self.page,
            ref_id,
            "function(){return this.tagName + '#' + (this.id||'')}",
        )
        .await
        .and_then(|v| v.as_str().map(str::to_string))
        .unwrap_or_default();
        let js = format!(
            "(() => {{ const e = document.elementFromPoint({x}, {y}); return e ? (e.tagName + '#' + (e.id||'')) : ''; }})()"
        );
        let top = self
            .page
            .evaluate(js)
            .await
            .ok()
            .and_then(|r| r.into_value::<String>().ok())
            .unwrap_or_default();
        // The target is topmost when the element at its center has the same
        // tag#id signature (a covering banner/modal returns a different one).
        Ok(!top.is_empty() && top == target)
    }

    /// Capture the current page state signature (URL + a11y-tree digest) for a
    /// `NoEffect` comparison.
    async fn page_sig(&self) -> AppResult<u64> {
        let url = self.current_url().await.unwrap_or_default();
        crate::agent::browser::state_signature(&self.page, &url).await
    }

    /// Insert text into the focused element (no key events — the IME path).
    pub async fn insert_text(&self, text: &str) -> AppResult<()> {
        let params = InsertTextParams::builder()
            .text(text)
            .build()
            .map_err(|e| AppError::Other(format!("insertText: {e}")))?;
        self.page
            .execute(params)
            .await
            .map(|_| ())
            .map_err(|e| AppError::Other(format!("insertText dispatch: {e}")))
    }

    /// Press the Enter key (form submit / confirm).
    /// Press the Enter key (form submit / confirm), then read back whether
    /// the document changed. `Navigated` when the URL moved, else `Changed`.
    pub async fn press_enter(&self) -> AppResult<Verdict> {
        let before = self.current_url().await.unwrap_or_default();
        let down = DispatchKeyEventParams::builder()
            .key("Enter")
            .r#type(DispatchKeyEventType::RawKeyDown)
            .windows_virtual_key_code(13)
            .build()
            .map_err(|e| AppError::Other(format!("keyDown Enter: {e}")))?;
        let up = DispatchKeyEventParams::builder()
            .key("Enter")
            .r#type(DispatchKeyEventType::KeyUp)
            .windows_virtual_key_code(13)
            .build()
            .map_err(|e| AppError::Other(format!("keyUp Enter: {e}")))?;
        self.page
            .execute(down)
            .await
            .map_err(|e| AppError::Other(format!("Enter keyDown: {e}")))?;
        self.page
            .execute(up)
            .await
            .map_err(|e| AppError::Other(format!("Enter keyUp: {e}")))?;
        // A submit often navigates (or the page re-renders in place). Compare
        // the URL to decide `Navigated` vs `Changed`.
        let after = self.current_url().await.unwrap_or_default();
        Ok(if !after.is_empty() && after != before {
            Verdict::Navigated
        } else {
            Verdict::Changed
        })
    }

    /// Scroll the page by `dy` CSS pixels (negative = up). `NoEffect` when the
    /// page state did not change.
    pub async fn scroll_page(&self, dy: f64) -> AppResult<Verdict> {
        let before = self.page_sig().await?;
        let params = DispatchMouseEventParams::builder()
            .r#type(DispatchMouseEventType::MouseWheel)
            .x(0.0)
            .y(0.0)
            .delta_x(0.0)
            .delta_y(dy)
            .build()
            .map_err(|e| AppError::Other(format!("mouseWheel: {e}")))?;
        self.page
            .execute(params)
            .await
            .map_err(|e| AppError::Other(format!("mouseWheel dispatch: {e}")))?;
        let after = self.page_sig().await?;
        Ok(if after == before {
            Verdict::NoEffect
        } else {
            Verdict::Changed
        })
    }

    /// Dispatch a single mouse event at `(x, y)`.
    async fn mouse_event(
        &self,
        ty: DispatchMouseEventType,
        x: f64,
        y: f64,
        button: MouseButton,
        click_count: i64,
    ) -> AppResult<()> {
        let mut b = DispatchMouseEventParams::builder();
        b = b
            .r#type(ty)
            .x(x)
            .y(y)
            .button(button)
            .click_count(click_count);
        let params = b
            .build()
            .map_err(|e| AppError::Other(format!("mouseEvent: {e}")))?;
        self.page
            .execute(params)
            .await
            .map(|_| ())
            .map_err(|e| AppError::Other(format!("mouseEvent dispatch: {e}")))
    }

    /// Render the current page to PDF and return the bytes. §7: CDP
    /// `Page.printToPDF` is the v1 `pdf` mechanism; document ops (`merge` /
    /// `watermark` / `extract`) are deferred to the §7a media-worker seam.
    /// `format` maps to Chrome paper dimensions in **inches** (§4a keeps the
    /// plugin's `A4`/`Letter`/`Legal` names; Chrome takes paper size, not a
    /// named format).
    pub async fn pdf(&self, format: &str, landscape: bool) -> AppResult<Vec<u8>> {
        let (w, h) = paper_inches(format);
        let mut b = PrintToPdfParams::builder().landscape(landscape);
        b = b.paper_width(w).paper_height(h);
        let params = b.build();
        self.page
            .pdf(params)
            .await
            .map_err(|e| AppError::Other(format!("pdf render: {e}")))
    }

    /// Extract the current page's visible text (the `Extract` action's
    /// read-back — §3, independent of the brain).
    pub async fn extract_text(&self) -> AppResult<String> {
        let inner = self
            .page
            .evaluate("document.body ? document.body.innerText : ''")
            .await
            .map_err(|e| AppError::Other(format!("extract evaluate: {e}")))?
            .into_value::<String>()
            .unwrap_or_default();
        Ok(inner)
    }

    /// Self-healing wait: poll the page's visible text + title for `needle`
    /// until it appears or `timeout_ms` elapses (§7 — avoid `networkidle`).
    pub async fn wait_text(&self, needle: &str, timeout_ms: u64) -> AppResult<()> {
        let deadline = tokio::time::Instant::now() + std::time::Duration::from_millis(timeout_ms);
        loop {
            let text = self.extract_text().await.unwrap_or_default();
            let title = self.title().await.unwrap_or_default();
            if text.contains(needle) || title.contains(needle) {
                return Ok(());
            }
            if tokio::time::Instant::now() >= deadline {
                return Err(AppError::Other(format!(
                    "timed out waiting for text {:?}",
                    needle
                )));
            }
            tokio::time::sleep(std::time::Duration::from_millis(200)).await;
        }
    }

    /// Capture a screenshot (PNG bytes).
    pub async fn screenshot(&self) -> AppResult<Vec<u8>> {
        self.page
            .screenshot(crate::agent::browser::screenshot_params())
            .await
            .map_err(|e| AppError::Other(format!("screenshot: {e}")))
    }

    /// Build the observation from the CDP accessibility tree. Collects every
    /// node with a role/name, keeping only interactive/editable/named in the
    /// prompt fragment (§3 token budget).
    pub async fn observe(&self) -> AppResult<crate::agent::observe::Observation> {
        let url = self.current_url().await?;
        let title = self.title().await.unwrap_or_default();
        let elements = crate::agent::browser::collect_elements(&self.page).await?;
        Ok(crate::agent::observe::Observation {
            url,
            title,
            elements,
        })
    }

    /// Close the browser and reap the handler task.
    pub async fn close(mut self) {
        let _ = self.browser.close().await;
        self.handler.abort();
    }
}

/// Map a named paper format to Chrome `printToPDF` dimensions in **inches**.
/// §4a keeps the plugin's `A4`/`Letter`/`Legal` names; Chrome takes
/// `paper_width`/`paper_height` (inches), so this is the name→dimension bridge.
/// Unknown names fall back to Letter.
fn paper_inches(format: &str) -> (f64, f64) {
    match format.to_ascii_lowercase().as_str() {
        "a4" => (8.27, 11.69),
        "legal" => (8.5, 14.0),
        // Letter and any unknown name.
        _ => (8.5, 11.0),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn maps_paper_formats_to_inches() {
        assert_eq!(paper_inches("A4"), (8.27, 11.69));
        assert_eq!(paper_inches("a4"), (8.27, 11.69));
        assert_eq!(paper_inches("Letter"), (8.5, 11.0));
        assert_eq!(paper_inches("Legal"), (8.5, 14.0));
        assert_eq!(paper_inches("bogus"), (8.5, 11.0)); // unknown → Letter
    }
}
