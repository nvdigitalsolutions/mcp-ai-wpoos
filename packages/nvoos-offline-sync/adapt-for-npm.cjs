// Adaptation script: Convert WordPress offline chat manager to standalone NPM package

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting offline-chat-manager.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'offline-chat-manager.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Remove IIFE wrapper — offline-chat-manager.js already uses a bare IIFE
// without 'use strict' at the top level, and already exports to module.exports.
console.log('   → Converting from IIFE to ES module');
// Remove the outer (function() { 'use strict'; ... })(); wrapper
// Note: no ^ anchor — the IIFE starts after the file-level JSDoc comment
code = code.replace(/\(function\(\) \{\s*'use strict';/, '');
code = code.replace(/\/\/ Also export as module[\s\S]*?module\.exports = OfflineChatManager;\s*\}\s*\}\)\(\);/, '');
// Remove the window global export block (we'll use ES module exports instead)
code = code.replace(/\/\/ Export to global scope[\s\S]*?window\.WP_MCP_AI_OfflineChatManager = OfflineChatManager;\s*\}/, '');

// Step 2: Make the WordPress-specific hardcodings injectable via constructor options.
// Replace window.wpMcpAi?.restUrl and window.wpMcpAi?.nonce with this.options.*
console.log('   → Making WordPress globals injectable via constructor options');

// Replace hardcoded WordPress REST URL and nonce with options
code = code.replace(
  /const response = await fetch\(window\.wpMcpAi\?\.restUrl \+ '\/chat\/save', \{/,
  'const syncUrl = this.options.syncUrl;\n\t\t\tif (!syncUrl) throw new Error(\'nvoos-offline-sync: syncUrl not configured. Pass it to the constructor.\');\n\t\t\tconst response = await fetch(syncUrl, {'
);
code = code.replace(
  /'X-WP-Nonce': window\.wpMcpAi\?\.nonce \|\| ''/,
  "...(this.options.syncHeaders || {})"
);
// Remove 'Content-Type' line that was part of the headers object since we spread now
code = code.replace(
  /'Content-Type': 'application\/json',\s*\.\.\.\(this\.options\.syncHeaders \|\| \{\}\)/,
  "'Content-Type': 'application/json',\n\t\t\t\t\t...( this.options.syncHeaders || {} )"
);

// Step 3: Inject options parameter into constructor and add defaults
console.log('   → Adding constructor options');
code = code.replace(
  /constructor\(\) \{/,
  `/**
	 * @param {Object} [options] Configuration options
	 * @param {string} [options.syncUrl]         Server endpoint for syncing messages
	 * @param {Record<string,string>} [options.syncHeaders] Extra headers sent on sync requests
	 * @param {string} [options.dbName]          IndexedDB database name. Default: 'nvoos-offline'
	 * @param {number} [options.dbVersion]       IndexedDB schema version. Default: 1
	 * @param {boolean} [options.showOfflineUI]  Show/hide the built-in offline banner. Default: true
	 */
	constructor(options = {}) {`
);

// Update the constructor body to use options
code = code.replace(
  /this\.db = null;\s*\n\s*this\.syncQueue = \[\];\s*\n\s*this\.isOnline = navigator\.onLine;\s*\n\s*this\.dbName = 'wp-mcp-ai-offline';\s*\n\s*this\.dbVersion = 1;/,
  `this.options = options;
		this.db = null;
		this.syncQueue = [];
		this.isOnline = navigator.onLine;
		this.dbName = options.dbName || 'nvoos-offline';
		this.dbVersion = options.dbVersion || 1;
		this.showOfflineUI = options.showOfflineUI !== false;`
);

// Step 4: Gate the offline notice UI on the showOfflineUI flag
code = code.replace(
  /handleOffline\(\) \{\s*\n\s*this\.isOnline = false;\s*\n\s*this\.showOfflineNotice\(\);/,
  `handleOffline() {
		this.isOnline = false;
		if (this.showOfflineUI) {
			this.showOfflineNotice();
		}`
);

// Also gate the notice removal in handleOnline
code = code.replace(
  /\/\/ Hide offline notice if showing\s*\n\s*const notice = document\.querySelector\('\.wp-mcp-ai-offline-notice'\);\s*\n\s*if \(notice\) \{\s*\n\s*notice\.remove\(\);\s*\n\s*\}/,
  `// Hide offline notice if showing
		if (this.showOfflineUI) {
			const notice = document.querySelector('.nvoos-offline-notice');
			if (notice) notice.remove();
		}`
);

// Step 5: Update the offline notice CSS class from WordPress prefix to package prefix
code = code.replace(/wp-mcp-ai-offline-notice/g, 'nvoos-offline-notice');

// Step 6: Add ES module export
code = code.trim() + `

// ES Module export
export { OfflineChatManager };
export default OfflineChatManager;
`;

// Step 7: Write dist
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

const outputFile = path.join(distDir, 'nvoos-offline-sync.js');
fs.writeFileSync(outputFile, code);
console.log('   → Generated dist/nvoos-offline-sync.js');

// Step 8: TypeScript definitions
const dtsContent = `/**
 * IndexedDB-backed offline-first sync manager.
 * Saves data locally immediately and syncs to a server endpoint when online.
 * Zero external dependencies — uses only IndexedDB, fetch, and standard browser APIs.
 * @package @nvdigitalsolutions/nvoos-offline-sync
 */

export interface OfflineSyncOptions {
  /**
   * Server endpoint URL that receives POST requests with the message body.
   * Required for server sync. If omitted, messages are stored locally only.
   */
  syncUrl?: string;

  /**
   * Additional HTTP headers sent with every sync request.
   * Use this to pass authorization tokens, CSRF tokens, etc.
   * @example { 'Authorization': 'Bearer token', 'X-CSRF-Token': 'abc' }
   */
  syncHeaders?: Record<string, string>;

  /**
   * IndexedDB database name. Default: 'nvoos-offline'
   */
  dbName?: string;

  /**
   * IndexedDB schema version. Increment when changing the schema. Default: 1
   */
  dbVersion?: number;

  /**
   * Show the built-in offline banner when the device goes offline.
   * Set to false to implement your own offline UI. Default: true
   */
  showOfflineUI?: boolean;
}

export interface OfflineMessage {
  id?: number;
  timestamp?: number;
  synced?: boolean;
  [key: string]: unknown;
}

export declare class OfflineChatManager {
  options: OfflineSyncOptions;
  db: IDBDatabase | null;
  syncQueue: OfflineMessage[];
  isOnline: boolean;
  dbName: string;
  dbVersion: number;
  showOfflineUI: boolean;

  constructor(options?: OfflineSyncOptions);

  /** Open (or upgrade) the IndexedDB database. Called by initialize(). */
  openDatabase(): Promise<IDBDatabase>;

  /** Initialize the manager. Must be called before saving messages. */
  initialize(): Promise<void>;

  /**
   * Save a message locally and sync to server when online.
   * If offline, the message is queued and sent when connectivity is restored.
   */
  saveMessage(message: Record<string, unknown>): Promise<void>;

  /** Persist a message to the local IndexedDB store. */
  saveToLocal(message: Record<string, unknown>): Promise<number>;

  /** Send a single message to the configured syncUrl. */
  syncToServer(message: OfflineMessage): Promise<void>;

  /** Mark a locally-stored message as successfully synced. */
  markAsSynced(messageId: number): Promise<void>;

  /** Handle the browser coming back online — drains the sync queue. */
  handleOnline(): Promise<void>;

  /** Handle the browser going offline. */
  handleOffline(): void;

  /** Display the built-in offline notification banner. */
  showOfflineNotice(): void;

  /** Retrieve all locally stored messages. */
  getAllMessages(): Promise<OfflineMessage[]>;

  /** Erase all locally stored messages and conversations. */
  clearAllData(): Promise<void>;
}

export default OfflineChatManager;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-offline-sync.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
