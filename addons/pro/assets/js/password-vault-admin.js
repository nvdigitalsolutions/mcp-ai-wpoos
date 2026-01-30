/**
 * Password Vault Admin JavaScript
 *
 * Handles password generation, TOTP, clipboard operations, and form interactions.
 *
 * @package WP_MCP_AI_Pro
 */

(function($) {
	'use strict';

	/**
	 * Initialize Password Vault Admin functionality.
	 */
	$(document).ready(function() {
		initPasswordGenerator();
		initTOTPGenerator();
		initClipboardButtons();
	});

	/**
	 * Initialize password generator form.
	 */
	function initPasswordGenerator() {
		const $form = $('#password-generator-form');
		if (!$form.length) {
			return;
		}

		$form.on('submit', function(e) {
			e.preventDefault();

			const formData = {
				action: 'vault_generate_password',
				_wpnonce: $form.find('[name="_wpnonce"]').val(),
				length: $('#password_length').val(),
				uppercase: $form.find('[name="uppercase"]').is(':checked') ? 1 : 0,
				lowercase: $form.find('[name="lowercase"]').is(':checked') ? 1 : 0,
				numbers: $form.find('[name="numbers"]').is(':checked') ? 1 : 0,
				symbols: $form.find('[name="symbols"]').is(':checked') ? 1 : 0,
				avoid_ambiguous: $form.find('[name="avoid_ambiguous"]').is(':checked') ? 1 : 0
			};

			// Submit via admin-ajax.php
			$.ajax({
				url: wpMcpAiVault.ajax_url,
				method: 'POST',
				data: formData,
				beforeSend: function() {
					$form.find('button[type="submit"]').prop('disabled', true).addClass('disabled');
				},
				success: function(response) {
					if (response.success && response.data) {
						displayGeneratedPassword(response.data.password, response.data.strength);
					} else {
						alert(wpMcpAiVault.strings.copy_failed || 'Failed to generate password.');
					}
				},
				error: function() {
					alert('An error occurred while generating the password.');
				},
				complete: function() {
					$form.find('button[type="submit"]').prop('disabled', false).removeClass('disabled');
				}
			});
		});
	}

	/**
	 * Display generated password with strength indicator.
	 *
	 * @param {string} password The generated password.
	 * @param {Object} strength Strength data with score and label.
	 */
	function displayGeneratedPassword(password, strength) {
		const $result = $('#password-result');
		const $passwordInput = $('#generated-password');
		const $strengthIndicator = $('#strength-indicator');
		const $strengthText = $('#strength-text');

		// Set password
		$passwordInput.val(password);

		// Set strength
		let strengthClass = 'weak';
		if (strength.score >= 80) {
			strengthClass = 'strong';
		} else if (strength.score >= 60) {
			strengthClass = 'medium';
		}

		$strengthIndicator.attr('data-strength', strengthClass);
		$strengthText.text(strength.label).removeClass('weak medium strong').addClass(strengthClass);

		// Show result
		$result.slideDown();
	}

	/**
	 * Initialize TOTP generator form.
	 */
	function initTOTPGenerator() {
		const $form = $('#totp-generator-form');
		if (!$form.length) {
			return;
		}

		$form.on('submit', function(e) {
			e.preventDefault();

			const formData = {
				action: 'vault_generate_totp_secret',
				_wpnonce: $form.find('[name="_wpnonce"]').val()
			};

			$.ajax({
				url: wpMcpAiVault.ajax_url,
				method: 'POST',
				data: formData,
				beforeSend: function() {
					$form.find('button[type="submit"]').prop('disabled', true).addClass('disabled');
				},
				success: function(response) {
					if (response.success && response.data) {
						displayTOTPSecret(response.data.secret, response.data.qr_code_url);
					} else {
						alert('Failed to generate TOTP secret.');
					}
				},
				error: function() {
					alert('An error occurred while generating the TOTP secret.');
				},
				complete: function() {
					$form.find('button[type="submit"]').prop('disabled', false).removeClass('disabled');
				}
			});
		});

		// TOTP verification
		// TODO: Implement server-side TOTP verification endpoint for production use.
		// Current implementation is client-side simulation for UI demonstration only.
		$('#verify-totp-code').on('click', function() {
			const code = $('#totp-test-code').val();
			const secret = $('#totp-secret').text();

			if (!code || code.length !== 6) {
				alert('Please enter a 6-digit code.');
				return;
			}

			if (!secret) {
				alert('No TOTP secret available. Generate a secret first.');
				return;
			}

			// Show verification message
			$('#totp-verification-result')
				.text('TOTP verification requires server-side implementation.')
				.removeClass('success error')
				.css('color', '#646970');

			// TODO: Replace with actual AJAX call to server endpoint for TOTP verification
			// Example:
			// $.ajax({
			//     url: wpMcpAiVault.ajax_url,
			//     method: 'POST',
			//     data: {
			//         action: 'vault_verify_totp',
			//         _wpnonce: wpMcpAiVault.nonce,
			//         code: code,
			//         secret: secret
			//     },
			//     success: function(response) {
			//         if (response.success) {
			//             $('#totp-verification-result').text('✓ Code verified!').addClass('success').removeClass('error');
			//         } else {
			//             $('#totp-verification-result').text('✗ Invalid code').addClass('error').removeClass('success');
			//         }
			//     }
			// });
		});
	}

	/**
	 * Display TOTP secret with QR code.
	 *
	 * @param {string} secret The TOTP secret.
	 * @param {string} qrCodeUrl The QR code data URL or URL.
	 */
	function displayTOTPSecret(secret, qrCodeUrl) {
		const $result = $('#totp-result');
		const $secretElement = $('#totp-secret');
		const $qrContainer = $('#qr-code-container');

		// Set secret
		$secretElement.text(secret);

		// Set QR code
		if (qrCodeUrl) {
			if (qrCodeUrl.startsWith('data:') || qrCodeUrl.startsWith('http')) {
				$qrContainer.html('<img src="' + qrCodeUrl + '" alt="QR Code" style="max-width: 200px;" />');
			} else {
				// If it's a canvas or needs QR code generation library
				$qrContainer.html('<p>QR Code generation requires additional library</p>');
			}
		}

		// Show result
		$result.slideDown();
	}

	/**
	 * Initialize clipboard copy functionality.
	 */
	function initClipboardButtons() {
		// Copy generated password
		$('#copy-password').on('click', function() {
			const password = $('#generated-password').val();
			copyToClipboard(password, $(this));
		});

		// Copy TOTP secret
		$('#copy-totp-secret').on('click', function() {
			const secret = $('#totp-secret').text();
			copyToClipboard(secret, $(this));
		});
	}

	/**
	 * Copy text to clipboard.
	 *
	 * @param {string} text Text to copy.
	 * @param {jQuery} $button Button element to show feedback.
	 */
	function copyToClipboard(text, $button) {
		if (!text) {
			return;
		}

		// Modern clipboard API
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function() {
				showCopyFeedback($button, true);
			}).catch(function() {
				// Fallback to old method
				copyToClipboardFallback(text, $button);
			});
		} else {
			// Fallback for older browsers
			copyToClipboardFallback(text, $button);
		}
	}

	/**
	 * Fallback clipboard copy method.
	 *
	 * @param {string} text Text to copy.
	 * @param {jQuery} $button Button element to show feedback.
	 */
	function copyToClipboardFallback(text, $button) {
		const $temp = $('<textarea>');
		$temp.val(text);
		$temp.css({
			position: 'absolute',
			left: '-9999px',
			top: '0'
		});
		$('body').append($temp);
		$temp.select();

		let success = false;
		try {
			success = document.execCommand('copy');
		} catch (err) {
			success = false;
		}

		$temp.remove();
		showCopyFeedback($button, success);
	}

	/**
	 * Show copy feedback on button.
	 *
	 * @param {jQuery} $button Button element.
	 * @param {boolean} success Whether copy was successful.
	 */
	function showCopyFeedback($button, success) {
		const originalText = $button.html();
		const successMsg = wpMcpAiVault.strings.copy_success || 'Copied!';
		const failMsg = wpMcpAiVault.strings.copy_failed || 'Failed';

		if (success) {
			$button.html('<span class="dashicons dashicons-yes"></span> ' + successMsg);
			$button.addClass('button-primary');
		} else {
			$button.html('<span class="dashicons dashicons-no"></span> ' + failMsg);
		}

		setTimeout(function() {
			$button.html(originalText);
			$button.removeClass('button-primary');
		}, 2000);
	}

})(jQuery);
