/**
 * REST API client layer.
 *
 * Wraps @wordpress/api-fetch with WordPress nonce authentication.
 * All API calls use this client so the nonce is automatically
 * attached to every request.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Initialize the API client with the current WordPress nonce.
 *
 * Call this once on app mount after fetching the nonce from
 * the /wp-json/nvoos-saas/v1/auth/nonce endpoint.
 *
 * @param nonce WordPress REST nonce.
 */
export function initApiClient(nonce: string): void {
  apiFetch.use(apiFetch.createNonceMiddleware(nonce));
  apiFetch.use(apiFetch.createRootURLMiddleware('/wp-json/'));
}

/**
 * Raw API fetch without nonce (for public endpoints).
 */
export async function publicFetch<T = unknown>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(`/wp-json${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...options.headers,
    },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Request failed' }));
    throw new Error(error.message || `HTTP ${response.status}`);
  }

  return response.json();
}

export { apiFetch };
export default apiFetch;
