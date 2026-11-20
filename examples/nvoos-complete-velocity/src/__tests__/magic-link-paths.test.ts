/**
 * Magic-link paths per backend.
 *
 * The console is one artifact serving two runtimes: the WordPress plugin's
 * `/wp-json/mcp-ai/v1/magic-login/*` and this runtime's
 * `/api/v1/magic-login/*`. Both answer `ok` + `message` and accept
 * `identifier`, so only the path differs — and a hardcoded path would have
 * silently posted to the WordPress proxy on a Laravel deployment.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';

import { confirmMagicLink, requestMagicLink } from '../login';

const jsonResponse = (body: unknown, status = 200): Response =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('requestMagicLink', () => {
  it('posts the identifier to the WordPress route by default', async () => {
    vi.stubGlobal('__NVOOS_BACKEND__', 'wordpress');
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ ok: true, message: 'sent' }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await requestMagicLink('ops@example.com');

    expect(result.ok).toBe(true);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/wp-json/mcp-ai/v1/magic-login/request');
    expect(init.body).toBe(JSON.stringify({ identifier: 'ops@example.com' }));
  });

  it('posts to /api/v1 on a Laravel runtime', async () => {
    vi.stubGlobal('__NVOOS_BACKEND__', 'laravel');
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ ok: true, message: 'sent' }));
    vi.stubGlobal('fetch', fetchMock);

    await requestMagicLink('ops@example.com');

    const [url] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/api/v1/magic-login/request');
  });

  it('relays the uniform message and never invents a different one', async () => {
    vi.stubGlobal('__NVOOS_BACKEND__', 'laravel');
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(jsonResponse({ ok: true, message: 'check your inbox' })),
    );

    const result = await requestMagicLink('ops@example.com');

    expect(result).toEqual({ ok: true, message: 'check your inbox' });
  });
});

describe('confirmMagicLink', () => {
  it('posts the token to the Laravel route and reports success', async () => {
    vi.stubGlobal('__NVOOS_BACKEND__', 'laravel');
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ ok: true, id: 7 }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await confirmMagicLink('token-123');

    expect(result.ok).toBe(true);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/api/v1/magic-login/confirm');
    expect(init.body).toBe(JSON.stringify({ token: 'token-123' }));
  });

  it('surfaces the backend message on an invalid token', async () => {
    vi.stubGlobal('__NVOOS_BACKEND__', 'laravel');
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(
        jsonResponse({ ok: false, message: 'This sign-in link is invalid or has expired.' }, 401),
      ),
    );

    const result = await confirmMagicLink('expired');

    expect(result).toEqual({
      ok: false,
      message: 'This sign-in link is invalid or has expired.',
    });
  });
});
