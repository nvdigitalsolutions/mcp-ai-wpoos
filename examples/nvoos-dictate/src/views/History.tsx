import { useCallback, useEffect, useState } from 'react';
import { api, type TranscriptRow } from '../lib/ipc';

export default function HistoryView() {
  const [rows, setRows] = useState<TranscriptRow[]>([]);
  const [query, setQuery] = useState('');
  const [message, setMessage] = useState('');

  const load = useCallback(async (q: string) => {
    try {
      setRows(await api.listHistory(q));
    } catch (e) {
      setMessage(String(e));
    }
  }, []);

  useEffect(() => {
    void load('');
  }, [load]);

  const search = () => void load(query);

  const remove = async (id: number) => {
    await api.deleteHistory(id);
    await load(query);
  };

  const clear = async () => {
    await api.clearHistory();
    await load(query);
  };

  const exportJson = async () => {
    const text = await api.exportHistory();
    await navigator.clipboard.writeText(text);
    setMessage('JSON copied to clipboard.');
  };

  return (
    <section className="card">
      <div className="toolbar">
        <input
          type="search"
          placeholder="Search transcripts…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && search()}
        />
        <button className="btn" onClick={search}>
          Search
        </button>
        <button className="btn ghost" onClick={() => void exportJson()}>
          Export JSON
        </button>
        <button className="btn ghost danger" onClick={() => void clear()}>
          Clear all
        </button>
      </div>
      {message && <p className="muted">{message}</p>}
      {rows.length === 0 && <p className="muted">No dictations yet.</p>}
      <ul className="transcripts">
        {rows.map((row) => (
          <li key={row.id}>
            <p className="clean">{row.text_clean}</p>
            <p className="muted small">
              {row.target_app || 'unknown app'} · {row.engine}/{row.model} · {row.provider} ·{' '}
              {row.e2e_ms} ms · {row.created_at}
            </p>
            <details>
              <summary>Raw</summary>
              <p className="raw">{row.text_raw}</p>
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
