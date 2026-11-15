import { useCallback, useEffect, useState } from 'react';
import { api, type TranscriptRow } from '../lib/ipc';

type DiffPart = { type: 'same' | 'added' | 'removed'; text: string };

/**
 * Word-level diff between the raw ASR text and the cleaned text, so a user can
 * see exactly what the deterministic rules changed (Proposal 144 W4).
 * LCS over word arrays; a dictation is small, so O(n·m) is fine.
 */
function diffWords(before: string, after: string): DiffPart[] {
  const a = before.split(/\s+/).filter(Boolean);
  const b = after.split(/\s+/).filter(Boolean);
  const m = a.length;
  const n = b.length;
  const table: number[][] = Array.from({ length: m + 1 }, () => new Array<number>(n + 1).fill(0));

  for (let i = m - 1; i >= 0; i -= 1) {
    for (let j = n - 1; j >= 0; j -= 1) {
      table[i][j] =
        a[i] === b[j]
          ? table[i + 1][j + 1] + 1
          : Math.max(table[i + 1][j], table[i][j + 1]);
    }
  }

  const parts: DiffPart[] = [];
  const push = (type: DiffPart['type'], text: string) => {
    const last = parts[parts.length - 1];
    if (last && last.type === type) {
      last.text += ` ${text}`;
    } else {
      parts.push({ type, text });
    }
  };

  let i = 0;
  let j = 0;
  while (i < m && j < n) {
    if (a[i] === b[j]) {
      push('same', a[i]);
      i += 1;
      j += 1;
    } else if (table[i + 1][j] >= table[i][j + 1]) {
      push('removed', a[i]);
      i += 1;
    } else {
      push('added', b[j]);
      j += 1;
    }
  }
  while (i < m) {
    push('removed', a[i]);
    i += 1;
  }
  while (j < n) {
    push('added', b[j]);
    j += 1;
  }
  return parts;
}

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
    try {
      await api.deleteHistory(id);
      await load(query);
    } catch (e) {
      setMessage(String(e));
    }
  };

  const clear = async () => {
    try {
      await api.clearHistory();
      await load(query);
      setMessage('History cleared.');
    } catch (e) {
      setMessage(String(e));
    }
  };

  const exportJson = async () => {
    try {
      const text = await api.exportHistory();
      await navigator.clipboard.writeText(text);
      setMessage('JSON copied to clipboard.');
    } catch (e) {
      setMessage(`Export failed: ${e}`);
    }
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
              {row.media && <span className="badge ok"> media</span>}
            </p>
            <details>
              <summary>
                {row.text_raw.trim() === row.text_clean.trim()
                  ? 'Raw'
                  : 'What the rules changed'}
              </summary>
              {row.text_raw.trim() === row.text_clean.trim() ? (
                <p className="raw">{row.text_raw}</p>
              ) : (
                <>
                  <p className="raw">
                    {diffWords(row.text_raw, row.text_clean).map((part, i) =>
                      part.type === 'same' ? (
                        <span key={i}>{part.text} </span>
                      ) : part.type === 'removed' ? (
                        <del key={i} title="removed by cleanup">
                          {part.text}{' '}
                        </del>
                      ) : (
                        <ins key={i} title="added by cleanup">
                          {part.text}{' '}
                        </ins>
                      ),
                    )}
                  </p>
                  <p className="muted small">
                    Struck-through text was removed, underlined text was added by the
                    deterministic cleanup rules.
                  </p>
                </>
              )}
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
