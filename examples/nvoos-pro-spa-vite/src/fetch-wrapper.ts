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
  /** When true and no token exists yet, send the legacy guest flag. */
  guest?: boolean;
  guestToken?: string;
}

export function installFetchWrapper(options: FetchWrapperOptions): () => void {
  const original = window.fetch.bind(window);

  window.fetch = (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const headers = new Headers(init?.headers);

    if (options.bearer && !headers.has('Authorization')) {
      headers.set('Authorization', `Bearer ${options.bearer}`);
    }
    if (options.guest && !headers.has('X-WP-MCP-AI-Guest')) {
      headers.set('X-WP-MCP-AI-Guest', options.guestToken || '1');
    }

    // The SPA sets `X-WP-Nonce` unconditionally — with an empty value when no
    // cookie nonce exists. The plugin's REST layer rejects bearer/guest
    // requests that carry an empty nonce header, so drop it for token auth.
    if ((options.bearer || options.guest) && headers.get('X-WP-Nonce') === '') {
      headers.delete('X-WP-Nonce');
    }

    return original(input, { ...init, headers });
  };

  return () => {
    window.fetch = original;
  };
}
