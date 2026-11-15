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
          <li>
            <span>Capture mode</span>
            <span>
              {info.mic.capture_mode}
              {info.mic.paused ? ' · paused' : ' · running'}
            </span>
          </li>
          {info.mic.capture_mode === 'on_demand' && (
            <li>
              <span>Last resume</span>
              <span>{info.mic.last_resume_ms} ms</span>
            </li>
          )}
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
        <h2>Clipboard</h2>
        {info.paste ? (
          <>
            <ul className="kv">
              <li>
                <span>Backend</span>
                <span>{info.paste.backend}</span>
              </li>
              <li>
                <span>Previous contents</span>
                <span>
                  {info.paste.snapshot_kind === 'formats'
                    ? 'all formats'
                    : info.paste.snapshot_kind}
                </span>
              </li>
              {info.paste.partial_formats > 0 && (
                <li>
                  <span>Not copyable</span>
                  <span className="badge warn">
                    {info.paste.partial_formats} format
                    {info.paste.partial_formats === 1 ? '' : 's'}
                  </span>
                </li>
              )}
              <li>
                <span>Restored</span>
                <span className={badge(info.paste.restored)}>
                  {info.paste.restored
                    ? 'yes'
                    : `no${info.paste.restore_skipped ? ` (${info.paste.restore_skipped})` : ''}`}
                </span>
              </li>
              <li>
                <span>Paste time</span>
                <span>{info.paste.elapsed_ms} ms</span>
              </li>
            </ul>
            <p className="muted small">
              Last attempt {info.paste.at} UTC. Clipboard contents are never recorded here —
              only what kind of content was present and whether it was put back.
            </p>
          </>
        ) : (
          <p className="muted">No dictation has been pasted yet.</p>
        )}
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
        <h2>Document OCR</h2>
        {info.document.available ? (
          <ul className="diag-list">
            <li>
              <span>Documents / pages</span>
              <span>
                {info.document.documents} / {info.document.pages}
              </span>
            </li>
            <li>
              <span>Pages by provenance</span>
              <span>
                text {info.document.text_pages} · ocr {info.document.ocr_pages} · model{' '}
                {info.document.model_pages}
              </span>
            </li>
            <li>
              <span>Model tier (DeepSeek-OCR)</span>
              <span className={badge(info.document.model_enabled)}>
                {info.document.model_enabled
                  ? `${info.document.model_provider} · ${info.document.model_model}`
                  : 'off'}
              </span>
            </li>
            <li>
              <span>Last document</span>
              <span>{info.document.last_ms} ms</span>
            </li>
          </ul>
        ) : (
          <p className="muted">not compiled into this build</p>
        )}
      </section>

      <section className="card">
        <h2>Memory</h2>
        {info.memory.available ? (
          <ul className="kv">
            <li>
              <span>Compiled / enabled</span>
              <span className={badge(info.memory.enabled)}>
                {info.memory.enabled ? 'enabled' : 'compiled, disabled'}
              </span>
            </li>
            <li>
              <span>Embedder</span>
              <span className={badge(info.memory.model_installed)}>
                {info.memory.embedder}
                {info.memory.model_installed ? '' : ' — not downloaded'}
              </span>
            </li>
            <li>
              <span>Chunks (indexed / total)</span>
              <span>
                {info.memory.chunks_indexed} / {info.memory.chunks_total}
                {info.memory.chunks_pending > 0 ? ` · ${info.memory.chunks_pending} pending` : ''}
              </span>
            </li>
            <li>
              <span>Vector width / blend weight</span>
              <span>
                {info.memory.dim} · {info.memory.semantic_weight.toFixed(2)}
              </span>
            </li>
            <li>
              <span>Last worker batch</span>
              <span>{info.memory.last_batch_ms} ms</span>
            </li>
            {info.memory.errors > 0 && (
              <li>
                <span>Errors</span>
                <span className="badge warn">{info.memory.errors}</span>
              </li>
            )}
          </ul>
        ) : (
          <p className="muted">not compiled into this build (needs the `memory` feature)</p>
        )}
        {info.memory.available && info.memory.recent_ops.length > 0 && (
          <>
            <h3>Destructive operation log</h3>
            <ul className="kv">
              {info.memory.recent_ops.map((op, i) => (
                <li key={i}>
                  <span>{op.created_at}</span>
                  <span>
                    {op.action} — {op.detail}
                  </span>
                </li>
              ))}
            </ul>
          </>
        )}
      </section>

      <section className="card">
        <h2>Screen OCR (snippet)</h2>
        {info.snippet.available ? (
          <ul className="kv">
            <li>
              <span>Compiled / enabled</span>
              <span className={badge(info.snippet.enabled)}>
                {info.snippet.enabled ? 'enabled' : 'compiled, disabled'}
              </span>
            </li>
            <li>
              <span>Hotkey / engine</span>
              <span>
                {info.snippet.hotkey || '(disabled)'} · {info.snippet.engine}
                {info.snippet.language ? ` · ${info.snippet.language}` : ''}
              </span>
            </li>
            <li>
              <span>State</span>
              <span className={badge(info.snippet.phase === 'idle')}>
                {info.snippet.phase}
              </span>
            </li>
            <li>
              <span>Captures / recognitions</span>
              <span>
                {info.snippet.captures} / {info.snippet.recognitions}
              </span>
            </li>
            <li>
              <span>Last recognition</span>
              <span>{info.snippet.last_ocr_ms} ms</span>
            </li>
            {info.snippet.ocr_languages.length > 0 && (
              <li>
                <span>Windows OCR languages</span>
                <span>{info.snippet.ocr_languages.join(', ')}</span>
              </li>
            )}
            {info.snippet.errors > 0 && (
              <li>
                <span>Errors</span>
                <span className="badge warn">
                  {info.snippet.errors} — {info.snippet.last_error ?? 'unknown'}
                </span>
              </li>
            )}
          </ul>
        ) : (
          <p className="muted">Screen OCR is not compiled into this build.</p>
        )}
        {info.snippet.available && info.snippet.onnx_available && (
          <>
            <h3>RapidOCR language packs</h3>
            <ul className="kv">
              {info.snippet.models.map((m) => (
                <li key={m.id}>
                  <span>{m.label}</span>
                  <span className={badge(m.installed)}>
                    {m.installed ? 'installed' : `not installed (${m.size_mb} MB)`}
                  </span>
                </li>
              ))}
            </ul>
            <p className="muted">
              Snippet OCR runs entirely on-device — the frozen frame never leaves memory and is
              never written to disk.
            </p>
          </>
        )}
      </section>

      <section className="card">
        <h2>Browser agent</h2>
        {info.agent.available ? (
          <ul className="kv">
            <li>
              <span>Compiled / enabled</span>
              <span className={badge(info.agent.enabled)}>
                {info.agent.enabled ? 'enabled (opt-in)' : 'compiled, disabled'}
              </span>
            </li>
            <li>
              <span>Brain provider</span>
              <span>
                {info.agent.provider || 'ollama'}
                {info.agent.model ? ` · ${info.agent.model}` : ''}
              </span>
            </li>
            <li>
              <span>Max steps / confirmation</span>
              <span>
                {info.agent.max_steps} steps · destructive actions{' '}
                {info.agent.confirmation_required ? 'confirmed' : 'auto-approved'}
              </span>
            </li>
            <li>
              <span>Run</span>
              <span className={badge(!info.agent.running)}>
                {info.agent.running ? 'running…' : 'idle'}
              </span>
            </li>
            {info.agent.last_error && (
              <li>
                <span>Last error</span>
                <span className="badge warn">{info.agent.last_error}</span>
              </li>
            )}
          </ul>
        ) : (
          <p className="muted">
            Browser agent is not compiled into this build (needs the `agent` feature).
          </p>
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
