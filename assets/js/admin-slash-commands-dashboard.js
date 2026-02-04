/**
 * Slash Commands Dashboard JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.10.0
 */

(function($) {
	'use strict';

	/**
	 * Slash Commands Dashboard Handler
	 */
	class SlashCommandsDashboard {
		constructor() {
			this.init();
		}

		init() {
			// Commands tab.
			this.initCommandsTab();

			// Workflows tab.
			this.initWorkflowsTab();

			// History tab.
			this.initHistoryTab();

			// Test tab.
			this.initTestTab();
		}

		/**
		 * Initialize commands tab.
		 */
		initCommandsTab() {
			// View command help.
			$(document).on('click', '.view-command-help', (e) => {
				const command = $(e.currentTarget).data('command');
				this.viewCommandHelp(command);
			});

			// Close help.
			$(document).on('click', '.close-help', () => {
				$('#command-help-display').hide();
			});
		}

		/**
		 * Initialize workflows tab.
		 */
		initWorkflowsTab() {
			// View workflow details.
			$(document).on('click', '.view-workflow', (e) => {
				const workflow = $(e.currentTarget).data('workflow');
				this.viewWorkflowDetails(workflow);
			});

			// Execute workflow.
			$(document).on('click', '.execute-workflow', (e) => {
				const workflow = $(e.currentTarget).data('workflow');
				this.executeWorkflow(workflow);
			});

			// Close details.
			$(document).on('click', '.close-details', () => {
				$('#workflow-details-display').hide();
			});

			// Close execution output.
			$(document).on('click', '.close-execution', () => {
				$('#workflow-execution-output').hide();
			});
		}

		/**
		 * Initialize history tab.
		 */
		initHistoryTab() {
			// Refresh history.
			$(document).on('click', '#refresh-history', () => {
				this.refreshHistory();
			});

			// Clear history.
			$(document).on('click', '#clear-history', () => {
				if (confirm('Are you sure you want to clear all execution history?')) {
					this.clearHistory();
				}
			});

			// View history details.
			$(document).on('click', '.view-history-details', (e) => {
				const entryId = $(e.currentTarget).data('entry-id');
				this.viewHistoryDetails(entryId);
			});

			// Close history details.
			$(document).on('click', '.close-history-details', () => {
				$('#history-details-display').hide();
			});
		}

		/**
		 * Initialize test tab.
		 */
		initTestTab() {
			// Execute command on button click.
			$(document).on('click', '#execute-command-btn', () => {
				const command = $('#command-input').val().trim();
				if (command) {
					this.executeCommand(command);
				}
			});

			// Execute command on Enter key.
			$(document).on('keypress', '#command-input', (e) => {
				if (e.which === 13) { // Enter key
					const command = $('#command-input').val().trim();
					if (command) {
						this.executeCommand(command);
					}
				}
			});
		}

		/**
		 * View command help.
		 */
		viewCommandHelp(command) {
			$('#command-help-display').show();
			$('#command-help-content').html('<p>Loading...</p>');

			// Execute /help command.
			this.sendCommandRequest('/help ' + command, (response) => {
				if (response.success) {
					$('#command-help-content').html('<pre>' + this.escapeHtml(response.data.output) + '</pre>');
				} else {
					$('#command-help-content').html('<p style="color: red;">Error: ' + this.escapeHtml(response.data.message) + '</p>');
				}
			});
		}

		/**
		 * View workflow details.
		 */
		viewWorkflowDetails(workflow) {
			$('#workflow-details-display').show();
			$('#workflow-details-content').html('<p>Loading...</p>');

			// Execute /workflow --show command.
			this.sendCommandRequest('/workflow ' + workflow + ' --show', (response) => {
				if (response.success) {
					$('#workflow-details-content').html('<pre>' + this.escapeHtml(response.data.output) + '</pre>');
				} else {
					const errorMessage = response.data && response.data.message ? response.data.message : 'Unknown error occurred';
					$('#workflow-details-content').html('<p style="color: red;">Error: ' + this.escapeHtml(errorMessage) + '</p>');
				}
			});
		}

		/**
		 * Execute workflow.
		 */
		executeWorkflow(workflow) {
			$('#workflow-execution-output').show().addClass('executing');
			$('#workflow-execution-content').html('<p>Executing workflow...</p>');

			$.ajax({
				url: wpMcpAiSlashCommands.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_execute_workflow',
					nonce: wpMcpAiSlashCommands.nonce,
					workflow: workflow
				},
				success: (response) => {
					$('#workflow-execution-output').removeClass('executing');
					if (response.success) {
						$('#workflow-execution-content').html('<pre>' + this.escapeHtml(response.data.output) + '</pre>');
					} else {
						const errorMessage = response.data && response.data.message ? response.data.message : 'Unknown error occurred';
						$('#workflow-execution-content').html('<p style="color: red;">Error: ' + this.escapeHtml(errorMessage) + '</p>');
					}
				},
				error: (xhr, textStatus, errorThrown) => {
					$('#workflow-execution-output').removeClass('executing');
					let errorMessage = 'Request failed';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMessage = xhr.responseJSON.data.message;
					} else if (errorThrown && errorThrown !== '') {
						errorMessage = errorThrown;
					} else if (xhr.statusText && xhr.statusText !== 'error') {
						errorMessage = xhr.statusText;
					} else if (xhr.status) {
						errorMessage = 'HTTP ' + xhr.status + ' error';
					}
					$('#workflow-execution-content').html('<p style="color: red;">Error: ' + this.escapeHtml(errorMessage) + '</p>');
				}
			});
		}

		/**
		 * Execute command from test tab.
		 */
		executeCommand(command) {
			const $output = $('#command-output');
			$output.addClass('executing');
			$output.html('<p class="no-output">Executing command...</p>');

			this.sendCommandRequest(command, (response) => {
				$output.removeClass('executing success error');

				if (response.success) {
					$output.addClass('success');
					$output.text(response.data.output);
				} else {
					$output.addClass('error');
					const errorMessage = response.data && response.data.message ? response.data.message : 'Unknown error occurred';
					$output.text('Error: ' + errorMessage);
				}
			});
		}

		/**
		 * Send command request.
		 */
		sendCommandRequest(command, callback) {
			$.ajax({
				url: wpMcpAiSlashCommands.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_execute_command',
					nonce: wpMcpAiSlashCommands.nonce,
					command: command
				},
				success: callback,
				error: (xhr, textStatus, errorThrown) => {
					let errorMessage = 'Request failed';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMessage = xhr.responseJSON.data.message;
					} else if (errorThrown && errorThrown !== '') {
						errorMessage = errorThrown;
					} else if (xhr.statusText && xhr.statusText !== 'error') {
						errorMessage = xhr.statusText;
					} else if (xhr.status) {
						errorMessage = 'HTTP ' + xhr.status + ' error';
					}
					callback({
						success: false,
						data: {
							message: errorMessage
						}
					});
				}
			});
		}

		/**
		 * Refresh history.
		 */
		refreshHistory() {
			const $btn = $('#refresh-history');
			$btn.prop('disabled', true);

			$.ajax({
				url: wpMcpAiSlashCommands.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_command_history',
					nonce: wpMcpAiSlashCommands.nonce,
					limit: 50
				},
				success: (response) => {
					if (response.success) {
						this.updateHistoryTable(response.data.history);
					}
					$btn.prop('disabled', false);
				},
				error: () => {
					alert('Failed to refresh history.');
					$btn.prop('disabled', false);
				}
			});
		}

		/**
		 * Clear history.
		 */
		clearHistory() {
			const $btn = $('#clear-history');
			$btn.prop('disabled', true);

			$.ajax({
				url: wpMcpAiSlashCommands.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_clear_command_history',
					nonce: wpMcpAiSlashCommands.nonce
				},
				success: (response) => {
					if (response.success) {
						// Clear table.
						$('#history-table tbody').html('<tr><td colspan="6">No execution history available.</td></tr>');
					}
					$btn.prop('disabled', false);
				},
				error: () => {
					alert('Failed to clear history.');
					$btn.prop('disabled', false);
				}
			});
		}

		/**
		 * View history details.
		 */
		viewHistoryDetails(entryId) {
			$('#history-details-display').show();
			$('#history-details-content').html('<p>Loading...</p>');

			// Fetch entry details from server.
			$.ajax({
				url: wpMcpAiSlashCommands.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_history_entry',
					nonce: wpMcpAiSlashCommands.nonce,
					entry_id: entryId
				},
				success: (response) => {
					if (response.success && response.data.entry) {
						const entry = response.data.entry;
						$('#history-details-content').html(
							'<dl>' +
							'<dt><strong>Timestamp:</strong></dt>' +
							'<dd>' + this.escapeHtml(entry.timestamp) + '</dd>' +
							'<dt><strong>Type:</strong></dt>' +
							'<dd>' + this.escapeHtml(entry.type) + '</dd>' +
							'<dt><strong>Command:</strong></dt>' +
							'<dd><code>' + this.escapeHtml(entry.command) + '</code></dd>' +
							'<dt><strong>User:</strong></dt>' +
							'<dd>' + this.escapeHtml(entry.user) + '</dd>' +
							'<dt><strong>Status:</strong></dt>' +
							'<dd>' + this.escapeHtml(entry.status) + '</dd>' +
							'<dt><strong>Output:</strong></dt>' +
							'<dd><pre>' + this.escapeHtml(entry.output) + '</pre></dd>' +
							'</dl>'
						);
					} else {
						$('#history-details-content').html('<p style="color: red;">Error: ' + this.escapeHtml(response.data?.message || 'Failed to load entry') + '</p>');
					}
				},
				error: (xhr) => {
					$('#history-details-content').html('<p style="color: red;">Error: ' + this.escapeHtml(xhr.statusText) + '</p>');
				}
			});
		}

		/**
		 * Update history table.
		 */
		updateHistoryTable(history) {
			const $tbody = $('#history-table tbody');
			$tbody.empty();

			if (history.length === 0) {
				$tbody.html('<tr><td colspan="6">No execution history available.</td></tr>');
				return;
			}

			history.forEach((entry) => {
				const statusClass = entry.status === 'success' ? 'success' : 'error';
				const row = $('<tr>')
					.append($('<td>').text(entry.timestamp))
					.append($('<td>').text(entry.type.charAt(0).toUpperCase() + entry.type.slice(1)))
					.append($('<td>').html('<code>' + this.escapeHtml(entry.command) + '</code>'))
					.append($('<td>').text(entry.user))
					.append($('<td>').html('<span class="status-badge status-' + statusClass + '">' + entry.status.charAt(0).toUpperCase() + entry.status.slice(1) + '</span>'))
					.append(
						$('<td>').append(
							$('<button>')
								.addClass('button button-small view-history-details')
								.attr('data-entry-id', entry.id)
								.text('Details')
						)
					);

				$tbody.append(row);
			});
		}

		/**
		 * Escape HTML.
		 */
		escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	}

	// Initialize when document is ready.
	$(document).ready(() => {
		if ($('.wp-mcp-ai-slash-commands-dashboard').length) {
			new SlashCommandsDashboard();
		}
	});

})(jQuery);
