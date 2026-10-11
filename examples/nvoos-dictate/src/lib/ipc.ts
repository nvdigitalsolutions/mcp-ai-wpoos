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
    provider: string; // disabled | openai_compat | nvoos
    wait_for_llm_ms: number;
    replace_on_completion: boolean;
    deterministic_cleanup: boolean;
  };
  nvoos: {
    site_url: string;
    assistant_id: string | null;
    cleanup_prompt: string;
    archive_enabled: boolean;
  };
  openai_compat: {
    base_url: string;
    model: string;
  };
  audio: {
    sample_rate: number;
    pre_roll_ms: number;
    ring_capacity_secs: number;
    min_utterance_ms: number;
  };
  privacy: {
    retain_meeting_audio: boolean;
    telemetry: boolean;
  };
  ui: {
    overlay_positions: Record<string, string>;
    overlay_opacity: number;
  };
}

export interface StatusInfo {
  engine: string | null;
  model: string | null;
  engine_features: string;
  mic_available: boolean;
  nvoos_configured: boolean;
  nvoos_credential_stored: boolean;
  openai_key_stored: boolean;
  wp_app_password_stored: boolean;
  meeting_recording: boolean;
  system_audio_available: boolean;
  version: string;
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
  url: string;
  sha256: string | null;
  archive: boolean;
  file_name: string | null;
  languages: string;
}

export interface ConnectionTest {
  ok: boolean;
  message: string;
  authenticated: boolean;
}

export type DictateEvent =
  | { state: 'idle' }
  | { state: 'recording'; hint: string | null }
  | { state: 'processing' }
  | { state: 'done'; text: string; raw: string; provider: string; e2e_ms: number }
  | { state: 'error'; message: string }
  | { state: 'cancelled' };

// ---- commands ------------------------------------------------------------

export const api = {
  getSettings: () => invoke<Settings>('get_settings'),
  updateSettings: (settings: Settings) => invoke<Settings>('update_settings', { settings }),
  getStatus: () => invoke<StatusInfo>('get_status'),

  setNvoosCredential: (siteUrl: string, credential: string) =>
    invoke<void>('set_nvoos_credential', { siteUrl, credential }),
  clearNvoosCredential: () => invoke<void>('clear_nvoos_credential'),
  testNvoosConnection: () => invoke<ConnectionTest>('test_nvoos_connection'),
  setOpenaiApiKey: (apiKey: string) => invoke<void>('set_openai_api_key', { apiKey }),
  clearOpenaiApiKey: () => invoke<void>('clear_openai_api_key'),
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
  downloadModel: (engine: string, model: string) =>
    invoke<string>('download_model', { engine, model }),
  transcribeFile: (path: string) => invoke<string>('transcribe_file', { path }),
  cancelDictation: () => invoke<void>('cancel_dictation'),
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

/** Which webview window is this? (overlay = focusless PTT capsule) */
export function windowKind(): 'main' | 'overlay' {
  return new URLSearchParams(window.location.search).get('window') === 'overlay'
    ? 'overlay'
    : 'main';
}
