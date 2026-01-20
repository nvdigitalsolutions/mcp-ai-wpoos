/**
 * Media Collection Admin UI JavaScript (Phase 4)
 *
 * Provides interactive functionality for the Media Collection admin interface
 * including quick process and bulk operations.
 *
 * @package WP_MCP_AI
 */

(function ($) {
	'use strict';

	const MediaCollectionAdmin = {
		/**
		 * Initialize the admin interface enhancements.
		 */
		init: function () {
			this.initQuickProcess();
			this.initBulkActions();
			this.handleProcessNotice();
			this.handleExportNotice();
		},

		/**
		 * Initialize quick process functionality.
		 */
		initQuickProcess: function () {
			$(document).on('click', '.quick-process-collection', function (e) {
				e.preventDefault();

				if (!confirm(mcpAiMediaCollection.i18n.confirmProcess)) {
					return false;
				}

				const button = $(this);
				const collectionId = button.data('collection-id');
				const originalText = button.text();

				button.text(mcpAiMediaCollection.i18n.processing);

				$.ajax({
					url: mcpAiMediaCollection.ajaxUrl,
					type: 'POST',
					data: {
						action: 'mcp_ai_quick_process_collection',
						nonce: mcpAiMediaCollection.nonce,
						collection_id: collectionId
					},
					success: function (response) {
						if (response.success) {
							MediaCollectionAdmin.showMessage(mcpAiMediaCollection.i18n.processSuccess, 'success');

							// Reload page to show updated stats.
							setTimeout(function () {
								window.location.reload();
							}, 1500);
						} else {
							MediaCollectionAdmin.showMessage(
								response.data && response.data.error ? response.data.error : mcpAiMediaCollection.i18n.processError,
								'error'
							);
							button.text(originalText);
						}
					},
					error: function () {
						MediaCollectionAdmin.showMessage(mcpAiMediaCollection.i18n.processError, 'error');
						button.text(originalText);
					}
				});
			});
		},

		/**
		 * Initialize bulk action enhancements.
		 */
		initBulkActions: function () {
			$('#doaction, #doaction2').on('click', function (e) {
				const action = $(this).siblings('select').val();

				if (action === 'process_collections') {
					const checked = $('input[name="post[]"]:checked').length;
					if (checked === 0) {
						e.preventDefault();
						alert('Please select collections to process.');
						return false;
					}

					if (!confirm('Are you sure you want to process ' + checked + ' collection(s)? This may take a while.')) {
						e.preventDefault();
						return false;
					}
				}

				if (action === 'export_collections') {
					const checked = $('input[name="post[]"]:checked').length;
					if (checked === 0) {
						e.preventDefault();
						alert('Please select collections to export.');
						return false;
					}
				}
			});
		},

		/**
		 * Handle process notice.
		 */
		handleProcessNotice: function () {
			const urlParams = new URLSearchParams(window.location.search);
			const processedCount = urlParams.get('processed_collections');
			const errorCount = urlParams.get('processing_errors');

			if (processedCount) {
				let message = `<strong>Processing Complete!</strong> ${processedCount} collection(s) processed successfully.`;

				if (errorCount) {
					message += ` ${errorCount} collection(s) failed to process.`;
				}

				const noticeClass = errorCount > 0 ? 'notice-warning' : 'notice-success';

				const notice = `
					<div class="notice ${noticeClass} template-bulk-action is-dismissible">
						<p>${message}</p>
					</div>
				`;

				$('.wrap h1').after(notice);

				// Clean URL.
				const cleanUrl = window.location.pathname + '?post_type=mcp_ai_media_coll';
				window.history.replaceState({}, document.title, cleanUrl);
			}
		},

		/**
		 * Handle export notice with download link.
		 */
		handleExportNotice: function () {
			const urlParams = new URLSearchParams(window.location.search);
			const exportedCount = urlParams.get('exported_collections');
			const exportKey = urlParams.get('export_key');

			if (exportedCount && exportKey) {
				const message = `
					<div class="notice notice-success template-bulk-action is-dismissible">
						<p><strong>Export Complete!</strong> ${exportedCount} collection(s) exported.</p>
						<p>
							<a href="${mcpAiMediaCollection.ajaxUrl}?action=mcp_ai_download_collection_export&key=${exportKey}&_wpnonce=${mcpAiMediaCollection.nonce}" 
							   class="template-export-link" download="media-collections-export.json">
								<span class="dashicons dashicons-download"></span>
								Download Export File
							</a>
						</p>
					</div>
				`;

				$('.wrap h1').after(message);

				// Clean URL.
				const cleanUrl = window.location.pathname + '?post_type=mcp_ai_media_coll';
				window.history.replaceState({}, document.title, cleanUrl);
			}
		},

		/**
		 * Show admin message.
		 *
		 * @param {string} message Message text.
		 * @param {string} type Message type (success/error).
		 */
		showMessage: function (message, type) {
			const className = type === 'success' ? 'template-success-message' : 'template-error-message';
			const messageHtml = `<div class="${className}">${message}</div>`;

			$('.wrap h1').after(messageHtml);

			// Scroll to message.
			$('html, body').animate({
				scrollTop: $('.' + className).offset().top - 50
			}, 300);

			// Auto-remove after 5 seconds.
			setTimeout(function () {
				$('.' + className).fadeOut(300, function () {
					$(this).remove();
				});
			}, 5000);
		}
	};

	// Initialize when DOM is ready.
	$(document).ready(function () {
		MediaCollectionAdmin.init();
	});

})(jQuery);
