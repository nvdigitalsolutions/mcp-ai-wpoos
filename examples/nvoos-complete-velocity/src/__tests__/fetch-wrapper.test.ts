/**
 * Fetch-wrapper session-expiry tests — 401 → onUnauthorized scoping
 * (site-origin only), re-install idempotency, and the jQuery ajaxError
 * mirror for the Content Graph explorer.
 */

import $ from 'jquery';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { installFetchWrapper } from '../fetch-wrapper';

const siteOrigin = () => window.location.origin;

describe('installFetchWrapper 401 signal', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('fires onUnauthorized when a site-origin request answers 401', async () => {
    const onUnauthorized = vi.fn();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('', { status: 401 })));
    const restore = installFetchWrapper({ siteOrigin: siteOrigin(), onUnauthorized });

    await window.fetch('/wp-json/wp/v2/users/me');

    expect(onUnauthorized).toHaveBeenCalledTimes(1);
    restore();
  });

  it('ignores 401s from other origins', async () => {
    const onUnauthorized = vi.fn();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('', { status: 401 })));
    const restore = installFetchWrapper({ siteOrigin: siteOrigin(), onUnauthorized });

    await window.fetch('https://media.example.com/ping');

    expect(onUnauthorized).not.toHaveBeenCalled();
    restore();
  });

  it('ignores non-401 responses', async () => {
    const onUnauthorized = vi.fn();
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('{}', { status: 403 })),
    );
    const restore = installFetchWrapper({ siteOrigin: siteOrigin(), onUnauthorized });

    await window.fetch('/wp-json/wp/v2/users/me');

    expect(onUnauthorized).not.toHaveBeenCalled();
    restore();
  });

  it('re-install wraps the native fetch, not the previous wrapper', async () => {
    const first = vi.fn();
    const second = vi.fn();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('', { status: 401 })));

    installFetchWrapper({ siteOrigin: siteOrigin(), onUnauthorized: first });
    const restore = installFetchWrapper({ siteOrigin: siteOrigin(), onUnauthorized: second });

    await window.fetch('/wp-json/x');

    expect(first).not.toHaveBeenCalled();
    expect(second).toHaveBeenCalledTimes(1);
    restore();
  });

  it('mirrors the 401 signal onto jQuery AJAX for site-origin requests only', () => {
    const onUnauthorized = vi.fn();
    const restore = installFetchWrapper({ siteOrigin: siteOrigin(), onUnauthorized });

    $(document).triggerHandler('ajaxError', [
      { status: 401 },
      { url: '/wp-json/nvoos-content-graph/v1/nodes' },
      'Unauthorized',
    ]);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);

    $(document).triggerHandler('ajaxError', [
      { status: 401 },
      { url: 'https://media.example.com/nodes' },
      'Unauthorized',
    ]);
	    expect(onUnauthorized).toHaveBeenCalledTimes(1);

	    restore();
	  });
	});

	describe('installFetchWrapper CSRF bridge', () => {
	  afterEach(() => {
	    vi.unstubAllGlobals();
	    vi.restoreAllMocks();
	  });

	  function installedWith(
	    response: Response = new Response('{}', { status: 200 }),
	  ): ReturnType< typeof vi.fn > {
	    const fetchMock = vi.fn().mockResolvedValue(response);
	    vi.stubGlobal('fetch', fetchMock);
	    return fetchMock;
	  }

	  function sentHeaders(fetchMock: ReturnType< typeof vi.fn >): Headers {
	    const init = fetchMock.mock.calls[0][1] as RequestInit;
	    return new Headers(init.headers);
	  }

	  it('mirrors the WordPress nonce into X-CSRF-TOKEN', async () => {
	    const fetchMock = installedWith();
	    const restore = installFetchWrapper({ siteOrigin: siteOrigin() });

	    await window.fetch('/wp-json/mcp-ai/v1/session/me', {
	      headers: { 'X-WP-Nonce': 'token-1' },
	    });

	    const headers = sentHeaders(fetchMock);
	    expect(headers.get('X-WP-Nonce')).toBe('token-1');
	    expect(headers.get('X-CSRF-TOKEN')).toBe('token-1');

	    restore();
	  });

	  it('does not overwrite an explicit X-CSRF-TOKEN', async () => {
	    const fetchMock = installedWith();
	    const restore = installFetchWrapper({ siteOrigin: siteOrigin() });

	    await window.fetch('/wp-json/mcp-ai/v1/session/me', {
	      headers: { 'X-WP-Nonce': 'wp-token', 'X-CSRF-TOKEN': 'explicit' },
	    });

	    expect(sentHeaders(fetchMock).get('X-CSRF-TOKEN')).toBe('explicit');

	    restore();
	  });

	  it('adds no CSRF header when the nonce is absent', async () => {
	    const fetchMock = installedWith();
	    const restore = installFetchWrapper({ siteOrigin: siteOrigin() });

	    await window.fetch('/wp-json/mcp-ai/v1/session/me');

	    expect(sentHeaders(fetchMock).has('X-CSRF-TOKEN')).toBe(false);

	    restore();
	  });
	});
