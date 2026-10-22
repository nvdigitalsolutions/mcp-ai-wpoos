/**
 * NV oOS Complete Console — entry point.
 *
 * Fixed cookie-auth console: builds the `window.NVOOS_PRO_SPA` runtime from
 * the logged-in WordPress session (through the same-origin proxy), installs
 * the fetch wrapper, then lazy-mounts the SA-format console shell
 * (src/console/ConsoleShell.tsx). No connection screen — unauthenticated
 * visitors get the login flow.
 *
 * The spa-v2 sources are imported through the `@nvoos/pro-spa-vite`-style
 * Vite alias — no fork, tracks the plugin SPA.
 */

import { createRoot } from 'react-dom/client';
import { lazy, Suspense, useEffect, useState, type JSX } from 'react';

import '@nvoos/pro-spa-v2/styles/main.css';
import '@nvoos/pro-spa-v2/styles/drawers.css';
import '@nvoos/pro-spa-v2/styles/embedded.css';
import { useUIStore } from '@nvoos/pro-spa-v2/stores/uiStore';
import './shell.css';

import {
  buildConsoleRuntime,
  fetchCurrentUser,
  fetchFeatureMap,
  fetchSessionNonce,
} from './runtime-config';
import { installFetchWrapper } from './fetch-wrapper';

declare global {
  interface Window {
    NVOOS_PRO_SPA?: unknown;
  }
}

/**
 * The console shell is the heavy part of the bundle (spa-v2 pages + chat + AI
 * SDK + editors) — lazy-mount it so the entry chunk stays lean. The shell
 * needs the runtime global + session (nonce + user) before it can render.
 */
const ConsoleShell = lazy(() =>
  import('./console/ConsoleShell').then((m) => ({ default: m.ConsoleShell })),
);

type BootState =
  | { phase: 'loading' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' };

function Console(): JSX.Element {
  const [boot, setBoot] = useState<BootState>({ phase: 'loading' });

  useEffect(() => {
    let cancelled = false;

    async function boot(): Promise<void> {
      try {
        // Cookie mode: the fetch wrapper only needs the empty-X-WP-Nonce
        // strip spa-v2 sends; no bearer/basic/guest credentials exist here.
        installFetchWrapper({});

        const nonce = await fetchSessionNonce();
        const user = await fetchCurrentUser(nonce);
        // Feature map fails open — never block boot on optional surfaces.
        const features = await fetchFeatureMap(nonce);
        if (cancelled) {
          return;
        }

        window.NVOOS_PRO_SPA = buildConsoleRuntime({ nonce, user, features });
        setBoot({ phase: 'ready' });
      } catch (error) {
        if (!cancelled) {
          setBoot({
            phase: 'error',
            message: error instanceof Error ? error.message : String(error),
          });
        }
      }
    }

    void boot();
    return () => {
      cancelled = true;
    };
  }, []);

  if (boot.phase === 'loading') {
    return (
      <div className="nvoos-surface-loading" role="status" aria-label="Loading NV oOS Console">
        <div className="nvoos-console-loading__spinner" aria-hidden="true" />
        <p>Loading NV oOS Console…</p>
      </div>
    );
  }

  if (boot.phase === 'error') {
    return (
      <div className="nvoos-console-error" role="alert">
        <h2>Unable to start the NV oOS Console</h2>
        <p>{boot.message}</p>
        <a href="/wp-login.php?redirect_to=%2F" className="nvoos-console-error__login">
          Log in to WordPress
        </a>
      </div>
    );
  }

  return (
    <Suspense fallback={<SurfaceLoader />}>
      <ConsoleShell />
    </Suspense>
  );
}

function SurfaceLoader(): JSX.Element {
  return (
    <div className="nvoos-surface-loading" role="status" aria-label="Loading NV oOS Console">
      Loading NV oOS Console…
    </div>
  );
}

const rootElement = document.getElementById('root');
if (rootElement) {
  // Boot the sidebar state from the route BEFORE first paint: the docked
  // chat starts with the conversation list collapsed and the full-page
  // /chat breakout starts with it open. ChatDock re-syncs on every mode
  // change; the status bar ☰ toggles the same store state in between.
  useUIStore
    .getState()
    .setSidebarOpen(window.location.hash.startsWith('#/chat'));

  createRoot(rootElement).render(<Console />);
}
