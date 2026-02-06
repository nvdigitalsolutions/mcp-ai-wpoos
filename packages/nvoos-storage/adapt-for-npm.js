// Adaptation script: Convert WordPress plugin code to standalone NPM package
// This script removes WordPress-specific code and makes the module framework-agnostic

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting storage-util.js for NPM distribution...\n');

// Read the original WordPress plugin file
const sourceFile = path.join(__dirname, 'storage-util.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Remove WordPress-specific configuration references
console.log('   → Removing WordPress global object references');
code = code.replace(
  /const workerUrl = window\.wpMcpAiChat && window\.wpMcpAiChat\.storageWorkerUrl;/g,
  'const workerUrl = this.config.workerUrl;'
);

code = code.replace(
  /if \(!workerUrl\) \{[\s\S]*?return;[\s\S]*?\}/,
  `if (!workerUrl) {
				console.warn('nvoos-storage: Worker URL not configured. Call configure() first.');
				return;
			}`
);

// Step 2: Update console messages
console.log('   → Updating log prefixes');
code = code.replace(/NV oOS:/g, 'nvoos-storage:');

// Step 3: Convert from IIFE to ES module
console.log('   → Converting to ES module format');
code = code.replace(/\(function\(window\) \{[\s]*'use strict';/, '');
code = code.replace(/\/\/ Expose to global scope[\s]*window\.wpMcpAiStorageUtil = StorageUtil;[\s]*\}\)\(window\);/, '');

// Step 4: Add ES module exports
code = code.trim() + '\n\n// ES Module exports\nexport { StorageUtil };\nexport default StorageUtil;\n';

// Step 5: Add configuration method
const configMethod = `
	/**
	 * Configure the storage utility (call this before using)
	 * @param {Object} options Configuration options
	 * @param {string} options.workerUrl URL to the Web Worker script
	 * @param {number} options.sizeThreshold Size threshold in bytes (default: 10000)
	 */
	static configure(options = {}) {
		if (options.workerUrl) {
			this.config = this.config || {};
			this.config.workerUrl = options.workerUrl;
		}
		if (typeof options.sizeThreshold === 'number') {
			this.WORKER_THRESHOLD = options.sizeThreshold;
		}
	}

`;

// Insert configure method after class definition starts
code = code.replace(
  /(const StorageUtil = \{[\s\S]*?WORKER_THRESHOLD: 10000,)/,
  '$1' + configMethod
);

// Step 6: Create dist directory
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) {
  fs.mkdirSync(distDir, { recursive: true });
}

// Step 7: Write adapted file
const outputFile = path.join(distDir, 'nvoos-storage.js');
fs.writeFileSync(outputFile, code);

console.log('   → Generated dist/nvoos-storage.js');

// Step 8: Generate TypeScript definitions
const dtsContent = `/**
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
`;

fs.writeFileSync(path.join(distDir, 'nvoos-storage.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
