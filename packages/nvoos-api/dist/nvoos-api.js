/**
 * Typed REST API client for NV Open Operator System (oOS).
 *
 * Provides endpoint URL builders, typed payload constructors, and fetch
 * wrappers for WordPress REST APIs. Zero external dependencies — pure
 * fetch with typed responses.
 *
 * Source: extracted from assets/js/src/shared/api.ts + wp-rest.ts
 *
 * @package @nvdigitalsolutions/nvoos-api
 * @since   0.1.0-alpha.1
 */

// ── Config ──────────────────────────────────────────────────────────

/**
 * Minimal config needed by the endpoint builders.
 * (Type contract: see dist/nvoos-api.d.ts → ApiConfig.)
 *
 * @typedef {object} ApiConfig
 * @property {string} restUrl Base REST URL (e.g. "https://example.com/wp-json/mcp-ai/v1").
 * @property {string} [uploadEndpoint] Optional upload endpoint override.
 * @property {string} [transcriptsEndpoint] Optional transcripts endpoint override.
 * @property {string} [nonce] WordPress REST nonce for authenticated requests.
 */

// ── Endpoint Builders ────────────────────────────────────────────────

function restBase(config) {
  return config.restUrl.replace(/\/+$/, '');
}

/** Chat endpoint (POST). */
export function chatEndpoint(config) {
  return `${restBase(config)}/chat`;
}

/** Chat-client endpoint (POST, for SPA / SSE adapter). */
export function chatClientEndpoint(config) {
  return `${restBase(config)}/chat-client`;
}

/** Tools list endpoint (GET). */
export function toolsListEndpoint(config) {
  return `${restBase(config)}/tools`;
}

/** Tool execution endpoint (POST). */
export function toolExecuteEndpoint(config) {
  return `${restBase(config)}/tools/execute`;
}

/** Upload endpoint (POST multipart). */
export function uploadEndpoint(config) {
  return config.uploadEndpoint || `${restBase(config)}/upload`;
}

/** Transcripts endpoint (GET / POST / DELETE). */
export function transcriptsEndpoint(config, sessionKey) {
  const base = config.transcriptsEndpoint || `${restBase(config)}/transcripts`;
  if (!sessionKey) return base;
  return `${base}/${encodeURIComponent(sessionKey)}`;
}

/** History sessions endpoint (GET). */
export function historyEndpoint(config, params) {
  let url = `${restBase(config)}/history`;
  if (params) {
    const qs = Object.entries(params)
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
      .join('&');
    if (qs) url += `?${qs}`;
  }
  return url;
}

/** SSE endpoint (GET, EventSource). */
export function sseEndpoint(config, params) {
  let url = `${restBase(config)}/sse`;
  if (params) {
    const qs = Object.entries(params)
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
      .join('&');
    if (qs) url += `?${qs}`;
  }
  return url;
}

// ── Payload Builders ─────────────────────────────────────────────────

/**
 * Payload for a tool execution request.
 * (Type contract: see dist/nvoos-api.d.ts → ToolExecutionPayload.)
 *
 * @typedef {object} ToolExecutionPayload
 * @property {string} tool
 * @property {Record<string, unknown>} arguments
 * @property {string|number} [assistant_id]
 */

/** Build the payload for a chat request (non-streaming). */
export function buildChatPayload(assistantId, messages) {
  return {
    assistant_id: assistantId,
    messages,
    save_transcript: true,
  };
}

/** Build the payload for a tool execution request. */
export function buildToolExecutionPayload(payload) {
  return {
    tool: payload.tool,
    arguments: payload.arguments,
    ...(payload.assistant_id ? { assistant_id: payload.assistant_id } : {}),
  };
}

// ── Auth Headers ─────────────────────────────────────────────────────

/** Build authenticated WP REST headers. */
export function buildAuthHeaders(config) {
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };
  if (config.nonce) {
    headers['X-WP-Nonce'] = config.nonce;
  }
  return headers;
}

/** Build guest (unauthenticated) WP REST headers. */
export function buildGuestHeaders() {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-WP-MCP-AI-Guest': '1',
  };
}

// ── Typed Fetch Helpers ──────────────────────────────────────────────

/** Perform a typed GET request. */
export async function wpGet(url, headers, signal) {
  const response = await fetch(url, {
    method: 'GET',
    headers,
    credentials: 'same-origin',
    signal,
  });

  if (!response.ok) {
    const errorBody = await response.json().catch(() => null);
    const message = errorBody?.message ?? response.statusText;
    throw new Error(`WP REST GET ${url} ${response.status}: ${message}`);
  }

  return response.json();
}

/** Perform a typed POST request. */
export async function wpPost(url, body, headers, signal) {
  const response = await fetch(url, {
    method: 'POST',
    headers,
    credentials: 'same-origin',
    body: JSON.stringify(body),
    signal,
  });

  if (!response.ok) {
    const errorBody = await response.json().catch(() => null);
    const message = errorBody?.message ?? response.statusText;
    throw new Error(`WP REST POST ${url} ${response.status}: ${message}`);
  }

  return response.json();
}

/** Upload a file via multipart POST. */
export async function wpUpload(url, file, headers, signal) {
  const uploadHeaders = {};
  for (const [key, value] of Object.entries(headers)) {
    if (key.toLowerCase() !== 'content-type') {
      uploadHeaders[key] = value;
    }
  }

  const response = await fetch(url, {
    method: 'POST',
    headers: uploadHeaders,
    credentials: 'same-origin',
    body: file,
    signal,
  });

  if (!response.ok) {
    const errorBody = await response.json().catch(() => null);
    const message = errorBody?.message ?? response.statusText;
    throw new Error(`WP REST upload ${url} ${response.status}: ${message}`);
  }

  return response.json();
}

// ── Utilities ────────────────────────────────────────────────────────

/** Sanitise a string for safe use as a session / storage key. */
export function sanitizeSessionKey(raw) {
  return raw.replace(/[^0-9a-zA-Z_-]/g, '_');
}

/** Format bytes to a human-readable string. */
export function formatBytes(bytes, decimals = 1) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  const value = bytes / Math.pow(k, i);
  return `${parseFloat(value.toFixed(decimals))} ${sizes[i]}`;
}
