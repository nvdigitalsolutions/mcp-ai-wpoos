import { useEffect, useState } from 'react';
import { api, type Settings } from '../lib/ipc';

/**
 * Assistant — embeds the hosted NV oOS Pro SPA (chat surface) in a frame.
 *
 * The URL is user-configured under Settings → NV oOS ("Assistant tab URL").
 * While unset, show a hint. The iframe is sandboxed to allow scripts +
 * same-origin + forms but not popups/top-navigation from the SPA; the SPA's
 * own cross-origin fetches run inside the frame against ITS origin (governed
 * by that origin's CORS + the backend's allowlist), not this webview.
 */
export default function AssistantView() {
  const [url, setUrl] = useState('');

  useEffect(() => {
    api
      .getSettings()
      .then((s: Settings) => setUrl(s.spa?.url ?? ''))
      .catch(() => setUrl(''));
  }, []);

  if (!url.trim()) {
    return (
      <section className="card">
        <h2>Assistant</h2>
        <p className="muted">
          No Pro SPA is configured. Add the URL of a hosted NV oOS Pro SPA (standalone app or a
          WordPress page rendering <code>[nvoos_pro_spa]</code>) under{' '}
          <strong>Settings → NV oOS → Assistant tab URL</strong>.
        </p>
      </section>
    );
  }

  return (
    <div className="assistant-frame-wrap">
      <iframe
        className="assistant-frame"
        title="NV oOS Assistant"
        src={url.trim()}
        sandbox="allow-scripts allow-same-origin allow-forms"
        referrerPolicy="no-referrer"
      />
    </div>
  );
}
