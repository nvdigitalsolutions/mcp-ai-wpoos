import { useCallback, useEffect, useState } from 'react';
import { api, type ConnectionTest, type ModelSpec, type Settings } from '../lib/ipc';

interface Props {
  onChanged: () => void;
}

type SettingsTab = 'general' | 'engine' | 'nvoos' | 'openai' | 'privacy';

export default function SettingsView({ onChanged }: Props) {
  const [tab, setTab] = useState<SettingsTab>('general');
  const [settings, setSettings] = useState<Settings | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');

  useEffect(() => {
    void api.getSettings().then(setSettings);
  }, []);

  const save = useCallback(
    async (next: Settings) => {
      setBusy(true);
      setMessage('');
      try {
        setSettings(await api.updateSettings(next));
        setMessage('Saved.');
        onChanged();
      } catch (e) {
        setMessage(`Save failed: ${e}`);
      } finally {
        setBusy(false);
      }
    },
    [onChanged],
  );

  if (!settings) {
    return <p className="muted">Loading settings…</p>;
  }

  const patch = (partial: Partial<Settings>) => save({ ...settings, ...partial });

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

      {busy && <p className="muted">Saving…</p>}
    </div>
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
  const [progress, setProgress] = useState<Record<string, number>>({});

  useEffect(() => {
    void api.getModels().then(setModels);
  }, []);

  const download = async (engine: string, model: string) => {
    setProgress((p) => ({ ...p, [`${engine}/${model}`]: 0 }));
    try {
      await api.downloadModel(engine, model);
      setProgress((p) => ({ ...p, [`${engine}/${model}`]: 1 }));
    } catch (e) {
      console.error(e);
    }
  };

  const entries = models.filter(
    (m) => m.engine === settings.engine.engine || m.engine === 'vad',
  );

  return (
    <section className="card">
      <h2>Speech engine</h2>
      <label className="row">
        <span>Engine</span>
        <select
          value={settings.engine.engine}
          onChange={(e) => patchEngine(onPatch, settings, { engine: e.target.value })}
        >
          <option value="mock">Mock (no model — pipeline testing only)</option>
          <option value="parakeet">Parakeet TDT (fastest English, ~600 MB, local)</option>
          <option value="whisper">Whisper (multilingual, local)</option>
          <option value="moonshine">Moonshine v2 (streaming — experimental, local)</option>
          <option value="embedded">Embedded (plugin) — remote STT via your NV oOS site</option>
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
        <input
          type="text"
          value={settings.engine.model}
          onChange={(e) => patchEngine(onPatch, settings, { model: e.target.value })}
        />
      </label>
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
            <button className="btn" onClick={() => void download(m.engine, m.id)}>
              {progress[`${m.engine}/${m.id}`] === undefined
                ? 'Download'
                : progress[`${m.engine}/${m.id}`] < 1
                  ? `Downloading ${Math.round(progress[`${m.engine}/${m.id}`] * 100)}%`
                  : 'Downloaded'}
            </button>
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
