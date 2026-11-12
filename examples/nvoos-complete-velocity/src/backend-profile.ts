/**
 * Backend profile for the NV oOS Complete Console.
 *
 * One console artifact serves two runtimes (Proposal 090-B, ADR-6):
 *   - `wordpress` (default): the existing same-origin WP proxy.
 *   - `laravel`: `/api/v1` + `/mcp` on the Laravel origin.
 *   - `hybrid`: Laravel API for the console, WordPress for `wpBase` deep-links
 *     during a migration window.
 *
 * The build-time env (`VITE_NVOOS_BACKEND`) selects the profile; the server
 * (`NVOOS_BACKEND` in scripts/serve.mjs) selects which prefixes are proxied.
 * Keep the two in sync — the startup assertion in runtime-config.ts catches a
 * mismatch at boot instead of rendering empty pages.
 */

export type ConsoleBackend = 'wordpress' | 'laravel' | 'hybrid';

/**
 * Normalise a backend value. Anything unrecognised falls back to WordPress so
 * an old deployment cannot accidentally point at a non-existent Laravel API.
 */
export function resolveBackend(value?: string | null): ConsoleBackend {
  const normalized = (value ?? '').trim().toLowerCase();
  if (normalized === 'laravel' || normalized === 'hybrid') {
    return normalized;
  }
  return 'wordpress';
}

/** Read the build-time backend profile (Vite injects `import.meta.env`). */
export function buildTimeBackend(): ConsoleBackend {
  const env = (import.meta as unknown as { env?: Record<string, string | undefined> }).env;
  return resolveBackend(env?.VITE_NVOOS_BACKEND);
}

export interface BackendPaths {
  /** Core REST base (`mcp-ai/v1` on WP, `/api/v1` on Laravel). */
  apiBase: string;
  /** Pro REST base (`mcp-ai-pro/v1` on WP, `/api/v1/pro` on Laravel). */
  proBase: string;
  /** WP-core-shaped base used by the records fallback. */
  wpBase: string;
  nonce: string;
  me: string;
  site: string;
  features: string;
}

/**
 * Resolve the absolute endpoint paths for a backend. Origins are always the
 * console's own origin — the BFF proxy is what reaches the backend.
 */
export function backendPaths(origin: string, backend: ConsoleBackend): BackendPaths {
  if (backend === 'laravel' || backend === 'hybrid') {
    return {
      apiBase: `${origin}/api/v1`,
      proBase: `${origin}/api/v1/pro`,
      // Hybrid keeps the WP core base for T5 deep-links; a pure Laravel
      // runtime has no wp/v2, so records must use the Laravel shim.
      wpBase: backend === 'hybrid' ? `${origin}/wp-json/wp/v2` : `${origin}/api/v1/wp`,
      nonce: `${origin}/api/v1/session/nonce`,
      me: `${origin}/api/v1/session/me`,
      site: `${origin}/api/v1/site`,
      features: `${origin}/api/v1/features`,
    };
  }

  return {
    apiBase: `${origin}/wp-json/mcp-ai/v1`,
    proBase: `${origin}/wp-json/mcp-ai-pro/v1`,
    wpBase: `${origin}/wp-json/wp/v2`,
    nonce: `${origin}/wp-json/mcp-ai/v1/session/nonce`,
    me: `${origin}/wp-json/wp/v2/users/me?context=edit`,
    site: `${origin}/wp-json/`,
    features: `${origin}/wp-json/mcp-ai/v1/features`,
  };
}
