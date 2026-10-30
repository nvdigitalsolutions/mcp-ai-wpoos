/**
 * Runtime configuration builder for the NV oOS Complete Console.
 *
 * Fixed cookie-auth console (no connection screen): the browser talks to
 * this app's own origin, where scripts/serve.mjs proxies WordPress
 * (/wp-json, /wp-admin, /wp-login.php, /wp-includes) — the BFF pattern.
 * Builds the `window.NVOOS_PRO_SPA` global spa-v2's readProSpaConfig()
 * expects. Ported from examples/nvoos-pro-spa-vite/src/runtime-config.ts
 * with the connection-screen surface removed; keep the endpoint set in sync
 * with that file's buildEndpoints().
 */

import type { ProSpaRuntime, ProSpaUser, ProSpaEndpoints } from '@nvoos/pro-spa-v2/api/config';

// Island bundles for Media Studio / Document Editor (Proposal 087 Phase 3).
// Vite copies these self-contained IIFE bundles into the console build and
// rewrites the imports to hashed asset URLs; the page components load them
// lazily via spa-v2's utils/island-assets.ts. Same imports as the standalone
// app's runtime-config.ts — keep them in sync.
import mediaStudioAssetUrl from '../../../addons/media-studio/assets/dist/media-studio.js?url';
import mediaStudioCssUrl from '../../../addons/media-studio/assets/dist/media-studio.css?url';
import documentEditorAssetUrl from '../../../addons/document-editor/assets/dist/document-editor.js?url';
import documentEditorCssUrl from '../../../addons/document-editor/assets/dist/document-editor.css?url';

/**
 * Parse a fetch response as JSON, refusing non-JSON payloads. Without this
 * guard a 200 HTML response (SPA fallback, login redirect, Varnish error)
 * surfaces as "Unexpected token '<'..." — which is what happens when cookie
 * mode runs without a proxy in front of this origin.
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
          ? ' This origin is not serving the WordPress REST API — cookie auth needs the proxy in front of the app (dev: NVOOS_TARGET_SITE, production: scripts/serve.mjs).'
          : ''),
    );
  }
  return (await response.json()) as T;
}

/**
 * Build the console endpoint map, mirroring the server SPA config's
 * capability gating (WP_MCP_AI_Pro_SPA_Config::build()): admin-gated
 * endpoints are empty strings for non-admin sessions so pages skip the
 * fetch instead of hitting a 403. Keep this matrix in sync with the
 * server and with src/auth/permissions.ts in spa-v2.
 */
function buildEndpoints(origin: string, capabilities: string[]): ProSpaEndpoints {
  const isAdmin = capabilities.includes('manage_options');
  const canEdit = capabilities.includes('edit_posts');
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
    workflows: isAdmin ? `${pro}/workflows` : '',
    analytics: isAdmin ? `${core}/analytics` : '',
    cost: isAdmin ? `${core}/cost` : '',
    approvals: isAdmin ? `${core}/approvals` : '',
    triggers: isAdmin ? `${core}/orchestration/triggers` : '',
    teams: isAdmin ? `${core}/teams` : '',
    portability: isAdmin ? `${core}/assistants` : '',
    security: isAdmin ? `${core}/security` : '',
    operations: isAdmin ? `${core}/operations` : '',
    diagnostics: isAdmin ? `${core}/diagnostics` : '',
    paperStore: isAdmin ? `${core}/paper-store` : '',
    tokenManager: isAdmin ? `${core}/token-manager` : '',
    orchestrationStats: isAdmin ? `${core}/orchestration/stats` : '',
    proOps: isAdmin ? `${pro}/ops` : '',
    scheduleManager: isAdmin ? `${pro}` : '',
    workflowBuilder: isAdmin ? `${core}/orchestration/workflows` : '',
    workflowPresets: isAdmin ? `${pro}/workflow-presets` : '',
    workflowNodes: isAdmin ? `${pro}/workflow-builder` : '',
    workflowRuns: isAdmin ? `${core}/orchestration/runs` : '',
    wpBase: `${api}/wp/v2`,
    recordsSchema: isAdmin ? `${pro}/records/schema` : '',
    dashboardsSchema: isAdmin ? `${pro}/dashboards/schema` : '',
    chatChannels: isAdmin ? `${pro}/chat-channels` : '',
    remoteSites: isAdmin ? `${pro}/remote-sites` : '',
    mediaCc: isAdmin ? `${pro}/media-cc` : '',
    blueprints: canEdit ? `${pro}/blueprints` : '',
    commandCenter: isAdmin ? `${pro}/command-center` : '',
    shortcuts: `${pro}/tool-shortcuts`,
    slashCommands: `${pro}/slash-commands`,
    		okf: `${pro}/okf`,
    		// NV oOS Content Graph (standalone plugin) — the knowledge-graph page
    		// renders an unavailable state when the site doesn't expose it.
    		contentGraph: `${api}/nvoos-content-graph/v1`,
    		// NV oOS Docs Hub — the #/docs page mounts the vendored Docs Hub island
    		// against this local REST base and renders an unavailable state when
    		// the connected site does not expose the plugin (same contract as
    		// contentGraph above).
    		docsHub: `${api}/nvoos-docs/v1`,
    		// NV oOS Media Studio / Document Editor — the #/media and #/documents
    		// pages mount the lazily-loaded islands against these local REST bases
    		// (unconditional like contentGraph: the island's own error UI covers
    		// sites without the addon). Asset URLs come from this app's build.
    		mediaStudio: `${api}/nvoos-media-studio/v1`,
    		mediaStudioAsset: mediaStudioAssetUrl,
    		mediaStudioCss: mediaStudioCssUrl,
    		documentEditor: `${api}/nvoos-document-editor/v1`,
    		documentEditorAsset: documentEditorAssetUrl,
    		documentEditorCss: documentEditorCssUrl,
  };
}

export interface ConsoleRuntimeOptions {
  /** Assistant to preselect. 0 = selector per session. */
  assistantId?: number;
  /** Platform-provisioned default assistant id, used when assistantId is 0. */
  defaultAssistantId?: number;
  nonce?: string;
  user?: Partial<ProSpaUser>;
  /**
   * Server-reported feature availability (GET /mcp-ai/v1/features). Gates the
   * nav and the optional-surface endpoints. Absent = fail open (show).
   */
  features?: Record<string, boolean>;
}

/**
 * Build the spa-v2 runtime for the fixed cookie-auth console surface.
 * Origin is always this app's own origin (viaProxy) — serve.mjs (production)
 * or the Vite dev proxy forwards /wp-json to the WordPress target.
 */
export function buildConsoleRuntime(options: ConsoleRuntimeOptions = {}): ProSpaRuntime {
  const origin =
    typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
  const assistantId = options.assistantId ?? options.defaultAssistantId ?? 0;
  const features = options.features ?? {};

  // Optional surfaces resolve to an empty endpoint when the server reports
  // them unavailable — their pages then render the unavailable state instead
  // of framing a dead URL (and the nav hides them entirely). Admin-gated
  // surfaces were already emptied for non-admin users inside buildEndpoints().
  const endpoints = buildEndpoints(origin, options.user?.capabilities ?? []);
  if (features.docs_hub === false) {
    endpoints.docsHub = '';
  }
  if (features.knowledge_graph === false) {
    endpoints.contentGraph = '';
  }
  if (features.media_studio === false) {
    endpoints.mediaStudio = '';
  }
  	if ( features.document_editor === false ) {
  		endpoints.documentEditor = '';
  	}
  	if ( features.okf === false ) {
  		endpoints.okf = '';
  	}

  return {
    apiUrl: `${origin}/wp-json/mcp-ai/v1`,
    proApi: `${origin}/wp-json/mcp-ai-pro/v1`,
    nonce: options.nonce ?? '',
    config: {
      assistantId,
      theme: 'auto',
      allowSensitiveTools: false,
      mode: 'admin',
      height: '',
      showSidebar: true,
      // "Both" per Proposal 083 §6.2: a platform-provisioned default assistant
      // may be pinned, and the per-session selector stays available.
      assistantSelector: true,
      cronMonitor: true,
      routes: ['chat'],
      profileSelector: false,
    },
    endpoints,
    user: {
      id: options.user?.id ?? 0,
      login: options.user?.login ?? '',
      displayName: options.user?.displayName ?? '',
      capabilities: options.user?.capabilities ?? [],
    },
    mentionTypes: [],
    assistants: undefined,
    profiles: undefined,
    features,
  };
}

/**
 * Resolve a fresh `wp_rest` nonce for the logged-in session.
 *
 * Preferred source: the plugin's `session/nonce` endpoint, which mints the
 * nonce server-side for the session identified by the request's own auth
 * cookie — it cannot go stale. The wp-admin scrape is only a fallback: a
 * cached `/wp-admin/` or `/dashboard/` page embeds a nonce minted for
 * whichever session rendered the cached copy, and adopting it makes every
 * authenticated REST call fail with 403 `rest_cookie_invalid_nonce`
 * ("Cookie check failed").
 */
export async function fetchSessionNonce(): Promise<string> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';

  let mintError = '';
  try {
    const response = await fetch(`${base}/wp-json/mcp-ai/v1/session/nonce`, {
      credentials: 'include',
    });
    if (response.ok) {
      const data = await readJson<{ nonce?: string; success?: boolean }>(
        response,
        'nonce endpoint',
      );
      if (typeof data?.nonce === 'string' && data.nonce) {
        return data.nonce;
      }
    }
  } catch (error) {
    // Keep the reason (e.g. an HTML challenge page) for the final error.
    mintError = error instanceof Error ? error.message : String(error);
  }

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
    // Fall through to the error below.
  }

  throw new Error(
    mintError ||
      'Could not obtain a wp_rest nonce — the session/nonce endpoint and the wp-admin scrape both failed',
  );
}

/**
 * The session endpoints answered 401 — the visitor has no WordPress session
 * (or it expired). The console answers this with its own sign-in screen
 * instead of a link to the proxied wp-login.php page, so the boot gate can
 * tell "not signed in" apart from infrastructure failures.
 */
export class UnauthorizedError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'UnauthorizedError';
  }
}

/**
 * Fetch the logged-in user via WordPress core (cookie auth + nonce).
 *
 * The edit context is requested because it is the only one that includes
 * `capabilities` (which drive the console's nav gating). It can answer 403
 * in two ways that still leave a usable session:
 *
 *  - The cookie is valid but the nonce was minted for a different session
 *    token (stale page HTML, full-page cache, session rotation) — core
 *    answers `rest_cookie_invalid_nonce` with 403.
 *  - A hardened install denies the edit context to roles that lack
 *    `list_users` (e.g. authors) even though the session is valid.
 *
 * In both cases the browser may still hold a valid session, so a 403 falls
 * back to re-minting the nonce from the auth cookie and retrying the
 * default (view) context, which any logged-in role can read. Only a second
 * 401/403 counts as "no WordPress session" and routes to the sign-in
 * screen.
 */
/**
 * Result of the current-user fetch: the mapped user plus the nonce the
 * caller must adopt for subsequent authenticated requests.
 */
export interface CurrentUserResult {
  user: Partial<ProSpaUser>;
  /**
   * Nonce to use for subsequent authenticated requests. Differs from the
   * nonce passed in when the 403 fallback minted a fresh session-bound
   * nonce — callers must use this value, not the original one.
   */
  nonce: string;
}

export async function fetchCurrentUser(nonce = ''): Promise<CurrentUserResult> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  const headers: Record<string, string> = {};
  if (nonce) {
    headers['X-WP-Nonce'] = nonce;
  }
  const response = await fetch(`${base}/wp-json/wp/v2/users/me?context=edit`, {
    credentials: 'include',
    headers,
  });
  if (response.status === 401) {
    throw new UnauthorizedError(
      `wp/v2/users/me returned ${response.status} — no WordPress session`,
    );
  }
  if (response.status === 403) {
    return fetchCurrentUserFallback(nonce);
  }
  if (!response.ok) {
    throw new Error('Session expired, please login first');
  }
  return {
    user: mapCurrentUser(
      await readJson<CurrentUserPayload>(response, 'wp/v2/users/me'),
    ),
    nonce,
  };
}

/** Raw `wp/v2/users/me` payload — the edit and view contexts share this shape. */
interface CurrentUserPayload {
  id?: number;
  name?: string;
  slug?: string;
  capabilities?: Record<string, boolean>;
}

/** Map a core user payload onto the SPA user shape (capabilities absent in view context). */
function mapCurrentUser(data: CurrentUserPayload): Partial<ProSpaUser> {
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
 * Retry `users/me` with a fresh session-bound nonce and the view context
 * after the edit-context request answered 403.
 *
 * Prefers a nonce re-minted from the auth cookie (see mintSessionNonce) so
 * a stale-nonce 403 recovers; when minting is unavailable the previously
 * rejected nonce is reused, which still recovers the role-gated edit
 * context (the nonce was fine, the context was the problem).
 */
async function fetchCurrentUserFallback(previousNonce: string): Promise<CurrentUserResult> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  const nonce = (await mintSessionNonce()) || previousNonce;
  const response = await fetch(`${base}/wp-json/wp/v2/users/me`, {
    credentials: 'include',
    headers: nonce ? { 'X-WP-Nonce': nonce } : {},
  });
  if (response.status === 401 || response.status === 403) {
    throw new UnauthorizedError(
      `wp/v2/users/me returned ${response.status} — no WordPress session`,
    );
  }
  if (!response.ok) {
    throw new Error('Session expired, please login first');
  }
  const user = mapCurrentUser(
    await readJson<CurrentUserPayload>(response, 'wp/v2/users/me'),
  );

  // The view context omits `capabilities` (core gates them behind
  // list_users), so recover them from the plugin's session/me endpoint —
  // it returns the requesting session's own capability map without that
  // gate. Best-effort: older bundles without the route keep the
  // view-context user (empty capabilities), and the server still enforces
  // every capability either way.
  try {
    const meResponse = await fetch(`${base}/wp-json/mcp-ai/v1/session/me`, {
      credentials: 'include',
      headers: nonce ? { 'X-WP-Nonce': nonce } : {},
    });
    if (meResponse.ok) {
      const me = await readJson<{ capabilities?: Record<string, boolean> }>(
        meResponse,
        'session/me endpoint',
      );
      if (me.capabilities && typeof me.capabilities === 'object') {
        user.capabilities = Object.entries(me.capabilities)
          .filter(([, enabled]) => enabled)
          .map(([cap]) => cap);
      }
    }
  } catch {
    // Capability recovery is optional — never fail the boot over it.
  }

  return {
    user,
    // The recovered nonce must win — it is the one bound to this session.
    nonce,
  };
}

/**
 * Mint a fresh `wp_rest` nonce via the plugin's session endpoint.
 *
 * The endpoint intentionally requires no nonce and no capability: it
 * re-binds the current user from the request's own auth cookie before
 * minting, so a logged-in visitor always receives a nonce that passes
 * core's rest_cookie_check_errors. Returns '' when the endpoint is
 * unavailable so the caller can pick its own fallback.
 */
async function mintSessionNonce(): Promise<string> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  try {
    const response = await fetch(`${base}/wp-json/mcp-ai/v1/session/nonce`, {
      credentials: 'include',
    });
    if (!response.ok) {
      return '';
    }
    const data = await readJson<{ nonce?: string }>(response, 'nonce endpoint');
    return typeof data?.nonce === 'string' ? data.nonce : '';
  } catch {
    return '';
  }
}

/**
 * Fetch the server-side feature-availability map (GET /mcp-ai/v1/features).
 *
 * Fail-open by design: on any failure (older plugin without the endpoint,
 * proxy hiccup, non-JSON response) the empty map is returned so a nav item
 * is never hidden by a transient error — the feature pages render their own
 * unavailable states, and the server still enforces every capability.
 */
export async function fetchFeatureMap(nonce = ''): Promise<Record<string, boolean>> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  try {
    const headers: Record<string, string> = {};
    if (nonce) {
      headers['X-WP-Nonce'] = nonce;
    }
    const response = await fetch(`${base}/wp-json/mcp-ai/v1/features`, {
      credentials: 'include',
      headers,
    });
    if (!response.ok) {
      console.warn(
        `features endpoint returned ${response.status} — showing all nav items`,
      );
      return {};
    }
    const data = await readJson<{
      success?: boolean;
      data?: { features?: Record<string, boolean> };
    }>(response, 'features endpoint');
    const features = data?.data?.features;
    if (!features || typeof features !== 'object' || Array.isArray(features)) {
      return {};
    }
    return features;
  } catch (error) {
    console.warn(
      `features fetch failed (${error instanceof Error ? error.message : String(error)}) — showing all nav items`,
    );
    return {};
  }
}
