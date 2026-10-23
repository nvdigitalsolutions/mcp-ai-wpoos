/**
 * Sign-in unit tests — the wp-login replay flow (fetch mocked) and the 401 →
 * UnauthorizedError mapping from the session bootstrap.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';

import { extractWpLoginError, logoutFromWordPress, submitWpLogin } from '../login';
import { fetchCurrentUser, UnauthorizedError } from '../runtime-config';

const htmlResponse = (body: string, status = 200): Response =>
  new Response(body, { status, headers: { 'Content-Type': 'text/html; charset=utf-8' } });

const LOGIN_ERROR_PAGE = htmlResponse(
  '<div id="login_error"><strong>Error</strong>: The username <b>admin</b> is not registered on this site.</div>',
);

describe('submitWpLogin', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('seeds the test cookie, then replays the native login form POST', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(htmlResponse('<html></html>')) // Seed GET.
      .mockResolvedValueOnce(new Response(null, { status: 302 })); // Success redirect.
    vi.stubGlobal('fetch', fetchMock);

    const result = await submitWpLogin({
      username: 'admin',
      password: 'secret',
      remember: true,
    });
    expect(result.ok).toBe(true);

    const seed = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(seed[0]).toBe('/wp-login.php');
    expect(seed[1].credentials).toBe('include');
    expect(seed[1].redirect).toBe('manual');

    const post = fetchMock.mock.calls[1] as [string, RequestInit];
    expect(post[0]).toBe('/wp-login.php');
    expect(post[1].method).toBe('POST');
    expect(post[1].credentials).toBe('include');
    expect(post[1].redirect).toBe('manual');
    const body = new URLSearchParams(post[1].body as string);
    expect(body.get('log')).toBe('admin');
    expect(body.get('pwd')).toBe('secret');
    expect(body.get('wp-submit')).toBe('Log In');
    expect(body.get('testcookie')).toBe('1');
    expect(body.get('redirect_to')).toBe('/');
    expect(body.get('rememberme')).toBe('forever');
  });

  it('omits rememberme when not requested', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(htmlResponse('<html></html>'))
      .mockResolvedValueOnce(new Response(null, { status: 303 }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await submitWpLogin({ username: 'admin', password: 'secret' });
    expect(result.ok).toBe(true);
    const post = fetchMock.mock.calls[1] as [string, RequestInit];
    const body = new URLSearchParams(post[1].body as string);
    expect(body.has('rememberme')).toBe(false);
  });

  it('returns a generic message on failed sign-on without leaking WP detail', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(htmlResponse('<html></html>'))
      .mockResolvedValueOnce(LOGIN_ERROR_PAGE);
    vi.stubGlobal('fetch', fetchMock);
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

    const result = await submitWpLogin({ username: 'nobody', password: 'x' });
    expect(result.ok).toBe(false);
    expect(result.message).toBe('Invalid username or password.');
    expect(result.message).not.toContain('not registered');
    // The specific WP reason goes to the console only (debug aid).
    expect(warn).toHaveBeenCalledWith(
      '[nvoos-console] wp-login rejected the sign-in:',
      expect.stringContaining('not registered'),
    );
  });

  it('reports unavailability when the proxy answers 502', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(htmlResponse('<html></html>'))
      .mockResolvedValueOnce(htmlResponse('bad gateway', 502));
    vi.stubGlobal('fetch', fetchMock);

    const result = await submitWpLogin({ username: 'admin', password: 'secret' });
    expect(result.ok).toBe(false);
    expect(result.message).toBe('Sign-in is temporarily unavailable. Please try again.');
  });

  it('rethrows AbortError so the caller can cancel', async () => {
    const abortError = new Error('aborted');
    abortError.name = 'AbortError';
    vi.stubGlobal('fetch', vi.fn().mockRejectedValueOnce(abortError));

    await expect(submitWpLogin({ username: 'a', password: 'b' })).rejects.toThrow('aborted');
  });
});

describe('extractWpLoginError', () => {
  it('extracts and unescapes the login error text', () => {
    expect(
      extractWpLoginError(
        '<div id="login_error"><strong>Error</strong>: The password you entered for the username &quot;admin&quot; is incorrect.</div>',
      ),
    ).toBe('Error: The password you entered for the username "admin" is incorrect.');
  });

  it('returns an empty string when no error div is present', () => {
    expect(extractWpLoginError('<form name="loginform"></form>')).toBe('');
  });
});

describe('logoutFromWordPress', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('scrapes the confirmation page for the nonce, then hits the nonced URL', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        htmlResponse('<a href="wp-login.php?action=logout&amp;_wpnonce=deadbeef00">log out</a>'),
      )
      .mockResolvedValueOnce(new Response(null, { status: 302 }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await logoutFromWordPress();
    expect(result.ok).toBe(true);

    const logout = fetchMock.mock.calls[1] as [string, RequestInit];
    expect(logout[0]).toBe('/wp-login.php?action=logout&_wpnonce=deadbeef00');
    expect(logout[1].credentials).toBe('include');
    expect(logout[1].redirect).toBe('manual');
  });

  it('falls back to the wp-admin page nonce when the confirmation page has none', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(htmlResponse('<p>nothing here</p>'))
      .mockResolvedValueOnce(
        htmlResponse(
          '<a href="https://site.example/wp-login.php?action=logout&amp;_wpnonce=aa11bb22cc">Log Out</a>',
        ),
      )
      .mockResolvedValueOnce(new Response(null, { status: 302 }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await logoutFromWordPress();
    expect(result.ok).toBe(true);

    const logout = fetchMock.mock.calls[2] as [string, RequestInit];
    expect(logout[0]).toContain('_wpnonce=aa11bb22cc');
  });

  it('reports failure when no nonce can be scraped', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(htmlResponse('<p>nope</p>')));

    const result = await logoutFromWordPress();
    expect(result.ok).toBe(false);
  });

  it('reports failure when the nonced logout request errors', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        htmlResponse('<a href="wp-login.php?action=logout&amp;_wpnonce=deadbeef00">log out</a>'),
      )
      .mockRejectedValueOnce(new Error('network down'));
    vi.stubGlobal('fetch', fetchMock);

    const result = await logoutFromWordPress();
    expect(result.ok).toBe(false);
    expect(result.message).toContain('log-out request failed');
  });
});

describe('fetchCurrentUser 401 → UnauthorizedError', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('throws UnauthorizedError on 401', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response('{"code":"rest_cookie_invalid_nonce"}', {
          status: 401,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );
    await expect(fetchCurrentUser('stale')).rejects.toBeInstanceOf(UnauthorizedError);
  });

  it('keeps generic errors for non-401 failures', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('bad gateway', { status: 502 })),
    );
    await expect(fetchCurrentUser('ok')).rejects.not.toBeInstanceOf(UnauthorizedError);
  });
});
