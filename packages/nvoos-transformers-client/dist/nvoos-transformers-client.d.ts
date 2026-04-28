/**
 * HuggingFace Transformers.js task wrapper.
 * @package @nvdigitalsolutions/nvoos-transformers-client
 */

export interface TransformersTasksClientOptions {
  transformersUrl?: string;
  transformersImporter?: () => Promise<unknown>;
  device?: 'webgpu' | 'wasm' | null;
  dtype?: string;
  models?: Partial<TransformersModelMap>;
}

export interface TransformersModelMap {
  summarization: string;
  sentiment: string;
  ner: string;
  translation: string;
  qa: string;
  embedding: string;
  [key: string]: string;
}

export interface SummarizeOptions {
  maxLength?: number;
  minLength?: number;
}

export interface SummarizeResult {
  success: true;
  summary: string;
  originalLength: number;
  summaryLength: number;
}

export interface SentimentResult {
  success: true;
  label: string;
  score: number;
  confidence: string;
}

export interface NamedEntity {
  text: string;
  type: string;
  score: number;
}

export interface ExtractEntitiesResult {
  success: true;
  entities: NamedEntity[];
  count: number;
}

export interface TranslateOptions {
  sourceLang?: string;
  targetLang?: string;
}

export interface TranslateResult {
  success: true;
  translatedText: string;
  sourceLang: string;
  targetLang: string;
}

export interface QuestionAnsweringResult {
  success: true;
  answer: string;
  score: number;
  confidence: string;
  start: number;
  end: number;
}

export interface EmbedResult {
  success: true;
  embeddings: number[][];
  dimensions: number;
}

export declare class TransformersTasksClient {
  constructor(options?: TransformersTasksClientOptions);
  configure(options: TransformersTasksClientOptions): void;
  detectDevice(): Promise<'webgpu' | 'wasm'>;
  loadTransformers(): Promise<unknown>;
  getPipeline(task: string, model: string): Promise<unknown>;
  summarize(text: string, options?: SummarizeOptions): Promise<SummarizeResult>;
  sentiment(text: string): Promise<SentimentResult>;
  extractEntities(text: string): Promise<ExtractEntitiesResult>;
  translate(text: string, options?: TranslateOptions): Promise<TranslateResult>;
  questionAnswering(question: string, context: string): Promise<QuestionAnsweringResult>;
  embed(text: string | string[]): Promise<EmbedResult>;
  isTaskAvailable(task: string): boolean;
  getAvailableTasks(): string[];
  clearCache(): void;
}

export default TransformersTasksClient;
