/**
 * Laravel backend profile tests (Proposal 090-B Phase 0).
 *
 * Covers the path rewrite (`/api/v1` + `/api/v1/pro`), the addon surfaces that
 * are unavailable on a pure Laravel runtime, the session/features probes, and
 * the startup contract assertion.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';

import { backendPaths, resolveBackend } from '../backend-profile';
import {
  assertBackendContract,
  buildConsoleRuntime,
  fetchCurrentUser,
  fetchSessionNonce,
} from '../runtime-config';

const jsonResponse = (body: unknown, status = 200): Response =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });

const origin = (): string => window.location.origin;

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('resolveBackend', () => {
  it('defaults to wordpress', () => {
    expect(resolveBackend()).toBe('wordpress');
    expect(resolveBackend('')).toBe('wordpress');
    expect(resolveBackend('something-else')).toBe('wordpress');
  });

  it('accepts laravel and hybrid', () => {
    expect(resolveBackend('laravel')).toBe('laravel');
    expect(resolveBackend(' LARAVEL ')).toBe('laravel');
    expect(resolveBackend('hybrid')).toBe('hybrid');
  });
});

describe('backendPaths', () => {
  it('rewrites the Laravel contract under /api/v1', () => {
    expect(backendPaths(origin(), 'laravel')).toEqual({
      apiBase: `${origin()}/api/v1`,
      proBase: `${origin()}/api/v1/pro`,
      wpBase: `${origin()}/api/v1/wp`,
      nonce: `${origin()}/api/v1/session/nonce`,
      me: `${origin()}/api/v1/session/me`,
      site: `${origin()}/api/v1/site`,
      features: `${origin()}/api/v1/features`,
    });
  });

  it('keeps the WordPress wp/v2 base in hybrid mode', () => {
    expect(backendPaths(origin(), 'hybrid').wpBase).toBe(`${origin()}/wp-json/wp/v2`);
    expect(backendPaths(origin(), 'hybrid').apiBase).toBe(`${origin()}/api/v1`);
  });

  it('keeps the WordPress contract unchanged', () => {
    expect(backendPaths(origin(), 'wordpress')).toEqual({
      apiBase: `${origin()}/wp-json/mcp-ai/v1`,
      proBase: `${origin()}/wp-json/mcp-ai-pro/v1`,
      wpBase: `${origin()}/wp-json/wp/v2`,
      nonce: `${origin()}/wp-json/mcp-ai/v1/session/nonce`,
      me: `${origin()}/wp-json/wp/v2/users/me?context=edit`,
      site: `${origin()}/wp-json/`,
      features: `${origin()}/wp-json/mcp-ai/v1/features`,
    });
  });

  it('leaves the org-team surface empty on WordPress', () => {
    const runtime = buildConsoleRuntime({
      backend: 'wordpress',
      nonce: 'nonce-1',
      user: { id: 1, capabilities: ['manage_options'] },
    });

    // WordPress has the agent-team catalogue at `teams`; users-with-roles is
    // Laravel-only until the contract is ported.
    expect(runtime.endpoints.teams).toBe(`${origin()}/wp-json/mcp-ai/v1/teams`);
    expect(runtime.endpoints.orgTeams).toBe('');
  });
});

describe('buildConsoleRuntime (laravel)', () => {
  it('builds the runtime against /api/v1', () => {
    const runtime = buildConsoleRuntime({
      backend: 'laravel',
      nonce: 'nonce-1',
      user: { id: 7, capabilities: ['manage_options', 'edit_posts'] },
    });

    expect(runtime.apiUrl).toBe(`${origin()}/api/v1`);
    expect(runtime.proApi).toBe(`${origin()}/api/v1/pro`);
    expect(runtime.endpoints.chat).toBe(`${origin()}/api/v1/chat`);
    expect(runtime.endpoints.tools).toBe(`${origin()}/api/v1/tools`);
    expect(runtime.endpoints.proOps).toBe(`${origin()}/api/v1/pro/ops`);
    expect(runtime.endpoints.upload).toBe(`${origin()}/api/v1/media`);
    expect(runtime.endpoints.wpBase).toBe(`${origin()}/api/v1/wp`);
    // Org teams (users + roles) are a Laravel-only contract; the WordPress
    // agent-team catalogue keeps `/teams`.
    expect(runtime.endpoints.orgTeams).toBe(`${origin()}/api/v1/org/teams`);
  });

  it('marks the WordPress-only addon surfaces unavailable', () => {
    const runtime = buildConsoleRuntime({
      backend: 'laravel',
      user: { id: 1, capabilities: ['manage_options'] },
    });

    expect(runtime.endpoints.contentGraph).toBe('');
    expect(runtime.endpoints.docsHub).toBe('');
    expect(runtime.endpoints.mediaStudio).toBe('');
    expect(runtime.endpoints.documentEditor).toBe('');
    expect(runtime.endpoints.okf).toBe('');
  });

  it('keeps capability gating for admin surfaces', () => {
    const runtime = buildConsoleRuntime({
      backend: 'laravel',
      user: { id: 2, capabilities: ['read'] },
    });

    expect(runtime.endpoints.proOps).toBe('');
    expect(runtime.endpoints.assistants).toBe(`${origin()}/api/v1/assistants`);
    expect(runtime.endpoints.orgTeams).toBe('');
  });
});

describe('fetchSessionNonce (laravel)', () => {
  it('uses /api/v1/session/nonce and never scrapes wp-admin', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ success: true, nonce: 'csrf-123' }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(fetchSessionNonce('laravel')).resolves.toBe('csrf-123');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toBe(`${origin()}/api/v1/session/nonce`);
  });
});

describe('fetchCurrentUser (laravel)', () => {
  it('maps the Laravel session payload', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        id: 9,
        login: 'owner@example.com',
        displayName: 'Owner',
        capabilities: ['manage_options', 'read'],
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const result = await fetchCurrentUser('nonce-9', 'laravel');

    expect(fetchMock.mock.calls[0][0]).toBe(`${origin()}/api/v1/session/me`);
    expect(result.user).toEqual({
      id: 9,
      login: 'owner@example.com',
      displayName: 'Owner',
      capabilities: ['manage_options', 'read'],
    });
    expect(result.nonce).toBe('nonce-9');
  });
});

describe('assertBackendContract', () => {
  it('is a no-op for wordpress', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    await expect(assertBackendContract('wordpress')).resolves.toBeUndefined();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('accepts a matching Laravel contract', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        success: true,
        data: { backend: 'laravel', contract: 'mcp-ai/v1', contract_version: 1 },
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(assertBackendContract('laravel')).resolves.toBeUndefined();
    expect(fetchMock.mock.calls[0][0]).toBe(`${origin()}/api/v1/features`);
  });

  it('rejects a mismatched contract', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        success: true,
        data: { backend: 'laravel', contract: 'other/v9', contract_version: 9 },
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(assertBackendContract('laravel')).rejects.toThrow(
      /contract mismatch/i,
    );
  });

  it('rejects a non-2xx features probe', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({}, 503));
    vi.stubGlobal('fetch', fetchMock);

    await expect(assertBackendContract('laravel')).rejects.toThrow(/503/);
  });
});
