/**
 * Vehicle Cleaning Estimator — Frontend Script
 *
 * Provides an interactive, multi-step PWA-style car-wash quote experience.
 *
 * Steps:
 *   1. Image drop zone   — upload vehicle photos
 *   2. Package picker    — select a wash tier and optional add-ons
 *   3. Message           — freeform notes
 *   4. Result            — line-item estimate rendered from AI response
 *
 * Localisation object (injected by PHP):
 *   window.mcpVehicleCleaningEstimator = {
 *     restUrl         : string,  // trailing-slash REST root, e.g. 'https://example.com/wp-json/mcp-ai/v1/'
 *     uploadEndpoint  : string,  // 'https://example.com/wp-json/wp/v2/media'
 *     nonce           : string,  // wp_rest nonce
 *     assistantId     : string,  // post ID of the assistant
 *     currency        : string,  // 'CAD' | 'USD' | 'GBP' | 'EUR' | 'AUD'
 *     taxRate         : number,  // decimal, e.g. 0.13
 *     i18n            : { ... }
 *   };
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.0
 */

/* global mcpVehicleCleaningEstimator */

( function () {
	'use strict';

	// ─────────────────────────────────────────────────────────────────────────
	// PACKAGE METADATA (matches WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate)
	// ─────────────────────────────────────────────────────────────────────────
	var PACKAGES = [
		{
			slug : 'premium_exterior_express',
			name : 'Premium Exterior Express',
			desc : 'Exterior wash, dry, windows & tires',
			icon : '🚗',
		},
		{
			slug : 'practical_interior_express',
			name : 'Practical Interior Express',
			desc : 'Interior vacuum, wipe-down & glass',
			icon : '🧹',
		},
		{
			slug : 'popular_interior_express',
			name : 'Popular Interior Express',
			desc : 'Exterior + interior combined service',
			icon : '✨',
		},
		{
			slug : 'prestige_interior_express',
			name : 'Prestige Interior Express',
			desc : 'Full detail: leather, steam & polish',
			icon : '👑',
		},
	];

	var ADD_ONS = [
		{ code : 'soil_mud_sap_oil',            label : 'Soil / Mud / Sap' },
		{ code : 'pet_hair_removal',             label : 'Pet Hair Removal' },
		{ code : 'additional_interior_clean',    label : 'Extra Interior Clean' },
		{ code : 'premium_hand_wash_upgrade',    label : 'Premium Hand Wash' },
		{ code : 'rims_tire_dressing',           label : 'Rims & Tire Dressing' },
		{ code : 'trunk_bed_shampoo',            label : 'Trunk / Bed Shampoo' },
		{ code : 'carpet_seat_deodorizer',       label : 'Carpet & Seat Deodorizer' },
	];

	// ─────────────────────────────────────────────────────────────────────────
	// HELPERS
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Return the localisation config, falling back gracefully.
	 *
	 * @return {Object}
	 */
	function cfg() {
		return window.mcpVehicleCleaningEstimator || {};
	}

	/**
	 * Return a translated string from the i18n map, or the key itself.
	 *
	 * @param {string} key
	 * @return {string}
	 */
	function t( key ) {
		var i18n = ( cfg().i18n ) || {};
		return i18n[ key ] || key;
	}

	/**
	 * Format a number as currency.
	 *
	 * @param {number} amount
	 * @param {string} currency
	 * @return {string}
	 */
	function formatCurrency( amount, currency ) {
		currency = currency || cfg().currency || 'CAD';
		try {
			return new Intl.NumberFormat( undefined, {
				style                : 'currency',
				currency             : currency,
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			} ).format( amount );
		} catch ( e ) {
			return currency + ' ' + parseFloat( amount ).toFixed( 2 );
		}
	}

	/**
	 * Safely escape HTML for use in innerHTML.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( String( str ) ) );
		return d.innerHTML;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// MAIN CLASS
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Vehicle Cleaning Estimator controller.
	 *
	 * Each instance manages one `.mcp-vce-app` element on the page.
	 *
	 * @param {HTMLElement} el  The `.mcp-vce-app` root element.
	 * @constructor
	 */
	function VehicleCleaningEstimator( el ) {
		this.root            = el;
		this.currentStep     = 1;
		this.uploadedImages  = [];   // { file, objectUrl, attachmentId }
		this.pendingUploads  = 0;
		this.selectedPackage = null;
		this.selectedAddOns  = [];

		this._bindUI();
	}

	// ── Prototype ─────────────────────────────────────────────────────────────

	VehicleCleaningEstimator.prototype = {

		// ── DOM helpers ───────────────────────────────────────────────────────

		/**
		 * Return a child element matching the selector.
		 *
		 * @param {string} sel CSS selector.
		 * @return {HTMLElement|null}
		 */
		$: function ( sel ) {
			return this.root.querySelector( sel );
		},

		/**
		 * Return all child elements matching the selector.
		 *
		 * @param {string} sel CSS selector.
		 * @return {NodeList}
		 */
		$$: function ( sel ) {
			return this.root.querySelectorAll( sel );
		},

		// ── UI Binding ────────────────────────────────────────────────────────

		/**
		 * Attach all event listeners after the DOM is ready.
		 */
		_bindUI: function () {
			var self = this;

			// ── Step 1: Drop zone ──────────────────────────────────────────
			var dropzone  = this.$( '.mcp-vce-dropzone' );
			var fileInput = this.$( '.mcp-vce-file-input' );
			var skipBtn   = this.$( '.mcp-vce-skip-link' );

			if ( dropzone ) {
				dropzone.addEventListener( 'dragover', function ( e ) {
					e.preventDefault();
					dropzone.classList.add( 'mcp-vce-dropzone--drag-over' );
				} );
				dropzone.addEventListener( 'dragleave', function () {
					dropzone.classList.remove( 'mcp-vce-dropzone--drag-over' );
				} );
				dropzone.addEventListener( 'drop', function ( e ) {
					e.preventDefault();
					dropzone.classList.remove( 'mcp-vce-dropzone--drag-over' );
					self._handleFiles( e.dataTransfer.files );
				} );
			}

			if ( fileInput ) {
				fileInput.addEventListener( 'change', function () {
					self._handleFiles( fileInput.files );
					// Reset so the same file can be re-selected if removed.
					fileInput.value = '';
				} );
			}

			if ( skipBtn ) {
				skipBtn.addEventListener( 'click', function () {
					self._goToStep( 2 );
				} );
			}

			// ── Step 1 → 2 continue button ────────────────────────────────
			var continueBtn = this.$( '[data-vce-action="continue-to-packages"]' );
			if ( continueBtn ) {
				continueBtn.addEventListener( 'click', function () {
					self._goToStep( 2 );
				} );
			}

			// ── Step 2: Package cards ──────────────────────────────────────
			var pkgCards = this.$$( '.mcp-vce-pkg-card' );
			pkgCards.forEach( function ( card ) {
				card.addEventListener( 'click', function () {
					self._selectPackage( card.dataset.pkg );
				} );
				// Keyboard accessibility.
				card.setAttribute( 'tabindex', '0' );
				card.setAttribute( 'role', 'radio' );
				card.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key || ' ' === e.key ) {
						e.preventDefault();
						self._selectPackage( card.dataset.pkg );
					}
				} );
			} );

			// ── Step 2: Add-on chips ───────────────────────────────────────
			var addonChips = this.$$( '.mcp-vce-addon-chip' );
			addonChips.forEach( function ( chip ) {
				chip.addEventListener( 'click', function () {
					self._toggleAddOn( chip.dataset.addon );
				} );
				chip.setAttribute( 'tabindex', '0' );
				chip.setAttribute( 'role', 'checkbox' );
				chip.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key || ' ' === e.key ) {
						e.preventDefault();
						self._toggleAddOn( chip.dataset.addon );
					}
				} );
			} );

			// ── Step 2 → 3 continue button ────────────────────────────────
			var toMsgBtn = this.$( '[data-vce-action="continue-to-message"]' );
			if ( toMsgBtn ) {
				toMsgBtn.addEventListener( 'click', function () {
					self._goToStep( 3 );
				} );
			}

			// ── Step 3: Char counter ───────────────────────────────────────
			var textarea  = this.$( '.mcp-vce-message-area' );
			var charCount = this.$( '.mcp-vce-char-count' );
			if ( textarea && charCount ) {
				textarea.addEventListener( 'input', function () {
					charCount.textContent = textarea.value.length + ' / 1000';
				} );
			}

			// ── Submit ─────────────────────────────────────────────────────
			var submitBtn = this.$( '[data-vce-action="submit"]' );
			if ( submitBtn ) {
				submitBtn.addEventListener( 'click', function () {
					self._submit();
				} );
			}

			// ── Start Over ─────────────────────────────────────────────────
			var startOverBtns = this.$$( '[data-vce-action="start-over"]' );
			startOverBtns.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					self._reset();
				} );
			} );

			// ── Fullscreen toggle ──────────────────────────────────────────
			var fsBtn   = this.$( '[data-vce-action="fullscreen"]' );
			var closeFs = this.$( '[data-vce-action="close-fullscreen"]' );

			if ( fsBtn ) {
				fsBtn.addEventListener( 'click', function () {
					self.root.classList.add( 'mcp-vce-app--fullscreen' );
					if ( fsBtn ) { fsBtn.style.display = 'none'; }
					if ( closeFs ) { closeFs.style.display = ''; }
				} );
			}
			if ( closeFs ) {
				closeFs.style.display = 'none';
				closeFs.addEventListener( 'click', function () {
					self.root.classList.remove( 'mcp-vce-app--fullscreen' );
					if ( fsBtn ) { fsBtn.style.display = ''; }
					if ( closeFs ) { closeFs.style.display = 'none'; }
				} );
			}

			// ── Back buttons ───────────────────────────────────────────────
			var backBtns = this.$$( '[data-vce-action="back"]' );
			backBtns.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					if ( self.currentStep > 1 ) {
						self._goToStep( self.currentStep - 1 );
					}
				} );
			} );
		},

		// ── Navigation ────────────────────────────────────────────────────────

		/**
		 * Navigate to a specific step.
		 *
		 * @param {number} stepNum 1-4
		 */
		_goToStep: function ( stepNum ) {
			this.currentStep = stepNum;

			// Show the correct step panel.
			var steps = this.$$( '.mcp-vce-step' );
			steps.forEach( function ( panel ) {
				panel.classList.toggle(
					'mcp-vce-step--active',
					parseInt( panel.dataset.step, 10 ) === stepNum
				);
			} );

			// Update progress indicators.
			var dots = this.$$( '.mcp-vce-progress__step' );
			dots.forEach( function ( dotEl ) {
				var s = parseInt( dotEl.dataset.step, 10 );
				dotEl.classList.toggle( 'mcp-vce-progress__step--active', s === stepNum );
				dotEl.classList.toggle( 'mcp-vce-progress__step--done', s < stepNum );
			} );

			// Scroll the app back to the top.
			var body = this.$( '.mcp-vce-body' );
			if ( body ) { body.scrollTop = 0; }
		},

		// ── Image upload ──────────────────────────────────────────────────────

		/**
		 * Process a FileList from either file-input or drag-and-drop.
		 *
		 * @param {FileList} files
		 */
		_handleFiles: function ( files ) {
			var self      = this;
			var maxImages = 10;

			if ( ! files || ! files.length ) { return; }

			var remaining = maxImages - this.uploadedImages.length;
			if ( remaining <= 0 ) {
				this._showError( t( 'max_images_reached' ) || 'Maximum 10 images allowed.' );
				return;
			}

			var toProcess = Math.min( files.length, remaining );

			for ( var i = 0; i < toProcess; i++ ) {
				var file = files[ i ];
				if ( ! file.type || 0 !== file.type.indexOf( 'image/' ) ) {
					continue;
				}
				this._uploadImage( file );
			}
		},

		/**
		 * Upload a single image to WordPress media library and track it.
		 *
		 * @param {File} file
		 */
		_uploadImage: function ( file ) {
			var self      = this;
			var objectUrl = URL.createObjectURL( file );
			var index     = this.uploadedImages.length;

			// Add a placeholder entry immediately so the thumbnail shows.
			this.uploadedImages.push( {
				file        : file,
				objectUrl   : objectUrl,
				attachmentId: null,
			} );
			this.pendingUploads++;
			this._renderThumbs();

			var uploadEndpoint = cfg().uploadEndpoint || '';
			var nonce          = cfg().nonce || '';

			if ( ! uploadEndpoint ) {
				// No endpoint: skip upload, keep objectUrl only (no attachment ID).
				this.uploadedImages[ index ].attachmentId = null;
				this.pendingUploads--;
				this._renderThumbs();
				return;
			}

			var formData = new FormData();
			formData.append( 'file', file );
			formData.append( 'title', file.name );

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', uploadEndpoint, true );
			xhr.setRequestHeader( 'X-WP-Nonce', nonce );

			xhr.onload = function () {
				self.pendingUploads--;

				if ( 200 === xhr.status || 201 === xhr.status ) {
					try {
						var data = JSON.parse( xhr.responseText );
						if ( data && data.id ) {
							self.uploadedImages[ index ].attachmentId = data.id;
						}
					} catch ( e ) {
						// Silently ignore parse errors.
					}
				} else {
					// Remove failed upload from the list.
					self.uploadedImages.splice( index, 1 );
					self._showError( t( 'upload_failed' ) || 'Image upload failed. Please try again.' );
				}

				self._renderThumbs();
			};

			xhr.onerror = function () {
				self.pendingUploads--;
				self.uploadedImages.splice( index, 1 );
				self._showError( t( 'upload_failed' ) || 'Image upload failed. Please try again.' );
				self._renderThumbs();
			};

			xhr.send( formData );
		},

		/**
		 * Re-render the thumbnail strip inside the drop zone.
		 */
		_renderThumbs: function () {
			var self  = this;
			var strip = this.$( '.mcp-vce-thumbs' );
			if ( ! strip ) { return; }

			strip.innerHTML = '';

			this.uploadedImages.forEach( function ( img, idx ) {
				var thumb = document.createElement( 'div' );
				thumb.className = 'mcp-vce-thumb' + ( null === img.attachmentId ? ' mcp-vce-thumb--uploading' : '' );

				var imgEl = document.createElement( 'img' );
				imgEl.src = img.objectUrl;
				imgEl.alt = '';
				thumb.appendChild( imgEl );

				if ( null === img.attachmentId ) {
					// Show spinner while uploading.
					var spinnerWrap = document.createElement( 'div' );
					spinnerWrap.className = 'mcp-vce-thumb__spinner';
					var spinner = document.createElement( 'div' );
					spinner.className = 'mcp-vce-spinner mcp-vce-spinner--sm mcp-vce-spinner--visible';
					spinnerWrap.appendChild( spinner );
					thumb.appendChild( spinnerWrap );
				} else {
					// Show remove button once uploaded.
					var removeBtn = document.createElement( 'button' );
					removeBtn.className = 'mcp-vce-thumb__remove';
					removeBtn.setAttribute( 'aria-label', t( 'remove_image' ) || 'Remove image' );
					removeBtn.textContent = '×';
					removeBtn.addEventListener( 'click', function ( e ) {
						e.stopPropagation();
						self._removeImage( idx );
					} );
					thumb.appendChild( removeBtn );
				}

				strip.appendChild( thumb );
			} );

			// Show / update the "continue" button state.
			var continueBtn = this.$( '[data-vce-action="continue-to-packages"]' );
			if ( continueBtn ) {
				var hasReady = this.uploadedImages.some( function ( i ) {
					return null !== i.attachmentId;
				} );
				continueBtn.disabled = ( this.pendingUploads > 0 );
				continueBtn.style.display = ( this.uploadedImages.length > 0 ) ? '' : 'none';
			}
		},

		/**
		 * Remove an image from the upload list.
		 *
		 * @param {number} index
		 */
		_removeImage: function ( index ) {
			var img = this.uploadedImages[ index ];
			if ( img ) {
				URL.revokeObjectURL( img.objectUrl );
				this.uploadedImages.splice( index, 1 );
				this._renderThumbs();
			}
		},

		// ── Package selection ─────────────────────────────────────────────────

		/**
		 * Select (or de-select) a package tier.
		 *
		 * @param {string} slug
		 */
		_selectPackage: function ( slug ) {
			this.selectedPackage = ( this.selectedPackage === slug ) ? null : slug;

			var cards = this.$$( '.mcp-vce-pkg-card' );
			cards.forEach( function ( card ) {
				card.classList.toggle(
					'mcp-vce-pkg-card--selected',
					card.dataset.pkg === slug
				);
				card.setAttribute( 'aria-checked', String( card.dataset.pkg === slug ) );
			} );

			// Enable / disable the "Continue" button.
			var nextBtn = this.$( '[data-vce-action="continue-to-message"]' );
			if ( nextBtn ) {
				nextBtn.disabled = ( null === this.selectedPackage );
			}
		},

		// ── Add-on selection ──────────────────────────────────────────────────

		/**
		 * Toggle an add-on chip.
		 *
		 * @param {string} code
		 */
		_toggleAddOn: function ( code ) {
			var idx = this.selectedAddOns.indexOf( code );
			if ( -1 === idx ) {
				this.selectedAddOns.push( code );
			} else {
				this.selectedAddOns.splice( idx, 1 );
			}

			var chips = this.$$( '.mcp-vce-addon-chip' );
			var selected = this.selectedAddOns;
			chips.forEach( function ( chip ) {
				var active = -1 !== selected.indexOf( chip.dataset.addon );
				chip.classList.toggle( 'mcp-vce-addon-chip--selected', active );
				chip.setAttribute( 'aria-checked', String( active ) );
			} );
		},

		// ── Submit ────────────────────────────────────────────────────────────

		/**
		 * Build and POST the chat request.
		 */
		_submit: function () {
			var self      = this;
			var textarea  = this.$( '.mcp-vce-message-area' );
			var userNote  = textarea ? textarea.value.trim() : '';

			// Collect attachment IDs (skip images still uploading).
			var attachmentIds = [];
			this.uploadedImages.forEach( function ( img ) {
				if ( img.attachmentId ) {
					attachmentIds.push( img.attachmentId );
				}
			} );

			// Build a natural-language message including selected package/add-ons and context.
			var messageParts = [];
			if ( this.selectedPackage ) {
				var pkg = PACKAGES.filter( function ( p ) { return p.slug === self.selectedPackage; } )[ 0 ];
				messageParts.push( 'Please generate a cleaning estimate for the ' + ( pkg ? pkg.name : this.selectedPackage ) + ' package.' );
			} else {
				messageParts.push( 'Please generate a vehicle cleaning estimate.' );
			}

			if ( this.selectedAddOns.length ) {
				var addonNames = this.selectedAddOns.map( function ( code ) {
					var ao = ADD_ONS.filter( function ( a ) { return a.code === code; } )[ 0 ];
					return ao ? ao.label : code;
				} );
				messageParts.push( 'Include the following add-ons: ' + addonNames.join( ', ' ) + '.' );
			}

			// Include currency/tax context in the message so the assistant can format the estimate.
			var currency = cfg().currency || 'CAD';
			var taxRate  = cfg().taxRate  || 0;
			messageParts.push( 'Currency: ' + currency + '. Tax rate: ' + ( taxRate * 100 ).toFixed( 0 ) + '%.' );

			if ( userNote ) {
				messageParts.push( userNote );
			}

			var messageText = messageParts.join( ' ' );

			// Build the user message content.
			// When images are attached, use an array of content segments so the vision
			// model can see them.  The /chat endpoint accepts:
			//   messages[].content  = string  (text-only)
			//   messages[].content  = array   (multi-part: text + input_image segments)
			var messageContent;
			if ( attachmentIds.length ) {
				messageContent = [ { type: 'text', text: messageText } ];
				attachmentIds.forEach( function ( id ) {
					messageContent.push( { type: 'input_image', attachment_id: id } );
				} );
			} else {
				messageContent = messageText;
			}

			// Build request body using the /chat-client endpoint's expected schema.
			// `messages` is required (array of {role, content} objects).
			var body = {
				messages    : [ { role: 'user', content: messageContent } ],
				assistant_id: cfg().assistantId || '',
			};

			// Use the pre-built chatEndpoint (resolves to /chat-client, matching all
			// other browser-side widgets such as the chat bubble and professional selector).
			// Fall back to constructing from restUrl for backward compatibility.
			var chatUrl = cfg().chatEndpoint || ( cfg().restUrl || '' ).replace( /\/$/, '' ) + '/chat-client';
			var nonce   = cfg().nonce || '';

			this._setLoading( true );
			this._clearError();

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', chatUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/json' );
			xhr.setRequestHeader( 'Accept', 'application/json' );
			xhr.setRequestHeader( 'X-WP-Nonce', nonce );

			xhr.onload = function () {
				self._setLoading( false );

				if ( xhr.status >= 200 && xhr.status < 300 ) {
					try {
						var data = JSON.parse( xhr.responseText );
						self._renderResult( data );
						self._goToStep( 4 );
					} catch ( e ) {
						self._showError( t( 'parse_error' ) || 'Could not parse the response. Please try again.' );
					}
				} else {
					var errMsg = t( 'request_failed' ) || 'Request failed. Please try again.';
					try {
						var errData = JSON.parse( xhr.responseText );
						if ( errData && errData.message ) {
							errMsg = errData.message;
						}
					} catch ( ignore ) {}
					self._showError( errMsg );
				}
			};

			xhr.onerror = function () {
				self._setLoading( false );
				self._showError( t( 'network_error' ) || 'Network error. Please check your connection.' );
			};

			xhr.send( JSON.stringify( body ) );
		},

		// ── Result rendering ──────────────────────────────────────────────────

		/**
		 * Render the estimate result into step 4.
		 *
		 * Attempts to parse a structured JSON estimate from the tool_results
		 * in the API response.  Falls back to rendering the raw reply text.
		 *
		 * @param {Object} apiResponse  The /chat endpoint response object.
		 */
		_renderResult: function ( apiResponse ) {
			var container = this.$( '.mcp-vce-result-container' );
			if ( ! container ) { return; }

			// Try to extract structured estimate JSON.
			var estimate = this._extractEstimate( apiResponse );

			if ( estimate ) {
				container.innerHTML = this._buildReceiptHtml( estimate );
			} else {
				// Graceful fallback: show raw assistant message as prose.
				// The /chat endpoint returns OpenAI-shaped choices[].message.content.
				var replyText = this._getReplyText( apiResponse );
				container.innerHTML = '<div class="mcp-vce-result-prose">' + this._textToHtml( replyText ) + '</div>';
			}
		},

		/**
		 * Extract the assistant's plain-text reply from the API response.
		 *
		 * Supports both the OpenAI-shaped `choices[].message.content` format
		 * returned by the /chat endpoint and older `reply` / `message` shapes.
		 *
		 * @param {Object} response
		 * @return {string}
		 */
		_getReplyText: function ( response ) {
			if ( ! response ) { return ''; }

			// Primary: OpenAI format — choices[last].message.content.
			if ( response.choices && response.choices.length ) {
				var lastChoice = response.choices[ response.choices.length - 1 ];
				if ( lastChoice && lastChoice.message ) {
					var content = lastChoice.message.content;
					if ( 'string' === typeof content ) {
						return content;
					}
					// Content can be an array of segments; join text parts.
					if ( Array.isArray( content ) ) {
						return content
							.filter( function ( s ) { return s && 'text' === s.type; } )
							.map( function ( s ) { return s.text || ''; } )
							.join( '\n' );
					}
				}
			}

			// Legacy fallbacks.
			if ( 'string' === typeof response.reply )   { return response.reply; }
			if ( 'string' === typeof response.message ) { return response.message; }
			return '';
		},

		/**
		 * Walk the API response looking for vehicle_cleaning_estimate tool output.
		 *
		 * @param {Object} response
		 * @return {Object|null} Parsed estimate object, or null.
		 */
		_extractEstimate: function ( response ) {
			if ( ! response ) { return null; }

			// Try direct tool_result key.
			var sources = [
				response.tool_result,
				response.tool_results && response.tool_results[ 0 ],
				response.estimate,
			];

			for ( var i = 0; i < sources.length; i++ ) {
				var src = sources[ i ];
				if ( ! src ) { continue; }

				// May already be an object.
				if ( 'object' === typeof src && src.line_items ) {
					return src;
				}

				// May be a JSON string.
				if ( 'string' === typeof src ) {
					try {
						var parsed = JSON.parse( src );
						if ( parsed && parsed.line_items ) {
							return parsed;
						}
					} catch ( e ) {}
				}
			}

			// Last resort: scan all choices' content for embedded JSON block.
			var replyText = this._getReplyText( response );
			var jsonMatch = replyText.match( /```json\s*([\s\S]*?)```/ );
			if ( jsonMatch ) {
				try {
					var inlineJson = JSON.parse( jsonMatch[ 1 ] );
					if ( inlineJson && inlineJson.line_items ) {
						return inlineJson;
					}
				} catch ( e ) {}
			}

			return null;
		},

		/**
		 * Build receipt HTML from a structured estimate object.
		 *
		 * Expected shape (from vehicle_cleaning_estimate tool):
		 *   {
		 *     vehicle_size: string,
		 *     size_confidence: number,
		 *     package: string,
		 *     currency: string,
		 *     line_items: [ { label, description, amount } ],
		 *     subtotal: number,
		 *     tax_amount: number,
		 *     total: number,
		 *     note: string,
		 *   }
		 *
		 * @param {Object} est
		 * @return {string} HTML string.
		 */
		_buildReceiptHtml: function ( est ) {
			var currency = est.currency || cfg().currency || 'CAD';
			var html     = '';

			// Header.
			html += '<div class="mcp-vce-result-card">';
			html += '<div class="mcp-vce-result-card__header">';
			html += '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 11l1.5-4.5h11L19 11M3 17h18M7 17v1a2 2 0 0 0 4 0v-1m2 0v1a2 2 0 0 0 4 0v-1M4 11h16l1 4H3z"/></svg>';
			html += '<div>';
			html += '<p class="mcp-vce-result-card__title">' + escHtml( t( 'your_estimate' ) || 'Your Estimate' ) + '</p>';

			if ( est.package ) {
				var pkgMeta = PACKAGES.filter( function ( p ) { return p.slug === est.package; } )[ 0 ];
				var pkgName = pkgMeta ? pkgMeta.name : est.package;
				html += '<p class="mcp-vce-result-card__sub">' + escHtml( pkgName ) + '</p>';
			}

			html += '</div>';
			html += '</div>';

			// Vehicle banner.
			if ( est.vehicle_size ) {
				var sizeLabels = {
					car                : 'Car',
					small_truck_suv    : 'Small Truck / SUV',
					oversize_truck_suv : 'Oversize Truck / SUV',
				};
				html += '<div class="mcp-vce-vehicle-banner">';
				html += '<span>' + escHtml( t( 'vehicle_detected' ) || 'Vehicle detected:' ) + '</span>';
				html += '<span class="mcp-vce-vehicle-banner__tier">' + escHtml( sizeLabels[ est.vehicle_size ] || est.vehicle_size ) + '</span>';

				if ( est.size_confidence ) {
					var pct = Math.round( est.size_confidence * 100 );
					html += '<span class="mcp-vce-vehicle-banner__confidence">' + escHtml( pct + '% confidence' ) + '</span>';
				}

				html += '</div>';
			}

			// Line items.
			if ( est.line_items && est.line_items.length ) {
				html += '<div class="mcp-vce-line-items">';
				est.line_items.forEach( function ( item ) {
					html += '<div class="mcp-vce-line-item">';
					html += '<div class="mcp-vce-line-item__label">' + escHtml( item.label || '' );
					if ( item.description ) {
						html += '<small>' + escHtml( item.description ) + '</small>';
					}
					html += '</div>';
					html += '<div class="mcp-vce-line-item__amount">' + escHtml( formatCurrency( item.amount, currency ) ) + '</div>';
					html += '</div>';
				} );
				html += '</div>';
			}

			// Totals.
			html += '<div class="mcp-vce-totals">';
			if ( 'number' === typeof est.subtotal ) {
				html += '<div class="mcp-vce-total-row"><span>' + escHtml( t( 'subtotal' ) || 'Subtotal' ) + '</span><span class="mcp-vce-total-row__amount">' + escHtml( formatCurrency( est.subtotal, currency ) ) + '</span></div>';
			}
			if ( est.tax_amount && est.tax_amount > 0 ) {
				html += '<div class="mcp-vce-total-row"><span>' + escHtml( t( 'tax' ) || 'Tax' ) + '</span><span class="mcp-vce-total-row__amount">' + escHtml( formatCurrency( est.tax_amount, currency ) ) + '</span></div>';
			}
			if ( 'number' === typeof est.total ) {
				html += '<div class="mcp-vce-total-row mcp-vce-total-row--grand"><span>' + escHtml( t( 'total' ) || 'Total' ) + '</span><span class="mcp-vce-total-row__amount">' + escHtml( formatCurrency( est.total, currency ) ) + '</span></div>';
			}
			html += '</div>';

			// Disclaimer note.
			var note = est.note || t( 'estimate_note' ) || 'This is an estimate only. Final price may vary based on vehicle condition.';
			html += '<div class="mcp-vce-estimate-note">' + escHtml( note ) + '</div>';

			html += '</div>'; // .mcp-vce-result-card

			return html;
		},

		/**
		 * Convert plain text (with line-breaks) to safe HTML paragraphs.
		 *
		 * @param {string} text
		 * @return {string}
		 */
		_textToHtml: function ( text ) {
			if ( ! text ) { return ''; }
			return text
				.split( /\n{2,}/ )
				.map( function ( para ) {
					return '<p>' + escHtml( para.replace( /\n/g, ' ' ) ) + '</p>';
				} )
				.join( '' );
		},

		// ── Loading / error helpers ───────────────────────────────────────────

		/**
		 * Show or hide the loading state on the submit button.
		 *
		 * @param {boolean} loading
		 */
		_setLoading: function ( loading ) {
			var btn     = this.$( '[data-vce-action="submit"]' );
			var spinner = this.$( '.mcp-vce-spinner' );

			if ( btn ) { btn.disabled = loading; }
			if ( spinner ) { spinner.classList.toggle( 'mcp-vce-spinner--visible', loading ); }
		},

		/**
		 * Show an inline error message below the action bar.
		 *
		 * @param {string} msg
		 */
		_showError: function ( msg ) {
			var bar = this.$( '.mcp-vce-error-bar' );
			if ( ! bar ) {
				bar = document.createElement( 'div' );
				bar.className = 'mcp-vce-error-bar';
				bar.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg><span class="mcp-vce-error-bar__msg"></span>';
				var actionBar = this.$( '.mcp-vce-action-bar' );
				if ( actionBar ) { actionBar.insertAdjacentElement( 'afterend', bar ); }
			}

			var msgSpan = bar.querySelector( '.mcp-vce-error-bar__msg' );
			if ( msgSpan ) { msgSpan.textContent = msg; }
			bar.style.display = '';
		},

		/**
		 * Hide the inline error message.
		 */
		_clearError: function () {
			var bar = this.$( '.mcp-vce-error-bar' );
			if ( bar ) { bar.style.display = 'none'; }
		},

		// ── Reset ─────────────────────────────────────────────────────────────

		/**
		 * Reset all state and return to step 1.
		 */
		_reset: function () {
			// Release object URLs.
			this.uploadedImages.forEach( function ( img ) {
				URL.revokeObjectURL( img.objectUrl );
			} );

			this.uploadedImages  = [];
			this.pendingUploads  = 0;
			this.selectedPackage = null;
			this.selectedAddOns  = [];

			// Clear thumbs.
			var strip = this.$( '.mcp-vce-thumbs' );
			if ( strip ) { strip.innerHTML = ''; }

			// Deselect packages.
			var cards = this.$$( '.mcp-vce-pkg-card' );
			cards.forEach( function ( c ) {
				c.classList.remove( 'mcp-vce-pkg-card--selected' );
				c.setAttribute( 'aria-checked', 'false' );
			} );

			// Deselect add-ons.
			var chips = this.$$( '.mcp-vce-addon-chip' );
			chips.forEach( function ( c ) {
				c.classList.remove( 'mcp-vce-addon-chip--selected' );
				c.setAttribute( 'aria-checked', 'false' );
			} );

			// Clear textarea.
			var textarea = this.$( '.mcp-vce-message-area' );
			if ( textarea ) { textarea.value = ''; }

			// Clear result container.
			var resultContainer = this.$( '.mcp-vce-result-container' );
			if ( resultContainer ) { resultContainer.innerHTML = ''; }

			// Clear errors.
			this._clearError();

			// Hide continue button.
			var continueBtn = this.$( '[data-vce-action="continue-to-packages"]' );
			if ( continueBtn ) { continueBtn.style.display = 'none'; }

			this._goToStep( 1 );
		},
	};

	// ─────────────────────────────────────────────────────────────────────────
	// BOOT — initialise all widgets on the page
	// ─────────────────────────────────────────────────────────────────────────

	function init() {
		var apps = document.querySelectorAll( '.mcp-vce-app' );
		apps.forEach( function ( el ) {
			if ( ! el.dataset.vceInit ) {
				el.dataset.vceInit = '1';
				new VehicleCleaningEstimator( el );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )();
