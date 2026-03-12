/**
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
