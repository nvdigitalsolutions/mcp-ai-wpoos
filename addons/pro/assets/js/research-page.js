/**
 * Research Page JavaScript
 *
 * Handles research page functionality including chat interaction
 * and creating CPT entries from research results.
 *
 * @package WP_MCP_AI_Pro
 */

(function($) {
	'use strict';

	/**
	 * Research Page Manager
	 */
	const ResearchPage = {
		/**
		 * Initialize the research page.
		 */
		init: function() {
			this.bindEvents();
			this.monitorChatMessages();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Example query buttons
			$(document).on('click', '.wp-mcp-ai-example-query', this.handleExampleQuery.bind(this));

			// Create from research buttons
			$(document).on('click', '.wp-mcp-ai-create-place-btn, .wp-mcp-ai-create-place-btn-sidebar', this.handleCreatePlace.bind(this));
			$(document).on('click', '.wp-mcp-ai-create-quiz-btn, .wp-mcp-ai-create-quiz-btn-sidebar', this.handleCreateQuiz.bind(this));
			$(document).on('click', '.wp-mcp-ai-create-eca-btn, .wp-mcp-ai-create-eca-btn-sidebar', this.handleCreateECA.bind(this));
			$(document).on('click', '.wp-mcp-ai-create-policy-btn, .wp-mcp-ai-create-policy-btn-sidebar', this.handleCreatePolicy.bind(this));

			// Close research data preview
			$(document).on('click', '.wp-mcp-ai-close-research', this.closeResearchPreview.bind(this));
		},

		/**
		 * Handle example query button click.
		 *
		 * @param {Event} e Click event
		 */
		handleExampleQuery: function(e) {
			e.preventDefault();
			const query = $(e.currentTarget).data('query');
			
			if (!query) {
				return;
			}

			// Find the chat input and set the query
			const $chatInput = $('.wp-mcp-ai-chat-input');
			if ($chatInput.length) {
				$chatInput.val(query).focus();
				
				// Trigger send button if available
				const $sendBtn = $('.wp-mcp-ai-send-button');
				if ($sendBtn.length) {
					$sendBtn.trigger('click');
				}
			}
		},

		/**
		 * Monitor chat messages for research results.
		 */
		monitorChatMessages: function() {
			// Check for new messages periodically
			setInterval(() => {
				this.checkForResearchData();
			}, 2000);
		},

		/**
		 * Check chat messages for research data.
		 */
		checkForResearchData: function() {
			const $messages = $('.wp-mcp-ai-message');
			if ($messages.length === 0) {
				return;
			}

			// Get the last assistant message
			const $lastMessage = $messages.filter('.wp-mcp-ai-message--assistant').last();
			if ($lastMessage.length === 0) {
				return;
			}

			const messageText = $lastMessage.text();
			
			// Check if message contains research results (simple heuristic)
			// You can make this more sophisticated based on your needs
			if (this.looksLikeResearchData(messageText)) {
				this.extractAndShowResearchData($lastMessage);
			}
		},

		/**
		 * Check if message looks like research data.
		 *
		 * @param {string} text Message text
		 * @return {boolean} True if looks like research data
		 */
		looksLikeResearchData: function(text) {
			// Check for common research data indicators
			const indicators = [
				'address:', 'latitude:', 'longitude:',
				'phone:', 'website:', 'rating:',
				'question:', 'answer:', 'correct:',
				'hours:', 'location:', 'amenities:'
			];

			const lowerText = text.toLowerCase();
			let matchCount = 0;

			indicators.forEach(indicator => {
				if (lowerText.includes(indicator)) {
					matchCount++;
				}
			});

			// If we have 3 or more indicators, likely research data
			return matchCount >= 3;
		},

		/**
		 * Extract and show research data.
		 *
		 * @param {jQuery} $message Message element
		 */
		extractAndShowResearchData: function($message) {
			const messageText = $message.text();
			const messageHtml = $message.html();

			// Store research data
			this.currentResearchData = {
				text: messageText,
				html: messageHtml,
				timestamp: new Date().getTime()
			};

			// Show the research data preview
			this.showResearchPreview(messageHtml);
		},

		/**
		 * Show research data preview section.
		 *
		 * @param {string} dataHtml Research data HTML
		 */
		showResearchPreview: function(dataHtml) {
			const $previewSection = $('.wp-mcp-ai-research-create-section');
			const $dataContent = $('#wp-mcp-ai-research-data-content');

			if ($previewSection.length && $dataContent.length) {
				$dataContent.html(dataHtml);
				$previewSection.slideDown(300);

				// Scroll to preview
				$('html, body').animate({
					scrollTop: $previewSection.offset().top - 100
				}, 500);
			}
		},

		/**
		 * Close research preview.
		 *
		 * @param {Event} e Click event
		 */
		closeResearchPreview: function(e) {
			e.preventDefault();
			$('.wp-mcp-ai-research-create-section').slideUp(300);
		},

		/**
		 * Handle create place from research.
		 *
		 * @param {Event} e Click event
		 */
		handleCreatePlace: function(e) {
			e.preventDefault();
			
			const $button = $(e.currentTarget);
			
			if (!this.currentResearchData) {
				this.showError(wpMcpAiResearchPage.strings.error);
				return;
			}

			// Confirm with user
			if (!confirm(wpMcpAiResearchPage.strings.confirmCreate)) {
				return;
			}

			// Disable button and show loading
			$button.prop('disabled', true).text(wpMcpAiResearchPage.strings.creating);

			// Extract research data
			const researchData = this.parseResearchDataForPlace(this.currentResearchData.text);

			// Send AJAX request
			$.ajax({
				url: wpMcpAiResearchPage.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_create_place_from_research',
					nonce: wpMcpAiResearchPage.nonce,
					research_data: JSON.stringify(researchData)
				},
				success: (response) => {
					if (response.success) {
						this.showSuccess(response.data.message);
						
						// Redirect to edit page after short delay
						setTimeout(() => {
							if (response.data.edit_url) {
								window.location.href = response.data.edit_url;
							}
						}, 1500);
					} else {
						this.showError(response.data.message || wpMcpAiResearchPage.strings.error);
						$button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + $button.data('original-text'));
					}
				},
				error: () => {
					this.showError(wpMcpAiResearchPage.strings.error);
					$button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + $button.data('original-text'));
				}
			});

			// Store original button text
			if (!$button.data('original-text')) {
				$button.data('original-text', $button.text());
			}
		},

		/**
		 * Handle create quiz from research.
		 *
		 * @param {Event} e Click event
		 */
		handleCreateQuiz: function(e) {
			e.preventDefault();
			
			const $button = $(e.currentTarget);
			
			if (!this.currentResearchData) {
				this.showError(wpMcpAiResearchPage.strings.error);
				return;
			}

			// Confirm with user
			if (!confirm(wpMcpAiResearchPage.strings.confirmCreate)) {
				return;
			}

			// Disable button and show loading
			$button.prop('disabled', true).text(wpMcpAiResearchPage.strings.creating);

			// Extract research data
			const researchData = this.parseResearchDataForQuiz(this.currentResearchData.text);

			// Send AJAX request
			$.ajax({
				url: wpMcpAiResearchPage.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_create_quiz_from_research',
					nonce: wpMcpAiResearchPage.nonce,
					research_data: JSON.stringify(researchData)
				},
				success: (response) => {
					if (response.success) {
						this.showSuccess(response.data.message);
						
						// Redirect to edit page after short delay
						setTimeout(() => {
							if (response.data.edit_url) {
								window.location.href = response.data.edit_url;
							}
						}, 1500);
					} else {
						this.showError(response.data.message || wpMcpAiResearchPage.strings.error);
						$button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + $button.data('original-text'));
					}
				},
				error: () => {
					this.showError(wpMcpAiResearchPage.strings.error);
					$button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + $button.data('original-text'));
				}
			});

			// Store original button text
			if (!$button.data('original-text')) {
				$button.data('original-text', $button.text());
			}
		},

		/**
		 * Parse research data for place creation.
		 *
		 * @param {string} text Research text
		 * @return {Object} Parsed data
		 */
		parseResearchDataForPlace: function(text) {
			// Basic parsing - extract key information
			// This is a simple implementation; you may want to make it more sophisticated
			const data = {
				name: this.extractField(text, ['name:', 'place name:', 'location:']),
				description: this.extractField(text, ['description:', 'about:']),
				address: this.extractField(text, ['address:', 'location:']),
				phone: this.extractField(text, ['phone:', 'tel:', 'telephone:']),
				website: this.extractField(text, ['website:', 'url:', 'web:']),
				place_type: this.extractField(text, ['type:', 'category:', 'place type:'])
			};

			// Extract coordinates if present
			const latMatch = text.match(/latitude[:\s]+(-?\d+\.?\d*)/i);
			const lonMatch = text.match(/longitude[:\s]+(-?\d+\.?\d*)/i);
			
			if (latMatch) {
				data.latitude = parseFloat(latMatch[1]);
			}
			if (lonMatch) {
				data.longitude = parseFloat(lonMatch[1]);
			}

			return data;
		},

		/**
		 * Parse research data for quiz creation.
		 *
		 * @param {string} text Research text
		 * @return {Object} Parsed data
		 */
		parseResearchDataForQuiz: function(text) {
			// Basic parsing - extract key information
			const data = {
				title: this.extractField(text, ['title:', 'quiz title:', 'name:']),
				description: this.extractField(text, ['description:', 'about:']),
				subject: this.extractField(text, ['subject:', 'topic:', 'category:']),
				difficulty: this.extractField(text, ['difficulty:', 'level:']),
				questions: [] // Questions would need more sophisticated parsing
			};

			return data;
		},

		/**
		 * Handle create ECA from research.
		 *
		 * @param {Event} e Click event
		 */
		handleCreateECA: function(e) {
			e.preventDefault();
			
			const $button = $(e.currentTarget);
			
			if (!this.currentResearchData) {
				this.showError(wpMcpAiResearchPage.strings.error);
				return;
			}

			// Confirm with user
			if (!confirm(wpMcpAiResearchPage.strings.confirmCreate)) {
				return;
			}

			// Disable button and show loading
			$button.prop('disabled', true).text(wpMcpAiResearchPage.strings.creating);

			// Extract research data
			const researchData = this.parseResearchDataForECA(this.currentResearchData.text);

			// Send AJAX request
			$.ajax({
				url: wpMcpAiResearchPage.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_create_eca_from_research',
					nonce: wpMcpAiResearchPage.nonce,
					research_data: JSON.stringify(researchData)
				},
				success: (response) => {
					if (response.success) {
						this.showSuccess(response.data.message);
						
						// Redirect to edit page after short delay
						setTimeout(() => {
							if (response.data.edit_url) {
								window.location.href = response.data.edit_url;
							}
						}, 1500);
					} else {
						this.showError(response.data.message || wpMcpAiResearchPage.strings.error);
						$button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + $button.data('original-text'));
					}
				},
				error: () => {
					this.showError(wpMcpAiResearchPage.strings.error);
					$button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + $button.data('original-text'));
				}
			});

			// Store original button text
			if (!$button.data('original-text')) {
				$button.data('original-text', $button.text());
			}
		},

		/**
		 * Handle create policy from research.
		 *
		 * @param {Event} e Click event
		 */
		handleCreatePolicy: function(e) {
			e.preventDefault();
			
			const $button = $(e.currentTarget);
			
			if (!this.currentResearchData) {
				this.showError(wpMcpAiResearchPage.strings.error);
				return;
			}

			// Confirm with user
			if (!confirm(wpMcpAiResearchPage.strings.confirmCreate)) {
				return;
			}

			// Disable button and show loading
			$button.prop('disabled', true).text(wpMcpAiResearchPage.strings.creating);

			// Extract research data
			const researchData = this.parseResearchDataForPolicy(this.currentResearchData.text);

			// Send AJAX request
			$.ajax({
				url: wpMcpAiResearchPage.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_create_policy_from_research',
					nonce: wpMcpAiResearchPage.nonce,
					research_data: JSON.stringify(researchData)
				},
				success: (response) => {
					if (response.success) {
						this.showSuccess(response.data.message);
						
						// Redirect to edit page after short delay
						setTimeout(() => {
							if (response.data.edit_url) {
								window.location.href = response.data.edit_url;
							}
						}, 1500);
					} else {
						this.showError(response.data.message || wpMcpAiResearchPage.strings.error);
						$button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + $button.data('original-text'));
					}
				},
				error: () => {
					this.showError(wpMcpAiResearchPage.strings.error);
					$button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + $button.data('original-text'));
				}
			});

			// Store original button text
			if (!$button.data('original-text')) {
				$button.data('original-text', $button.text());
			}
		},

		/**
		 * Parse research data for ECA creation.
		 *
		 * @param {string} text Research text
		 * @return {Object} Parsed data
		 */
		parseResearchDataForECA: function(text) {
			// Basic parsing - extract key information
			const data = {
				title: this.extractField(text, ['title:', 'activity:', 'name:']),
				description: this.extractField(text, ['description:', 'about:']),
				category: this.extractField(text, ['category:', 'type:']),
				age_range: this.extractField(text, ['age range:', 'ages:']),
				duration: this.extractField(text, ['duration:', 'length:']),
				frequency: this.extractField(text, ['frequency:', 'schedule:'])
			};

			return data;
		},

		/**
		 * Parse research data for policy creation.
		 *
		 * @param {string} text Research text
		 * @return {Object} Parsed data
		 */
		parseResearchDataForPolicy: function(text) {
			// Basic parsing - extract key information
			const data = {
				policy_name: this.extractField(text, ['policy name:', 'name:', 'title:']),
				description: this.extractField(text, ['description:', 'about:']),
				policy_type: this.extractField(text, ['policy type:', 'type:', 'category:']),
				coverage_details: this.extractField(text, ['coverage:', 'covered:']),
				deductible: this.extractField(text, ['deductible:']),
				premium_range: this.extractField(text, ['premium:', 'cost:'])
			};

			return data;
		},

		/**
		 * Extract field from text.
		 *
		 * @param {string} text Text to search
		 * @param {Array} labels Field labels to look for
		 * @return {string} Extracted value
		 */
		extractField: function(text, labels) {
			for (let label of labels) {
				const regex = new RegExp(label + '\\s*([^\\n]+)', 'i');
				const match = text.match(regex);
				if (match && match[1]) {
					return match[1].trim();
				}
			}
			return '';
		},

		/**
		 * Show success message.
		 *
		 * @param {string} message Success message
		 */
		showSuccess: function(message) {
			const $container = $('.wp-mcp-ai-research-main');
			const $msg = $('<div class="wp-mcp-ai-success-message"><p></p></div>');
			$msg.find('p').text(message);
			$container.prepend($msg);

			setTimeout(() => {
				$msg.fadeOut(300, function() {
					$(this).remove();
				});
			}, 5000);
		},

		/**
		 * Show error message.
		 *
		 * @param {string} message Error message
		 */
		showError: function(message) {
			const $container = $('.wp-mcp-ai-research-main');
			const $msg = $('<div class="wp-mcp-ai-error-message"><p></p></div>');
			$msg.find('p').text(message);
			$container.prepend($msg);

			setTimeout(() => {
				$msg.fadeOut(300, function() {
					$(this).remove();
				});
			}, 5000);
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		if ($('.wp-mcp-ai-research-page').length) {
			ResearchPage.init();
		}
	});

})(jQuery);
