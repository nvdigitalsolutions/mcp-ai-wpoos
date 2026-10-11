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
pub mod recorder;

pub use chunker::Chunker;
pub use recorder::{MeetingRecorder, MeetingSummary};

/// Whether meeting system-audio capture is available on this platform.
pub fn system_audio_available() -> bool {
    #[cfg(windows)]
    {
        true
    }
    #[cfg(not(windows))]
    {
        false
    }
}
