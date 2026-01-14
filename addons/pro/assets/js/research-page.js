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
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Example query buttons
			$(document).on('click', '.wp-mcp-ai-example-query', this.handleExampleQuery.bind(this));

			// Listen for CPT action events from chat UI
			$(document).on('wp-mcp-ai-cpt-action', this.handleCptAction.bind(this));

			// Legacy support for old buttons (if any still exist)
			$(document).on('click', '.wp-mcp-ai-create-place-btn, .wp-mcp-ai-create-place-btn-sidebar', this.handleCreatePlace.bind(this));
			$(document).on('click', '.wp-mcp-ai-create-quiz-btn, .wp-mcp-ai-create-quiz-btn-sidebar', this.handleCreateQuiz.bind(this));
			$(document).on('click', '.wp-mcp-ai-create-eca-btn, .wp-mcp-ai-create-eca-btn-sidebar', this.handleCreateECA.bind(this));
			$(document).on('click', '.wp-mcp-ai-create-policy-btn, .wp-mcp-ai-create-policy-btn-sidebar', this.handleCreatePolicy.bind(this));
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
			const $chatInput = $('.wp-mcp-ai-chat__input');
			if ($chatInput.length) {
				$chatInput.val(query).focus();
				
				// Trigger form submission
				const $chatForm = $('.wp-mcp-ai-chat__form');
				if ($chatForm.length) {
					$chatForm.trigger('submit');
				}
			}
		},

		/**
		 * Handle CPT action event from chat UI.
		 * This is the main entry point for CPT action buttons.
		 *
		 * @param {Event} e Custom event
		 */
		handleCptAction: function(e) {
			if (!e.originalEvent || !e.originalEvent.detail) {
				return;
			}

			const detail = e.originalEvent.detail;
			const action = detail.action;
			const conversation = detail.conversation;
			const button = detail.button;

			// Route to appropriate handler based on action type
			switch (action) {
				case 'create_quiz':
					this.handleCreateQuizFromConversation(conversation, button);
					break;
				case 'create_place':
					this.handleCreatePlaceFromConversation(conversation, button);
					break;
				case 'create_eca':
					this.handleCreateECAFromConversation(conversation, button);
					break;
				case 'create_policy':
					this.handleCreatePolicyFromConversation(conversation, button);
					break;
				default:
					console.warn('[Research Page] Unknown CPT action:', action);
			}
		},

		/**
		 * Handle quiz creation from conversation data.
		 * Builds the data package and sends it to the add-to-database method.
		 *
		 * @param {Object} conversation Extracted conversation data
		 * @param {HTMLElement} button The button element
		 */
		handleCreateQuizFromConversation: function(conversation, button) {
			if (!conversation) {
				this.showError(wpMcpAiResearchPage.strings.error);
				return;
			}

			// Check if we have structured data from research_quiz_topic tool
			let researchData = null;
			if (conversation.toolResults && conversation.toolResults.research_quiz_topic) {
				researchData = conversation.toolResults.research_quiz_topic;
				
				// Validate structured data
				if (!researchData || !researchData.success) {
					console.warn('[Research Page] research_quiz_topic tool result not successful');
					researchData = null;
				}
			}

			// If no structured data, fall back to text parsing
			if (!researchData) {
				if (!conversation.lastAssistantMessage && !conversation.fullText) {
					this.showError(wpMcpAiResearchPage.strings.error);
					return;
				}
				
				// Build data package from text
				researchData = this.buildQuizDataPackage(conversation);
			}

			// Confirm with user
			if (!confirm(wpMcpAiResearchPage.strings.confirmCreate)) {
				return;
			}

			// Disable button and show loading state
			const $button = $(button);
			const originalText = $button.html();
			$button.prop('disabled', true).html(
				'<span class="dashicons dashicons-update dashicons-spin"></span> ' + 
				wpMcpAiResearchPage.strings.creating
			);

			// Send to add-to-database method
			this.addQuizToDatabase(researchData).then((response) => {
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
					$button.prop('disabled', false).html(originalText);
				}
			}).catch((error) => {
				console.error('[Research Page] Quiz creation failed:', error);
				this.showError(wpMcpAiResearchPage.strings.error);
				$button.prop('disabled', false).html(originalText);
			});
		},

		/**
		 * Build quiz data package from conversation.
		 * Extracts and structures the information needed for quiz creation.
		 *
		 * @param {Object} conversation Conversation data from chat
		 * @return {Object} Structured quiz data package
		 */
		buildQuizDataPackage: function(conversation) {
			const text = conversation.lastAssistantMessage || conversation.fullText || '';
			
			// Parse quiz data from text
			const data = {
				title: this.extractField(text, ['title:', 'quiz title:', 'name:']),
				description: this.extractField(text, ['description:', 'about:']),
				subject: this.extractField(text, ['subject:', 'topic:', 'category:']),
				difficulty: this.extractField(text, ['difficulty:', 'level:']),
				time_limit: this.extractField(text, ['time limit:', 'duration:']),
				passing_score: this.extractField(text, ['passing score:', 'pass mark:']),
				questions: this.extractQuestions(text),
				raw_text: text // Include raw text for fallback processing
			};

			// If no title extracted, generate one from subject or use default
			if (!data.title && data.subject) {
				data.title = data.subject + ' Quiz';
			} else if (!data.title) {
				data.title = 'Quiz from Research';
			}

			return data;
		},

		/**
		 * Extract quiz questions from text.
		 * Parses question blocks with answers from the conversation.
		 *
		 * @param {string} text Text to parse
		 * @return {Array} Array of question objects
		 */
		extractQuestions: function(text) {
			const questions = [];
			
			// Try to match numbered questions
			// Pattern: "1. Question text?" or "Question 1:" etc.
			const questionPattern = /(?:^|\n)(?:\d+[\.\)]\s+|Question\s+\d+:\s*)(.+?)(?=\n(?:\d+[\.\)]|\n|Question\s+\d+:|$))/gis;
			const matches = text.matchAll(questionPattern);
			
			for (const match of matches) {
				const questionBlock = match[1].trim();
				
				// Extract question text (first line typically)
				const lines = questionBlock.split('\n');
				const questionText = lines[0].trim();
				
				// Try to extract answers
				const answers = this.extractAnswers(questionBlock);
				
				if (questionText && answers.length > 0) {
					questions.push({
						question: questionText,
						answers: answers
					});
				}
			}

			return questions;
		},

		/**
		 * Extract answers from question block.
		 *
		 * @param {string} text Question block text
		 * @return {Array} Array of answer objects
		 */
		extractAnswers: function(text) {
			const answers = [];
			
			// Pattern: "A) answer" or "a. answer" or "- answer"
			const answerPattern = /(?:^|\n)\s*(?:[A-Da-d][\.\)]\s*|\-\s+)(.+?)(?=\n|$)/g;
			const matches = text.matchAll(answerPattern);
			
			for (const match of matches) {
				const answerText = match[1].trim();
				
				// Check if marked as correct (contains "correct", "*", etc.)
				const isCorrect = /\(correct\)|\*|✓|✔/i.test(answerText);
				
				answers.push({
					text: answerText.replace(/\(correct\)|\*|✓|✔/gi, '').trim(),
					is_correct: isCorrect
				});
			}

			return answers;
		},

		/**
		 * Add quiz to database via AJAX.
		 * This is the final method that actually creates the CPT.
		 *
		 * @param {Object} researchData Quiz data package
		 * @return {Promise} Promise resolving to AJAX response
		 */
		addQuizToDatabase: function(researchData) {
			return $.ajax({
				url: wpMcpAiResearchPage.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_create_quiz_from_research',
					nonce: wpMcpAiResearchPage.nonce,
					research_data: JSON.stringify(researchData)
				}
			});
		},

		/**
		 * Handle create quiz from research (legacy method for old buttons).
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

			// Use the same add-to-database method
			this.addQuizToDatabase(researchData).then((response) => {
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
			}).catch(() => {
				this.showError(wpMcpAiResearchPage.strings.error);
				$button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + $button.data('original-text'));
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
