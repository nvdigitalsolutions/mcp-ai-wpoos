/**
 * Enhanced Research Page JavaScript
 *
 * Handles workflow tab switching and interactions for enhanced research pages.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	// Mode to content ID mapping (shared constant)
	const MODE_TO_CONTENT_ID = {
		'chat': 'research-mode',
		'import': 'import-mode',
		'consolidate': 'consolidate-mode'
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
		$('#import-file').on('change', function() {
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
			const $spinner = $('.wp-mcp-ai-import-actions .spinner');
			
			// Get import data from textarea or file
			let importData = $('#import-data-paste').val();
			const fileInput = document.getElementById('import-file');
			
			// Determine format
			let format = 'csv';
			
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
			
			// Try to detect format from content
			try {
				JSON.parse(importData);
				format = 'json';
			} catch (e) {
				format = 'csv';
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
						$('#import-data-paste').val('');
						$('#import-file').val('');
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
			
			// Map button IDs to action names
			const actionMap = {
				'find-duplicate-tasks': 'find_duplicates',
				'organize-by-priority': 'organize_priority',
				'group-by-project': 'suggest_grouping'
			};
			
			$.ajax({
				url: wpMcpAiResearchPage.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_consolidate_tasks',
					nonce: wpMcpAiResearchPage.nonce,
					consolidation_action: actionMap[action] || action,
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

	// Initialize on document ready
	$(document).ready(function() {
		initEnhancedResearchPage();
	});

})(jQuery);
