// Typed IPC surface mirroring src-tauri/src/ipc.rs and the serialized DTOs.
// The webview is thin: all state lives in Rust; these wrappers only marshal.

import { invoke } from '@tauri-apps/api/core';
import { listen, type UnlistenFn } from '@tauri-apps/api/event';

export type AppCategory =
  | 'Default'
  | 'Code'
  | 'Terminal'
  | 'Chat'
  | 'Mail'
  | 'Docs'
  | 'Browser';

export interface Settings {
  hotkey: {
    ptt_key: string; // RAlt | RCtrl
    ptt_mode: string; // hold | toggle (macOS PTT delivery mode)
    ptt_combo: string; // global shortcut used in toggle mode
    esc_cancels: boolean;
    double_tap_ms: number;
  };
  engine: {
    engine: string; // parakeet | whisper | moonshine | mock
    model: string;
    language: string | null;
    num_threads: number;
  };
  cleanup: {
    provider: string; // disabled | openai_compat | lm_studio | ollama | nvoos
    polish_enabled: boolean;
    wait_for_llm_ms: number;
    replace_on_completion: boolean;
    deterministic_cleanup: boolean;
  };
  nvoos: {
    site_url: string;
    assistant_id: string | null;
    cleanup_prompt: string;
    polish_provider: string | null;
    polish_model: string | null;
    archive_enabled: boolean;
  };
  openai_compat: {
    base_url: string;
    model: string;
  };
  lm_studio: {
    base_url: string;
    model: string;
    load_timeout_ms: number;
  };
  ollama: {
    base_url: string;
    model: string;
    load_timeout_ms: number;
  };
  audio: {
    sample_rate: number;
    pre_roll_ms: number;
    ring_capacity_secs: number;
    min_utterance_ms: number;
    input_device: string | null;
  };
  camera: {
    enabled: boolean;
    device: string | null;
    preview: boolean;
  };
  vision: {
    enabled: boolean;
    detector: string;
    confidence: number;
    iou_threshold: number;
    track_objects: boolean;
    hand_model: string;
    polish_enabled: boolean;
    polish_provider: string; // lm_studio | ollama
    polish_model: string;
    polish_min_confidence: number;
    polish_max_per_second: number;
  };
  agent: {
    enabled: boolean;
    provider: string; // lm_studio | ollama | nvoos
    model: string;
    max_steps: number;
    headless: boolean;
    persistent_profile_path: string;
    confirmation_required: boolean;
  };
  privacy: {
    retain_meeting_audio: boolean;
    telemetry: boolean;
  };
  ui: {
    overlay_positions: Record<string, string>;
    overlay_opacity: number;
  };
  spa: {
    url: string;
  };
}

export interface StatusInfo {
  ptt_key: string;
  ptt_mode: string;
  ptt_combo: string;
  engine: string | null;
  model: string | null;
  engine_features: string;
  mic_available: boolean;
  nvoos_configured: boolean;
  nvoos_credential_stored: boolean;
  openai_key_stored: boolean;
  lm_studio_token_stored: boolean;
  ollama_token_stored: boolean;
  wp_app_password_stored: boolean;
  meeting_recording: boolean;
  system_audio_available: boolean;
  vision_available: boolean;
  vision_running: boolean;
  vision_detector: string | null;
  vision_hand_model: string | null;
  agent_available: boolean;
  agent_running: boolean;
  version: string;
  polish: string;
  spa_configured: boolean;
  spa_url: string;
}

export interface SpaCheck {
  ok: boolean;
  status: number | null;
  message: string;
}

export interface SpaSurface {
  id: string;
  title: string;
  url: string;
  description: string;
  route?: string;
}

export interface TranscriptRow {
  id: number;
  text_raw: string;
  text_clean: string;
  engine: string;
  model: string;
  target_app: string;
  provider: string;
  e2e_ms: number;
  created_at: string;
  media: string;
}

export interface MeetingRow {
  id: number;
  title: string;
  text: string;
  duration_ms: number;
  created_at: string;
}

export interface MeetingSummary {
  id: number;
  title: string;
  text: string;
  duration_ms: number;
  system_audio: boolean;
}

export interface ModelSpec {
  engine: string;
  id: string;
  description: string;
  size_mb: number;
  license: string;
  languages: string;
  installed: boolean;
}

export interface ConnectionTest {
  ok: boolean;
  message: string;
  authenticated: boolean;
}

export interface LmStudioConnectionTest {
  ok: boolean;
  message: string;
  models: string[];
  recommended_model: string | null;
}

export interface OllamaConnectionTest {
  ok: boolean;
  message: string;
  models: string[];
  recommended_model: string | null;
}

export interface MediaTranscription {
  text: string;
  duration_ms: number;
  container: string; // 'audio' | 'video'
}

export interface MediaProgress {
  phase: string; // 'decode' | 'transcribe' | 'done'
  fraction: number;
}

export interface PreviewFrame {
  width: number;
  height: number;
  rgb: number[]; // width * height * 3 bytes
}

export interface Detection {
  id: number | null;
  label: string;
  confidence: number;
  x: number;
  y: number;
  w: number;
  h: number;
  source: string; // 'coco' | 'vlm'
}

export interface VisionModelStatus {
  id: string;
  description: string;
  size_mb: number;
  license: string;
  installed: boolean;
}

export interface AgentSnapshot {
  running: boolean;
  last: {
    completed: boolean;
    summary: string;
    steps: {
      turn: number;
      action: unknown;
      verdict: string;
      detail: string;
    }[];
    final_url: string | null;
  } | null;
  last_error: string | null;
}

export interface PendingApproval {
  action: unknown;
  description: string;
}

export interface DiagnosticsInfo {
  version: string;
  os: string;
  config_path: string;
  models_dir: string;
  hotkey: {
    ptt_key: string;
    ptt_mode: string;
    hook_installed: boolean;
    hook_heartbeat_age_ms: number;
    hook_calls: number;
    swallow_pending: boolean;
  };
  mic: {
    available: boolean;
    device_name: string | null;
    sample_rate: number;
    channels: number;
    buffered_samples: number;
    live_rms: number;
    live_peak: number;
    vad_kind: string;
    capture_callbacks: number;
    capture_stall_ms: number;
    capture_errors: number;
    capture_last_error: string | null;
  };
  engine: {
    configured: string;
    model: string;
    loaded: boolean;
    features: string;
    models: ModelSpec[];
  };
  credentials: {
    nvoos_configured: boolean;
    nvoos_credential_stored: boolean;
    openai_key_stored: boolean;
    lm_studio_token_stored: boolean;
    ollama_token_stored: boolean;
    wp_credentials_stored: boolean;
  };
  vision: {
    available: boolean;
    enabled: boolean;
    running: boolean;
    detector: string;
    hand_model: string;
    detector_installed: boolean;
    confidence_threshold: number;
    iou_threshold: number;
    frames: number;
    total_detections: number;
    track_events: number;
    active_tracks: number;
    last_infer_ms: number;
    errors: number;
    last_error: string | null;
    models: VisionModelStatus[];
    polish_enabled: boolean;
    polish_provider: string;
    polish_model: string;
    polish_calls: number;
    polish_results: number;
  };
  agent: {
    available: boolean;
    enabled: boolean;
    running: boolean;
    provider: string;
    model: string;
    max_steps: number;
    confirmation_required: boolean;
    last_error: string | null;
  };
  last_dictation: {
    window_ms: number;
    utterance_ms: number;
    sample_rate: number;
    rms: number;
    peak: number;
    vad_threshold: number;
    engine: string;
    model: string;
    ok: boolean;
    error: string | null;
    e2e_ms: number | null;
    polish_ttft_ms: number | null;
    polish_total_ms: number | null;
  } | null;
}

export type DictateEvent =
  | { state: 'idle' }
  | { state: 'recording'; hint: string | null }
  | { state: 'processing' }
  | { state: 'done'; text: string; raw: string; provider: string; e2e_ms: number }
  | { state: 'error'; message: string }
  | { state: 'cancelled' };

export interface PermissionInfo {
  kind: 'microphone' | 'accessibility' | 'input_monitoring' | 'screen_recording';
  label: string;
  explanation: string;
  granted: boolean;
  promptable: boolean;
}

/** Whether the webview runs inside the macOS app (permission rows are mac-only). */
export function isMac(): boolean {
  return /Mac/i.test(navigator.platform) || /Macintosh/i.test(navigator.userAgent);
}

// ---- commands ------------------------------------------------------------

export const api = {
  getSettings: () => invoke<Settings>('get_settings'),
  updateSettings: (settings: Settings) => invoke<Settings>('update_settings', { settings }),
  getStatus: () => invoke<StatusInfo>('get_status'),
  	checkSpa: (url: string) => invoke<SpaCheck>('check_spa', { url }),
  	listSpaSurfaces: () => invoke<SpaSurface[]>('list_spa_surfaces'),
  getDiagnostics: () => invoke<DiagnosticsInfo>('get_diagnostics'),
  listInputDevices: () => invoke<string[]>('list_input_devices'),
  restartMicrophone: () => invoke<DiagnosticsInfo['mic']>('restart_microphone'),
  listCameraDevices: () => invoke<string[]>('list_camera_devices'),
  cameraAvailable: () => invoke<boolean>('camera_available'),
  startCamera: () => invoke<void>('start_camera'),
  stopCamera: () => invoke<void>('stop_camera'),
  getPreviewFrame: () => invoke<PreviewFrame | null>('get_preview_frame'),

  visionAvailable: () => invoke<boolean>('vision_available'),
  startVision: () => invoke<void>('start_vision'),
  stopVision: () => invoke<void>('stop_vision'),
  getDetections: () => invoke<Detection[]>('get_detections'),
  visionSelectTrack: (id: number) => invoke<boolean>('vision_select_track', { id }),
  getVisionModels: () => invoke<VisionModelStatus[]>('get_vision_models'),
  downloadVisionModel: (model: string, force = false) =>
    invoke<string>('download_vision_model', { model, force }),
  testVisionPolish: () => invoke<LmStudioConnectionTest>('test_vision_polish'),

  agentAvailable: () => invoke<boolean>('agent_available'),
  agentRun: (instruction: string) => invoke<void>('agent_run', { instruction }),
  agentStatus: () => invoke<AgentSnapshot>('agent_status'),
  agentPending: () => invoke<PendingApproval | null>('agent_pending'),
  agentRespond: (approve: boolean) => invoke<boolean>('agent_respond', { approve }),
  agentStop: () => invoke<void>('agent_stop'),

  setNvoosCredential: (siteUrl: string, credential: string) =>
    invoke<void>('set_nvoos_credential', { siteUrl, credential }),
  clearNvoosCredential: () => invoke<void>('clear_nvoos_credential'),
  testNvoosConnection: () => invoke<ConnectionTest>('test_nvoos_connection'),
  setOpenaiApiKey: (apiKey: string) => invoke<void>('set_openai_api_key', { apiKey }),
  clearOpenaiApiKey: () => invoke<void>('clear_openai_api_key'),
  setLmStudioToken: (token: string) => invoke<void>('set_lm_studio_token', { token }),
  clearLmStudioToken: () => invoke<void>('clear_lm_studio_token'),
  testLmStudioConnection: () => invoke<LmStudioConnectionTest>('test_lm_studio_connection'),
  setOllamaToken: (token: string) => invoke<void>('set_ollama_token', { token }),
  clearOllamaToken: () => invoke<void>('clear_ollama_token'),
  testOllamaConnection: () => invoke<OllamaConnectionTest>('test_ollama_connection'),
  setWpAppPassword: (username: string, appPassword: string) =>
    invoke<void>('set_wp_app_password', { username, appPassword }),
  clearWpAppPassword: () => invoke<void>('clear_wp_app_password'),

  listHistory: (query = '', limit = 100, offset = 0) =>
    invoke<TranscriptRow[]>('list_history', { query, limit, offset }),
  deleteHistory: (id: number) => invoke<void>('delete_history', { id }),
  clearHistory: () => invoke<void>('clear_history'),
  exportHistory: () => invoke<string>('export_history'),

  getDictionary: () => invoke<[string, string][]>('get_dictionary'),
  setDictionary: (entries: [string, string][]) => invoke<void>('set_dictionary', { entries }),

  startMeeting: () => invoke<void>('start_meeting'),
  stopMeeting: (title: string) => invoke<MeetingSummary>('stop_meeting', { title }),
  listMeetings: () => invoke<MeetingRow[]>('list_meetings'),
  deleteMeeting: (id: number) => invoke<void>('delete_meeting', { id }),

  getModels: () => invoke<ModelSpec[]>('get_models'),
  getCompiledEngines: () => invoke<string[]>('get_compiled_engines'),
  downloadModel: (engine: string, model: string, force = false) =>
    invoke<string>('download_model', { engine, model, force }),
  transcribeFile: (path: string) => invoke<string>('transcribe_file', { path }),
  transcribeMedia: (path: string) => invoke<MediaTranscription>('transcribe_media', { path }),
  summarizeMedia: (text: string) => invoke<string>('summarize_media', { text }),
  pickMediaFile: () => invoke<string | null>('pick_media_file'),
  cancelDictation: () => invoke<void>('cancel_dictation'),

  getPermissions: () => invoke<PermissionInfo[]>('get_permissions'),
  requestPermission: (kind: string) =>
    invoke<PermissionInfo>('request_permission', { kind }),
  openSystemSettings: (kind: string) => invoke<void>('open_system_settings', { kind }),
};

// ---- events --------------------------------------------------------------

export function onDictateEvent(handler: (event: DictateEvent) => void): Promise<UnlistenFn> {
  return listen<DictateEvent>('dictate://state', (e) => handler(e.payload));
}

export function onModelProgress(
  handler: (progress: { engine: string; model: string; fraction: number }) => void,
): Promise<UnlistenFn> {
  return listen<{ engine: string; model: string; fraction: number }>(
    'model://progress',
    (e) => handler(e.payload),
  );
}

export function onMediaProgress(handler: (progress: MediaProgress) => void): Promise<UnlistenFn> {
  return listen<MediaProgress>('media://progress', (e) => handler(e.payload));
}

/** Which webview window is this? (overlay = focusless PTT capsule) */
export function windowKind(): 'main' | 'overlay' {
  return new URLSearchParams(window.location.search).get('window') === 'overlay'
    ? 'overlay'
    : 'main';
}
