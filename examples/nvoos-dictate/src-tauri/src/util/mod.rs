//! Small platform utilities.

pub mod decode;

#[cfg(target_os = "macos")]
pub mod macos_foreground;
#[cfg(windows)]
pub mod win_foreground;
