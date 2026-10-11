//! Application state: the shared core graph managed by Tauri.
//!
//! `AppState` is managed via `app.manage()` and handed to every IPC command.
//! Cross-thread state lives here — settings (RwLock), the SQLite handle
//! (Mutex), the secret store (OS keychain), the resident STT engine, the
//! resident microphone, the paste backend, and the meeting recorder.

use std::collections::HashMap;
use std::path::PathBuf;
use std::sync::{Arc, Mutex, RwLock};

use tauri::{AppHandle, Manager};

use crate::audio::capture::MicCapture;
use crate::config::Settings;
use crate::error::{AppError, AppResult};
use crate::meetings::recorder::MeetingRecorder;
use crate::paste::ClipboardKeystrokeBackend;
use crate::paste::PasteBackend;
use crate::storage::Db;
use crate::stt::{create_engine, EngineResources, SttEngine};

/// Keychain service/account names. Values never leave the OS credential store.
pub const KEYRING_SERVICE: &str = "com.nvdigitalsolutions.nvoos-dictate";
pub const KEY_NVOOS_CREDENTIAL: &str = "nvoos/assistant-credential";
pub const KEY_OPENAI_API_KEY: &str = "openai-compat/api-key";
pub const KEY_WP_USERNAME: &str = "nvoos/wp-username";
pub const KEY_WP_APP_PASSWORD: &str = "nvoos/wp-app-password";

/// Abstraction over the OS credential store. Tests inject an in-memory store.
pub trait SecretStore: Send + Sync {
    fn get(&self, key: &str) -> AppResult<Option<String>>;
    fn set(&self, key: &str, value: &str) -> AppResult<()>;
    fn delete(&self, key: &str) -> AppResult<()>;
}

/// Real implementation backed by the OS keychain
/// (Windows Credential Manager / macOS Keychain / Linux Secret Service).
pub struct OsKeyringStore;

impl SecretStore for OsKeyringStore {
    fn get(&self, key: &str) -> AppResult<Option<String>> {
        let entry = keyring::Entry::new(KEYRING_SERVICE, key)?;
        match entry.get_password() {
            Ok(v) => Ok(Some(v)),
            Err(keyring::Error::NoEntry) => Ok(None),
            Err(e) => Err(AppError::Keyring(e.to_string())),
        }
    }

    fn set(&self, key: &str, value: &str) -> AppResult<()> {
        let entry = keyring::Entry::new(KEYRING_SERVICE, key)?;
        entry.set_password(value)?;
        Ok(())
    }

    fn delete(&self, key: &str) -> AppResult<()> {
        let entry = keyring::Entry::new(KEYRING_SERVICE, key)?;
        match entry.delete_credential() {
            Ok(()) => Ok(()),
            Err(keyring::Error::NoEntry) => Ok(()),
            Err(e) => Err(AppError::Keyring(e.to_string())),
        }
    }
}

/// In-memory store for tests and headless environments.
#[derive(Default)]
pub struct InMemorySecretStore {
    map: Mutex<HashMap<String, String>>,
}

impl SecretStore for InMemorySecretStore {
    fn get(&self, key: &str) -> AppResult<Option<String>> {
        Ok(self.map.lock().unwrap().get(key).cloned())
    }

    fn set(&self, key: &str, value: &str) -> AppResult<()> {
        self.map
            .lock()
            .unwrap()
            .insert(key.to_string(), value.to_string());
        Ok(())
    }

    fn delete(&self, key: &str) -> AppResult<()> {
        self.map.lock().unwrap().remove(key);
        Ok(())
    }
}

/// The shared application state.
pub struct AppState {
    pub settings: RwLock<Settings>,
    pub db: Db,
    pub secrets: Arc<dyn SecretStore>,
    pub app: AppHandle,
    /// Resident microphone (ring buffer). None when no mic exists.
    pub mic: Mutex<Option<Arc<MicCapture>>>,
    /// Resident STT engine (swapped on settings change).
    pub engine: RwLock<Option<Arc<dyn SttEngine>>>,
    pub paste: Arc<dyn PasteBackend>,
    pub recorder: Arc<MeetingRecorder>,
}

impl AppState {
    pub fn new(app: AppHandle, db: Db, secrets: Arc<dyn SecretStore>) -> Self {
        Self {
            settings: RwLock::new(Settings::default()),
            db,
            secrets,
            app,
            mic: Mutex::new(None),
            engine: RwLock::new(None),
            paste: Arc::new(ClipboardKeystrokeBackend::default()),
            recorder: Arc::new(MeetingRecorder::new(16000)),
        }
    }

    /// Reload the persisted settings file.
    pub fn reload_settings(&self) -> AppResult<Settings> {
        let settings = Settings::load()?;
        *self.settings.write().unwrap() = settings.clone();
        Ok(settings)
    }

    /// Persist and swap the active settings.
    pub fn update_settings(&self, settings: Settings) -> AppResult<Settings> {
        settings.save()?;
        *self.settings.write().unwrap() = settings.clone();
        Ok(settings)
    }

    pub fn settings_snapshot(&self) -> Settings {
        self.settings.read().unwrap().clone()
    }

    /// Whether an NV oOS credential is stored in the keychain.
    pub fn has_nvoos_credential(&self) -> bool {
        self.secrets
            .get(KEY_NVOOS_CREDENTIAL)
            .ok()
            .flatten()
            .is_some()
    }

    pub fn has_openai_key(&self) -> bool {
        self.secrets
            .get(KEY_OPENAI_API_KEY)
            .ok()
            .flatten()
            .is_some()
    }

    /// Whether a WP application password (for embedded STT) is stored.
    pub fn has_wp_app_password(&self) -> bool {
        self.secrets
            .get(KEY_WP_APP_PASSWORD)
            .ok()
            .flatten()
            .is_some()
    }

    /// Directory for model downloads.
    pub fn models_dir(&self) -> PathBuf {
        dirs::data_dir()
            .unwrap_or_else(|| PathBuf::from("."))
            .join("nvoos-dictate")
            .join("models")
    }

    /// Load the currently configured engine (creating it if needed).
    pub fn ensure_engine(&self) -> AppResult<Arc<dyn SttEngine>> {
        {
            let guard = self.engine.read().unwrap();
            if let Some(engine) = guard.clone() {
                return Ok(engine);
            }
        }
        let settings = self.settings_snapshot();
        let models_dir = self.models_dir();
        // Secrets for the remote `embedded` engine; fetched once so the
        // borrows are stable for the resources struct.
        let credential = self.secrets.get(KEY_NVOOS_CREDENTIAL)?;
        let wp_username = self.secrets.get(KEY_WP_USERNAME)?;
        let wp_app_password = self.secrets.get(KEY_WP_APP_PASSWORD)?;
        let resources = EngineResources {
            models_dir: &models_dir,
            nvoos_site_url: Some(settings.nvoos.site_url.as_str()),
            nvoos_credential: credential.as_deref(),
            wp_username: wp_username.as_deref(),
            wp_app_password: wp_app_password.as_deref(),
        };
        let engine = create_engine(&settings.engine, &resources)?;
        let engine: Arc<dyn SttEngine> = Arc::from(engine);
        engine.warm()?;
        *self.engine.write().unwrap() = Some(engine.clone());
        Ok(engine)
    }

    /// Swap the engine when settings change engine/model.
    pub fn reapply_engine(&self, previous: &Settings) -> AppResult<()> {
        let current = self.settings_snapshot();
        let changed = previous.engine.engine != current.engine.engine
            || previous.engine.model != current.engine.model;
        if !changed {
            return Ok(());
        }
        *self.engine.write().unwrap() = None;
        self.ensure_engine()?;
        Ok(())
    }

    /// Build an NV oOS client from stored settings + keychain credential.
    pub fn nvoos_client(&self) -> AppResult<crate::nvoos::NvoosClient> {
        let settings = self.settings_snapshot();
        let credential = self
            .secrets
            .get(KEY_NVOOS_CREDENTIAL)?
            .ok_or_else(|| AppError::NotConfigured("NV oOS credential not stored".into()))?;
        crate::nvoos::NvoosClient::new(settings.nvoos.site_url, credential)
    }
}

/// Build and manage the global AppState in Tauri's setup phase.
pub fn setup(app: &AppHandle) -> AppResult<()> {
    let db = Db::open_default()?;
    let secrets: Arc<dyn SecretStore> = Arc::new(OsKeyringStore);
    let state = AppState::new(app.clone(), db, secrets);
    app.manage(state);
    Ok(())
}
