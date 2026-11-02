//! Windows foreground-app detection for per-app cleanup profiles.
//!
//! Category mapping lives in `config::AppCategory::from_process_name`; this
//! module just answers "what process owns the foreground window right now".

use windows::Win32::Foundation::HWND;
use windows::Win32::System::Threading::{
    GetCurrentProcessId, OpenProcess, QueryFullProcessImageNameW, PROCESS_NAME_WIN32,
    PROCESS_QUERY_LIMITED_INFORMATION,
};
use windows::Win32::UI::WindowsAndMessaging::{
    GetForegroundWindow, GetWindowTextW, GetWindowThreadProcessId,
};

use crate::config::AppCategory;

/// Current foreground process name (lowercased, no `.exe`) if it can be read.
pub fn foreground_process_name() -> Option<String> {
    unsafe {
        let hwnd: HWND = GetForegroundWindow();
        if hwnd.0.is_null() {
            return None;
        }
        let mut pid: u32 = 0;
        GetWindowThreadProcessId(hwnd, Some(&mut pid as *mut u32));
        if pid == 0 || pid == GetCurrentProcessId() {
            // Our own window (or unreadable): don't classify.
            return None;
        }
        let handle = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, false, pid).ok()?;
        let mut buf = vec![0u16; 1024];
        let mut len = buf.len() as u32;
        QueryFullProcessImageNameW(
            handle,
            PROCESS_NAME_WIN32,
            windows::core::PWSTR(buf.as_mut_ptr()),
            &mut len,
        )
        .ok()?;
        let wide: String = String::from_utf16_lossy(&buf[..len as usize]);
        let file = std::path::Path::new(wide.trim_end_matches('\0'))
            .file_name()
            .and_then(|n| n.to_str())
            .unwrap_or_default()
            .to_string();
        Some(
            file.trim_end_matches(".exe")
                .trim_end_matches(".EXE")
                .to_lowercase(),
        )
    }
}

/// Category of the currently focused app (Windows); `Default` elsewhere.
pub fn current_category() -> AppCategory {
    foreground_process_name()
        .map(|p| AppCategory::from_process_name(&p))
        .or_else(foreground_own_window_category)
        .unwrap_or(AppCategory::Default)
}

/// When the foreground window is one of ours, classify it by title: the NV
/// oOS console window is a chat surface (dictation into its composer gets
/// the Chat profile — trailing-period strip). WebView2 sets the host window
/// title from the document title, so both the Tauri window title and the
/// console's document title are matched.
fn foreground_own_window_category() -> Option<AppCategory> {
    unsafe {
        let hwnd: HWND = GetForegroundWindow();
        if hwnd.0.is_null() {
            return None;
        }
        let mut pid: u32 = 0;
        GetWindowThreadProcessId(hwnd, Some(&mut pid as *mut u32));
        if pid == 0 || pid != GetCurrentProcessId() {
            return None;
        }
        let mut buf = [0u16; 256];
        let len = GetWindowTextW(hwnd, &mut buf);
        let title = String::from_utf16_lossy(&buf[..len as usize]).to_lowercase();
        classify_own_window_title(&title)
    }
}

/// Pure mapping from our own window titles to a cleanup category
/// (case-insensitive).
fn classify_own_window_title(title: &str) -> Option<AppCategory> {
    let t = title.to_lowercase();
    if t.contains("nv oos console") || t.contains("nv oos complete") {
        Some(AppCategory::Chat)
    } else {
        None
    }
}

#[cfg(test)]
mod tests {
    use super::classify_own_window_title;

    #[test]
    fn console_window_titles_map_to_chat() {
        assert_eq!(
            classify_own_window_title("NV oOS Console"),
            Some(crate::config::AppCategory::Chat)
        );
        // WebView2 replaces the host title with the console document title.
        assert_eq!(
            classify_own_window_title("NV oOS Complete"),
            Some(crate::config::AppCategory::Chat)
        );
    }

    #[test]
    fn other_own_windows_stay_unclassified() {
        assert_eq!(classify_own_window_title("NV oOS Dictation"), None);
        assert_eq!(classify_own_window_title(""), None);
        assert_eq!(classify_own_window_title("overlay"), None);
    }
}
