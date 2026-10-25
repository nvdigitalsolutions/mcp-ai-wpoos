/**
 * Runtime config unit tests — endpoint building, runtime shape, nonce/user
 * fetch chains (fetch mocked).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  buildConsoleRuntime,
  fetchCurrentUser,
  fetchFeatureMap,
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

  it('prefers the wp-admin embedded nonce', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response('<script>var wpApiSettings={"nonce":"0123456789"};</script>', {
          status: 200,
          headers: { 'Content-Type': 'text/html' },
        }),
      ),
    );
    await expect(fetchSessionNonce()).resolves.toBe('0123456789');
  });

  it('falls back to the session nonce endpoint', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValueOnce(
          new Response('', { status: 404, headers: { 'Content-Type': 'text/html' } }),
        )
        .mockResolvedValueOnce(jsonResponse({ nonce: 'fedcba9876' })),
    );
    await expect(fetchSessionNonce()).resolves.toBe('fedcba9876');
  });

  it('rejects a non-JSON nonce response', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValueOnce(
          new Response('', { status: 404, headers: { 'Content-Type': 'text/html' } }),
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
    const user = await fetchCurrentUser('nonce');
    expect(user).toEqual({
      id: 3,
      login: 'console-admin',
      displayName: 'Console Admin',
      capabilities: ['manage_options'],
    });
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
			);
		vi.stubGlobal( 'fetch', mockFetch );

		await expect( fetchCurrentUser( 'stale123' ) ).resolves.toEqual( {
			id: 9,
			login: 'author-one',
			displayName: 'An Author',
			capabilities: [],
		} );

		expect( mockFetch ).toHaveBeenCalledTimes( 3 );
		const retryUrl = mockFetch.mock.calls[ 2 ][ 0 ] as string;
		const [, retryInit] = mockFetch.mock.calls[ 2 ] as [ string, RequestInit ];
		expect( retryUrl ).toContain( '/wp-json/wp/v2/users/me' );
		expect( retryUrl ).not.toContain( 'context' );
		expect( retryInit.headers ).toEqual( { 'X-WP-Nonce': 'fresh456' } );
	} );

	it( 'reuses the rejected nonce when minting fails but the view context succeeds', async () => {
		const mockFetch = vi
			.fn()
			.mockResolvedValueOnce( jsonResponse( { code: 'rest_forbidden' }, 403 ) )
			.mockResolvedValueOnce( jsonResponse( { code: 'rest_forbidden' }, 403 ) )
			.mockResolvedValueOnce( jsonResponse( { id: 4, name: 'A', slug: 'a' } ) );
		vi.stubGlobal( 'fetch', mockFetch );

		await expect( fetchCurrentUser( 'stale123' ) ).resolves.toMatchObject( { id: 4 } );

		const [, retryInit] = mockFetch.mock.calls[ 2 ] as [ string, RequestInit ];
		expect( retryInit.headers ).toEqual( { 'X-WP-Nonce': 'stale123' } );
	} );
} );
