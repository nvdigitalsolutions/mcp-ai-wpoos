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
		chatInstance: null,
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
		 * @param {number} memberCount - Number of team members
		 */
		openModal(teamId, teamTitle, memberCount) {
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

			// Destroy chat instance
			if (this.chatInstance && typeof this.chatInstance.destroy === 'function') {
				this.chatInstance.destroy();
				this.chatInstance = null;
			}

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
					
					// User-friendly error message
					let errorMessage = 'Failed to load team members. Please try again.';
					
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

			// Initialize or reset chat
			if (!this.chatInstance) {
				this.initializeChat(memberId, memberTitle);
			} else {
				this.chatInstance.reset();
			}
		},

		/**
		 * Initialize chat interface for selected member
		 *
		 * @param {number} memberId - Member profession ID
		 * @param {string} memberTitle - Member title
		 */
		initializeChat(memberId, memberTitle) {
			// Check if WpMcpAiChat is available
			if (typeof window.WpMcpAiChat === 'undefined') {
				console.error('WpMcpAiChat class not found. Ensure chat.js is loaded.');
				this.chatContainer.html(
					'<div class="notice notice-error"><p>Chat interface failed to load. Please refresh the page.</p></div>'
				);
				return;
			}

			// Create assistant ID for this team member
			const assistantId = `team_${this.currentTeamId}_member_${memberId}`;

			try {
				// Initialize chat instance
				this.chatInstance = new window.WpMcpAiChat(
					this.chatContainer[0],
					{
						assistantId: assistantId,
						professionId: memberId,
						teamId: this.currentTeamId,
						mode: 'test-team',
						showHistory: false,
						enableFileUpload: true,
						placeholder: `Chat with ${memberTitle}...`,
					}
				);
			} catch (error) {
				console.error('Failed to initialize chat:', error);
				this.chatContainer.html(
					'<div class="notice notice-error"><p>Failed to initialize chat: ' + error.message + '</p></div>'
				);
			}
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
