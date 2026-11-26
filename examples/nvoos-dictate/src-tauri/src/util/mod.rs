// SPDX-License-Identifier: LicenseRef-Proprietary
//! Small platform utilities.
//!
//! Pure helpers only; I/O belongs to the calling module.

pub mod decode;

/// In-memory RGB8 frame cropping. Needs the optional `image` crate, which the
/// `camera` feature enables (both consumers — `vision` and `gestures` — imply
/// it).
#[cfg(feature = "camera")]
pub mod image;

#[cfg(target_os = "macos")]
pub mod macos_foreground;
#[cfg(windows)]
pub mod win_foreground;
