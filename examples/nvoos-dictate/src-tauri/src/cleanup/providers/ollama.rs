//! Ollama Stage-2 provider: delegates transcript polish to a local model
//! served by Ollama's OpenAI-compatible endpoint (`http://localhost:11434/v1`
//! by default). Nothing leaves the machine on the default path.
//!
//! Auth: the local Ollama server needs no token; the optional bearer token
//! (for a remote/headless server) lives in the OS keychain — never in config.

use async_trait::async_trait;

use crate::cleanup::providers::local_openai::{LocalKind, LocalOpenAiProvider};
use crate::cleanup::providers::{CleanupProvider, PolishMeta};
use crate::config::{AppCategory, OllamaConfig};
use crate::error::AppResult;
use crate::state::{SecretStore, KEY_OLLAMA_TOKEN};

/// Result of the Settings-wizard Ollama connectivity + model-discovery test.
pub use crate::cleanup::providers::local_openai::LocalConnectionTest as OllamaConnectionTest;

fn kind() -> LocalKind {
    LocalKind {
        name: "Ollama",
        id: "ollama",
    }
}

pub struct OllamaProvider {
    inner: LocalOpenAiProvider,
}

impl OllamaProvider {
    pub fn new(config: &OllamaConfig, secrets: &dyn SecretStore) -> AppResult<Self> {
        let token = secrets.get(KEY_OLLAMA_TOKEN)?;
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
    pub async fn test_connection(&self) -> OllamaConnectionTest {
        self.inner.test_connection().await
    }
}

#[async_trait]
impl CleanupProvider for OllamaProvider {
    fn id(&self) -> &'static str {
        self.inner.kind.id
    }

    async fn polish(&self, text: &str, category: AppCategory) -> AppResult<(String, PolishMeta)> {
        self.inner.polish(text, category).await
    }

    async fn summarize(&self, text: &str) -> AppResult<String> {
        self.inner.summarize(text).await
    }
}
