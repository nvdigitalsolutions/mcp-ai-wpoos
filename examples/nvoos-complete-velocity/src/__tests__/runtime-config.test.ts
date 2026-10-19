/**
 * Runtime config unit tests — endpoint building, runtime shape, nonce/user
 * fetch chains (fetch mocked).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  buildConsoleRuntime,
  fetchCurrentUser,
  fetchSessionNonce,
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

  it('surfaces login-required errors', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({ code: 'rest_forbidden' }, 403),
      ),
    );
    await expect(fetchCurrentUser('nonce')).rejects.toThrow(/log into WordPress/);
  });
});
