/**
 * Admin Test Profession Page JavaScript
 *
 * Handles the test profession modal and chat interface initialization.
 * Follows SoC by separating UI interaction from chat logic.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Test Profession Modal Handler
	 */
	const TestProfessionModal = {
		modal: null,
		chatContainer: null,
		currentProfessionId: null,
		currentProfessionTitle: null,
		chatInstance: null,

		/**
		 * Initialize the modal handler
		 */
		init() {
			this.modal = $('#wp-mcp-ai-test-profession-modal');
			this.chatContainer = $('#wp-mcp-ai-test-profession-chat-container');
			this.bindEvents();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents() {
			// Test button click
			$(document).on('click', '.wp-mcp-ai-test-profession-btn', (e) => {
				e.preventDefault();
				const $btn = $(e.currentTarget);
				this.openModal(
					$btn.data('profession-id'),
					$btn.data('profession-title')
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
		},

		/**
		 * Open the modal and initialize chat
		 *
		 * @param {number} professionId - Profession post ID
		 * @param {string} professionTitle - Profession title
		 */
		openModal(professionId, professionTitle) {
			this.currentProfessionId = professionId;
			this.currentProfessionTitle = professionTitle;

			// Update modal title
			$('#wp-mcp-ai-test-profession-modal__title').text(
				'Test Profession: ' + professionTitle
			);

			// Show modal
			this.modal.fadeIn(200);
			$('body').addClass('wp-mcp-ai-modal-open');

			// Initialize chat if not already initialized
			if (!this.chatInstance) {
				this.initializeChat();
			} else {
				// Reset chat for new profession
				this.chatInstance.reset();
			}
		},

		/**
		 * Close the modal
		 */
		closeModal() {
			this.modal.fadeOut(200);
			$('body').removeClass('wp-mcp-ai-modal-open');

			// Optional: Destroy chat instance to free memory
			if (this.chatInstance && typeof this.chatInstance.destroy === 'function') {
				this.chatInstance.destroy();
				this.chatInstance = null;
			}
		},

		/**
		 * Initialize chat interface
		 */
		initializeChat() {
			// Check if WpMcpAiChat is available (from chat.js)
			if (typeof window.WpMcpAiChat === 'undefined') {
				console.error('WpMcpAiChat class not found. Ensure chat.js is loaded.');
				this.chatContainer.html(
					'<div class="notice notice-error"><p>Chat interface failed to load. Please refresh the page.</p></div>'
				);
				return;
			}

			// Create a temporary assistant ID for this profession
			// The backend will handle creating/retrieving the test assistant
			const assistantId = `profession_${this.currentProfessionId}`;

			try {
				// Initialize chat instance
				this.chatInstance = new window.WpMcpAiChat(
					this.chatContainer[0],
					{
						assistantId: assistantId,
						professionId: this.currentProfessionId,
						mode: 'test-profession',
						showHistory: false,
						enableFileUpload: true,
						placeholder: 'Test the profession by asking questions...',
					}
				);
			} catch (error) {
				console.error('Failed to initialize chat:', error);
				this.chatContainer.html(
					'<div class="notice notice-error"><p>Failed to initialize chat: ' + error.message + '</p></div>'
				);
			}
		}
	};

	/**
	 * Initialize when DOM is ready
	 */
	$(document).ready(function() {
		TestProfessionModal.init();
	});

})(jQuery);
