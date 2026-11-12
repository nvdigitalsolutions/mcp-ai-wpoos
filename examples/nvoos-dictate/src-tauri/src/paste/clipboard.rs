//! Clipboard snapshot and restore (Proposal 144 §4.2).
//!
//! The paste path must never destroy what the user had on the clipboard.
//!
//! **Windows** snapshots *every* copyable format by enumerating the clipboard
//! and cloning each payload's bytes: text, `CF_HDROP`, `CF_DIB`, HTML, RTF and
//! any registered/private format an application published. Restore replays the
//! same bytes back, so rich formats survive instead of being downgraded to
//! text. Formats whose data is a GDI handle rather than a global memory block
//! (`CF_BITMAP`, `CF_PALETTE`, metafiles) cannot be copied as bytes; they are
//! recorded as unsupported and reported instead of being silently dropped.
//!
//! **Other platforms** keep the `arboard` fallback (text and RGBA image), which
//! is a documented limitation rather than a claim of parity.
//!
//! Contract:
//!
//! 1. [`snapshot`] captures what is there, before the transcript is written.
//! 2. [`write_text_transient`] writes the transcript, marked on Windows so it
//!    does not enter clipboard history or cloud sync.
//! 3. [`restore`] puts the snapshot back, but only if nothing else wrote to the
//!    clipboard in the meantime (see [`sequence_number`]).
//!
//! Privacy: a snapshot may contain a password. It is held in memory for the
//! duration of one paste and never logged, serialised, or surfaced in
//! diagnostics beyond its [`ClipSnapshot::kind`] and format counts.

use std::time::Instant;

use crate::error::AppResult;
use crate::paste::backend::PasteOutcome;

#[cfg(not(windows))]
use std::borrow::Cow;

#[cfg(not(windows))]
use crate::error::AppError;

/// Upper bound on a non-Windows text snapshot.
#[cfg(not(windows))]
const MAX_TEXT_BYTES: usize = 1 << 20; // 1 MiB

/// Upper bound on a non-Windows image snapshot (RGBA bytes).
#[cfg(not(windows))]
const MAX_IMAGE_BYTES: usize = 32 << 20; // 32 MiB

/// Windows: upper bound on the total bytes cloned across all formats. A
/// snapshot must never block the paste path or balloon memory.
#[cfg(windows)]
const MAX_TOTAL_BYTES: usize = 64 << 20; // 64 MiB

/// One clipboard format captured as raw bytes (Windows).
#[cfg(windows)]
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ClipItem {
    pub format: u32,
    /// Registered format name, or a `CF_*` label for standard formats.
    pub name: String,
    pub bytes: Vec<u8>,
}

/// What was on the clipboard before the transcript was written.
#[derive(Debug, Clone, PartialEq)]
pub enum ClipSnapshot {
    Empty,
    /// Windows: every format whose payload is a global memory block.
    #[cfg(windows)]
    Formats {
        items: Vec<ClipItem>,
        /// Formats present but not copyable as bytes (GDI handles).
        unsupported: Vec<String>,
    },
    /// Non-Windows fallback: text.
    #[cfg(not(windows))]
    Text(String),
    /// Non-Windows fallback: RGBA image.
    #[cfg(not(windows))]
    Image {
        width: usize,
        height: usize,
        rgba: Vec<u8>,
    },
    /// Content we can see but cannot copy losslessly.
    Partial(&'static str),
}

impl ClipSnapshot {
    /// Short, non-sensitive label for diagnostics.
    pub fn kind(&self) -> &'static str {
        match self {
            Self::Empty => "empty",
            #[cfg(windows)]
            Self::Formats { .. } => "formats",
            #[cfg(not(windows))]
            Self::Text(_) => "text",
            #[cfg(not(windows))]
            Self::Image { .. } => "image",
            Self::Partial(_) => "partial",
        }
    }

    pub fn is_restorable(&self) -> bool {
        match self {
            Self::Empty | Self::Partial(_) => false,
            #[cfg(windows)]
            Self::Formats { items, .. } => !items.is_empty(),
            #[cfg(not(windows))]
            Self::Text(_) | Self::Image { .. } => true,
        }
    }

    /// Number of formats we could see but not copy (Windows).
    pub fn partial_count(&self) -> usize {
        match self {
            #[cfg(windows)]
            Self::Formats { unsupported, .. } => unsupported.len(),
            _ => 0,
        }
    }
}

/// Capture the clipboard. Never fails: an unreadable clipboard is `Empty`.
pub fn snapshot() -> ClipSnapshot {
    #[cfg(windows)]
    {
        match win::read_all_formats() {
            Ok((items, unsupported)) if !items.is_empty() => {
                ClipSnapshot::Formats { items, unsupported }
            }
            Ok((_, unsupported)) if !unsupported.is_empty() => {
                ClipSnapshot::Partial("unsupported formats")
            }
            Ok(_) => ClipSnapshot::Empty,
            Err(reason) => ClipSnapshot::Partial(reason),
        }
    }

    #[cfg(not(windows))]
    {
        if let Some(snapshot) = read_text() {
            return snapshot;
        }
        if let Some(snapshot) = read_image() {
            return snapshot;
        }
        ClipSnapshot::Empty
    }
}

#[cfg(not(windows))]
fn read_text() -> Option<ClipSnapshot> {
    let mut clipboard = arboard::Clipboard::new().ok()?;
    let text = clipboard.get_text().ok()?;
    if text.is_empty() {
        return None;
    }
    if text.len() > MAX_TEXT_BYTES {
        return Some(ClipSnapshot::Partial("oversized text"));
    }
    Some(ClipSnapshot::Text(text))
}

#[cfg(not(windows))]
fn read_image() -> Option<ClipSnapshot> {
    let mut clipboard = arboard::Clipboard::new().ok()?;
    let image = clipboard.get_image().ok()?;
    if image.bytes.is_empty() || image.width == 0 || image.height == 0 {
        return None;
    }
    if image.bytes.len() > MAX_IMAGE_BYTES {
        return Some(ClipSnapshot::Partial("oversized image"));
    }
    Some(ClipSnapshot::Image {
        width: image.width,
        height: image.height,
        rgba: image.bytes.into_owned(),
    })
}

/// Put the snapshot back. Returns whether anything was restored.
pub fn restore(snapshot: &ClipSnapshot) -> AppResult<bool> {
    #[cfg(windows)]
    {
        match snapshot {
            ClipSnapshot::Formats { items, .. } => win::write_all_formats(items),
            _ => Ok(false),
        }
    }

    #[cfg(not(windows))]
    {
        match snapshot {
            ClipSnapshot::Empty | ClipSnapshot::Partial(_) => Ok(false),
            ClipSnapshot::Text(text) => {
                let mut clipboard = arboard::Clipboard::new().map_err(paste_err)?;
                clipboard.set_text(text.clone()).map_err(paste_err)?;
                Ok(true)
            }
            ClipSnapshot::Image {
                width,
                height,
                rgba,
            } => {
                let mut clipboard = arboard::Clipboard::new().map_err(paste_err)?;
                let data = arboard::ImageData {
                    width: *width,
                    height: *height,
                    bytes: Cow::Borrowed(rgba.as_slice()),
                };
                clipboard.set_image(data).map_err(paste_err)?;
                Ok(true)
            }
        }
    }
}

/// Write the transcript as text.
///
/// On Windows the write is marked with the documented clipboard-history
/// exclusion formats, so dictation does not fill the user's `Win+V` history.
pub fn write_text_transient(text: &str) -> AppResult<()> {
    #[cfg(windows)]
    {
        win::write_text_excluded_from_history(text)
    }
    #[cfg(not(windows))]
    {
        let mut clipboard = arboard::Clipboard::new().map_err(paste_err)?;
        clipboard.set_text(text.to_string()).map_err(paste_err)?;
        Ok(())
    }
}

/// Clipboard change counter, used to detect that something else wrote while we
/// were pasting. `None` where the platform has no equivalent.
pub fn sequence_number() -> Option<u32> {
    #[cfg(windows)]
    {
        Some(unsafe { windows::Win32::System::DataExchange::GetClipboardSequenceNumber() })
    }
    #[cfg(not(windows))]
    {
        None
    }
}

#[cfg(not(windows))]
fn paste_err(err: arboard::Error) -> AppError {
    AppError::Paste(err.to_string())
}

/// The paste flow shared by every platform backend: snapshot, write, inject,
/// restore.
///
/// `inject` performs the platform keystroke and runs even when the snapshot is
/// unusable; the restore decision is made afterwards. Keeping the flow here
/// means the platform modules stay thin wrappers — the only unverified code on
/// a platform we cannot compile locally is the keystroke injection itself.
pub fn run_paste(
    text: &str,
    backend: &'static str,
    restore_clipboard: bool,
    restore_delay_ms: u64,
    inject: impl FnOnce() -> AppResult<()>,
) -> AppResult<PasteOutcome> {
    let started = Instant::now();

    let snapshot = if restore_clipboard {
        snapshot()
    } else {
        ClipSnapshot::Empty
    };

    write_text_transient(text).inspect_err(|_| {
        // The transient write emptied the clipboard before failing; put the
        // snapshot back so a failed paste cannot cost the user their copy.
        let _ = restore(&snapshot);
    })?;
    let sequence_after_write = sequence_number();

    let injected = inject();

    let restore = restore_after_paste(
        &snapshot,
        restore_clipboard,
        sequence_after_write,
        restore_delay_ms,
    );

    let outcome = PasteOutcome {
        backend,
        snapshot_kind: snapshot.kind(),
        restored: restore.restored,
        restore_skipped: restore.skipped,
        partial_formats: snapshot.partial_count(),
        elapsed_ms: started.elapsed().as_millis() as u64,
    };

    // The restore already ran; surface an injection failure afterwards so a
    // blocked paste is still reported.
    injected.map(|()| outcome)
}

/// Result of the post-paste restore decision.
#[derive(Debug, Clone, PartialEq)]
pub struct RestoreOutcome {
    pub restored: bool,
    pub skipped: Option<&'static str>,
}

/// Wait `delay_ms`, then put the snapshot back unless something invalidated it.
///
/// The sequence-number check is the important part: if the user copied
/// something during the paste window, restoring would destroy that instead.
/// In that case we leave their new content alone and record why.
pub fn restore_after_paste(
    snapshot: &ClipSnapshot,
    enabled: bool,
    sequence_after_write: Option<u32>,
    delay_ms: u64,
) -> RestoreOutcome {
    if delay_ms > 0 {
        std::thread::sleep(std::time::Duration::from_millis(delay_ms));
    }

    if !enabled {
        return RestoreOutcome {
            restored: false,
            skipped: Some("disabled"),
        };
    }
    if !snapshot.is_restorable() {
        return RestoreOutcome {
            restored: false,
            skipped: Some(snapshot.kind()),
        };
    }
    if let (Some(before), Some(now)) = (sequence_after_write, sequence_number()) {
        if before != now {
            return RestoreOutcome {
                restored: false,
                skipped: Some("clipboard changed during paste"),
            };
        }
    }

    match restore(snapshot) {
        Ok(true) => RestoreOutcome {
            restored: true,
            skipped: None,
        },
        Ok(false) => RestoreOutcome {
            restored: false,
            skipped: Some("nothing to restore"),
        },
        Err(_) => RestoreOutcome {
            restored: false,
            skipped: Some("restore failed"),
        },
    }
}

#[cfg(windows)]
mod win {
    use std::time::Duration;

    use windows::core::PCWSTR;
    use windows::Win32::Foundation::{GlobalFree, HANDLE, HGLOBAL};
    use windows::Win32::System::DataExchange::{
        CloseClipboard, EmptyClipboard, EnumClipboardFormats, GetClipboardData,
        GetClipboardFormatNameW, OpenClipboard, RegisterClipboardFormatW, SetClipboardData,
    };
    use windows::Win32::System::Memory::{
        GlobalAlloc, GlobalLock, GlobalSize, GlobalUnlock, GMEM_MOVEABLE,
    };

    use super::{AppResult, ClipItem, MAX_TOTAL_BYTES};
    use crate::error::AppError;

    const CF_UNICODETEXT: u32 = 13;

    /// Open the clipboard, retrying briefly: another process may hold it.
    pub(super) fn open_retry() -> windows::core::Result<()> {
        let mut last = Ok(());
        for _ in 0..5 {
            match unsafe { OpenClipboard(None) } {
                Ok(()) => return Ok(()),
                Err(e) => last = Err(e),
            }
            std::thread::sleep(Duration::from_millis(20));
        }
        last
    }

    pub(super) fn close() {
        let _ = unsafe { CloseClipboard() };
    }

    /// Copy `bytes` into a moveable HGLOBAL the clipboard can own.
    pub(super) unsafe fn global_from_bytes(bytes: &[u8]) -> windows::core::Result<HANDLE> {
        let handle = GlobalAlloc(GMEM_MOVEABLE, bytes.len())?;
        let ptr = GlobalLock(handle);
        if ptr.is_null() {
            return Err(windows::core::Error::from_win32());
        }
        std::ptr::copy_nonoverlapping(bytes.as_ptr(), ptr as *mut u8, bytes.len());
        let _ = GlobalUnlock(handle);
        Ok(HANDLE(handle.0))
    }

    fn wide(name: &str) -> Vec<u16> {
        name.encode_utf16().chain(std::iter::once(0)).collect()
    }

    /// UTF-16LE bytes with a NUL terminator (the `CF_UNICODETEXT` layout).
    fn utf16_bytes(text: &str) -> Vec<u8> {
        let mut bytes = Vec::with_capacity(text.len() * 2 + 2);
        for unit in text.encode_utf16() {
            bytes.extend_from_slice(&unit.to_le_bytes());
        }
        bytes.extend_from_slice(&0u16.to_le_bytes());
        bytes
    }

    /// Human-readable format name: the registered name, or a `CF_*` label.
    fn format_label(format: u32) -> String {
        let mut buf = [0u16; 128];
        let len = unsafe { GetClipboardFormatNameW(format, &mut buf) };
        if len > 0 {
            return String::from_utf16_lossy(&buf[..len as usize]);
        }
        match format {
            1 => "CF_TEXT".to_string(),
            2 => "CF_BITMAP".to_string(),
            3 => "CF_METAFILEPICT".to_string(),
            6 => "CF_TIFF".to_string(),
            7 => "CF_OEMTEXT".to_string(),
            8 => "CF_DIB".to_string(),
            13 => "CF_UNICODETEXT".to_string(),
            15 => "CF_HDROP".to_string(),
            16 => "CF_LOCALE".to_string(),
            17 => "CF_DIBV5".to_string(),
            _ => format!("CF_{format}"),
        }
    }

    /// Enumerate the clipboard and clone every format whose payload is a global
    /// memory block.
    ///
    /// `GlobalSize` returning zero is the discriminator: HGLOBAL payloads
    /// always have a size, while GDI handles (`CF_BITMAP`, `CF_PALETTE`,
    /// metafiles) are not global memory. Those are reported as unsupported
    /// rather than misread as data.
    pub(super) fn read_all_formats() -> Result<(Vec<ClipItem>, Vec<String>), &'static str> {
        unsafe {
            open_retry().map_err(|_| "clipboard busy")?;
            let result = (|| -> Result<(Vec<ClipItem>, Vec<String>), &'static str> {
                let mut items = Vec::new();
                let mut unsupported = Vec::new();
                let mut total = 0usize;

                let mut format = 0u32;
                loop {
                    format = EnumClipboardFormats(format);
                    if format == 0 {
                        break;
                    }
                    let Ok(handle) = GetClipboardData(format) else {
                        continue;
                    };
                    let global = HGLOBAL(handle.0);
                    let size = GlobalSize(global);
                    if size == 0 {
                        // A GDI handle or an unrenderable delayed format.
                        unsupported.push(format_label(format));
                        continue;
                    }
                    if total + size > MAX_TOTAL_BYTES {
                        return Err("oversized clipboard");
                    }
                    let ptr = GlobalLock(global);
                    if ptr.is_null() {
                        unsupported.push(format_label(format));
                        continue;
                    }
                    let bytes = std::slice::from_raw_parts(ptr as *const u8, size).to_vec();
                    let _ = GlobalUnlock(global);
                    total += size;
                    let name = format_label(format);
                    items.push(ClipItem {
                        format,
                        name,
                        bytes,
                    });
                }
                Ok((items, unsupported))
            })();
            close();
            result
        }
    }

    /// Replay every captured format onto the clipboard.
    ///
    /// Individual formats can be rejected (the system may refuse a format);
    /// that should not discard the rest, so failures are counted and only a
    /// complete failure is reported as an error.
    pub(super) fn write_all_formats(items: &[ClipItem]) -> AppResult<bool> {
        unsafe {
            open_retry().map_err(|e| AppError::Paste(format!("clipboard open failed: {e}")))?;
            let result = (|| -> windows::core::Result<usize> {
                EmptyClipboard()?;
                let mut placed = 0usize;
                for item in items {
                    let Ok(handle) = global_from_bytes(&item.bytes) else {
                        continue;
                    };
                    match SetClipboardData(item.format, handle) {
                        Ok(_) => placed += 1,
                        // The system did not take ownership, so free it.
                        Err(_) => {
                            let _ = GlobalFree(HGLOBAL(handle.0));
                        }
                    }
                }
                Ok(placed)
            })();
            close();
            match result {
                Ok(0) => Ok(false),
                Ok(_) => Ok(true),
                Err(e) => Err(AppError::Paste(format!("clipboard restore failed: {e}"))),
            }
        }
    }

    pub(super) fn write_text_excluded_from_history(text: &str) -> AppResult<()> {
        let payload = utf16_bytes(text);
        unsafe {
            open_retry().map_err(|e| AppError::Paste(format!("clipboard open failed: {e}")))?;
            let result = (|| -> windows::core::Result<()> {
                EmptyClipboard()?;
                let handle = global_from_bytes(&payload)?;
                if let Err(e) = SetClipboardData(CF_UNICODETEXT, handle) {
                    let _ = GlobalFree(HGLOBAL(handle.0));
                    return Err(e);
                }

                // Documented Microsoft formats: keep the transient transcript
                // out of clipboard history and out of cloud sync.
                let zero = 0u32.to_le_bytes();
                for name in [
                    "CanIncludeInClipboardHistory",
                    "ExcludeClipboardContentFromMonitorProcessing",
                ] {
                    let wide_name = wide(name);
                    let format = RegisterClipboardFormatW(PCWSTR(wide_name.as_ptr()));
                    if format != 0 {
                        if let Ok(handle) = global_from_bytes(&zero) {
                            if SetClipboardData(format, handle).is_err() {
                                let _ = GlobalFree(HGLOBAL(handle.0));
                            }
                        }
                    }
                }
                Ok(())
            })();
            close();
            result.map_err(|e| AppError::Paste(format!("clipboard write failed: {e}")))
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn empty_and_partial_are_not_restorable() {
        assert!(!ClipSnapshot::Empty.is_restorable());
        assert!(!ClipSnapshot::Partial("bitmap handle").is_restorable());
        assert_eq!(ClipSnapshot::Partial("x").partial_count(), 0);
    }

    #[cfg(windows)]
    #[test]
    fn formats_snapshot_reports_kind_and_partial_count() {
        let snapshot = ClipSnapshot::Formats {
            items: vec![ClipItem {
                format: 13,
                name: "CF_UNICODETEXT".into(),
                bytes: vec![0, 0],
            }],
            unsupported: vec!["CF_BITMAP".into(), "CF_PALETTE".into()],
        };
        assert_eq!(snapshot.kind(), "formats");
        assert!(snapshot.is_restorable());
        assert_eq!(snapshot.partial_count(), 2);
        // Diagnostics only ever see the kind and counts, never payloads.
        assert!(!snapshot.kind().contains("CF_"));
    }

    /// Full-fidelity round trip through the real Windows clipboard.
    ///
    /// Ignored by default because it replaces the machine's clipboard content.
    /// Run explicitly: `cargo test --lib all_formats_round_trip -- --ignored`.
    #[cfg(windows)]
    #[test]
    #[ignore = "mutates the system clipboard; run explicitly"]
    fn all_formats_round_trip() {
        use windows::core::PCWSTR;
        use windows::Win32::Foundation::HGLOBAL;
        use windows::Win32::System::DataExchange::{
            EmptyClipboard, GetClipboardData, RegisterClipboardFormatW, SetClipboardData,
        };
        use windows::Win32::System::Memory::{GlobalLock, GlobalSize, GlobalUnlock};

        const TEST_FORMAT: &str = "NV oOS Test Format";
        const TEST_BYTES: &[u8] = b"round-trip-payload-\x00\x01\x02";

        fn wide(s: &str) -> Vec<u16> {
            s.encode_utf16().chain(std::iter::once(0)).collect()
        }

        // Remember the user's current text so the test can put it back.
        let previous = super::win::read_all_formats()
            .ok()
            .and_then(|(items, _)| items.into_iter().find(|i| i.format == 13).map(|i| i.bytes));

        // Seed the clipboard with text + a private format.
        let format = unsafe {
            let name = wide(TEST_FORMAT);
            let format = RegisterClipboardFormatW(PCWSTR(name.as_ptr()));
            assert_ne!(format, 0, "could not register the test format");

            super::win::open_retry().expect("open clipboard");
            EmptyClipboard().expect("empty clipboard");

            let text = wide("nv oos round trip");
            let mut text_bytes = Vec::new();
            for unit in text.iter() {
                text_bytes.extend_from_slice(&unit.to_le_bytes());
            }
            let handle = super::win::global_from_bytes(&text_bytes).expect("alloc text");
            SetClipboardData(13, handle).expect("set text");

            let handle = super::win::global_from_bytes(TEST_BYTES).expect("alloc custom");
            SetClipboardData(format, handle).expect("set custom");

            super::win::close();
            format
        };

        // Snapshot, then clobber.
        let snapshot = snapshot();
        match &snapshot {
            ClipSnapshot::Formats { items, .. } => {
                assert!(
                    items.len() >= 2,
                    "expected both formats, captured {}",
                    items.len()
                );
            }
            other => panic!("expected Formats, got {}", other.kind()),
        }

        unsafe {
            super::win::open_retry().expect("open clipboard");
            EmptyClipboard().expect("empty clipboard");
            let handle = super::win::global_from_bytes(&[0u8, 0u8]).expect("alloc clobber");
            SetClipboardData(13, handle).expect("set clobber");
            super::win::close();
        }

        assert!(
            restore(&snapshot).expect("restore"),
            "restore reported false"
        );

        // Verify both formats came back byte-identical.
        unsafe {
            super::win::open_retry().expect("open clipboard");

            let handle = GetClipboardData(13).expect("text format missing");
            let global = HGLOBAL(handle.0);
            let size = GlobalSize(global);
            let ptr = GlobalLock(global);
            assert!(!ptr.is_null());
            let raw = std::slice::from_raw_parts(ptr as *const u8, size);
            let (pairs, _) = raw.as_chunks::<2>();
            let units: Vec<u16> = pairs
                .iter()
                .map(|c| u16::from_le_bytes(*c))
                .take_while(|u| *u != 0)
                .collect();
            let restored_text = String::from_utf16_lossy(&units);
            let _ = GlobalUnlock(global);
            assert_eq!(restored_text, "nv oos round trip");

            let handle = GetClipboardData(format).expect("private format missing");
            let global = HGLOBAL(handle.0);
            let size = GlobalSize(global);
            let ptr = GlobalLock(global);
            assert!(!ptr.is_null());
            let raw = std::slice::from_raw_parts(ptr as *const u8, size);
            let restored = raw.to_vec();
            let _ = GlobalUnlock(global);
            assert_eq!(restored, TEST_BYTES, "private format bytes changed");

            super::win::close();
        }

        // Put the user's previous text back.
        unsafe {
            super::win::open_retry().expect("open clipboard");
            EmptyClipboard().expect("empty clipboard");
            if let Some(bytes) = previous {
                if let Ok(handle) = super::win::global_from_bytes(&bytes) {
                    let _ = SetClipboardData(13, handle);
                }
            }
            super::win::close();
        }
    }
}
