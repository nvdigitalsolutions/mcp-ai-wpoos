//! Meeting recording and transcription.
//!
//! Explicit "Record" action only (never automatic). Windows captures the
//! other side of a call via WASAPI loopback, fully in-process (cpal opens a
//! loopback input on the default output device); macOS requires the Swift
//! sidecar helper (phase P5) and Linux meeting capture is documented as
//! unsupported for now. Audio is transcribed and the file is discarded
//! unless the user opts into retention (privacy default).

mod chunker;
#[cfg(windows)]
mod loopback;
#[cfg(target_os = "macos")]
mod macos_system_audio;
pub mod recorder;

/// NDJSON framing for the macOS sidecar (platform-free so the codec is
/// unit-tested everywhere).
pub mod sidecar_protocol;

pub use chunker::Chunker;
pub use recorder::{MeetingRecorder, MeetingSummary};

/// Whether meeting system-audio capture is available on this platform.
/// On macOS this means the ScreenCaptureKit helper binary is present; the
/// Screen Recording permission is checked when a meeting actually starts.
pub fn system_audio_available() -> bool {
    #[cfg(windows)]
    {
        true
    }
    #[cfg(target_os = "macos")]
    {
        macos_system_audio::helper_available()
    }
    #[cfg(all(not(windows), not(target_os = "macos")))]
    {
        false
    }
}
