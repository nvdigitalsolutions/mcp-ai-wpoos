/**
 * Admin Test Team Page JavaScript
 *
 * Handles the test team modal, member selection, and chat interface initialization.
 * Follows SoC by separating UI interaction from chat logic.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Test Team Modal Handler
	 */
	const TestTeamModal = {
		modal: null,
		chatContainer: null,
		selectorContainer: null,
		currentTeamId: null,
		currentTeamTitle: null,
		currentMemberId: null,
		teamMembers: [],

		/**
		 * Initialize the modal handler
		 */
		init() {
			this.modal = $('#wp-mcp-ai-test-team-modal');
			this.chatContainer = $('#wp-mcp-ai-test-team-chat-container');
			this.selectorContainer = $('#wp-mcp-ai-test-team-selector');
			this.bindEvents();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents() {
			// Test button click
			$(document).on('click', '.wp-mcp-ai-test-team-btn', (e) => {
				e.preventDefault();
				const $btn = $(e.currentTarget);
				
				if ($btn.prop('disabled')) {
					return;
				}

				this.openModal(
					$btn.data('team-id'),
					$btn.data('team-title'),
					$btn.data('member-count')
				);
			});

			// Close button click
			this.modal.on('click', '.wp-mcp-ai-test-modal__close', () => {
				this.closeModal();
			});

			// Backdrop click
			this.modal.on('click', '.wp-mcp-ai-test-modal__backdrop', () => {
				this.closeModal();
			});

			// Escape key
			$(document).on('keydown', (e) => {
				if (e.key === 'Escape' && this.modal.is(':visible')) {
					this.closeModal();
				}
			});

			// Member selection
			this.selectorContainer.on('click', '.wp-mcp-ai-team-member-btn', (e) => {
				e.preventDefault();
				const $btn = $(e.currentTarget);
				this.selectMember(
					$btn.data('member-id'),
					$btn.data('member-title')
				);
			});
		},

		/**
		 * Open the modal and load team members
		 *
		 * @param {number} teamId - Team post ID
		 * @param {string} teamTitle - Team title
		 * @param {number} _memberCount - Number of team members (unused, kept for API compatibility)
		 */
		openModal(teamId, teamTitle, _memberCount) {
			this.currentTeamId = teamId;
			this.currentTeamTitle = teamTitle;

			// Update modal title
			$('#wp-mcp-ai-test-team-modal__title').text(
				'Test Team: ' + teamTitle
			);

			// Show modal
			this.modal.fadeIn(200);
			$('body').addClass('wp-mcp-ai-modal-open');

			// Load team members
			this.loadTeamMembers();
		},

		/**
		 * Close the modal
		 */
		closeModal() {
			this.modal.fadeOut(200);
			$('body').removeClass('wp-mcp-ai-modal-open');

			// Clear selections
			this.currentMemberId = null;
			this.teamMembers = [];

			// Clear containers
			this.selectorContainer.empty();
			this.chatContainer.empty();
		},

		/**
		 * Load team members via AJAX
		 */
		loadTeamMembers() {
			this.selectorContainer.html(
				'<div class="wp-mcp-ai-loading"><span class="spinner is-active"></span> Loading team members...</div>'
			);

			const ajaxUrl = wpMcpAiChat.restUrl + 'teams/' + this.currentTeamId + '/members';
			console.log('Loading team members from:', ajaxUrl);

			// Use WordPress REST API to get team meta
			$.ajax({
				url: ajaxUrl,
				method: 'GET',
				beforeSend: (xhr) => {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiChat.nonce);
					console.log('Sending request with nonce:', wpMcpAiChat.nonce ? 'present' : 'missing');
				},
				success: (data) => {
					console.log('Team members loaded successfully:', data);
					this.teamMembers = data.members || [];
					this.renderMemberSelector();
				},
				error: (xhr, status, error) => {
					console.error('Failed to load team members:', {
						status: xhr.status,
						statusText: xhr.statusText,
						responseText: xhr.responseText,
						error: error,
						url: ajaxUrl
					});
					
					// User-friendly error message from localized strings
					const errorMessage = wpMcpAiChat.strings && wpMcpAiChat.strings.teamMemberLoadError 
						? wpMcpAiChat.strings.teamMemberLoadError
						: 'Failed to load team members. Please try again.';
					
					// Log detailed error information to console only
					if (xhr.status === 404) {
						console.error('Endpoint not found - check REST API registration');
					} else if (xhr.status === 403) {
						console.error('Permission denied - check user capabilities');
					} else if (xhr.status === 500) {
						console.error('Server error - check PHP error logs');
					} else if (xhr.status === 0) {
						console.error('Network error - check browser console and network tab');
					}
					
					this.selectorContainer.html(
						'<div class="notice notice-error"><p>' + errorMessage + '</p></div>'
					);
				}
			});
		},

		/**
		 * Render member selector buttons
		 */
		renderMemberSelector() {
			if (!this.teamMembers || this.teamMembers.length === 0) {
				this.selectorContainer.html(
					'<div class="notice notice-warning"><p>This team has no members configured.</p></div>'
				);
				return;
			}

			let html = '<div class="wp-mcp-ai-team-members">';
			html += '<h3>Select a team member to chat with:</h3>';
			html += '<div class="wp-mcp-ai-team-members-grid">';

			this.teamMembers.forEach((member) => {
				const activeClass = this.currentMemberId === member.id ? 'active' : '';
				html += `
					<button 
						type="button" 
						class="wp-mcp-ai-team-member-btn ${activeClass}"
						data-member-id="${member.id}"
						data-member-title="${this.escapeHtml(member.title)}"
					>
						<span class="dashicons dashicons-businessperson"></span>
						<strong>${this.escapeHtml(member.title)}</strong>
						${member.category ? `<small>${this.escapeHtml(member.category)}</small>` : ''}
					</button>
				`;
			});

			html += '</div></div>';
			this.selectorContainer.html(html);
		},

		/**
		 * Select a team member and initialize chat
		 *
		 * @param {number} memberId - Profession post ID
		 * @param {string} memberTitle - Member title
		 */
		selectMember(memberId, memberTitle) {
			this.currentMemberId = memberId;

			// Update active state
			this.selectorContainer.find('.wp-mcp-ai-team-member-btn').removeClass('active');
			this.selectorContainer.find(`[data-member-id="${memberId}"]`).addClass('active');

			// Initialize chat for selected member
			this.initializeChat(memberId, memberTitle);
		},

		/**
		 * Initialize chat interface for selected member
		 *
		 * @param {number} memberId - Member profession ID
		 * @param {string} memberTitle - Member title
		 */
		initializeChat(memberId, memberTitle) {
			// Clear previous chat container
			this.chatContainer.empty();

			// Create unique instance ID for this chat
			const instanceId = 'wp-mcp-ai-test-team-chat-' + this.currentTeamId + '-' + memberId + '-' + Date.now();

			// Build chat HTML structure
			const chatHTML = this.buildChatHTML(instanceId, memberTitle);
			this.chatContainer.html(chatHTML);

			// Initialize chat instance configuration
			if (!window.wpMcpAiChatInstances) {
				window.wpMcpAiChatInstances = {};
			}

			// Build endpoints from base REST URL
			const baseRestUrl = (window.wpMcpAiChat && window.wpMcpAiChat.restUrl) ? window.wpMcpAiChat.restUrl : '/wp-json/mcp-ai/v1';

			// Get file upload configuration from global config
			const fileAccept = (window.wpMcpAiChat && window.wpMcpAiChat.fileAccept) ? window.wpMcpAiChat.fileAccept : '';
			const allowedImageMimes = (window.wpMcpAiChat && window.wpMcpAiChat.allowedImageMimes) ? window.wpMcpAiChat.allowedImageMimes : [];
			const allowedFileMimes = (window.wpMcpAiChat && window.wpMcpAiChat.allowedFileMimes) ? window.wpMcpAiChat.allowedFileMimes : [];
			const allowedExtensions = (window.wpMcpAiChat && window.wpMcpAiChat.allowedExtensions) ? window.wpMcpAiChat.allowedExtensions : [];

			// Create assistant ID for this team member
			const assistantId = 'team_' + this.currentTeamId + '_member_' + memberId;

			window.wpMcpAiChatInstances[instanceId] = {
				assistantId: assistantId,
				professionId: memberId,
				teamId: this.currentTeamId,
				userId: (window.wpMcpAiChat && typeof window.wpMcpAiChat.currentUserId !== 'undefined') ? window.wpMcpAiChat.currentUserId : 0,
				messagesEndpoint: baseRestUrl + '/chat-client',
				toolsEndpoint: baseRestUrl + '/tools',
				filesEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.filesEndpoint) ? window.wpMcpAiChat.filesEndpoint : baseRestUrl + '/files/',
				transcriptsEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.transcriptsEndpoint) ? window.wpMcpAiChat.transcriptsEndpoint : baseRestUrl + '/chat-transcripts',
				crawl4aiTaskEndpoint: baseRestUrl + '/crawl4ai/task/',
				uploadEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.uploadEndpoint) ? window.wpMcpAiChat.uploadEndpoint : '/wp-json/wp/v2/media',
				sessionKey: this.generateSessionKey(),
				enableStreaming: true,
				canUploadAttachments: true,
				saveTranscript: true,
				allowSensitiveTools: true,
				toolShortcuts: [],
				fileAccept: fileAccept,
				allowedImageMimes: allowedImageMimes,
				allowedFileMimes: allowedFileMimes,
				allowedExtensions: allowedExtensions,
				restNonce: (window.wpMcpAiChat && window.wpMcpAiChat.nonce) ? window.wpMcpAiChat.nonce : '',
				historyPerPage: 20,
			};

			// Trigger chat.js initialization
			this.initializeChatInstance(instanceId);
		},

		/**
		 * Build the chat interface HTML structure.
		 *
		 * @param {string} instanceId - Unique instance identifier
		 * @param {string} memberTitle - Team member title for placeholder
		 * @return {string} HTML string for chat interface
		 */
		buildChatHTML(instanceId, memberTitle) {
			const placeholderEscaped = this.escapeHtml(this.getPlaceholder(memberTitle));
			const attachLabelEscaped = this.escapeHtml(this.getAttachLabel());
			const transcribeLabelEscaped = this.escapeHtml(this.getTranscribeLabel());
			const sendLabelEscaped = this.escapeHtml(this.getSendLabel());
			
			return '<div class="wp-mcp-ai-chat" id="' + instanceId + '" data-wp-mcp-ai-chat>' +
				'<div class="wp-mcp-ai-chat__transcript-controls">' +
				'<button type="button" class="wp-mcp-ai-chat__transcript-toggle" aria-expanded="false" aria-label="Expand conversation">' +
				'<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />' +
				'</svg>' +
				'<span class="screen-reader-text">Expand conversation</span>' +
				'</button>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__messages" role="log" aria-live="polite" aria-atomic="false"></div>' +
				'<form class="wp-mcp-ai-chat__form">' +
				'<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden></div>' +
				'<div class="wp-mcp-ai-chat__tool-shortcuts-wrapper" hidden>' +
				'<button type="button" class="wp-mcp-ai-chat__tool-shortcuts-toggle wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed" aria-expanded="false" aria-controls="' + instanceId + '-tool-shortcuts">' +
				'<span class="wp-mcp-ai-chat__tool-shortcuts-toggle-text">Quick Tasks</span>' +
				'<svg class="wp-mcp-ai-chat__tool-shortcuts-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />' +
				'</svg>' +
				'</button>' +
				'<div id="' + instanceId + '-tool-shortcuts" class="wp-mcp-ai-chat__tool-shortcuts wp-mcp-ai-chat__tool-shortcuts--collapsed" role="group" aria-label="Assistant tool tasks" hidden></div>' +
				'</div>' +
				'<textarea class="wp-mcp-ai-chat__input" rows="4" placeholder="' + placeholderEscaped + '" required></textarea>' +
				'<div class="wp-mcp-ai-chat__attachments" hidden>' +
				'<div class="wp-mcp-ai-chat__attachments-header">Attachments</div>' +
				'<ul class="wp-mcp-ai-chat__attachments-list"></ul>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__actions">' +
				'<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />' +
				'<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden />' +
				'<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="' + transcribeLabelEscaped + '">' +
				'<svg class="wp-mcp-ai-chat__transcribe-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>' +
				'<path d="M12 16a7 7 0 0 0 6.93-6H17a5 5 0 0 1-10 0H5.07A7 7 0 0 0 12 16zm-1 2.05V21h2v-2.95A9 9 0 0 0 20.95 11H19a7 7 0 0 1-14 0H3.05A9 9 0 0 0 11 18.05z"></path>' +
				'</svg>' +
				'<span class="screen-reader-text">' + transcribeLabelEscaped + '</span>' +
				'</button>' +
				'<button type="button" class="wp-mcp-ai-chat__attach">' + attachLabelEscaped + '</button>' +
				'<button type="submit" class="wp-mcp-ai-chat__submit">' + sendLabelEscaped + '</button>' +
				'</div>' +
				'</form>' +
				'<div class="wp-mcp-ai-chat__controls">' +
				'<div class="wp-mcp-ai-chat__quota-monitor" role="status" aria-live="polite" aria-atomic="true"></div>' +
				'<div class="wp-mcp-ai-chat__cron-status" role="status" aria-live="polite" aria-atomic="true" hidden>' +
				'<span class="wp-mcp-ai-chat__cron-status-label">Jobs:</span>' +
				'<span class="wp-mcp-ai-chat__cron-status-pending" title="Pending jobs">' +
				'<span class="wp-mcp-ai-chat__cron-status-count">0</span>' +
				'</span>' +
				'<span class="wp-mcp-ai-chat__cron-status-completed" title="Completed jobs">' +
				'<span class="wp-mcp-ai-chat__cron-status-count">0</span>' +
				'</span>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__control-buttons">' +
				'<button type="button" class="wp-mcp-ai-chat__save" aria-label="Save conversation" title="Save conversation">' +
				'<svg class="wp-mcp-ai-chat__save-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM5 5v14h14V9h-4V5H5z" />' +
				'<path d="M7 5h6v3H7V5zm5 9a2 2 0 11-4 0 2 2 0 014 0z" />' +
				'</svg>' +
				'<span class="screen-reader-text">Save conversation</span>' +
				'</button>' +
				'<button type="button" class="wp-mcp-ai-chat__export" aria-label="Export conversation" title="Export conversation">' +
				'<svg class="wp-mcp-ai-chat__export-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 16a1 1 0 01-1-1V5a1 1 0 012 0v10a1 1 0 01-1 1z" />' +
				'<path d="M12 16a1 1 0 01-.707-.293l-4-4a1 1 0 011.414-1.414L12 13.586l3.293-3.293a1 1 0 011.414 1.414l-4 4A1 1 0 0112 16z" />' +
				'<path d="M5 19a1 1 0 010-2h14a1 1 0 010 2H5z" />' +
				'</svg>' +
				'<span class="screen-reader-text">Export conversation</span>' +
				'</button>' +
				'<button type="button" class="wp-mcp-ai-chat__history-toggle" aria-expanded="false" aria-controls="' + instanceId + '-history" aria-label="Show previous conversations">' +
				'<svg class="wp-mcp-ai-chat__history-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M6 5.5a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h7a1 1 0 010 2H7a1 1 0 01-1-1z" />' +
				'<path d="M5 9a1 1 0 012 0 1 1 0 11-2 0zm0 6a1 1 0 012 0 1 1 0 11-2 0zm0-12a1 1 0 012 0 1 1 0 11-2 0z" />' +
				'</svg>' +
				'<span class="screen-reader-text">Show previous conversations</span>' +
				'</button>' +
				'<button type="button" class="wp-mcp-ai-chat__new-chat" aria-label="Start new conversation">' +
				'<svg class="wp-mcp-ai-chat__new-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 4a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6V5a1 1 0 011-1z" />' +
				'</svg>' +
				'<span class="screen-reader-text">Start new conversation</span>' +
				'</button>' +
				'</div>' +
				'</div>' +
				'<section class="wp-mcp-ai-chat__history" id="' + instanceId + '-history" hidden aria-label="Previous conversations">' +
				'<div class="wp-mcp-ai-chat__history-header">' +
				'<button type="button" class="wp-mcp-ai-chat__history-refresh" aria-label="Refresh conversation history" title="Refresh conversation history">' +
				'<svg class="wp-mcp-ai-chat__history-refresh-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M4 12a8 8 0 018-8V3c-1.105 0-2.165.21-3.13.594l1.42 1.42A6.004 6.004 0 0112 5a7 7 0 110 14 7 7 0 01-6.93-6H3a8 8 0 008 8 8 8 0 000-16V3l-3 3 3 3v-1.078z"/>' +
				'</svg>' +
				'<span class="screen-reader-text">Refresh conversation history</span>' +
				'</button>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__history-status" role="status" aria-live="polite" hidden></div>' +
				'<ul class="wp-mcp-ai-chat__history-list" role="list"></ul>' +
				'</section>' +
				'</div>';
		},

		/**
		 * Initialize a chat instance manually.
		 *
		 * @param {string} instanceId - Instance identifier
		 */
		initializeChatInstance(instanceId) {
			// Wait a brief moment for DOM to settle
			setTimeout(() => {
				const container = document.getElementById(instanceId);

				if (!container) {
					return;
				}

				// Trigger a DOMContentLoaded event to re-init chat.js
				const event = document.createEvent('Event');
				event.initEvent('DOMContentLoaded', true, true);
				document.dispatchEvent(event);

				// Focus the textarea to give user immediate access
				setTimeout(() => {
					const textarea = container.querySelector('.wp-mcp-ai-chat__input');
					if (textarea) {
						textarea.focus();
					}
				}, 200);
			}, 100);
		},

		/**
		 * Generate a unique session key for the chat instance.
		 *
		 * @return {string} Session key
		 */
		generateSessionKey() {
			return 'test-team-' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
		},

		/**
		 * Get placeholder text for input.
		 *
		 * @param {string} memberTitle - Team member title
		 * @return {string} Placeholder text
		 */
		getPlaceholder(memberTitle) {
			if (memberTitle) {
				return 'Chat with ' + memberTitle + '...';
			}
			return (window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.placeholder) ? window.wpMcpAiChat.strings.placeholder : 'Ask something...';
		},

		/**
		 * Get send button label.
		 *
		 * @return {string} Send label
		 */
		getSendLabel() {
			return (window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.send) ? window.wpMcpAiChat.strings.send : 'Send';
		},

		/**
		 * Get attach button label.
		 *
		 * @return {string} Attach label
		 */
		getAttachLabel() {
			return (window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.attachFile) ? window.wpMcpAiChat.strings.attachFile : 'Attach file';
		},

		/**
		 * Get transcribe button label.
		 *
		 * @return {string} Transcribe label
		 */
		getTranscribeLabel() {
			return (window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.transcribeAudio) ? window.wpMcpAiChat.strings.transcribeAudio : 'Transcribe audio';
		},

		/**
		 * Escape HTML to prevent XSS
		 *
		 * @param {string} text - Text to escape
		 * @return {string} Escaped text
		 */
		escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	/**
	 * Initialize when DOM is ready
	 */
	$(document).ready(function() {
		TestTeamModal.init();
	});

})(jQuery);
