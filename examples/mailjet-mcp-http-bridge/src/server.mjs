#!/usr/bin/env node
// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Streamable HTTP bridge in front of @mailjet/mailjet-mcp-server.
 *
 * The official server (github.com/mailgun/mailjet-mcp-server) speaks **stdio
 * only** and is **read-only by design**: it registers one tool per GET endpoint
 * in its bundled OpenAPI spec and never registers POST/PUT/DELETE. That is why
 * this bridge can expose it safely — there is no write surface to accidentally
 * widen.
 *
 * Why a per-request McpServer instance instead of one shared server:
 * `registerTool()` closes over the `userContext` passed to
 * `generateToolsFromOpenApi()`, and `makeMailjetRequest()` reads the key pair
 * from that closure. A shared instance would therefore serve whichever
 * credentials were registered first to every caller. Constructing the server
 * per request keeps credentials isolated; it costs object construction only —
 * no network, no spec re-parse.
 *
 * Credentials arrive per request:
 *   Authorization: Bearer <BRIDGE_TOKEN>      (tenancy gate for this bridge)
 *   X-Mailjet-Key:    <mailjet api key>
 *   X-Mailjet-Secret: <mailjet api secret>
 *   X-Mailjet-Region: us|eu                    (optional, default us)
 *
 * Nothing here is logged or echoed. Run this behind TLS and a secret manager;
 * the bridge does not store Mailjet credentials.
 */

import http from 'node:http';
import process from 'node:process';

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import {
  generateToolsFromOpenApi,
  parsedOpenApiSpec,
} from '@mailjet/mailjet-mcp-server';

const PORT = Number.parseInt(process.env.PORT ?? '8080', 10);
const BRIDGE_TOKEN = process.env.BRIDGE_TOKEN ?? '';
const MAX_BODY_BYTES = 1024 * 1024;
const SERVER_VERSION = '0.1.0';

// The upstream server resolves its API hostname ONCE, at module load, from
// MAILJET_API_REGION. It is therefore process-level, not per-call: one bridge
// deployment serves exactly one region. `X-Mailjet-Region` is validated against
// this value so a misrouted client gets a clear error instead of a confusing
// 401 from the wrong host.
const PROCESS_REGION = 'eu' === String(process.env.MAILJET_API_REGION ?? '').toLowerCase() ? 'eu' : 'us';

// Mirrors the upstream server's own exclusions, so /health reports the same
// count the client will discover.
const UNSUPPORTED_GET_PATHS = new Set([
  '/v3/REST/apikey',
  '/v3/REST/apikey/{apikey_ID}',
]);

/**
 * Count the tools upstream will register: one per supported GET operation.
 *
 * @param {object} spec Parsed OpenAPI document.
 * @returns {number}
 */
function countSupportedTools(spec) {
  let count = 0;

  for (const [path, item] of Object.entries(spec?.paths ?? {})) {
    if (item?.get && !UNSUPPORTED_GET_PATHS.has(path)) {
      count += 1;
    }
  }

  return count;
}

const TOOL_COUNT = countSupportedTools(parsedOpenApiSpec);

/**
 * Read a request body with a hard cap.
 *
 * @param {http.IncomingMessage} req
 * @returns {Promise<string>}
 */
function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];

    req.on('data', (chunk) => {
      size += chunk.length;

      if (size > MAX_BODY_BYTES) {
        reject(new Error('Request body too large.'));
        req.destroy();
        return;
      }

      chunks.push(chunk);
    });

    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

/**
 * @param {http.ServerResponse} res
 * @param {number} status
 * @param {object} payload
 */
function sendJson(res, status, payload) {
  const body = JSON.stringify(payload);

  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
  });
  res.end(body);
}

/**
 * Constant-time-ish comparison for the bridge token.
 *
 * @param {string} provided
 * @returns {boolean}
 */
function tokenMatches(provided) {
  if ('' === BRIDGE_TOKEN) {
    // No token configured: the gate is disabled on purpose, and credentials are
    // still required for every call. See /health `token_required`.
    return true;
  }

  if (provided.length !== BRIDGE_TOKEN.length) {
    return false;
  }

  let mismatch = 0;

  for (let i = 0; i < provided.length; i += 1) {
    mismatch |= provided.charCodeAt(i) ^ BRIDGE_TOKEN.charCodeAt(i);
  }

  return 0 === mismatch;
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', `http://${req.headers.host ?? 'localhost'}`);

  if ('/health' === url.pathname) {
    sendJson(res, 200, {
      ok: true,
      server: 'mailjet-mcp-http-bridge',
      version: SERVER_VERSION,
      // The bridge refuses to serve without a token, so "configured" is the
      // honest health signal.
      // When no token is configured the bridge is usable by anyone holding a
      // valid Mailjet key pair — read-only, but still worth knowing. Set
      // BRIDGE_TOKEN in any shared or production deployment.
      token_required: '' !== BRIDGE_TOKEN,
      region: PROCESS_REGION,
      tools: TOOL_COUNT,
      read_only: true,
    });
    return;
  }

  if ('/mcp' !== url.pathname) {
    sendJson(res, 404, { error: 'not_found' });
    return;
  }

  const authorization = String(req.headers.authorization ?? '');

  // Two accepted shapes:
  //  - `Authorization: Basic base64(key:secret)` — what an NV oOS
  //    `mailjet_mcp` connection sends for its `basic_auth` mapping. Optional
  //    tenancy gate via `X-Bridge-Token` when BRIDGE_TOKEN is set.
  //  - `Authorization: Bearer <BRIDGE_TOKEN>` + `X-Mailjet-Key/Secret` — for
  //    proxies or callers that prefer to keep the pair out of Authorization.
  let apiKey = String(req.headers['x-mailjet-key'] ?? '').trim();
  let apiSecret = String(req.headers['x-mailjet-secret'] ?? '').trim();
  let gateToken = String(req.headers['x-bridge-token'] ?? '').trim();

  if ('' === apiKey && authorization.startsWith('Basic ')) {
    const decoded = Buffer.from(authorization.slice(6).trim(), 'base64').toString('utf8');
    const separator = decoded.indexOf(':');

    if (separator > 0) {
      apiKey = decoded.slice(0, separator).trim();
      apiSecret = decoded.slice(separator + 1).trim();
    }
  } else if (authorization.startsWith('Bearer ')) {
    gateToken = authorization.slice(7).trim();
  }

  if ('' !== BRIDGE_TOKEN && !tokenMatches(gateToken)) {
    res.setHeader('WWW-Authenticate', 'Bearer realm="mailjet-mcp"');
    sendJson(res, 401, { error: 'unauthorized' });
    return;
  }

  const requestedRegion = String(req.headers['x-mailjet-region'] ?? '').trim().toLowerCase();

  if ('eu' === requestedRegion || 'us' === requestedRegion) {
    if (requestedRegion !== PROCESS_REGION) {
      sendJson(res, 400, {
        error: 'wrong_region',
        message: `This bridge serves the ${PROCESS_REGION} region. Deploy a second bridge with MAILJET_API_REGION=${requestedRegion} for keys minted there.`,
      });
      return;
    }
  }

  if ('' === apiKey || '' === apiSecret) {
    sendJson(res, 400, {
      error: 'missing_mailjet_credentials',
      message: 'Send Authorization: Basic base64(key:secret), or X-Mailjet-Key and X-Mailjet-Secret. The bridge holds no credentials of its own.',
    });
    return;
  }

  let parsedBody;

  if ('POST' === req.method) {
    try {
      const raw = await readBody(req);
      parsedBody = '' === raw ? undefined : JSON.parse(raw);
    } catch (error) {
      sendJson(res, 400, { error: 'invalid_body', message: String(error?.message ?? error) });
      return;
    }
  }

  const mcp = new McpServer({ name: 'mailjet', version: SERVER_VERSION });

  // Per-request credentials, closed over by every registered tool handler.
  // Region is deliberately absent: it is process-level (see PROCESS_REGION).
  generateToolsFromOpenApi(parsedOpenApiSpec, mcp, { apiKey, secretKey: apiSecret });

  const transport = new StreamableHTTPServerTransport({
    // Stateless: the bridge keeps no session table, so a redeploy cannot strand
    // a client holding a session id that no longer exists.
    sessionIdGenerator: undefined,
    enableJsonResponse: true,
  });

  res.on('close', () => {
    transport.close().catch(() => {});
    mcp.close().catch(() => {});
  });

  try {
    await mcp.connect(transport);
    await transport.handleRequest(req, res, parsedBody);
  } catch (error) {
    if (!res.headersSent) {
      sendJson(res, 500, { error: 'transport_error', message: String(error?.message ?? error) });
    }
  }
});

server.listen(PORT, () => {
  // stdout only; never print credentials or the token.
  console.log(`mailjet-mcp-http-bridge listening on :${PORT} (${PROCESS_REGION} region, ${TOOL_COUNT} read-only tools)`);

  if ('' === BRIDGE_TOKEN) {
    console.warn('WARNING: BRIDGE_TOKEN is unset — any valid Mailjet key pair may use this read-only bridge. Set it for any shared deployment.');
  }
});
