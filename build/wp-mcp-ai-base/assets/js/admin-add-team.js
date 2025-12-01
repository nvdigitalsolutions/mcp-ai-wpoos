/**
 * Add Team Page JavaScript
 */
(function($) {
	'use strict';

	var modal = null;

	$(document).ready(function() {
		modal = $('#wp-mcp-ai-deploy-results-modal');

		// Handle deploy team button click
		$('.wp-mcp-ai-deploy-team').on('click', function(e) {
			e.preventDefault();

			var button = $(this);
			var card = button.closest('.wp-mcp-ai-team-card');
			var teamId = button.data('team-id');
			var originalButtonText = button.text();

			// Confirm deployment
			var membersCount = button.text().match(/\d+/)[0];
			var confirmMessage = membersCount == 1 
				? 'Are you sure you want to deploy this team? 1 assistant will be created.'
				: 'Are you sure you want to deploy this team? ' + membersCount + ' assistants will be created.';
			
			if (!confirm(confirmMessage)) {
				return;
			}

			// Disable button and show loading
			button.prop('disabled', true).text(wpMcpAiAddTeam.strings.deploying);
			card.addClass('loading');

			// Submit via AJAX
			$.ajax({
				url: wpMcpAiAddTeam.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_deploy_team',
					nonce: wpMcpAiAddTeam.nonce,
					team_id: teamId
				},
				success: function(response) {
					card.removeClass('loading');
					button.prop('disabled', false).text(originalButtonText);

					if (response.success) {
						showResults(response.data);
					} else {
						alert(response.data.message || wpMcpAiAddTeam.strings.error);
					}
				},
				error: function() {
					card.removeClass('loading');
					button.prop('disabled', false).text(originalButtonText);
					alert(wpMcpAiAddTeam.strings.error);
				}
			});
		});

		// Close modal
		$('.wp-mcp-ai-modal-close, .wp-mcp-ai-modal-overlay').on('click', function() {
			modal.fadeOut(200);
		});
	});

	function showResults(data) {
		var html = '';

		// Success message
		if (data.message) {
			html += '<div class="success-message">' + escapeHtml(data.message) + '</div>';
		}

		// List of created assistants
		if (data.assistants && data.assistants.length > 0) {
			html += '<h3>Created Assistants:</h3>';
			html += '<ul class="assistants-list">';
			data.assistants.forEach(function(assistant) {
				html += '<li>';
				html += '<span>' + escapeHtml(assistant.title) + '</span>';
				html += '<a href="' + escapeHtml(assistant.url) + '" class="button button-small">';
				html += 'Edit';
				html += '</a>';
				html += '</li>';
			});
			html += '</ul>';
		}

		// Errors
		if (data.errors && data.errors.length > 0) {
			html += '<h3>Errors:</h3>';
			data.errors.forEach(function(error) {
				html += '<div class="error-message">' + escapeHtml(error) + '</div>';
			});
		}

		$('#deploy-results-content').html(html);
		modal.fadeIn(200);
	}

	function escapeHtml(text) {
		var map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return text.replace(/[&<>"']/g, function(m) { return map[m]; });
	}

})(jQuery);
