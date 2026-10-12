//! macOS TCC permission detection, request, and System Settings deep links
//! (P5 port, phase M1).
//!
//! Every capability the app needs on macOS sits behind a separate user
//! grant: Microphone (capture), Accessibility (paste injection + event-tap
//! swallowing), Input Monitoring (event-tap keyboard listening — the one
//! permission with no one-click prompt), and Screen Recording (meeting
//! system-audio via the ScreenCaptureKit sidecar).
//!
//! On non-macOS platforms this module degrades to "not applicable": the
//! permission model is a macOS concept and the UI hides these rows.

use serde::Serialize;

/// One TCC permission row surfaced to the webview.
#[derive(Debug, Clone, Serialize)]
pub struct PermissionInfo {
    /// Stable id: `microphone` | `accessibility` | `input_monitoring` | `screen_recording`.
    pub kind: &'static str,
    pub label: &'static str,
    /// Human explanation shown when the permission is missing.
    pub explanation: &'static str,
    pub granted: bool,
    /// Whether the OS can show a one-click prompt for this permission.
    /// Input Monitoring cannot — the user must toggle it by hand after the
    /// Settings pane opens.
    pub promptable: bool,
}

/// The macOS implementation (all detection happens here).
#[cfg(target_os = "macos")]
mod imp {
    use super::PermissionInfo;
    use crate::error::{AppError, AppResult};

    /// CoreGraphics access functions not exposed by the `core-graphics`
    /// crate (public C API since macOS 10.15). Declared directly against
    /// the CoreGraphics framework link the crate already establishes.
    #[link(name = "CoreGraphics", kind = "framework")]
    extern "C" {
        fn CGPreflightListenEventAccess() -> bool;
        fn CGRequestListenEventAccess();
        fn CGPreflightScreenCaptureAccess() -> bool;
        fn CGRequestScreenCaptureAccess();
    }

    pub fn permissions(mic_available: bool) -> Vec<PermissionInfo> {
        vec![
            PermissionInfo {
                kind: "microphone",
                label: "Microphone",
                explanation:
                    "Needed to hear your voice. Grant it, then restart the microphone from Diagnostics.",
                granted: mic_available,
                promptable: true,
            },
            PermissionInfo {
                kind: "accessibility",
                label: "Accessibility",
                explanation:
                    "Needed to paste text into other apps (and to swallow the PTT key in hold mode).",
                granted: macos_accessibility_client::accessibility::application_is_trusted(),
                promptable: true,
            },
            PermissionInfo {
                kind: "input_monitoring",
                label: "Input Monitoring",
                explanation:
                    "Needed to listen for the push-to-talk key globally. macOS has no one-click prompt for this: enable it manually in the pane that opens.",
                granted: unsafe { CGPreflightListenEventAccess() },
                promptable: false,
            },
            PermissionInfo {
                kind: "screen_recording",
                label: "Screen Recording",
                explanation:
                    "Needed only for meeting system-audio capture. Denying it keeps meetings microphone-only.",
                granted: unsafe { CGPreflightScreenCaptureAccess() },
                promptable: true,
            },
        ]
    }

    /// Ask macOS to show the permission prompt (or open the pane for the
    /// manual toggle). Returns the refreshed row.
    pub fn request(kind: &str, mic_available: bool) -> AppResult<PermissionInfo> {
        let row = permissions(mic_available)
            .into_iter()
            .find(|p| p.kind == kind)
            .ok_or_else(|| AppError::Other(format!("unknown permission '{kind}'")))?;
        match kind {
            "accessibility" => {
                macos_accessibility_client::accessibility::application_is_trusted_with_prompt();
            }
            "input_monitoring" => unsafe { CGRequestListenEventAccess() },
            "screen_recording" => unsafe { CGRequestScreenCaptureAccess() },
            // Microphone has no headless prompt API — opening an input
            // stream is what triggers TCC, and that is exactly what the
            // restart action does.
            "microphone" => {}
            _ => return Err(AppError::Other(format!("unknown permission '{kind}'"))),
        }
        Ok(row)
    }

    /// The `x-apple.systempreferences:` deep link for a permission pane.
    pub fn system_settings_url(kind: &str) -> AppResult<String> {
        match kind {
            "microphone" => Ok(
                "x-apple.systempreferences:com.apple.preference.security?Privacy_Microphone"
                    .to_string(),
            ),
            "accessibility" => Ok(
                "x-apple.systempreferences:com.apple.preference.security?Privacy_Accessibility"
                    .to_string(),
            ),
            "input_monitoring" => Ok(
                "x-apple.systempreferences:com.apple.preference.security?Privacy_ListenEvent"
                    .to_string(),
            ),
            "screen_recording" => Ok(
                "x-apple.systempreferences:com.apple.preference.security?Privacy_ScreenCapture"
                    .to_string(),
            ),
            _ => Err(AppError::Other(format!("unknown permission '{kind}'"))),
        }
    }
}

/// Non-macOS stub: the permission model does not apply; the webview hides
/// these rows entirely.
#[cfg(not(target_os = "macos"))]
mod imp {
    use super::PermissionInfo;
    use crate::error::{AppError, AppResult};

    pub fn permissions(_mic_available: bool) -> Vec<PermissionInfo> {
        Vec::new()
    }

    pub fn request(kind: &str, _mic_available: bool) -> AppResult<PermissionInfo> {
        Err(AppError::Unsupported(format!(
            "permissions are a macOS concept; '{kind}' does not apply on this platform"
        )))
    }

    pub fn system_settings_url(kind: &str) -> AppResult<String> {
        Err(AppError::Unsupported(format!(
            "permissions are a macOS concept; '{kind}' does not apply on this platform"
        )))
    }
}

pub use imp::{permissions, request, system_settings_url};
