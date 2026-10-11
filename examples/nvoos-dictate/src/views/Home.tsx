import type { DictateEvent, StatusInfo } from '../lib/ipc';

interface Props {
  status: StatusInfo | null;
  dictate: DictateEvent;
  onRefresh: () => void;
}

function badge(ok: boolean) {
  return ok ? 'badge ok' : 'badge warn';
}

export default function Home({ status, dictate, onRefresh }: Props) {
  return (
    <div className="stack">
      <section className="card">
        <h2>Dictation</h2>
        <p className="muted">
          Hold <strong>Right Alt</strong>, speak, release — cleaned text pastes into the focused
          app. Hold <strong>Shift</strong> on release for raw passthrough. Double-tap for
          hands-free mode, <strong>Esc</strong> cancels.
        </p>
        <div className="dictate-state" data-state={dictate.state}>
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
