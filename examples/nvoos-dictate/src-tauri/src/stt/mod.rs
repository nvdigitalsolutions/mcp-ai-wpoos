//! Speech-to-text engines.

pub mod embedded;
mod engine;
pub mod mock;
pub mod model_registry;

#[cfg(feature = "moonshine")]
mod moonshine;
#[cfg(feature = "parakeet")]
mod parakeet;
#[cfg(feature = "whisper")]
mod whisper;

pub use engine::{available_features, create_engine, EngineResources, EngineStatus, SttEngine};
