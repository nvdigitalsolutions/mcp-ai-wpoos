/**
 * Runtime config unit tests — endpoint building, runtime shape, nonce/user
 * fetch chains (fetch mocked).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import {
  buildConsoleRuntime,
  fetchCurrentUser,
  fetchFeatureMap,
  fetchRuntimeAvailability,
  fetchSessionNonce,
  UnauthorizedError,
} from '../runtime-config';

const jsonResponse = (body: unknown, status = 200): Response =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });

describe('buildConsoleRuntime', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/');
  });

  it('builds the admin-mode runtime against the app origin', () => {
    const runtime = buildConsoleRuntime({ nonce: 'abc123', user: { id: 7 } });
    expect(runtime.apiUrl).toBe(`${window.location.origin}/wp-json/mcp-ai/v1`);
    expect(runtime.proApi).toBe(`${window.location.origin}/wp-json/mcp-ai-pro/v1`);
    expect(runtime.nonce).toBe('abc123');
    expect(runtime.config?.mode).toBe('admin');
    expect(runtime.config?.assistantSelector).toBe(true);
    expect(runtime.user?.id).toBe(7);
    expect(runtime.endpoints.chat).toContain('/mcp-ai/v1/chat');
  });

  it('empties admin-gated endpoints for non-admin users (mirrors the server config)', () => {
    const runtime = buildConsoleRuntime({ user: { id: 7, capabilities: ['edit_posts'] } });

    // manage_options surfaces must be empty so pages skip the fetch instead
    // of hitting a 403.
    expect(runtime.endpoints.commandCenter).toBe('');
    expect(runtime.endpoints.approvals).toBe('');
    expect(runtime.endpoints.workflowBuilder).toBe('');
    expect(runtime.endpoints.remoteSites).toBe('');
    expect(runtime.endpoints.mediaCc).toBe('');
    // Logged-in surfaces stay available.
    expect(runtime.endpoints.settings).not.toBe('');
    expect(runtime.endpoints.assistants).not.toBe('');
    expect(runtime.endpoints.slashCommands).not.toBe('');
    // edit_posts surfaces stay available.
    expect(runtime.endpoints.blueprints).not.toBe('');
  });

  it('keeps admin-gated endpoints for administrators', () => {
    const runtime = buildConsoleRuntime({
      user: { id: 1, capabilities: ['manage_options', 'edit_posts'] },
    });

    expect(runtime.endpoints.commandCenter).toContain('/command-center');
    expect(runtime.endpoints.approvals).toContain('/approvals');
    expect(runtime.endpoints.blueprints).not.toBe('');
  });

  it('points toolkits at the WordPress manifest base', () => {
    // WordPress serves the manifest engine at `/toolkit-manifests`; the
    // console must not blank the key (the earlier Laravel-only assumption).
    const runtime = buildConsoleRuntime({
      user: { id: 1, capabilities: ['manage_options'] },
    });

    expect(runtime.endpoints.toolkits).toBe(
      `${window.location.origin}/wp-json/mcp-ai-pro/v1/toolkit-manifests`,
    );
  });

  it('points toolkits at the Laravel toolkits base', () => {
    const runtime = buildConsoleRuntime({
      backend: 'laravel',
      user: { id: 1, capabilities: ['manage_options'] },
    });

    expect(runtime.endpoints.toolkits).toBe(`${window.location.origin}/api/v1/pro/toolkits`);
  });

  it('keeps toolkits blank for non-admin sessions', () => {
    const runtime = buildConsoleRuntime({ user: { id: 9, capabilities: [] } });

    expect(runtime.endpoints.toolkits).toBe('');
  });

  it('empties edit_posts surfaces for users without the capability', () => {
    const runtime = buildConsoleRuntime({ user: { id: 9, capabilities: [] } });

    expect(runtime.endpoints.blueprints).toBe('');
    expect(runtime.endpoints.commandCenter).toBe('');
  });

  it('pins the default assistant id when provided', () => {
    const runtime = buildConsoleRuntime({ defaultAssistantId: 42 });
    expect(runtime.config?.assistantId).toBe(42);
  });

  it('defaults assistantId to 0 (selector)', () => {
    const runtime = buildConsoleRuntime();
    expect(runtime.config?.assistantId).toBe(0);
  });

  it('fails open — optional endpoints stay set without a feature map', () => {
    const runtime = buildConsoleRuntime();
    expect(runtime.endpoints.docsHub).not.toBe('');
    expect(runtime.endpoints.contentGraph).not.toBe('');
    expect(runtime.endpoints.mediaStudio).not.toBe('');
    expect(runtime.endpoints.documentEditor).not.toBe('');
  });

	  it( 'empties optional endpoints the server reports unavailable', () => {
		  const runtime = buildConsoleRuntime( {
			  features: {
				  docs_hub: false,
				  knowledge_graph: false,
				  media_studio: false,
				  document_editor: false,
				  okf: false,
			  },
		  } );
		  expect( runtime.endpoints.docsHub ).toBe( '' );
		  expect( runtime.endpoints.contentGraph ).toBe( '' );
		  expect( runtime.endpoints.mediaStudio ).toBe( '' );
		  expect( runtime.endpoints.documentEditor ).toBe( '' );
		  expect( runtime.endpoints.okf ).toBe( '' );
	  } );

	  it( 'keeps available surfaces wired when the map says so', () => {
    const runtime = buildConsoleRuntime({
      features: { docs_hub: true, knowledge_graph: true },
    });
    expect(runtime.endpoints.docsHub).not.toBe('');
    expect(runtime.endpoints.contentGraph).not.toBe('');
    expect(runtime.features).toEqual({ docs_hub: true, knowledge_graph: true });
  });
});

describe('fetchSessionNonce', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('prefers the session nonce endpoint', async () => {
    const mockFetch = vi
      .fn()
      .mockResolvedValue(jsonResponse({ nonce: 'fedcba9876' }));
    vi.stubGlobal('fetch', mockFetch);
    await expect(fetchSessionNonce()).resolves.toBe('fedcba9876');
    expect(mockFetch).toHaveBeenCalledTimes(1);
    expect(mockFetch.mock.calls[0][0] as string).toContain('/wp-json/mcp-ai/v1/session/nonce');
  });

  it('falls back to the wp-admin embedded nonce when the endpoint is unavailable', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValueOnce(
          new Response('', { status: 404, headers: { 'Content-Type': 'text/html' } }),
        )
        .mockResolvedValueOnce(
          new Response('<script>var wpApiSettings={"nonce":"0123456789"};</script>', {
            status: 200,
            headers: { 'Content-Type': 'text/html' },
          }),
        ),
    );
    await expect(fetchSessionNonce()).resolves.toBe('0123456789');
  });

  it('rejects a non-JSON nonce response (challenge page) when the scrape also fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValueOnce(
          new Response('<!doctype html><html>', {
            status: 200,
            headers: { 'Content-Type': 'text/html' },
          }),
        )
        .mockResolvedValueOnce(
          new Response('<!doctype html><html>', {
            status: 200,
            headers: { 'Content-Type': 'text/html' },
          }),
        ),
    );
    await expect(fetchSessionNonce()).rejects.toThrow(/expected JSON/);
  });
});

describe('fetchFeatureMap', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns the server map on success', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({ success: true, data: { features: { docs_hub: false, pro: true } } }),
      ),
    );
    await expect(fetchFeatureMap('nonce')).resolves.toEqual({
      docs_hub: false,
      pro: true,
    });
  });

  it('fails open to an empty map on HTTP errors', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({ code: 'rest_forbidden' }, 403),
      ),
    );
    await expect(fetchFeatureMap()).resolves.toEqual({});
    expect(warn).toHaveBeenCalled();
  });

  it('fails open on non-JSON responses', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response('<!doctype html>', {
          status: 200,
          headers: { 'Content-Type': 'text/html' },
        }),
      ),
    );
    await expect(fetchFeatureMap()).resolves.toEqual({});
    expect(warn).toHaveBeenCalled();
  });

  it('fails open on network errors', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network down')));
    await expect(fetchFeatureMap()).resolves.toEqual({});
    expect(warn).toHaveBeenCalled();
  });
});

describe('endpoint availability (090-B)', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/');
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  const admin = { id: 7, capabilities: ['manage_options', 'edit_posts'] };

  it('blanks an endpoint the backend reports it cannot serve', () => {
    const runtime = buildConsoleRuntime({
      user: admin,
      endpoints: { workflowBuilder: false, tokenManager: false },
    });

    expect(runtime.endpoints.workflowBuilder).toBe('');
    expect(runtime.endpoints.tokenManager).toBe('');

    // Everything the backend did not disclaim still resolves.
    expect(runtime.endpoints.assistants).toContain('/assistants');
  });

  it('keeps endpoints when the backend serves them', () => {
    const runtime = buildConsoleRuntime({
      user: admin,
      endpoints: { workflowBuilder: true, approvals: true },
    });

    expect(runtime.endpoints.workflowBuilder).not.toBe('');
    expect(runtime.endpoints.approvals).not.toBe('');
  });

  it('fails open when the backend sends no endpoint map', () => {
    // Older builds predate the map; hiding every page would be worse than a
    // page that reports its own unavailable state.
    const runtime = buildConsoleRuntime({ user: admin });

    expect(runtime.endpoints.workflowBuilder).not.toBe('');
    expect(runtime.endpoints.tokenManager).not.toBe('');
  });

  it('mounts a connected app at the URL the backend resolved', () => {
    // Class C discovery: the product is not on this router, so the backend
    // publishes its base URL instead of a boolean.
    const runtime = buildConsoleRuntime({
      backend: 'laravel',
      user: admin,
      endpoints: { docsHub: true },
      endpointUrls: { docsHub: 'https://docs.example.com/wp-json/nvoos-docs/v1' },
    });

    expect(runtime.endpoints.docsHub).toBe(
      'https://docs.example.com/wp-json/nvoos-docs/v1',
    );
  });

  it('mounts a connected app on the same-origin proxy when advertised', () => {
    // 090-p W6: the proxy advertises itself on /features, and the console
    // prefers the same-origin mount over the product's absolute URL — that is
    // what removes the CORS requirement.
    const runtime = buildConsoleRuntime({
      backend: 'laravel',
      user: admin,
      endpoints: { docsHub: true },
      endpointUrls: { docsHub: 'https://docs.example.com/wp-json/nvoos-docs/v1' },
      connectedProxy: true,
    });

    expect(runtime.endpoints.docsHub).toBe(
      `${window.location.origin}/connected/docsHub`,
    );
  });

  it('keeps connected-app URLs away from non-admin sessions', () => {
    const runtime = buildConsoleRuntime({
      backend: 'laravel',
      user: { id: 9, capabilities: ['edit_posts'] },
      endpointUrls: { docsHub: 'https://docs.example.com/wp-json/nvoos-docs/v1' },
    });

    expect(runtime.endpoints.docsHub).toBe('');
  });

  it('ignores a connected-app URL for a key the console does not build', () => {
    const runtime = buildConsoleRuntime({
      user: admin,
      endpointUrls: { notAConsoleKey: 'https://example.com' },
    });

    expect(runtime.endpoints).not.toHaveProperty('notAConsoleKey');
  });

  it('covers exactly the shared console endpoint contract', () => {
    // The backend asserts the same file (EndpointCapabilitiesTest), so a key
    // added on one side without the other fails on both CIs instead of on a
    // user's browser.
    const contractPath = resolve(
      process.cwd(),
      '../../docker/laravel/app-skeleton/contracts/console-endpoints.json',
    );

    const contract = JSON.parse(readFileSync(contractPath, 'utf8')) as {
      route_backed: string[];
      asset: string[];
    };

    const runtime = buildConsoleRuntime({ user: admin });

    expect(Object.keys(runtime.endpoints).sort()).toEqual(
      [...contract.route_backed, ...contract.asset].sort(),
    );
  });

  it('classifies every route-backed key in the shared contract', () => {
    // The same file the PHP suites assert. The reason is what lets the console
    // tell "not built on this backend yet" from "install the plugin" from
    // "connect a service" from "the addon is not active"; an unclassified key
    // would render as one of those by accident. WordPress overrides are merged
    // over the canonical (Laravel) map at runtime.
    const contractPath = resolve(
      process.cwd(),
      '../../docker/laravel/app-skeleton/contracts/console-endpoints.json',
    );

    const contract = JSON.parse(readFileSync(contractPath, 'utf8')) as {
      route_backed: string[];
      reasons?: Record<string, string>;
      wordpress_reasons?: Record<string, string>;
      reason_codes?: string[];
      fragments?: {
        laravel?: Record<string, string>;
        wordpress?: Record<string, string>;
      };
    };

    const reasons = contract.reasons ?? {};
    const overrides = contract.wordpress_reasons ?? {};
    const codes = contract.reason_codes ?? [];

    expect(Object.keys(reasons).sort()).toEqual([...contract.route_backed].sort());

    // An override may only reclassify a key the contract already knows.
    for (const key of Object.keys(overrides)) {
      expect(contract.route_backed).toContain(key);
    }

    // The vocabulary is shared with both PHP runtimes; an empty list means the
    // fixture forgot to publish it.
    expect(codes.length).toBeGreaterThan(0);

    const unknown: string[] = [];
    for (const [key, reason] of Object.entries(reasons)) {
      if (!codes.includes(reason)) unknown.push(`${key}=${reason}`);
    }
    for (const [key, reason] of Object.entries(overrides)) {
      if (!codes.includes(reason)) unknown.push(`${key}=${reason}`);
    }

    expect(unknown).toEqual([]);

    // Both runtimes declare the exact route fragment each key maps to. The
    // PHP suites assert their constants against these maps, so a path moved on
    // one side fails on three CIs (the `toolkits` drift class).
    for (const runtime of ['laravel', 'wordpress'] as const) {
      const fragments = contract.fragments?.[runtime] ?? {};

      expect(Object.keys(fragments).sort()).toEqual([...contract.route_backed].sort());

      for (const [key, fragment] of Object.entries(fragments)) {
        expect(typeof fragment, `${runtime}.${key}`).toBe('string');
        expect(fragment, `${runtime}.${key}`).not.toBe('');
      }
    }
  });
});

describe('fetchRuntimeAvailability', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns both maps from a single request', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        success: true,
        data: {
          features: { docs_hub: false },
          endpoints: { workflowBuilder: false, assistants: true },
        },
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(fetchRuntimeAvailability('nonce')).resolves.toEqual({
      features: { docs_hub: false },
      endpoints: { workflowBuilder: false, assistants: true },
      endpointUrls: {},
      endpointReasons: {},
      connectedProxy: false,
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('returns the supply class for every endpoint', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({
          success: true,
          data: {
            features: {},
            endpoints: { docsHub: false },
            endpoint_reasons: { docsHub: 'external-product' },
          },
        }),
      ),
    );

    await expect(fetchRuntimeAvailability()).resolves.toEqual({
      features: {},
      endpoints: { docsHub: false },
      endpointUrls: {},
      endpointReasons: { docsHub: 'external-product' },
      connectedProxy: false,
    });
  });

  it('returns resolved connected-app URLs', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({
          success: true,
          data: {
            features: {},
            endpoints: { docsHub: true },
            endpoint_urls: {
              docsHub: 'https://docs.example.com/wp-json/nvoos-docs/v1',
            },
          },
        }),
      ),
    );

    await expect(fetchRuntimeAvailability()).resolves.toEqual({
      features: {},
      endpoints: { docsHub: true },
      endpointUrls: { docsHub: 'https://docs.example.com/wp-json/nvoos-docs/v1' },
      endpointReasons: {},
      connectedProxy: false,
    });
  });

  it('reports an advertised connected-app proxy', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({
          success: true,
          data: {
            features: {},
            endpoints: { docsHub: true },
            endpoint_urls: {
              docsHub: 'https://docs.example.com/wp-json/nvoos-docs/v1',
            },
            connected_proxy: true,
          },
        }),
      ),
    );

    await expect(fetchRuntimeAvailability()).resolves.toEqual({
      features: {},
      endpoints: { docsHub: true },
      endpointUrls: { docsHub: 'https://docs.example.com/wp-json/nvoos-docs/v1' },
      endpointReasons: {},
      connectedProxy: true,
    });
  });

  it('fails open to empty maps when the endpoint map is absent', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({ success: true, data: { features: { pro: true } } }),
      ),
    );

    await expect(fetchRuntimeAvailability()).resolves.toEqual({
      features: { pro: true },
      endpoints: {},
      endpointUrls: {},
      endpointReasons: {},
      connectedProxy: false,
    });
  });

  it('fails open to empty maps on HTTP errors', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse({ code: 'rest_forbidden' }, 403)),
    );

    await expect(fetchRuntimeAvailability()).resolves.toEqual({
      features: {},
      endpoints: {},
      endpointUrls: {},
      endpointReasons: {},
      connectedProxy: false,
    });
    expect(warn).toHaveBeenCalled();
  });
});

describe('fetchCurrentUser', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('maps the WP user + capabilities', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({
          id: 3,
          name: 'Console Admin',
          slug: 'console-admin',
          capabilities: { manage_options: true, edit_posts: false },
        }),
      ),
    );
    const { user, nonce: usedNonce } = await fetchCurrentUser('nonce');
    expect(user).toEqual({
      id: 3,
      login: 'console-admin',
      displayName: 'Console Admin',
      capabilities: ['manage_options'],
    });
    expect(usedNonce).toBe('nonce');
  });

  	it( 'treats 403 as not-signed-in (hardened cookie auth)', async () => {
  		vi.stubGlobal(
  			'fetch',
  			vi.fn().mockResolvedValue(
  				jsonResponse( { code: 'rest_forbidden' }, 403 ),
  			),
  		);
		await expect( fetchCurrentUser( 'nonce' ) ).rejects.toBeInstanceOf( UnauthorizedError );
	} );

	it( 'recovers a session when the edit context 403s (author role) via fresh nonce + view context', async () => {
		const mockFetch = vi
			.fn()
			.mockResolvedValueOnce( jsonResponse( { code: 'rest_forbidden' }, 403 ) )
			.mockResolvedValueOnce( jsonResponse( { nonce: 'fresh456' } ) )
			.mockResolvedValueOnce(
				jsonResponse( { id: 9, name: 'An Author', slug: 'author-one' } ),
			)
			.mockResolvedValueOnce(
				jsonResponse( { capabilities: { edit_posts: true, upload_files: true } } ),
			);
		vi.stubGlobal( 'fetch', mockFetch );

		await expect( fetchCurrentUser( 'stale123' ) ).resolves.toEqual( {
			user: {
				id: 9,
				login: 'author-one',
				displayName: 'An Author',
				capabilities: [ 'edit_posts', 'upload_files' ],
			},
			nonce: 'fresh456',
		} );

		expect( mockFetch ).toHaveBeenCalledTimes( 4 );
		const retryUrl = mockFetch.mock.calls[ 2 ][ 0 ] as string;
		const [, retryInit] = mockFetch.mock.calls[ 2 ] as [ string, RequestInit ];
		expect( retryUrl ).toContain( '/wp-json/wp/v2/users/me' );
		expect( retryUrl ).not.toContain( 'context' );
		expect( retryInit.headers ).toEqual( { 'X-WP-Nonce': 'fresh456' } );

		const meUrl = mockFetch.mock.calls[ 3 ][ 0 ] as string;
		const [, meInit] = mockFetch.mock.calls[ 3 ] as [ string, RequestInit ];
		expect( meUrl ).toContain( '/wp-json/mcp-ai/v1/session/me' );
		expect( meInit.headers ).toEqual( { 'X-WP-Nonce': 'fresh456' } );
	} );

	it( 'reuses the rejected nonce when minting fails but the view context succeeds', async () => {
		const mockFetch = vi
			.fn()
			.mockResolvedValueOnce( jsonResponse( { code: 'rest_forbidden' }, 403 ) )
			.mockResolvedValueOnce( jsonResponse( { code: 'rest_forbidden' }, 403 ) )
			.mockResolvedValueOnce( jsonResponse( { id: 4, name: 'A', slug: 'a' } ) )
			.mockResolvedValueOnce( jsonResponse( { code: 'rest_forbidden' }, 403 ) );
		vi.stubGlobal( 'fetch', mockFetch );

		const result = await fetchCurrentUser( 'stale123' );
		await expect( result.user ).toMatchObject( { id: 4 } );
		expect( result.user.capabilities ).toEqual( [] );
		expect( result.nonce ).toBe( 'stale123' );

		const [, retryInit] = mockFetch.mock.calls[ 2 ] as [ string, RequestInit ];
		expect( retryInit.headers ).toEqual( { 'X-WP-Nonce': 'stale123' } );
	} );
} );
