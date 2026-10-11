// Adaptation script: Convert WordPress plugin code to standalone NPM package
// This script removes WordPress-specific code and makes the module framework-agnostic

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting llm-worker-manager.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'llm-worker-manager.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Replace WordPress global with configurable property
console.log('   → Removing WordPress global object references');
code = code.replace(
  /getWorkerUrl\(\) \{[\s\S]*?return pluginUrl \+ 'assets\/js\/workers\/llm-worker\.js';[\s\S]*?\}/,
  `getWorkerUrl() {
		if ( ! this.config.workerUrl ) {
			throw new Error( 'nvoos-llm-worker: workerUrl is not configured. Call configure({ workerUrl }) first.' );
		}
		return this.config.workerUrl;
	}`
);

// Step 2: Update log prefixes
console.log('   → Updating log prefixes');
code = code.replace(/\[NV oOS Worker Manager\]/g, '[nvoos-llm-worker]');

// Step 3: Strip the IIFE wrapper
console.log('   → Converting to ES module format');
code = code.replace(/\(function\(\) \{\s*'use strict';/, '');
// Remove global window export and trailing IIFE close
code = code.replace(/\/\/ Export to global scope[\s\S]*?window\.WP_MCP_AI_LLM_Worker_Manager = WP_MCP_AI_LLM_Worker_Manager;[\s\S]*?\}\)\(\);/, '');
// Remove trailing class-loaded log if it remains
code = code.replace(/console\.log\(\s*'\[nvoos-llm-worker\] Class loaded'\s*\);\s*/g, '');

// Step 4: Rename class to a clean export name and add config support
console.log('   → Renaming class and adding configure() method');
code = code.replace(
  /class WP_MCP_AI_LLM_Worker_Manager \{\s*constructor\(\) \{/,
  `class LLMWorkerManager {
	constructor( options = {} ) {
		this.config = {
			workerUrl: options.workerUrl || null,
			workerOptions: options.workerOptions || { type: 'module' }
		};`
);

// Step 5: Replace hardcoded workerOptions in createWorker
code = code.replace(
  /this\.worker = new Worker\( workerUrl, \{ type: 'module' \} \);/,
  'this.worker = new Worker( workerUrl, this.config.workerOptions );'
);

// Step 6: Add configure() method
const configureMethod = `
	/**
	 * Configure the worker manager.
	 * @param {Object} options
	 * @param {string} [options.workerUrl] URL to the LLM worker script.
	 * @param {Object} [options.workerOptions] Options forwarded to the Worker constructor.
	 */
	configure( options = {} ) {
		if ( options.workerUrl ) {
			this.config.workerUrl = options.workerUrl;
		}
		if ( options.workerOptions ) {
			this.config.workerOptions = options.workerOptions;
		}
	}

`;

// Insert configure() right after isSupported() method
code = code.replace(
  /(isSupported\(\) \{\s*return typeof Worker !== 'undefined';\s*\})/,
  '$1\n' + configureMethod
);

// Step 7: Append ES module exports
code = code.trim() + '\n\n// ES Module exports\nexport { LLMWorkerManager };\nexport default LLMWorkerManager;\n';

// Step 8: Write dist
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) {
  fs.mkdirSync(distDir, { recursive: true });
}

const outputFile = path.join(distDir, 'nvoos-llm-worker.js');
fs.writeFileSync(outputFile, code);
console.log('   → Generated dist/nvoos-llm-worker.js');

// Step 9: TypeScript definitions
const dtsContent = `/**
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
`;

fs.writeFileSync(path.join(distDir, 'nvoos-llm-worker.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
