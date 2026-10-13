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
use crate::paste::platform_backend;
use crate::paste::PasteBackend;
use crate::storage::Db;
use crate::stt::{create_engine, EngineResources, SttEngine};

/// Keychain service/account names. Values never leave the OS credential store.
pub const KEYRING_SERVICE: &str = "com.nvdigitalsolutions.nvoos-dictate";
pub const KEY_NVOOS_CREDENTIAL: &str = "nvoos/assistant-credential";
pub const KEY_OPENAI_API_KEY: &str = "openai-compat/api-key";
pub const KEY_LM_STUDIO_TOKEN: &str = "lm-studio/bearer-token";
pub const KEY_OLLAMA_TOKEN: &str = "ollama/bearer-token";
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
    /// Resident camera (video preview ring). Feature-gated; `None` without the
    /// `camera` feature or when no camera is running.
    pub camera: Mutex<Option<Arc<crate::camera::CameraCapture>>>,
    /// Resident vision controller (object detection + tracking). `None` when
    /// disabled or not compiled.
    pub vision: Mutex<Option<Arc<crate::vision::controller::VisionController>>>,
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
            camera: Mutex::new(None),
            vision: Mutex::new(None),
            engine: RwLock::new(None),
            paste: platform_backend(),
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
        // Reject catalogue-known engine/model mismatches BEFORE persisting:
        // a broken combo on disk re-fails every boot ("parakeet model
        // 'base.en' not found") and is hard to recover from the UI.
        crate::stt::model_registry::validate_pairing(
            &settings.engine.engine,
            &settings.engine.model,
        )?;
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

    pub fn has_lm_studio_token(&self) -> bool {
        self.secrets
            .get(KEY_LM_STUDIO_TOKEN)
            .ok()
            .flatten()
            .is_some()
    }

    pub fn has_ollama_token(&self) -> bool {
        self.secrets.get(KEY_OLLAMA_TOKEN).ok().flatten().is_some()
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

    /// Swap the resident microphone when the capture device or ring capacity
    /// changes. Mirrors [`Self::reapply_engine`]: the new capture is built
    /// before the old one is dropped (its Drop stops the worker thread and
    /// releases the cpal stream), and a failure keeps the previous device
    /// active — settings are already persisted, so the swap must not wedge
    /// the next boot.
    pub fn reapply_audio(&self, previous: &Settings) -> AppResult<()> {
        let current = self.settings_snapshot();
        let changed = previous.audio.input_device != current.audio.input_device
            || previous.audio.ring_capacity_secs != current.audio.ring_capacity_secs;
        if !changed {
            return Ok(());
        }
        self.restart_mic()
    }

    /// Rebuild the resident microphone capture on the configured device
    /// (diagnostics recovery: dead/unplugged device, changed OS default).
    /// The previous capture keeps running if the rebuild fails.
    pub fn restart_mic(&self) -> AppResult<()> {
        let settings = self.settings_snapshot();
        match MicCapture::start(
            settings.audio.ring_capacity_secs,
            settings.audio.input_device.as_deref(),
        ) {
            Ok(mic) => {
                tracing::info!(
                    device = mic.device_name(),
                    rate = mic.sample_rate(),
                    "restarted microphone capture"
                );
                *self.mic.lock().unwrap() = Some(Arc::new(mic));
                Ok(())
            }
            Err(e) => Err(AppError::Audio(format!(
                "microphone restart failed; the previous device is still active: {e}"
            ))),
        }
    }

    /// Start (or restart) the resident camera on the configured device. The
    /// previous camera keeps running if the rebuild fails. A no-op error when
    /// the `camera` feature is not compiled in.
    pub fn restart_camera(&self) -> AppResult<()> {
        let settings = self.settings_snapshot();
        match crate::camera::CameraCapture::start(settings.camera.device.as_deref()) {
            Ok(cam) => {
                tracing::info!(device = cam.device_name(), "started camera capture");
                *self.camera.lock().unwrap() = Some(Arc::new(cam));
                Ok(())
            }
            Err(e) => {
                if !crate::camera::available() {
                    *self.camera.lock().unwrap() = None;
                }
                Err(e)
            }
        }
    }

    /// Stop the resident camera and release the device.
    pub fn stop_camera(&self) {
        *self.camera.lock().unwrap() = None;
    }

    /// Resolve the on-disk path of the configured vision detector model.
    pub fn vision_model_path(&self) -> AppResult<std::path::PathBuf> {
        let settings = self.settings_snapshot();
        let spec = crate::vision::models::find(&settings.vision.detector).ok_or_else(|| {
            AppError::Model(format!(
                "unknown vision model '{}'",
                settings.vision.detector
            ))
        })?;
        Ok(crate::vision::models::model_path(spec, &self.models_dir()))
    }

    /// Start (or restart) the resident vision controller on the configured
    /// detector model, pulling frames from the resident camera ring. Requires
    /// a running camera; a no-op error when the `vision` feature is absent.
    pub fn restart_vision(&self) -> AppResult<()> {
        let settings = self.settings_snapshot();
        if !settings.vision.enabled {
            return Ok(());
        }
        let model_path = self.vision_model_path()?;
        if !model_path.is_file() {
            return Err(AppError::Model(format!(
                "vision model '{}' is not downloaded — get it from Settings",
                settings.vision.detector
            )));
        }
        // The frame ring must exist (camera running) before the loop can pull.
        let camera = self
            .camera
            .lock()
            .unwrap()
            .clone()
            .ok_or_else(|| AppError::NotConfigured("start the camera preview first".into()))?;
        let frame = camera.frame.clone();
        let detector_cfg = crate::vision::detect::DetectorConfig {
            confidence: settings.vision.confidence,
            iou: settings.vision.iou_threshold,
        };
        let tracker_cfg = crate::vision::tracker::TrackerConfig::default();
        let controller = crate::vision::controller::VisionController::start(
            model_path,
            detector_cfg,
            tracker_cfg,
            frame,
        )?;
        tracing::info!("started vision detection loop");
        *self.vision.lock().unwrap() = Some(Arc::new(controller));
        Ok(())
    }

    /// Stop the resident vision controller and release the detector.
    pub fn stop_vision(&self) {
        *self.vision.lock().unwrap() = None;
    }

    /// Re-apply vision settings (enable/disable, model/threshold swap).
    pub fn reapply_vision(&self, previous: &Settings) -> AppResult<()> {
        let current = self.settings_snapshot();
        let changed = previous.vision != current.vision;
        if !changed {
            return Ok(());
        }
        self.stop_vision();
        if current.vision.enabled {
            self.restart_vision()?;
        }
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

    /// Build an LM Studio provider from stored settings + keychain token. The
    /// token is optional (local servers need none), so this only errors on an
    /// invalid base URL, not on a missing secret.
    pub fn lm_studio_provider(
        &self,
    ) -> AppResult<crate::cleanup::providers::lm_studio::LmStudioProvider> {
        let settings = self.settings_snapshot();
        crate::cleanup::providers::lm_studio::LmStudioProvider::new(
            &settings.lm_studio,
            self.secrets.as_ref(),
        )
    }

    /// Build an Ollama provider from stored settings + keychain token (token
    /// optional for the local default).
    pub fn ollama_provider(&self) -> AppResult<crate::cleanup::providers::ollama::OllamaProvider> {
        let settings = self.settings_snapshot();
        crate::cleanup::providers::ollama::OllamaProvider::new(
            &settings.ollama,
            self.secrets.as_ref(),
        )
    }
}

/// Build and manage the global AppState in Tauri's setup phase.
pub fn setup(app: &AppHandle) -> AppResult<()> {
    let db = Db::open_default()?;
    // Windows: the native Credential Manager store (keyring 3.6.3 writes
    // CRED_PERSIST_ENTERPRISE, which silently fails to persist from an
    // unelevated app — see storage/win_cred.rs). Other platforms keep the
    // keyring crate (macOS Keychain / Linux Secret Service).
    #[cfg(windows)]
    let secrets: Arc<dyn SecretStore> = Arc::new(crate::storage::win_cred::WindowsCredentialStore);
    #[cfg(not(windows))]
    let secrets: Arc<dyn SecretStore> = Arc::new(OsKeyringStore);
    let state = AppState::new(app.clone(), db, secrets);
    app.manage(state);
    Ok(())
}
