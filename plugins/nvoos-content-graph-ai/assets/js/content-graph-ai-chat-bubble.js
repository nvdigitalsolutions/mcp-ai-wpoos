/**
 * NV oOS Content Graph AI — Chat bubble behaviour.
 *
 * Floating chat bubble toggle for the `nvoos-content-graph-ai/chat-bubble`
 * block (and any future Elementor bubble widget). Aligned port of the base
 * plugin's chat-bubble.js behaviour contract: trigger toggle, panel close
 * button, Escape key, click-outside close, auto-open delay, remember-state
 * via sessionStorage, and open/close custom events.
 *
 * Deviation (documented): the base defers chat initialisation until the
 * first open because its chat.js bundle is heavy — the CG widget is small
 * and initialises eagerly inside the hidden panel.
 *
 * @since 1.1.0
 */
(function (root) {
	'use strict';

	const CLASSES = {
		ROOT: 'nvoos-cg-bubble',
		TRIGGER: 'nvoos-cg-bubble__trigger',
		PANEL: 'nvoos-cg-bubble__panel',
		PANEL_CLOSE: 'nvoos-cg-bubble__panel-close',
		BADGE: 'nvoos-cg-bubble__badge',
		OPEN: 'nvoos-cg-bubble--open',
	};

	const EVENTS = {
		OPEN: 'nvoos-cg-bubble:open',
		CLOSE: 'nvoos-cg-bubble:close',
	};

	const STORAGE_PREFIX = 'nvoos-cg-bubble-state-';

	const instances = {};

	function storageGet(key) {
		try {
			return root.sessionStorage.getItem(key);
		} catch (e) {
			return null;
		}
	}

	function storageSet(key, value) {
		try {
			root.sessionStorage.setItem(key, value);
		} catch (e) {
			// Storage full or blocked — silently ignore.
		}
	}

	function fireEvent(el, eventName) {
		let evt;
		if (typeof root.CustomEvent === 'function') {
			evt = new root.CustomEvent(eventName, { bubbles: true, cancelable: true });
		} else {
			evt = root.document.createEvent('CustomEvent');
			evt.initCustomEvent(eventName, true, true, {});
		}
		el.dispatchEvent(evt);
	}

	/**
	 * @param {HTMLElement} rootEl Bubble root element.
	 */
	function BubbleInstance(rootEl) {
		this.root = rootEl;
		this.trigger = rootEl.querySelector('.' + CLASSES.TRIGGER);
		this.panel = rootEl.querySelector('.' + CLASSES.PANEL);
		this.closeButton = rootEl.querySelector('.' + CLASSES.PANEL_CLOSE);
		this.badge = rootEl.querySelector('.' + CLASSES.BADGE);
		this.bubbleId = rootEl.getAttribute('data-bubble-id') || 'bubble';
		this.rememberState = rootEl.getAttribute('data-remember-state') === 'true';
		this.autoOpenDelay = parseInt(rootEl.getAttribute('data-auto-open-delay') || '0', 10);
		this.isOpen = false;
	}

	BubbleInstance.prototype.init = function () {
		const self = this;

		if (!this.trigger || !this.panel) {
			return;
		}

		this.trigger.addEventListener('click', function () {
			self.toggle();
		});

		if (this.closeButton) {
			this.closeButton.addEventListener('click', function () {
				self.close();
			});
		}

		root.document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && self.isOpen) {
				self.close();
			}
		});

		root.document.addEventListener('click', function (event) {
			if (!self.isOpen) {
				return;
			}
			if (self.root.contains(event.target)) {
				return;
			}
			self.close();
		});

		// Restore a remembered open state (only after a user has interacted
		// once — the base contract remembers the last state per session).
		if (this.rememberState && storageGet(STORAGE_PREFIX + this.bubbleId) === 'open') {
			this.open();
		}

		if (this.autoOpenDelay > 0) {
			root.setTimeout(function () {
				self.open();
			}, this.autoOpenDelay);
		}
	};

	BubbleInstance.prototype.toggle = function () {
		if (this.isOpen) {
			this.close();
		} else {
			this.open();
		}
	};

	BubbleInstance.prototype.open = function () {
		if (this.isOpen) {
			return;
		}
		this.isOpen = true;
		this.root.classList.add(CLASSES.OPEN);
		this.trigger.setAttribute('aria-expanded', 'true');
		this.panel.setAttribute('aria-hidden', 'false');
		if (this.panel.hasAttribute('inert')) {
			this.panel.removeAttribute('inert');
		}
		if (this.badge) {
			this.badge.hidden = true;
		}
		if (this.rememberState) {
			storageSet(STORAGE_PREFIX + this.bubbleId, 'open');
		}
		fireEvent(this.root, EVENTS.OPEN);
	};

	BubbleInstance.prototype.close = function () {
		if (!this.isOpen) {
			return;
		}
		this.isOpen = false;
		this.root.classList.remove(CLASSES.OPEN);
		this.trigger.setAttribute('aria-expanded', 'false');
		this.panel.setAttribute('aria-hidden', 'true');
		if ('inert' in this.panel) {
			this.panel.setAttribute('inert', '');
		}
		if (this.rememberState) {
			storageSet(STORAGE_PREFIX + this.bubbleId, 'closed');
		}
		fireEvent(this.root, EVENTS.CLOSE);
	};

	function initBubble(rootEl) {
		const bubbleId = rootEl.getAttribute('data-bubble-id') || '';
		if (!bubbleId || instances[bubbleId]) {
			return;
		}
		const instance = new BubbleInstance(rootEl);
		instances[bubbleId] = instance;
		instance.init();
	}

	function scan() {
		const bubbles = root.document.querySelectorAll('.' + CLASSES.ROOT);
		for (let i = 0; i < bubbles.length; i++) {
			initBubble(bubbles[i]);
		}
	}

	if (root.document.readyState === 'loading') {
		root.document.addEventListener('DOMContentLoaded', scan);
	} else {
		scan();
	}

	// Late-injected markup (block rendered after load, e.g. via AJAX).
	if (typeof root.MutationObserver === 'function') {
		const observer = new root.MutationObserver(function () {
			scan();
		});
		observer.observe(root.document.body, { childList: true, subtree: true });
	}
})(typeof window !== 'undefined' ? window : this);
