/**
 * Runtime configuration builder for the standalone Pro SPA.
 *
 * Mirrors the shape produced by the plugin's PHP loader
 * (addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php) so the SPA's
 * readProSpaConfig() accepts it unchanged. The endpoint set follows the
 * plugin's REST conventions for any WordPress site running NV oOS:
 *
 *   {site}/wp-json/mcp-ai/v1/{chat,chat-client,chat-transcripts,chat-memory,
 *     threads,tools,assistants,settings,approvals}
 *   {site}/wp-json/mcp-ai-pro/v1/{workflows,analytics,tool-shortcuts,
 *     slash-commands,okf}
 *   {site}/wp-json/wp/v2/media
 *   {site}/wp-json/mcp-ai/v1/session/nonce   (fresh wp_rest nonce, cookie auth)
 */

import type { ProSpaRuntime, ProSpaUser, ProSpaEndpoints } from '@nvoos/pro-spa-v2/api/config';

export type AuthMode = 'cookie' | 'bearer' | 'guest' | 'wp';

export interface ConnectionSettings {
  /** Base URL of the WordPress site, e.g. https://example.com (no trailing slash). */
  siteUrl: string;
  auth: AuthMode;
  /** Assistant credential (cred_xxxxx.SECRET) for bearer mode. */
  bearer?: string;
  /** Server-minted guest token for guest mode. */
  guestToken?: string;
  /** WordPress login name for wp mode (Application Password, Basic auth). */
  wpUsername?: string;
  /** WordPress Application Password (xxxx xxxx xxxx xxxx xxxx xxxx) for wp mode. */
  wpAppPassword?: string;
  /** Assistant to chat with (required for guest mode; optional otherwise). */
  assistantId?: number;
  /** Render the full three-column admin surface instead of the chat surface. */
  adminLayout?: boolean;
  /**
   * Optional Design Stack media worker (image/video generation, documents,
   * OCR, crawling…). Requests to this origin get the X-Site-Token header.
   */
  mediaWorkerUrl?: string;
  mediaWorkerToken?: string;
}

function normalizeSiteUrl(raw: string): string {
  const trimmed = raw.trim().replace(/\/+$/, '');
  if (!/^https?:\/\//i.test(trimmed)) {
    throw new Error('Site URL must start with http:// or https://');
  }
  return trimmed;
}

/**
 * Parse a fetch response as JSON, refusing non-JSON payloads.
 *
 * Without this guard a 200 HTML response (SPA fallback page, login redirect,
 * Varnish error page) surfaces as the unhelpful
 * "Unexpected token '<', \"<!doctype \"... is not valid JSON" — which is what
 * happens when cookie mode runs without a proxy in front of this origin.
 */
async function readJson<T>(response: Response, context: string): Promise<T> {
  const contentType = response.headers.get('content-type') ?? '';
  if (!/application\/([a-z0-9.+-]*\+)?json/i.test(contentType)) {
    let snippet = '';
    try {
      snippet = (await response.text()).slice(0, 140).replace(/\s+/g, ' ');
    } catch {
      // Ignore — the content-type already tells us enough.
    }
    const looksLikeHtml =
      contentType.toLowerCase().includes('text/html') ||
      snippet.toLowerCase().startsWith('<!doctype') ||
      snippet.toLowerCase().startsWith('<html');
    throw new Error(
      `${context}: expected JSON but got ${contentType || 'no content-type'} (status ${response.status}).` +
        (snippet ? ` Response starts with: "${snippet}"` : '') +
        (looksLikeHtml
          ? ' This origin is not serving the WordPress REST API — cookie mode needs a proxy in front of the app (dev: `npm run dev:proxy`, production: `scripts/serve.mjs` with NVOOS_TARGET_SITE set).'
          : ''),
    );
  }
  return (await response.json()) as T;
}

/**
 * Probe whether `base` actually serves the WordPress REST API.
 *
 * WordPress REST endpoints always answer with a JSON content type (even for
 * 401/403/404). Any other payload — the SPA's index.html fallback, a plain
 * 404 — means there is no proxy between this origin and WordPress, and
 * cookie mode cannot work. True on any status as long as the body is JSON.
 */
export async function detectCookieProxy(base: string): Promise<boolean> {
  try {
    const response = await fetch(`${base}/wp-json/mcp-ai/v1/session/nonce`, {
      credentials: 'include',
    });
    const contentType = response.headers.get('content-type') ?? '';
    return /application\/([a-z0-9.+-]*\+)?json/i.test(contentType);
  } catch {
    return false;
  }
}

function buildEndpoints( origin: string ): ProSpaEndpoints {
	const api = `${origin}/wp-json`;
	const core = `${api}/mcp-ai/v1`;
	const pro = `${api}/mcp-ai-pro/v1`;
	return {
		chat: `${core}/chat`,
		chatClient: `${core}/chat-client`,
		transcripts: `${core}/chat-transcripts`,
		memory: `${core}/chat-memory`,
		threads: `${core}/threads`,
		tools: `${core}/tools`,
		assistants: `${core}/assistants`,
		settings: `${core}/settings`,
		upload: `${api}/wp/v2/media`,
		workflows: `${pro}/workflows`,
		analytics: `${pro}/analytics`,
		approvals: `${core}/approvals`,
		shortcuts: `${pro}/tool-shortcuts`,
		slashCommands: `${pro}/slash-commands`,
		okf: `${pro}/okf`,
		// NV oOS Content Graph (standalone plugin) — the knowledge-graph page
		// renders an unavailable state when the site doesn't expose it.
		contentGraph: `${api}/nvoos-content-graph/v1`,
	};
}

export interface RuntimeBuildOptions {
  /**
   * When true, endpoints point at the dev server's own origin (the Vite proxy
   * forwards them to NVOOS_TARGET_SITE) so cookie + nonce auth is same-origin.
   */
  viaProxy: boolean;
  nonce?: string;
  user?: Partial<ProSpaUser>;
}

export function buildRuntime(
  connection: ConnectionSettings,
  options: RuntimeBuildOptions,
): ProSpaRuntime {
  const siteUrl = normalizeSiteUrl(connection.siteUrl);
  const origin = options.viaProxy && typeof window !== 'undefined' ? window.location.origin : siteUrl;

  const isGuest = connection.auth === 'guest';
  const embedded = isGuest || !connection.adminLayout;

  return {
    apiUrl: `${origin}/wp-json/mcp-ai/v1`,
    proApi: `${origin}/wp-json/mcp-ai-pro/v1`,
    nonce: isGuest ? '' : options.nonce ?? '',
    config: {
      assistantId: connection.assistantId ?? 0,
      theme: 'auto',
      allowSensitiveTools: false,
      mode: embedded ? 'embedded' : 'admin',
      height: '',
      showSidebar: true,
      assistantSelector: false,
      cronMonitor: true,
      routes: ['chat'],
      profileSelector: false,
      ...(isGuest ? { guest: true, guestToken: connection.guestToken ?? '' } : {}),
    },
    endpoints: buildEndpoints(origin),
    user: {
      id: options.user?.id ?? 0,
      login: options.user?.login ?? '',
      displayName: options.user?.displayName ?? '',
      capabilities: options.user?.capabilities ?? [],
      ...(connection.assistantId ? { assistant_id: connection.assistantId } : {}),
    },
    mentionTypes: [],
    assistants: undefined,
    profiles: undefined,
  };
}

/**
 * Build an `Authorization: Basic …` header value from a WordPress username and
 * Application Password.
 *
 * Encodes UTF-8 via TextEncoder before base64 — `btoa()` alone throws
 * `InvalidCharacterError` on non-Latin-1 code points (RFC 7617 §2.1 defines
 * the user-pass as ISO-8859-1 by default, but WP stores UTF-8; the encoder
 * path is correct for both). The password is never logged or echoed.
 */
export function buildBasicAuthHeader(username: string, appPassword: string): string {
  const bytes = new TextEncoder().encode(`${username}:${appPassword}`);
  let binary = '';
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }
  return `Basic ${btoa(binary)}`;
}

/**
 * Validate WordPress Application Password credentials and fetch the real user.
 *
 * `GET /wp/v2/users/me?context=edit` returns the authenticated user (id,
 * name, slug) and — in `edit` context only — the full capability map.
 * Stateless: `credentials: 'omit'` (no cookie session) and no nonce
 * (Application Password auth is CSRF-safe by design).
 */
export async function fetchCurrentUserBasic(
  base: string,
  username: string,
  appPassword: string,
): Promise<Partial<ProSpaUser>> {
  const response = await fetch(`${base}/wp-json/wp/v2/users/me?context=edit`, {
    credentials: 'omit',
    headers: { Authorization: buildBasicAuthHeader(username, appPassword) },
  });
  if (!response.ok) {
    if (response.status === 401 || response.status === 403) {
      throw new Error(
        `WordPress rejected the username or application password (HTTP ${response.status}). ` +
          'Create an application password under Users → Profile → Application Passwords.',
      );
    }
    throw new Error(`wp/v2/users/me returned ${response.status}`);
  }
  const data = (await response.json()) as {
    id?: number;
    name?: string;
    slug?: string;
    capabilities?: Record<string, boolean>;
  };
  return {
    id: data.id ?? 0,
    login: data.slug ?? '',
    displayName: data.name ?? '',
    capabilities: Object.entries(data.capabilities ?? {})
      .filter(([, enabled]) => enabled)
      .map(([cap]) => cap),
  };
}

/**
 * Resolve a fresh `wp_rest` nonce for the logged-in session.
 *
 * Preferred source: the nonce WordPress embeds in the wp-admin page
 * (`wpApiSettings.nonce`), fetched same-origin through the Vite proxy.
 * On hardened installs the plugin's `session/nonce` endpoint can mint an
 * unauthenticated nonce (its handler runs without the session user), which
 * WordPress then rejects — so the endpoint is only a fallback here.
 */
export async function fetchSessionNonce(base: string): Promise<string> {
  try {
    const admin = await fetch(`${base}/wp-admin/`, { credentials: 'include' });
    if (admin.ok) {
      const html = await admin.text();
      const match = html.match(/"nonce":"([a-f0-9]{10})"/);
      if (match?.[1]) {
        return match[1];
      }
    }
  } catch {
    // Fall through to the REST endpoint.
  }

  const response = await fetch(`${base}/wp-json/mcp-ai/v1/session/nonce`, {
    credentials: 'include',
  });
  if (!response.ok) {
    throw new Error(`nonce endpoint returned ${response.status}`);
  }
  const data = await readJson<{ nonce?: string; success?: boolean }>(
    response,
    'nonce endpoint',
  );
  if (typeof data?.nonce !== 'string' || !data.nonce) {
    throw new Error('nonce endpoint did not return a nonce');
  }
  return data.nonce;
}

/**
 * Ping the media worker's public health endpoint when configured.
 * Returns the worker's reported version, or null when no worker is set.
 */
export async function checkMediaWorker(connection: ConnectionSettings): Promise<string | null> {
  if (!connection.mediaWorkerUrl?.trim()) {
    return null;
  }
  const url = `${connection.mediaWorkerUrl.trim().replace(/\/+$/, '')}/api/health`;
  const headers: Record<string, string> = {};
  if (connection.mediaWorkerToken?.trim()) {
    headers['X-Site-Token'] = connection.mediaWorkerToken.trim();
  }
  const response = await fetch(url, { headers });
  if (!response.ok) {
    throw new Error(`Media worker health check returned ${response.status}`);
  }
  const data = await readJson<{ version?: string; status?: string }>(
    response,
    'media worker health check',
  );
  return typeof data?.version === 'string' ? data.version : (data?.status ?? 'ok');
}

/** Fetch the logged-in user via WordPress core (cookie auth + nonce). */
export async function fetchCurrentUser(base: string, nonce = ''): Promise<Partial<ProSpaUser>> {
  const headers: Record<string, string> = {};
  if (nonce) {
    headers['X-WP-Nonce'] = nonce;
  }
  const response = await fetch(`${base}/wp-json/wp/v2/users/me?context=edit`, {
    credentials: 'include',
    headers,
  });
  if (!response.ok) {
    throw new Error(`wp/v2/users/me returned ${response.status} — log into WordPress first`);
  }
  const data = await readJson<{
    id?: number;
    name?: string;
    slug?: string;
    capabilities?: Record<string, boolean>;
  }>(response, 'wp/v2/users/me');
  return {
    id: data.id ?? 0,
    login: data.slug ?? '',
    displayName: data.name ?? '',
    capabilities: Object.entries(data.capabilities ?? {})
      .filter(([, enabled]) => enabled)
      .map(([cap]) => cap),
  };
}
