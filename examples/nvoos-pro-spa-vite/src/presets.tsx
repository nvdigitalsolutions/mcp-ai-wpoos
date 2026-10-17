/**
 * Login presets for the standalone Pro SPA.
 *
 * A deployment can pin the connection screen to a single auth option by
 * setting `HOME` in the app's .env file (vite.config.ts bakes it into the
 * bundle as `import.meta.env.NVOOS_HOME` at build time):
 *
 *   HOME unset — the full connection screen (all auth modes + media worker).
 *   HOME=1     — Cookie (dev proxy): full admin surface, log in through the
 *                app (the login affordance is just a button).
 *   HOME=2     — WordPress application password (cross-origin).
 *   HOME=3     — Assistant credential (cross-origin, no cookies).
 *   HOME=4     — No inputs at all: the connection is assembled from env vars
 *                (NVOOS_SITE_URL + NVOOS_ASSISTANT_CREDENTIAL, or
 *                NVOOS_WP_USERNAME + NVOOS_WP_APP_PASSWORD) — Connect only.
 *
 * On every preset page the Design Stack media worker fields are hidden — the
 * worker is attached from NVOOS_WORKER_URL / NVOOS_WORKER_TOKEN instead.
 *
 * Runtime overrides: scripts/serve.mjs (production/Velocity) injects
 * `window.__NVOOS_RUNTIME__` into the served HTML shell from the RUNTIME
 * environment. Every value below resolves runtime-first, then falls back to
 * the baked build-time value — so operators can switch presets or rotate
 * credentials without a redeploy. Keep the key names in sync with
 * RUNTIME_ENV_KEYS in serve.mjs and src/env.d.ts.
 */

import type { FormEvent, JSX } from 'react';

import type { AuthMode, ConnectionSettings } from './runtime-config';

export type LoginPreset = 0 | 1 | 2 | 3 | 4;

export interface PresetMeta {
  auth: AuthMode;
  /** Short badge shown under the header. */
  label: string;
  /** One-line description of the pinned option. */
  description: string;
}

const META: Record<Exclude<LoginPreset, 0>, PresetMeta> = {
  1: {
    auth: 'cookie',
    label: 'Cookie login (dev proxy)',
    description:
      'Full admin surface — log in to WordPress through this app, then connect. The session cookie lives on this origin.',
  },
  2: {
    auth: 'wp',
    label: 'WordPress application password',
    description:
      'Cross-origin login as a real WordPress user, authenticated with an application password.',
  },
  3: {
    auth: 'bearer',
    label: 'Assistant credential',
    description: 'Cross-origin, no cookies — a credential issued by the NV oOS plugin.',
  },
  4: {
    auth: 'bearer',
    label: 'Preconfigured connection (env)',
    description: 'The connection comes entirely from the environment — nothing to type.',
  },
};

/** Per-preset metadata, or null for the default (unpinned) screen. */
export function presetMeta(preset: LoginPreset): PresetMeta | null {
  return preset === 0 ? null : META[preset];
}

/**
 * Runtime overrides injected into the HTML shell by scripts/serve.mjs.
 * Key names mirror RUNTIME_ENV_KEYS there — keep the two in sync.
 */
export interface RuntimeEnvOverrides {
  NVOOS_HOME?: string;
  NVOOS_SITE_URL?: string;
  NVOOS_ASSISTANT_CREDENTIAL?: string;
  NVOOS_WP_USERNAME?: string;
  NVOOS_WP_APP_PASSWORD?: string;
  NVOOS_WORKER_URL?: string;
  NVOOS_WORKER_TOKEN?: string;
  NVOOS_AUTO_CONNECT?: string;
}

declare global {
  interface Window {
    __NVOOS_RUNTIME__?: RuntimeEnvOverrides;
  }
}

const ENV = import.meta.env;

type EnvKey = keyof RuntimeEnvOverrides;

/**
 * Resolve a login-preset env value: the runtime override injected by
 * serve.mjs wins, the build-time value baked in by vite.config.ts is the
 * fallback (dev server, static hosting without serve.mjs). Empty runtime
 * values never override a baked value.
 */
function envValue(key: EnvKey): string {
  const runtime =
    typeof window !== 'undefined' && window.__NVOOS_RUNTIME__
      ? window.__NVOOS_RUNTIME__[key]
      : undefined;
  if (typeof runtime === 'string' && runtime.trim()) {
    return runtime.trim();
  }
  const baked = ENV[key];
  return (typeof baked === 'string' ? baked : '').trim();
}

export interface EnvSettings {
  siteUrl: string;
  assistantCredential: string;
  wpUsername: string;
  wpAppPassword: string;
  workerUrl: string;
  workerToken: string;
}

/** Login-preset env values (runtime overrides first, baked values fallback). */
export function readEnvSettings(): EnvSettings {
  return {
    siteUrl: envValue('NVOOS_SITE_URL'),
    assistantCredential: envValue('NVOOS_ASSISTANT_CREDENTIAL'),
    wpUsername: envValue('NVOOS_WP_USERNAME'),
    wpAppPassword: envValue('NVOOS_WP_APP_PASSWORD'),
    workerUrl: envValue('NVOOS_WORKER_URL'),
    workerToken: envValue('NVOOS_WORKER_TOKEN'),
  };
}

/**
 * Parse the HOME login-preset flag. Anything that is not exactly '1'..'4'
 * falls back to the default connection screen (preset 0).
 */
export function readLoginPreset(): LoginPreset {
  const raw = envValue('NVOOS_HOME');
  if (raw === '1' || raw === '2' || raw === '3' || raw === '4') {
    return Number(raw) as LoginPreset;
  }
  return 0;
}

/**
 * NVOOS_AUTO_CONNECT=1: on the preconfigured page (HOME=4) connect
 * immediately on load instead of waiting for the Connect click.
 * See the README's security note — pair with edge access control when the
 * app is publicly reachable.
 */
export function readAutoConnect(): boolean {
  return envValue('NVOOS_AUTO_CONNECT') === '1';
}

/**
 * Resolve the mode-4 connection entirely from env vars.
 *
 * Auth resolution: NVOOS_ASSISTANT_CREDENTIAL (bearer) wins; otherwise
 * NVOOS_WP_USERNAME + NVOOS_WP_APP_PASSWORD (application password). The
 * media worker is attached when NVOOS_WORKER_URL is set.
 */
export function resolveEnvConnection(
  env: EnvSettings,
): { connection: ConnectionSettings | null; error: string } {
  if (!env.siteUrl) {
    return {
      connection: null,
      error:
        'HOME=4 needs NVOOS_SITE_URL in the environment — there is no WordPress site to connect to.',
    };
  }

  let connection: ConnectionSettings;
  if (env.assistantCredential) {
    connection = {
      siteUrl: env.siteUrl,
      auth: 'bearer',
      bearer: env.assistantCredential,
      adminLayout: true,
    };
  } else if (env.wpUsername && env.wpAppPassword) {
    connection = {
      siteUrl: env.siteUrl,
      auth: 'wp',
      wpUsername: env.wpUsername,
      wpAppPassword: env.wpAppPassword,
      adminLayout: true,
    };
  } else {
    return {
      connection: null,
      error:
        'HOME=4 needs credentials in the environment: set NVOOS_ASSISTANT_CREDENTIAL ' +
        '(or NVOOS_WP_USERNAME + NVOOS_WP_APP_PASSWORD).',
    };
  }

  if (env.workerUrl) {
    connection.mediaWorkerUrl = env.workerUrl.replace(/\/+$/, '');
    connection.mediaWorkerToken = env.workerToken || undefined;
  }

  return { connection, error: '' };
}

function authLabel(connection: ConnectionSettings): string {
  switch (connection.auth) {
    case 'bearer':
      return 'Assistant credential (from env)';
    case 'wp':
      return 'WordPress application password (from env)';
    case 'cookie':
      return 'Cookie session (from env)';
    case 'guest':
      return 'Guest token (from env)';
  }
}

export interface PresetLoginProps {
  preset: Exclude<LoginPreset, 0>;
  siteUrl: string;
  onSiteUrl: (value: string) => void;
  bearer: string;
  onBearer: (value: string) => void;
  wpUsername: string;
  onWpUsername: (value: string) => void;
  wpAppPassword: string;
  onWpAppPassword: (value: string) => void;
  /** Resolved mode-4 connection, or null when the env is incomplete. */
  envConnection: ConnectionSettings | null;
  /** Why mode 4 cannot connect yet (env incomplete). */
  envError: string;
  /** Env-provided media worker shown as a muted note (fields stay hidden). */
  workerNote: string;
  /** NVOOS_AUTO_CONNECT=1 — mode 4 usually connects by itself on load. */
  autoConnect: boolean;
  connecting: boolean;
  error: string;
  onSubmit: (event: FormEvent) => void;
}

/**
 * Single-option login page for HOME=1..4. Purely presentational: all field
 * state lives in main.tsx's Shell and `onSubmit` runs the shared connect flow.
 */
export function PresetLogin(props: PresetLoginProps): JSX.Element {
  const meta = META[props.preset];
  const isEnv = props.preset === 4;

  return (
    <main className="nvoos-connect">
      <h1>
        NV oOS <span>Pro</span> — Standalone
      </h1>
      <span className="nvoos-preset-badge">{meta.label}</span>
      <p className="nvoos-connect-sub">{meta.description}</p>

      <form onSubmit={props.onSubmit} className="nvoos-connect-form">
        {isEnv ? (
          <>
            {props.envConnection && (
              <dl className="nvoos-env-summary">
                <dt>WordPress site</dt>
                <dd>{props.envConnection.siteUrl}</dd>
                <dt>Authentication</dt>
                <dd>{authLabel(props.envConnection)}</dd>
                {props.envConnection.mediaWorkerUrl ? (
                  <>
                    <dt>Media worker</dt>
                    <dd>{props.envConnection.mediaWorkerUrl}</dd>
                  </>
                ) : null}
              </dl>
            )}
            {props.envError && (
              <p className="nvoos-connect-error" role="alert">
                {props.envError}
              </p>
            )}
            {props.autoConnect && props.envConnection && (
              <span className="nvoos-connect-hint">
                Auto-connect is enabled (NVOOS_AUTO_CONNECT=1) — this page usually connects by
                itself. Use Connect to retry after a failure.
              </span>
            )}
          </>
        ) : (
          <>
            <label>
              WordPress site URL
              <input
                type="url"
                required
                placeholder="https://example.com"
                value={props.siteUrl}
                onChange={(e) => props.onSiteUrl(e.target.value)}
              />
            </label>

            {props.preset === 1 && (
              <p className="nvoos-cookie-login">
                <a
                  className="nvoos-login-button"
                  href="/wp-login.php?redirect_to=%2F"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Log in to WordPress
                </a>
                <span className="nvoos-connect-hint">
                  Opens the site login in a new tab. After logging in you return to this app —
                  the session cookie is set on this origin, so Connect works right away.
                </span>
              </p>
            )}

            {props.preset === 2 && (
              <>
                <label>
                  WordPress username
                  <input
                    type="text"
                    autoComplete="username"
                    value={props.wpUsername}
                    onChange={(e) => props.onWpUsername(e.target.value)}
                    placeholder="Login name (not email)"
                  />
                </label>
                <label>
                  Application password (xxxx xxxx xxxx xxxx xxxx xxxx)
                  <input
                    type="password"
                    autoComplete="current-password"
                    value={props.wpAppPassword}
                    onChange={(e) => props.onWpAppPassword(e.target.value)}
                    placeholder="Created in Users → Profile → Application Passwords"
                  />
                </label>
                <span className="nvoos-connect-hint">
                  Use an application password from your WordPress profile — never your regular
                  account password. It authenticates as your user with your real permissions and
                  can be revoked at any time. Prefer a dedicated, least-privilege user for this app.
                </span>
              </>
            )}

            {props.preset === 3 && (
              <label>
                Assistant credential (cred_xxxxx.SECRET)
                <input
                  type="password"
                  value={props.bearer}
                  onChange={(e) => props.onBearer(e.target.value)}
                  placeholder="cred_…"
                />
              </label>
            )}

            {props.workerNote && (
              <span className="nvoos-connect-hint">
                Media worker (from env): {props.workerNote}
              </span>
            )}
          </>
        )}

        {props.error && (
          <p className="nvoos-connect-error" role="alert">
            {props.error}
          </p>
        )}

        <button type="submit" disabled={props.connecting || (isEnv && !props.envConnection)}>
          {props.connecting ? 'Connecting…' : 'Connect'}
        </button>
      </form>
    </main>
  );
}
