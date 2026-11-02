import { useEffect, useState } from 'react';
import { api, type Settings } from '../lib/ipc';

/**
 * NV oOS — the Complete Velocity console.
 *
 * The configured "Default Complete Velocity URL" is rendered in a single
 * sandboxed iframe. Every NV oOS surface (assistant, chat, toolkits,
 * schedule, media, documents, …) is wrapped inside the
 * nvoos-complete-velocity console, so the hub's earlier per-addon surface
 * discovery and sub-tabs were retired: one frame, one origin, and tab
 * switches inside the app never reload the console.
 */
export default function AssistantView() {
  const [url, setUrl] = useState('');

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

  if (!url) {
    return (
      <section className="card">
        <h2>NV oOS</h2>
        <p className="muted">
          No Complete Velocity URL is configured. Set the{' '}
          <strong>Default Complete Velocity URL</strong> under{' '}
          <strong>Settings → NV oOS</strong> and the console will appear here.
        </p>
      </section>
    );
  }

  return (
    <div className="nvoos-hub">
      <iframe
        className="assistant-frame"
        title="NV oOS Complete Velocity"
        src={url}
        sandbox="allow-scripts allow-same-origin allow-forms"
        referrerPolicy="no-referrer"
      />
    </div>
  );
}
