/**
 * WP MCP AI Assistant Builder Blocks Frontend
 *
 * Handles the interactive functionality for the blocks on the frontend.
 * This script is loaded when the blocks are rendered on the page.
 *
 * @package WP_MCP_AI
 */

/* global jQuery, wpMcpAiChat */

( function( $ ) {
	'use strict';

	/**
	 * Assistant Builder Block Controller
	 */
	var AssistantBuilderBlock = {
		/**
		 * Instances by block ID.
		 */
		instances: {},

		/**
		 * Initialize all assistant builder blocks on the page.
		 */
		init: function() {
			var self = this;

			$( '.wp-block-wp-mcp-ai-assistant-builder' ).each( function() {
				var $block = $( this );
				var blockId = $block.data( 'block-id' );

				if ( blockId && ! self.instances[ blockId ] ) {
					self.instances[ blockId ] = new AssistantBuilderInstance( $block );
				}
			} );

			// Also initialize standalone blocks.
			self.initAssistantSelectors();
			self.initToolsGrids();
		},

		/**
		 * Initialize standalone assistant selector blocks.
		 */
		initAssistantSelectors: function() {
			$( '.wp-block-wp-mcp-ai-assistant-selector' ).each( function() {
				var $block = $( this );

				// Skip if inside an assistant builder block.
				if ( $block.closest( '.wp-block-wp-mcp-ai-assistant-builder' ).length > 0 ) {
					return;
				}

				new AssistantSelectorController( $block );
			} );
		},

		/**
		 * Initialize standalone tools grid blocks.
		 */
		initToolsGrids: function() {
			$( '.wp-block-wp-mcp-ai-tools-grid' ).each( function() {
				var $block = $( this );

				// Skip if inside an assistant builder block.
				if ( $block.closest( '.wp-block-wp-mcp-ai-assistant-builder' ).length > 0 ) {
					return;
				}

				new ToolsGridController( $block );
			} );
		}
	};

	/**
	 * Assistant Builder Instance
	 *
	 * @param {jQuery} $block The block container.
	 */
	function AssistantBuilderInstance( $block ) {
		this.$block = $block;
		this.blockId = $block.data( 'block-id' );
		this.config = this.parseConfig();
		this.selectedTools = [];
		this.uploadedFiles = [];
		this.currentAssistantId = null;

		this.$selector = $block.find( '.wp-block-wp-mcp-ai-assistant-selector' );
		this.$tools = $block.find( '.wp-block-wp-mcp-ai-assistant-builder__tools' );
		this.$knowledgeBase = $block.find( '.wp-block-wp-mcp-ai-assistant-builder__knowledge-base' );
		this.$chat = $block.find( '.wp-block-wp-mcp-ai-assistant-builder__chat' );

		this.selectorController = null;
		this.toolsController = null;
		this.knowledgeBaseController = null;

		this.init();
	}

	AssistantBuilderInstance.prototype = {
		/**
		 * Parse configuration from inline JSON.
		 *
		 * @return {Object} Configuration object.
		 */
		parseConfig: function() {
			var $configScript = this.$block.find( '.wp-mcp-ai-assistant-builder-config' );
			var config = {};

			if ( $configScript.length > 0 ) {
				try {
					config = JSON.parse( $configScript.text() );
				} catch ( e ) {
					console.error( 'Failed to parse assistant builder config:', e );
				}
			}

			return config;
		},

		/**
		 * Initialize the instance.
		 */
		init: function() {
			var self = this;

			// Initialize selector.
			if ( this.$selector.length > 0 ) {
				this.selectorController = new AssistantSelectorController( this.$selector, {
					onSelect: function( assistantId, assistantData ) {
						self.onAssistantSelect( assistantId, assistantData );
					},
					onStart: function( assistantId, assistantData ) {
						self.onStartChat( assistantId, assistantData );
					}
				} );
			}

			// Initialize tools grid.
			if ( this.$tools.length > 0 ) {
				var $toolsGrid = this.$tools.find( '.wp-block-wp-mcp-ai-tools-grid' );
				if ( $toolsGrid.length > 0 ) {
					this.toolsController = new ToolsGridController( $toolsGrid, {
						onChange: function( selectedTools ) {
							self.selectedTools = selectedTools;
						}
					} );
				}
			}
		},

		/**
		 * Handle assistant selection.
		 *
		 * @param {number} assistantId   Assistant ID.
		 * @param {Object} assistantData Assistant data from option.
		 */
		onAssistantSelect: function( assistantId, assistantData ) {
			this.currentAssistantId = assistantId;

			if ( assistantId ) {
				// Show tools section.
				this.$tools.show();

				// Update tools selection based on assistant's tools.
				if ( this.toolsController && assistantData.tools ) {
					this.toolsController.setSelectedTools( assistantData.tools );
					this.selectedTools = assistantData.tools.slice();
				}
			} else {
				// Hide tools section.
				this.$tools.hide();
				this.$chat.hide();
			}
		},

		/**
		 * Handle start chat.
		 *
		 * @param {number} assistantId   Assistant ID.
		 * @param {Object} assistantData Assistant data from option.
		 */
		onStartChat: function( assistantId, assistantData ) {
			var self = this;

			// Show chat container.
			this.$chat.show();

			// Initialize chat widget.
			this.initializeChatWidget( assistantId, assistantData );
		},

		/**
		 * Initialize the chat widget.
		 *
		 * @param {number} assistantId   Assistant ID.
		 * @param {Object} assistantData Assistant data from option.
		 */
		initializeChatWidget: function( assistantId, assistantData ) {
			var self = this;
			var $container = this.$chat.find( '.wp-block-wp-mcp-ai-assistant-builder__chat, .wp-mcp-ai-assistant-builder__chat' );

			if ( $container.length === 0 ) {
				$container = this.$chat;
			}

			// Clear previous content.
			$container.empty();

			// Create instance ID.
			var instanceId = 'wp-mcp-ai-builder-chat-' + assistantId + '-' + Date.now();

			// Build chat HTML.
			var chatHTML = this.buildChatHTML( instanceId, assistantData.title || 'Assistant' );
			$container.html( chatHTML );

			// Configure chat instance.
			if ( ! window.wpMcpAiChatInstances ) {
				window.wpMcpAiChatInstances = {};
			}

			var baseRestUrl = this.config.restUrl || ( window.wpMcpAiChat && window.wpMcpAiChat.restUrl ) || '/wp-json/mcp-ai/v1';

			window.wpMcpAiChatInstances[ instanceId ] = {
				id: instanceId,
				assistantId: assistantId,
				userId: ( window.wpMcpAiChat && typeof window.wpMcpAiChat.currentUserId !== 'undefined' ) ? window.wpMcpAiChat.currentUserId : 0,
				restUrl: baseRestUrl,
				messagesEndpoint: baseRestUrl + '/chat-client',
				toolsEndpoint: baseRestUrl + '/tools',
				filesEndpoint: baseRestUrl + '/files/',
				transcriptsEndpoint: baseRestUrl + '/chat-transcripts',
				uploadEndpoint: '/wp-json/wp/v2/media',
				sessionKey: this.generateSessionKey(),
				enableStreaming: this.config.enableStreaming !== false,
				canUploadAttachments: true,
				saveTranscript: false,
				allowSensitiveTools: true,
				toolShortcuts: assistantData.shortcuts || [],
				selectedTools: this.selectedTools.slice(),
				restNonce: this.config.nonce || ( window.wpMcpAiChat && window.wpMcpAiChat.nonce ) || '',
				historyPerPage: 20,
				asyncToolTimeout: 300000
			};

			// Trigger chat initialization.
			this.triggerChatInit( instanceId );
		},

		/**
		 * Build chat HTML structure.
		 *
		 * @param {string} instanceId     Instance ID.
		 * @param {string} assistantTitle Assistant title.
		 * @return {string} HTML string.
		 */
		buildChatHTML: function( instanceId, assistantTitle ) {
			var placeholder = this.config.chatPlaceholder || 'Describe the assistant you want to create...';
			var showBuild = this.config.showBuildButton !== false;

			return '<div class="wp-mcp-ai-chat" id="' + this.escapeHtml( instanceId ) + '" data-wp-mcp-ai-chat>' +
				'<div class="wp-mcp-ai-chat__assistant">' +
				'<label class="wp-mcp-ai-chat__label">' + this.escapeHtml( assistantTitle ) + '</label>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__transcript-controls">' +
				'<button type="button" class="wp-mcp-ai-chat__transcript-toggle" aria-expanded="false">' +
				'<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />' +
				'</svg>' +
				'</button>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__messages" role="log" aria-live="polite" aria-atomic="false"></div>' +
				'<form class="wp-mcp-ai-chat__form">' +
				'<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden></div>' +
				'<div class="wp-mcp-ai-chat__tool-shortcuts-wrapper" hidden></div>' +
				'<textarea class="wp-mcp-ai-chat__input" rows="4" placeholder="' + this.escapeHtml( placeholder ) + '" required></textarea>' +
				'<div class="wp-mcp-ai-chat__attachments" hidden>' +
				'<div class="wp-mcp-ai-chat__attachments-header">Attachments</div>' +
				'<ul class="wp-mcp-ai-chat__attachments-list"></ul>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__actions">' +
				'<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />' +
				'<button type="button" class="wp-mcp-ai-chat__attach">Attach file</button>' +
				( showBuild ? '<button type="button" class="wp-mcp-ai-chat__build">Build</button>' : '' ) +
				'<button type="submit" class="wp-mcp-ai-chat__submit">Send</button>' +
				'</div>' +
				'</form>' +
				'<div class="wp-mcp-ai-chat__controls">' +
				'<div class="wp-mcp-ai-chat__quota-monitor" role="status" aria-live="polite" aria-atomic="true"></div>' +
				'<div class="wp-mcp-ai-chat__control-buttons">' +
				'<button type="button" class="wp-mcp-ai-chat__new-chat" aria-label="Start new conversation">' +
				'<svg class="wp-mcp-ai-chat__new-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 4a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6V5a1 1 0 011-1z" />' +
				'</svg>' +
				'</button>' +
				'</div>' +
				'</div>' +
				'</div>';
		},

		/**
		 * Trigger chat.js initialization.
		 *
		 * @param {string} instanceId Instance ID.
		 */
		triggerChatInit: function( instanceId ) {
			setTimeout( function() {
				var container = document.getElementById( instanceId );
				if ( ! container ) {
					return;
				}

				// Re-trigger DOMContentLoaded for chat.js.
				var event = document.createEvent( 'Event' );
				event.initEvent( 'DOMContentLoaded', true, true );
				document.dispatchEvent( event );

				// Focus textarea.
				setTimeout( function() {
					var textarea = container.querySelector( '.wp-mcp-ai-chat__input' );
					if ( textarea ) {
						textarea.focus();
					}
				}, 200 );
			}, 100 );
		},

		/**
		 * Generate a session key.
		 *
		 * @return {string} Session key.
		 */
		generateSessionKey: function() {
			return 'builder-' + Math.random().toString( 36 ).substring( 2, 15 ) + Math.random().toString( 36 ).substring( 2, 15 );
		},

		/**
		 * Escape HTML.
		 *
		 * @param {string} text Text to escape.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function( text ) {
			var div = document.createElement( 'div' );
			div.textContent = text;
			return div.innerHTML;
		}
	};

	/**
	 * Assistant Selector Controller
	 *
	 * @param {jQuery} $block   The selector block.
	 * @param {Object} options  Callback options.
	 */
	function AssistantSelectorController( $block, options ) {
		this.$block = $block;
		this.options = options || {};
		this.$select = $block.find( '.wp-mcp-ai-assistant-selector__select' );
		this.$startBtn = $block.find( '.wp-mcp-ai-assistant-selector__start' );

		this.init();
	}

	AssistantSelectorController.prototype = {
		/**
		 * Initialize the controller.
		 */
		init: function() {
			var self = this;

			// Handle selection change.
			this.$select.on( 'change', function() {
				var value = $( this ).val();
				var assistantData = self.getAssistantData( $( this ).find( 'option:selected' ) );

				if ( self.$startBtn.length > 0 ) {
					self.$startBtn.prop( 'disabled', ! value );
				}

				if ( self.options.onSelect ) {
					self.options.onSelect( parseInt( value, 10 ) || 0, assistantData );
				}
			} );

			// Handle start button click.
			this.$startBtn.on( 'click', function() {
				var value = self.$select.val();
				var assistantData = self.getAssistantData( self.$select.find( 'option:selected' ) );

				if ( value && self.options.onStart ) {
					self.options.onStart( parseInt( value, 10 ), assistantData );
				}
			} );
		},

		/**
		 * Get assistant data from option element.
		 *
		 * @param {jQuery} $option The option element.
		 * @return {Object} Assistant data.
		 */
		getAssistantData: function( $option ) {
			var toolsJson = $option.data( 'tools' );
			var shortcutsJson = $option.data( 'shortcuts' );
			var tools = [];
			var shortcuts = [];

			if ( toolsJson ) {
				try {
					tools = typeof toolsJson === 'string' ? JSON.parse( toolsJson ) : toolsJson;
					if ( ! Array.isArray( tools ) ) {
						tools = [];
					}
				} catch ( e ) {
					tools = [];
				}
			}

			if ( shortcutsJson ) {
				try {
					shortcuts = typeof shortcutsJson === 'string' ? JSON.parse( shortcutsJson ) : shortcutsJson;
					if ( ! Array.isArray( shortcuts ) ) {
						shortcuts = [];
					}
				} catch ( e ) {
					shortcuts = [];
				}
			}

			return {
				id: parseInt( $option.val(), 10 ) || 0,
				title: $option.text(),
				tools: tools,
				shortcuts: shortcuts,
				provider: $option.data( 'provider' ) || '',
				model: $option.data( 'model' ) || ''
			};
		}
	};

	/**
	 * Tools Grid Controller
	 *
	 * @param {jQuery} $block   The tools grid block.
	 * @param {Object} options  Callback options.
	 */
	function ToolsGridController( $block, options ) {
		this.$block = $block;
		this.options = options || {};
		this.selectedTools = [];

		this.init();
	}

	ToolsGridController.prototype = {
		/**
		 * Initialize the controller.
		 */
		init: function() {
			var self = this;

			// Handle checkbox changes.
			this.$block.on( 'change', '.wp-mcp-ai-tools-grid__checkbox', function() {
				self.updateSelection();
			} );

			// Handle select all.
			this.$block.on( 'click', '.wp-mcp-ai-tools-grid__select-all', function() {
				self.$block.find( '.wp-mcp-ai-tools-grid__checkbox' ).prop( 'checked', true );
				self.updateSelection();
			} );

			// Handle deselect all.
			this.$block.on( 'click', '.wp-mcp-ai-tools-grid__deselect-all', function() {
				self.$block.find( '.wp-mcp-ai-tools-grid__checkbox' ).prop( 'checked', false );
				self.updateSelection();
			} );

			// Initial count update.
			this.updateSelection();
		},

		/**
		 * Update the selection state.
		 */
		updateSelection: function() {
			var self = this;
			this.selectedTools = [];

			// Collect selected tools.
			this.$block.find( '.wp-mcp-ai-tools-grid__checkbox:checked' ).each( function() {
				var slug = $( this ).val();
				if ( slug ) {
					self.selectedTools.push( slug );
				}
			} );

			// Update item states.
			this.$block.find( '.wp-block-wp-mcp-ai-tools-grid__item' ).each( function() {
				var $item = $( this );
				var $checkbox = $item.find( '.wp-mcp-ai-tools-grid__checkbox' );
				$item.toggleClass( 'wp-block-wp-mcp-ai-tools-grid__item--selected', $checkbox.is( ':checked' ) );
			} );

			// Update group counts.
			this.$block.find( '.wp-block-wp-mcp-ai-tools-grid__group' ).each( function() {
				var $group = $( this );
				var $checkboxes = $group.find( '.wp-mcp-ai-tools-grid__checkbox' );
				var checkedCount = $checkboxes.filter( ':checked' ).length;
				$group.find( '.wp-mcp-ai-tools-grid__group-selected' ).text( checkedCount );
			} );

			// Update total count.
			this.$block.find( '.wp-mcp-ai-tools-grid__selected-count' ).text( this.selectedTools.length );

			// Trigger callback.
			if ( this.options.onChange ) {
				this.options.onChange( this.selectedTools.slice() );
			}
		},

		/**
		 * Set selected tools.
		 *
		 * @param {Array} tools Array of tool slugs.
		 */
		setSelectedTools: function( tools ) {
			var self = this;

			// Uncheck all first.
			this.$block.find( '.wp-mcp-ai-tools-grid__checkbox' ).prop( 'checked', false );

			// Check specified tools.
			tools.forEach( function( slug ) {
				self.$block.find( '.wp-mcp-ai-tools-grid__checkbox[value="' + slug + '"]' ).prop( 'checked', true );
			} );

			this.updateSelection();
		},

		/**
		 * Get selected tools.
		 *
		 * @return {Array} Selected tool slugs.
		 */
		getSelectedTools: function() {
			return this.selectedTools.slice();
		}
	};

	/**
	 * Knowledge Base Controller
	 *
	 * @param {jQuery} $block   The knowledge base block.
	 * @param {Object} options  Callback options.
	 */
	function KnowledgeBaseController( $block, options ) {
		this.$block = $block;
		this.options = options || {};
		this.uploadedFiles = [];
		this.isUploading = false;

		this.allowedTypes = ( $block.data( 'allowed-types' ) || '' ).split( ',' ).map( function( t ) {
			return t.trim().toLowerCase();
		} );
		this.maxFiles = parseInt( $block.data( 'max-files' ), 10 ) || 10;
		this.maxSize = parseInt( $block.data( 'max-size' ), 10 ) || 10485760;
		this.nonce = $block.data( 'nonce' ) || '';
		this.uploadUrl = $block.data( 'upload-url' ) || '/wp-json/wp/v2/media';

		this.$dropzone = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__dropzone' );
		this.$fileInput = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__file-input' );
		this.$fileList = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__file-list' );
		this.$fileIdsInput = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__file-ids' );
		this.$count = $block.find( '.wp-mcp-ai-knowledge-base__count' );
		this.$clearAll = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__clear-all' );
		this.$progress = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__progress' );
		this.$progressFill = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__progress-fill' );
		this.$progressText = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__progress-text' );

		this.init();
	}

	KnowledgeBaseController.prototype = {
		/**
		 * Initialize the controller.
		 */
		init: function() {
			var self = this;

			// Click to upload.
			this.$dropzone.on( 'click', function() {
				self.$fileInput.trigger( 'click' );
			} );

			// Keyboard support.
			this.$dropzone.on( 'keydown', function( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					self.$fileInput.trigger( 'click' );
				}
			} );

			// File input change.
			this.$fileInput.on( 'change', function() {
				var files = this.files;
				if ( files && files.length > 0 ) {
					self.handleFiles( files );
				}
				// Reset input to allow re-selecting same file.
				this.value = '';
			} );

			// Drag and drop.
			this.$dropzone.on( 'dragover dragenter', function( e ) {
				e.preventDefault();
				e.stopPropagation();
				$( this ).addClass( 'wp-block-wp-mcp-ai-knowledge-base__dropzone--dragover' );
			} );

			this.$dropzone.on( 'dragleave drop', function( e ) {
				e.preventDefault();
				e.stopPropagation();
				$( this ).removeClass( 'wp-block-wp-mcp-ai-knowledge-base__dropzone--dragover' );
			} );

			this.$dropzone.on( 'drop', function( e ) {
				var files = e.originalEvent.dataTransfer.files;
				if ( files && files.length > 0 ) {
					self.handleFiles( files );
				}
			} );

			// Clear all button.
			this.$clearAll.on( 'click', function() {
				self.clearAllFiles();
			} );

			// Remove individual file.
			this.$fileList.on( 'click', '.wp-block-wp-mcp-ai-knowledge-base__file-remove', function() {
				var $item = $( this ).closest( '.wp-block-wp-mcp-ai-knowledge-base__file-item' );
				var fileId = $item.data( 'file-id' );
				self.removeFile( fileId );
			} );
		},

		/**
		 * Handle selected files.
		 *
		 * @param {FileList} files Files to process.
		 */
		handleFiles: function( files ) {
			var self = this;
			var filesToUpload = [];

			for ( var i = 0; i < files.length; i++ ) {
				var file = files[ i ];
				var validation = this.validateFile( file );

				if ( validation.valid ) {
					filesToUpload.push( file );
				} else {
					this.showFileError( file.name, validation.error );
				}
			}

			if ( filesToUpload.length > 0 ) {
				this.uploadFiles( filesToUpload );
			}
		},

		/**
		 * Validate a file.
		 *
		 * @param {File} file File to validate.
		 * @return {Object} Validation result.
		 */
		validateFile: function( file ) {
			// Check max files.
			if ( this.uploadedFiles.length >= this.maxFiles ) {
				return { valid: false, error: 'Maximum number of files reached' };
			}

			// Check file size.
			if ( file.size > this.maxSize ) {
				return { valid: false, error: 'File is too large (max ' + this.formatSize( this.maxSize ) + ')' };
			}

			// Check file type.
			var ext = '.' + file.name.split( '.' ).pop().toLowerCase();
			if ( this.allowedTypes.length > 0 && this.allowedTypes.indexOf( ext ) === -1 ) {
				return { valid: false, error: 'Invalid file type' };
			}

			return { valid: true };
		},

		/**
		 * Upload files to WordPress media library.
		 *
		 * @param {Array} files Files to upload.
		 */
		uploadFiles: function( files ) {
			var self = this;
			var uploaded = 0;
			var total = files.length;

			this.isUploading = true;
			this.showProgress( 0, total );

			files.forEach( function( file, index ) {
				// Add temporary item.
				var tempId = 'temp-' + Date.now() + '-' + index;
				self.addFileItem( {
					id: tempId,
					name: file.name,
					size: file.size,
					uploading: true
				} );

				// Upload the file.
				self.uploadFile( file, tempId, function( result ) {
					uploaded++;
					self.updateProgress( uploaded, total );

					if ( result.success ) {
						// Update item with real data.
						self.updateFileItem( tempId, result.data );
					} else {
						// Show error.
						self.updateFileItemError( tempId, result.error );
					}

					if ( uploaded === total ) {
						self.isUploading = false;
						self.hideProgress();
					}
				} );
			} );
		},

		/**
		 * Upload a single file.
		 *
		 * @param {File}     file     File to upload.
		 * @param {string}   tempId   Temporary ID.
		 * @param {Function} callback Callback function.
		 */
		uploadFile: function( file, tempId, callback ) {
			var self = this;
			var formData = new FormData();
			formData.append( 'file', file );

			$.ajax( {
				url: self.uploadUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				headers: {
					'X-WP-Nonce': self.nonce
				},
				success: function( response ) {
					callback( {
						success: true,
						data: {
							id: response.id,
							name: response.title.rendered || file.name,
							size: response.media_details && response.media_details.filesize ? response.media_details.filesize : file.size,
							url: response.source_url,
							mimeType: response.mime_type
						}
					} );
				},
				error: function( xhr ) {
					var errorMessage = 'Upload failed';
					if ( xhr.responseJSON && xhr.responseJSON.message ) {
						errorMessage = xhr.responseJSON.message;
					}
					callback( { success: false, error: errorMessage } );
				}
			} );
		},

		/**
		 * Add a file item to the list.
		 *
		 * @param {Object} file File data.
		 */
		addFileItem: function( file ) {
			var ext = file.name.split( '.' ).pop().toUpperCase();
			var uploadingClass = file.uploading ? ' wp-block-wp-mcp-ai-knowledge-base__file-item--uploading' : '';

			var html = '<li class="wp-block-wp-mcp-ai-knowledge-base__file-item' + uploadingClass + '" data-file-id="' + file.id + '">' +
				'<span class="wp-block-wp-mcp-ai-knowledge-base__file-icon">' + this.escapeHtml( ext ) + '</span>' +
				'<div class="wp-block-wp-mcp-ai-knowledge-base__file-info">' +
				'<span class="wp-block-wp-mcp-ai-knowledge-base__file-name">' + this.escapeHtml( file.name ) + '</span>' +
				'<span class="wp-block-wp-mcp-ai-knowledge-base__file-meta">' +
				( file.uploading ? 'Uploading...' : this.formatSize( file.size ) ) +
				'</span>' +
				'</div>' +
				( file.uploading ? '' : '<button type="button" class="wp-block-wp-mcp-ai-knowledge-base__file-remove">Remove</button>' ) +
				'</li>';

			this.$fileList.append( html );
			this.updateCount();
		},

		/**
		 * Update a file item with real data.
		 *
		 * @param {string} tempId  Temporary ID.
		 * @param {Object} data    Real file data.
		 */
		updateFileItem: function( tempId, data ) {
			var $item = this.$fileList.find( '[data-file-id="' + tempId + '"]' );
			if ( $item.length === 0 ) {
				return;
			}

			$item.attr( 'data-file-id', data.id );
			$item.removeClass( 'wp-block-wp-mcp-ai-knowledge-base__file-item--uploading' );
			$item.find( '.wp-block-wp-mcp-ai-knowledge-base__file-meta' ).text( this.formatSize( data.size ) );
			$item.append( '<button type="button" class="wp-block-wp-mcp-ai-knowledge-base__file-remove">Remove</button>' );

			// Add to uploaded files.
			this.uploadedFiles.push( data );
			this.updateFileIds();
			this.triggerChange();
		},

		/**
		 * Update a file item with error.
		 *
		 * @param {string} tempId Temporary ID.
		 * @param {string} error  Error message.
		 */
		updateFileItemError: function( tempId, error ) {
			var $item = this.$fileList.find( '[data-file-id="' + tempId + '"]' );
			if ( $item.length === 0 ) {
				return;
			}

			$item.removeClass( 'wp-block-wp-mcp-ai-knowledge-base__file-item--uploading' );
			$item.addClass( 'wp-block-wp-mcp-ai-knowledge-base__file-item--error' );
			$item.find( '.wp-block-wp-mcp-ai-knowledge-base__file-meta' ).html(
				'<span class="wp-block-wp-mcp-ai-knowledge-base__file-error">' + this.escapeHtml( error ) + '</span>'
			);
			$item.append( '<button type="button" class="wp-block-wp-mcp-ai-knowledge-base__file-remove">Remove</button>' );
		},

		/**
		 * Show file error without adding an item.
		 *
		 * @param {string} fileName File name.
		 * @param {string} error    Error message.
		 */
		showFileError: function( fileName, error ) {
			var ext = fileName.split( '.' ).pop().toUpperCase();
			var html = '<li class="wp-block-wp-mcp-ai-knowledge-base__file-item wp-block-wp-mcp-ai-knowledge-base__file-item--error" data-file-id="error-' + Date.now() + '">' +
				'<span class="wp-block-wp-mcp-ai-knowledge-base__file-icon">' + this.escapeHtml( ext ) + '</span>' +
				'<div class="wp-block-wp-mcp-ai-knowledge-base__file-info">' +
				'<span class="wp-block-wp-mcp-ai-knowledge-base__file-name">' + this.escapeHtml( fileName ) + '</span>' +
				'<span class="wp-block-wp-mcp-ai-knowledge-base__file-error">' + this.escapeHtml( error ) + '</span>' +
				'</div>' +
				'<button type="button" class="wp-block-wp-mcp-ai-knowledge-base__file-remove">Remove</button>' +
				'</li>';

			this.$fileList.append( html );
		},

		/**
		 * Remove a file.
		 *
		 * @param {number|string} fileId File ID.
		 */
		removeFile: function( fileId ) {
			// Remove from list.
			this.$fileList.find( '[data-file-id="' + fileId + '"]' ).remove();

			// Remove from uploaded files array.
			this.uploadedFiles = this.uploadedFiles.filter( function( f ) {
				return f.id !== fileId;
			} );

			this.updateCount();
			this.updateFileIds();
			this.triggerChange();
		},

		/**
		 * Clear all files.
		 */
		clearAllFiles: function() {
			this.$fileList.empty();
			this.uploadedFiles = [];
			this.updateCount();
			this.updateFileIds();
			this.triggerChange();
		},

		/**
		 * Update the file count display.
		 */
		updateCount: function() {
			var count = this.uploadedFiles.length;
			this.$count.text( count );
			this.$clearAll.toggle( count > 0 );
		},

		/**
		 * Update the hidden file IDs input.
		 */
		updateFileIds: function() {
			var ids = this.uploadedFiles.map( function( f ) {
				return f.id;
			} );
			this.$fileIdsInput.val( ids.join( ',' ) );
		},

		/**
		 * Show upload progress.
		 *
		 * @param {number} current Current count.
		 * @param {number} total   Total count.
		 */
		showProgress: function( current, total ) {
			var percent = total > 0 ? Math.round( ( current / total ) * 100 ) : 0;
			this.$progressFill.css( 'width', percent + '%' );
			this.$progressText.text( 'Uploading ' + current + ' of ' + total + '...' );
			this.$progress.show();
		},

		/**
		 * Update progress.
		 *
		 * @param {number} current Current count.
		 * @param {number} total   Total count.
		 */
		updateProgress: function( current, total ) {
			var percent = total > 0 ? Math.round( ( current / total ) * 100 ) : 0;
			this.$progressFill.css( 'width', percent + '%' );
			this.$progressText.text( 'Uploading ' + current + ' of ' + total + '...' );
		},

		/**
		 * Hide progress.
		 */
		hideProgress: function() {
			var self = this;
			setTimeout( function() {
				self.$progress.hide();
				self.$progressFill.css( 'width', '0%' );
			}, 500 );
		},

		/**
		 * Format file size.
		 *
		 * @param {number} bytes Size in bytes.
		 * @return {string} Formatted size.
		 */
		formatSize: function( bytes ) {
			if ( bytes === 0 ) {
				return '0 B';
			}
			var k = 1024;
			var sizes = [ 'B', 'KB', 'MB', 'GB' ];
			var i = Math.floor( Math.log( bytes ) / Math.log( k ) );
			return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 1 ) ) + ' ' + sizes[ i ];
		},

		/**
		 * Escape HTML.
		 *
		 * @param {string} text Text to escape.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function( text ) {
			var div = document.createElement( 'div' );
			div.textContent = text;
			return div.innerHTML;
		},

		/**
		 * Trigger change callback.
		 */
		triggerChange: function() {
			if ( this.options.onChange ) {
				this.options.onChange( this.getUploadedFiles() );
			}
		},

		/**
		 * Get uploaded files.
		 *
		 * @return {Array} Uploaded file data.
		 */
		getUploadedFiles: function() {
			return this.uploadedFiles.slice();
		},

		/**
		 * Get uploaded file IDs.
		 *
		 * @return {Array} File IDs.
		 */
		getUploadedFileIds: function() {
			return this.uploadedFiles.map( function( f ) {
				return f.id;
			} );
		}
	};

	// Initialize on document ready.
	$( document ).ready( function() {
		AssistantBuilderBlock.init();

		// Initialize standalone knowledge base blocks.
		$( '.wp-block-wp-mcp-ai-knowledge-base' ).each( function() {
			var $block = $( this );

			// Skip if inside an assistant builder block.
			if ( $block.closest( '.wp-block-wp-mcp-ai-assistant-builder' ).length > 0 ) {
				return;
			}

			new KnowledgeBaseController( $block );
		} );
	} );

	// Expose for external use.
	window.WpMcpAiAssistantBuilderBlock = AssistantBuilderBlock;
	window.WpMcpAiKnowledgeBaseController = KnowledgeBaseController;

} )( jQuery );
