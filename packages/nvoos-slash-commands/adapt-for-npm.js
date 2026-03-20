// Adaptation script: Convert WordPress slash-commands + command-autocomplete to standalone NPM package

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting slash-commands + command-autocomplete for NPM distribution...\n');

// ─── 1. Read source files ────────────────────────────────────────────────────
console.log('   → Reading source files');
let autocompleteCode = fs.readFileSync(path.join(__dirname, 'command-autocomplete.js'), 'utf8');
let slashCode        = fs.readFileSync(path.join(__dirname, 'slash-commands.js'), 'utf8');

// ─── 2. CommandAutocomplete: strip IIFE wrapper ───────────────────────────────
console.log('   → Stripping CommandAutocomplete IIFE wrapper');
// Remove opening: (function() { 'use strict';
autocompleteCode = autocompleteCode.replace(/^\(function\(\) \{\s*\n\s*'use strict';\s*\n/, '');
// Remove closing: window.CommandAutocomplete = …; and })();
autocompleteCode = autocompleteCode.replace(/\s*\/\/ Export for external use\s*\n\s*window\.CommandAutocomplete = CommandAutocomplete;\s*\n\s*\}\)\(\);\s*$/, '');

// ─── 3. SlashCommandsHandler: strip IIFE wrapper and auto-init ─────────────────
console.log('   → Stripping SlashCommandsHandler IIFE wrapper and auto-init');
// Remove opening
slashCode = slashCode.replace(/^\(function\(\) \{\s*\n\s*'use strict';\s*\n/, '');
// Remove auto-init block and global assignments at the end:
//   // Initialize when DOM is ready
//   if (document.readyState === ... { ... }
//   window.SlashCommandsHandler = SlashCommandsHandler;
slashCode = slashCode.replace(
	/\s*\/\/ Initialize when DOM is ready[\s\S]*?window\.SlashCommandsHandler = SlashCommandsHandler;\s*\n\s*\}\)\(\);\s*$/,
	''
);

// ─── 4. Make mcpAiData dependency injectable ─────────────────────────────────
console.log('   → Making mcpAiData config injectable');
// Add a static configure() method to SlashCommandsHandler that sets a module-level config
const configBlock = `
/**
 * Module-level config, injected via SlashCommandsHandler.configure() or
 * the constructor options argument. Falls back to window.mcpAiData for
 * backwards-compatibility in WordPress environments.
 */
let _moduleConfig = null;

/**
 * Helper: return the active config object (instance override → module config → WP global).
 */
function _getConfig() {
	return _moduleConfig || (typeof window !== 'undefined' ? window.mcpAiData : null) || {};
}

`;
slashCode = configBlock + slashCode;

// Patch constructor to accept optional config argument
slashCode = slashCode.replace(
	'constructor() {',
	`constructor(config) {
			if (config) {
				_moduleConfig = config;
			}`
);

// Patch all window.mcpAiData?.* reads to use _getConfig()
slashCode = slashCode.replace(/window\.mcpAiData\?\./g, '_getConfig().');
slashCode = slashCode.replace(/window\.mcpAiData &&\s*window\.mcpAiData\./g, '_getConfig() && _getConfig().');
// Fix the broader guard used in init()
slashCode = slashCode.replace(
	'if (!window.mcpAiData || !window.mcpAiData.slashCommandListEndpoint)',
	'if (!_getConfig() || !_getConfig().slashCommandListEndpoint)'
);
// Patch any remaining bare window.mcpAiData references
slashCode = slashCode.replace(/window\.mcpAiData/g, '_getConfig()');

// ─── 5. Add static configure() ───────────────────────────────────────────────
console.log('   → Adding SlashCommandsHandler.configure()');
slashCode = slashCode.replace(
	'\n\t// Initialize when DOM is ready',
	`

	/**
	 * Configure the module-level defaults shared across all instances.
	 * Call this once before constructing any SlashCommandsHandler.
	 *
	 * @param {Object} config
	 * @param {string} config.restUrl                   - Base REST URL
	 * @param {string} config.nonce                     - Authentication nonce
	 * @param {string} config.slashCommandEndpoint      - Execute endpoint URL
	 * @param {string} config.slashCommandListEndpoint  - List endpoint URL
	 */
	static configure(config) {
		_moduleConfig = config;
	}

\t// Initialize when DOM is ready`
);

// ─── 6. Add auto-init helper (opt-in) ────────────────────────────────────────
const autoInitBlock = `
/**
 * Convenience helper: initialise a SlashCommandsHandler when the DOM is ready.
 * Returns the handler instance so callers can call .init() themselves if preferred.
 *
 * @param {Object} [config] - Optional config passed to SlashCommandsHandler constructor
 * @returns {SlashCommandsHandler}
 */
function createSlashCommands(config) {
	const handler = new SlashCommandsHandler(config);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			handler.init();
		});
	} else {
		handler.init();
	}
	return handler;
}
`;

// ─── 7. Combine + add ES module exports ───────────────────────────────────────
console.log('   → Combining modules and adding ES exports');
const combined = `${autocompleteCode.trim()}

${slashCode.trim()}
${autoInitBlock}
// ES Module exports
export { CommandAutocomplete, SlashCommandsHandler, createSlashCommands };
export default { CommandAutocomplete, SlashCommandsHandler, createSlashCommands };
`;

// ─── 8. Write dist ────────────────────────────────────────────────────────────
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) {
	fs.mkdirSync(distDir, { recursive: true });
}
fs.writeFileSync(path.join(distDir, 'nvoos-slash-commands.js'), combined);
console.log('   → Generated dist/nvoos-slash-commands.js');

// ─── 9. TypeScript definitions ────────────────────────────────────────────────
const dts = `/**
 * Slash command system with fuzzy-search autocomplete and execution engine.
 * Zero external dependencies — uses only standard browser APIs.
 * @package @nvdigitalsolutions/nvoos-slash-commands
 */

// ─── CommandAutocomplete ──────────────────────────────────────────────────────

export interface SlashCommand {
  name: string;
  description?: string;
  aliases?: string[];
  [key: string]: unknown;
}

export declare class CommandAutocomplete {
  constructor(inputElement: HTMLInputElement | HTMLTextAreaElement);
  init(): void;
  show(input: string): void;
  hide(): void;
  isVisible(): boolean;
  handleKeyDown(e: KeyboardEvent): boolean;
  destroy(): void;
}

// ─── SlashCommandsHandler ────────────────────────────────────────────────────

export interface SlashCommandsConfig {
  restUrl?: string;
  nonce?: string;
  slashCommandEndpoint?: string;
  slashCommandListEndpoint?: string;
  [key: string]: unknown;
}

export declare class SlashCommandsHandler {
  constructor(config?: SlashCommandsConfig);
  static configure(config: SlashCommandsConfig): void;
  init(): void;
  executeCommand(command: string): Promise<void>;
  fetchCommands(): Promise<SlashCommand[]>;
  announceToScreenReader(message: string): void;
  destroy?(): void;
}

// ─── createSlashCommands ─────────────────────────────────────────────────────

/**
 * Convenience factory: creates and auto-initialises a SlashCommandsHandler.
 */
export declare function createSlashCommands(config?: SlashCommandsConfig): SlashCommandsHandler;

declare const _default: {
  CommandAutocomplete: typeof CommandAutocomplete;
  SlashCommandsHandler: typeof SlashCommandsHandler;
  createSlashCommands: typeof createSlashCommands;
};
export default _default;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-slash-commands.d.ts'), dts);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
