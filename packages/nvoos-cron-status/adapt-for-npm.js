// Adaptation script: Convert WordPress cron-status-service.js to a standalone NPM package.
// The source uses two WP globals (wpMcpAiSSE, wpMcpAiJobBus) and one CSS class. We replace
// them with module-level injectable adapters set via configure().

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting cron-status-service.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'cron-status-service.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Strip IIFE wrapper + duplicate-init guard.
console.log('   → Converting from IIFE to ES module');
code = code.replace(
	/\(function \(window\) \{\s*'use strict';\s*\/\/ Prevent multiple initialization\s*if \(window\.wpMcpAiCronStatus\) \{\s*return;\s*\}/,
	''
);

// Step 2: Drop the global export + IIFE close.
code = code.replace(
	/\s*\/\/ Export to global scope\s*\n\s*window\.wpMcpAiCronStatus = CronStatusService;\s*\n\s*\}\)\(window\);\s*$/,
	''
);

// Step 3: Replace `window.wpMcpAiSSE` → `_config.sseAdapter`.
console.log('   → Replacing window.wpMcpAiSSE with injectable sseAdapter');
code = code.replace(/window\.wpMcpAiSSE/g, '_config.sseAdapter');

// Step 4: Replace `window.wpMcpAiJobBus` → `_config.jobBus`.
console.log('   → Replacing window.wpMcpAiJobBus with injectable jobBus');
code = code.replace(/window\.wpMcpAiJobBus/g, '_config.jobBus');

// Step 5: Replace WP-specific CSS class with a configurable one.
console.log('   → Making job-clickable CSS class configurable');
code = code.replace(/'wp-mcp-ai-job-clickable'/g, '_config.jobClickableClass');

// Step 6: Tidy log prefix to match package identity.
code = code.replace(/\[NV oOS\] /g, '[nvoos-cron-status] ');

// Step 7: Drop legacy `window.console &&` guards — `console` is always defined in
// modern targets (Node 18+, all evergreen browsers). We keep the calls themselves.
code = code.replace(/if \(window\.console && console\.error\) \{\s*console\.error\(/g, 'if (typeof console !== "undefined") { console.error(');
code = code.replace(/if \(window\.console && console\.warn\) \{\s*console\.warn\(/g, 'if (typeof console !== "undefined") { console.warn(');
code = code.replace(/if \(window\.console && console\.log\) \{\s*console\.log\(/g, 'if (typeof console !== "undefined") { console.log(');

// Step 8: Prepend the configurable module-level state + configure() helper.
const configBlock = `const _config = {
	/**
	 * SSE adapter compatible with @nvdigitalsolutions/nvoos-events SSEService.
	 * Must expose:
	 *   isSupported(): boolean
	 *   connect(url, options): { close(): void } | null
	 * When omitted, the service skips SSE and uses REST polling only.
	 */
	sseAdapter: null,

	/**
	 * Job event bus with a handleJobUpdate(jobId, payload) method.
	 * Compatible with @nvdigitalsolutions/nvoos-events JobEventBus.
	 * When omitted, individual cron_job_status emissions are dropped silently.
	 */
	jobBus: null,

	/**
	 * CSS class added to elements made clickable by attachClickHandlers().
	 * Default mirrors the upstream WordPress plugin for drop-in compatibility.
	 */
	jobClickableClass: 'nvoos-job-clickable'
};

/**
 * Configure injectable dependencies for the cron status service.
 *
 * @param {Object} options
 * @param {Object} [options.sseAdapter]      SSE service with isSupported() + connect().
 * @param {Object} [options.jobBus]          Job event bus with handleJobUpdate(jobId, payload).
 * @param {string} [options.jobClickableClass] CSS class for click-enabled job elements.
 */
export function configure(options) {
	options = options || {};
	if (options.sseAdapter !== undefined) _config.sseAdapter = options.sseAdapter;
	if (options.jobBus !== undefined)     _config.jobBus = options.jobBus;
	if (options.jobClickableClass)        _config.jobClickableClass = options.jobClickableClass;
}

`;

// Step 9: ES module exports.
const exportBlock = `

// ES Module exports
export { CronStatusService };
export default CronStatusService;
`;

const finalCode = configBlock + code.trim() + exportBlock;

const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

fs.writeFileSync(path.join(distDir, 'nvoos-cron-status.js'), finalCode);
console.log('   → Generated dist/nvoos-cron-status.js');

const dtsContent = `/**
 * SSE-first cron/job status monitor with REST polling fallback.
 * @package @nvdigitalsolutions/nvoos-cron-status
 */

export interface SSEAdapter {
	isSupported(): boolean;
	connect(url: string, options: {
		eventHandlers?: Record<string, (data: any) => void>;
		onError?: (err?: any) => void;
		onOpen?: () => void;
	}): { close: () => void } | null;
}

export interface JobBusAdapter {
	handleJobUpdate(jobId: string | number, payload: any): void;
}

export interface CronStatusConfig {
	sseAdapter?: SSEAdapter | null;
	jobBus?: JobBusAdapter | null;
	jobClickableClass?: string;
}

export type StatusCallback = (data: any) => void;

export interface CronStatusServiceShape {
	fallbackPollingInterval: number;
	maxPollingInterval: number;
	backoffMultiplier: number;
	maxPollingAttempts: number;

	fetchStatusREST(
		endpoint: string,
		nonce: string | null,
		limit?: number,
		assistantId?: string | number,
		guestToken?: string
	): Promise<any | null>;

	startMonitoring(
		containerId: string,
		endpoint: string,
		nonce: string | null,
		callback: StatusCallback,
		assistantId?: string | number,
		guestToken?: string
	): void;

	stopMonitoring(containerId: string): void;
	stopSSE(containerId: string): void;

	emitJobUpdates(data: { jobs?: Array<{ job_id?: string | number }> }): void;

	/** @deprecated use startMonitoring */
	startPolling(...args: any[]): void;
	/** @deprecated use stopMonitoring */
	stopPolling(containerId: string): void;
}

export declare function configure(options: CronStatusConfig): void;
export declare const CronStatusService: CronStatusServiceShape;
export default CronStatusService;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-cron-status.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
