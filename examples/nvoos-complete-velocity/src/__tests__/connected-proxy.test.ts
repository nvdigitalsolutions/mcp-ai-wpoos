// @vitest-environment node
/**
 * End-to-end tests for the `/connected/<key>/*` proxy in scripts/serve.mjs
 * (Proposal 090-p W6).
 *
 * A fixture upstream stands in for a connected product; the real server is
 * spawned as a child process so the routing, header policy and /features
 * advertisement are exercised exactly as they run on Velocity.
 *
 * Acceptance (090-p §5.1): an allowlisted upstream is reachable through the
 * proxy, an unknown key is 404, traversal/encoded-separator paths are 400,
 * console cookies are not forwarded, and a product's Set-Cookie is stripped.
 */
import { spawn, type ChildProcess } from 'node:child_process';
import { createServer as createHttpServer, request as httpRequest, type Server } from 'node:http';
import { createServer as createNetServer } from 'node:net';
import { fileURLToPath } from 'node:url';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

const SERVE_ENTRY = fileURLToPath(new URL('../../scripts/serve.mjs', import.meta.url));

interface RawResponse {
  status: number;
  headers: Record<string, string | string[] | undefined>;
  body: string;
}

/** Ask the OS for a free port, then release it for the child to bind. */
async function freePort(): Promise<number> {
  const probe = createNetServer();
  await new Promise<void>((resolve) => probe.listen(0, '127.0.0.1', resolve));
  const address = probe.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  await new Promise<void>((resolve) => probe.close(() => resolve()));
  return port;
}

/**
 * Raw GET.
 *
 * Uses node:http rather than fetch so the request line is sent verbatim — the
 * traversal cases must reach the server un-normalised by the client.
 */
function get(port: number, path: string): Promise<RawResponse> {
  return new Promise((resolve, reject) => {
    const req = httpRequest(
      {
        host: '127.0.0.1',
        port,
        path,
        method: 'GET',
        headers: {
          cookie: 'wordpress_logged_in=console-user',
          'x-wp-nonce': 'console-nonce',
        },
      },
      (res) => {
        const chunks: Buffer[] = [];
        res.on('data', (chunk: Buffer) => chunks.push(chunk));
        res.on('end', () =>
          resolve({
            status: res.statusCode ?? 0,
            headers: res.headers,
            body: Buffer.concat(chunks).toString('utf8'),
          }),
        );
      },
    );
    req.on('error', reject);
    req.end();
  });
}

describe('connected-app proxy', () => {
  let consoleProcess: ChildProcess | undefined;
  let upstream: Server | undefined;
  let consolePort = 0;
  let upstreamPort = 0;
  let lastUpstreamHeaders: Record<string, string | string[] | undefined> = {};
  let lastUpstreamUrl = '';

  beforeAll(async () => {
    upstream = createHttpServer((req, res) => {
      lastUpstreamUrl = req.url ?? '';
      lastUpstreamHeaders = req.headers;

      if (lastUpstreamUrl.startsWith('/wp-json/mcp-ai/v1/features')) {
        res.writeHead(200, {
          'Content-Type': 'application/json; charset=utf-8',
          'Cache-Control': 'no-store',
          'Set-Cookie': 'wp_session=1; Path=/',
        });
        res.end(
          JSON.stringify({
            success: true,
            data: { features: {}, endpoints: { docsHub: true } },
          }),
        );
        return;
      }

      res.writeHead(200, {
        'Content-Type': 'application/json; charset=utf-8',
        'Set-Cookie': 'product_session=1; Path=/',
        'Strict-Transport-Security': 'max-age=63072000',
      });
      res.end(JSON.stringify({ ok: true, path: lastUpstreamUrl }));
    });

    await new Promise<void>((resolve) => upstream?.listen(0, '127.0.0.1', resolve));
    const upstreamAddress = upstream.address();
    upstreamPort =
      typeof upstreamAddress === 'object' && upstreamAddress ? upstreamAddress.port : 0;

    consolePort = await freePort();
    consoleProcess = spawn(process.execPath, [SERVE_ENTRY], {
      env: {
        ...process.env,
        PORT: String(consolePort),
        NVOOS_TARGET_SITE: `http://127.0.0.1:${upstreamPort}`,
        NVOOS_BACKEND: 'wordpress',
        NVOOS_PROXY_CACHE: '0',
        NVOOS_CONNECTED_APPS: JSON.stringify({
          contentGraph: {
            base: `http://127.0.0.1:${upstreamPort}/wp-json/nvoos-content-graph/v1`,
            token: 'product-secret',
          },
          docsHub: {
            base: `http://127.0.0.1:${upstreamPort}/wp-json/nvoos-docs/v1`,
            cacheTtl: 60,
          },
        }),
      },
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    // Readiness: poll the static surface until the listener answers.
    let ready = false;
    for (let attempt = 0; attempt < 120 && !ready; attempt += 1) {
      try {
        await get(consolePort, '/');
        ready = true;
      } catch {
        await new Promise((resolve) => setTimeout(resolve, 50));
      }
    }
    if (!ready) {
      throw new Error('console server did not start');
    }
  }, 30_000);

  afterAll(async () => {
    consoleProcess?.kill();
    await new Promise<void>((resolve) => {
      if (!upstream) {
        resolve();
        return;
      }
      upstream.close(() => resolve());
    });
  });

  it('reaches the configured upstream with the base path preserved', async () => {
    const response = await get(consolePort, '/connected/contentGraph/pages');

    expect(response.status).toBe(200);
    expect(JSON.parse(response.body).path).toBe('/wp-json/nvoos-content-graph/v1/pages');
    expect(response.headers['x-nvoos-connected']).toBe('contentGraph');
  });

  it('forwards the query string', async () => {
    const response = await get(consolePort, '/connected/contentGraph/pages?per_page=10');

    expect(response.status).toBe(200);
    expect(JSON.parse(response.body).path).toBe(
      '/wp-json/nvoos-content-graph/v1/pages?per_page=10',
    );
  });

  it('does not forward console cookies, nonce or Authorization', async () => {
    await get(consolePort, '/connected/contentGraph/pages');

    expect(lastUpstreamHeaders.cookie).toBeUndefined();
    expect(lastUpstreamHeaders['x-wp-nonce']).toBeUndefined();
    // The browser's Authorization is replaced by the configured per-app token.
    expect(lastUpstreamHeaders.authorization).toBe('Bearer product-secret');
  });

  it('strips Set-Cookie and origin-policy headers from the response', async () => {
    const response = await get(consolePort, '/connected/contentGraph/pages');

    expect(response.headers['set-cookie']).toBeUndefined();
    expect(response.headers['strict-transport-security']).toBeUndefined();
  });

  it('is no-store by default and honours a product TTL', async () => {
    const noStore = await get(consolePort, '/connected/contentGraph/pages');
    const cached = await get(consolePort, '/connected/docsHub/pages');

    expect(noStore.headers['cache-control']).toBe('no-store');
    expect(cached.headers['cache-control']).toBe('public, max-age=60');
  });

  it('refuses an unknown product key', async () => {
    const response = await get(consolePort, '/connected/evilProduct/pages');

    expect(response.status).toBe(404);
    expect(JSON.parse(response.body).code).toBe('nvoos_connected_unknown_app');
  });

  it('refuses encoded traversal', async () => {
    // WHATWG URL normalisation resolves a single-encoded %2e%2e before the
    // handler runs, so this arrives as an unknown key (404). Either refusal
    // is correct — what matters is that it never reaches an upstream.
    const response = await get(consolePort, '/connected/contentGraph/%2e%2e/secret');

    expect([400, 404]).toContain(response.status);
    expect(JSON.parse(response.body).code).toMatch(/^nvoos_connected_(path_invalid|unknown_app)$/);
  });

  it('refuses double-encoded traversal', async () => {
    // %25 survives URL normalisation, so the policy layer is what refuses it.
    const response = await get(consolePort, '/connected/contentGraph/%252e%252e/secret');

    expect(response.status).toBe(400);
    expect(JSON.parse(response.body).code).toBe('nvoos_connected_path_invalid');
  });

  it('refuses an encoded path separator', async () => {
    const response = await get(consolePort, '/connected/contentGraph/a%2fb');

    expect(response.status).toBe(400);
    expect(JSON.parse(response.body).code).toBe('nvoos_connected_path_invalid');
  });

  it('advertises the proxy on the availability read', async () => {
    const response = await get(consolePort, '/wp-json/mcp-ai/v1/features');
    const payload = JSON.parse(response.body) as {
      data: { connected_proxy?: boolean; connected_apps?: string[] };
    };

    expect(response.status).toBe(200);
    expect(payload.data.connected_proxy).toBe(true);
    expect(payload.data.connected_apps).toEqual(['contentGraph', 'docsHub']);
  });
});
