/**
 * Global fetch wrapper for the standalone SPA.
 *
 * The spa-v2 sources build their own fetch calls with `credentials:
 * 'same-origin'` and send `X-WP-Nonce` / `X-WP-MCP-AI-Guest` where needed,
 * but they never send an assistant credential. Wrapping `window.fetch` adds
 * the `Authorization: Bearer cred_…` header to every request — including the
 * SSE chat stream, which `@microsoft/fetch-event-source` opens through the
 * global fetch as well. Zero changes to the SPA sources.
 */

export interface FetchWrapperOptions {
  bearer?: string;
  /** Pre-encoded `Basic …` header value for WordPress Application Password auth. */
  basic?: string;
  /**
   * Origin of the WordPress site the credentials belong to. The Basic header
   * is attached only to requests targeting this origin — never to the media
   * worker or any third-party URL. (Bearer keeps its pre-existing
   * every-request behavior; scoping it is tracked as deferred hardening in
   * proposal 064.)
   */
  siteOrigin?: string;
  /** When true and no token exists yet, send the legacy guest flag. */
  guest?: boolean;
  guestToken?: string;
  /**
   * Design Stack media worker origin + site token. Requests to this origin
   * get the X-Site-Token header (the worker's strict-mode auth).
   */
  mediaWorkerUrl?: string;
  mediaWorkerToken?: string;
}

export function installFetchWrapper(options: FetchWrapperOptions): () => void {
  const original = window.fetch.bind(window);
  const workerOrigin = options.mediaWorkerUrl?.trim()
    ? new URL(options.mediaWorkerUrl.trim()).origin
    : '';

  window.fetch = (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const headers = new Headers(init?.headers);
    const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;

    // Scope credentials to the configured WordPress site. Absolute request
    // URLs are built from runtime.apiUrl, so they all share the site origin
    // (including the SSE stream opened through this global fetch); anything
    // else (worker, external URLs) must never see the WP credential.
    let targetOrigin = '';
    try {
      targetOrigin = new URL(url, window.location.origin).origin;
    } catch {
      // Unparseable URLs (blob:, data:) get no credential header.
    }
    const isSiteRequest = options.siteOrigin !== undefined && targetOrigin === options.siteOrigin;

    if (options.basic && isSiteRequest && !headers.has('Authorization')) {
      headers.set('Authorization', options.basic);
    }
    if (options.bearer && !headers.has('Authorization')) {
      headers.set('Authorization', `Bearer ${options.bearer}`);
    }
    if (options.guest && !headers.has('X-WP-MCP-AI-Guest')) {
      headers.set('X-WP-MCP-AI-Guest', options.guestToken || '1');
    }
    if (workerOrigin && url.startsWith(workerOrigin) && !headers.has('X-Site-Token')) {
      headers.set('X-Site-Token', options.mediaWorkerToken ?? '');
    }

    // The SPA sets `X-WP-Nonce` unconditionally — with an empty value when no
    // cookie nonce exists. The plugin's REST layer rejects bearer/guest/wp
    // requests that carry an empty nonce header, so drop it for token auth.
    if ((options.bearer || options.guest || options.basic) && headers.get('X-WP-Nonce') === '') {
      headers.delete('X-WP-Nonce');
    }

    return original(input, { ...init, headers });
  };

  return () => {
    window.fetch = original;
  };
}
