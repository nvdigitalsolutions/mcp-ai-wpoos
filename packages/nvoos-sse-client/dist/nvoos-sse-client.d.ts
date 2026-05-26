/**
 * SSE Connection Manager — TypeScript-native SSE client with lifecycle tracking.
 * @package @nvdigitalsolutions/nvoos-sse-client
 */

import type { EventSourceMessage } from '@microsoft/fetch-event-source';

export declare const READY_STATE: {
  readonly CONNECTING: 0;
  readonly OPEN: 1;
  readonly CLOSED: 2;
};

export type ReadyState = 0 | 1 | 2;

export type ConnectionStatus = 'connecting' | 'open' | 'closed';

export interface SseConnectionOptions {
  method?: string;
  headers?: Record<string, string>;
  body?: string | Record<string, unknown>;
  onMessage?: (data: unknown, event: EventSourceMessage) => void;
  onError?: (error: unknown) => void;
  onOpen?: (response: Response) => void;
  eventHandlers?: Record<string, (data: unknown, event: EventSourceMessage) => void>;
  openWhenHidden?: boolean;
}

export interface SseHandle {
  ctrl: AbortController;
  close(): void;
  abort(): void;
  getStatus(): ConnectionStatus;
}

export declare function isSseSupported(): boolean;

export declare function getReadyStateName(readyState: number): string;

export declare function connect(
  url: string,
  options?: SseConnectionOptions,
): SseHandle | null;

export declare function closeConnection(key: string): void;

export declare function closeAll(): void;

export declare function getConnectionCount(): number;

export declare function getConnectionStatus(url: string): ConnectionStatus;
