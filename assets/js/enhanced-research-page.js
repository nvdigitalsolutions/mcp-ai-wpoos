/**
 * Enhanced Research Page JavaScript
 *
 * Handles workflow tab switching and interactions for enhanced research pages.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

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
			const urlParams = new URLSearchParams(href.split('?')[1]);
			const mode = urlParams.get('mode') || 'chat';
			
			// Update active state on tabs
			$('.mode-tab').removeClass('active');
			$(this).addClass('active');
			
			// Show corresponding content
			$('.research-mode-content').removeClass('active').hide();
			
			// Map mode to content ID
			const modeToId = {
				'chat': 'research-mode',
				'import': 'import-mode',
				'consolidate': 'consolidate-mode'
			};
			
			const contentId = modeToId[mode] || 'research-mode';
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
				$('.import-file-selected').text(files.length + ' file(s) selected').show();
			} else {
				$('.import-file-selected').text('').hide();
			}
		});

		// Handle import form submission
		$('#wp-mcp-ai-import-form').on('submit', function(e) {
			e.preventDefault();
			
			const $form = $(this);
			const $submitBtn = $form.find('button[type="submit"]');
			const formData = new FormData(this);
			
			// Disable submit button
			$submitBtn.prop('disabled', true).text('Importing...');
			
			// Show loading indicator
			$('.import-result').html('<p>Processing import...</p>').show();
			
			$.ajax({
				url: wpMcpAiResearchPage.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					if (response.success) {
						$('.import-result').html(
							'<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
						);
						
						// Clear form
						$form[0].reset();
						$('.import-file-selected').text('').hide();
						
						// Refresh the review tab if available
						refreshReviewData();
					} else {
						$('.import-result').html(
							'<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
						);
					}
				},
				error: function() {
					$('.import-result').html(
						'<div class="notice notice-error"><p>Import failed. Please try again.</p></div>'
					);
				},
				complete: function() {
					$submitBtn.prop('disabled', false).text('Import & Process');
				}
			});
		});

		// Handle refresh button in review tab
		$('.refresh-quality-data').on('click', function(e) {
			e.preventDefault();
			refreshReviewData();
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
		
		const modeToId = {
			'chat': 'research-mode',
			'import': 'import-mode',
			'consolidate': 'consolidate-mode'
		};
		
		const contentId = modeToId[mode] || 'research-mode';
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
