/**
 * Web Worker manager for non-blocking LLM operations.
 * @package @nvdigitalsolutions/nvoos-llm-worker
 */

export interface LLMWorkerManagerOptions {
  workerUrl?: string;
  workerOptions?: WorkerOptions;
}

export interface ProgressEvent {
  progress?: number;
  text?: string;
  modelId?: string;
  [key: string]: unknown;
}

export interface ChunkEvent {
  content?: string;
  [key: string]: unknown;
}

export declare class LLMWorkerManager {
  worker: Worker | null;
  listeners: Map<string, (data: any) => void>;
  isInitialized: boolean;
  isWorkerReady: boolean;
  messageQueue: any[];
  config: { workerUrl: string | null; workerOptions: WorkerOptions };

  constructor(options?: LLMWorkerManagerOptions);
  configure(options: LLMWorkerManagerOptions): void;
  isSupported(): boolean;
  createWorker(): Promise<void>;
  getWorkerUrl(): string;
  loadModel(modelId: string, onProgress?: (data: ProgressEvent) => void): Promise<void>;
  generate(
    messages: Array<{ role: string; content: string }>,
    options: Record<string, unknown>,
    onChunk?: (data: ChunkEvent) => void
  ): Promise<string>;
  unloadModel(): Promise<void>;
  getStats(): Promise<string>;
  terminate(): void;
  isReady(): boolean;
}

export default LLMWorkerManager;
