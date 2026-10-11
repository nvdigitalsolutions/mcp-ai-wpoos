//! User settings: DTOs, defaults, and persistence.
//!
//! Security invariant: `Settings` holds **no secrets**. Credentials and API
//! keys live exclusively in the OS keychain via `state::SecretStore`. The
//! JSON file at `{app_config_dir}/config.json` must remain safe to back up
//! and safe to read by any local user.

use std::collections::HashMap;
use std::fs;
use std::path::PathBuf;

use serde::{Deserialize, Serialize};

use crate::error::AppResult;

/// Foreground-app category used to pick a cleanup profile.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize, Default)]
pub enum AppCategory {
    #[default]
    Default,
    Code,
    Terminal,
    Chat,
    Mail,
    Docs,
    Browser,
}

impl AppCategory {
    /// Map a Windows process name (lowercased, with or without `.exe`) to a category.
    pub fn from_process_name(process: &str) -> Self {
        let p = process.to_lowercase();
        match p.as_str() {
            // Code editors / IDEs.
            "code" | "code - insiders" | "devenv" | "idea64" | "pycharm64" | "webstorm64"
            | "sublime_text" | "notepad++" | "cursor" | "zed" => AppCategory::Code,
            // Terminals.
            "windowsterminal" | "wt" | "cmd" | "powershell" | "pwsh" | "alacritty"
            | "wezterm-gui" | "conhost" | "kitty" => AppCategory::Terminal,
            // Chat / messaging.
            "slack" | "discord" | "telegram" | "whatsapp" | "teams" | "zoom" | "signal"
            | "messenger" | "element" => AppCategory::Chat,
            // Mail.
            "outlook" | "hxmail" | "mail" | "thunderbird" => AppCategory::Mail,
            // Documents.
            "winword" | "excel" | "powerpnt" | "notepad" | "write" | "wordpad" | "lyx"
            | "scrivener" => AppCategory::Docs,
            // Browsers.
            "chrome" | "msedge" | "firefox" | "brave" | "opera" | "arc" => AppCategory::Browser,
            _ => AppCategory::Default,
        }
    }

    pub fn id(&self) -> &'static str {
        match self {
            AppCategory::Default => "default",
            AppCategory::Code => "code",
            AppCategory::Terminal => "terminal",
            AppCategory::Chat => "chat",
            AppCategory::Mail => "mail",
            AppCategory::Docs => "docs",
            AppCategory::Browser => "browser",
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct HotkeyConfig {
    /// Virtual-key name for push-to-talk: `RAlt` or `RCtrl`.
    pub ptt_key: String,
    /// Cancel the current dictation with Escape.
    pub esc_cancels: bool,
    /// Window (ms) in which press-release-press counts as a double-tap toggle.
    pub double_tap_ms: u64,
}

impl Default for HotkeyConfig {
    fn default() -> Self {
        Self {
            ptt_key: "RAlt".to_string(),
            esc_cancels: true,
            double_tap_ms: 400,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct EngineConfig {
    /// `parakeet` | `whisper` | `moonshine` | `mock`.
    pub engine: String,
    /// Model id per engine, e.g. `sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8` or `base.en`.
    pub model: String,
    pub language: Option<String>,
    pub num_threads: usize,
}

impl Default for EngineConfig {
    fn default() -> Self {
        Self {
            engine: "mock".to_string(),
            model: "sherpa-onnx-nemo-parakeet-tdt-0.6b-v2-int8".to_string(),
            language: None,
            num_threads: num_cpus::physical(),
        }
    }
}

/// Tiny helper so we don't pull a full num_cpus dep into settings.
mod num_cpus {
    pub fn physical() -> usize {
        std::thread::available_parallelism()
            .map(|n| n.get())
            .unwrap_or(4)
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct CleanupConfig {
    /// Stage-2 provider: `disabled` | `openai_compat` | `nvoos`.
    pub provider: String,
    /// If > 0, paste waits up to this many ms for the Stage-2 polish before
    /// falling back to Stage-1 text (decision record 0001).
    pub wait_for_llm_ms: u64,
    /// When true, a fast Stage-2 result replaces the clipboard before paste.
    pub replace_on_completion: bool,
    /// Disable Stage-1 rules entirely (raw ASR output, spacing only).
    pub deterministic_cleanup: bool,
}

impl Default for CleanupConfig {
    fn default() -> Self {
        Self {
            provider: "disabled".to_string(),
            wait_for_llm_ms: 0,
            replace_on_completion: true,
            deterministic_cleanup: true,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct NvoosConfig {
    pub site_url: String,
    pub assistant_id: Option<String>,
    /// System prompt sent with every cleanup request.
    pub cleanup_prompt: String,
    pub archive_enabled: bool,
}

impl Default for NvoosConfig {
    fn default() -> Self {
        Self {
            site_url: String::new(),
            assistant_id: None,
            cleanup_prompt: "You are a dictation editor. Return only the cleaned text: remove filler words, fix grammar and punctuation, keep names, numbers and code identifiers exactly as written, and preserve the meaning.".to_string(),
            archive_enabled: false,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct OpenAiCompatConfig {
    /// OpenAI-compatible base URL (defaults to https://api.openai.com/v1).
    pub base_url: String,
    pub model: String,
}

impl Default for OpenAiCompatConfig {
    fn default() -> Self {
        Self {
            base_url: "https://api.openai.com/v1".to_string(),
            model: "gpt-4o-mini".to_string(),
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct AudioConfig {
    pub sample_rate: u32,
    /// Pre-roll captured before the hotkey press, so the first syllable is kept.
    pub pre_roll_ms: u64,
    /// Ring buffer capacity in seconds (audio is never persisted).
    pub ring_capacity_secs: usize,
    /// Recording must be at least this long to count.
    pub min_utterance_ms: u64,
}

impl Default for AudioConfig {
    fn default() -> Self {
        Self {
            sample_rate: 16000,
            pre_roll_ms: 300,
            ring_capacity_secs: 60,
            min_utterance_ms: 120,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Default)]
#[serde(default)]
pub struct PrivacyConfig {
    /// Retain meeting audio files after transcription (default: off — audio never persists).
    pub retain_meeting_audio: bool,
    /// Telemetry is permanently disabled in this edition; kept as a dead switch.
    pub telemetry: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct UiConfig {
    /// Overlay widget position, per monitor id: "monitor:x,y" -> "x,y".
    pub overlay_positions: HashMap<String, String>,
    pub overlay_opacity: f64,
}

impl Default for UiConfig {
    fn default() -> Self {
        Self {
            overlay_positions: HashMap::new(),
            overlay_opacity: 0.92,
        }
    }
}

/// The complete persisted settings blob.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Default)]
#[serde(default)]
pub struct Settings {
    pub hotkey: HotkeyConfig,
    pub engine: EngineConfig,
    pub cleanup: CleanupConfig,
    pub nvoos: NvoosConfig,
    pub openai_compat: OpenAiCompatConfig,
    pub audio: AudioConfig,
    pub privacy: PrivacyConfig,
    pub ui: UiConfig,
}

impl Settings {
    pub fn config_path() -> PathBuf {
        dirs::config_dir()
            .unwrap_or_else(|| PathBuf::from("."))
            .join("nvoos-dictate")
            .join("config.json")
    }

    pub fn load() -> AppResult<Self> {
        let path = Self::config_path();
        if !path.exists() {
            return Ok(Self::default());
        }
        let raw = fs::read_to_string(&path)?;
        let settings: Settings = serde_json::from_str(&raw).unwrap_or_default();
        Ok(settings)
    }

    pub fn save(&self) -> AppResult<()> {
        let path = Self::config_path();
        if let Some(parent) = path.parent() {
            fs::create_dir_all(parent)?;
        }
        let raw = serde_json::to_string_pretty(self)?;
        // Atomic write: temp file + rename (crash-safe config).
        let tmp = path.with_extension("json.tmp");
        fs::write(&tmp, raw)?;
        fs::rename(&tmp, &path)?;
        Ok(())
    }
}
