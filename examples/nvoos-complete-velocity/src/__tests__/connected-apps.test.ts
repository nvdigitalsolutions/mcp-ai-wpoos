// SPDX-License-Identifier: LicenseRef-Proprietary
// @vitest-environment node
/**
 * Unit tests for the connected-app proxy policy (Proposal 090-p W6).
 *
 * The module under test is deliberately I/O-free so the allowlist and path
 * rules can be pinned without a server; the end-to-end behaviour (cookies
 * stripped, Set-Cookie stripped, /features advertisement) is covered by
 * connected-proxy.test.ts.
 */
import { describe, expect, it, vi, afterEach } from 'vitest';

import {
  CONNECTED_KEYS,
  CONNECTED_TTL_CAP,
  buildConnectedRequestHeaders,
  buildConnectedResponseHeaders,
  normaliseConnectedPath,
  parseConnectedApps,
} from '../../scripts/connected-apps.mjs';

afterEach(() => {
  vi.restoreAllMocks();
});

describe('parseConnectedApps', () => {
  it('returns an empty allowlist when unconfigured', () => {
    expect(parseConnectedApps(undefined).size).toBe(0);
    expect(parseConnectedApps('').size).toBe(0);
    expect(parseConnectedApps('   ').size).toBe(0);
  });

  it('disables the proxy rather than crashing on bad JSON', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    expect(parseConnectedApps('{not json').size).toBe(0);
    expect(warn).toHaveBeenCalled();
  });

  it('rejects a non-object payload', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    expect(parseConnectedApps('["contentGraph"]').size).toBe(0);
    expect(warn).toHaveBeenCalled();
  });

  it('drops unknown product keys', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const apps = parseConnectedApps(
      JSON.stringify({
        contentGraph: { base: 'https://cg.example.com' },
        evilProduct: { base: 'https://evil.example.com' },
      }),
    );

    expect([...apps.keys()]).toEqual(['contentGraph']);
    expect(warn).toHaveBeenCalled();
  });

  it('refuses a non-http(s) base', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    for (const base of ['file:///etc/passwd', 'ftp://example.com', 'not-a-url', '']) {
      expect(parseConnectedApps(JSON.stringify({ docsHub: { base } })).size).toBe(0);
    }
    expect(warn).toHaveBeenCalled();
  });

  it('normalises the base path and clamps the TTL', () => {
    const apps = parseConnectedApps(
      JSON.stringify({
        contentGraph: {
          base: 'https://cg.example.com/wp-json/nvoos-content-graph/v1///',
          token: 'secret',
          cacheTtl: 10_000,
        },
        docsHub: { base: 'https://docs.example.com', cacheTtl: -5 },
      }),
    );

    expect(apps.get('contentGraph')).toEqual({
      key: 'contentGraph',
      base: 'https://cg.example.com/wp-json/nvoos-content-graph/v1',
      token: 'secret',
      cacheTtl: CONNECTED_TTL_CAP,
    });
    // A negative TTL means "no shared caching", never a negative max-age.
    expect(apps.get('docsHub')?.cacheTtl).toBe(0);
    expect(apps.get('docsHub')?.token).toBe('');
  });

  it('only ever yields the four known keys', () => {
    const configured = Object.fromEntries(
      CONNECTED_KEYS.map((key) => [key, { base: `https://${key}.example.com` }]),
    );
    const apps = parseConnectedApps(JSON.stringify(configured));

    expect([...apps.keys()].sort()).toEqual([...CONNECTED_KEYS].sort());
  });
});

describe('normaliseConnectedPath', () => {
  it('defaults the bare key to the base path', () => {
    expect(normaliseConnectedPath(undefined)).toBe('/');
    expect(normaliseConnectedPath('')).toBe('/');
  });

  it('keeps a normal REST path', () => {
    expect(normaliseConnectedPath('/wp-json/nvoos-docs/v1/pages')).toBe(
      '/wp-json/nvoos-docs/v1/pages',
    );
  });

  it('rejects traversal segments', () => {
    expect(normaliseConnectedPath('/../etc/passwd')).toBeNull();
    expect(normaliseConnectedPath('/a/../../b')).toBeNull();
    expect(normaliseConnectedPath('/./a')).toBeNull();
  });

  it('rejects encoded traversal and separators', () => {
    // One decode turns these into a dot-segment / separator, so a second
    // decode downstream would change the path's meaning.
    expect(normaliseConnectedPath('/%2e%2e/secret')).toBeNull();
    expect(normaliseConnectedPath('/%2E%2E/secret')).toBeNull();
    expect(normaliseConnectedPath('/a%2fb')).toBeNull();
    expect(normaliseConnectedPath('/a%5cb')).toBeNull();
    // Double-encoded: the first decode leaves %2e%2e behind.
    expect(normaliseConnectedPath('/%252e%252e/secret')).toBeNull();
  });

  it('rejects a protocol-relative path', () => {
    // "//host/path" would swap the origin when resolved against a base.
    expect(normaliseConnectedPath('//evil.example.com/x')).toBeNull();
  });

  it('rejects backslashes (treated as a separator by some servers)', () => {
    expect(normaliseConnectedPath('/a\\b')).toBeNull();
  });

  it('rejects a path that is not rooted', () => {
    expect(normaliseConnectedPath('relative/path')).toBeNull();
  });

  it('rejects malformed percent-encoding', () => {
    expect(normaliseConnectedPath('/%zz')).toBeNull();
  });

  it('allows percent-encoded data that is not a separator', () => {
    // Decoding here is what lets the check look for encoded separators; the
    // decoded form is the one the upstream receives.
    expect(normaliseConnectedPath('/search/a%20b')).toBe('/search/a b');
  });
});

describe('buildConnectedRequestHeaders', () => {
  const app = { base: 'https://cg.example.com/api', token: 'secret' };

  it('drops console-origin credentials and origin assertions', () => {
    const headers = buildConnectedRequestHeaders(
      {
        Cookie: 'wordpress_logged_in=1',
        authorization: 'Bearer wp-token',
        'X-WP-Nonce': 'nonce',
        origin: 'https://console.example.com',
        referer: 'https://console.example.com/console/',
        'X-Forwarded-For': '203.0.113.9',
      },
      app,
    );

    expect(headers.cookie).toBeUndefined();
    expect(headers['x-wp-nonce']).toBeUndefined();
    expect(headers.origin).toBeUndefined();
    expect(headers.referer).toBeUndefined();
    expect(headers['x-forwarded-for']).toBeUndefined();
    // The browser's Authorization is replaced by the per-app token, never
    // forwarded as-is.
    expect(headers.authorization).toBe('Bearer secret');
  });

  it('sets Host to the configured upstream', () => {
    const headers = buildConnectedRequestHeaders({ host: 'console.example.com' }, app);
    expect(headers.host).toBe('cg.example.com');
  });

  it('sends no Authorization when the app has no token', () => {
    const headers = buildConnectedRequestHeaders(
      { authorization: 'Bearer wp-token' },
      { base: app.base, token: '' },
    );
    expect(headers.authorization).toBeUndefined();
  });

  it('keeps ordinary request headers', () => {
    const headers = buildConnectedRequestHeaders({ accept: 'application/json' }, app);
    expect(headers.accept).toBe('application/json');
  });
});

describe('buildConnectedResponseHeaders', () => {
  const app = { key: 'contentGraph', cacheTtl: 0 };

  it('strips Set-Cookie and origin-policy headers', () => {
    const headers = buildConnectedResponseHeaders(
      {
        'set-cookie': ['product=1; Path=/'],
        'strict-transport-security': 'max-age=63072000',
        'alt-svc': 'h3=":443"',
        'content-security-policy': "default-src 'none'",
        'x-frame-options': 'DENY',
      },
      app,
    );

    expect(headers['set-cookie']).toBeUndefined();
    expect(headers['strict-transport-security']).toBeUndefined();
    expect(headers['alt-svc']).toBeUndefined();
    expect(headers['content-security-policy']).toBeUndefined();
    expect(headers['x-frame-options']).toBeUndefined();
  });

  it('is no-store unless the product opts into a public TTL', () => {
    expect(buildConnectedResponseHeaders({}, app)['cache-control']).toBe('no-store');
    expect(buildConnectedResponseHeaders({}, { key: 'docsHub', cacheTtl: 60 })['cache-control']).toBe(
      'public, max-age=60',
    );
  });

  it('replaces an upstream cache-control instead of forwarding it', () => {
    const headers = buildConnectedResponseHeaders(
      { 'cache-control': 'public, max-age=31536000' },
      app,
    );
    expect(headers['cache-control']).toBe('no-store');
  });

  it('tags the response with the app key', () => {
    expect(buildConnectedResponseHeaders({}, app)['x-nvoos-connected']).toBe('contentGraph');
  });
});
