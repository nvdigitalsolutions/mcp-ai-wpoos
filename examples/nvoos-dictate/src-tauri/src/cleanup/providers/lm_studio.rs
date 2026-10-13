//! LM Studio Stage-2 provider: delegates transcript polish to a local model
//! served by LM Studio's OpenAI-compatible server (`http://localhost:1234/v1`
//! by default). Nothing leaves the machine on the default path.
//!
//! Auth: the local LM Studio server needs no token; the optional bearer token
//! (for a remote/headless server) lives in the OS keychain — never in config.
//!
//! The shared local OpenAI-compatible behaviour (auth, discovery, timing,
//! cold-load timeout) lives in [`crate::cleanup::providers::local_openai`];
//! this module only supplies LM Studio's identity.

use async_trait::async_trait;

use crate::cleanup::providers::local_openai::{LocalKind, LocalOpenAiProvider};
use crate::cleanup::providers::{CleanupProvider, PolishMeta};
use crate::config::{AppCategory, LmStudioConfig};
use crate::error::AppResult;
use crate::state::{SecretStore, KEY_LM_STUDIO_TOKEN};

/// Result of the Settings-wizard LM Studio connectivity + model-discovery test.
pub use crate::cleanup::providers::local_openai::LocalConnectionTest as LmStudioConnectionTest;

fn kind() -> LocalKind {
    LocalKind {
        name: "LM Studio",
        id: "lm_studio",
    }
}

pub struct LmStudioProvider {
    inner: LocalOpenAiProvider,
}

impl LmStudioProvider {
    pub fn new(config: &LmStudioConfig, secrets: &dyn SecretStore) -> AppResult<Self> {
        let token = secrets.get(KEY_LM_STUDIO_TOKEN)?;
        let inner = LocalOpenAiProvider::new(
            kind(),
            &config.base_url,
            &config.model,
            config.load_timeout_ms,
            token,
        )?;
        Ok(Self { inner })
    }

    /// Discover models and test connectivity against `GET /v1/models`.
    pub async fn test_connection(&self) -> LmStudioConnectionTest {
        self.inner.test_connection().await
    }
}

#[async_trait]
impl CleanupProvider for LmStudioProvider {
    fn id(&self) -> &'static str {
        self.inner.kind.id
    }

    async fn polish(&self, text: &str, category: AppCategory) -> AppResult<(String, PolishMeta)> {
        self.inner.polish(text, category).await
    }

    async fn summarize(&self, text: &str) -> AppResult<String> {
        self.inner.summarize(text).await
    }

    async fn complete(&self, system: &str, user: &str, temperature: f64) -> AppResult<String> {
        self.inner.complete(system, user, temperature).await
    }
}
