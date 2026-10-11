//! Windows foreground-app detection for per-app cleanup profiles.
//!
//! Category mapping lives in `config::AppCategory::from_process_name`; this
//! module just answers "what process owns the foreground window right now".

use windows::Win32::Foundation::HWND;
use windows::Win32::System::Threading::{
    GetCurrentProcessId, OpenProcess, QueryFullProcessImageNameW, PROCESS_NAME_WIN32,
    PROCESS_QUERY_LIMITED_INFORMATION,
};
use windows::Win32::UI::WindowsAndMessaging::{GetForegroundWindow, GetWindowThreadProcessId};

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
        .unwrap_or(AppCategory::Default)
}
