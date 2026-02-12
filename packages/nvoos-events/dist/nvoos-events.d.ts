/**
 * Real-time event coordination system
 * @package @nvdigital/nvoos-events
 */

import { fetchEventSource } from '@microsoft/fetch-event-source';

export interface SSEOptions {
  method?: 'GET' | 'POST';
  headers?: Record<string, string>;
  body?: string | object;
  onMessage?: (data: any, event: MessageEvent) => void;
  onError?: (error: Error) => void;
  onOpen?: (response: Response) => void;
  eventHandlers?: Record<string, (data: any, event: MessageEvent) => void>;
  openWhenHidden?: boolean;
}

export interface SSEConnection {
  ctrl: AbortController;
  close: () => void;
  abort: () => void;
}

export declare const SSEService: {
  connections: Record<string, any>;
  debug: boolean;
  
  isSupported(): boolean;
  enableDebug(): void;
  disableDebug(): void;
  getReadyStateName(readyState: number): string;
  connect(url: string, options: SSEOptions): SSEConnection | null;
  closeConnection(key: string): void;
  closeAll(): void;
  generateConnectionKey(url: string): string;
  getConnectionCount(): number;
};

export interface EventBusHandler {
  (event: any): void;
}

export interface EventBus {
  all: Map<string | symbol, EventBusHandler[]>;
  on(type: string | symbol, handler: EventBusHandler): void;
  off(type: string | symbol, handler?: EventBusHandler): void;
  emit(type: string | symbol, event?: any): void;
}

export interface JobWatchOptions {
  onProgress?: (data: any) => void;
  timeout?: number;
}

export interface JobEventBusType extends EventBus {
  cache: Record<string, any>;
  handleJobUpdate(jobId: string, data: any): void;
  watchJob(jobId: string, options?: JobWatchOptions): Promise<any>;
  getCached(jobId: string): any | null;
  clearCache(jobId: string): void;
  clearAllCache(): void;
}

export declare const JobEventBus: JobEventBusType;

export declare function createEventBus(all?: Map<string | symbol, EventBusHandler[]>): EventBus;

declare const _default: {
  SSEService: typeof SSEService;
  JobEventBus: typeof JobEventBus;
  createEventBus: typeof createEventBus;
};

export default _default;
