/**
 * Create Assistant Modal JavaScript
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		var modal = $('#wp-mcp-ai-create-assistant-modal');
		var form = $('#wp-mcp-ai-create-assistant-form');

		// Open modal
		$(document).on('click', '#wp-mcp-ai-open-create-modal', function(e) {
			e.preventDefault();
			modal.fadeIn(200);
		});

		// Close modal
		$(document).on('click', '.wp-mcp-ai-modal-close, .wp-mcp-ai-modal-overlay', function(e) {
			e.preventDefault();
			modal.fadeOut(200);
		});

		// Prevent modal content clicks from closing
		$(document).on('click', '.wp-mcp-ai-modal-content', function(e) {
			e.stopPropagation();
		});

		// Close on ESC key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && modal.is(':visible')) {
				modal.fadeOut(200);
			}
		});

		// Handle form submission
		form.on('submit', function(e) {
			e.preventDefault();

			// Clear previous messages
			$('.wp-mcp-ai-error-message, .wp-mcp-ai-success-message').remove();

			// Validate professions (max 3)
			var professions = $('#assistant-professions').val();
			if (!professions || professions.length === 0) {
				showError(wpMcpAiCreateAssistant.strings.required);
				return;
			}
			if (professions.length > 3) {
				showError(wpMcpAiCreateAssistant.strings.maxProfessions);
				return;
			}

			// Validate regions (max 2)
			var regions = $('#assistant-regions').val();
			if (!regions || regions.length === 0) {
				showError(wpMcpAiCreateAssistant.strings.required);
				return;
			}
			if (regions.length > 2) {
				showError(wpMcpAiCreateAssistant.strings.maxRegions);
				return;
			}

			// Show loading state
			modal.addClass('loading');
			$('#wp-mcp-ai-submit-create').prop('disabled', true).text(wpMcpAiCreateAssistant.strings.creating);

			// Prepare form data
			var formData = {
				action: 'wp_mcp_ai_create_assistant_from_modal',
				nonce: wpMcpAiCreateAssistant.nonce,
				title: $('#assistant-title').val(),
				professions: professions,
				regions: regions,
				industry_focus: $('#assistant-industry').val(),
				provider: $('#assistant-provider').val(),
				model: $('#assistant-model').val(),
				temperature: $('#assistant-temperature').val(),
				async: $('#assistant-async').is(':checked') ? '1' : '0'
			};

			// Send AJAX request
			$.ajax({
				url: wpMcpAiCreateAssistant.ajaxUrl,
				type: 'POST',
				data: formData,
				success: function(response) {
					modal.removeClass('loading');
					$('#wp-mcp-ai-submit-create').prop('disabled', false).text('Create Assistant');

					if (response.success) {
						showSuccess(response.data.message || wpMcpAiCreateAssistant.strings.success);
						
						// Redirect to edit page if assistant was created synchronously
						if (response.data.assistant_id) {
							setTimeout(function() {
								window.location.href = response.data.edit_link || ('post.php?post=' + response.data.assistant_id + '&action=edit');
							}, 1000);
						} else if (response.data.status === 'scheduled') {
							// For async creation, just show success and close modal
							setTimeout(function() {
								modal.fadeOut(200);
								form[0].reset();
								location.reload();
							}, 2000);
						}
					} else {
						showError(response.data.message || wpMcpAiCreateAssistant.strings.error);
					}
				},
				error: function(xhr, status, error) {
					modal.removeClass('loading');
					$('#wp-mcp-ai-submit-create').prop('disabled', false).text('Create Assistant');
					showError(wpMcpAiCreateAssistant.strings.error + ' (' + error + ')');
				}
			});
		});

		function showError(message) {
			var errorHtml = '<div class="wp-mcp-ai-error-message">' + message + '</div>';
			$('.wp-mcp-ai-modal-body').prepend(errorHtml);
			// Scroll to top of modal body
			$('.wp-mcp-ai-modal-body').scrollTop(0);
		}

		function showSuccess(message) {
			var successHtml = '<div class="wp-mcp-ai-success-message">' + message + '</div>';
			$('.wp-mcp-ai-modal-body').prepend(successHtml);
			// Scroll to top of modal body
			$('.wp-mcp-ai-modal-body').scrollTop(0);
		}
	});

})(jQuery);
