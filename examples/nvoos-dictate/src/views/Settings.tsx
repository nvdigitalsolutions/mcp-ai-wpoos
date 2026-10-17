import { useCallback, useEffect, useRef, useState } from 'react';
import {
  api,
  isMac,
  onModelProgress,
  type ConnectionTest,
  type LmStudioConnectionTest,
  type ModelSpec,
  type OllamaConnectionTest,
  type PermissionInfo,
  type Settings,
  type StatusInfo,
} from '../lib/ipc';

interface Props {
  onChanged: () => void;
  status: StatusInfo | null;
}

type SettingsTab = 'general' | 'engine' | 'nvoos' | 'openai' | 'lmstudio' | 'ollama' | 'agent' | 'privacy' | 'permissions';

export default function SettingsView({ onChanged, status }: Props) {
  const [tab, setTab] = useState<SettingsTab>('general');
  const [settings, setSettings] = useState<Settings | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [loadFailed, setLoadFailed] = useState(false);
  const [devices, setDevices] = useState<string[]>([]);
  const [cameras, setCameras] = useState<string[]>([]);
  const [visionModels, setVisionModels] = useState<
    { id: string; description: string; size_mb: number; license: string; installed: boolean }[]
  >([]);
  const [modelDownloading, setModelDownloading] = useState<string | null>(null);
  const [polishTesting, setPolishTesting] = useState(false);
  const [visionPolishModels, setVisionPolishModels] = useState<string[]>([]);

  // Latest settings + a debounce timer: typing must not fire an
  // `update_settings` (which swaps the speech engine) on every keystroke.
  const latestRef = useRef<Settings | null>(null);
  const saveTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const load = useCallback(async () => {
    setLoadFailed(false);
    try {
      const loaded = await api.getSettings();
      latestRef.current = loaded;
      setSettings(loaded);
    } catch (e) {
      setLoadFailed(true);
      setMessage(`Failed to load settings: ${e}`);
    }
  }, []);

  useEffect(() => {
    void load();
    // Input devices for the microphone picker; failures leave the dropdown
    // at "System default" only.
    api
      .listInputDevices()
      .then(setDevices)
      .catch(() => setDevices([]));
    // Camera devices for the preview picker (empty without the `camera` feature).
    api
      .listCameraDevices()
      .then(setCameras)
      .catch(() => setCameras([]));
    // Vision detector catalogue (empty without the `vision` feature).
    api
      .getVisionModels()
      .then(setVisionModels)
      .catch(() => setVisionModels([]));
    return () => {
      if (saveTimer.current) clearTimeout(saveTimer.current);
    };
  }, [load]);

  const flush = useCallback(async () => {
    if (saveTimer.current) {
      clearTimeout(saveTimer.current);
      saveTimer.current = null;
    }
    const next = latestRef.current;
    if (!next || busy) return;
    setBusy(true);
    setMessage('');
    try {
      const saved = await api.updateSettings(next);
      latestRef.current = saved;
      setSettings(saved);
      setMessage('Saved.');
      onChanged();
    } catch (e) {
      setMessage(`Save failed: ${e}`);
    } finally {
      setBusy(false);
    }
  }, [busy, onChanged]);

  if (!settings) {
    if (loadFailed) {
      return (
        <section className="card">
          <p className="muted">Could not load settings from the backend.</p>
          <button className="btn" onClick={() => void load()}>
            Retry
          </button>
        </section>
      );
    }
    return <p className="muted">Loading settings…</p>;
  }

  // Debounced, serialized save: latest settings win, one request at a time.
  // Returns a resolved promise to match the `onPatch` contract of the tabs.
  const patch = (partial: Partial<Settings>): Promise<void> => {
    const current = latestRef.current ?? settings;
    const next = { ...current, ...partial };
    latestRef.current = next;
    setSettings(next);
    if (saveTimer.current) clearTimeout(saveTimer.current);
    saveTimer.current = setTimeout(() => void flush(), 400);
    return Promise.resolve();
  };

  const downloadModel = async (model: string) => {
    setModelDownloading(model);
    setMessage('');
    try {
      await api.downloadVisionModel(model);
      // Refresh the catalogue so the installed flag updates.
      setVisionModels(await api.getVisionModels());
    } catch (e) {
      setMessage(`Download failed: ${e}`);
    } finally {
      setModelDownloading(null);
    }
  };

  const testVisionPolish = async () => {
    setPolishTesting(true);
    setMessage('');
    try {
      const result = await api.testVisionPolish();
      setVisionPolishModels(result.models);
      setMessage(result.message);
    } catch (e) {
      setMessage(`Vision test failed: ${e}`);
    } finally {
      setPolishTesting(false);
    }
  };

  return (
    <div className="stack">
      <nav className="sub-tabs">
        <button className={tab === 'general' ? 'tab active' : 'tab'} onClick={() => setTab('general')}>
          General
        </button>
        <button className={tab === 'engine' ? 'tab active' : 'tab'} onClick={() => setTab('engine')}>
          Engine & Models
        </button>
        <button className={tab === 'nvoos' ? 'tab active' : 'tab'} onClick={() => setTab('nvoos')}>
          NV oOS
        </button>
        <button className={tab === 'openai' ? 'tab active' : 'tab'} onClick={() => setTab('openai')}>
          OpenAI-compatible
        </button>
        <button className={tab === 'lmstudio' ? 'tab active' : 'tab'} onClick={() => setTab('lmstudio')}>
          LM Studio
        </button>
        <button className={tab === 'ollama' ? 'tab active' : 'tab'} onClick={() => setTab('ollama')}>
          Ollama
        </button>
        <button className={tab === 'agent' ? 'tab active' : 'tab'} onClick={() => setTab('agent')}>
          Browser Agent
        </button>
        <button className={tab === 'privacy' ? 'tab active' : 'tab'} onClick={() => setTab('privacy')}>
          Privacy
        </button>
        {isMac() && (
          <button
            className={tab === 'permissions' ? 'tab active' : 'tab'}
            onClick={() => setTab('permissions')}
          >
            Permissions
          </button>
        )}
      </nav>

      {message && <p className="muted">{message}</p>}

      {tab === 'general' && (
        <section className="card">
          <h2>Hotkey</h2>
          <label className="row">
            <span>Push-to-talk key</span>
            <select
              value={settings.hotkey.ptt_key}
              onChange={(e) =>
                patch({ hotkey: { ...settings.hotkey, ptt_key: e.target.value } })
              }
            >
              <option value="RAlt">Right Alt (recommended — AltGr stays usable outside dictation)</option>
              <option value="RCtrl">Right Control</option>
            </select>
          </label>
          {isMac() && (
            <>
              <label className="row">
                <span>PTT mode</span>
                <select
                  value={settings.hotkey.ptt_mode}
                  onChange={(e) =>
                    patch({ hotkey: { ...settings.hotkey, ptt_mode: e.target.value } })
                  }
                >
                  <option value="toggle">
                    Toggle via shortcut (no permissions needed — recommended)
                  </option>
                  <option value="hold">
                    Hold Right Option (needs Accessibility + Input Monitoring)
                  </option>
                </select>
              </label>
              {settings.hotkey.ptt_mode === 'toggle' && (
                <label className="row">
                  <span>Shortcut combo</span>
                  <input
                    type="text"
                    value={settings.hotkey.ptt_combo}
                    onChange={(e) =>
                      patch({ hotkey: { ...settings.hotkey, ptt_combo: e.target.value } })
                    }
                  />
                </label>
              )}
            </>
          )}
          <label className="row">
            <span>Esc cancels</span>
            <input
              type="checkbox"
              checked={settings.hotkey.esc_cancels}
              onChange={(e) =>
                patch({ hotkey: { ...settings.hotkey, esc_cancels: e.target.checked } })
              }
            />
          </label>
          <h2>Microphone</h2>
          <label className="row">
            <span>Input device</span>
            <select
              value={settings.audio.input_device ?? ''}
              onChange={(e) =>
                patch({
                  audio: { ...settings.audio, input_device: e.target.value || null },
                })
              }
            >
              <option value="">System default</option>
              {devices.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </label>
          <p className="muted">
            Capture restarts on the selected device when you save. The Diagnostics tab shows
            which device the app is actually hearing, with a live level meter.
          </p>
          {cameras.length > 0 && (
            <>
              <h2>Camera</h2>
              <label className="row">
                <span>Preview device</span>
                <select
                  value={settings.camera.device ?? ''}
                  onChange={(e) =>
                    patch({
                      camera: { ...settings.camera, device: e.target.value || null },
                    })
                  }
                >
                  <option value="">First camera</option>
                  {cameras.map((name) => (
                    <option key={name} value={name}>
                      {name}
                    </option>
                  ))}
                </select>
              </label>
              <p className="muted">
                Live preview only in this version — audio transcription uses your selected
                microphone (a webcam mic appears under Microphone above).
              </p>
            </>
          )}
          {visionModels.length > 0 && (
            <>
              <h2>Vision</h2>
              <label className="row">
                <span>Object detection (auto-start with camera)</span>
                <input
                  type="checkbox"
                  checked={settings.vision.enabled}
                  onChange={(e) =>
                    patch({ vision: { ...settings.vision, enabled: e.target.checked } })
                  }
                />
              </label>
              <label className="row">
                <span>Detector model</span>
                <select
                  value={settings.vision.detector}
                  onChange={(e) =>
                    patch({ vision: { ...settings.vision, detector: e.target.value } })
                  }
                >
                  {visionModels.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.id}
                      {m.installed ? '' : ' (not downloaded)'}
                    </option>
                  ))}
                </select>
              </label>
              <div className="btn-row">
                {visionModels
                  .filter((m) => !m.installed)
                  .map((m) => (
                    <button
                      key={m.id}
                      className="btn ghost"
                      disabled={modelDownloading !== null}
                      onClick={() => downloadModel(m.id)}
                    >
                      {modelDownloading === m.id ? 'Downloading…' : `Download ${m.id}`}
                    </button>
                  ))}
              </div>
              <label className="row">
                <span>Confidence threshold</span>
                <input
                  type="number"
                  step={0.05}
                  min={0.05}
                  max={0.95}
                  value={settings.vision.confidence}
                  onChange={(e) =>
                    patch({
                      vision: {
                        ...settings.vision,
                        confidence: parseFloat(e.target.value) || 0.25,
                      },
                    })
                  }
                />
              </label>
              <label className="row">
                <span>Stable track ids (click-to-pin)</span>
                <input
                  type="checkbox"
                  checked={settings.vision.track_objects}
                  onChange={(e) =>
                    patch({ vision: { ...settings.vision, track_objects: e.target.checked } })
                  }
                />
              </label>

              <h3>Label polish (local VLM)</h3>
              <label className="row">
                <span>Relabel detections with a vision model</span>
                <input
                  type="checkbox"
                  checked={settings.vision.polish_enabled}
                  onChange={(e) =>
                    patch({ vision: { ...settings.vision, polish_enabled: e.target.checked } })
                  }
                />
              </label>
              {settings.vision.polish_enabled && (
                <>
                  <label className="row">
                    <span>Provider</span>
                    <select
                      value={settings.vision.polish_provider}
                      onChange={(e) =>
                        patch({
                          vision: { ...settings.vision, polish_provider: e.target.value },
                        })
                      }
                    >
                      <option value="lm_studio">LM Studio</option>
                      <option value="ollama">Ollama</option>
                    </select>
                  </label>
                  <label className="row">
                    <span>Vision model</span>
                    <input
                      type="text"
                      value={settings.vision.polish_model}
                      placeholder="qwen/qwen2.5-vl-7b"
                      onChange={(e) =>
                        patch({ vision: { ...settings.vision, polish_model: e.target.value } })
                      }
                    />
                  </label>
                  <div className="btn-row">
                    <button className="btn ghost" onClick={testVisionPolish}>
                      {polishTesting ? 'Testing…' : 'Test / list models'}
                    </button>
                    {visionPolishModels.length > 0 && (
                      <select
                        value=""
                        onChange={(e) => {
                          if (e.target.value) {
                            patch({
                              vision: { ...settings.vision, polish_model: e.target.value },
                            });
                          }
                        }}
                      >
                        <option value="">use a discovered model…</option>
                        {visionPolishModels.map((m) => (
                          <option key={m} value={m}>
                            {m}
                          </option>
                        ))}
                      </select>
                    )}
                  </div>
                  <label className="row">
                    <span>Relabel only below COCO confidence</span>
                    <input
                      type="number"
                      step={0.05}
                      min={0}
                      max={0.95}
                      value={settings.vision.polish_min_confidence}
                      onChange={(e) =>
                        patch({
                          vision: {
                            ...settings.vision,
                            polish_min_confidence: parseFloat(e.target.value) || 0.5,
                          },
                        })
                      }
                    />
                  </label>
                  <label className="row">
                    <span>VLM calls per second</span>
                    <input
                      type="number"
                      step={1}
                      min={1}
                      max={10}
                      value={settings.vision.polish_max_per_second}
                      onChange={(e) =>
                        patch({
                          vision: {
                            ...settings.vision,
                            polish_max_per_second: parseInt(e.target.value, 10) || 2,
                          },
                        })
                      }
                    />
                  </label>
                  <p className="muted">
                    A local vision model (e.g. Qwen2.5-VL) names each detected object accurately,
                    replacing the generic COCO label. Runs entirely on-device via LM Studio/Ollama.
                  </p>
                </>
              )}
              <p className="muted">
                Fully on-device (YOLO via ONNX Runtime + optional local VLM) — pixels never leave
                your computer. Detects objects in the live preview; no facial recognition or person
                identity. Toggle detection on the Home camera preview (or auto-start with the camera).
              </p>
            </>
          )}
          <h2>Cleanup</h2>
          <label className="row">
            <span>Stage-2 AI polish</span>
            <select
              value={settings.cleanup.provider}
              onChange={(e) => patch({ cleanup: { ...settings.cleanup, provider: e.target.value } })}
            >
              <option value="disabled">Disabled (deterministic only — recommended default)</option>
              <option value="nvoos">NV oOS assistant (your WordPress site)</option>
              <option value="openai_compat">OpenAI-compatible API</option>
              <option value="lm_studio">LM Studio (local model)</option>
              <option value="ollama">Ollama (local model)</option>
            </select>
          </label>
          <label className="row">
            <span>Enable Stage-2 polish</span>
            <input
              type="checkbox"
              checked={settings.cleanup.polish_enabled}
              onChange={(e) =>
                patch({ cleanup: { ...settings.cleanup, polish_enabled: e.target.checked } })
              }
            />
          </label>
          <p className="muted">
            Runs after deterministic cleanup. Picking a provider above no longer enables it on its
            own — flip this switch on too, or only the deterministic pass runs.
          </p>
          <label className="row">
            <span>Wait for polish (ms, 0 = paste immediately)</span>
            <input
              type="number"
              min={0}
              max={8000}
              value={settings.cleanup.wait_for_llm_ms}
              onChange={(e) =>
                patch({
                  cleanup: { ...settings.cleanup, wait_for_llm_ms: Number(e.target.value) },
                })
              }
            />
          </label>
          <label className="row">
            <span>Deterministic cleanup</span>
            <input
              type="checkbox"
              checked={settings.cleanup.deterministic_cleanup}
              onChange={(e) =>
                patch({
                  cleanup: { ...settings.cleanup, deterministic_cleanup: e.target.checked },
                })
              }
            />
          </label>
        </section>
      )}

      {tab === 'engine' && (
        <EngineTab settings={settings} onPatch={patch} />
      )}

      {tab === 'nvoos' && <NvoosTab settings={settings} onPatch={patch} status={status} onChanged={onChanged} />}

      {tab === 'lmstudio' && <LmStudioTab settings={settings} onPatch={patch} status={status} onChanged={onChanged} />}

      {tab === 'ollama' && <OllamaTab settings={settings} onPatch={patch} status={status} onChanged={onChanged} />}

      {tab === 'agent' && (
        <section className="card">
          <h2>Browser agent</h2>
          <p className="muted">
            Dictate an instruction and a local model will drive a real browser to carry it
            out. Destructive actions (clicks, submits, PDFs) pause for confirmation by
            default. Requires the `agent` build feature and a local model server.
          </p>
          <label className="row">
            <span>Enable browser agent</span>
            <input
              type="checkbox"
              checked={settings.agent.enabled}
              onChange={(e) =>
                patch({ agent: { ...settings.agent, enabled: e.target.checked } })
              }
            />
          </label>
          <label className="row">
            <span>Brain provider</span>
            <select
              value={settings.agent.provider}
              onChange={(e) =>
                patch({ agent: { ...settings.agent, provider: e.target.value } })
              }
            >
              <option value="ollama">Ollama (local)</option>
              <option value="lm_studio">LM Studio (local)</option>
              <option value="nvoos">NV oOS (hosted, your site)</option>
            </select>
          </label>
          <label className="row">
            <span>Model (empty = serve the single loaded model)</span>
            <input
              type="text"
              value={settings.agent.model}
              onChange={(e) => patch({ agent: { ...settings.agent, model: e.target.value } })}
            />
          </label>
          <label className="row">
            <span>Max steps per run</span>
            <input
              type="number"
              min={1}
              max={50}
              value={settings.agent.max_steps}
              onChange={(e) =>
                patch({
                  agent: { ...settings.agent, max_steps: Number(e.target.value) || 10 },
                })
              }
            />
          </label>
          <label className="row">
            <span>Require confirmation for destructive actions</span>
            <input
              type="checkbox"
              checked={settings.agent.confirmation_required}
              onChange={(e) =>
                patch({
                  agent: { ...settings.agent, confirmation_required: e.target.checked },
                })
              }
            />
          </label>
        </section>
      )}

      {tab === 'openai' && (
        <section className="card">
          <h2>OpenAI-compatible provider</h2>
          <label className="row">
            <span>Base URL</span>
            <input
              type="text"
              value={settings.openai_compat.base_url}
              onChange={(e) =>
                patch({ openai_compat: { ...settings.openai_compat, base_url: e.target.value } })
              }
            />
          </label>
          <label className="row">
            <span>Model</span>
            <input
              type="text"
              value={settings.openai_compat.model}
              onChange={(e) =>
                patch({ openai_compat: { ...settings.openai_compat, model: e.target.value } })
              }
            />
          </label>
          <ApiKeyPanel
            onSet={(key) => api.setOpenaiApiKey(key).then(() => onChanged())}
            onClear={() => api.clearOpenaiApiKey().then(() => onChanged())}
            stored={!!status?.openai_key_stored}
            label="API key (stored in the OS keychain, never in config files)"
          />
        </section>
      )}

      {tab === 'privacy' && (
        <section className="card">
          <h2>Privacy</h2>
          <label className="row">
            <span>Retain meeting audio after transcription</span>
            <input
              type="checkbox"
              checked={settings.privacy.retain_meeting_audio}
              onChange={(e) =>
                patch({ privacy: { ...settings.privacy, retain_meeting_audio: e.target.checked } })
              }
            />
          </label>
          <p className="muted">
            Off = audio is deleted immediately after transcription. Telemetry is permanently
            disabled in this edition and cannot be enabled.
          </p>
        </section>
      )}

      {tab === 'permissions' && isMac() && <PermissionsTab />}

      {busy && <p className="muted">Saving…</p>}
    </div>
  );
}

/** macOS TCC permission rows (phase M1): detect, request, deep-link. */
function PermissionsTab() {
  const [permissions, setPermissions] = useState<PermissionInfo[]>([]);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    api
      .getPermissions()
      .then(setPermissions)
      .catch((e) => setError(`Failed to read permissions: ${e}`));
  }, []);

  useEffect(() => {
    load();
    // Permissions can change while the app runs (user toggles the pane,
    // grants from the prompt) — re-check when the window regains focus.
    const onFocus = () => void load();
    window.addEventListener('focus', onFocus);
    return () => window.removeEventListener('focus', onFocus);
  }, [load]);

  if (permissions.length === 0 && !error) {
    return (
      <section className="card">
        <p className="muted">Reading macOS permissions…</p>
      </section>
    );
  }

  return (
    <section className="card">
      <h2>macOS permissions</h2>
      <p className="muted">
        Each capability is a separate macOS grant. Denied permissions degrade gracefully —
        dictation always works with the shortcut toggle, and meetings can run microphone-only.
      </p>
      {error && <p className="danger">{error}</p>}
      <ul className="permissions">
        {permissions.map((p) => (
          <li key={p.kind} className="permission-row">
            <div>
              <strong>{p.label}</strong>
              <span className="muted">{p.granted ? 'Granted ✓' : 'Not granted'}</span>
              <div className="muted">{p.explanation}</div>
            </div>
            <span className="row">
              <button
                className="btn"
                disabled={p.granted}
                onClick={() =>
                  void api
                    .requestPermission(p.kind)
                    .then(() => load())
                    .catch((e) => setError(String(e)))
                }
              >
                {p.kind === 'microphone'
                  ? 'Start microphone (triggers the prompt)'
                  : p.promptable
                    ? 'Request'
                    : 'Open settings pane'}
              </button>
              <button className="btn" onClick={() => void api.openSystemSettings(p.kind)}>
                System Settings
              </button>
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

function EngineTab({
  settings,
  onPatch,
}: {
  settings: Settings;
  onPatch: (p: Partial<Settings>) => Promise<void>;
}) {
  const [models, setModels] = useState<ModelSpec[]>([]);
  const [engines, setEngines] = useState<string[]>([]);
  const [progress, setProgress] = useState<Record<string, number>>({});
  const [error, setError] = useState('');

  useEffect(() => {
    api
      .getModels()
      .then(setModels)
      .catch((e) => setError(`Failed to list models: ${e}`));
    api
      .getCompiledEngines()
      .then(setEngines)
      .catch((e) => setError(`Failed to list engines: ${e}`));
    const unlisten = onModelProgress(({ engine, model, fraction }) => {
      setProgress((p) => ({ ...p, [`${engine}/${model}`]: fraction }));
    });
    return () => {
      void unlisten.then((u) => u());
    };
  }, []);

  const download = async (engine: string, model: string, force = false) => {
    setError('');
    setProgress((p) => ({ ...p, [`${engine}/${model}`]: 0 }));
    try {
      await api.downloadModel(engine, model, force);
      setProgress((p) => ({ ...p, [`${engine}/${model}`]: 1 }));
      setModels(await api.getModels());
    } catch (e) {
      setError(`Download failed: ${e}`);
    }
  };

  const entries = models.filter(
    (m) => m.engine === settings.engine.engine || m.engine === 'vad',
  );
  const modelsForEngine = models.filter((m) => m.engine === settings.engine.engine);

  return (
    <section className="card">
      <h2>Speech engine</h2>
      <label className="row">
        <span>Engine</span>
        <select
          value={settings.engine.engine}
          onChange={(e) => {
            const engine = e.target.value;
            // Reset the model to a catalogue entry of the new engine so the
            // dropdown can never land on a cross-engine mismatch.
            const first = models.find((m) => m.engine === engine);
            patchEngine(onPatch, settings, {
              engine,
              model: first ? first.id : '',
            });
          }}
        >
          {engines.includes('mock') && (
            <option value="mock">Mock (no model — pipeline testing only)</option>
          )}
          {engines.includes('parakeet') && (
            <option value="parakeet">Parakeet TDT (fastest English, ~600 MB, local)</option>
          )}
          {engines.includes('whisper') && (
            <option value="whisper">Whisper (multilingual, local)</option>
          )}
          {engines.includes('moonshine') && (
            <option value="moonshine">Moonshine v2 (streaming — experimental, local)</option>
          )}
          {engines.includes('fastconformer') && (
            <option value="fastconformer">FastConformer-CTC (high accuracy English, ~162 MB, local)</option>
          )}
          <option value="embedded">Embedded (plugin) — remote STT via your NV oOS site</option>
          {!engines.includes(settings.engine.engine) && (
            <option value={settings.engine.engine} disabled>
              {settings.engine.engine} (not compiled into this build)
            </option>
          )}
        </select>
      </label>
      {settings.engine.engine === 'embedded' && (
        <p className="muted">
          The Embedded engine transcribes on your NV oOS site (Gemma 4 audio endpoint) — no
          local model download. Audio is sent only to that site. Configure the Site URL and a
          WP application password in the NV oOS tab.
        </p>
      )}
      <label className="row">
        <span>Model</span>
        {modelsForEngine.length > 0 ? (
          <select
            value={settings.engine.model}
            onChange={(e) => patchEngine(onPatch, settings, { model: e.target.value })}
          >
            {!modelsForEngine.some((m) => m.id === settings.engine.model) && (
              <option value={settings.engine.model} disabled>
                {settings.engine.model} (not in catalogue)
              </option>
            )}
            {modelsForEngine.map((m) => (
              <option key={m.id} value={m.id}>
                {m.id}
              </option>
            ))}
          </select>
        ) : (
          <select disabled value={settings.engine.model}>
            <option>{settings.engine.model || 'no model'}</option>
          </select>
        )}
      </label>
      <p className="muted">
        Only models belonging to the selected engine are offered, so a mismatch like
        &ldquo;parakeet + base.en&rdquo; cannot be saved. Custom model ids can still be
        edited directly in config.json.
      </p>
      <label className="row">
        <span>Language (optional BCP-47, e.g. en)</span>
        <input
          type="text"
          value={settings.engine.language ?? ''}
          placeholder="auto"
          onChange={(e) =>
            patchEngine(onPatch, settings, { language: e.target.value || null })
          }
        />
      </label>

      <h3>Available models for this engine</h3>
      {error && <p className="danger">{error}</p>}
      {entries.length === 0 && <p className="muted">No catalogue entries.</p>}
      <ul className="models">
        {entries.map((m) => (
          <li key={m.id}>
            <div>
              <strong>{m.id}</strong> — {m.description}
              <div className="muted">
                ~{m.size_mb} MB · {m.license} · {m.languages}
              </div>
            </div>
            {m.installed ? (
              <span className="row">
                <span className="muted">Installed ✓</span>
                <button
                  className="btn"
                  disabled={progress[`${m.engine}/${m.id}`] !== undefined &&
                    progress[`${m.engine}/${m.id}`] < 1}
                  onClick={() => void download(m.engine, m.id, true)}
                  title="Discard and re-download this model from scratch (useful when a file is suspected corrupt)"
                >
                  Re-download
                </button>
              </span>
            ) : (
              <button
                className="btn"
                disabled={progress[`${m.engine}/${m.id}`] !== undefined &&
                  progress[`${m.engine}/${m.id}`] < 1}
                onClick={() => void download(m.engine, m.id)}
              >
                {progress[`${m.engine}/${m.id}`] === undefined
                  ? 'Download'
                  : progress[`${m.engine}/${m.id}`] < 1
                    ? `Downloading ${Math.round(progress[`${m.engine}/${m.id}`] * 100)}%`
                    : 'Downloaded'}
              </button>
            )}
          </li>
        ))}
      </ul>
    </section>
  );
}

function patchEngine(
  onPatch: (p: Partial<Settings>) => Promise<void>,
  settings: Settings,
  partial: Partial<Settings['engine']>,
) {
  return void onPatch({ engine: { ...settings.engine, ...partial } });
}

function NvoosTab({
  settings,
  onPatch,
  status,
  onChanged,
}: {
  settings: Settings;
  onPatch: (p: Partial<Settings>) => Promise<void>;
  status: StatusInfo | null;
  onChanged: () => void;
}) {
  const [test, setTest] = useState<ConnectionTest | null>(null);
  const [credValue, setCredValue] = useState('');
  const [credError, setCredError] = useState('');
  const [wpUser, setWpUser] = useState('');
  const [wpPass, setWpPass] = useState('');
  const [wpError, setWpError] = useState('');
  const stored = !!status?.nvoos_credential_stored;

  return (
    <section className="card">
      <h2>NV oOS WordPress site</h2>
      <p className="muted">
        Your site is the optional AI enrichment backend: the Stage-2 polish call sends only the
        transcript text (plus the target-app category) to your assistant over the chat REST
        endpoint. Create an assistant credential in the NV oOS plugin first.
      </p>
      <label className="row">
        <span>Site URL (https)</span>
        <input
          type="text"
          value={settings.nvoos.site_url}
          placeholder="https://yoursite.com"
          onChange={(e) => patchNvoos(onPatch, settings, { site_url: e.target.value })}
        />
      </label>
      					<label className="row">
      						<span>Default SPA URL (legacy fallback)</span>
      						<input
      							type="text"
      							value={settings.spa.url}
      							placeholder="https://chat.nvoos.cloud — or your site's [nvoos_pro_spa] page"
      							onChange={(e) => onPatch({ spa: { ...settings.spa, url: e.target.value } })}
      						/>
      					</label>
      					<p className="muted">
      						The NV oOS tab discovers its surfaces from this site (assistant, chat, toolkit…)
      						and shows one sub-tab per installed addon. This URL is only used as a fallback when
      						the site cannot be reached or has no Pro SPA endpoint.
      					</p>
      <label className="row">
        <span>Assistant ID (optional — uses site default)</span>
        <input
          type="text"
          value={settings.nvoos.assistant_id ?? ''}
          onChange={(e) =>
            patchNvoos(onPatch, settings, { assistant_id: e.target.value || null })
          }
        />
      </label>
      <label className="row">
        <span>Polish provider override (optional)</span>
        <input
          type="text"
          value={settings.nvoos.polish_provider ?? ''}
          placeholder="deepseek"
          onChange={(e) =>
            patchNvoos(onPatch, settings, { polish_provider: e.target.value || null })
          }
        />
      </label>
      <label className="row">
        <span>Polish model override (optional)</span>
        <input
          type="text"
          value={settings.nvoos.polish_model ?? ''}
          placeholder="deepseek-flash"
          onChange={(e) =>
            patchNvoos(onPatch, settings, { polish_model: e.target.value || null })
          }
        />
      </label>
      <p className="muted">
        Sent as <code>options.provider</code> / <code>options.model</code> on the polish chat
        request; blank uses the assistant&#39;s configured provider and model. The site answers
        only with providers that have an API key configured on it — e.g.{' '}
        <code>deepseek</code> + <code>deepseek-flash</code> (fast, cheap — recommended for
        transcript cleanup), <code>gemini</code> + <code>gemini-3.8-flash</code>, or{' '}
        <code>openai</code> + <code>gpt-5.6-luna</code>.
      </p>
      <label className="row">
        <span>Cleanup system prompt</span>
        <textarea
          value={settings.nvoos.cleanup_prompt}
          rows={3}
          onChange={(e) => patchNvoos(onPatch, settings, { cleanup_prompt: e.target.value })}
        />
      </label>
      <label className="row">
        <span>Archive transcripts to the site</span>
        <input
          type="checkbox"
          checked={settings.nvoos.archive_enabled}
          onChange={(e) =>
            patchNvoos(onPatch, settings, { archive_enabled: e.target.checked })
          }
        />
      </label>

      <CredentialPanel
        value={credValue}
        onValueChange={setCredValue}
        stored={stored}
        error={credError}
        onSet={async (cred) => {
          setCredError('');
          try {
            await api.setNvoosCredential(settings.nvoos.site_url, cred);
            // Refresh the app status so the stored badge + Test button
            // reflect the new state immediately.
            onChanged();
            setTest(null);
          } catch (e) {
            setCredError(String(e));
            throw e;
          }
        }}
        onClear={async () => {
          setCredError('');
          try {
            await api.clearNvoosCredential();
            onChanged();
            setTest(null);
          } catch (e) {
            setCredError(String(e));
          }
        }}
        label="Assistant credential (cred_XXXXX.SECRET — stored in the OS keychain)"
      />
      <button
        className="btn"
        disabled={credValue.trim() !== '' && !stored}
        title={
          credValue.trim() !== '' && !stored
            ? 'Store the credential first (or type it and press Store)'
            : undefined
        }
        onClick={() => void api.testNvoosConnection().then(setTest)}
      >
        Test connection
      </button>
      {test && (
        <p className={test.ok ? 'ok-text' : 'warn-text'}>
          {test.ok ? '✓ ' : '✗ '}
          {test.message}
          {test.authenticated ? ' (credential accepted)' : ''}
        </p>
      )}
      {!stored && (
        <p className="muted">
          No credential stored yet — paste one above, press <strong>Store</strong>, then test.
        </p>
      )}

      <h3>WP application password (for Embedded STT)</h3>
      <p className="muted">
        Create one in wp-admin → Users → Profile → Application Passwords. This authorizes the
        Embedded engine without any addon changes; both values live in the OS keychain.
      </p>
      <div className="credential">
        <label className="row">
          <span>WP username</span>
          <input
            type="text"
            value={wpUser}
            onChange={(e) => setWpUser(e.target.value)}
            autoComplete="off"
          />
        </label>
        <label className="row">
          <span>Application password</span>
          <input
            type="password"
            value={wpPass}
            onChange={(e) => setWpPass(e.target.value)}
            autoComplete="off"
          />
        </label>
        <div className="btn-row">
          <button
            className="btn"
            disabled={!wpUser.trim() || !wpPass.trim()}
            onClick={() =>
              void api
                .setWpAppPassword(wpUser.trim(), wpPass.trim())
                .then(() => {
                  setWpUser('');
                  setWpPass('');
                  setWpError('');
                  onChanged();
                })
                .catch((e) => setWpError(String(e)))
            }
          >
            Store
          </button>
          <button className="btn ghost" onClick={() => void api.clearWpAppPassword()}>
            Remove
          </button>
        </div>
        {wpError && <p className="warn-text">{wpError}</p>}
      </div>
    </section>
  );
}

function patchNvoos(
  onPatch: (p: Partial<Settings>) => Promise<void>,
  settings: Settings,
  partial: Partial<Settings['nvoos']>,
) {
  return void onPatch({ nvoos: { ...settings.nvoos, ...partial } });
}

function LmStudioTab({
  settings,
  onPatch,
  status,
  onChanged,
}: {
  settings: Settings;
  onPatch: (p: Partial<Settings>) => Promise<void>;
  status: StatusInfo | null;
  onChanged: () => void;
}) {
  const [test, setTest] = useState<LmStudioConnectionTest | null>(null);
  const [testing, setTesting] = useState(false);

  const runTest = async () => {
    setTesting(true);
    setTest(null);
    try {
      const result = await api.testLmStudioConnection();
      setTest(result);
      // Auto-select a discovered model that isn't already pinned.
      if (result.ok && result.recommended_model && !settings.lm_studio.model) {
        onPatch({ lm_studio: { ...settings.lm_studio, model: result.recommended_model } });
      }
    } catch (e) {
      setTest({ ok: false, message: String(e), models: [], recommended_model: null });
    } finally {
      setTesting(false);
    }
  };

  return (
    <section className="card">
      <h2>LM Studio (local model)</h2>
      <p className="muted">
        Run Stage-2 polish against a model served locally by LM Studio — text never leaves
        your machine. Start the server in LM Studio (Developer → Local Server), then test the
        connection here to discover the loaded model.
      </p>
      <label className="row">
        <span>Base URL (include /v1)</span>
        <input
          type="text"
          value={settings.lm_studio.base_url}
          placeholder="http://localhost:1234/v1"
          onChange={(e) => patchLmStudio(onPatch, settings, { base_url: e.target.value })}
        />
      </label>
      <label className="row">
        <span>Model (empty = server's loaded model)</span>
        <input
          type="text"
          value={settings.lm_studio.model}
          placeholder="e.g. qwen2.5-7b-instruct"
          onChange={(e) => patchLmStudio(onPatch, settings, { model: e.target.value })}
        />
      </label>
      {test && test.models.length > 0 && (
        <label className="row">
          <span>Discovered models</span>
          <select
            value={settings.lm_studio.model}
            onChange={(e) => patchLmStudio(onPatch, settings, { model: e.target.value })}
          >
            <option value="">Use the server's loaded model</option>
            {test.models.map((m) => (
              <option key={m} value={m}>
                {m}
              </option>
            ))}
          </select>
        </label>
      )}
      <ApiKeyPanel
        onSet={(token) => api.setLmStudioToken(token).then(() => onChanged())}
        onClear={() => api.clearLmStudioToken().then(() => onChanged())}
        stored={!!status?.lm_studio_token_stored}
        label="Bearer token (optional — only for a remote/headless server, stored in the OS keychain)"
      />
      <label className="row">
        <span>Cold-load wait (ms, 0 = default)</span>
        <input
          type="number"
          min={0}
          max={30000}
          value={settings.lm_studio.load_timeout_ms}
          onChange={(e) =>
            patchLmStudio(onPatch, settings, { load_timeout_ms: Number(e.target.value) })
          }
        />
      </label>
      <p className="muted">
        LM Studio can idle-unload a model; the next dictation then pays a one-time cold-load
        cost. Increase the wait in the General tab's <em>Wait for polish</em> field if the
        first dictation after a pause falls back to deterministic text.
      </p>
      <button className="btn" disabled={testing} onClick={() => void runTest()}>
        {testing ? 'Testing…' : 'Test connection'}
      </button>
      {test && (
        <p className={test.ok ? 'ok-text' : 'warn-text'}>
          {test.ok ? '✓ ' : '✗ '}
          {test.message}
        </p>
      )}
    </section>
  );
}

function patchLmStudio(
  onPatch: (p: Partial<Settings>) => Promise<void>,
  settings: Settings,
  partial: Partial<Settings['lm_studio']>,
) {
  return void onPatch({ lm_studio: { ...settings.lm_studio, ...partial } });
}

function OllamaTab({
  settings,
  onPatch,
  status,
  onChanged,
}: {
  settings: Settings;
  onPatch: (p: Partial<Settings>) => Promise<void>;
  status: StatusInfo | null;
  onChanged: () => void;
}) {
  const [test, setTest] = useState<OllamaConnectionTest | null>(null);
  const [testing, setTesting] = useState(false);

  const runTest = async () => {
    setTesting(true);
    setTest(null);
    try {
      const result = await api.testOllamaConnection();
      setTest(result);
      if (result.ok && result.recommended_model && !settings.ollama.model) {
        onPatch({ ollama: { ...settings.ollama, model: result.recommended_model } });
      }
    } catch (e) {
      setTest({ ok: false, message: String(e), models: [], recommended_model: null });
    } finally {
      setTesting(false);
    }
  };

  return (
    <section className="card">
      <h2>Ollama (local model)</h2>
      <p className="muted">
        Run Stage-2 polish against a model served locally by Ollama — text never leaves your
        machine. Start Ollama (<code>ollama serve</code> or the desktop app), pull a model
        (<code>ollama pull llama3.2</code>), then test the connection here.
      </p>
      <label className="row">
        <span>Base URL (include /v1)</span>
        <input
          type="text"
          value={settings.ollama.base_url}
          placeholder="http://localhost:11434/v1"
          onChange={(e) => patchOllama(onPatch, settings, { base_url: e.target.value })}
        />
      </label>
      <label className="row">
        <span>Model (empty = server's loaded model)</span>
        <input
          type="text"
          value={settings.ollama.model}
          placeholder="e.g. llama3.2"
          onChange={(e) => patchOllama(onPatch, settings, { model: e.target.value })}
        />
      </label>
      {test && test.models.length > 0 && (
        <label className="row">
          <span>Discovered models</span>
          <select
            value={settings.ollama.model}
            onChange={(e) => patchOllama(onPatch, settings, { model: e.target.value })}
          >
            <option value="">Use the server's loaded model</option>
            {test.models.map((m) => (
              <option key={m} value={m}>
                {m}
              </option>
            ))}
          </select>
        </label>
      )}
      <ApiKeyPanel
        onSet={(token) => api.setOllamaToken(token).then(() => onChanged())}
        onClear={() => api.clearOllamaToken().then(() => onChanged())}
        stored={!!status?.ollama_token_stored}
        label="Bearer token (optional — only for a remote/headless server, stored in the OS keychain)"
      />
      <label className="row">
        <span>Cold-load wait (ms, 0 = default)</span>
        <input
          type="number"
          min={0}
          max={30000}
          value={settings.ollama.load_timeout_ms}
          onChange={(e) =>
            patchOllama(onPatch, settings, { load_timeout_ms: Number(e.target.value) })
          }
        />
      </label>
      <p className="muted">
        Ollama loads a model on demand; the next dictation can pay a one-time cold-load cost.
        Increase the wait in the General tab's <em>Wait for polish</em> field if the first
        dictation after a pause falls back to deterministic text.
      </p>
      <button className="btn" disabled={testing} onClick={() => void runTest()}>
        {testing ? 'Testing…' : 'Test connection'}
      </button>
      {test && (
        <p className={test.ok ? 'ok-text' : 'warn-text'}>
          {test.ok ? '✓ ' : '✗ '}
          {test.message}
        </p>
      )}
    </section>
  );
}

function patchOllama(
  onPatch: (p: Partial<Settings>) => Promise<void>,
  settings: Settings,
  partial: Partial<Settings['ollama']>,
) {
  return void onPatch({ ollama: { ...settings.ollama, ...partial } });
}

function CredentialPanel({
  value,
  onValueChange,
  stored,
  error,
  onSet,
  onClear,
  label,
}: {
  value: string;
  onValueChange: (v: string) => void;
  stored: boolean;
  error: string;
  onSet: (value: string) => Promise<void>;
  onClear: () => Promise<void>;
  label: string;
}) {
  const [localError, setLocalError] = useState('');
  return (
    <div className="credential">
      <label className="row">
        <span>{label}</span>
        <input
          type="password"
          value={value}
          onChange={(e) => onValueChange(e.target.value)}
          autoComplete="off"
        />
      </label>
      <div className="btn-row">
        <button
          className="btn"
          disabled={!value.trim()}
          onClick={() =>
            void onSet(value.trim())
              .then(() => {
                onValueChange('');
                setLocalError('');
              })
              .catch((e) => setLocalError(String(e)))
          }
        >
          Store
        </button>
        <button className="btn ghost" onClick={() => void onClear()}>
          Remove
        </button>
        <span className={stored ? 'badge ok' : 'badge warn'}>
          {stored ? 'stored' : 'not set'}
        </span>
      </div>
      {(error || localError) && <p className="warn-text">{error || localError}</p>}
    </div>
  );
}

function ApiKeyPanel({
  onSet,
  onClear,
  stored,
  label,
}: {
  onSet: (value: string) => Promise<void>;
  onClear: () => Promise<void>;
  stored: boolean;
  label: string;
}) {
  const [value, setValue] = useState('');
  return (
    <CredentialPanel
      value={value}
      onValueChange={setValue}
      stored={stored}
      error=""
      onSet={onSet}
      onClear={onClear}
      label={label}
    />
  );
}
