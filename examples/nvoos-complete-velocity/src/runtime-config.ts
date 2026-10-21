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

function buildEndpoints(origin: string): ProSpaEndpoints {
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
    triggers: `${core}/orchestration/triggers`,
    teams: `${core}/teams`,
    portability: `${core}/assistants`,
    security: `${core}/security`,
    operations: `${core}/operations`,
    diagnostics: `${core}/diagnostics`,
    tokenManager: `${core}/token-manager`,
    orchestrationStats: `${core}/orchestration/stats`,
    proOps: `${pro}/ops`,
    scheduleManager: `${pro}`,
    workflowBuilder: `${core}/orchestration/workflows`,
    workflowPresets: `${pro}/workflow-presets`,
    shortcuts: `${pro}/tool-shortcuts`,
    slashCommands: `${pro}/slash-commands`,
    okf: `${pro}/okf`,
    // NV oOS Content Graph (standalone plugin) — the knowledge-graph page
    // renders an unavailable state when the site doesn't expose it.
    contentGraph: `${api}/nvoos-content-graph/v1`,
    // NV oOS Docs Hub — the #/docs page renders this front-end URL in a
    // sandboxed iframe. Defaults to the hosted console docs URL.
    docsHub: 'https://console.nvoos.cloud/docs-hub/',
    // Media Studio / Document Editor — framed via their shortcode pages.
    mediaStudio: `${origin}/media-studio/`,
    documentEditor: `${origin}/document-editor/`,
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
  // of framing a dead URL (and the nav hides them entirely).
  const endpoints = buildEndpoints(origin);
  if (features.docs_hub === false) {
    endpoints.docsHub = '';
  }
  if (features.knowledge_graph === false) {
    endpoints.contentGraph = '';
  }
  if (features.media_studio === false) {
    endpoints.mediaStudio = '';
  }
  if (features.document_editor === false) {
    endpoints.documentEditor = '';
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
 * Preferred source: the nonce WordPress embeds in the wp-admin page
 * (`wpApiSettings.nonce`), fetched same-origin through the proxy. On
 * hardened installs the plugin's `session/nonce` endpoint can mint an
 * unauthenticated nonce, which WordPress then rejects — so the endpoint is
 * only a fallback here.
 */
export async function fetchSessionNonce(): Promise<string> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
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

/** Fetch the logged-in user via WordPress core (cookie auth + nonce). */
export async function fetchCurrentUser(nonce = ''): Promise<Partial<ProSpaUser>> {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  const headers: Record<string, string> = {};
  if (nonce) {
    headers['X-WP-Nonce'] = nonce;
  }
  const response = await fetch(`${base}/wp-json/wp/v2/users/me?context=edit`, {
    credentials: 'include',
    headers,
  });
  if (!response.ok) {
    throw new Error(
      `wp/v2/users/me returned ${response.status} — log into WordPress first`,
    );
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
