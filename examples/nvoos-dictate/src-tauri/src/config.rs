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

    /// Map a macOS bundle identifier to a category (P5 port; the analogue of
    /// [`Self::from_process_name`]). Bundle IDs are case-sensitive and
    /// conventionally lowercase; compare as-is.
    pub fn from_bundle_id(bundle_id: &str) -> Self {
        match bundle_id {
            // Code editors / IDEs.
            "com.microsoft.VSCode"
            | "com.microsoft.VSCodeInsiders"
            | "com.jetbrains.intellij"
            | "com.jetbrains.pycharm"
            | "com.jetbrains.webstorm"
            | "com.sublimetext.4"
            | "com.todesktop.230313mzl4w4u92"
            | "dev.zed.Zed" => AppCategory::Code,
            // Terminals.
            "com.apple.Terminal"
            | "com.googlecode.iterm2"
            | "org.alacritty"
            | "com.github.wez.wezterm"
            | "net.kovidgoyal.kitty"
            | "io.warp" => AppCategory::Terminal,
            // Chat / messaging.
            "com.tinyspeck.slackmacgap"
            | "com.hnc.Discord"
            | "ru.keepcoder.Telegram"
            | "com.microsoft.teams2"
            | "us.zoom.xos"
            | "org.whispersystems.signal-desktop"
            | "com.viber.osx"
            | "chat.rocket.mac" => AppCategory::Chat,
            // Mail.
            "com.apple.mail"
            | "com.microsoft.Outlook"
            | "com.readdle.smartemail-Mac"
            | "org.mozilla.thunderbird"
            | "com.sparkmailapp.Spark" => AppCategory::Mail,
            // Documents.
            "com.microsoft.Word"
            | "com.microsoft.Excel"
            | "com.microsoft.Powerpoint"
            | "com.apple.TextEdit"
            | "com.apple.iWork.Pages"
            | "com.apple.iWork.Numbers"
            | "com.literatureandlatte.scrivener3" => AppCategory::Docs,
            // Browsers.
            "com.apple.Safari"
            | "com.google.Chrome"
            | "com.brave.Browser"
            | "org.mozilla.firefox"
            | "com.operasoftware.Opera"
            | "company.thebrowser.Browser" => AppCategory::Browser,
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
    /// PTT delivery mode: `hold` (press-and-hold, Windows hook or the macOS
    /// CGEventTap) or `toggle` (first activation arms, second finalizes —
    /// the permission-free combo path on macOS/Linux). Windows ignores this
    /// field (the hook always delivers hold semantics).
    pub ptt_mode: String,
    /// Global shortcut combo used in `toggle` mode (tauri global-shortcut
    /// syntax, e.g. `Command+Option+Space`). Unused on Windows.
    pub ptt_combo: String,
    /// Cancel the current dictation with Escape.
    pub esc_cancels: bool,
    /// Window (ms) in which press-release-press counts as a double-tap toggle.
    pub double_tap_ms: u64,
}

impl Default for HotkeyConfig {
    fn default() -> Self {
        Self {
            ptt_key: "RAlt".to_string(),
            // macOS: the permission-free default (macos-port-plan D2). The
            // event-tap hold mode is opt-in from Settings. Linux keeps the
            // existing plugin toggle; Windows ignores the field.
            #[cfg(target_os = "macos")]
            ptt_mode: "toggle".to_string(),
            #[cfg(not(target_os = "macos"))]
            ptt_mode: "hold".to_string(),
            #[cfg(target_os = "macos")]
            ptt_combo: "Command+Option+Space".to_string(),
            #[cfg(not(target_os = "macos"))]
            ptt_combo: "Control+Shift+Space".to_string(),
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
    /// Master switch for Stage-2 polish. Off by default — the provider must
    /// be explicitly enabled before any polish request is dispatched. This is
    /// intentionally separate from `wait_for_llm_ms` so that a `0` latency knob
    /// can no longer silently disable a feature the user already turned on.
    pub polish_enabled: bool,
    /// Paste waits up to this many ms for the Stage-2 polish before falling
    /// back to Stage-1 text (decision record 0001). Only meaningful when
    /// `polish_enabled`; `0` = paste Stage-1 text immediately (see
    /// `replace_on_completion`).
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
            polish_enabled: false,
            wait_for_llm_ms: 1500,
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
    /// Optional per-request provider override for the polish chat call
    /// (blank = the assistant's configured provider).
    pub polish_provider: Option<String>,
    /// Optional per-request model override for the polish chat call
    /// (blank = the assistant's configured model, e.g. `deepseek-flash`).
    pub polish_model: Option<String>,
    pub archive_enabled: bool,
}

impl Default for NvoosConfig {
    fn default() -> Self {
        Self {
            site_url: String::new(),
            assistant_id: None,
            cleanup_prompt: "You are a dictation editor. Return only the cleaned text: remove filler words, fix grammar and punctuation, keep names, numbers and code identifiers exactly as written, and preserve the meaning.".to_string(),
            polish_provider: None,
            polish_model: None,
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
pub struct LmStudioConfig {
    /// OpenAI-compatible base URL, e.g. `http://localhost:1234/v1`. Local
    /// servers are plain http; a remote/headless server must be https://
    /// (the provider enforces the same egress invariant as the NV oOS client).
    pub base_url: String,
    /// LM Studio model identifier (from `GET /v1/models`), or empty to omit
    /// the `model` field entirely and let the server serve its single loaded
    /// model (LM Studio's documented single-model behaviour).
    pub model: String,
    /// Idle-TTL / cold-load awareness: wait up to this many ms for a model
    /// that LM Studio must Just-In-Time load before the polish call gives up
    /// (0 = rely on `cleanup.wait_for_llm_ms` only).
    pub load_timeout_ms: u64,
}

impl Default for LmStudioConfig {
    fn default() -> Self {
        Self {
            base_url: "http://localhost:1234/v1".to_string(),
            model: String::new(),
            load_timeout_ms: 0,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct OllamaConfig {
    /// OpenAI-compatible base URL, e.g. `http://localhost:11434/v1`. Local
    /// servers are plain http; a remote/headless server must be https://.
    pub base_url: String,
    /// Ollama model name (from `GET /v1/models`), or empty to omit the
    /// `model` field and let the server use its single loaded model.
    pub model: String,
    /// Cold-load awareness: wait up to this many ms for a model that Ollama
    /// must load on demand before the polish call gives up (0 = rely on
    /// `cleanup.wait_for_llm_ms` only).
    pub load_timeout_ms: u64,
}

impl Default for OllamaConfig {
    fn default() -> Self {
        Self {
            base_url: "http://localhost:11434/v1".to_string(),
            model: String::new(),
            load_timeout_ms: 0,
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
    /// Capture device by display name, or `None` for the OS default.
    /// A stored name that no longer resolves (unplugged) falls back to the
    /// default device at start; the actual device is always visible on the
    /// Diagnostics page.
    pub input_device: Option<String>,
}

impl Default for AudioConfig {
    fn default() -> Self {
        Self {
            sample_rate: 16000,
            pre_roll_ms: 300,
            ring_capacity_secs: 60,
            min_utterance_ms: 120,
            input_device: None,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct CameraConfig {
    /// Whether the webcam/camera is enabled as a live source.
    pub enabled: bool,
    /// Camera device by display name, or `None` for the first/default camera.
    pub device: Option<String>,
    /// Show the live preview thumbnail (depends on no vision model — v1 is a
    /// passive preview only).
    pub preview: bool,
}

impl Default for CameraConfig {
    fn default() -> Self {
        Self {
            enabled: false,
            device: None,
            preview: true,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct VisionConfig {
    /// Whether local object detection is enabled (opt-in; drives a detector+
    /// tracker loop on the live camera preview).
    pub enabled: bool,
    /// Detector model id (`yolov11n` default, `yolov8n` fallback).
    pub detector: String,
    /// Confidence threshold (0..1).
    pub confidence: f32,
    /// NMS IoU threshold (0..1).
    pub iou_threshold: f32,
    /// Assign stable SORT track ids + enable click-to-select pin.
    pub track_objects: bool,
    /// Hand model id (MediaPipe-hand ONNX), or empty to disable.
    pub hand_model: String,
    /// Vision label polish (Proposal 076): relabel detection boxes with a
    /// local VLM. `polish_enabled` is opt-in and off by default (the always-on
    /// YOLO path is zero-egress).
    pub polish_enabled: bool,
    /// Polish provider: `lm_studio` | `ollama`.
    pub polish_provider: String,
    /// VLM model id (e.g. `qwen2.5-vl-7b-instruct`); blank = serve the server's
    /// single loaded model.
    pub polish_model: String,
    /// Only relabel boxes whose YOLO confidence is below this (0 = relabel all).
    pub polish_min_confidence: f32,
    /// Cap on VLM calls per second (the VLM is far slower than the detect loop).
    pub polish_max_per_second: usize,
}

impl Default for VisionConfig {
    fn default() -> Self {
        Self {
            enabled: false,
            detector: "yolov11n".to_string(),
            confidence: 0.25,
            iou_threshold: 0.45,
            track_objects: true,
            hand_model: String::new(),
            polish_enabled: false,
            polish_provider: "lm_studio".to_string(),
            polish_model: "qwen/qwen2.5-vl-7b".to_string(),
            polish_min_confidence: 0.5,
            polish_max_per_second: 2,
        }
    }
}

/// Browser-agent configuration (dictated web control, §6).
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct AgentConfig {
    /// Master switch. Off by default — the agent must be explicitly enabled
    /// and a provider picked in Settings before any run (decision #6).
    pub enabled: bool,
    /// Brain provider: `lm_studio` | `ollama` (the agent reuses the Stage-2
    /// local providers via `CleanupProvider::complete`; `nvoos` is a §5a
    /// follow-up).
    pub provider: String,
    /// Model id to ask for (empty = serve the server's single loaded model).
    pub model: String,
    /// Maximum brain turns before the loop halts.
    pub max_steps: usize,
    /// Launch the browser headed (debug) vs headless (default).
    pub headless: bool,
    /// Persistent profile directory, or empty = a throwaway session.
    pub persistent_profile_path: String,
    /// Require human confirmation for destructive actions (§4.2).
    pub confirmation_required: bool,
}

impl Default for AgentConfig {
    fn default() -> Self {
        Self {
            enabled: false,
            provider: "ollama".to_string(),
            model: String::new(),
            max_steps: 10,
            headless: true,
            persistent_profile_path: String::new(),
            confirmation_required: true,
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

/// The hosted NV oOS Pro SPA (chat surface) embedded in the Assistant tab.
/// Empty `url` = the tab shows a "not configured" hint pointing at Settings.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Default)]
#[serde(default)]
pub struct SpaConfig {
    /// Full URL of a hosted Pro SPA (e.g. `https://chat.nvoos.cloud`) or a
    /// WordPress page that renders the `[nvoos_pro_spa]` shortcode.
    pub url: String,
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
    pub lm_studio: LmStudioConfig,
    pub ollama: OllamaConfig,
    pub audio: AudioConfig,
    pub camera: CameraConfig,
    pub vision: VisionConfig,
    pub agent: AgentConfig,
    pub privacy: PrivacyConfig,
    pub ui: UiConfig,
    pub spa: SpaConfig,
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn cleanup_defaults_polish_off_with_nonzero_wait() {
        let c = CleanupConfig::default();
        // The master switch is off by default — polish must be explicitly
        // enabled, never implied by a provider choice or a wait value.
        assert!(!c.polish_enabled);
        // `wait_for_llm_ms` is a latency knob with a sane non-zero default so
        // a `0` no longer silently disables an otherwise-enabled feature.
        assert_eq!(c.wait_for_llm_ms, 1500);
        assert_eq!(c.provider, "disabled");
    }

    #[test]
    fn cleanup_missing_polish_enabled_deserializes_off_and_preserves_provider() {
        // An existing config.json (pre-`polish_enabled`) must not reset the
        // user's provider selection; the new field fills from default (false).
        let raw = r#"{"provider":"lm_studio","wait_for_llm_ms":0,"replace_on_completion":true,"deterministic_cleanup":true}"#;
        let c: CleanupConfig = serde_json::from_str(raw).unwrap();
        assert_eq!(c.provider, "lm_studio");
        assert!(!c.polish_enabled);
        assert_eq!(c.wait_for_llm_ms, 0);
    }
}
