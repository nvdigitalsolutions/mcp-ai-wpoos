/**
 * Media Template Admin UI JavaScript (Phase 4)
 *
 * Provides interactive functionality for the Media Template admin interface
 * including quick apply, preview, and bulk operations.
 *
 * @package WP_MCP_AI
 */

(function ($) {
	'use strict';

	const MediaTemplateAdmin = {
		/**
		 * Initialize the admin interface enhancements.
		 */
		init: function () {
			this.initQuickApply();
			this.initPreview();
			this.initBulkActions();
			this.initDuplicateConfirm();
			this.handleExportNotice();
			this.enhanceOperationColumn();
		},

		/**
		 * Initialize quick apply functionality.
		 */
		initQuickApply: function () {
			$(document).on('click', '.quick-apply-template', function (e) {
				e.preventDefault();

				const button = $(this);
				const templateId = button.data('template-id');

				// Open media library modal to select image.
				if (typeof wp !== 'undefined' && wp.media) {
					const frame = wp.media({
						title: mcpAiMediaTemplate.i18n.selectImage,
						button: {
							text: mcpAiMediaTemplate.i18n.processing
						},
						multiple: false,
						library: {
							type: 'image'
						}
					});

					frame.on('select', function () {
						const attachment = frame.state().get('selection').first().toJSON();
						MediaTemplateAdmin.applyTemplate(templateId, attachment.id, button);
					});

					frame.open();
				} else {
					alert(mcpAiMediaTemplate.i18n.applyError);
				}
			});
		},

		/**
		 * Apply template to an image.
		 *
		 * @param {number} templateId Template ID.
		 * @param {number} attachmentId Attachment ID.
		 * @param {jQuery} button Button element.
		 */
		applyTemplate: function (templateId, attachmentId, button) {
			const originalText = button.text();
			button.prop('disabled', true).text(mcpAiMediaTemplate.i18n.processing);

			$.ajax({
				url: mcpAiMediaTemplate.ajaxUrl,
				type: 'POST',
				data: {
					action: 'mcp_ai_quick_apply_template',
					nonce: mcpAiMediaTemplate.nonce,
					template_id: templateId,
					attachment_id: attachmentId
				},
				success: function (response) {
					if (response.success) {
						MediaTemplateAdmin.showMessage(mcpAiMediaTemplate.i18n.applySuccess, 'success');

						// Redirect to media library to view result.
						if (response.data && response.data.attachment_id) {
							window.location.href = 'post.php?post=' + response.data.attachment_id + '&action=edit';
						}
					} else {
						MediaTemplateAdmin.showMessage(
							response.data && response.data.error ? response.data.error : mcpAiMediaTemplate.i18n.applyError,
							'error'
						);
					}
				},
				error: function () {
					MediaTemplateAdmin.showMessage(mcpAiMediaTemplate.i18n.applyError, 'error');
				},
				complete: function () {
					button.prop('disabled', false).text(originalText);
				}
			});
		},

		/**
		 * Initialize template preview functionality.
		 */
		initPreview: function () {
			$(document).on('click', '.preview-template', function (e) {
				e.preventDefault();

				const button = $(this);
				const templateId = button.data('template-id');

				MediaTemplateAdmin.loadPreview(templateId);
			});
		},

		/**
		 * Load template preview.
		 *
		 * @param {number} templateId Template ID.
		 */
		loadPreview: function (templateId) {
			$.ajax({
				url: mcpAiMediaTemplate.ajaxUrl,
				type: 'POST',
				data: {
					action: 'mcp_ai_preview_template',
					nonce: mcpAiMediaTemplate.nonce,
					template_id: templateId
				},
				success: function (response) {
					if (response.success && response.data) {
						MediaTemplateAdmin.displayPreview(response.data);
					} else {
						MediaTemplateAdmin.showMessage(mcpAiMediaTemplate.i18n.previewError, 'error');
					}
				},
				error: function () {
					MediaTemplateAdmin.showMessage(mcpAiMediaTemplate.i18n.previewError, 'error');
				}
			});
		},

		/**
		 * Display template preview.
		 *
		 * @param {Object} data Preview data.
		 */
		displayPreview: function (data) {
			const previewHtml = `
				<div class="template-preview-card">
					<h3>${data.title}</h3>
					<div class="template-preview-info">
						<div class="template-preview-info-item">
							<label>Operation</label>
							<div class="value">${data.operation || 'Not set'}</div>
						</div>
						<div class="template-preview-info-item">
							<label>Parameters</label>
							<div class="value">${Object.keys(data.parameters || {}).length} configured</div>
						</div>
					</div>
					<div class="template-preview-summary">
						<strong>Summary:</strong> ${data.summary}
					</div>
				</div>
			`;

			// Insert after the row or show in modal.
			// For now, show as admin notice.
			$('.wrap').prepend(previewHtml);

			// Scroll to preview.
			$('html, body').animate({
				scrollTop: $('.template-preview-card').offset().top - 50
			}, 300);
		},

		/**
		 * Initialize bulk action enhancements.
		 */
		initBulkActions: function () {
			// Handle custom bulk action confirmations.
			$('#doaction, #doaction2').on('click', function (e) {
				const action = $(this).siblings('select').val();

				if (action === 'duplicate_templates') {
					const checked = $('input[name="post[]"]:checked').length;
					if (checked === 0) {
						e.preventDefault();
						alert('Please select templates to duplicate.');
						return false;
					}
				}

				if (action === 'export_templates') {
					const checked = $('input[name="post[]"]:checked').length;
					if (checked === 0) {
						e.preventDefault();
						alert('Please select templates to export.');
						return false;
					}
				}
			});
		},

		/**
		 * Initialize duplicate confirmation.
		 */
		initDuplicateConfirm: function () {
			$('.row-actions .duplicate a').on('click', function (e) {
				if (!confirm(mcpAiMediaTemplate.i18n.confirmDuplicate)) {
					e.preventDefault();
					return false;
				}
			});
		},

		/**
		 * Handle export notice with download link.
		 */
		handleExportNotice: function () {
			const urlParams = new URLSearchParams(window.location.search);
			const exportedCount = urlParams.get('exported_templates');
			const exportKey = urlParams.get('export_key');

			if (exportedCount && exportKey) {
				const message = `
					<div class="notice notice-success template-bulk-action is-dismissible">
						<p><strong>Export Complete!</strong> ${exportedCount} template(s) exported.</p>
						<p>
							<a href="${mcpAiMediaTemplate.ajaxUrl}?action=mcp_ai_download_export&key=${exportKey}&_wpnonce=${mcpAiMediaTemplate.nonce}" 
							   class="template-export-link" download="media-templates-export.json">
								<span class="dashicons dashicons-download"></span>
								Download Export File
							</a>
						</p>
					</div>
				`;

				$('.wrap h1').after(message);

				// Clean URL.
				const cleanUrl = window.location.pathname + '?post_type=mcp_ai_media_tpl';
				window.history.replaceState({}, document.title, cleanUrl);
			}

			// Handle duplicated notice.
			const duplicatedCount = urlParams.get('duplicated_templates');
			if (duplicatedCount) {
				const message = `
					<div class="notice notice-success template-bulk-action is-dismissible">
						<p><strong>Templates Duplicated!</strong> ${duplicatedCount} template(s) duplicated successfully.</p>
					</div>
				`;

				$('.wrap h1').after(message);

				// Clean URL.
				const cleanUrl = window.location.pathname + '?post_type=mcp_ai_media_tpl';
				window.history.replaceState({}, document.title, cleanUrl);
			}
		},

		/**
		 * Enhance operation column with badges.
		 */
		enhanceOperationColumn: function () {
			$('.column-operation').each(function () {
				const $cell = $(this);
				const operation = $cell.text().trim().toLowerCase();

				if (operation && operation !== 'not set') {
					let badgeClass = 'operation-badge';

					if (operation.includes('resize')) {
						badgeClass += ' resize';
					} else if (operation.includes('logo')) {
						badgeClass += ' logo';
					} else if (operation.includes('ai')) {
						badgeClass += ' ai';
					}

					$cell.wrapInner('<span class="' + badgeClass + '"></span>');
				}
			});
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
		MediaTemplateAdmin.init();
	});

})(jQuery);
