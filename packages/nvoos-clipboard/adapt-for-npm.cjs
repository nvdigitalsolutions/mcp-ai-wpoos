// Adaptation script: Convert WordPress clipboard service to standalone NPM package

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-clipboard-service.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'chat-clipboard-service.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Remove IIFE wrapper
console.log('   → Converting from IIFE to ES module');
code = code.replace(/\(function\(window\) \{\s*'use strict';/, '');
code = code.replace(/\/\/ Export public API[\s\S]*?window\.wpMcpAiChatClipboard = \{[\s\S]*?\};\s*\}\)\(window\);/, '');

// Step 2: Replace WordPress-specific CSS class constants with configurable defaults.
// Consumers can call configure() to override these for their own CSS.
console.log('   → Making CSS class names configurable');
code = code.replace(
  /const COPY_BUTTON_CLASS = 'wp-mcp-ai-copy-button';/,
  "let COPY_BUTTON_CLASS = 'nvoos-copy-button';"
);
code = code.replace(
  /const COPY_ENABLED_CLASS = 'wp-mcp-ai-copy-enabled';/,
  "let COPY_ENABLED_CLASS = 'nvoos-copy-enabled';"
);
code = code.replace(
  /const COPY_ERROR_CLASS = 'wp-mcp-ai-copy-button--error';/,
  "let COPY_ERROR_CLASS = 'nvoos-copy-button--error';"
);

// Step 3: Remove the wpMcpAiChatDomBatcher dependency block (the fallback is already
// built-in, so we use that fallback pattern as the default and skip the global lookup).
console.log('   → Removing WordPress DOM batcher dependency');
code = code.replace(
  /\/\*\*\s*\n\s*\* DOM update batcher reference[\s\S]*?const domUpdateBatcher = window\.wpMcpAiChatDomBatcher \|\| \{[\s\S]*?\};\s*\n/,
  `// Schedule DOM updates using requestAnimationFrame when available, or fall back to
	// immediate synchronous execution. Callers can override via configure().
	let domUpdateBatcher = {
		schedule: function(fn) {
			if (typeof fn === 'function') {
				if (typeof requestAnimationFrame !== 'undefined') {
					requestAnimationFrame(fn);
				} else {
					fn();
				}
			}
		}
	};\n\n`
);

// Step 4: Add configure() function so callers can customise class names and the batcher
console.log('   → Adding configure() export');
const configureBlock = `
/**
 * Configure the clipboard service.
 *
 * @param {Object} options Configuration options
 * @param {string} [options.copyButtonClass]  CSS class added to each copy button
 * @param {string} [options.copyEnabledClass] CSS class added to the host element
 * @param {string} [options.copyErrorClass]   CSS class added to button on error
 * @param {Object} [options.domBatcher]       DOM scheduler with a schedule(fn) method
 */
function configure(options = {}) {
	if (options.copyButtonClass) COPY_BUTTON_CLASS = options.copyButtonClass;
	if (options.copyEnabledClass) COPY_ENABLED_CLASS = options.copyEnabledClass;
	if (options.copyErrorClass) COPY_ERROR_CLASS = options.copyErrorClass;
	if (options.domBatcher && typeof options.domBatcher.schedule === 'function') {
		domUpdateBatcher = options.domBatcher;
	}
}

`;

// Insert before the first function definition
code = code.replace(
  /\t\/\*\*\s*\n\s*\* Update copy button visual state\./,
  configureBlock + '\t/**\n\t * Update copy button visual state.'
);

// Step 5: Add ES module exports
code = code.trim() + `

// ES Module exports
export {
	configure,
	copyTextToClipboard,
	fallbackCopyText,
	attachCopyButton,
	updateCopyButtonState,
	resolveCopyText
};

export default {
	configure,
	copyTextToClipboard,
	fallbackCopyText,
	attachCopyButton,
	updateCopyButtonState,
	resolveCopyText
};
`;

// Step 6: Write dist
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

const outputFile = path.join(distDir, 'nvoos-clipboard.js');
fs.writeFileSync(outputFile, code);
console.log('   → Generated dist/nvoos-clipboard.js');

// Step 7: TypeScript definitions
const dtsContent = `/**
 * Clipboard copy utilities with Clipboard API and legacy execCommand fallback.
 * Zero external dependencies — uses only standard browser APIs.
 * @package @nvdigitalsolutions/nvoos-clipboard
 */

export interface ClipboardConfig {
  /** CSS class applied to each copy button. Default: 'nvoos-copy-button' */
  copyButtonClass?: string;
  /** CSS class applied to the host element. Default: 'nvoos-copy-enabled' */
  copyEnabledClass?: string;
  /** CSS class applied to the button on error. Default: 'nvoos-copy-button--error' */
  copyErrorClass?: string;
  /** Custom DOM scheduler. Defaults to requestAnimationFrame. */
  domBatcher?: { schedule: (fn: () => void) => void };
}

/**
 * Configure global defaults for the clipboard service.
 */
export declare function configure(options: ClipboardConfig): void;

/**
 * Copy text to clipboard using the modern Clipboard API with legacy fallback.
 * @returns Promise resolving to true on success, false on failure.
 */
export declare function copyTextToClipboard(text: string): Promise<boolean>;

/**
 * Legacy clipboard copy using execCommand (for browsers without Clipboard API).
 * @returns Promise resolving to true on success, false on failure.
 */
export declare function fallbackCopyText(text: string): Promise<boolean>;

/**
 * Attach a copy button to a DOM element.
 * The button uses Clipboard API with execCommand fallback automatically.
 *
 * @param bubble  - The host element that will receive the copy button.
 * @param text    - Optional explicit text to copy; falls back to element text content.
 */
export declare function attachCopyButton(bubble: HTMLElement, text?: string): void;

/**
 * Programmatically set the visual state of a copy button.
 * @param button    - The copy button element.
 * @param stateName - 'idle' | 'copied' | 'error'
 */
export declare function updateCopyButtonState(
  button: HTMLElement,
  stateName: 'idle' | 'copied' | 'error'
): void;

/**
 * Resolve the text to copy from a bubble element or explicit string.
 */
export declare function resolveCopyText(bubble: HTMLElement | null, text?: string): string;

declare const _default: {
  configure: typeof configure;
  copyTextToClipboard: typeof copyTextToClipboard;
  fallbackCopyText: typeof fallbackCopyText;
  attachCopyButton: typeof attachCopyButton;
  updateCopyButtonState: typeof updateCopyButtonState;
  resolveCopyText: typeof resolveCopyText;
};

export default _default;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-clipboard.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
