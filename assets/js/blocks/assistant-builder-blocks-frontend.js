/**
 * WP MCP AI Assistant Builder Blocks - Frontend JavaScript
 *
 * Handles frontend interactivity for the blocks rendered via PHP.
 *
 * @package WP_MCP_AI
 */

/* global jQuery */

( function ( $ ) {
	'use strict';

	/**
	 * Initialize all assistant builder blocks on the page.
	 */
	function initBlocks() {
		initAssistantSelectors();
		initToolsGrids();
		initKnowledgeBases();
		initAssistantBuilders();
	}

	/**
	 * Initialize assistant selector blocks.
	 */
	function initAssistantSelectors() {
		$( '.wp-block-wp-mcp-ai-assistant-selector' ).each( function () {
			var $block = $( this );
			var $select = $block.find( '.wp-mcp-ai-assistant-selector__select' );
			var $startBtn = $block.find( '.wp-mcp-ai-assistant-selector__start' );

			// Enable/disable start button based on selection.
			$select.on( 'change', function () {
				$startBtn.prop( 'disabled', ! $( this ).val() );

				// Trigger custom event for other blocks to listen to.
				$block.trigger( 'assistantSelected', {
					assistantId: $( this ).val(),
					tools: $( this ).find( ':selected' ).data( 'tools' ) || [],
					shortcuts: $( this ).find( ':selected' ).data( 'shortcuts' ) || []
				} );
			} );

			// Handle start button click.
			$startBtn.on( 'click', function () {
				var assistantId = $select.val();
				if ( assistantId ) {
					$block.trigger( 'startChat', { assistantId: assistantId } );
				}
			} );
		} );
	}

	/**
	 * Initialize tools grid blocks.
	 */
	function initToolsGrids() {
		$( '.wp-block-wp-mcp-ai-tools-grid' ).each( function () {
			var $block = $( this );
			var $selectAll = $block.find( '.wp-mcp-ai-tools-grid__select-all' );
			var $deselectAll = $block.find( '.wp-mcp-ai-tools-grid__deselect-all' );
			var $selectedCount = $block.find( '.wp-mcp-ai-tools-grid__selected-count' );
			var $checkboxes = $block.find( '.wp-mcp-ai-tools-grid__checkbox' );

			/**
			 * Update counts and selected state.
			 */
			function updateCounts() {
				var totalSelected = $checkboxes.filter( ':checked' ).length;
				$selectedCount.text( totalSelected );

				// Update group counts.
				$block.find( '.wp-block-wp-mcp-ai-tools-grid__group' ).each( function () {
					var $group = $( this );
					var $groupCheckboxes = $group.find( '.wp-mcp-ai-tools-grid__checkbox' );
					var groupSelected = $groupCheckboxes.filter( ':checked' ).length;
					$group.find( '.wp-mcp-ai-tools-grid__group-selected' ).text( groupSelected );
				} );

				// Update item classes.
				$checkboxes.each( function () {
					var $checkbox = $( this );
					$checkbox.closest( '.wp-block-wp-mcp-ai-tools-grid__item' )
						.toggleClass( 'wp-block-wp-mcp-ai-tools-grid__item--selected', $checkbox.is( ':checked' ) );
				} );

				// Trigger custom event.
				var selectedTools = $checkboxes.filter( ':checked' ).map( function () {
					return $( this ).val();
				} ).get();
				$block.trigger( 'toolsChanged', { tools: selectedTools } );
			}

			// Handle checkbox changes.
			$checkboxes.on( 'change', updateCounts );

			// Handle select all.
			$selectAll.on( 'click', function () {
				$checkboxes.prop( 'checked', true );
				updateCounts();
			} );

			// Handle deselect all.
			$deselectAll.on( 'click', function () {
				$checkboxes.prop( 'checked', false );
				updateCounts();
			} );

			// Initial count update.
			updateCounts();
		} );
	}

	/**
	 * Initialize knowledge base blocks.
	 */
	function initKnowledgeBases() {
		$( '.wp-block-wp-mcp-ai-knowledge-base' ).each( function () {
			var $block = $( this );
			var $dropzone = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__dropzone' );
			var $fileInput = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__file-input' );
			var $fileList = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__file-list' );
			var $fileIds = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__file-ids' );
			var $count = $block.find( '.wp-mcp-ai-knowledge-base__count' );
			var $clearAll = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__clear-all' );
			var $progress = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__progress' );
			var $progressFill = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__progress-fill' );
			var $progressText = $block.find( '.wp-block-wp-mcp-ai-knowledge-base__progress-text' );

			var allowedTypes = ( $block.data( 'allowed-types' ) || '' ).split( ',' );
			var maxFiles = $block.data( 'max-files' ) || 10;
			var maxSize = $block.data( 'max-size' ) || 10485760; // 10MB default.
			var uploadUrl = $block.data( 'upload-url' ) || '';
			var nonce = $block.data( 'nonce' ) || '';

			var files = [];
			var uploading = false;

			/**
			 * Update the file count and clear button visibility.
			 */
			function updateUI() {
				$count.text( files.length );
				$clearAll.toggle( files.length > 0 );
				$fileIds.val( files.filter( function ( f ) { return f.id; } ).map( function ( f ) { return f.id; } ).join( ',' ) );
				$block.trigger( 'filesChanged', { files: files } );
			}

			/**
			 * Get file extension.
			 *
			 * @param {string} filename File name.
			 * @return {string} Extension.
			 */
			function getExtension( filename ) {
				return '.' + filename.split( '.' ).pop().toLowerCase();
			}

			/**
			 * Format file size.
			 *
			 * @param {number} bytes File size in bytes.
			 * @return {string} Formatted size.
			 */
			function formatSize( bytes ) {
				if ( bytes < 1024 ) {
					return bytes + ' B';
				}
				if ( bytes < 1048576 ) {
					return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
				}
				return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
			}

			/**
			 * Validate a file.
			 *
			 * @param {File} file File to validate.
			 * @return {string|null} Error message or null if valid.
			 */
			function validateFile( file ) {
				if ( files.length >= maxFiles ) {
					return 'Maximum number of files reached';
				}

				var ext = getExtension( file.name );
				if ( allowedTypes.length > 0 && allowedTypes.indexOf( ext ) === -1 ) {
					return 'Invalid file type: ' + ext;
				}

				if ( file.size > maxSize ) {
					return 'File too large: ' + formatSize( file.size );
				}

				return null;
			}

			/**
			 * Add a file to the list.
			 *
			 * @param {File} file The file object.
			 * @param {string|null} error Error message if any.
			 * @return {Object} File data object.
			 */
			function addFileToList( file, error ) {
				var fileData = {
					file: file,
					name: file.name,
					size: file.size,
					error: error,
					id: null,
					status: error ? 'error' : 'pending'
				};

				files.push( fileData );

				var itemClass = 'wp-block-wp-mcp-ai-knowledge-base__file-item';
				if ( error ) {
					itemClass += ' wp-block-wp-mcp-ai-knowledge-base__file-item--error';
				}

				var $item = $( '<li>', { class: itemClass, 'data-index': files.length - 1 } );

				var ext = getExtension( file.name ).replace( '.', '' ).toUpperCase();
				$item.append( $( '<span>', { class: 'wp-block-wp-mcp-ai-knowledge-base__file-icon', text: ext } ) );

				var $info = $( '<span>', { class: 'wp-block-wp-mcp-ai-knowledge-base__file-info' } );
				$info.append( $( '<span>', { class: 'wp-block-wp-mcp-ai-knowledge-base__file-name', text: file.name } ) );

				if ( error ) {
					$info.append( $( '<span>', { class: 'wp-block-wp-mcp-ai-knowledge-base__file-error', text: error } ) );
				} else {
					$info.append( $( '<span>', { class: 'wp-block-wp-mcp-ai-knowledge-base__file-meta', text: formatSize( file.size ) } ) );
				}

				$item.append( $info );
				$item.append( $( '<button>', {
					type: 'button',
					class: 'wp-block-wp-mcp-ai-knowledge-base__file-remove',
					text: 'Remove'
				} ) );

				$fileList.append( $item );
				updateUI();

				return fileData;
			}

			/**
			 * Upload pending files.
			 */
			function uploadFiles() {
				if ( uploading || ! uploadUrl ) {
					return;
				}

				var pendingFiles = files.filter( function ( f ) { return f.status === 'pending'; } );
				if ( pendingFiles.length === 0 ) {
					return;
				}

				uploading = true;
				$progress.show();
				$progressFill.css( 'width', '0%' );

				var total = pendingFiles.length;
				var completed = 0;

				function uploadNext() {
					if ( completed >= total ) {
						uploading = false;
						$progress.hide();
						updateUI();
						return;
					}

					var fileData = pendingFiles[ completed ];
					var $item = $fileList.find( '[data-index="' + files.indexOf( fileData ) + '"]' );
					$item.addClass( 'wp-block-wp-mcp-ai-knowledge-base__file-item--uploading' );

					var formData = new FormData();
					formData.append( 'file', fileData.file );

					$.ajax( {
						url: uploadUrl,
						type: 'POST',
						data: formData,
						processData: false,
						contentType: false,
						headers: { 'X-WP-Nonce': nonce },
						success: function ( response ) {
							fileData.id = response.id;
							fileData.status = 'uploaded';
							$item.removeClass( 'wp-block-wp-mcp-ai-knowledge-base__file-item--uploading' );
						},
						error: function ( xhr ) {
							var errorMsg = 'Upload failed';
							try {
								var resp = JSON.parse( xhr.responseText );
								if ( resp.message ) {
									errorMsg = resp.message;
								}
							} catch ( e ) {
								// Keep default error.
							}
							fileData.error = errorMsg;
							fileData.status = 'error';
							$item.addClass( 'wp-block-wp-mcp-ai-knowledge-base__file-item--error' );
							$item.find( '.wp-block-wp-mcp-ai-knowledge-base__file-meta' )
								.removeClass( 'wp-block-wp-mcp-ai-knowledge-base__file-meta' )
								.addClass( 'wp-block-wp-mcp-ai-knowledge-base__file-error' )
								.text( errorMsg );
						},
						complete: function () {
							completed++;
							var percent = Math.round( ( completed / total ) * 100 );
							$progressFill.css( 'width', percent + '%' );
							$progressText.text( 'Uploading... ' + completed + '/' + total );
							uploadNext();
						}
					} );
				}

				uploadNext();
			}

			/**
			 * Handle files being added.
			 *
			 * @param {FileList} fileList Files to add.
			 */
			function handleFiles( fileList ) {
				for ( var i = 0; i < fileList.length; i++ ) {
					var file = fileList[ i ];
					var error = validateFile( file );
					addFileToList( file, error );
				}
				uploadFiles();
			}

			// Handle dropzone click.
			$dropzone.on( 'click', function () {
				$fileInput.click();
			} );

			// Handle file input change.
			$fileInput.on( 'change', function () {
				handleFiles( this.files );
				this.value = '';
			} );

			// Handle drag and drop.
			$dropzone.on( 'dragover dragenter', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$dropzone.addClass( 'wp-block-wp-mcp-ai-knowledge-base__dropzone--dragover' );
			} );

			$dropzone.on( 'dragleave dragend drop', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$dropzone.removeClass( 'wp-block-wp-mcp-ai-knowledge-base__dropzone--dragover' );
			} );

			$dropzone.on( 'drop', function ( e ) {
				e.preventDefault();
				var dt = e.originalEvent.dataTransfer;
				if ( dt && dt.files ) {
					handleFiles( dt.files );
				}
			} );

			// Handle remove button.
			$fileList.on( 'click', '.wp-block-wp-mcp-ai-knowledge-base__file-remove', function () {
				var $item = $( this ).closest( '.wp-block-wp-mcp-ai-knowledge-base__file-item' );
				var index = parseInt( $item.data( 'index' ), 10 );
				files.splice( index, 1 );
				$item.remove();

				// Re-index remaining items.
				$fileList.find( '.wp-block-wp-mcp-ai-knowledge-base__file-item' ).each( function ( i ) {
					$( this ).data( 'index', i );
				} );

				updateUI();
			} );

			// Handle clear all.
			$clearAll.on( 'click', function () {
				files = [];
				$fileList.empty();
				updateUI();
			} );

			// Handle keyboard navigation for dropzone.
			$dropzone.on( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					$fileInput.click();
				}
			} );
		} );
	}

	/**
	 * Initialize assistant builder blocks.
	 */
	function initAssistantBuilders() {
		$( '.wp-block-wp-mcp-ai-assistant-builder' ).each( function () {
			var $block = $( this );
			var $config = $block.find( '.wp-mcp-ai-assistant-builder-config' );
			var config = {};

			try {
				config = JSON.parse( $config.text() || '{}' );
			} catch ( e ) {
				// Keep empty config.
			}

			var $selector = $block.find( '.wp-block-wp-mcp-ai-assistant-selector' );
			var $tools = $block.find( '.wp-block-wp-mcp-ai-assistant-builder__tools' );
			var $knowledgeBase = $block.find( '.wp-block-wp-mcp-ai-assistant-builder__knowledge-base' );
			var $chat = $block.find( '.wp-block-wp-mcp-ai-assistant-builder__chat' );

			// Listen for assistant selection to show other components.
			$selector.on( 'startChat', function ( e, data ) {
				// Show tools and knowledge base.
				$tools.slideDown();
				$knowledgeBase.slideDown();
				$chat.slideDown();

				// Initialize chat if available.
				if ( window.wpMcpAiChat && typeof window.wpMcpAiChat.init === 'function' ) {
					window.wpMcpAiChat.init( $chat[ 0 ], {
						assistantId: data.assistantId,
						enableStreaming: config.enableStreaming,
						placeholder: config.chatPlaceholder
					} );
				}
			} );

			// Sync tools selection with chat.
			$tools.on( 'toolsChanged', function ( e, data ) {
				$chat.trigger( 'updateTools', data );
			} );

			// Sync knowledge base files with chat.
			$knowledgeBase.on( 'filesChanged', function ( e, data ) {
				$chat.trigger( 'updateKnowledgeBase', data );
			} );
		} );
	}

	// Initialize on document ready.
	$( document ).ready( initBlocks );

}( jQuery ) );
