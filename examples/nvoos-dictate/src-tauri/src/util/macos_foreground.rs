//! macOS foreground-app detection via NSWorkspace (P5 port, phase M6).
//!
//! The Windows counterpart is `win_foreground.rs`; both feed the per-app
//! cleanup profile and the History `target_app` column. Bundle-ID → category
//! mapping lives in `config::AppCategory::from_bundle_id` so the table is
//! unit-tested on every OS.
//!
//! API note (objc2 0.6 + objc2-app-kit 0.3): `sharedWorkspace`,
//! `frontmostApplication`, `bundleIdentifier` and `localizedName` are
//! generated as SAFE functions — do not wrap them in `unsafe` blocks
//! (`unused_unsafe` is denied in CI).

use objc2_app_kit::NSWorkspace;

use crate::config::AppCategory;

/// The frontmost application's bundle identifier (e.g. `com.apple.Safari`).
pub fn foreground_bundle_id() -> Option<String> {
    let workspace = NSWorkspace::sharedWorkspace();
    let app = workspace.frontmostApplication()?;
    let bundle_id = app.bundleIdentifier()?;
    Some(bundle_id.to_string())
}

/// The cleanup-profile category for the frontmost application.
pub fn current_category() -> AppCategory {
    foreground_bundle_id()
        .map(|id| AppCategory::from_bundle_id(&id))
        .unwrap_or(AppCategory::Default)
}

/// The frontmost application's display name (History `target_app`).
pub fn foreground_process_name() -> Option<String> {
    let workspace = NSWorkspace::sharedWorkspace();
    let app = workspace.frontmostApplication()?;
    let name = app.localizedName()?;
    Some(name.to_string())
}
