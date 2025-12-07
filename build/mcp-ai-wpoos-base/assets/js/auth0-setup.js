/**
 * Auth0 1-Click Setup Wizard JS
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		const $autoConfigureBtn = $('#auto-configure-btn');
		const $tokenField = $('#auth0-token');
		const $resultDiv = $('#auto-configure-result');
		const $spinner = $autoConfigureBtn.next('.spinner');
		const $currentDomain = $('#current-domain');
		const $currentAudience = $('#current-audience');
		const $auth0DashboardLink = $('#open-auth0-dashboard');
		const $bridgeCheckbox = $('#enable-auth0-github-bridge');

		// Handle checkbox toggle
		$bridgeCheckbox.on('change', function() {
			const enabled = $(this).is(':checked');

			// Make AJAX request to save the setting
			$.ajax({
				url: wpMcpAiAuth0Setup.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_toggle_auth0_bridge',
					nonce: wpMcpAiAuth0Setup.nonce,
					enabled: enabled ? '1' : '0'
				},
				success: function(response) {
					if (response.success) {
						// Show a brief success message
						const $notice = $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
						$('.wp-mcp-ai-setup-wizard').prepend($notice);
						setTimeout(function() {
							$notice.fadeOut(function() {
								$(this).remove();
							});
						}, 3000);
					} else {
						// Revert checkbox on error
						$bridgeCheckbox.prop('checked', !enabled);
						alert(response.data.message || 'Failed to update setting.');
					}
				},
				error: function() {
					// Revert checkbox on error
					$bridgeCheckbox.prop('checked', !enabled);
					alert('Failed to update setting. Please try again.');
				}
			});
		});

		// Auto-configure from token
		$autoConfigureBtn.on('click', function(e) {
			e.preventDefault();

			const token = $tokenField.val().trim();

			if (!token) {
				showResult('error', 'Please paste an Auth0 bearer token.');
				return;
			}

			// Show loading state
			$autoConfigureBtn.prop('disabled', true);
			$spinner.addClass('is-active');
			$resultDiv.hide();

			// Make AJAX request
			$.ajax({
				url: wpMcpAiAuth0Setup.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_auto_configure_auth0',
					nonce: wpMcpAiAuth0Setup.nonce,
					token: token
				},
				success: function(response) {
					if (response.success) {
						// Update current domain/audience display
						if (response.data.domain) {
							$currentDomain.text(response.data.domain);
						}
						if (response.data.audience) {
							$currentAudience.text(response.data.audience);
						}

						// Update Auth0 dashboard link
						if (response.data.domain) {
							const dashboardUrl = 'https://' + response.data.domain + '/dashboard';
							$auth0DashboardLink.attr('href', dashboardUrl);
						}

						showResult('success', response.data.message);

						// Clear token field for security
						$tokenField.val('');
					} else {
						showResult('error', response.data.message || 'An error occurred.');
					}
				},
				error: function(xhr, status, error) {
					showResult('error', 'AJAX error: ' + error);
				},
				complete: function() {
					$autoConfigureBtn.prop('disabled', false);
					$spinner.removeClass('is-active');
				}
			});
		});

		// Helper function to show result message
		function showResult(type, message) {
			$resultDiv
				.removeClass('success error')
				.addClass(type)
				.html('<strong>' + (type === 'success' ? 'Success!' : 'Error:') + '</strong> ' + message)
				.slideDown();
		}

		// Clear result when token field changes
		$tokenField.on('input', function() {
			$resultDiv.slideUp();
		});
	});
})(jQuery);
