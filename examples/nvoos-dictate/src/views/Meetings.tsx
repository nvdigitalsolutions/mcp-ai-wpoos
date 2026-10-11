import { useCallback, useEffect, useState } from 'react';
import { api, type MeetingRow } from '../lib/ipc';

interface Props {
  onChanged: () => void;
  meetingRecording: boolean;
}

export default function MeetingsView({ onChanged, meetingRecording }: Props) {
  const [rows, setRows] = useState<MeetingRow[]>([]);
  const [title, setTitle] = useState('');
  const [message, setMessage] = useState('');
  const recording = meetingRecording;

  const load = useCallback(async () => {
    try {
      setRows(await api.listMeetings());
    } catch (e) {
      setMessage(String(e));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const start = async () => {
    try {
      await api.startMeeting();
      setMessage('');
      onChanged();
    } catch (e) {
      setMessage(String(e));
    }
  };

  const stop = async () => {
    try {
      const summary = await api.stopMeeting(title.trim() || 'Meeting');
      setMessage(`Transcript saved (#${summary.id}, ${Math.round(summary.duration_ms / 1000)} s).`);
      await load();
      onChanged();
    } catch (e) {
      setMessage(String(e));
    }
  };

  const remove = async (id: number) => {
    try {
      await api.deleteMeeting(id);
      await load();
    } catch (e) {
      setMessage(String(e));
    }
  };

  return (
    <section className="card">
      <h2>Meeting transcription</h2>
      <p className="muted">
        Recording is always explicit. The mic plus system audio (Windows) are captured, the
        meeting is transcribed locally, and the audio is deleted afterwards unless retention is
        enabled. Check local consent laws before recording others.
      </p>
      <div className="toolbar">
        <input
          type="text"
          placeholder="Meeting title"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          disabled={recording}
        />
        {recording ? (
          <button className="btn danger" onClick={() => void stop()}>
            ⏹ Stop & transcribe
          </button>
        ) : (
          <button className="btn" onClick={() => void start()}>
            ● Record
          </button>
        )}
      </div>
      {recording && <p className="warn-text">● Recording — leave this window open.</p>}
      {message && <p className="muted">{message}</p>}

      <ul className="transcripts">
        {rows.map((row) => (
          <li key={row.id}>
            <p className="clean">{row.title}</p>
            <p className="muted small">
              {Math.round(row.duration_ms / 1000)} s · {row.created_at}
            </p>
            <details>
              <summary>Transcript</summary>
              <p className="raw">{row.text}</p>
            </details>
            <button className="link danger" onClick={() => void remove(row.id)}>
              Delete
            </button>
          </li>
        ))}
      </ul>
    </section>
  );
}
