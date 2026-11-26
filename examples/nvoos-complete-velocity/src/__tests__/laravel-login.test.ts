// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Laravel login/logout path tests (Proposal 090-B Phase 1).
 *
 * Forces `VITE_NVOOS_BACKEND=laravel` so the shared submit/logout entry points
 * take the Laravel branch, then asserts the nonce + session POST sequence.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';

import { logoutFromWordPress, submitWpLogin } from '../login';

const jsonResponse = (body: unknown, status = 200): Response =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('submitWpLogin (laravel)', () => {
  it('fetches a nonce then posts credentials to /api/v1/session/login', async () => {
    vi.stubGlobal('__NVOOS_BACKEND__', 'laravel');
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ success: true, nonce: 'nonce-1' }))
      .mockResolvedValueOnce(jsonResponse({ id: 1, capabilities: ['read'] }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await submitWpLogin({ username: 'owner@example.com', password: 'secret' });

    expect(result.ok).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/session/nonce');

    const [url, init] = fetchMock.mock.calls[1] as [string, RequestInit];
    expect(url).toBe('/api/v1/session/login');
    // Laravel validates the raw session token in `X-CSRF-TOKEN`. The
    // `X-WP-Nonce` translation shim was removed with AcceptWpNonce, so a bare
    // `X-WP-Nonce` is a 419 — which is what the CI e2e smoke first surfaced.
    // Asserting the old header here pinned the client's assumption instead of
    // the server's contract, so the suite stayed green while sign-in was dead.
    const headers = init.headers as Record<string, string>;
    expect(headers['X-CSRF-TOKEN']).toBe('nonce-1');
    expect(headers['X-WP-Nonce']).toBeUndefined();
    expect(JSON.parse(String(init.body))).toEqual({
      email: 'owner@example.com',
      password: 'secret',
      remember: false,
    });
  });

  it('returns the uniform error on a 401', async () => {
    vi.stubGlobal('__NVOOS_BACKEND__', 'laravel');
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ success: true, nonce: 'nonce-1' }))
      .mockResolvedValueOnce(jsonResponse({ success: false }, 401));
    vi.stubGlobal('fetch', fetchMock);

    const result = await submitWpLogin({ username: 'owner@example.com', password: 'bad' });

    expect(result.ok).toBe(false);
    expect(result.message).toBe('Invalid username or password.');
  });

  it('surfaces a 419 as unavailable rather than invalid credentials', async () => {
    // A 419 means the CSRF alias did not match — a configuration or contract
    // fault, not a wrong password. Telling the user their password is wrong
    // would send them down the wrong path, and (per OWASP A07) leaking the
    // distinction is not useful here anyway.
    vi.stubGlobal('__NVOOS_BACKEND__', 'laravel');
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ success: true, nonce: 'nonce-1' }))
      .mockResolvedValueOnce(jsonResponse({ message: 'CSRF token mismatch.' }, 419));
    vi.stubGlobal('fetch', fetchMock);

    const result = await submitWpLogin({ username: 'owner@example.com', password: 'secret' });

    expect(result.ok).toBe(false);
    expect(result.message).toBe('Sign-in is temporarily unavailable. Please try again.');
  });
});

describe('logoutFromWordPress (laravel)', () => {
  it('posts to /api/v1/session/logout with the nonce', async () => {
    vi.stubGlobal('__NVOOS_BACKEND__', 'laravel');
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ success: true, nonce: 'nonce-2' }))
      .mockResolvedValueOnce(jsonResponse({ success: true }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await logoutFromWordPress();

    expect(result.ok).toBe(true);
    const [url, init] = fetchMock.mock.calls[1] as [string, RequestInit];
    expect(url).toBe('/api/v1/session/logout');
    const headers = init.headers as Record<string, string>;
    expect(headers['X-CSRF-TOKEN']).toBe('nonce-2');
    expect(headers['X-WP-Nonce']).toBeUndefined();
  });
});
