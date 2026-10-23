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
