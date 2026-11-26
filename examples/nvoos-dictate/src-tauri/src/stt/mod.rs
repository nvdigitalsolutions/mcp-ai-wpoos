// SPDX-License-Identifier: LicenseRef-Proprietary
//! Speech-to-text engines.
//!
//! Engines share the `engine` seam; feature flags choose the compiled backend,
//! and the registry resolves a model without touching the UI.

pub mod embedded;
mod engine;
pub mod mock;
pub mod model_registry;

#[cfg(feature = "fastconformer")]
mod fastconformer;
#[cfg(feature = "moonshine")]
mod moonshine;
#[cfg(feature = "parakeet")]
mod parakeet;
#[cfg(feature = "whisper")]
mod whisper;

pub use engine::{available_features, create_engine, EngineResources, EngineStatus, SttEngine};
