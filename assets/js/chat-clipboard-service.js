/**
 * Clipboard Service for NV oOS Chat
 * 
 * Handles copy-to-clipboard functionality with fallback support for older browsers.
 * This is a self-contained service that can be used independently.
 * 
 * @since 1.0.0
 */

(function(window) {
	'use strict';

	// Clipboard button classes and icons
	const COPY_BUTTON_CLASS = 'wp-mcp-ai-copy-button';
	const COPY_ENABLED_CLASS = 'wp-mcp-ai-copy-enabled';
	const COPY_ERROR_CLASS = 'wp-mcp-ai-copy-button--error';
	const COPY_ICON = '<svg class="wp-mcp-ai-copy-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 5a2 2 0 012-2h7a2 2 0 012 2v9a2 2 0 01-2 2H8a2 2 0 01-2-2zm2-1a1 1 0 00-1 1v9a1 1 0 001 1h7a1 1 0 001-1V5a1 1 0 00-1-1z"></path><path d="M4 7a2 2 0 012-2v1a1 1 0 00-1 1v9a1 1 0 001 1h7a1 1 0 001-1h1a2 2 0 01-2 2H6a2 2 0 01-2-2z"></path></svg>';
	const COPY_SUCCESS_ICON = '<svg class="wp-mcp-ai-copy-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M8.293 12.293l-2.147-2.146 1.414-1.414L9 10.586l3.44-3.44 1.414 1.415L9 13.414z"></path><path d="M6 3a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2zm0 1h8a1 1 0 011 1v10a1 1 0 01-1 1H6a1 1 0 01-1-1V5a1 1 0 011-1z"></path></svg>';

	/**
	 * DOM update batcher reference (if available from main chat.js).
	 * Falls back to immediate execution if not available.
	 */
	const domUpdateBatcher = window.wpMcpAiChatDomBatcher || {
		schedule: function(fn) {
			if (typeof fn === 'function') {
				fn();
			}
		}
	};

	/**
	 * Update copy button visual state.
	 * 
	 * @param {HTMLElement} button - The copy button element
	 * @param {string} stateName - State name ('idle', 'copied', 'error')
	 */
	function updateCopyButtonState(button, stateName) {
		if (!button) {
			return;
		}

		button.classList.remove(COPY_ERROR_CLASS);
		button.dataset.state = stateName;

		if (stateName === 'copied') {
			button.innerHTML = COPY_SUCCESS_ICON;
			button.setAttribute('aria-label', 'Copied response');
			button.setAttribute('title', 'Copied response');
			return;
		}

		if (stateName === 'error') {
			button.innerHTML = COPY_ICON;
			button.setAttribute('aria-label', 'Unable to copy');
			button.setAttribute('title', 'Unable to copy');
			button.classList.add(COPY_ERROR_CLASS);
			return;
		}

		button.innerHTML = COPY_ICON;
		button.setAttribute('aria-label', 'Copy response');
		button.setAttribute('title', 'Copy response');
	}

	/**
	 * Copy text to clipboard using modern API.
	 * 
	 * @param {string} text - Text to copy
	 * @return {Promise<boolean>} Promise resolving to success status
	 */
	function copyTextToClipboard(text) {
		if (!text) {
			return Promise.resolve(false);
		}

		if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
			return navigator.clipboard
				.writeText(text)
				.then(function () {
					return true;
				})
				.catch(function () {
					return fallbackCopyText(text);
				});
		}

		return fallbackCopyText(text);
	}

	/**
	 * Fallback copy method for browsers without Clipboard API.
	 * 
	 * @param {string} text - Text to copy
	 * @return {Promise<boolean>} Promise resolving to success status
	 */
	function fallbackCopyText(text) {
		return new Promise(function (resolve) {
			const textarea = document.createElement('textarea');
			textarea.value = text;
			textarea.setAttribute('readonly', '');
			textarea.style.position = 'absolute';
			textarea.style.left = '-9999px';

			document.body.appendChild(textarea);

			const selection = document.getSelection ? document.getSelection().rangeCount : 0;

			textarea.select();
			textarea.setSelectionRange(0, textarea.value.length);

			let succeeded = false;

			try {
				succeeded = document.execCommand('copy');
			} catch (error) {
				succeeded = false;
			}

			document.body.removeChild(textarea);

			if (selection && document.getSelection) {
				try {
					document.getSelection().removeAllRanges();
				} catch (error) {
					// Ignore errors restoring selection
				}
			}

			resolve(Boolean(succeeded));
		});
	}

	/**
	 * Resolve text to copy from bubble or explicit text.
	 * 
	 * @param {HTMLElement} bubble - Message bubble element
	 * @param {string} text - Explicit text to copy
	 * @return {string} Text to copy
	 */
	function resolveCopyText(bubble, text) {
		if (text && typeof text === 'string') {
			return text.trim();
		}

		if (bubble && bubble.dataset && bubble.dataset.copyText) {
			const stored = bubble.dataset.copyText.trim();
			if (stored) {
				return stored;
			}
		}

		if (!bubble) {
			return '';
		}

		let textContent = '';
		if (typeof bubble.textContent === 'string') {
			textContent = bubble.textContent;
		} else if (bubble.innerText) {
			textContent = bubble.innerText;
		}

		return textContent.trim();
	}

	/**
	 * Attach copy button to a message bubble.
	 * 
	 * @param {HTMLElement} bubble - Message bubble element
	 * @param {string} text - Optional explicit text to copy
	 */
	function attachCopyButton(bubble, text) {
		if (!bubble) {
			return;
		}

		const textToCopy = resolveCopyText(bubble, text);
		if (!textToCopy) {
			return;
		}

		if (bubble.classList) {
			bubble.classList.add(COPY_ENABLED_CLASS);
		}

		if (bubble.dataset) {
			bubble.dataset.copyText = textToCopy;
		}

		const existing = bubble.querySelector('.' + COPY_BUTTON_CLASS);
		if (existing) {
			existing.dataset.copyText = textToCopy;
			existing.disabled = false;
			updateCopyButtonState(existing, 'idle');
			return;
		}

		const button = document.createElement('button');
		button.type = 'button';
		button.className = COPY_BUTTON_CLASS;
		button.dataset.copyText = textToCopy;

		updateCopyButtonState(button, 'idle');

		button.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();

			const currentText = resolveCopyText(bubble, button.dataset.copyText || text);
			if (!currentText) {
				updateCopyButtonState(button, 'error');
				setTimeout(function () {
					domUpdateBatcher.schedule(function() {
						updateCopyButtonState(button, 'idle');
					});
				}, 2000);
				return;
			}

			button.disabled = true;

			copyTextToClipboard(currentText)
				.then(function (success) {
					if (success) {
						updateCopyButtonState(button, 'copied');
					} else {
						updateCopyButtonState(button, 'error');
					}

					setTimeout(function () {
						domUpdateBatcher.schedule(function() {
							updateCopyButtonState(button, 'idle');
							button.disabled = false;
						});
					}, 2000);
				})
				.catch(function () {
					updateCopyButtonState(button, 'error');
					setTimeout(function () {
						domUpdateBatcher.schedule(function() {
							updateCopyButtonState(button, 'idle');
							button.disabled = false;
						});
					}, 2000);
				});
		});

		bubble.appendChild(button);
	}

	// Export public API
	window.wpMcpAiChatClipboard = {
		copyTextToClipboard: copyTextToClipboard,
		attachCopyButton: attachCopyButton,
		updateCopyButtonState: updateCopyButtonState,
		COPY_BUTTON_CLASS: COPY_BUTTON_CLASS,
		COPY_ENABLED_CLASS: COPY_ENABLED_CLASS
	};

})(window);
