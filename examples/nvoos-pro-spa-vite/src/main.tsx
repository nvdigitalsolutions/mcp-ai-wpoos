/**
 * Standalone NV oOS Pro SPA — entry point.
 *
 * 1. Reads the saved connection (site URL + auth mode) from localStorage.
 * 2. Builds the `window.NVOOS_PRO_SPA` runtime the spa-v2 sources expect.
 * 3. Renders the spa-v2 <App/> (admin) or <EmbeddedApp/> (chat surface).
 *
 * The SPA sources are imported from addons/pro/assets/spa-v2/src through the
 * `@nvoos/pro-spa-v2` Vite alias — no fork, tracks the plugin SPA.
 */

import { createRoot } from 'react-dom/client';
import { lazy, Suspense, useEffect, useState, type JSX, type FormEvent } from 'react';

import type { ProSpaRuntime } from '@nvoos/pro-spa-v2/api/config';
import '@nvoos/pro-spa-v2/styles/main.css';
import '@nvoos/pro-spa-v2/styles/drawers.css';
import '@nvoos/pro-spa-v2/styles/embedded.css';
import './shell.css';

import {
  buildBasicAuthHeader,
  buildRuntime,
  checkMediaWorker,
  detectCookieProxy,
  fetchCurrentUser,
  fetchCurrentUserBasic,
  fetchSessionNonce,
  type AuthMode,
  type ConnectionSettings,
} from './runtime-config';
import { installFetchWrapper } from './fetch-wrapper';

const STORAGE_KEY = 'nvoos-standalone-connection';

/**
 * The two SPA surfaces are the heavy part of the bundle (router + chat +
 * AI SDK + editors). They only mount after a successful connect, so load
 * them on demand: the connection screen — the first thing every visitor
 * sees — paints with just react/react-dom and the entry chunk stays well
 * under the build's chunk-size warning. The spa-v2 sources stay untouched;
 * the plugin's own esbuild bundle is unaffected.
 */
const AdminApp = lazy(() =>
  import('@nvoos/pro-spa-v2/App').then((m) => ({ default: m.App })),
);
const EmbeddedSurface = lazy(() =>
  import('@nvoos/pro-spa-v2/features/embedded/EmbeddedApp').then((m) => ({ default: m.EmbeddedApp })),
);

/** Shown while the admin/chat surface chunk loads after a connect. */
function SurfaceLoader(): JSX.Element {
  return (
    <div className="nvoos-surface-loading" role="status" aria-label="Loading NV oOS Pro">
      Loading NV oOS Pro…
    </div>
  );
}

declare global {
  interface Window {
    NVOOS_PRO_SPA?: unknown;
  }
}

function readSavedConnection(): ConnectionSettings | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw) as ConnectionSettings) : null;
  } catch {
    return null;
  }
}

/** Resolve a site URL to its origin, tolerating malformed input. */
function safeOrigin(siteUrl: string): string | undefined {
  try {
    return new URL(siteUrl).origin;
  } catch {
    return undefined;
  }
}

interface ConnectResult {
  runtime?: ProSpaRuntime;
  error?: string;
}

async function connect(connection: ConnectionSettings): Promise<ConnectResult> {
  const viaProxy = connection.auth === 'cookie';

  try {
    let nonce = '';
    let user: Parameters<typeof buildRuntime>[1]['user'];

    // Optional Design Stack media worker: verify it is reachable before
    // mounting the SPA (fail fast with a clear error when misconfigured).
    if (connection.mediaWorkerUrl?.trim()) {
      await checkMediaWorker(connection);
    }

    if (connection.auth === 'cookie') {
      // Cookie mode rides a same-origin proxy (the Vite dev proxy or the
      // built-in serve.mjs proxy). Without one, every request hits this
      // app's own SPA fallback and dies with a JSON parse error — probe
      // first so the user gets a clear message instead.
      const proxied = await detectCookieProxy(window.location.origin);
      if (!proxied) {
        return {
          error:
            'Cookie mode needs a proxy in front of this origin. In dev run `npm run dev:proxy` ' +
            '(NVOOS_TARGET_SITE=https://your-site.com), or start the production server with ' +
            'NVOOS_TARGET_SITE set (scripts/serve.mjs). On managed Node hosting that variable must be ' +
            'in the app\u2019s RUNTIME environment (not the build-time one, where VITE_DEFAULT_SITE_URL lives). ' +
            'Assistant credentials and guest tokens work without a proxy.',
        };
      }
      // Same-origin through the proxy: mint a nonce and read the user.
      nonce = await fetchSessionNonce(window.location.origin);
      user = await fetchCurrentUser(window.location.origin, nonce);
    } else if (connection.auth === 'wp') {
      if (!connection.wpUsername?.trim() || !connection.wpAppPassword?.trim()) {
        return { error: 'Enter your WordPress username and an application password.' };
      }
      // Stateless Basic auth: users/me validates the credentials and returns
      // the real WordPress user (including the real capability list, which
      // gates the admin surface server-side and client-side).
      user = await fetchCurrentUserBasic(
        connection.siteUrl,
        connection.wpUsername.trim(),
        connection.wpAppPassword.trim(),
      );
    } else if (connection.auth === 'bearer') {
      if (!connection.bearer?.trim()) {
        return { error: 'Enter an assistant credential (cred_xxxxx.SECRET).' };
      }
      // Quick credential check against the assistants endpoint.
      const check = await fetch(`${connection.siteUrl}/wp-json/mcp-ai/v1/assistants`, {
        headers: { Authorization: `Bearer ${connection.bearer.trim()}` },
      });
      if (!check.ok) {
        return {
          error: `Credential rejected (HTTP ${check.status}). Check the credential and site URL.`,
        };
      }
      user = {
        id: 0,
        login: 'credential',
        displayName: 'Assistant credential',
        // The server enforces everything; this unlocks the admin layout
        // client-side for the credential holder (typically the site operator).
        capabilities: ['manage_options'],
      };
    } else if (connection.auth === 'guest') {
      if (!connection.assistantId) {
        return { error: 'Guest mode needs an assistant ID (and usually a guest token from the site).' };
      }
      user = { id: 0, login: '', displayName: '', capabilities: [] };
    }

    const runtime = buildRuntime(connection, { viaProxy, nonce, user });
    window.NVOOS_PRO_SPA = runtime;

    installFetchWrapper({
      bearer: connection.auth === 'bearer' ? connection.bearer?.trim() : undefined,
      basic:
        connection.auth === 'wp' && connection.wpUsername && connection.wpAppPassword
          ? buildBasicAuthHeader(connection.wpUsername.trim(), connection.wpAppPassword.trim())
          : undefined,
      siteOrigin: connection.auth === 'wp' ? safeOrigin(connection.siteUrl) : undefined,
      guest: connection.auth === 'guest',
      guestToken: connection.auth === 'guest' ? connection.guestToken : undefined,
      mediaWorkerUrl: connection.mediaWorkerUrl?.trim() || undefined,
      mediaWorkerToken: connection.mediaWorkerToken?.trim() || undefined,
    });

    return { runtime };
  } catch (error) {
    return { error: error instanceof Error ? error.message : String(error) };
  }
}

function Shell(): JSX.Element {
  const [connection, setConnection] = useState<ConnectionSettings | null>(readSavedConnection);
  const [runtime, setRuntime] = useState<ProSpaRuntime | null>(null);
  const [connecting, setConnecting] = useState(false);
  const [error, setError] = useState('');

  // Connection form state. The site URL defaults to the backend configured at
  // build time (VITE_DEFAULT_SITE_URL), so a Velocity deployment can ship
  // with its WordPress backend pre-filled (e.g. https://nvoos.pro).
  const defaultSiteUrl = (import.meta.env.VITE_DEFAULT_SITE_URL as string | undefined)?.trim() ?? '';
  const [siteUrl, setSiteUrl] = useState(connection?.siteUrl ?? defaultSiteUrl);
  const [auth, setAuth] = useState<AuthMode>(connection?.auth ?? 'cookie');
  const [bearer, setBearer] = useState(connection?.bearer ?? '');
  const [guestToken, setGuestToken] = useState(connection?.guestToken ?? '');
  const [wpUsername, setWpUsername] = useState(connection?.wpUsername ?? '');
  const [wpAppPassword, setWpAppPassword] = useState(connection?.wpAppPassword ?? '');
  const [assistantId, setAssistantId] = useState(String(connection?.assistantId ?? ''));
  const [adminLayout, setAdminLayout] = useState(connection?.adminLayout ?? true);
  const [mediaWorkerUrl, setMediaWorkerUrl] = useState(connection?.mediaWorkerUrl ?? '');
  const [mediaWorkerToken, setMediaWorkerToken] = useState(connection?.mediaWorkerToken ?? '');

  // Auto-reconnect when a saved connection exists (page reload / fresh tab).
  useEffect(() => {
    if (connection && !runtime && !connecting && !error) {
      setConnecting(true);
      void connect(connection).then((result) => {
        if (result.runtime) {
          setRuntime(result.runtime);
        } else {
          setError(result.error ?? 'Reconnect failed.');
        }
        setConnecting(false);
      });
    }
  }, [connection, runtime, connecting, error]);

  const handleConnect = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setConnecting(true);
    setError('');

    const next: ConnectionSettings = {
      siteUrl: siteUrl.trim().replace(/\/+$/, ''),
      auth,
      bearer: auth === 'bearer' ? bearer.trim() : undefined,
      guestToken: auth === 'guest' ? guestToken.trim() : undefined,
      wpUsername: auth === 'wp' ? wpUsername.trim() : undefined,
      wpAppPassword: auth === 'wp' ? wpAppPassword.trim() : undefined,
      assistantId: assistantId ? Number(assistantId) : undefined,
      adminLayout,
      mediaWorkerUrl: mediaWorkerUrl.trim().replace(/\/+$/, ''),
      mediaWorkerToken: mediaWorkerToken.trim(),
    };

    const result = await connect(next);
    if (!result.runtime) {
      setError(result.error ?? 'Connection failed.');
      setConnecting(false);
      return;
    }

    localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    setConnection(next);
    setRuntime(result.runtime);
    setConnecting(false);
  };

  const handleDisconnect = (): void => {
    localStorage.removeItem(STORAGE_KEY);
    window.NVOOS_PRO_SPA = undefined;
    setConnection(null);
    setRuntime(null);
    setError('');
  };

  if (connection && runtime) {
    const embedded = connection.auth === 'guest' || !connection.adminLayout;
    return (
      <>
        <Suspense fallback={<SurfaceLoader />}>
          {embedded ? <EmbeddedSurface /> : <AdminApp />}
        </Suspense>
        <button
          type="button"
          className="nvoos-disconnect"
          onClick={handleDisconnect}
          title="Change site / disconnect"
        >
          ⚙
        </button>
      </>
    );
  }

  return (
    <main className="nvoos-connect">
      <h1>
        NV oOS <span>Pro</span> — Standalone
      </h1>
      <p className="nvoos-connect-sub">
        Mounts the Pro SPA (addons/pro/assets/spa-v2) against any WordPress site running the NV oOS
        plugin.
      </p>

      <form onSubmit={handleConnect} className="nvoos-connect-form">
        <label>
          WordPress site URL
          <input
            type="url"
            required
            placeholder="https://example.com"
            value={siteUrl}
            onChange={(e) => setSiteUrl(e.target.value)}
          />
        </label>

        <fieldset className="nvoos-auth-modes">
          <legend>Authentication</legend>
          <label>
            <input type="radio" name="auth" checked={auth === 'cookie'} onChange={() => setAuth('cookie')} />
            Cookie (dev proxy) — full admin surface, log in through the app
          </label>
          <label>
            <input type="radio" name="auth" checked={auth === 'wp'} onChange={() => setAuth('wp')} />
            WordPress login — application password (cross-origin)
          </label>
          <label>
            <input type="radio" name="auth" checked={auth === 'bearer'} onChange={() => setAuth('bearer')} />
            Assistant credential — cross-origin, no cookies
          </label>
          <label>
            <input type="radio" name="auth" checked={auth === 'guest'} onChange={() => setAuth('guest')} />
            Guest — public chat surface
          </label>
        </fieldset>

        {auth === 'cookie' && (
          <p className="nvoos-cookie-login">
            <a href="/wp-login.php?redirect_to=%2F" target="_blank" rel="noopener noreferrer">
              Log in to WordPress
            </a>
            <span className="nvoos-connect-hint">
              Opens the site login in a new tab. After logging in you return to this app — the
              session cookie is set on this origin, so Connect works right away.
            </span>
          </p>
        )}

        {auth === 'wp' && (
          <>
            <label>
              WordPress username
              <input
                type="text"
                autoComplete="username"
                value={wpUsername}
                onChange={(e) => setWpUsername(e.target.value)}
                placeholder="Login name (not email)"
              />
            </label>
            <label>
              Application password (xxxx xxxx xxxx xxxx xxxx xxxx)
              <input
                type="password"
                autoComplete="current-password"
                value={wpAppPassword}
                onChange={(e) => setWpAppPassword(e.target.value)}
                placeholder="Created in Users → Profile → Application Passwords"
              />
            </label>
            <span className="nvoos-connect-hint">
              Use an application password from your WordPress profile — never your regular account
              password. It authenticates as your user with your real permissions and can be revoked
              at any time. Prefer a dedicated, least-privilege user for this app.
            </span>
          </>
        )}

        {auth === 'bearer' && (
          <label>
            Assistant credential (cred_xxxxx.SECRET)
            <input type="password" value={bearer} onChange={(e) => setBearer(e.target.value)} placeholder="cred_…" />
          </label>
        )}

        {auth === 'guest' && (
          <>
            <label>
              Assistant ID
              <input
                type="number"
                min={1}
                value={assistantId}
                onChange={(e) => setAssistantId(e.target.value)}
                placeholder="e.g. 42"
              />
            </label>
            <label>
              Guest token (optional — minted by the site&apos;s shortcode page)
              <input
                type="password"
                value={guestToken}
                onChange={(e) => setGuestToken(e.target.value)}
                placeholder="X-WP-MCP-AI-Guest"
              />
            </label>
          </>
        )}

        {auth !== 'guest' && (
          <label className="nvoos-check">
            <input type="checkbox" checked={adminLayout} onChange={(e) => setAdminLayout(e.target.checked)} />
            Show the full admin surface (assistants, tools, workflows, analytics)
          </label>
        )}

        <fieldset className="nvoos-auth-modes">
          <legend>Design Stack media worker (optional)</legend>
          <label>
            Worker URL (e.g. https://velocity-app-url)
            <input
              type="url"
              placeholder="https://media-worker.example.com"
              value={mediaWorkerUrl}
              onChange={(e) => setMediaWorkerUrl(e.target.value)}
            />
          </label>
          <label>
            Site token (X-Site-Token)
            <input
              type="password"
              value={mediaWorkerToken}
              onChange={(e) => setMediaWorkerToken(e.target.value)}
              placeholder="Worker WORKER_API_TOKEN"
            />
          </label>
          <span className="nvoos-connect-hint">
            Requests to the worker origin carry the token automatically; leave empty to skip.
          </span>
        </fieldset>

        {error && (
          <p className="nvoos-connect-error" role="alert">
            {error}
          </p>
        )}

        <button type="submit" disabled={connecting}>
          {connecting ? 'Connecting…' : 'Connect'}
        </button>
      </form>

      <aside className="nvoos-connect-notes">
        <strong>Cookie mode</strong> needs a same-origin proxy in front of this app: start the dev
        server with <code>NVOOS_TARGET_SITE=https://your-site.com npm run dev:proxy</code>, or run the
        production server (<code>scripts/serve.mjs</code>) with <code>NVOOS_TARGET_SITE</code> set — the
        proxy makes the WordPress REST API (and wp-login.php) same-origin. <strong>WordPress
        login</strong> authenticates with an application password and works cross-origin like
        assistant credentials (allow the app origin under Security → Network).{' '}
        <strong>Assistant credentials</strong> are issued in the NV oOS plugin and work
        cross-origin (the plugin sends CORS headers; set the allowed origin under Security →
        Network).
      </aside>
    </main>
  );
}

createRoot(document.getElementById('root') as HTMLElement).render(<Shell />);
