// SPDX-License-Identifier: LicenseRef-Proprietary
//! Audio capture and voice activity detection.
//!
//! The ring buffer is the single hand-off between capture and the recognizer.

pub mod capture;
pub mod ring_buffer;
pub mod vad;
pub mod wav;
