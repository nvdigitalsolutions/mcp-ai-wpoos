// Adaptation script: Convert WordPress chat-memory-service.js to a standalone NPM package.
// The source is already a thin REST proxy with `request()` + 8 verbs. We replace the
// `window.wpMcpAiChat` lookups with a module-level `_config` object configured via
// `configure({ endpoints, headers, fetch })`.

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-memory-service.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'chat-memory-service.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Strip IIFE wrapper.
console.log('   → Converting from IIFE to ES module');
code = code.replace(/\(function\(window\) \{\s*'use strict';/, '');

// Drop the trailing exports + IIFE close.
code = code.replace(
	/\twindow\[SERVICE_NAME\] = \{[\s\S]*?\};\s*\}\)\(window\);\s*$/,
	''
);

// Step 2: Drop the SERVICE_NAME constant — no longer needed.
console.log('   → Removing global service registration');
code = code.replace(/\tconst SERVICE_NAME = 'wpMcpAiChatMemory';\s*\n/, '');

// Step 3: Replace getConfig/getNonce/getEndpoints with module-level _config readers.
console.log('   → Replacing WP globals with injectable configuration');
code = code.replace(
	/\tfunction getConfig\(\)[\s\S]*?\tfunction getNonce\(\)[\s\S]*?\t\}\n/,
	`\tfunction getEndpoints() {
		return (_config && _config.endpoints) || null;
	}

	function getExtraHeaders() {
		return (_config && _config.headers) || {};
	}

	function getFetch() {
		if (_config && typeof _config.fetch === 'function') {
			return _config.fetch;
		}
		if (typeof globalThis !== 'undefined' && typeof globalThis.fetch === 'function') {
			return globalThis.fetch.bind(globalThis);
		}
		throw new Error('nvoos-chat-memory: no fetch implementation available. Pass one via configure({ fetch }).');
	}

	function getCredentials() {
		return _config && _config.credentials ? _config.credentials : 'same-origin';
	}\n`
);

// Drop the now-redundant standalone getEndpoints() — our replacement is above.
code = code.replace(/\tfunction getEndpoints\(\) \{\s*const cfg = getConfig\(\);\s*return \(cfg && cfg\.memoryEndpoints\) \|\| null;\s*\}\s*\n/, '');

// Step 4: Replace `request()` body to use injectable fetch + headers (drop X-WP-Nonce).
console.log('   → Routing requests through injectable fetch + headers');
code = code.replace(
	/\tfunction request\(url, options\) \{[\s\S]*?\t\}\);\s*\}\n/,
	`	function request(url, options) {
		options = options || {};
		const headers = Object.assign(
			{ Accept: 'application/json' },
			getExtraHeaders(),
			options.headers || {}
		);

		let body;
		if (options.body && typeof options.body === 'object') {
			headers['Content-Type'] = 'application/json';
			body = JSON.stringify(options.body);
		} else {
			body = options.body;
		}

		const fetchImpl = getFetch();
		return fetchImpl(url, {
			method: options.method || 'GET',
			credentials: getCredentials(),
			headers: headers,
			body: body
		}).then(function(response) {
			return response.json().then(
				function(data) {
					if (!response.ok) {
						const error = new Error(
							(data && (data.message || data.code)) || ('HTTP ' + response.status)
						);
						error.status = response.status;
						error.data = data;
						throw error;
					}
					return data;
				},
				function() {
					if (!response.ok) {
						const error = new Error('HTTP ' + response.status);
						error.status = response.status;
						throw error;
					}
					return null;
				}
			);
		});
	}\n`
);

// Step 5: Update storeBeacon() to use injectable fetch + extra headers (drop nonce inline).
code = code.replace(
	/\t\treturn window\.fetch\(eps\.store, \{[\s\S]*?\}\);\s*\}\n/,
	`		const fetchImpl = getFetch();
		return fetchImpl(eps.store, {
			method: 'POST',
			credentials: getCredentials(),
			keepalive: true,
			headers: Object.assign(
				{ Accept: 'application/json', 'Content-Type': 'application/json' },
				getExtraHeaders()
			),
			body: JSON.stringify(body)
		}).then(function(response) {
			if (!response.ok) {
				const error = new Error('HTTP ' + response.status);
				error.status = response.status;
				throw error;
			}
			return response.json().catch(function() { return null; });
		});
	}\n`
);

// Step 6: Prepend module-level config + configure() function.
const configBlock = `let _config = { endpoints: null, headers: {}, fetch: null, credentials: 'same-origin' };

/**
 * Configure the chat memory client.
 *
 * @param {Object} options
 * @param {Object} options.endpoints - Endpoint URL map. Required keys:
 *   - wakeUp, recall, store, itemBase, preferences (and optional: audit).
 *   itemBase MUST end with a slash and accepts an appended URL-encoded contextId.
 * @param {Object} [options.headers] - Extra request headers (e.g. auth tokens).
 * @param {Function} [options.fetch] - Custom fetch implementation. Defaults to globalThis.fetch.
 * @param {RequestCredentials} [options.credentials] - fetch credentials mode. Default: 'same-origin'.
 */
function configure(options) {
	options = options || {};
	if (options.endpoints) _config.endpoints = options.endpoints;
	if (options.headers) _config.headers = options.headers;
	if (typeof options.fetch === 'function') _config.fetch = options.fetch;
	if (options.credentials) _config.credentials = options.credentials;
}

`;

// Step 7: Wire up ES module exports.
const exportBlock = `

export {
	configure,
	isAvailable,
	wakeUp,
	recall,
	store,
	storeBeacon,
	update,
	remove,
	remove as delete_,
	audit,
	getPreferences,
	setPreferences,
	isMemoryRetrievalResult
};

export default {
	configure: configure,
	isAvailable: isAvailable,
	wakeUp: wakeUp,
	recall: recall,
	store: store,
	storeBeacon: storeBeacon,
	update: update,
	remove: remove,
	'delete': remove,
	audit: audit,
	getPreferences: getPreferences,
	setPreferences: setPreferences,
	isMemoryRetrievalResult: isMemoryRetrievalResult
};
`;

const finalCode = configBlock + code.trim() + exportBlock;

const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

fs.writeFileSync(path.join(distDir, 'nvoos-chat-memory.js'), finalCode);
console.log('   → Generated dist/nvoos-chat-memory.js');

const dtsContent = `/**
 * Promise-based client for the NV oOS chat-memory REST bridge.
 * @package @nvdigitalsolutions/nvoos-chat-memory
 */

export interface ChatMemoryEndpoints {
	wakeUp: string;
	recall: string;
	store: string;
	/** Base URL for /{contextId} item operations. MUST end with a slash. */
	itemBase: string;
	preferences: string;
	audit?: string;
}

export interface ChatMemoryConfig {
	endpoints: ChatMemoryEndpoints;
	headers?: Record<string, string>;
	fetch?: typeof fetch;
	credentials?: RequestCredentials;
}

export interface WakeUpParams {
	agentId?: string;
	wing?: string;
	room?: string;
}

export interface RecallFilters {
	agentId?: string;
	wing?: string;
	room?: string;
	limit?: number;
}

export interface StorePayload {
	agentId?: string;
	wing?: string;
	room?: string;
	title?: string;
	content?: string;
	tags?: string[];
	importance?: number | string;
	contextType?: string;
	verbatim?: boolean;
	summarize?: boolean;
}

export interface UpdatePatch {
	agentId?: string;
	title?: string;
	content?: string;
	tags?: string[];
	importance?: number | string;
}

export interface AuditOptions {
	agentId?: string;
	limit?: number;
	actionType?: string;
}

export interface MemoryPreferences {
	enabled?: boolean;
	autosummarize?: boolean;
}

export declare function configure(options: ChatMemoryConfig): void;
export declare function isAvailable(): boolean;
export declare function wakeUp(params?: WakeUpParams): Promise<any>;
export declare function recall(query: string, filters?: RecallFilters): Promise<any>;
export declare function store(payload: StorePayload): Promise<any>;
export declare function storeBeacon(payload: StorePayload): Promise<any>;
export declare function update(contextId: string, patch: UpdatePatch): Promise<any>;
export declare function remove(contextId: string, options?: { agentId?: string }): Promise<any>;
export { remove as delete_ };
export declare function audit(options?: AuditOptions): Promise<any>;
export declare function getPreferences(): Promise<MemoryPreferences>;
export declare function setPreferences(prefs: MemoryPreferences): Promise<any>;
export declare function isMemoryRetrievalResult(result: unknown): boolean;

declare const _default: {
	configure: typeof configure;
	isAvailable: typeof isAvailable;
	wakeUp: typeof wakeUp;
	recall: typeof recall;
	store: typeof store;
	storeBeacon: typeof storeBeacon;
	update: typeof update;
	remove: typeof remove;
	'delete': typeof remove;
	audit: typeof audit;
	getPreferences: typeof getPreferences;
	setPreferences: typeof setPreferences;
	isMemoryRetrievalResult: typeof isMemoryRetrievalResult;
};

export default _default;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-chat-memory.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
