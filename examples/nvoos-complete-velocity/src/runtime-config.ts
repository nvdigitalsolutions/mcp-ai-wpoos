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

import {
  backendPaths,
  buildTimeBackend,
  resolveBackend,
  type ConsoleBackend,
} from './backend-profile';

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
 * Fetch the WordPress site title (blogname) from the public REST index
 * (`GET /wp-json/` → `name`). Used for the console's branded nav logo.
 * Fail-open: any failure yields '' and the caller falls back.
 */
export async function fetchSiteName(
  backend: ConsoleBackend = resolveBackend(buildTimeBackend()),
): Promise<string> {
  try {
    const response = await fetch(backendPaths(window.location.origin, backend).site, {
      credentials: 'same-origin',
    });
    if (!response.ok) {
      return '';
    }
    const data = await readJson<{ name?: string }>(response, 'site-name');
    return typeof data.name === 'string' && data.name.trim() ? data.name.trim() : '';
  } catch {
    return '';
  }
}

/**
 * Build the console endpoint map, mirroring the server SPA config's
 * capability gating (WP_MCP_AI_Pro_SPA_Config::build()): admin-gated
 * endpoints are empty strings for non-admin sessions so pages skip the
 * fetch instead of hitting a 403. Keep this matrix in sync with the
 * server and with src/auth/permissions.ts in spa-v2.
 */
function buildEndpoints(origin: string, capabilities: string[], backend: ConsoleBackend): ProSpaEndpoints {
  const isAdmin = capabilities.includes('manage_options');
  const canEdit = capabilities.includes('edit_posts');
  const paths = backendPaths(origin, backend);
  const core = paths.apiBase;
  const pro = paths.proBase;
  const wp = paths.wpBase;
  const wpApi = `${origin}/wp-json`;
  const isWordPress = backend === 'wordpress';
  return {
    chat: `${core}/chat`,
    chatClient: `${core}/chat-client`,
    transcripts: `${core}/chat-transcripts`,
    memory: `${core}/chat-memory`,
    tools: `${core}/tools`,
    assistants: `${core}/assistants`,
    settings: `${core}/settings`,
    upload: isWordPress ? `${wp}/media` : `${core}/media`,
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
    wpBase: wp,
    recordsSchema: isAdmin ? `${pro}/records/schema` : '',
    // The manifest engine is Laravel-only for now; the WordPress runtime has
    // no /toolkits route yet, so the pages render their unavailable state.
    toolkits: isAdmin && !isWordPress ? `${pro}/toolkits` : '',
    dashboardsSchema: isAdmin ? `${pro}/dashboards/schema` : '',
    chatChannels: isAdmin ? `${pro}/chat-channels` : '',
    remoteSites: isAdmin ? `${pro}/remote-sites` : '',
    // Installed Agent Skills catalogue (base route).
    skills: isAdmin ? `${core}/skills` : '',
    // MCP Apps: Pro-only route; empty hides the panel instead of firing 404s.
    mcpApps: isAdmin ? `${core}/mcp-apps` : '',
    mediaCc: isAdmin ? `${pro}/media-cc` : '',
    // Profession catalogue (assistant editor selector) — Laravel-only; the
    // WordPress runtime has no professions REST route, so the editor hides
    // the selector when this is empty.
    professions: isAdmin && !isWordPress ? `${core}/professions` : '',
    // Org teams (users + roles) are Laravel-only for now; WordPress has the
    // agent-team catalogue at `teams` instead. Empty disables the page.
    orgTeams: isAdmin && !isWordPress ? `${core}/org/teams` : '',
    blueprints: canEdit ? `${pro}/blueprints` : '',
    commandCenter: isAdmin ? `${pro}/command-center` : '',
    shortcuts: `${pro}/tool-shortcuts`,
    slashCommands: `${pro}/slash-commands`,
    		okf: isWordPress ? `${pro}/okf` : '',
    		// NV oOS Content Graph (standalone plugin) — the knowledge-graph page
    		// renders an unavailable state when the site doesn't expose it.
    		contentGraph: isWordPress ? `${wpApi}/nvoos-content-graph/v1` : '',
    		// NV oOS Docs Hub — the #/docs page mounts the vendored Docs Hub island
    		// against this local REST base and renders an unavailable state when
    		// the connected site does not expose the plugin (same contract as
    		// contentGraph above).
    		docsHub: isWordPress ? `${wpApi}/nvoos-docs/v1` : '',
    		// NV oOS Media Studio / Document Editor — the #/media and #/documents
    		// pages mount the lazily-loaded islands against these local REST bases
    		// (unconditional like contentGraph: the island's own error UI covers
    		// sites without the addon). Asset URLs come from this app's build.
    		mediaStudio: isWordPress ? `${wpApi}/nvoos-media-studio/v1` : '',
    		mediaStudioAsset: mediaStudioAssetUrl,
    		mediaStudioCss: mediaStudioCssUrl,
    		documentEditor: isWordPress ? `${wpApi}/nvoos-document-editor/v1` : '',
    		documentEditorAsset: documentEditorAssetUrl,
    		documentEditorCss: documentEditorCssUrl,
  };
}

export interface ConsoleRuntimeOptions {
  /** Assistant to preselect. 0 = selector per session. */
  assistantId?: number;
  /** Platform-provisioned default assistant id, used when assistantId is 0. */
  defaultAssistantId?: number;
  /**
   * Backend profile. Defaults to the build-time `VITE_NVOOS_BACKEND`,
   * falling back to `wordpress` (Proposal 090-B, ADR-6).
   */
  backend?: ConsoleBackend;
  nonce?: string;
  user?: Partial<ProSpaUser>;
  /**
   * Server-reported feature availability (GET /mcp-ai/v1/features). Gates the
   * nav and the optional-surface endpoints. Absent = fail open (show).
   */
  features?: Record<string, boolean>;
  /**
   * Server-reported endpoint availability (GET /features → data.endpoints).
   * A key reported `false` blanks that endpoint, so the page renders its
   * unavailable state instead of firing a request that 404s. Absent = fail
   * open (older backends that predate the map).
   */
  endpoints?: Record<string, boolean>;
  /** WordPress site title (blogname) for branding surfaces. */
  siteName?: string;
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
  const backend = resolveBackend(options.backend ?? buildTimeBackend());
  const paths = backendPaths(origin, backend);
  const features = options.features ?? {};

  // Optional surfaces resolve to an empty endpoint when the server reports
  // them unavailable — their pages then render the unavailable state instead
  // of framing a dead URL (and the nav hides them entirely). Admin-gated
  // surfaces were already emptied for non-admin users inside buildEndpoints().
  const endpoints = buildEndpoints(origin, options.user?.capabilities ?? [], backend);
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

  // Which routes this runtime actually serves. An absent route blanks the
  // endpoint rather than letting the page discover the gap as a 404 — the
  // same degradation the feature flags above and the WordPress-only keys in
  // buildEndpoints() already use. Fail-open: no map means "assume present".
  const available = options.endpoints ?? {};
  for (const key of Object.keys(endpoints) as Array<keyof ProSpaEndpoints>) {
    if (available[key] === false) {
      endpoints[key] = '';
    }
  }

  return {
    apiUrl: paths.apiBase,
    proApi: paths.proBase,
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
    siteName: options.siteName ?? '',
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
export async function fetchSessionNonce(
  backend: ConsoleBackend = resolveBackend(buildTimeBackend()),
): Promise<string> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  const paths = backendPaths(base, backend);

  let mintError = '';
  try {
    const response = await fetch(paths.nonce, {
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

  if (backend === 'wordpress') {
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
  }

  throw new Error(
    mintError ||
      (backend === 'wordpress'
        ? 'Could not obtain a wp_rest nonce — the session/nonce endpoint and the wp-admin scrape both failed'
        : 'Could not obtain a session nonce from /api/v1/session/nonce — check that NVOOS_BACKEND=laravel and the Laravel app is reachable.'),
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

export async function fetchCurrentUser(
  nonce = '',
  backend: ConsoleBackend = resolveBackend(buildTimeBackend()),
): Promise<CurrentUserResult> {
  if (backend !== 'wordpress') {
    return fetchLaravelCurrentUser(nonce, backend);
  }
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

/**
 * Laravel session probe (`/api/v1/session/me`). The response is already in the
 * SPA user shape; the nonce travels unchanged and the compatibility middleware
 * validates it against the session token.
 */
async function fetchLaravelCurrentUser(
  nonce: string,
  backend: ConsoleBackend,
): Promise<CurrentUserResult> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  const paths = backendPaths(base, backend);
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (nonce) {
    headers['X-WP-Nonce'] = nonce;
  }
  const response = await fetch(paths.me, { credentials: 'include', headers });
  if (response.status === 401) {
    throw new UnauthorizedError(
      `session/me returned ${response.status} — no Laravel session`,
    );
  }
  if (!response.ok) {
    throw new Error(`Session expired, please login first (${response.status})`);
  }
  const data = await readJson<{
    id?: number;
    login?: string;
    displayName?: string;
    capabilities?: string[];
  }>(response, 'session/me');
  return {
    user: {
      id: data.id ?? 0,
      login: data.login ?? '',
      displayName: data.displayName ?? '',
      capabilities: Array.isArray(data.capabilities) ? data.capabilities : [],
    },
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
 * Server-reported availability, in one request.
 *
 * `features` gates optional surfaces (docs hub, knowledge graph, …) and
 * `endpoints` says which routes this runtime actually serves. Both fail open:
 * on any failure the maps are empty, so nothing is hidden by a transient
 * error — the server still enforces every capability.
 */
export interface RuntimeAvailability {
  features: Record<string, boolean>;
  endpoints: Record<string, boolean>;
}

/**
 * Fetch the availability maps (GET /mcp-ai/v1/features).
 *
 * Fail-open by design: on any failure (older plugin without the endpoint,
 * proxy hiccup, non-JSON response) empty maps are returned so a nav item or
 * endpoint is never hidden by a transient error — the feature pages render
 * their own unavailable states, and the server still enforces every
 * capability.
 */
export async function fetchRuntimeAvailability(
  nonce = '',
  backend: ConsoleBackend = resolveBackend(buildTimeBackend()),
): Promise<RuntimeAvailability> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  const empty: RuntimeAvailability = { features: {}, endpoints: {} };

  try {
    const headers: Record<string, string> = {};
    if (nonce) {
      headers['X-WP-Nonce'] = nonce;
    }
    const response = await fetch(backendPaths(base, backend).features, {
      credentials: 'include',
      headers,
    });
    if (!response.ok) {
      console.warn(
        `features endpoint returned ${response.status} — showing all nav items`,
      );
      return empty;
    }
    const data = await readJson<{
      success?: boolean;
      data?: {
        features?: Record<string, boolean>;
        endpoints?: Record<string, boolean>;
      };
    }>(response, 'features endpoint');

    const features = data?.data?.features;
    const endpoints = data?.data?.endpoints;

    return {
      features: isBooleanMap(features) ? features : {},
      endpoints: isBooleanMap(endpoints) ? endpoints : {},
    };
  } catch (error) {
    console.warn(
      `features fetch failed (${error instanceof Error ? error.message : String(error)}) — showing all nav items`,
    );
    return empty;
  }
}

/**
 * Fetch the server-side feature-availability map only.
 *
 * Kept for callers and tests that predate the endpoint map; prefer
 * {@see fetchRuntimeAvailability} so the availability read happens once.
 */
export async function fetchFeatureMap(
  nonce = '',
  backend: ConsoleBackend = resolveBackend(buildTimeBackend()),
): Promise<Record<string, boolean>> {
  return (await fetchRuntimeAvailability(nonce, backend)).features;
}

function isBooleanMap(value: unknown): value is Record<string, boolean> {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Startup contract assertion (Proposal 090-B, ADR-6).
 *
 * The console must not render a Laravel-backed admin against a WordPress
 * origin (or vice versa) — every page would show an empty state with no
 * actionable error. This probe fails the boot with a precise message when the
 * backend contract is missing or mismatched. WordPress is a no-op: its
 * contract is the plugin version, not a JSON envelope.
 */
export async function assertBackendContract(
  backend: ConsoleBackend = resolveBackend(buildTimeBackend()),
): Promise<void> {
  if (backend === 'wordpress') {
    return;
  }
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  const paths = backendPaths(base, backend);
  const response = await fetch(paths.features, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  });
  if (!response.ok) {
    throw new Error(
      `Backend contract assertion failed: ${paths.features} returned ${response.status}. ` +
        'Check NVOOS_BACKEND and that the Laravel app is reachable through the proxy.',
    );
  }
  const payload = await readJson<{
    success?: boolean;
    data?: {
      backend?: string;
      contract?: string;
      contract_version?: number;
    };
  }>(response, 'backend contract');
  const contract = payload?.data;
  if (
    !contract ||
    contract.backend !== 'laravel' ||
    contract.contract !== 'mcp-ai/v1' ||
    contract.contract_version !== 1
  ) {
    throw new Error(
      `Backend contract mismatch: expected laravel / mcp-ai/v1 / v1, got ${JSON.stringify(
        contract ?? {},
      )}.`,
    );
  }
}
