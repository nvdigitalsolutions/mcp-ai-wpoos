/**
 * Chat Bubble Widget
 *
 * Floating chat bubble toggle for the NV oOS chat interface.
 * Used by both Elementor widget and Gutenberg block.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

( function() {
	'use strict';

	/**
	 * CSS class constants (BEM).
	 *
	 * @type {Object}
	 */
	const CLASSES = {
		ROOT:        'wp-mcp-ai-chat-bubble',
		TRIGGER:     'wp-mcp-ai-chat-bubble__trigger',
		PANEL:       'wp-mcp-ai-chat-bubble__panel',
		PANEL_CLOSE: 'wp-mcp-ai-chat-bubble__panel-close',
		BADGE:       'wp-mcp-ai-chat-bubble__badge',
		OPEN:        'wp-mcp-ai-chat-bubble--open',
	};

	/**
	 * Custom event names.
	 *
	 * @type {Object}
	 */
	const EVENTS = {
		OPEN:  'wp-mcp-ai-chat-bubble:open',
		CLOSE: 'wp-mcp-ai-chat-bubble:close',
	};

	/**
	 * Prefix for sessionStorage keys.
	 *
	 * @type {string}
	 */
	const STORAGE_PREFIX = 'wp-mcp-ai-chat-bubble-state-';

	/**
	 * Registry of initialised bubble instances keyed by bubble ID.
	 *
	 * @type {Object<string, BubbleInstance>}
	 */
	const instances = {};

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	/**
	 * Safely read a value from sessionStorage.
	 *
	 * @param {string} key Storage key.
	 * @return {string|null} The stored value or null.
	 */
	function storageGet( key ) {
		try {
			return sessionStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Safely write a value to sessionStorage.
	 *
	 * @param {string} key   Storage key.
	 * @param {string} value Value to store.
	 */
	function storageSet( key, value ) {
		try {
			sessionStorage.setItem( key, value );
		} catch ( e ) {
			// Storage full or blocked – silently ignore.
		}
	}

	/**
	 * Dispatch a custom event on a DOM element.
	 *
	 * @param {HTMLElement} el        Target element.
	 * @param {string}      eventName Event name.
	 * @param {Object}      detail    Optional detail payload.
	 */
	function fireEvent( el, eventName, detail ) {
		let evt;
		if ( typeof CustomEvent === 'function' ) {
			evt = new CustomEvent( eventName, {
				bubbles: true,
				cancelable: true,
				detail: detail || {},
			} );
		} else {
			evt = document.createEvent( 'CustomEvent' );
			evt.initCustomEvent( eventName, true, true, detail || {} );
		}
		el.dispatchEvent( evt );
	}

	/**
	 * Detect a small-screen / mobile viewport.
	 *
	 * @return {boolean} True when viewport width is ≤ 480 px.
	 */
	function isMobileViewport() {
		return window.innerWidth <= 480;
	}

	/* ---------------------------------------------------------------
	 * BubbleInstance – encapsulates one chat bubble
	 * ------------------------------------------------------------- */

	/**
	 * Create and manage a single chat-bubble instance.
	 *
	 * @param {HTMLElement} rootEl The `.wp-mcp-ai-chat-bubble` element.
	 */
	function BubbleInstance( rootEl ) {
		this.root          = rootEl;
		this.bubbleId      = rootEl.getAttribute( 'data-bubble-id' ) || 'default';
		this.rememberState = rootEl.getAttribute( 'data-remember-state' ) === 'true';
		this.autoOpenDelay = parseInt( rootEl.getAttribute( 'data-auto-open-delay' ), 10 ) || 0;
		this.isOpen        = false;
		this.chatInited    = false;
		this.autoOpenTimer = null;
		this.promoted      = false;

		this.trigger    = rootEl.querySelector( '.' + CLASSES.TRIGGER );
		this.panel      = rootEl.querySelector( '.' + CLASSES.PANEL );
		this.closeBtn   = rootEl.querySelector( '.' + CLASSES.PANEL_CLOSE );
		this.badge      = rootEl.querySelector( '.' + CLASSES.BADGE );

		if ( ! this.trigger || ! this.panel ) {
			return;
		}

		this._promoteToBody();
		this._bindEvents();
		this._restoreState();
		this._scheduleAutoOpen();
	}

	/**
	 * Move the bubble element to document.body so it escapes any
	 * ancestor stacking-context created by page-builders (Elementor
	 * sections/columns often set transforms, z-index, or overflow
	 * that trap position:fixed children and block click events).
	 *
	 * Skipped inside the Elementor editor where the element must
	 * stay in-place for the visual builder to work, and skipped when
	 * the element is already a direct child of body.
	 */
	BubbleInstance.prototype._promoteToBody = function() {
		// Already a direct child of body – nothing to do.
		if ( this.root.parentNode === document.body ) {
			return;
		}

		// Inside the Elementor visual editor the widget must remain
		// within its container so the builder can manage it.
		if (
			window.elementorFrontend &&
			typeof window.elementorFrontend.isEditMode === 'function' &&
			window.elementorFrontend.isEditMode()
		) {
			return;
		}

		document.body.appendChild( this.root );
		this.promoted = true;
	};

	/**
	 * Bind DOM event listeners.
	 */
	BubbleInstance.prototype._bindEvents = function() {
		const self = this;

		// Trigger button – click and keyboard.
		this.trigger.addEventListener( 'click', function( e ) {
			e.preventDefault();
			self.toggle();
		} );

		this.trigger.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				self.toggle();
			}
		} );

		// Close button inside the panel.
		if ( this.closeBtn ) {
			this.closeBtn.addEventListener( 'click', function( e ) {
				e.preventDefault();
				self.close();
			} );
		}

		// Escape key – close when panel is open.
		document.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Escape' && self.isOpen ) {
				self.close();
			}
		} );

		// Outside click – close when clicking outside root.
		document.addEventListener( 'click', function( e ) {
			if ( self.isOpen && ! self.root.contains( e.target ) ) {
				self.close();
			}
		} );
	};

	/**
	 * Restore persisted open/close state from sessionStorage.
	 */
	BubbleInstance.prototype._restoreState = function() {
		if ( ! this.rememberState ) {
			return;
		}

		const saved = storageGet( STORAGE_PREFIX + this.bubbleId );
		if ( saved === 'open' ) {
			this.open();
		}
	};

	/**
	 * Set up the auto-open timer if a delay > 0 is configured.
	 */
	BubbleInstance.prototype._scheduleAutoOpen = function() {
		const self = this;

		if ( this.autoOpenDelay <= 0 || this.isOpen ) {
			return;
		}

		// Skip auto-open when the user explicitly closed previously.
		if ( this.rememberState && storageGet( STORAGE_PREFIX + this.bubbleId ) === 'closed' ) {
			return;
		}

		this.autoOpenTimer = setTimeout( function() {
			if ( ! self.isOpen ) {
				self.open();
			}
		}, this.autoOpenDelay * 1000 );
	};

	/**
	 * Persist the current state to sessionStorage.
	 */
	BubbleInstance.prototype._persistState = function() {
		if ( ! this.rememberState ) {
			return;
		}
		storageSet( STORAGE_PREFIX + this.bubbleId, this.isOpen ? 'open' : 'closed' );
	};

	/**
	 * Lazily initialise the embedded chat shortcode JS on first open.
	 *
	 * The shortcode HTML is already rendered inside the panel; this
	 * triggers `wpMcpAiChatInit.init()` once so the chat becomes
	 * interactive only when the user first opens the bubble.
	 */
	BubbleInstance.prototype._lazyInitChat = function() {
		if ( this.chatInited ) {
			return;
		}
		this.chatInited = true;

		if (
			window.wpMcpAiChatInit &&
			typeof window.wpMcpAiChatInit.init === 'function'
		) {
			window.wpMcpAiChatInit.init( this.panel );
		}
	};

	/**
	 * Move focus to the first chat input inside the panel, or the
	 * panel itself if no input is found.
	 */
	BubbleInstance.prototype._focusChat = function() {
		const input = this.panel.querySelector(
			'textarea, input[type="text"], [contenteditable="true"]'
		);
		if ( input ) {
			input.focus();
		} else {
			this.panel.setAttribute( 'tabindex', '-1' );
			this.panel.focus();
		}
	};

	/* -- Public methods ------------------------------------------- */

	/**
	 * Open the chat panel.
	 */
	BubbleInstance.prototype.open = function() {
		if ( this.isOpen ) {
			return;
		}

		this.isOpen = true;
		this.root.classList.add( CLASSES.OPEN );
		this.trigger.setAttribute( 'aria-expanded', 'true' );
		this.panel.setAttribute( 'aria-hidden', 'false' );

		// On mobile, prevent body scroll while panel is open.
		if ( isMobileViewport() ) {
			document.body.style.overflow = 'hidden';
		}

		this._lazyInitChat();
		this._persistState();

		// Defer focus so CSS transitions can complete.
		const self = this;
		requestAnimationFrame( function() {
			self._focusChat();
		} );

		fireEvent( this.root, EVENTS.OPEN, { bubbleId: this.bubbleId } );
	};

	/**
	 * Close the chat panel.
	 */
	BubbleInstance.prototype.close = function() {
		if ( ! this.isOpen ) {
			return;
		}

		this.isOpen = false;
		this.root.classList.remove( CLASSES.OPEN );
		this.trigger.setAttribute( 'aria-expanded', 'false' );
		this.panel.setAttribute( 'aria-hidden', 'true' );

		if ( isMobileViewport() ) {
			document.body.style.overflow = '';
		}

		this._persistState();
		this.trigger.focus();

		fireEvent( this.root, EVENTS.CLOSE, { bubbleId: this.bubbleId } );
	};

	/**
	 * Toggle the chat panel open/closed.
	 */
	BubbleInstance.prototype.toggle = function() {
		if ( this.isOpen ) {
			this.close();
		} else {
			this.open();
		}
	};

	/**
	 * Set the notification badge count.
	 *
	 * @param {number} count Number to display (0 hides the badge).
	 */
	BubbleInstance.prototype.setBadge = function( count ) {
		if ( ! this.badge ) {
			return;
		}

		const num = parseInt( count, 10 ) || 0;

		if ( num > 0 ) {
			this.badge.textContent = num > 99 ? '99+' : String( num );
			this.badge.style.display = '';
			this.badge.setAttribute( 'aria-label', num + ' unread' );
		} else {
			this.badge.textContent = '';
			this.badge.style.display = 'none';
			this.badge.removeAttribute( 'aria-label' );
		}
	};

	/**
	 * Clean up timers and listeners (useful for SPA-style removal).
	 */
	BubbleInstance.prototype.destroy = function() {
		if ( this.autoOpenTimer ) {
			clearTimeout( this.autoOpenTimer );
		}

		// Remove the DOM element if it was promoted to body.
		if ( this.promoted && this.root.parentNode ) {
			this.root.parentNode.removeChild( this.root );
		}
	};

	/* ---------------------------------------------------------------
	 * Lookup helper
	 * ------------------------------------------------------------- */

	/**
	 * Retrieve a bubble instance by its ID.
	 *
	 * @param {string} bubbleId Bubble identifier.
	 * @return {BubbleInstance|undefined}
	 */
	function getInstance( bubbleId ) {
		return instances[ bubbleId ];
	}

	/* ---------------------------------------------------------------
	 * Initialisation
	 * ------------------------------------------------------------- */

	/**
	 * Discover and initialise chat-bubble elements on the page.
	 *
	 * When called without arguments the entire document is scanned.
	 * When a scope element is provided (e.g. from Elementor's
	 * `frontend/element_ready` callback) only that subtree is scanned,
	 * allowing bubbles rendered in Elementor Pro headers, footers, and
	 * popups to be initialised after the initial DOMContentLoaded pass.
	 *
	 * @param {HTMLElement} [scope] Optional container to search within.
	 */
	function init( scope ) {
		let roots;
		const container = scope || document;

		// When Elementor passes the widget wrapper it may or may not be the
		// root element itself – handle both cases.
		if ( scope instanceof HTMLElement && scope.classList.contains( CLASSES.ROOT ) ) {
			roots = [ scope ];
		} else if ( container.querySelectorAll ) {
			roots = container.querySelectorAll( '.' + CLASSES.ROOT );
		} else {
			roots = [];
		}

		for ( let i = 0; i < roots.length; i++ ) {
			const root = roots[ i ];
			const id   = root.getAttribute( 'data-bubble-id' ) || 'default-' + i;

			if ( instances[ id ] ) {
				// Same DOM node – already initialised, skip.
				if ( instances[ id ].root === root ) {
					continue;
				}

				// DOM node was replaced (Elementor re-render) – destroy stale instance.
				if ( typeof instances[ id ].destroy === 'function' ) {
					instances[ id ].destroy();
				}
				delete instances[ id ];
			}

			instances[ id ] = new BubbleInstance( root );
		}
	}

	/* ---------------------------------------------------------------
	 * Global API
	 * ------------------------------------------------------------- */

	window.wpMcpAiChatBubble = {

		/**
		 * Re-run bubble initialisation.
		 *
		 * Call with no arguments to scan the full document or pass a
		 * container element to limit the scan (useful after injecting
		 * new bubble markup via AJAX or page-builder re-renders).
		 *
		 * @param {HTMLElement} [scope] Optional subtree to scan.
		 */
		init: function( scope ) {
			init( scope );
		},

		/**
		 * Open a specific bubble by ID.
		 *
		 * @param {string} bubbleId Bubble identifier.
		 */
		open: function( bubbleId ) {
			const inst = getInstance( bubbleId );
			if ( inst ) {
				inst.open();
			}
		},

		/**
		 * Close a specific bubble by ID.
		 *
		 * @param {string} bubbleId Bubble identifier.
		 */
		close: function( bubbleId ) {
			const inst = getInstance( bubbleId );
			if ( inst ) {
				inst.close();
			}
		},

		/**
		 * Toggle a specific bubble by ID.
		 *
		 * @param {string} bubbleId Bubble identifier.
		 */
		toggle: function( bubbleId ) {
			const inst = getInstance( bubbleId );
			if ( inst ) {
				inst.toggle();
			}
		},

		/**
		 * Set badge count for a specific bubble.
		 *
		 * @param {string} bubbleId Bubble identifier.
		 * @param {number} count    Number to display (0 hides badge).
		 */
		setBadge: function( bubbleId, count ) {
			const inst = getInstance( bubbleId );
			if ( inst ) {
				inst.setBadge( count );
			}
		},
	};

	/* ---------------------------------------------------------------
	 * Elementor Integration
	 *
	 * Elementor Pro header/footer/popup templates inject widget HTML
	 * dynamically.  Without hooking into `frontend/element_ready` the
	 * bubble buttons will never receive event listeners.
	 *
	 * @see https://developers.elementor.com/docs/addons/frontend-hooks/
	 * ------------------------------------------------------------- */

	/**
	 * Register the Elementor widget-ready handler.
	 */
	function registerElementorHandler() {
		if (
			window.elementorFrontend &&
			window.elementorFrontend.hooks &&
			window.elementorFrontend.hooks.addAction
		) {
			window.elementorFrontend.hooks.addAction(
				'frontend/element_ready/wp_mcp_ai_chat_bubble.default',
				function( $element ) {
					if ( $element && $element[ 0 ] ) {
						init( $element[ 0 ] );
					}
				}
			);
		}
	}

	/* ---------------------------------------------------------------
	 * Bootstrap
	 * ------------------------------------------------------------- */

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Elementor: register immediately if elementorFrontend is already loaded.
	registerElementorHandler();

	// Elementor: if elementorFrontend hasn't loaded yet, wait for its init event.
	// The `elementor/frontend/init` event is fired via jQuery on `window`.
	if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', registerElementorHandler );
	}

} )();
