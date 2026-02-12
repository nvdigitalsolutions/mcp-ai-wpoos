// Adaptation script: Convert WordPress plugin event system to standalone NPM package
// Combines SSE service and job event bus into unified package

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting SSE service and event bus for NPM distribution...\n');

// Step 1: Read both source files
console.log('   → Reading source files');
const sseFile = path.join(__dirname, 'sse-service.js');
const eventBusFile = path.join(__dirname, 'job-event-bus.js');

let sseCode = fs.readFileSync(sseFile, 'utf8');
let eventBusCode = fs.readFileSync(eventBusFile, 'utf8');

// Step 2: Process SSE service
console.log('   → Processing SSE service');
// Remove IIFE wrapper
sseCode = sseCode.replace(/\(function \(window\) \{[\s]*'use strict';[\s]*\/\/ Prevent multiple initialization[\s]*if \(window\.wpMcpAiSSE\) \{[\s]*return;[\s]*\}/, '');
sseCode = sseCode.replace(/\/\/ Export to global scope[\s]*window\.wpMcpAiSSE = SSEService;[\s]*\/\/ Clean up connections[\s\S]*?\}\)\(window\);/, '');

// Update debug checks to remove WordPress-specific globals
sseCode = sseCode.replace(/if \(window\.wpMcpAiDebug && window\.console/g, 'if (this.debug && console');
sseCode = sseCode.replace(/window\.console && console/g, 'console');

// Add configuration support
const sseConfigAddition = `
	SSEService.debug = false;
	
	/**
	 * Enable debug logging
	 */
	SSEService.enableDebug = function() {
		this.debug = true;
	};
	
	/**
	 * Disable debug logging
	 */
	SSEService.disableDebug = function() {
		this.debug = false;
	};
`;

sseCode = sseCode.replace('const SSEService = {', 'const SSEService = {' + sseConfigAddition);

// Step 3: Process Event Bus
console.log('   → Processing event bus');
// Remove IIFE wrapper
eventBusCode = eventBusCode.replace(/\(function \(window\) \{[\s]*'use strict';[\s]*\/\/ Prevent multiple initialization[\s]*if \(window\.wpMcpAiJobBus\) \{[\s]*return;[\s]*\}/, '');
eventBusCode = eventBusCode.replace(/\/\/ Export to global scope[\s]*window\.wpMcpAiJobBus = JobEventBus;[\s]*\/\/ Also export[\s\S]*?\}\)\(window\);/, '');

// Step 4: Combine both modules
console.log('   → Combining modules');
const combinedCode = `${sseCode}

${eventBusCode}

// ES Module exports
export { SSEService, JobEventBus, createEventBus };
export default { SSEService, JobEventBus, createEventBus };
`;

// Step 5: Create dist directory
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) {
  fs.mkdirSync(distDir, { recursive: true });
}

// Step 6: Write combined file
const outputFile = path.join(distDir, 'nvoos-events.js');
fs.writeFileSync(outputFile, combinedCode);

console.log('   → Generated dist/nvoos-events.js');

// Step 7: Generate TypeScript definitions
const dtsContent = `/**
 * Real-time event coordination system
 * @package @nvdigital/nvoos-events
 */

import { fetchEventSource } from '@microsoft/fetch-event-source';

export interface SSEOptions {
  method?: 'GET' | 'POST';
  headers?: Record<string, string>;
  body?: string | object;
  onMessage?: (data: any, event: MessageEvent) => void;
  onError?: (error: Error) => void;
  onOpen?: (response: Response) => void;
  eventHandlers?: Record<string, (data: any, event: MessageEvent) => void>;
  openWhenHidden?: boolean;
}

export interface SSEConnection {
  ctrl: AbortController;
  close: () => void;
  abort: () => void;
}

export declare const SSEService: {
  connections: Record<string, any>;
  debug: boolean;
  
  isSupported(): boolean;
  enableDebug(): void;
  disableDebug(): void;
  getReadyStateName(readyState: number): string;
  connect(url: string, options: SSEOptions): SSEConnection | null;
  closeConnection(key: string): void;
  closeAll(): void;
  generateConnectionKey(url: string): string;
  getConnectionCount(): number;
};

export interface EventBusHandler {
  (event: any): void;
}

export interface EventBus {
  all: Map<string | symbol, EventBusHandler[]>;
  on(type: string | symbol, handler: EventBusHandler): void;
  off(type: string | symbol, handler?: EventBusHandler): void;
  emit(type: string | symbol, event?: any): void;
}

export interface JobWatchOptions {
  onProgress?: (data: any) => void;
  timeout?: number;
}

export interface JobEventBusType extends EventBus {
  cache: Record<string, any>;
  handleJobUpdate(jobId: string, data: any): void;
  watchJob(jobId: string, options?: JobWatchOptions): Promise<any>;
  getCached(jobId: string): any | null;
  clearCache(jobId: string): void;
  clearAllCache(): void;
}

export declare const JobEventBus: JobEventBusType;

export declare function createEventBus(all?: Map<string | symbol, EventBusHandler[]>): EventBus;

declare const _default: {
  SSEService: typeof SSEService;
  JobEventBus: typeof JobEventBus;
  createEventBus: typeof createEventBus;
};

export default _default;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-events.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
