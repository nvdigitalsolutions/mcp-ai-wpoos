/**
 * Build script: Adapt the nvoos-api source for NPM distribution.
 *
 * Copies the pre-authored ES module source into dist/, generates TypeScript
 * definitions, and ensures the exports map is correct.
 *
 * @package @nvdigitalsolutions/nvoos-api
 */

const fs = require('fs');
const path = require('path');

const DIST = path.join(__dirname, 'dist');
const SRC = path.join(__dirname, 'nvoos-api.js');

if (!fs.existsSync(DIST)) {
  fs.mkdirSync(DIST, { recursive: true });
}

// Copy source to dist.
const code = fs.readFileSync(SRC, 'utf8');
fs.writeFileSync(path.join(DIST, 'nvoos-api.js'), code, 'utf8');

// Generate TypeScript definitions.
const dts = `/**
 * Typed REST API client for NV Open Operator System (oOS).
 * @package @nvdigitalsolutions/nvoos-api
 */

export interface ApiConfig {
  restUrl: string;
  uploadEndpoint?: string;
  transcriptsEndpoint?: string;
  nonce?: string;
}

export interface ToolExecutionPayload {
  tool: string;
  arguments: Record<string, unknown>;
  assistant_id?: string | number;
}

/** Chat endpoint (POST). */
export declare function chatEndpoint(config: ApiConfig): string;

/** Chat-client endpoint (POST, for SPA / SSE adapter). */
export declare function chatClientEndpoint(config: ApiConfig): string;

/** Tools list endpoint (GET). */
export declare function toolsListEndpoint(config: ApiConfig): string;

/** Tool execution endpoint (POST). */
export declare function toolExecuteEndpoint(config: ApiConfig): string;

/** Upload endpoint (POST multipart). */
export declare function uploadEndpoint(config: ApiConfig): string;

/** Transcripts endpoint (GET / POST / DELETE). */
export declare function transcriptsEndpoint(config: ApiConfig, sessionKey?: string): string;

/** History sessions endpoint (GET). */
export declare function historyEndpoint(config: ApiConfig, params?: Record<string, string | number>): string;

/** SSE endpoint (GET, EventSource). */
export declare function sseEndpoint(config: ApiConfig, params?: Record<string, string | number>): string;

/** Build the payload for a chat request. */
export declare function buildChatPayload(assistantId: string | number, messages: unknown[]): Record<string, unknown>;

/** Build the payload for a tool execution request. */
export declare function buildToolExecutionPayload(payload: ToolExecutionPayload): Record<string, unknown>;

/** Build authenticated WP REST headers. */
export declare function buildAuthHeaders(config: ApiConfig): Record<string, string>;

/** Build guest WP REST headers. */
export declare function buildGuestHeaders(): Record<string, string>;

/** Typed GET request. */
export declare function wpGet<T>(url: string, headers: Record<string, string>, signal?: AbortSignal): Promise<T>;

/** Typed POST request. */
export declare function wpPost<T>(url: string, body: unknown, headers: Record<string, string>, signal?: AbortSignal): Promise<T>;

/** Upload a file via multipart POST. */
export declare function wpUpload<T>(url: string, file: File, headers: Record<string, string>, signal?: AbortSignal): Promise<T>;

/** Sanitise for session/storage key. */
export declare function sanitizeSessionKey(raw: string): string;

/** Format bytes to human-readable. */
export declare function formatBytes(bytes: number, decimals?: number): string;
`;

fs.writeFileSync(path.join(DIST, 'nvoos-api.d.ts'), dts, 'utf8');

console.log('✅ nvoos-api generated successfully');
console.log(`   Output: ${path.join(DIST, 'nvoos-api.js')}`);
console.log(`   Types:  ${path.join(DIST, 'nvoos-api.d.ts')}`);
