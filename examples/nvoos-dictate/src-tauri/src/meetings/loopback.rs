//! Windows system-audio capture via WASAPI loopback (in-process).
//!
//! cpal opens a loopback input stream on the default render (output) device,
//! capturing what the machine plays — the "other side" of a call. Modern
//! builds can prefer process-loopback (`AUDIOCLIENT_ACTIVATION_TYPE_
//! PROCESS_LOOPBACK`), which excludes our own output; endpoint loopback is
//! the documented fallback (steno PR #175, parley PR #453 precedents).
//!
//! NOTE: verify the exact cpal loopback device-selection incantation against
//! cpal's docs for the pinned version at first Windows build — the logic is
//! isolated in this one file.

use std::sync::{Arc, Mutex};

use cpal::traits::{DeviceTrait, HostTrait};

use crate::error::{AppError, AppResult};

/// Try to open a loopback stream feeding `sink`. `Ok(None)` = no suitable
/// output device (meetings continue mic-only).
pub fn open_loopback(sink: Arc<Mutex<Vec<f32>>>) -> AppResult<Option<cpal::Stream>> {
    let host = cpal::default_host();

    // Windows enumerates loopback-capable inputs alongside the default
    // output device. Pick the default output device; cpal ≥ 0.15 exposes it
    // via `default_output_device()` + loopback input configs.
    let Some(output) = host.default_output_device() else {
        return Ok(None);
    };
    let Some(range) = output
        .supported_input_configs()
        .ok()
        .and_then(|mut c| c.next())
    else {
        return Ok(None);
    };
    let max_rate = range.max_sample_rate();
    let supported = range
        .try_with_sample_rate(max_rate)
        .ok_or_else(|| AppError::Audio("no loopback config at max sample rate".into()))?;
    let channels = supported.channels() as usize;
    let stream_config: cpal::StreamConfig = supported.config();

    let stream = output.build_input_stream(
        &stream_config,
        move |data: &[f32], _| {
            let mono: Vec<f32> = if channels == 1 {
                data.to_vec()
            } else {
                data.chunks(channels)
                    .map(|f| f.iter().sum::<f32>() / channels as f32)
                    .collect()
            };
            sink.lock().unwrap().extend_from_slice(&mono);
        },
        |err| tracing::warn!("loopback stream error: {err}"),
        None,
    )?;
    Ok(Some(stream))
}
