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

describe('installFetchWrapper X-Tenant-Id (Laravel estate selector)', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  function install(tenantId?: () => string) {
    const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);
    const restore = installFetchWrapper({ siteOrigin: siteOrigin(), tenantId });
    return { fetchMock, restore };
  }

  function headersOf(fetchMock: ReturnType<typeof vi.fn>): Headers {
    const init = fetchMock.mock.calls[0][1] as RequestInit;
    return new Headers(init.headers);
  }

  it('adds the estate header to same-origin console API requests', async () => {
    const { fetchMock, restore } = install(() => 'acme');

    await window.fetch('/api/v1/assistants');

    expect(headersOf(fetchMock).get('X-Tenant-Id')).toBe('acme');
    restore();
  });

  it('reads the selection per request, not at install time', async () => {
    let selected = 'default';
    const { fetchMock, restore } = install(() => selected);

    await window.fetch('/api/v1/assistants');
    selected = 'acme';
    await window.fetch('/api/v1/assistants');

    const first = new Headers((fetchMock.mock.calls[0][1] as RequestInit).headers);
    const second = new Headers((fetchMock.mock.calls[1][1] as RequestInit).headers);
    expect(first.get('X-Tenant-Id')).toBe('default');
    expect(second.get('X-Tenant-Id')).toBe('acme');
    restore();
  });

  it('never sends the header to the identity endpoints', async () => {
    // `/session/me` must report the server's own resolution — the picker
    // validates a stored id against it, so a stale client value here would
    // validate itself.
    const { fetchMock, restore } = install(() => 'acme');

    await window.fetch('/api/v1/session/me');
    await window.fetch('/api/v1/session/nonce');

    expect(headersOf(fetchMock).has('X-Tenant-Id')).toBe(false);
    const second = new Headers((fetchMock.mock.calls[1][1] as RequestInit).headers);
    expect(second.has('X-Tenant-Id')).toBe(false);
    restore();
  });

  it('never sends the header on a WordPress API path', async () => {
    const { fetchMock, restore } = install(() => 'acme');

    await window.fetch('/wp-json/mcp-ai/v1/assistants');

    expect(headersOf(fetchMock).has('X-Tenant-Id')).toBe(false);
    restore();
  });

  it('never sends the header to another origin', async () => {
    const { fetchMock, restore } = install(() => 'acme');

    await window.fetch('https://media.example.com/api/v1/assistants');

    expect(headersOf(fetchMock).has('X-Tenant-Id')).toBe(false);
    restore();
  });

  it('sends nothing when no selector is installed or it is empty', async () => {
    const withoutSelector = install(undefined);
    await window.fetch('/api/v1/assistants');
    expect(headersOf(withoutSelector.fetchMock).has('X-Tenant-Id')).toBe(false);
    withoutSelector.restore();

    const emptySelector = install(() => '');
    await window.fetch('/api/v1/assistants');
    expect(headersOf(emptySelector.fetchMock).has('X-Tenant-Id')).toBe(false);
    emptySelector.restore();
  });

  it('does not overwrite a request-level header', async () => {
    const { fetchMock, restore } = install(() => 'acme');

    await window.fetch('/api/v1/assistants', { headers: { 'X-Tenant-Id': 'explicit' } });

    expect(headersOf(fetchMock).get('X-Tenant-Id')).toBe('explicit');
    restore();
  });
});
