import { useEffect, useState } from 'react';
import {
  api,
  onMediaProgress,
  type Detection,
  type DictateEvent,
  type MediaTranscription,
  type PreviewFrame,
  type SpaCheck,
  type StatusInfo,
} from '../lib/ipc';

interface Props {
  status: StatusInfo | null;
  dictate: DictateEvent;
  onRefresh: () => void;
}

function badge(ok: boolean) {
  return ok ? 'badge ok' : 'badge warn';
}

/** Media transcription panel: pick an audio/video file, transcribe, summarize. */
function MediaPanel() {
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState<string | null>(null);
  const [result, setResult] = useState<MediaTranscription | null>(null);
  const [summary, setSummary] = useState<string | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    const unlisten = onMediaProgress((p) => {
      const pct = Math.round(p.fraction * 100);
      if (p.phase === 'done') setProgress(null);
      else setProgress(p.phase === 'decode' ? `Decoding…` : `Transcribing… ${pct}%`);
    });
    return () => void unlisten.then((fn) => fn());
  }, []);

  const pick = async () => {
    setError('');
    setSummary(null);
    const path = await api.pickMediaFile();
    if (!path) return;
    setBusy(true);
    setResult(null);
    try {
      const res = await api.transcribeMedia(path);
      setResult(res);
    } catch (e) {
      setError(String(e));
    } finally {
      setBusy(false);
    }
  };

  const summarize = async () => {
    if (!result) return;
    setError('');
    setSummary(null);
    setBusy(true);
    try {
      setSummary(await api.summarizeMedia(result.text));
    } catch (e) {
      setError(String(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="card">
      <h2>Transcribe media</h2>
      <p className="muted">
        Transcribe an audio (MP3, M4A, WAV, FLAC, OGG) or video (MP4, MOV, MKV, WebM) file locally,
        then optionally summarize it with your configured AI provider. Video files are handled by
        demuxing their audio track on-device (no FFmpeg).
      </p>
      <div className="btn-row">
        <button className="btn" disabled={busy} onClick={pick}>
          {busy ? 'Working…' : 'Choose file…'}
        </button>
        {result && !busy && (
          <button className="btn ghost" onClick={summarize} disabled={!!summary}>
            {summary ? 'Summarized' : 'Summarize'}
          </button>
        )}
      </div>
      {progress && <p className="muted">{progress}</p>}
      {error && <p className="warn-text">{error}</p>}
      {result && (
        <>
          <p className="muted small">
            {result.container === 'video' ? '🎬 Video' : '🎵 Audio'} ·{' '}
            {(result.duration_ms / 1000).toFixed(1)} s
          </p>
          <pre className="preview">{result.text}</pre>
        </>
      )}
      {summary && (
        <>
          <h3>Summary</h3>
          <pre className="preview">{summary}</pre>
        </>
      )}
    </section>
  );
}

/** Live webcam preview panel (Proposal 073) with an opt-in local object
 *  detection + tracking overlay (Proposal 075): detects objects on-device,
 *  draws labelled boxes on a canvas, and pins a track on click. */
function CameraPanel() {
  const [available, setAvailable] = useState<boolean | null>(null);
  const [visionAvailable, setVisionAvailable] = useState<boolean>(false);
  const [running, setRunning] = useState(false);
  const [frame, setFrame] = useState<PreviewFrame | null>(null);
  const [visionOn, setVisionOn] = useState(false);
  const [detections, setDetections] = useState<Detection[]>([]);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    void api.cameraAvailable().then((v) => {
      if (!cancelled) setAvailable(v);
    });
    void api.visionAvailable().then((v) => {
      if (!cancelled) setVisionAvailable(v);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  // Poll the preview while running (low rate; the Rust core holds a ring).
  useEffect(() => {
    if (!running) return;
    const id = setInterval(() => {
      void api.getPreviewFrame().then((f) => f && setFrame(f)).catch(() => {});
    }, 200);
    return () => clearInterval(id);
  }, [running]);

  // Poll detections while vision is on (~10 Hz is plenty for the overlay).
  useEffect(() => {
    if (!visionOn) return;
    const id = setInterval(() => {
      void api.getDetections().then(setDetections).catch(() => {});
    }, 100);
    return () => clearInterval(id);
  }, [visionOn]);

  const start = async () => {
    setError('');
    try {
      await api.startCamera();
      setRunning(true);
    } catch (e) {
      setError(String(e));
    }
  };

  const stop = async () => {
    if (visionOn) {
      try {
        await api.stopVision();
      } catch {
        /* already stopped */
      }
      setVisionOn(false);
    }
    try {
      await api.stopCamera();
    } catch (e) {
      setError(String(e));
    }
    setRunning(false);
    setFrame(null);
    setDetections([]);
  };

  const toggleVision = async () => {
    setError('');
    if (visionOn) {
      try {
        await api.stopVision();
      } catch (e) {
        setError(String(e));
      }
      setVisionOn(false);
      setDetections([]);
      return;
    }
    try {
      await api.startVision();
      setVisionOn(true);
    } catch (e) {
      setError(String(e));
    }
  };

  const selectTrack = (id: number | null) => {
    if (id == null) return;
    void api.visionSelectTrack(id).catch(() => {});
  };

  const dataUri = (() => {
    if (!frame || frame.width === 0 || frame.height === 0) return null;
    const canvas = document.createElement('canvas');
    canvas.width = frame.width;
    canvas.height = frame.height;
    const ctx = canvas.getContext('2d');
    if (!ctx) return null;
    const imgData = ctx.createImageData(frame.width, frame.height);
    const px = frame.width * frame.height;
    for (let p = 0; p < px; p++) {
      imgData.data[p * 4] = frame.rgb[p * 3] ?? 0;
      imgData.data[p * 4 + 1] = frame.rgb[p * 3 + 1] ?? 0;
      imgData.data[p * 4 + 2] = frame.rgb[p * 3 + 2] ?? 0;
      imgData.data[p * 4 + 3] = 255;
    }
    ctx.putImageData(imgData, 0, 0);
    return canvas.toDataURL('image/png');
  })();

  const drawOverlay = (canvas: HTMLCanvasElement | null) => {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    // The overlay is sized to the displayed preview; boxes are in frame pixel
    // space, so scale by the ratio between frame size and canvas size.
    const cw = canvas.clientWidth || canvas.width;
    const ch = canvas.clientHeight || canvas.height;
    ctx.clearRect(0, 0, cw, ch);
    if (!frame || frame.width === 0) return;
    const sx = cw / frame.width;
    const sy = ch / frame.height;
    for (const d of detections) {
      const x = d.x * sx;
      const y = d.y * sy;
      const w = d.w * sx;
      const h = d.h * sy;
      ctx.strokeStyle = '#22d3ee';
      ctx.lineWidth = 2;
      ctx.strokeRect(x, y, w, h);
      const label = `${d.id != null ? `#${d.id} ` : ''}${d.label} ${(d.confidence * 100).toFixed(0)}%${d.source === 'vlm' ? ' · VLM' : ''}`;
      ctx.fillStyle = '#22d3ee';
      ctx.font = '12px system-ui, sans-serif';
      const th = 16;
      ctx.fillRect(x, Math.max(0, y - th), ctx.measureText(label).width + 8, th);
      ctx.fillStyle = '#0b1120';
      ctx.fillText(label, x + 4, Math.max(th - 3, y - 3));
    }
  };

  if (available === false) {
    return null; // camera feature not compiled in — hide the panel
  }

  return (
    <section className="card">
      <h2>Camera preview</h2>
      <p className="muted">
        Live webcam preview (no recording). Camera audio transcription uses your webcam's microphone,
        selectable under Settings → Audio.
      </p>
      <div className="btn-row">
        {!running ? (
          <button className="btn" onClick={start}>
            Start camera
          </button>
        ) : (
          <button className="btn ghost danger" onClick={stop}>
            Stop camera
          </button>
        )}
        {running && visionAvailable && (
          <button
            className={visionOn ? 'btn' : 'btn ghost'}
            onClick={toggleVision}
            title="Run local object detection on the preview (opt-in)"
          >
            {visionOn ? 'Stop detection' : 'Detect objects'}
          </button>
        )}
      </div>
      {error && <p className="warn-text">{error}</p>}
      {visionOn && (
        <p className="muted small">
          Tracking {detections.length} object{detections.length === 1 ? '' : 's'} — click a box to
          pin it (keeps its id through brief misses).
        </p>
      )}
      {running && (
        <p className="muted small">Previewing{frame ? ` · ${frame.width}×${frame.height}` : ''}</p>
      )}
      {running && dataUri && (
        <div className="camera-frame" style={{ position: 'relative', display: 'inline-block' }}>
          <img src={dataUri} alt="camera preview" className="camera-preview" />
          {visionOn && (
            <canvas
              className="vision-overlay"
              ref={drawOverlay}
              style={{
                position: 'absolute',
                inset: 0,
                width: '100%',
                height: '100%',
                pointerEvents: detections.length ? 'auto' : 'none',
              }}
              onClick={(e) => {
                if (!frame) return;
                const rect = e.currentTarget.getBoundingClientRect();
                const px = ((e.clientX - rect.left) / rect.width) * frame.width;
                const py = ((e.clientY - rect.top) / rect.height) * frame.height;
                // Pick the smallest box containing the click point.
                let best: Detection | null = null;
                for (const d of detections) {
                  if (
                    px >= d.x &&
                    px <= d.x + d.w &&
                    py >= d.y &&
                    py <= d.y + d.h &&
                    (!best || d.w * d.h < best.w * best.h)
                  ) {
                    best = d;
                  }
                }
                selectTrack(best?.id ?? null);
              }}
            />
          )}
        </div>
      )}
    </section>
  );
}

/** Browser-agent panel: dictate/type an instruction, a local model drives a
 *  real browser to carry it out. Gated on the `agent` build feature. */
function BrowserAgentPanel() {
  const [available, setAvailable] = useState<boolean | null>(null);
  const [instruction, setInstruction] = useState('');
  const [busy, setBusy] = useState(false);
  const [snap, setSnap] = useState<{
    running: boolean;
    summary: string | null;
    completed: boolean | null;
    last_error: string | null;
  }>({ running: false, summary: null, completed: null, last_error: null });
  const [pending, setPending] = useState<{ description: string } | null>(null);

  useEffect(() => {
    let cancelled = false;
    void api.agentAvailable().then((v) => {
      if (!cancelled) setAvailable(v);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  // Poll the agent snapshot + any pending approval while a run is in flight.
  useEffect(() => {
    if (!snap.running) return;
    const id = setInterval(() => {
      void api
        .agentStatus()
        .then((s) =>
          setSnap({
            running: s.running,
            summary: s.last?.summary ?? null,
            completed: s.last?.completed ?? null,
            last_error: s.last_error,
          }),
        )
        .catch(() => {});
      void api
        .agentPending()
        .then((p) => setPending(p ? { description: p.description } : null))
        .catch(() => {});
    }, 300);
    return () => clearInterval(id);
  }, [snap.running]);

  const run = async () => {
    const text = instruction.trim();
    if (!text) return;
    setBusy(true);
    setSnap({ running: true, summary: null, completed: null, last_error: null });
    setPending(null);
    try {
      await api.agentRun(text);
    } catch (e) {
      setSnap({ running: false, summary: null, completed: null, last_error: String(e) });
    } finally {
      setBusy(false);
    }
  };

  const respond = async (approve: boolean) => {
    setPending(null);
    await api.agentRespond(approve).catch(() => {});
  };

  if (available === false) {
    return null; // agent feature not compiled in — hide the panel
  }

  return (
    <section className="card">
      <h2>Browser agent</h2>
      <p className="muted">
        Type an instruction (e.g. &ldquo;open example.com and click the login button&rdquo;) and a
        local model will drive a browser to carry it out. Destructive actions pause for
        confirmation when enabled in Settings.
      </p>
      <div className="btn-row">
        <input
          className="grow"
          type="text"
          placeholder="What should the browser do?"
          value={instruction}
          onChange={(e) => setInstruction(e.target.value)}
        />
        <button className="btn" disabled={busy || snap.running} onClick={run}>
          {snap.running ? 'Running…' : 'Run agent'}
        </button>
      </div>
      {snap.running && <p className="muted">Agent is working…</p>}
      {snap.running && (
        <button
          className="btn ghost danger"
          onClick={() => void api.agentStop().catch(() => {})}
        >
          Stop
        </button>
      )}
      {pending && (
        <div className="card warn">
          <p>
            <strong>Confirm action:</strong> {pending.description}
          </p>
          <div className="btn-row">
            <button className="btn" onClick={() => void respond(true)}>
              Approve
            </button>
            <button className="btn ghost danger" onClick={() => void respond(false)}>
              Deny
            </button>
          </div>
        </div>
      )}
      {snap.last_error && <p className="warn-text">{snap.last_error}</p>}
      {snap.summary && (
        <>
          <p className="muted small">
            {snap.completed ? 'Completed:' : 'Stopped:'}
          </p>
          <pre className="preview">{snap.summary}</pre>
        </>
      )}
    </section>
  );
}

export default function Home({ status, dictate, onRefresh }: Props) {
  const [spaChecking, setSpaChecking] = useState(false);
  const [spaCheck, setSpaCheck] = useState<SpaCheck | null>(null);

  const checkSpa = async () => {
    const url = status?.spa_url ?? '';
    if (!url.trim() || spaChecking) {
      return;
    }
    setSpaChecking(true);
    setSpaCheck(null);
    try {
      setSpaCheck(await api.checkSpa(url));
    } catch (e) {
      setSpaCheck({ ok: false, status: null, message: String(e) });
    } finally {
      setSpaChecking(false);
    }
  };

  return (
    <div className="stack">
      <section className="card">
        <h2>Dictation</h2>
        <p className="muted">
          {status?.ptt_mode === 'toggle' ? (
            <>
              Press <strong>{status?.ptt_combo ?? 'the PTT shortcut'}</strong> to start, speak,
              press again to stop — cleaned text pastes into the focused app. Hold{' '}
              <strong>Shift</strong> on stop for raw passthrough.
            </>
          ) : (
            <>
              Hold <strong>{status?.ptt_key ?? 'RAlt'}</strong>, speak, release — cleaned text
              pastes into the focused app. Hold <strong>Shift</strong> on release for raw
              passthrough. Double-tap for hands-free mode, <strong>Esc</strong> cancels.
            </>
          )}
        </p>
        <div className="dictate-state" data-state={dictate.state} role="status" aria-live="polite">
          {dictate.state === 'idle' && 'Idle — hold the PTT key to dictate'}
          {dictate.state === 'recording' &&
            `Listening${dictate.hint ? ` — ${dictate.hint}` : ''}… release to transcribe`}
          {dictate.state === 'processing' && 'Transcribing…'}
          {dictate.state === 'done' && (
            <span className="done">
              Pasted in {dictate.e2e_ms} ms ({dictate.provider})
            </span>
          )}
          {dictate.state === 'error' && `Error: ${dictate.message}`}
          {dictate.state === 'cancelled' && 'Cancelled'}
        </div>
        {dictate.state === 'done' && <pre className="preview">{dictate.text}</pre>}
      </section>

      <MediaPanel />
      <CameraPanel />
      {status?.agent_available && <BrowserAgentPanel />}

      <section className="card">
        <h2>System</h2>
        <ul className="kv">
          <li>
            <span>Speech engine</span>
            <span className={badge(!!status?.engine)}>
              {status?.engine ?? 'none'} {status?.model ? `· ${status.model}` : ''}
            </span>
          </li>
          <li>
            <span>Polish model</span>
            <span className={badge(!!(status?.polish && !status.polish.startsWith('off')))}>
              {status?.polish || 'off (deterministic only)'}
            </span>
          </li>
          <li>
            <span>Assistant (Pro SPA)</span>
            <span className="row">
              <span className={badge(!!status?.spa_configured)}>
                {status?.spa_configured ? status?.spa_url || 'configured' : 'not configured'}
              </span>
              {status?.spa_configured && (
                <button
                  className="btn ghost"
                  disabled={spaChecking}
                  onClick={() => void checkSpa()}
                  title="Probe whether the configured SPA origin answers"
                >
                  {spaChecking ? 'Checking…' : 'Check'}
                </button>
              )}
            </span>
          </li>
          {spaCheck && (
            <li>
              <span>SPA reachability</span>
              <span className={badge(spaCheck.ok)}>{spaCheck.message}</span>
            </li>
          )}
          <li>
            <span>Microphone</span>
            <span className={badge(!!status?.mic_available)}>
              {status?.mic_available ? 'available' : 'unavailable'}
            </span>
          </li>
          <li>
            <span>System audio (meetings)</span>
            <span className={badge(!!status?.system_audio_available)}>
              {status?.system_audio_available ? 'available' : 'unsupported here'}
            </span>
          </li>
          {status?.vision_available && (
            <li>
              <span>Vision detection</span>
              <span className={badge(!!status?.vision_running)}>
                {status?.vision_running
                  ? `running · ${status?.vision_detector ?? 'detector'}`
                  : 'available (start from Camera preview)'}
              </span>
            </li>
          )}
          <li>
            <span>NV oOS credential</span>
            <span className={badge(!!status?.nvoos_credential_stored)}>
              {status?.nvoos_credential_stored ? 'stored (OS keychain)' : 'not set'}
            </span>
          </li>
          <li>
            <span>OpenAI-compatible key</span>
            <span className={badge(!!status?.openai_key_stored)}>
              {status?.openai_key_stored ? 'stored (OS keychain)' : 'not set'}
            </span>
          </li>
          <li>
            <span>Meeting recording</span>
            <span className={badge(!!status?.meeting_recording)}>
              {status?.meeting_recording ? 'recording…' : 'stopped'}
            </span>
          </li>
        </ul>
        <button className="btn" onClick={onRefresh}>
          Refresh
        </button>
      </section>

      <section className="card">
        <h2>Privacy defaults</h2>
        <p className="muted">
          Push-to-talk only — no always-on listening. Dictation audio lives in a short in-memory
          window and is never written to disk. Meeting recordings are transcribed then deleted
          unless you opt into retention. Telemetry is permanently disabled.
        </p>
      </section>
    </div>
  );
}
