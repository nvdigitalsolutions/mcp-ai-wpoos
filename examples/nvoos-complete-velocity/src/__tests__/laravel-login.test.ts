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
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBe('nonce-1');
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
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBe('nonce-2');
  });
});
