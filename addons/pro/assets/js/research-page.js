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
			this.initPreview();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Example query buttons
			$(document).on('click', '.wp-mcp-ai-example-query', this.handleExampleQuery.bind(this));

			// Listen for CPT action events from chat UI
			$(document).on('wp-mcp-ai-cpt-action', this.handleCptAction.bind(this));

			// Listen for tool result storage events to update preview
			$(document).on('wp-mcp-ai-tool-result-stored', this.handleToolResultStored.bind(this));

			// Legacy support for old buttons (if any still exist)
			$(document).on('click', '.wp-mcp-ai-create-place-btn, .wp-mcp-ai-create-place-btn-sidebar', this.handleCreatePlace.bind(this));
			$(document).on('click', '.wp-mcp-ai-create-quiz-btn, .wp-mcp-ai-create-quiz-btn-sidebar', this.handleCreateQuiz.bind(this));
			$(document).on('click', '.wp-mcp-ai-create-eca-btn, .wp-mcp-ai-create-eca-btn-sidebar', this.handleCreateECA.bind(this));
			$(document).on('click', '.wp-mcp-ai-create-policy-btn, .wp-mcp-ai-create-policy-btn-sidebar', this.handleCreatePolicy.bind(this));
		},

		/**
		 * Initialize preview panel.
		 */
		initPreview: function() {
			// Hide preview initially
			$('#wp-mcp-ai-quiz-preview').hide();
			
			// Initialize preview state
			this.previewState = {
				currentPage: 1,
				questionsPerPage: 3,
				totalQuestions: 0,
				quizData: null
			};
			
			// Bind pagination events
			$(document).on('click', '.wp-mcp-ai-preview-prev', this.handlePrevPage.bind(this));
			$(document).on('click', '.wp-mcp-ai-preview-next', this.handleNextPage.bind(this));
		},

		/**
		 * Handle previous page button.
		 *
		 * @param {Event} e Click event
		 */
		handlePrevPage: function(e) {
			e.preventDefault();
			
			if (this.previewState.currentPage > 1) {
				this.previewState.currentPage--;
				this.renderQuestions();
			}
		},

		/**
		 * Handle next page button.
		 *
		 * @param {Event} e Click event
		 */
		handleNextPage: function(e) {
			e.preventDefault();
			
			const totalPages = Math.ceil(this.previewState.totalQuestions / this.previewState.questionsPerPage);
			
			if (this.previewState.currentPage < totalPages) {
				this.previewState.currentPage++;
				this.renderQuestions();
			}
		},

		/**
		 * Handle tool result stored event.
		 * Updates the sidebar preview when research tools complete.
		 *
		 * @param {Event} e Custom event with tool result data
		 */
		handleToolResultStored: function(e) {
			if (!e.originalEvent || !e.originalEvent.detail) {
				return;
			}

			const detail = e.originalEvent.detail;
			const toolName = detail.toolName;
			const result = detail.result;

			// Only handle research_quiz_topic results
			if (toolName !== 'research_quiz_topic') {
				return;
			}

			// Update preview with structured data
			this.updatePreview(result);
		},

		/**
		 * Update preview panel with quiz data.
		 * Supports multiple updates during conversation with smooth transitions.
		 *
		 * @param {Object} data Quiz data from research tool
		 */
		updatePreview: function(data) {
			const $preview = $('#wp-mcp-ai-quiz-preview');
			const $loading = $preview.find('.wp-mcp-ai-preview-loading');
			const $data = $preview.find('.wp-mcp-ai-preview-data');

			// Check if this is an update (preview already visible) or initial load
			const isUpdate = $preview.is(':visible') && $data.is(':visible');

			// Show preview panel if hidden
			if (!$preview.is(':visible')) {
				$preview.slideDown(300);
			}

			// Validate data
			if (!data || !data.success) {
				$loading.show();
				$loading.html('<p class="wp-mcp-ai-preview-error">Failed to load quiz data</p>');
				$data.hide();
				return;
			}

			// Store quiz data in state
			this.previewState.quizData = data;
			this.previewState.totalQuestions = (data.questions && data.questions.length) || 0;
			
			// Reset to page 1 if questions changed significantly
			if (isUpdate) {
				const totalPages = Math.ceil(this.previewState.totalQuestions / this.previewState.questionsPerPage);
				if (this.previewState.currentPage > totalPages) {
					this.previewState.currentPage = 1;
				}
			} else {
				this.previewState.currentPage = 1;
			}

			// Show brief loading for updates, or longer for initial load
			const loadingDelay = isUpdate ? 200 : 500;
			
			if (!isUpdate) {
				$loading.show();
				$data.hide();
			} else {
				// Add updating indicator for smooth transitions
				$data.css('opacity', '0.6');
			}

			// Build preview HTML after delay
			setTimeout(() => {
				// Update header
				this.updatePreviewHeader(data);
				
				// Render questions for current page
				this.renderQuestions();
				
				// Update pagination
				this.updatePagination();

				// Show data, hide loading
				$loading.hide();
				$data.css('opacity', '1').show();

				// Scroll to preview only on initial load
				if (!isUpdate) {
					$preview[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				}
			}, loadingDelay);
		},

		/**
		 * Update preview header with quiz metadata.
		 *
		 * @param {Object} data Quiz data
		 */
		updatePreviewHeader: function(data) {
			const $data = $('.wp-mcp-ai-preview-data');
			
			// Update title
			$data.find('.wp-mcp-ai-preview-title').text(data.title || 'Untitled Quiz');

			// Update meta information
			const metaParts = [];
			if (data.difficulty) {
				metaParts.push('Difficulty: ' + data.difficulty);
			}
			if (data.questions && data.questions.length) {
				metaParts.push(data.questions.length + ' Question' + (data.questions.length !== 1 ? 's' : ''));
			}
			if (data.time_limit) {
				metaParts.push('Time: ' + data.time_limit + ' min');
			}
			if (data.pass_score) {
				metaParts.push('Pass: ' + data.pass_score + '%');
			}
			$data.find('.wp-mcp-ai-preview-meta').text(metaParts.join(' • '));
		},

		/**
		 * Render questions for current page.
		 */
		renderQuestions: function() {
			const data = this.previewState.quizData;
			const $questions = $('.wp-mcp-ai-preview-questions');
			
			if (!data || !data.questions || data.questions.length === 0) {
				$questions.html('<p class="wp-mcp-ai-preview-empty">No questions available yet.</p>');
				return;
			}

			// Calculate pagination
			const startIndex = (this.previewState.currentPage - 1) * this.previewState.questionsPerPage;
			const endIndex = Math.min(startIndex + this.previewState.questionsPerPage, data.questions.length);
			const questionsToShow = data.questions.slice(startIndex, endIndex);

			// Clear existing questions
			$questions.empty();

			// Render questions for current page
			questionsToShow.forEach((q, relativeIndex) => {
				const absoluteIndex = startIndex + relativeIndex;
				const $questionBlock = $('<div class="wp-mcp-ai-preview-question"></div>');
				
				// Question text
				const $questionText = $('<p class="wp-mcp-ai-preview-question-text"></p>');
				$questionText.html('<strong>' + (absoluteIndex + 1) + '.</strong> ' + this.escapeHtml(q.question));
				$questionBlock.append($questionText);

				// Options
				if (q.options && typeof q.options === 'object') {
					const $optionsList = $('<ul class="wp-mcp-ai-preview-options"></ul>');
					
					for (const key in q.options) {
						if (q.options.hasOwnProperty(key)) {
							const isCorrect = key === q.correct_answer;
							const $option = $('<li></li>');
							$option.html(
								'<strong>' + key + ')</strong> ' + 
								this.escapeHtml(q.options[key]) +
								(isCorrect ? ' <span class="wp-mcp-ai-correct-badge">✓</span>' : '')
							);
							if (isCorrect) {
								$option.addClass('wp-mcp-ai-correct-option');
							}
							$optionsList.append($option);
						}
					}
					
					$questionBlock.append($optionsList);
				}

				$questions.append($questionBlock);
			});
		},

		/**
		 * Update pagination controls.
		 */
		updatePagination: function() {
			const $pagination = $('.wp-mcp-ai-preview-pagination');
			const $prev = $('.wp-mcp-ai-preview-prev');
			const $next = $('.wp-mcp-ai-preview-next');
			const $currentPage = $('.wp-mcp-ai-preview-current-page');
			const $totalPages = $('.wp-mcp-ai-preview-total-pages');

			const totalPages = Math.ceil(this.previewState.totalQuestions / this.previewState.questionsPerPage);

			// Show pagination if more than one page
			if (totalPages > 1) {
				$pagination.show();
				
				// Update page numbers
				$currentPage.text(this.previewState.currentPage);
				$totalPages.text(totalPages);

				// Update button states
				$prev.prop('disabled', this.previewState.currentPage === 1);
				$next.prop('disabled', this.previewState.currentPage === totalPages);
			} else {
				$pagination.hide();
			}
		},

		/**
		 * Escape HTML for safe display.
		 *
		 * @param {string} text Text to escape
		 * @return {string} Escaped text
		 */
		escapeHtml: function(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
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
