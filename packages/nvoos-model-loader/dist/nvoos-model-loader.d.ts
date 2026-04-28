/**
 * Progressive AI model loading UI with 4-stage progress tracking.
 * @package @nvdigitalsolutions/nvoos-model-loader
 */

export interface LoadingStage {
  name: string;
  progress: number;
  message: string;
}

export interface ProgressiveModelLoaderClassNames {
  container?: string;
  stage?: string;
  progressBar?: string;
  progressFill?: string;
  progressText?: string;
  details?: string;
  error?: string;
}

export interface InitProgressReport {
  progress: number;
  text: string;
  [key: string]: unknown;
}

export type EngineFactory = (
  modelId: string,
  opts: { initProgressCallback: (report: InitProgressReport) => void }
) => Promise<unknown>;

export interface ProgressiveModelLoaderOptions {
  engineFactory?: EngineFactory;
  classNames?: ProgressiveModelLoaderClassNames;
  stages?: LoadingStage[];
}

export declare class ProgressiveModelLoader {
  loadingStages: LoadingStage[];
  classNames: Required<ProgressiveModelLoaderClassNames>;
  engineFactory: EngineFactory | null;

  constructor(options?: ProgressiveModelLoaderOptions);
  configure(options: ProgressiveModelLoaderOptions): void;
  loadWithUI(modelId: string, container: HTMLElement): Promise<unknown>;
  checkModelCache(modelId: string): Promise<boolean>;
  downloadModel(modelId: string, onProgress: (progress: number) => void): Promise<void>;
  createLoadingUI(container: HTMLElement): HTMLElement;
  updateStage(ui: HTMLElement, stageIndex: number): void;
  updateProgress(ui: HTMLElement, progress: number): void;
  updateDetails(ui: HTMLElement, details: string): void;
  showError(ui: HTMLElement, error: Error): void;
}

export default ProgressiveModelLoader;
