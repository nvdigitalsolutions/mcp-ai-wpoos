import { useCallback, useEffect, useRef, useState } from 'react';
import {
  api,
  isMac,
  onModelProgress,
  type ConnectionTest,
  type ModelSpec,
  type PermissionInfo,
  type Settings,
} from '../lib/ipc';

interface Props {
  onChanged: () => void;
}

type SettingsTab = 'general' | 'engine' | 'nvoos' | 'openai' | 'privacy' | 'permissions';

export default function SettingsView({ onChanged }: Props) {
  const [tab, setTab] = useState<SettingsTab>('general');
  const [settings, setSettings] = useState<Settings | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [loadFailed, setLoadFailed] = useState(false);
  const [devices, setDevices] = useState<string[]>([]);

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
            </select>
          </label>
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

      {tab === 'nvoos' && <NvoosTab settings={settings} onPatch={patch} />}

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
            onSet={(key) => api.setOpenaiApiKey(key)}
            onClear={api.clearOpenaiApiKey}
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
}: {
  settings: Settings;
  onPatch: (p: Partial<Settings>) => Promise<void>;
}) {
  const [test, setTest] = useState<ConnectionTest | null>(null);
  const [wpUser, setWpUser] = useState('');
  const [wpPass, setWpPass] = useState('');

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
        onSet={async (cred) => {
          await api.setNvoosCredential(settings.nvoos.site_url, cred);
          setTest(null);
        }}
        onClear={async () => {
          await api.clearNvoosCredential();
          setTest(null);
        }}
        label="Assistant credential (cred_XXXXX.SECRET — stored in the OS keychain)"
      />
      <button
        className="btn"
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
              void api.setWpAppPassword(wpUser.trim(), wpPass.trim()).then(() => {
                setWpUser('');
                setWpPass('');
              })
            }
          >
            Store
          </button>
          <button className="btn ghost" onClick={() => void api.clearWpAppPassword()}>
            Remove
          </button>
        </div>
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

function CredentialPanel({
  onSet,
  onClear,
  label,
}: {
  onSet: (value: string) => Promise<void>;
  onClear: () => Promise<void>;
  label: string;
}) {
  const [value, setValue] = useState('');
  return (
    <div className="credential">
      <label className="row">
        <span>{label}</span>
        <input
          type="password"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          autoComplete="off"
        />
      </label>
      <div className="btn-row">
        <button
          className="btn"
          disabled={!value.trim()}
          onClick={() => void onSet(value.trim()).then(() => setValue(''))}
        >
          Store
        </button>
        <button className="btn ghost" onClick={() => void onClear()}>
          Remove
        </button>
      </div>
    </div>
  );
}

function ApiKeyPanel({
  onSet,
  onClear,
  label,
}: {
  onSet: (value: string) => Promise<void>;
  onClear: () => Promise<void>;
  label: string;
}) {
  return <CredentialPanel onSet={onSet} onClear={onClear} label={label} />;
}
