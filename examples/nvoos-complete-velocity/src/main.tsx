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
import { lazy, Suspense, useCallback, useEffect, useState, type JSX } from 'react';

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
  UnauthorizedError,
} from './runtime-config';
import { installFetchWrapper } from './fetch-wrapper';
import { logoutFromWordPress } from './login';
import { LoginScreen } from './console/LoginScreen';

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
  | { phase: 'login'; notice?: string }
  | { phase: 'error'; message: string }
  | { phase: 'ready' };

function Console(): JSX.Element {
  const [boot, setBoot] = useState<BootState>({ phase: 'loading' });

  /**
   * Session bootstrap — runs on mount and again after a successful sign-in.
   * A 401 from the user endpoint means "no WordPress session": the console
   * answers with its own sign-in screen (the WordPress login page is never
   * shown). Anything else is an infrastructure failure and keeps the error
   * screen, which also offers sign-in.
   */
  const bootstrap = useCallback(async (): Promise<void> => {
    setBoot({ phase: 'loading' });
    try {
      // Cookie mode: the fetch wrapper needs the empty-X-WP-Nonce strip
      // spa-v2 sends plus the 401 → sign-in signal (session expiry); no
      // bearer/basic/guest credentials exist here.
      installFetchWrapper({
        siteOrigin: window.location.origin,
        onUnauthorized: () =>
          setBoot((prev) =>
            prev.phase === 'login'
              ? prev
              : { phase: 'login', notice: 'Please click the button below.' },
          ),
      });

      const nonce = await fetchSessionNonce();
      const user = await fetchCurrentUser(nonce);
      // Feature map fails open — never block boot on optional surfaces.
      const features = await fetchFeatureMap(nonce);

      window.NVOOS_PRO_SPA = buildConsoleRuntime({ nonce, user, features });
      setBoot({ phase: 'ready' });
    } catch (error) {
      if (error instanceof UnauthorizedError) {
        setBoot({ phase: 'login' });
        return;
      }
      setBoot({
        phase: 'error',
        message: error instanceof Error ? error.message : String(error),
      });
    }
  }, []);

  useEffect(() => {
    void bootstrap();
  }, [bootstrap]);

  /**
   * Sign out without leaving the app: clear the WordPress session (nonce
   * scrape + nonced logout request — no wp page is ever rendered), then
   * return to the sign-in screen. The bootstrap re-check on the next
   * sign-in is the authoritative confirmation the session is really over.
   */
  const handleSignOut = async (): Promise<void> => {
    const result = await logoutFromWordPress();
    if (!result.ok) {
      console.warn('[nvoos-console] sign-out did not complete:', result.message);
    }
    setBoot({ phase: 'login' });
  };

  if (boot.phase === 'loading') {
    return (
      <div className="nvoos-surface-loading" role="status" aria-label="Loading NV oOS Console">
        <div className="nvoos-console-loading__spinner" aria-hidden="true" />
        <p>Loading NV oOS Console…</p>
      </div>
    );
  }

  if (boot.phase === 'login') {
    return <LoginScreen notice={boot.notice} onSuccess={() => void bootstrap()} />;
  }

  if (boot.phase === 'error') {
    return (
      <div className="nvoos-console-error" role="alert">
        <h2>Unable to start the NV oOS Console</h2>
        <p>{boot.message}</p>
        <button
          type="button"
          className="nvoos-console-error__login"
          onClick={() => setBoot({ phase: 'login' })}
        >
          Sign in
        </button>
      </div>
    );
  }

  return (
    <Suspense fallback={<SurfaceLoader />}>
      <ConsoleShell onSignOut={handleSignOut} />
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
