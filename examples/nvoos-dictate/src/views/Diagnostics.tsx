import { useCallback, useEffect, useRef, useState } from 'react';
import { api, type DiagnosticsInfo } from '../lib/ipc';

function badge(ok: boolean) {
  return ok ? 'badge ok' : 'badge warn';
}

/**
 * Live troubleshooting snapshot: hook health, the actual capture device with
 * a level meter, engine/model install state, credential presence, and the
 * measured levels of the most recent dictation attempt. Polls the core every
 * second so the meter moves while the user speaks into the mic.
 */
export default function DiagnosticsView() {
  const [info, setInfo] = useState<DiagnosticsInfo | null>(null);
  const [loadError, setLoadError] = useState('');
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  const refresh = useCallback(async () => {
    try {
      setInfo(await api.getDiagnostics());
      setLoadError('');
    } catch (e) {
      setLoadError(String(e));
    }
  }, []);

  useEffect(() => {
    void refresh();
    timer.current = setInterval(() => void refresh(), 1000);
    return () => {
      if (timer.current) clearInterval(timer.current);
    };
  }, [refresh]);

  const copyReport = () => {
    if (!info) return;
    void navigator.clipboard.writeText(JSON.stringify(info, null, 2));
  };

  if (!info) {
    return (
      <section className="card">
        <p className="muted">Loading diagnostics…</p>
        {loadError && <p className="muted">Failed: {loadError}</p>}
      </section>
    );
  }

  const levelPct = Math.min(100, Math.round((info.mic.live_rms / 0.2) * 100));
  const flat = info.mic.available && info.mic.live_rms < 0.0005;
  const last = info.last_dictation;

  return (
    <div className="stack">
      <section className="card">
        <h2>Microphone</h2>
        <ul className="kv">
          <li>
            <span>Capture device</span>
            <span className={badge(info.mic.available)}>
              {info.mic.device_name ?? 'unavailable'}
            </span>
          </li>
          <li>
            <span>Sample rate / channels</span>
            <span>
              {info.mic.sample_rate} Hz / {info.mic.channels}
            </span>
          </li>
          <li>
            <span>Buffered samples</span>
            <span>{info.mic.buffered_samples}</span>
          </li>
          <li>
            <span>Capture callbacks</span>
            <span className={badge(info.mic.capture_callbacks > 0)}>
              {info.mic.capture_callbacks > 0
                ? `${info.mic.capture_callbacks} delivered`
                : 'none — the stream is not delivering'}
            </span>
          </li>
          {info.mic.capture_callbacks > 0 && (
            <li>
              <span>Last callback</span>
              <span className={badge(info.mic.capture_stall_ms < 2000)}>
                {info.mic.capture_stall_ms < 2000
                  ? `${info.mic.capture_stall_ms} ms ago`
                  : `stalled ${Math.round(info.mic.capture_stall_ms / 1000)} s ago`}
              </span>
            </li>
          )}
          {info.mic.capture_errors > 0 && (
            <li>
              <span>Stream errors</span>
              <span className="badge warn">
                {info.mic.capture_errors} — {info.mic.capture_last_error ?? 'unknown'}
              </span>
            </li>
          )}
          <li>
            <span>Voice activity detection</span>
            <span>{info.mic.vad_kind}</span>
          </li>
        </ul>
        <div className="btn-row">
          <button
            className="btn ghost"
            onClick={() => void api.restartMicrophone().then(() => void refresh())}
          >
            Restart capture
          </button>
        </div>
        <p className="muted">Live input level (last ~250 ms) — speak to test:</p>
        <div className="meter" aria-label={`input level ${levelPct}%`}>
          <div className="meter-fill" style={{ width: `${levelPct}%` }} />
        </div>
        <p className="muted">
          RMS {info.mic.live_rms.toFixed(4)} · peak {info.mic.live_peak.toFixed(4)}.
          {flat
            ? ' The level is flat: the app is not hearing this microphone (muted, unplugged, or a different default device).'
            : ' The meter moving means the app is hearing the microphone — if dictation still fails, see Last dictation below.'}
        </p>
      </section>

      <section className="card">
        <h2>Push-to-talk hotkey</h2>
        <ul className="kv">
          <li>
            <span>PTT key</span>
            <span>{info.hotkey.ptt_key}</span>
          </li>
          <li>
            <span>PTT mode</span>
            <span>{info.hotkey.ptt_mode}</span>
          </li>
          <li>
            <span>Low-level listener</span>
            <span className={badge(info.hotkey.hook_installed)}>
              {info.hotkey.hook_installed ? 'running' : 'not running (plugin shortcut active)'}
            </span>
          </li>
          <li>
            <span>Listener heartbeat age</span>
            <span>{info.hotkey.hook_heartbeat_age_ms} ms</span>
          </li>
          <li>
            <span>Listener callback invocations</span>
            <span>{info.hotkey.hook_calls}</span>
          </li>
          <li>
            <span>Pending swallowed key</span>
            <span className={badge(!info.hotkey.swallow_pending)}>
              {info.hotkey.swallow_pending ? 'yes (awaiting release)' : 'none'}
            </span>
          </li>
        </ul>
      </section>

      <section className="card">
        <h2>Speech engine</h2>
        <ul className="kv">
          <li>
            <span>Configured engine</span>
            <span>
              {info.engine.configured} · {info.engine.model}
            </span>
          </li>
          <li>
            <span>Resident engine</span>
            <span className={badge(info.engine.loaded)}>
              {info.engine.loaded ? 'loaded' : 'not loaded'}
            </span>
          </li>
          <li>
            <span>Compiled features</span>
            <span>{info.engine.features}</span>
          </li>
        </ul>
        <h3>Model catalogue</h3>
        <ul className="kv">
          {info.engine.models.map((m) => (
            <li key={`${m.engine}/${m.id}`}>
              <span>
                {m.engine}/{m.id}
              </span>
              <span className={badge(m.installed)}>
                {m.installed ? 'installed' : `not installed (${m.size_mb} MB)`}
              </span>
            </li>
          ))}
        </ul>
        <p className="muted">Models directory: {info.models_dir}</p>
      </section>

      <section className="card">
        <h2>Object detection (vision)</h2>
        {info.vision.available ? (
          <ul className="kv">
            <li>
              <span>Compiled / enabled</span>
              <span className={badge(info.vision.enabled)}>
                {info.vision.available
                  ? info.vision.enabled
                    ? 'enabled (opt-in)'
                    : 'compiled, disabled'
                  : 'not compiled'}
              </span>
            </li>
            <li>
              <span>Detector model</span>
              <span className={badge(info.vision.detector_installed)}>
                {info.vision.detector}
                {info.vision.detector_installed ? '' : ' (not downloaded)'}
              </span>
            </li>
            <li>
              <span>Loop</span>
              <span className={badge(info.vision.running)}>
                {info.vision.running ? 'running' : 'stopped'}
              </span>
            </li>
            {info.vision.running && (
              <>
                <li>
                  <span>Frames processed</span>
                  <span>{info.vision.frames}</span>
                </li>
                <li>
                  <span>Last inference</span>
                  <span>{info.vision.last_infer_ms} ms</span>
                </li>
                <li>
                  <span>Active tracks</span>
                  <span>{info.vision.active_tracks}</span>
                </li>
                <li>
                  <span>Total detections / track events</span>
                  <span>
                    {info.vision.total_detections} / {info.vision.track_events}
                  </span>
                </li>
              </>
            )}
            {info.vision.errors > 0 && (
              <li>
                <span>Errors</span>
                <span className="badge warn">
                  {info.vision.errors} — {info.vision.last_error ?? 'unknown'}
                </span>
              </li>
            )}
            <li>
              <span>Thresholds</span>
              <span>
                conf ≥ {info.vision.confidence_threshold} · IoU {info.vision.iou_threshold}
              </span>
            </li>
            <li>
              <span>Label polish (VLM)</span>
              <span className={badge(info.vision.polish_enabled)}>
                {info.vision.polish_enabled
                  ? `${info.vision.polish_provider} · ${info.vision.polish_model}`
                  : 'off (COCO labels only)'}
              </span>
            </li>
            {info.vision.polish_enabled && (
              <li>
                <span>Polish calls / results</span>
                <span>
                  {info.vision.polish_calls} / {info.vision.polish_results}
                </span>
              </li>
            )}
          </ul>
        ) : (
          <p className="muted">Vision detection is not compiled into this build.</p>
        )}
        {info.vision.available && (
          <>
            <h3>Detector models</h3>
            <ul className="kv">
              {info.vision.models.map((m) => (
                <li key={m.id}>
                  <span>{m.id}</span>
                  <span className={badge(m.installed)}>
                    {m.installed ? 'installed' : `not installed (${m.size_mb} MB)`}
                  </span>
                </li>
              ))}
            </ul>
            <div className="btn-row">
              {info.vision.models
                .filter((m) => !m.installed)
                .map((m) => (
                  <button
                    key={m.id}
                    className="btn ghost"
                    onClick={() => void api.downloadVisionModel(m.id).then(() => void refresh())}
                  >
                    Download {m.id}
                  </button>
                ))}
            </div>
            <p className="muted">
              Detection runs entirely on-device via ONNX Runtime — camera pixels never leave your
              computer, and no faces or personal identities are stored.
            </p>
          </>
        )}
      </section>

      <section className="card">
        <h2>Last dictation</h2>
        {last ? (
          <>
            <ul className="kv">
              <li>
                <span>Outcome</span>
                <span className={badge(last.ok)}>{last.ok ? `pasted in ${last.e2e_ms ?? '?'} ms` : 'failed'}</span>
              </li>
              <li>
                <span>Window / utterance</span>
                <span>
                  {last.window_ms} ms / {last.utterance_ms} ms
                </span>
              </li>
              <li>
                <span>Captured level (RMS / peak)</span>
                <span>
                  {last.rms.toFixed(4)} / {last.peak.toFixed(4)}
                </span>
              </li>
              <li>
                <span>VAD gate / sample rate</span>
                <span>
                  {last.vad_threshold.toFixed(4)} / {last.sample_rate} Hz
                </span>
              </li>
              <li>
                <span>Engine / model</span>
                <span>
                  {last.engine} · {last.model}
                </span>
              </li>
              {last.polish_total_ms != null && (
                <li>
                  <span>Polish latency (TTFT / total)</span>
                  <span>
                    {last.polish_ttft_ms ?? '?'} ms / {last.polish_total_ms} ms
                  </span>
                </li>
              )}
            </ul>
            {last.error && <p className="muted">Error: {last.error}</p>}
            {!last.ok && last.peak < 0.004 && (
              <p className="muted">
                The peak is near zero — the app captured silence, not speech. Check the device
                name and the live meter above.
              </p>
            )}
          </>
        ) : (
          <p className="muted">No dictation attempt recorded yet.</p>
        )}
      </section>

      <section className="card">
        <h2>Credentials & config</h2>
        <ul className="kv">
          <li>
            <span>NV oOS site</span>
            <span className={badge(info.credentials.nvoos_configured)}>
              {info.credentials.nvoos_configured ? 'configured' : 'not configured'}
            </span>
          </li>
          <li>
            <span>NV oOS credential</span>
            <span className={badge(info.credentials.nvoos_credential_stored)}>
              {info.credentials.nvoos_credential_stored ? 'stored (OS keychain)' : 'not set'}
            </span>
          </li>
          <li>
            <span>OpenAI-compatible key</span>
            <span className={badge(info.credentials.openai_key_stored)}>
              {info.credentials.openai_key_stored ? 'stored (OS keychain)' : 'not set'}
            </span>
          </li>
          <li>
            <span>LM Studio token</span>
            <span className={badge(info.credentials.lm_studio_token_stored)}>
              {info.credentials.lm_studio_token_stored ? 'stored (OS keychain)' : 'not set'}
            </span>
          </li>
          <li>
            <span>Ollama token</span>
            <span className={badge(info.credentials.ollama_token_stored)}>
              {info.credentials.ollama_token_stored ? 'stored (OS keychain)' : 'not set'}
            </span>
          </li>
          <li>
            <span>WP application password</span>
            <span className={badge(info.credentials.wp_credentials_stored)}>
              {info.credentials.wp_credentials_stored ? 'stored (OS keychain)' : 'not set'}
            </span>
          </li>
          <li>
            <span>Config file</span>
            <span>{info.config_path}</span>
          </li>
          <li>
            <span>Build</span>
            <span>
              v{info.version} · {info.os}
            </span>
          </li>
        </ul>
      </section>

      <div className="btn-row">
        <button className="btn" onClick={copyReport}>
          Copy report (JSON)
        </button>
        <button className="btn ghost" onClick={() => void refresh()}>
          Refresh
        </button>
      </div>
    </div>
  );
}
