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
import '@nvoos/pro-spa-v2/styles/toolkits.css';
import '@nvoos/pro-spa-v2/styles/drawers.css';
import '@nvoos/pro-spa-v2/styles/embedded.css';
import { useUIStore } from '@nvoos/pro-spa-v2/stores/uiStore';
import {
  clearTenantId,
  readTenantId,
  resolveTenantSelection,
  writeTenantId,
  type EstateSummary,
} from '@nvoos/pro-spa-v2/api/tenant';
import './shell.css';

import {
  assertBackendContract,
  buildConsoleRuntime,
  fetchCurrentUser,
  fetchRuntimeAvailability,
  fetchSessionNonce,
  fetchSiteName,
  UnauthorizedError,
} from './runtime-config';
import { buildTimeBackend } from './backend-profile';
import { brandText } from './brand';
import { installFetchWrapper } from './fetch-wrapper';
import { confirmMagicLink, logoutFromWordPress } from './login';
import { LoginScreen } from './console/LoginScreen';
import { EstatePicker } from './console/EstatePicker';

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
  | { phase: 'login'; notice?: string; error?: string }
  | { phase: 'estate'; estates: EstateSummary[] }
  | { phase: 'error'; message: string }
  | { phase: 'ready' };

const MAGIC_LOGIN_HASH = /^#\/magic-login\?token=([A-Za-z0-9]+)/;

/** Pull the one-shot token out of a magic-link URL hash, if present. */
function extractMagicLoginToken(): string {
  const match = window.location.hash.match(MAGIC_LOGIN_HASH);
  return match?.[1] ?? '';
}

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
        // The Laravel runtime resolves the estate per request from this
        // header (149 §21). WordPress never reads it, so it is only wired for
        // a backend that does — and the wrapper scopes it to `/api/v1/`
        // paths while skipping the identity endpoints.
        tenantId: buildTimeBackend() === 'wordpress' ? undefined : readTenantId,
        onUnauthorized: () =>
          setBoot((prev) =>
            prev.phase === 'login'
              ? prev
              : { phase: 'login', notice: 'Please click the button below.' },
          ),
      });

      // Fail fast when the console and the backend disagree about the
      // runtime (Proposal 090-B, ADR-6). WordPress is a no-op.
      await assertBackendContract();

      const nonce = await fetchSessionNonce();
      const { user, nonce: effectiveNonce, session } = await fetchCurrentUser(nonce);

      // Estate selection (Laravel only — WordPress reports no memberships).
      // Boot order matters: `/session/me` and `/features` sit outside tenant
      // resolution, so this decision can be made before any scoped request.
      // A stored id that is no longer a membership resolves to `pick` instead
      // of a permanent 404, and a single membership needs no header at all.
      const estates = session?.estates ?? [];
      const selection = resolveTenantSelection(estates, readTenantId());
      if (selection.action === 'pick') {
        setBoot({ phase: 'estate', estates });
        return;
      }
      if (selection.action === 'none') {
        // A Laravel session that reports no memberships cannot own a stored
        // id: clear it so the next request is not sent with a selector the
        // middleware will 404. WordPress reports no session block at all, so
        // this never touches storage on that runtime.
        if (session && readTenantId()) {
          clearTenantId();
        }
      } else if (readTenantId() !== selection.tenantId) {
        writeTenantId(selection.tenantId);
      }

      // Availability map fails open — never block boot on optional surfaces.
      // One read returns both the feature flags and which routes this
      // backend serves, so a missing route blanks its endpoint instead of
      // 404ing when the user opens the page.
      const { features, endpoints, endpointUrls, endpointReasons, connectedProxy } =
        await fetchRuntimeAvailability(effectiveNonce);
      // Site title fails open too ('' → branding surfaces fall back to the
      // product name). It also feeds the browser tab title.
      const siteName = await fetchSiteName();
      document.title = brandText(siteName);

      window.NVOOS_PRO_SPA = buildConsoleRuntime({
        nonce: effectiveNonce,
        user,
        features,
        endpoints,
        endpointUrls,
        endpointReasons,
        connectedProxy,
        siteName,
        tenantId: selection.action === 'proceed' ? selection.tenantId : '',
        estates,
      });
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
    // Magic-link sign-in: the emailed link lands on this origin with the
    // token in the hash. Confirm it first (the backend starts the WordPress
    // session — the auth cookie lands on this origin via the proxy); the
    // regular bootstrap only runs once a session exists or the link failed.
    const token = extractMagicLoginToken();
    if (token) {
      void completeMagicLogin(token);
      return;
    }
    void bootstrap();
  }, [bootstrap]);

  /**
   * Consume a magic-link token from the URL hash.
   *
   * Success: the session cookie is set on this origin, so a hard reload
   * lands on the dashboard with a clean URL and a fresh bootstrap.
   * Failure (unknown, expired, or used token): drop the hash so a refresh
   * doesn't retry it, then show the sign-in screen with the backend's
   * uniform message.
   */
  const completeMagicLogin = useCallback(async (token: string): Promise<void> => {
    const result = await confirmMagicLink(token);
    if (result.ok) {
      window.location.replace(window.location.pathname + window.location.search + '#/');
      return;
    }
    window.history.replaceState(
      null,
      '',
      window.location.pathname + window.location.search + '#/',
    );
    setBoot({ phase: 'login', error: result.message });
  }, []);

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
    // The estate selection belongs to the signed-out session: leaving it
    // behind would point the next user's requests at an estate they may not
    // be a member of (the middleware would 404, but the picker would never
    // show because the stored id looked valid).
    clearTenantId();
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
    return (
      <LoginScreen
        notice={boot.notice}
        error={boot.error}
        onSuccess={() => void bootstrap()}
      />
    );
  }

  if (boot.phase === 'estate') {
    return (
      <EstatePicker
        estates={boot.estates}
        onSelect={(estateId) => {
          // Persist first: the fetch wrapper reads the selection per request,
          // so the very next bootstrap's `/session/me` and every scoped call
          // carry it.
          writeTenantId(estateId);
          void bootstrap();
        }}
        onSignOut={handleSignOut}
      />
    );
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
