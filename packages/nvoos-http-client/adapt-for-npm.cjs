// Adaptation script: Convert WordPress plugin HTTP client to standalone NPM package

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-http-client-service.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'chat-http-client-service.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Remove IIFE wrapper
console.log('   → Converting from IIFE to ES module');
code = code.replace(/\(function\(window\) \{\s*'use strict';/, '');
code = code.replace(/\/\/ Export public API[\s\S]*?window\.wpMcpAiHttpClient = \{[\s\S]*?\};\s*\}\)\(window\);/, '');

// Step 2: Remove hardcoded 'same-origin' credentials defaults — not appropriate
// outside of WordPress same-origin contexts. The lines appear inside requestOptions
// object literals only, so match the full object property to avoid touching comments.
console.log('   → Removing hardcoded same-origin credentials');
code = code.replace(/^\t\t\tcredentials: 'same-origin',\s*\n/gm, '');

// Step 4: Add credentials forwarding in each request function
const credentialsInject = `
		// Forward caller-provided credentials (e.g., 'include' for cross-origin with cookies)
		if (options.credentials) {
			requestOptions.credentials = options.credentials;
		}

		`;

code = code.replace(
  /\/\/ Add AbortSignal if provided \(for cancellation\)\n\t\tif \(options\.signal\) \{/g,
  credentialsInject + '\t\t// Add AbortSignal if provided (for cancellation)\n\t\tif (options.signal) {'
);

// Step 5: Add ES module exports
code = code.trim() + `

// ES Module exports
export {
	createHttpClient,
	postJson,
	uploadFile,
	get,
	del as delete,
	parseError,
	DEFAULT_TIMEOUT,
	DEFAULT_RETRY_CONFIG
};

export default {
	createHttpClient,
	postJson,
	uploadFile,
	get,
	delete: del,
	parseError,
	DEFAULT_TIMEOUT,
	DEFAULT_RETRY_CONFIG
};
`;

// Step 6: Write dist
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

const outputFile = path.join(distDir, 'nvoos-http-client.js');
fs.writeFileSync(outputFile, code);
console.log('   → Generated dist/nvoos-http-client.js');

// Step 7: TypeScript definitions
const dtsContent = `/**
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
`;

fs.writeFileSync(path.join(distDir, 'nvoos-http-client.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
