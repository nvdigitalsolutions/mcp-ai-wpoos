/**
 * Resilient HTTP client with automatic retry, exponential backoff, and request hooks.
 * Built on top of ky for a lightweight, modern fetch wrapper.
 * @package @nvdigitalsolutions/nvoos-http-client
 */

export interface RetryConfig {
  /** Maximum number of retries. Default: 3 */
  limit: number;
  /** HTTP methods to retry. Default: all common methods */
  methods: string[];
  /** HTTP status codes that trigger a retry. Default: [408, 413, 429, 500, 502, 503, 504] */
  statusCodes: number[];
  /** Maximum back-off delay in ms. Default: 10000 */
  backoffLimit: number;
}

export interface HttpClientOptions {
  /** Request timeout in milliseconds. Default: 30000 */
  timeout?: number;
  /** Maximum number of retries. Default: 3 */
  retryLimit?: number;
  /** Called before each retry with retry context */
  onRetry?: (context: { url: string; error: Error; retryCount: number; maxRetries: number }) => void;
  /** Called before each request */
  beforeRequest?: (request: Request) => void;
  /** Called after each response */
  afterResponse?: (request: Request, response: Response) => void;
  /** Called when a 401 Unauthorized response is received */
  onAuthFailure?: (context: { url: string; status: number; statusText: string }) => void;
}

export interface RequestOptions {
  /** Request timeout in milliseconds */
  timeout?: number;
  /** Maximum number of retries */
  retryLimit?: number;
  /** Retry callback */
  onRetry?: HttpClientOptions['onRetry'];
  /** AbortSignal for request cancellation */
  signal?: AbortSignal;
  /** Credentials mode: 'include', 'same-origin', 'omit' */
  credentials?: RequestCredentials;
}

export interface ParsedError {
  message: string;
  status: number | null;
  statusText: string | null;
  data: unknown;
}

export declare const DEFAULT_TIMEOUT: number;
export declare const DEFAULT_RETRY_CONFIG: RetryConfig;

/**
 * Create a configured ky instance with retry logic and hooks.
 */
export declare function createHttpClient(options?: HttpClientOptions): object;

/**
 * POST JSON data with automatic retry.
 */
export declare function postJson(
  url: string,
  data: unknown,
  headers?: Record<string, string>,
  options?: RequestOptions
): Promise<Response>;

/**
 * Upload a File or Blob with automatic retry.
 */
export declare function uploadFile(
  url: string,
  file: File | Blob,
  headers?: Record<string, string>,
  options?: RequestOptions
): Promise<Response>;

/**
 * GET request with automatic retry.
 */
export declare function get(
  url: string,
  headers?: Record<string, string>,
  options?: RequestOptions
): Promise<Response>;

/**
 * DELETE request with automatic retry.
 */
declare function _delete(
  url: string,
  headers?: Record<string, string>,
  options?: RequestOptions
): Promise<Response>;
export { _delete as delete };

/**
 * Parse a ky error response into a structured object.
 */
export declare function parseError(error: Error): Promise<ParsedError>;

declare const _default: {
  createHttpClient: typeof createHttpClient;
  postJson: typeof postJson;
  uploadFile: typeof uploadFile;
  get: typeof get;
  delete: typeof _delete;
  parseError: typeof parseError;
  DEFAULT_TIMEOUT: typeof DEFAULT_TIMEOUT;
  DEFAULT_RETRY_CONFIG: typeof DEFAULT_RETRY_CONFIG;
};

export default _default;
