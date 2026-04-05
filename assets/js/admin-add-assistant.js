/**
 * Add Assistant Page JavaScript
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
(function($) {
	'use strict';

	let modal = null;
	let currentProfessionId = null;

	$(document).ready(function() {
		modal = $('#wp-mcp-ai-create-modal');

		// Open modal when create button is clicked
		$('.wp-mcp-ai-create-assistant').on('click', function(e) {
			e.preventDefault();
			currentProfessionId = $(this).data('profession-id');
			$('#profession-id').val(currentProfessionId);
			
			// Get the profession title to pre-fill the assistant title
			const card = $(this).closest('.wp-mcp-ai-professional-card');
			const professionTitle = card.find('h3').text();
			$('#assistant-title').val('');
			$('#assistant-title').attr('placeholder', professionTitle);
			
			modal.fadeIn(200);
		});

		// Close modal
		$('.wp-mcp-ai-modal-close, .wp-mcp-ai-modal-overlay').on('click', function() {
			modal.fadeOut(200);
			resetForm();
		});

		// Handle form submission
		$('#wp-mcp-ai-create-form').on('submit', function(e) {
			e.preventDefault();

			const _form = $(this);
			const submitButton = $('#wp-mcp-ai-submit-create');
			const originalButtonText = submitButton.text();

			// Validate
			const title = $('#assistant-title').val().trim();
			if (!title) {
				alert(wpMcpAiAddAssistant.strings.error);
				return;
			}

			// Disable button and show loading
			submitButton.prop('disabled', true).text(wpMcpAiAddAssistant.strings.creating);

			// Submit via AJAX
			$.ajax({
				url: wpMcpAiAddAssistant.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_create_from_professional',
					nonce: wpMcpAiAddAssistant.nonce,
					profession_id: $('#profession-id').val(),
					title: title,
					provider: $('#assistant-provider').val(),
					model: $('#assistant-model').val()
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						alert(wpMcpAiAddAssistant.strings.success);
						
						// Redirect to edit page
						if (response.data.edit_url) {
							window.location.href = response.data.edit_url;
						} else {
							// Reload page to show updated list
							window.location.reload();
						}
					} else {
						alert(response.data.message || wpMcpAiAddAssistant.strings.error);
						submitButton.prop('disabled', false).text(originalButtonText);
					}
				},
				error: function() {
					alert(wpMcpAiAddAssistant.strings.error);
					submitButton.prop('disabled', false).text(originalButtonText);
				}
			});
		});
	});

	function resetForm() {
		$('#wp-mcp-ai-create-form')[0].reset();
		currentProfessionId = null;
	}

})(jQuery);
