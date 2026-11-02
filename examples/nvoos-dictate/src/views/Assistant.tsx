import { useCallback, useEffect, useState } from 'react';
import { api, type Settings } from '../lib/ipc';

/**
 * NV oOS — the Complete Velocity console launcher.
 *
 * The console opens in its own top-level window rather than an embedded
 * iframe: its WordPress sign-in is cookie-based, and embedded frames are a
 * third-party context where WebView2 refuses to store cookies — the login
 * fails with a misleading "Invalid username or password". A first-party
 * window is the same context as a browser tab, so sign-in works normally.
 */
export default function AssistantView() {
  const [url, setUrl] = useState('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      try {
        const s: Settings = await api.getSettings();
        setUrl(s.spa?.url?.trim() ?? '');
      } catch {
        setUrl('');
      }
    })();
  }, []);

  const open = useCallback(async () => {
    setError(null);
    try {
      await api.openNvoosConsole();
    } catch (e) {
      setError(String(e));
    }
  }, []);

  if (!url) {
    return (
      <section className="card">
        <h2>NV oOS</h2>
        <p className="muted">
          No Complete Velocity URL is configured. Set the{' '}
          <strong>Default Complete Velocity URL</strong> under{' '}
          <strong>Settings → NV oOS</strong> and the console will open from here.
        </p>
      </section>
    );
  }

  return (
    <section className="card">
      <h2>NV oOS Console</h2>
      <p className="muted">
        The console opens in its own window at <strong>{url}</strong> — a first-party context, so
        its WordPress sign-in works exactly like a browser tab.
      </p>
      {error && (
        <p className="muted" style={{ color: 'var(--danger)' }}>
          {error}
        </p>
      )}
      <div className="btn-row">
        <button className="btn" onClick={() => void open()}>
          Open NV oOS Console
        </button>
      </div>
    </section>
  );
}
