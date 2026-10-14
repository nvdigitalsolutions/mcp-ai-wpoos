import { useCallback, useEffect, useState } from 'react';
import { api, type Settings, type SpaSurface } from '../lib/ipc';

/**
 * NV oOS — the multi-surface hub.
 *
 * Each installed-and-activated NV oOS SPA addon on the configured WordPress
 * site becomes a sub-tab here; the active sub-tab's hosted SPA is rendered in
 * a sandboxed iframe. The list of surfaces is discovered from the site's
 * `GET /wp-json/mcp-ai-pro/v1/spa-surfaces` endpoint (authoritative — a
 * surface only appears when its addon is genuinely active server-side).
 *
 * Fallbacks, in order:
 *  1. Discovered surfaces (site reachable + credentialed) → sub-tabs.
 *  2. A single configured "Assistant tab URL" (legacy `spa.url`) → one tab.
 *  3. Nothing → a "not configured" hint pointing at Settings → NV oOS.
 */
export default function AssistantView() {
  const [surfaces, setSurfaces] = useState<SpaSurface[] | null>(null);
  const [legacyUrl, setLegacyUrl] = useState('');
  const [activeId, setActiveId] = useState<string | null>(null);

  const load = useCallback(async () => {
    // Legacy single-SPA URL (backward-compatible fallback).
    try {
      const s: Settings = await api.getSettings();
      setLegacyUrl(s.spa?.url ?? '');
    } catch {
      setLegacyUrl('');
    }

    // Authoritative discovery from the configured site. An empty list (site
    // unreachable, no credential, or no Pro endpoint) is not an error — it
    // means we fall back to the legacy single-SPA behaviour.
    try {
      const found = await api.listSpaSurfaces();
      setSurfaces(found);
      if (found.length > 0) {
        setActiveId((prev) => (prev && found.some((f) => f.id === prev) ? prev : found[0].id));
      }
    } catch {
      setSurfaces(null);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  // Determine the tabs to render and their resolved iframe URLs.
  // A surface with a `route` fragment (an SPA-internal route like
  // `#/knowledge-graph`) is resolved against the configured hosted-SPA URL;
  // otherwise its server-provided shortcode page URL is used verbatim.
  const spaBase = legacyUrl.trim().replace(/\/+$/, '');
  const resolveUrl = (s: SpaSurface): string => {
    if (s.route && s.route.trim()) {
      if (spaBase) {
        return spaBase + s.route.trim();
      }
      return s.url; // No SPA base configured — fall back to the placeholder.
    }
    return s.url;
  };

  const tabs: { surface: SpaSurface; url: string }[] =
    surfaces && surfaces.length > 0
      ? surfaces.map((s) => ({ surface: s, url: resolveUrl(s) }))
      : legacyUrl.trim()
        ? [
            {
              surface: { id: 'assistant', title: 'Assistant', url: legacyUrl.trim(), description: '' },
              url: legacyUrl.trim(),
            },
          ]
        : [];

  if (tabs.length === 0) {
    return (
      <section className="card">
        <h2>NV oOS</h2>
        <p className="muted">
          No NV oOS surfaces are configured. Connect this app to an NV oOS WordPress site with the
          Pro addon active under <strong>Settings → NV oOS</strong>, then the site&apos;s installed
          assistant surfaces will appear here as tabs.
        </p>
      </section>
    );
  }

  const active = tabs.find((t) => t.surface.id === activeId) ?? tabs[0];

  return (
    <div className="nvoos-hub">
      <nav className="tabs nvoos-hub-tabs" aria-label="NV oOS surfaces">
        {tabs.map((t) => (
          <button
            key={t.surface.id}
            className={t.surface.id === active.surface.id ? 'tab active' : 'tab'}
            onClick={() => setActiveId(t.surface.id)}
            title={t.surface.description || t.surface.title}
          >
            {t.surface.title}
          </button>
        ))}
      </nav>
      <div className="assistant-frame-wrap">
        <iframe
          className="assistant-frame"
          title={`NV oOS — ${active.surface.title}`}
          src={active.url}
          sandbox="allow-scripts allow-same-origin allow-forms"
          referrerPolicy="no-referrer"
        />
      </div>
    </div>
  );
}
