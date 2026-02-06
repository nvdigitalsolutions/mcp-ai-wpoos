/**
 * Storage utility for async localStorage operations with Web Worker optimization
 * @package @nvdigital/nvoos-storage
 */

export interface StorageUtilConfig {
  workerUrl?: string;
  sizeThreshold?: number;
}

export interface StorageUtilInterface {
  worker: Worker | null;
  workerSupported: boolean;
  pendingOperations: Record<number, { resolve: Function; reject: Function }>;
  operationId: number;
  WORKER_THRESHOLD: number;
  config?: { workerUrl?: string };

  configure(options: StorageUtilConfig): void;
  initWorker(): void;
  handleWorkerMessage(e: MessageEvent): void;
  handleWorkerError(error: ErrorEvent): void;
  postToWorker(action: string, data: any): Promise<any>;
  parseJSON(jsonString: string): Promise<any>;
  stringifyJSON(obj: any): Promise<string>;
  cleanup(): void;
}

export declare const StorageUtil: StorageUtilInterface;
export default StorageUtil;
