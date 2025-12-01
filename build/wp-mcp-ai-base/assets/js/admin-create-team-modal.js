/**
 * Create Team Modal JavaScript
 */
(function($) {
	'use strict';

	let modal = null;

	$(document).ready(function() {
		modal = $('#wp-mcp-ai-create-team-modal');

		// Open modal when create team button is clicked
		$(document).on('click', '#wp-mcp-ai-open-create-team-modal', function(e) {
			e.preventDefault();
			modal.fadeIn(200);
			$('#team-title').focus();
		});

		// Close modal
		$('.wp-mcp-ai-modal-close, .wp-mcp-ai-modal-overlay').on('click', function() {
			closeModal();
		});

		// Close on escape key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && modal.is(':visible')) {
				closeModal();
			}
		});

		// Handle form submission
		$('#wp-mcp-ai-create-team-form').on('submit', function(e) {
			e.preventDefault();

			const submitButton = $('#wp-mcp-ai-submit-create-team');
			const originalButtonText = submitButton.text();

			// Validate
			const title = $('#team-title').val().trim();
			const professions = $('#team-professions').val() || [];
			
			if (!title) {
				alert(wpMcpAiCreateTeam.strings.required);
				$('#team-title').focus();
				return;
			}

			if (professions.length < 2) {
				alert(wpMcpAiCreateTeam.strings.minProfessions);
				$('#team-professions').focus();
				return;
			}

			// Disable button and show loading
			submitButton.prop('disabled', true).text(wpMcpAiCreateTeam.strings.creating);

			// Submit via AJAX
			$.ajax({
				url: wpMcpAiCreateTeam.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_create_team_from_modal',
					nonce: wpMcpAiCreateTeam.nonce,
					title: title,
					professions: professions,
					description: $('#team-description').val(),
					provider: $('#team-provider').val(),
					model: $('#team-model').val(),
					temperature: $('#team-temperature').val()
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						alert(wpMcpAiCreateTeam.strings.success + '\n\n' + response.data.message);
						
						// Redirect to edit page
						if (response.data.edit_url) {
							window.location.href = response.data.edit_url;
						} else {
							// Reload page to show updated list
							window.location.reload();
						}
					} else {
						alert(response.data.message || wpMcpAiCreateTeam.strings.error);
						submitButton.prop('disabled', false).text(originalButtonText);
					}
				},
				error: function() {
					alert(wpMcpAiCreateTeam.strings.error);
					submitButton.prop('disabled', false).text(originalButtonText);
				}
			});
		});
	});

	function closeModal() {
		modal.fadeOut(200);
		resetForm();
	}

	function resetForm() {
		$('#wp-mcp-ai-create-team-form')[0].reset();
	}

})(jQuery);
