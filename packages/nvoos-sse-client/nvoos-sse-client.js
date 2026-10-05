/**
 * SSE Connection Manager — TypeScript-native SSE client with lifecycle
 * tracking, per-connection status, and automatic cleanup.
 *
 * Extracted from the NV oOS TypeScript SSE service
 * (assets/js/src/services/sse.ts).
 *
 * @package @nvdigitalsolutions/nvoos-sse-client
 * @since   0.1.0-alpha.1
 */

import { fetchEventSource } from '@microsoft/fetch-event-source';

// ── Constants ────────────────────────────────────────────────────────

const MAX_RECONNECT_ATTEMPTS = 10;

export const READY_STATE = {
  CONNECTING: 0,
  OPEN: 1,
  CLOSED: 2,
};

const READY_STATE_NAMES = {
  0: 'CONNECTING',
  1: 'OPEN',
  2: 'CLOSED',
};

// ── Types ────────────────────────────────────────────────────────────
// Type contracts (ReadyState, ConnectionStatus, SseConnectionOptions,
// ConnectionEntry) live in dist/nvoos-sse-client.d.ts.

// ── Service ──────────────────────────────────────────────────────────

const connections = {};

function generateConnectionKey(url) {
  return 'sse_fetch_' + url.replace(/[^a-zA-Z0-9]/g, '_') + '_' + Date.now();
}

/** Check if the runtime supports SSE (fetch + AbortController). */
export function isSseSupported() {
  return typeof fetch !== 'undefined' && typeof AbortController !== 'undefined';
}

/** Return the human-readable name for a ready-state value. */
export function getReadyStateName(readyState) {
  return READY_STATE_NAMES[readyState] || 'UNKNOWN';
}

/**
 * Open an SSE connection.
 *
 * Returns a handle with `ctrl` (AbortController), `close()`, `abort()`,
 * and `getStatus()`. Returns `null` if SSE is not supported.
 */
export function connect(url, options = {}) {
  if (!isSseSupported()) {
    options.onError?.(new Error('fetch or AbortController not supported'));
    return null;
  }
  if (!url || typeof url !== 'string') {
    options.onError?.(new Error('Invalid URL'));
    return null;
  }

  try {
    const ctrl = new AbortController();
    const connectionKey = generateConnectionKey(url);
    let reconnectAttempts = 0;

    const fetchOptions = {
      method: options.method || 'GET',
      headers: options.headers || {},
      signal: ctrl.signal,
      openWhenHidden: options.openWhenHidden ?? false,

      async onopen(response) {
        reconnectAttempts = 0;
        if (connections[connectionKey]) {
          connections[connectionKey].status = 'open';
        }
        if (
          response.ok &&
          response.headers.get('content-type')?.includes('text/event-stream')
        ) {
          options.onOpen?.(response);
          return;
        }
        if (response.status >= 400 && response.status < 500 && response.status !== 429) {
          const errorText = await response.text();
          throw new Error('Client error (' + response.status + '): ' + errorText);
        }
        throw new Error('Server error (' + response.status + ')');
      },

      onmessage(event) {
        try {
          if (event.data === '[DONE]') return;

          let data = event.data;
          try {
            data = JSON.parse(event.data);
          } catch {
            /* raw string is fine */
          }

          if (event.event && options.eventHandlers?.[event.event]) {
            options.eventHandlers[event.event](data, event);
          }
          options.onMessage?.(data, event);
        } catch (_parseError) {
          if (globalThis.console?.error) {
            console.error('[nvoos-sse-client] Failed to parse message:', _parseError);
          }
        }
      },

      onclose() {
        if (connections[connectionKey]) {
          connections[connectionKey].status = 'closed';
        }
      },

      onerror(err) {
        reconnectAttempts++;
        options.onError?.(err);

        if (err instanceof Error && err.message.includes('Client error')) {
          throw err;
        }
        if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
          throw new Error('Max reconnection attempts reached');
        }
      },
    };

    // Attach body for POST/PUT.
    if (options.body) {
      const method = (options.method || 'GET').toUpperCase();
      if (method === 'POST' || method === 'PUT') {
        fetchOptions.body =
          typeof options.body === 'string'
            ? options.body
            : JSON.stringify(options.body);
      }
    }

    connections[connectionKey] = {
      ctrl,
      url,
      createdAt: Date.now(),
      promise: fetchEventSource(url, fetchOptions),
      status: 'connecting',
    };

    return {
      ctrl,
      close() {
        closeConnection(connectionKey);
      },
      abort() {
        ctrl.abort();
      },
      getStatus() {
        return connections[connectionKey]?.status ?? 'closed';
      },
    };
  } catch (error) {
    options.onError?.(error);
    return null;
  }
}

/** Close a specific connection by its key. */
export function closeConnection(key) {
  if (connections[key]) {
    connections[key].ctrl.abort();
    delete connections[key];
  }
}

/** Close all active connections. */
export function closeAll() {
  for (const key of Object.keys(connections)) {
    closeConnection(key);
  }
}

/** Number of currently tracked connections. */
export function getConnectionCount() {
  return Object.keys(connections).length;
}

/** Get the status of a connection by URL (first match). */
export function getConnectionStatus(url) {
  for (const key of Object.keys(connections)) {
    const conn = connections[key];
    if (conn?.url === url) {
      return conn.status;
    }
  }
  return 'closed';
}

// ── Lifecycle ────────────────────────────────────────────────────────

if (typeof window !== 'undefined') {
  window.addEventListener('beforeunload', () => {
    closeAll();
  });
}
