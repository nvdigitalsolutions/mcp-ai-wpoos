// Adaptation script: Convert WordPress client-tools registry to standalone NPM package
// The source already declares CLIENT_TOOLS as a plain object literal — the adaptation
// strips the IIFE wrapper, removes the WordPress global export, replaces the bare
// `pipeline` reference with an injectable factory, and adds ES module exports.

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting client-tools.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'client-tools.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Replace the `/* global pipeline */` linter directive — we now look up
// pipeline through a configurable resolver instead of a global.
console.log('   → Replacing global `pipeline` resolver');
code = code.replace(/\/\* global pipeline \*\/\n/, '');

// Step 2: Strip IIFE wrapper.
console.log('   → Converting from IIFE to ES module');
code = code.replace(/\(function\(\) \{\s*'use strict';/, '');
code = code.replace(
	/\/\/ Export to global scope[\s\S]*?\}\)\(\);\s*$/,
	''
);

// Step 3: Replace bare `pipeline(...)` calls with `getPipeline()(...)`.
// The user provides the actual pipeline factory via `configure({ pipeline: fn })`.
console.log('   → Routing pipeline calls through configure() resolver');
code = code.replace(/await pipeline\(/g, 'await getPipeline()(');

// Step 4: Prepend a configurable resolver so consumers can inject either the
// CDN-loaded global or a bundled `@huggingface/transformers` import.
const configBlock = `
let pipelineFactory = null;

/**
 * Configure the client tools registry.
 *
 * @param {Object} options
 * @param {Function} [options.pipeline] - Transformers.js pipeline factory.
 *   Typically: \`import { pipeline } from '@huggingface/transformers'\`
 *   or a globally-loaded CDN copy.
 */
export function configure(options) {
	options = options || {};
	if (typeof options.pipeline === 'function') {
		pipelineFactory = options.pipeline;
	}
}

function getPipeline() {
	if (typeof pipelineFactory === 'function') {
		return pipelineFactory;
	}
	if (typeof globalThis !== 'undefined' && typeof globalThis.pipeline === 'function') {
		return globalThis.pipeline;
	}
	throw new Error(
		'nvoos-client-tools: pipeline factory is not configured. ' +
		'Call configure({ pipeline }) with the Transformers.js pipeline function before executing tools.'
	);
}

`;

// Step 5: Trim then append registry helpers + ES module exports.
code = code.trim();

const exportBlock = `

/**
 * Get the full tool registry as an object keyed by tool name.
 * @returns {Object} Map of tool name → tool definition
 */
export function getTools() {
	return CLIENT_TOOLS;
}

/**
 * Get a single tool definition by name.
 * @param {string} name
 * @returns {Object|null}
 */
export function getTool(name) {
	if (!name) return null;
	return Object.prototype.hasOwnProperty.call(CLIENT_TOOLS, name) ? CLIENT_TOOLS[name] : null;
}

/**
 * Execute a tool by name with the provided arguments.
 * @param {string} name - Tool name (e.g. 'client_summarize')
 * @param {Object} args - Tool arguments
 * @returns {Promise<*>} Tool result
 */
export function executeTool(name, args) {
	const tool = getTool(name);
	if (!tool || typeof tool.execute !== 'function') {
		return Promise.reject(new Error('nvoos-client-tools: unknown tool "' + name + '"'));
	}
	return Promise.resolve(tool.execute(args || {}));
}

export { CLIENT_TOOLS };

export default {
	configure: configure,
	getTools: getTools,
	getTool: getTool,
	executeTool: executeTool,
	CLIENT_TOOLS: CLIENT_TOOLS
};
`;

const finalCode = configBlock + code + exportBlock;

// Step 6: Write dist
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

const outputFile = path.join(distDir, 'nvoos-client-tools.js');
fs.writeFileSync(outputFile, finalCode);
console.log('   → Generated dist/nvoos-client-tools.js');

// Step 7: TypeScript definitions
const dtsContent = `/**
 * Browser-native AI tool registry powered by Transformers.js.
 * @package @nvdigitalsolutions/nvoos-client-tools
 */

export interface ClientToolParameters {
	type: 'object';
	properties: Record<string, unknown>;
	required?: string[];
}

export interface ClientTool {
	name: string;
	description: string;
	parameters: ClientToolParameters;
	execute(args: Record<string, any>): Promise<any>;
}

export interface ClientToolsConfig {
	/** Transformers.js pipeline factory function. */
	pipeline?: (...args: any[]) => Promise<any>;
}

/** Configure the registry (inject a pipeline factory). */
export declare function configure(options: ClientToolsConfig): void;

/** Get the full tool registry as an object keyed by tool name. */
export declare function getTools(): Record<string, ClientTool>;

/** Get a single tool definition by name. */
export declare function getTool(name: string): ClientTool | null;

/** Execute a tool by name with the provided arguments. */
export declare function executeTool(name: string, args?: Record<string, any>): Promise<any>;

/** The raw tool registry. */
export declare const CLIENT_TOOLS: Record<string, ClientTool>;

declare const _default: {
	configure: typeof configure;
	getTools: typeof getTools;
	getTool: typeof getTool;
	executeTool: typeof executeTool;
	CLIENT_TOOLS: typeof CLIENT_TOOLS;
};

export default _default;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-client-tools.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
