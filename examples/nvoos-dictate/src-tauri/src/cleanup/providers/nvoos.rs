//! NV oOS Stage-2 provider: delegates polishing to a configured WordPress
//! site's assistant via the plugin REST chat endpoint with the assistant
//! credential stored in the OS keychain.
//!
//! Auth contract (mirrors the repo's content-graph SPA contract):
//! `Authorization: Bearer cred_XXXXX.SECRET`; a failing credential is a
//! definitive 401 — the client never falls back to unauthenticated requests.

use async_trait::async_trait;

use crate::cleanup::providers::CleanupProvider;
use crate::config::{AppCategory, NvoosConfig};
use crate::error::AppResult;
use crate::nvoos::NvoosClient;
use crate::state::{SecretStore, KEY_NVOOS_CREDENTIAL};

pub struct NvoosProvider {
    client: NvoosClient,
    cleanup_prompt: String,
    assistant_id: Option<String>,
}

impl NvoosProvider {
    pub fn new(config: &NvoosConfig, secrets: &dyn SecretStore) -> AppResult<Self> {
        let credential = secrets.get(KEY_NVOOS_CREDENTIAL)?.ok_or_else(|| {
            crate::error::AppError::NotConfigured(
                "NV oOS assistant credential is not stored (set it in Settings > NV oOS)".into(),
            )
        })?;
        let client = NvoosClient::new(config.site_url.clone(), credential)?;
        Ok(Self {
            client,
            cleanup_prompt: config.cleanup_prompt.clone(),
            assistant_id: config.assistant_id.clone(),
        })
    }
}

#[async_trait]
impl CleanupProvider for NvoosProvider {
    fn id(&self) -> &'static str {
        "nvoos"
    }

    async fn polish(&self, text: &str, category: AppCategory) -> AppResult<String> {
        self.client
            .polish(
                text,
                category,
                &self.cleanup_prompt,
                self.assistant_id.as_deref(),
            )
            .await
    }
}
