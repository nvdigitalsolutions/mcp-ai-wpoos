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

import { fetchEventSource, type EventSourceMessage } from '@microsoft/fetch-event-source';

// ── Constants ────────────────────────────────────────────────────────

const MAX_RECONNECT_ATTEMPTS = 10;

export const READY_STATE = {
  CONNECTING: 0,
  OPEN: 1,
  CLOSED: 2,
} as const;

export type ReadyState = (typeof READY_STATE)[keyof typeof READY_STATE];

const READY_STATE_NAMES: Record<number, string> = {
  0: 'CONNECTING',
  1: 'OPEN',
  2: 'CLOSED',
};

export type ConnectionStatus = 'connecting' | 'open' | 'closed';

// ── Types ────────────────────────────────────────────────────────────

export interface SseConnectionOptions {
  /** HTTP method (default "GET"). */
  method?: string;
  /** Request headers. */
  headers?: Record<string, string>;
  /** Body for POST/PUT requests (string or JSON-serialisable). */
  body?: string | Record<string, unknown>;
  /** Generic message handler. */
  onMessage?: (data: unknown, event: EventSourceMessage) => void;
  /** Fatal/non-recoverable error handler. */
  onError?: (error: unknown) => void;
  /** Called when the connection opens successfully. */
  onOpen?: (response: Response) => void;
  /** Named event handlers (keyed by SSE event type). */
  eventHandlers?: Record<string, (data: unknown, event: EventSourceMessage) => void>;
  /** Keep connection alive when the tab is hidden. */
  openWhenHidden?: boolean;
}

interface ConnectionEntry {
  ctrl: AbortController;
  url: string;
  createdAt: number;
  promise: Promise<void>;
  status: ConnectionStatus;
}

// ── Service ──────────────────────────────────────────────────────────

const connections: Record<string, ConnectionEntry> = {};

function generateConnectionKey(url: string): string {
  return 'sse_fetch_' + url.replace(/[^a-zA-Z0-9]/g, '_') + '_' + Date.now();
}

/** Check if the runtime supports SSE (fetch + AbortController). */
export function isSseSupported(): boolean {
  return typeof fetch !== 'undefined' && typeof AbortController !== 'undefined';
}

/** Return the human-readable name for a ready-state value. */
export function getReadyStateName(readyState: number): string {
  return READY_STATE_NAMES[readyState] || 'UNKNOWN';
}

/**
 * Open an SSE connection.
 *
 * Returns a handle with `ctrl` (AbortController), `close()`, `abort()`,
 * and `getStatus()`. Returns `null` if SSE is not supported.
 */
export function connect(
  url: string,
  options: SseConnectionOptions = {},
): {
  ctrl: AbortController;
  close: () => void;
  abort: () => void;
  getStatus: () => ConnectionStatus;
} | null {
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

    const fetchOptions: Parameters<typeof fetchEventSource>[1] = {
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

          let data: unknown = event.data;
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
          if (
            (globalThis as unknown as { console?: Console }).console?.error
          ) {
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
export function closeConnection(key: string): void {
  if (connections[key]) {
    connections[key].ctrl.abort();
    delete connections[key];
  }
}

/** Close all active connections. */
export function closeAll(): void {
  for (const key of Object.keys(connections)) {
    closeConnection(key);
  }
}

/** Number of currently tracked connections. */
export function getConnectionCount(): number {
  return Object.keys(connections).length;
}

/** Get the status of a connection by URL (first match). */
export function getConnectionStatus(url: string): ConnectionStatus {
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
