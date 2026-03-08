/**
 * Enhanced Research Page JavaScript
 *
 * Handles workflow tab switching and interactions for enhanced research pages.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	// Constants
	const DEFAULT_FORMAT = 'csv';
	const MODE_TO_CONTENT_ID = {
		'chat': 'research-mode',
		'import': 'import-mode',
		'consolidate': 'consolidate-mode'
	};
	const CONSOLIDATION_ACTION_MAP = {
		'find-duplicate-tasks': 'find_duplicates',
		'organize-by-priority': 'organize_priority',
		'group-by-project': 'suggest_grouping'
	};

	/**
	 * Initialize enhanced research page functionality.
	 */
	function initEnhancedResearchPage() {
		// Handle workflow tab switching (for quiz research page)
		$('.workflow-option').on('click', function() {
			const workflow = $(this).data('workflow');
			
			// Update active state on buttons
			$('.workflow-option').removeClass('active');
			$(this).addClass('active');
			
			// Show corresponding content
			$('.workflow-content').removeClass('active').hide();
			$('#workflow-' + workflow).addClass('active').show();
			
			// Store selected workflow in sessionStorage
			sessionStorage.setItem('wp_mcp_ai_selected_workflow', workflow);
		});

		// Restore previously selected workflow
		const savedWorkflow = sessionStorage.getItem('wp_mcp_ai_selected_workflow');
		if (savedWorkflow) {
			$('.workflow-option[data-workflow="' + savedWorkflow + '"]').trigger('click');
		}

		// Handle mode tab switching (for task/project/etc research pages)
		$('.mode-tab').on('click', function(e) {
			e.preventDefault();
			
			// Get the target mode from the href
			const href = $(this).attr('href');
			const queryString = href.indexOf('?') !== -1 ? href.split('?')[1] : '';
			const urlParams = new URLSearchParams(queryString);
			const mode = urlParams.get('mode') || 'chat';
			
			// Update active state on tabs
			$('.mode-tab').removeClass('active');
			$(this).addClass('active');
			
			// Show corresponding content
			$('.research-mode-content').removeClass('active').hide();
			
			const contentId = MODE_TO_CONTENT_ID[mode] || 'research-mode';
			$('#' + contentId).addClass('active').show();
			
			// Update URL without page reload
			if (history.pushState) {
				const newUrl = href;
				history.pushState(null, '', newUrl);
			}
		});

		// Initialize correct tab based on URL on page load
		initializeActiveTab();

		// Handle example query buttons
		$(document).on('click', '.wp-mcp-ai-example-query', function(e) {
			e.preventDefault();
			const query = $(this).data('query');
			
			// Find the chat input and populate it
			const $chatInput = $('.wp-mcp-ai-chat-input, #wp-mcp-ai-message-input, textarea[name="message"]').first();
			if ($chatInput.length) {
				$chatInput.val(query).trigger('focus');
				
				// Auto-submit if there's a submit button visible
				const $submitBtn = $chatInput.closest('form').find('.wp-mcp-ai-send-button, button[type="submit"]').first();
				if ($submitBtn.length && $submitBtn.is(':visible')) {
					// Small delay to allow the value to be set
					setTimeout(function() {
						$submitBtn.trigger('click');
					}, 100);
				}
			}
		});

		// Handle file upload for import
		$('#wp-mcp-ai-import-file-input').on('change', function() {
			const files = this.files;
			if (files.length > 0) {
				$('.selected-file-name').text(files[0].name).show();
			} else {
				$('.selected-file-name').text('').hide();
			}
		});

		// Handle import button click
		$('#wp-mcp-ai-import-btn').on('click', function(e) {
			e.preventDefault();
			
			const $btn = $(this);
			const $results = $('#wp-mcp-ai-import-results');
			const $spinner = $btn.siblings('.spinner');
			
			// Get import data from textarea or file
			let importData = $('#wp-mcp-ai-import-text').val();
			const fileInput = document.getElementById('wp-mcp-ai-import-file-input');
			
			// Determine format
			let format = DEFAULT_FORMAT;
			
			if (fileInput && fileInput.files.length > 0) {
				// File upload
				const file = fileInput.files[0];
				const fileName = file.name.toLowerCase();
				
				if (fileName.endsWith('.json')) {
					format = 'json';
				}
				
				// Read file content
				const reader = new FileReader();
				reader.onload = function(e) {
					importData = e.target.result;
					processImport(importData, format, $btn, $results, $spinner);
				};
				reader.onerror = function() {
					$results.html('<div class="notice notice-error"><p>Failed to read file.</p></div>').show();
				};
				reader.readAsText(file);
				return;
			}
			
			// Process from textarea
			if (!importData) {
				$results.html('<div class="notice notice-error"><p>Please provide data to import.</p></div>').show();
				return;
			}
			
			// Try to detect format from content - lightweight check
			const trimmedData = importData.trim();
			if (trimmedData.charAt(0) === '{' || trimmedData.charAt(0) === '[') {
				format = 'json';
			}
			
			processImport(importData, format, $btn, $results, $spinner);
		});
		
		// Process import helper function
		function processImport(importData, format, $btn, $results, $spinner) {
			// Disable button
			$btn.prop('disabled', true).text('Importing...');
			$spinner.addClass('is-active');
			
			// Show processing message
			$results.html('<p>Processing import...</p>').show();
			
			$.ajax({
				url: wpMcpAiResearchPage.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_import_task',
					nonce: wpMcpAiResearchPage.nonce,
					import_data: importData,
					format: format
				},
				success: function(response) {
					if (response.success) {
						$results.html(
							'<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
						);
						
						// Clear form
						$('#wp-mcp-ai-import-text').val('');
						$('#wp-mcp-ai-import-file-input').val('');
						$('.selected-file-name').text('').hide();
					} else {
						$results.html(
							'<div class="notice notice-error"><p>' + (response.data.message || 'Import failed.') + '</p></div>'
						);
					}
				},
				error: function(xhr, status, error) {
					$results.html(
						'<div class="notice notice-error"><p>Import failed: ' + error + '</p></div>'
					);
				},
				complete: function() {
					$btn.prop('disabled', false).text('Import & Process');
					$spinner.removeClass('is-active');
				}
			});
		}

		// Handle refresh button in review tab
		$('.refresh-quality-data').on('click', function(e) {
			e.preventDefault();
			refreshReviewData();
		});
		
		// Handle consolidation action buttons
		$('#find-duplicate-tasks, #organize-by-priority, #group-by-project').on('click', function(e) {
			e.preventDefault();
			
			const $btn = $(this);
			const action = $btn.attr('id');
			const $results = $('#consolidation-results');
			
			// Disable button
			$btn.prop('disabled', true);
			const originalText = $btn.text();
			$btn.text('Processing...');
			
			// Show processing message
			$results.html('<p>AI is analyzing tasks...</p>').show();
			
			$.ajax({
				url: wpMcpAiResearchPage.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_consolidate_tasks',
					nonce: wpMcpAiResearchPage.nonce,
					consolidation_action: CONSOLIDATION_ACTION_MAP[action] || action,
					entity_type: wpMcpAiResearchPage.entityType
				},
				success: function(response) {
					if (response.success) {
						let html = '<div class="notice notice-success"><p>' + response.data.message + '</p></div>';
						
						if (response.data.results && response.data.results.length > 0) {
							html += '<div class="consolidation-results-list"><h4>Results:</h4><ul>';
							response.data.results.forEach(function(result) {
								html += '<li>' + result + '</li>';
							});
							html += '</ul></div>';
						}
						
						$results.html(html);
					} else {
						$results.html(
							'<div class="notice notice-error"><p>' + (response.data.message || 'Action failed.') + '</p></div>'
						);
					}
				},
				error: function(xhr, status, error) {
					$results.html(
						'<div class="notice notice-error"><p>Action failed: ' + error + '</p></div>'
					);
				},
				complete: function() {
					$btn.prop('disabled', false).text(originalText);
				}
			});
		});
	}

	/**
	 * Initialize the correct active tab based on URL parameter.
	 */
	function initializeActiveTab() {
		// Get mode from URL
		const urlParams = new URLSearchParams(window.location.search);
		const mode = urlParams.get('mode') || 'chat';
		
		// Activate the correct tab
		$('.mode-tab').removeClass('active');
		$('.mode-tab[href*="mode=' + mode + '"]').addClass('active');
		
		// Show the correct content
		$('.research-mode-content').removeClass('active').hide();
		
		const contentId = MODE_TO_CONTENT_ID[mode] || 'research-mode';
		$('#' + contentId).addClass('active').show();
	}

	/**
	 * Refresh review/consolidation data.
	 */
	function refreshReviewData() {
		const $dashboard = $('.quality-dashboard');
		
		// Show loading state
		$dashboard.css('opacity', '0.5');
		
		$.ajax({
			url: wpMcpAiResearchPage.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wp_mcp_ai_get_quality_data',
				nonce: wpMcpAiResearchPage.nonce,
				entity_type: wpMcpAiResearchPage.entityType
			},
			success: function(response) {
				if (response.success && response.data.html) {
					$dashboard.html(response.data.html);
				}
			},
			complete: function() {
				$dashboard.css('opacity', '1');
			}
		});
	}

	/**
	 * Initialize Excel preview functionality for registration products.
	 */
	function initExcelPreview() {
		// Handle "Preview Excel File" button click
		$(document).on('click', '.wp-mcp-ai-select-excel-file', function(e) {
			e.preventDefault();
			
			// Open WordPress media library
			if (typeof wp !== 'undefined' && wp.media) {
				const mediaFrame = wp.media({
					title: 'Select Excel File to Preview',
					button: {
						text: 'Preview File'
					},
					library: {
						type: ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv']
					},
					multiple: false
				});

				mediaFrame.on('select', function() {
					const attachment = mediaFrame.state().get('selection').first().toJSON();
					previewExcelFile(attachment);
				});

				mediaFrame.open();
			}
		});

		// Handle close preview button
		$(document).on('click', '.wp-mcp-ai-close-preview', function(e) {
			e.preventDefault();
			$('#wp-mcp-ai-product-preview').fadeOut();
		});

		// Handle preview pagination
		$(document).on('click', '.wp-mcp-ai-preview-prev, .wp-mcp-ai-preview-next', function(e) {
			e.preventDefault();
			const $btn = $(this);
			const isNext = $btn.hasClass('wp-mcp-ai-preview-next');
			const $preview = $('#wp-mcp-ai-product-preview');
			const currentPage = parseInt($preview.data('current-page') || 1);
			const totalPages = parseInt($preview.data('total-pages') || 1);
			
			let newPage = currentPage;
			if (isNext && currentPage < totalPages) {
				newPage = currentPage + 1;
			} else if (!isNext && currentPage > 1) {
				newPage = currentPage - 1;
			}
			
			if (newPage !== currentPage) {
				displayExcelPage(newPage);
			}
		});

		// Handle import Excel data button
		$(document).on('click', '.wp-mcp-ai-import-excel-data', function(e) {
			e.preventDefault();
			
			const $btn = $(this);
			const $preview = $('#wp-mcp-ai-product-preview');
			const fileUrl = $preview.data('file-url');
			
			if (!fileUrl) {
				alert('No file selected for import.');
				return;
			}
			
			// Disable button
			$btn.prop('disabled', true);
			const originalText = $btn.text();
			$btn.text('Importing...');
			
			// Call import via assistant chat
			const $chatInput = $('.wp-mcp-ai-chat-input, #wp-mcp-ai-message-input, textarea[name="message"]').first();
			if ($chatInput.length) {
				const importMessage = 'Import all products from the Excel file at: ' + fileUrl;
				$chatInput.val(importMessage);
				
				// Auto-submit
				const $submitBtn = $chatInput.closest('form').find('.wp-mcp-ai-send-button, button[type="submit"]').first();
				if ($submitBtn.length && $submitBtn.is(':visible')) {
					setTimeout(function() {
						$submitBtn.trigger('click');
						$btn.prop('disabled', false).text(originalText);
						$('#wp-mcp-ai-product-preview').fadeOut();
					}, 100);
				}
			}
		});
	}

	/**
	 * Preview an Excel file from the media library.
	 *
	 * @param {Object} attachment WordPress media attachment object.
	 */
	function previewExcelFile(attachment) {
		const $preview = $('#wp-mcp-ai-product-preview');
		const $loading = $preview.find('.wp-mcp-ai-preview-loading');
		const $data = $preview.find('.wp-mcp-ai-preview-data');
		
		// Show preview container
		$preview.show();
		$loading.show();
		$data.hide();
		
		// Store file info
		$preview.data('file-url', attachment.url);
		$preview.data('file-id', attachment.id);
		
		// Request preview data
		$.ajax({
			url: wpMcpAiResearchPage.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wp_mcp_ai_preview_excel',
				nonce: wpMcpAiResearchPage.nonce,
				file_id: attachment.id,
				file_url: attachment.url
			},
			success: function(response) {
				if (response.success && response.data) {
					displayExcelPreview(response.data);
				} else {
					$loading.html('<div class="notice notice-error"><p>' + escapeHtml(response.data?.message || 'Failed to preview file.') + '</p></div>');
				}
			},
			error: function(xhr, status, error) {
				$loading.html('<div class="notice notice-error"><p>Error loading preview: ' + escapeHtml(error) + '</p></div>');
			}
		});
	}

	/**
	 * Display Excel preview data.
	 *
	 * @param {Object} data Preview data from server.
	 */
	function displayExcelPreview(data) {
		const $preview = $('#wp-mcp-ai-product-preview');
		const $loading = $preview.find('.wp-mcp-ai-preview-loading');
		const $data = $preview.find('.wp-mcp-ai-preview-data');
		
		// Store data
		$preview.data('preview-data', data);
		$preview.data('current-page', 1);
		$preview.data('total-pages', Math.ceil((data.rows?.length || 0) / 10));
		
		// Update header
		$data.find('.wp-mcp-ai-preview-title').text(data.filename || 'Excel File');
		$data.find('.wp-mcp-ai-preview-meta').text(
			(data.rows?.length || 0) + ' rows, ' + (data.columns?.length || 0) + ' columns'
		);
		
		// Display first page
		displayExcelPage(1);
		
		// Show data, hide loading
		$loading.hide();
		$data.show();
	}

	/**
	 * Display a specific page of Excel data.
	 *
	 * @param {number} page Page number (1-indexed).
	 */
	function displayExcelPage(page) {
		const $preview = $('#wp-mcp-ai-product-preview');
		const data = $preview.data('preview-data');
		
		if (!data || !data.rows || !data.columns) {
			return;
		}
		
		const rowsPerPage = 10;
		const startIdx = (page - 1) * rowsPerPage;
		const endIdx = Math.min(startIdx + rowsPerPage, data.rows.length);
		const pageRows = data.rows.slice(startIdx, endIdx);
		
		// Build table
		const $table = $preview.find('.wp-mcp-ai-preview-table');
		const $thead = $table.find('thead');
		const $tbody = $table.find('tbody');
		
		// Clear table
		$thead.empty();
		$tbody.empty();
		
		// Add header
		let headerHtml = '<tr>';
		data.columns.forEach(function(col) {
			headerHtml += '<th>' + escapeHtml(col) + '</th>';
		});
		headerHtml += '</tr>';
		$thead.html(headerHtml);
		
		// Add rows
		pageRows.forEach(function(row) {
			let rowHtml = '<tr>';
			data.columns.forEach(function(col) {
				rowHtml += '<td>' + escapeHtml(row[col] || '') + '</td>';
			});
			rowHtml += '</tr>';
			$tbody.append(rowHtml);
		});
		
		// Update pagination
		const totalPages = Math.ceil(data.rows.length / rowsPerPage);
		$preview.data('current-page', page);
		$preview.data('total-pages', totalPages);
		
		$preview.find('.wp-mcp-ai-preview-current-page').text(page);
		$preview.find('.wp-mcp-ai-preview-total-pages').text(totalPages);
		
		$preview.find('.wp-mcp-ai-preview-prev').prop('disabled', page <= 1);
		$preview.find('.wp-mcp-ai-preview-next').prop('disabled', page >= totalPages);
		
		if (totalPages > 1) {
			$preview.find('.wp-mcp-ai-preview-pagination').show();
		} else {
			$preview.find('.wp-mcp-ai-preview-pagination').hide();
		}
	}

	/**
	 * Escape HTML to prevent XSS.
	 *
	 * @param {string} str String to escape.
	 * @return {string} Escaped string.
	 */
	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	// Initialize on document ready
	$(document).ready(function() {
		initEnhancedResearchPage();
		initExcelPreview();
	});

})(jQuery);
