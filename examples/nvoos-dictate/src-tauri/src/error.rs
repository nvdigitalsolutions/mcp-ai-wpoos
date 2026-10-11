//! Central error type for the NV oOS Dictation core.
//!
//! Every fallible core operation returns `AppResult<T>`. IPC handlers map
//! these to serializable strings — error messages must never carry secrets
//! (credentials, API keys) or raw audio data.

use std::io;

/// Result alias used across the Rust core.
pub type AppResult<T> = Result<T, AppError>;

/// Application error enum.
#[derive(Debug, thiserror::Error)]
pub enum AppError {
    #[error("I/O error: {0}")]
    Io(#[from] io::Error),

    #[error("Database error: {0}")]
    Db(#[from] rusqlite::Error),

    #[error("Migration error: {0}")]
    Migration(String),

    #[error("HTTP error: {0}")]
    Http(#[from] reqwest::Error),

    #[error("Serialization error: {0}")]
    Serde(#[from] serde_json::Error),

    #[error("Audio error: {0}")]
    Audio(String),

    #[error("Speech engine error: {0}")]
    Engine(String),

    #[error("Model error: {0}")]
    Model(String),

    #[error("Authentication error: {0}")]
    Auth(String),

    #[error("Keychain error: {0}")]
    Keyring(String),

    #[error("Not configured: {0}")]
    NotConfigured(String),

    #[error("Unsupported on this platform: {0}")]
    Unsupported(String),

    #[error("Hotkey error: {0}")]
    Hotkey(String),

    #[error("Paste error: {0}")]
    Paste(String),

    #[error("{0}")]
    Other(String),
}

impl From<arboard::Error> for AppError {
    fn from(e: arboard::Error) -> Self {
        AppError::Paste(e.to_string())
    }
}

impl From<cpal::BuildStreamError> for AppError {
    fn from(e: cpal::BuildStreamError) -> Self {
        AppError::Audio(e.to_string())
    }
}

impl From<cpal::PlayStreamError> for AppError {
    fn from(e: cpal::PlayStreamError) -> Self {
        AppError::Audio(e.to_string())
    }
}

impl From<cpal::DefaultStreamConfigError> for AppError {
    fn from(e: cpal::DefaultStreamConfigError) -> Self {
        AppError::Audio(e.to_string())
    }
}

impl From<cpal::SupportedStreamConfigsError> for AppError {
    fn from(e: cpal::SupportedStreamConfigsError) -> Self {
        AppError::Audio(e.to_string())
    }
}

impl From<keyring::Error> for AppError {
    fn from(e: keyring::Error) -> Self {
        AppError::Keyring(e.to_string())
    }
}

/// Serialize an error for the webview without leaking internals.
impl serde::Serialize for AppError {
    fn serialize<S>(&self, serializer: S) -> Result<S::Ok, S::Error>
    where
        S: serde::Serializer,
    {
        serializer.serialize_str(&self.to_string())
    }
}
