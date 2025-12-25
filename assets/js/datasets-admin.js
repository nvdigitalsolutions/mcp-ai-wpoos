/**
 * HuggingFace Datasets Admin Page JavaScript
 */

(function ($) {
	'use strict';

	const DatasetsAdmin = {
		/**
		 * Initialize
		 */
		init: function () {
			this.bindEvents();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function () {
			// Search functionality
			$('#wp-mcp-ai-datasets-search-btn').on('click', this.handleSearch.bind(this));
			$('#wp-mcp-ai-datasets-search').on('keypress', function (e) {
				if (e.which === 13) {
					e.preventDefault();
					DatasetsAdmin.handleSearch();
				}
			});

			// Filter changes
			$('#wp-mcp-ai-datasets-category, #wp-mcp-ai-datasets-priority').on('change', this.handleSearch.bind(this));

			// Preview buttons
			$(document).on('click', '.wp-mcp-ai-dataset-preview', this.handlePreview.bind(this));

			// Copy code buttons
			$(document).on('click', '.wp-mcp-ai-dataset-copy-code', this.handleCopyCode.bind(this));

			// Modal close
			$(document).on('click', '.wp-mcp-ai-modal-close', this.closeModal.bind(this));
			$(document).on('click', '.wp-mcp-ai-modal', function (e) {
				if ($(e.target).hasClass('wp-mcp-ai-modal')) {
					DatasetsAdmin.closeModal();
				}
			});
		},

		/**
		 * Handle search/filter
		 */
		handleSearch: function () {
			const query = $('#wp-mcp-ai-datasets-search').val();
			const category = $('#wp-mcp-ai-datasets-category').val();
			const priority = $('#wp-mcp-ai-datasets-priority').val();

			$.ajax({
				url: wpMcpAiDatasets.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_search_datasets',
					nonce: wpMcpAiDatasets.nonce,
					query: query,
					category: category,
					priority: priority
				},
				beforeSend: function () {
					$('#wp-mcp-ai-datasets-grid').html('<div class="wp-mcp-ai-loading">' + wpMcpAiDatasets.i18n.loading + '</div>');
				},
				success: function (response) {
					if (response.success) {
						$('#wp-mcp-ai-datasets-grid').html(response.data.html);
						
						if (response.data.count === 0) {
							$('#wp-mcp-ai-datasets-grid').html(
								'<div class="notice notice-info"><p>' + wpMcpAiDatasets.i18n.noResults + '</p></div>'
							);
						}
					} else {
						$('#wp-mcp-ai-datasets-grid').html(
							'<div class="notice notice-error"><p>' + (response.data.message || wpMcpAiDatasets.i18n.error) + '</p></div>'
						);
					}
				},
				error: function () {
					$('#wp-mcp-ai-datasets-grid').html(
						'<div class="notice notice-error"><p>' + wpMcpAiDatasets.i18n.error + '</p></div>'
					);
				}
			});
		},

		/**
		 * Handle dataset preview
		 */
		handlePreview: function (e) {
			e.preventDefault();
			const $btn = $(e.currentTarget);
			const dataset = $btn.data('dataset');

			$.ajax({
				url: wpMcpAiDatasets.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_load_dataset_preview',
					nonce: wpMcpAiDatasets.nonce,
					dataset: dataset
				},
				beforeSend: function () {
					$('#wp-mcp-ai-modal-body').html('<div class="wp-mcp-ai-loading">' + wpMcpAiDatasets.i18n.loading + '</div>');
					$('#wp-mcp-ai-dataset-modal').show();
				},
				success: function (response) {
					if (response.success) {
						$('#wp-mcp-ai-modal-body').html(response.data.html);
					} else {
						$('#wp-mcp-ai-modal-body').html(
							'<div class="notice notice-error"><p>' + (response.data.message || wpMcpAiDatasets.i18n.error) + '</p></div>'
						);
					}
				},
				error: function () {
					$('#wp-mcp-ai-modal-body').html(
						'<div class="notice notice-error"><p>' + wpMcpAiDatasets.i18n.error + '</p></div>'
					);
				}
			});
		},

		/**
		 * Handle copy code
		 */
		handleCopyCode: function (e) {
			e.preventDefault();
			const $btn = $(e.currentTarget);
			const code = $btn.data('code');

			// Create temporary textarea
			const $temp = $('<textarea>');
			$('body').append($temp);
			$temp.val(code).select();
			
			try {
				document.execCommand('copy');
				
				// Visual feedback
				const originalText = $btn.html();
				$btn.addClass('copied').html('<span class="dashicons dashicons-yes"></span> ' + wpMcpAiDatasets.i18n.copied);
				
				setTimeout(function () {
					$btn.removeClass('copied').html(originalText);
				}, 2000);
			} catch (err) {
				console.error('Copy failed', err);
			}
			
			$temp.remove();
		},

		/**
		 * Close modal
		 */
		closeModal: function () {
			$('#wp-mcp-ai-dataset-modal').hide();
		}
	};

	// Initialize on document ready
	$(document).ready(function () {
		DatasetsAdmin.init();
	});

})(jQuery);
