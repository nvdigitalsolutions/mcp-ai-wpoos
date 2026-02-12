/**
 * AI Quick Actions Widget JavaScript
 *
 * Handles client-side functionality for the Quick Actions widget.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * AI Quick Actions Widget Handler
	 */
	class WPMCPAIQuickActionsWidget {
		/**
		 * Constructor
		 *
		 * @param {jQuery} $element Widget element
		 */
		constructor($element) {
			this.$element = $element;
			this.$categorySelect = $element.find('.wp-mcp-ai-category-select');
			this.$toolsContainer = $element.find('.wp-mcp-ai-tools-container');
			this.$fileInput = $element.find('.wp-mcp-ai-file-input');
			this.$filePreview = $element.find('.wp-mcp-ai-file-preview');
			this.$previewImage = $element.find('.wp-mcp-ai-preview-image');
			this.$progress = $element.find('.wp-mcp-ai-progress');
			this.$progressMessage = $element.find('.wp-mcp-ai-progress-message');
			this.$resultPreview = $element.find('.wp-mcp-ai-result-preview');
			this.$resultContent = $element.find('.wp-mcp-ai-result-content');
			this.$successMessage = $element.find('.wp-mcp-ai-success-message');
			this.$errorMessage = $element.find('.wp-mcp-ai-error-message');
			
			this.selectedTool = null;
			this.attachedFile = null;
			this.mediaFrame = null;
			this.currentRequest = null;
			
			this.init();
		}

		/**
		 * Initialize widget
		 */
		init() {
			this.bindEvents();
			this.filterTools();
		}

		/**
		 * Bind event handlers
		 */
		bindEvents() {
			// Category selection
			this.$categorySelect.on('change', () => this.filterTools());
			
			// Tool button click
			this.$toolsContainer.on('click', '.wp-mcp-ai-quick-action-btn', (e) => {
				this.handleToolClick($(e.currentTarget));
			});
			
			// File upload
			this.$fileInput.on('change', (e) => this.handleFileUpload(e));
			
			// Media library button
			this.$element.find('.wp-mcp-ai-media-library-btn').on('click', () => {
				this.openMediaLibrary();
			});
			
			// Remove file
			this.$element.find('.wp-mcp-ai-remove-file').on('click', () => {
				this.removeFile();
			});
			
			// Result actions
			this.$element.find('.wp-mcp-ai-apply-result').on('click', () => {
				this.applyResult();
			});
			
			this.$element.find('.wp-mcp-ai-regenerate-result').on('click', () => {
				this.regenerateResult();
			});
			
			this.$element.find('.wp-mcp-ai-cancel-result').on('click', () => {
				this.cancelResult();
			});
		}

		/**
		 * Filter tools by category
		 */
		filterTools() {
			const selectedCategory = this.$categorySelect.val();
			const $categories = this.$toolsContainer.find('.wp-mcp-ai-tools-category');
			
			if (!selectedCategory) {
				$categories.show();
			} else {
				$categories.hide();
				$categories.filter('[data-category="' + selectedCategory + '"]').show();
			}
		}

		/**
		 * Handle tool button click
		 *
		 * @param {jQuery} $button Clicked button
		 */
		handleToolClick($button) {
			this.selectedTool = $button.data('tool');
			const toolName = $button.find('.wp-mcp-ai-tool-name').text();
			
			// Check if tool requires a file
			const requiresFile = this.toolRequiresFile(this.selectedTool);
			
			if (requiresFile && !this.attachedFile) {
				this.showError('Please upload or select a file first.');
				return;
			}
			
			// Execute the tool
			this.executeTool(this.selectedTool, toolName);
		}

		/**
		 * Check if tool requires a file input
		 *
		 * @param {string} toolSlug Tool slug
		 * @return {boolean} True if file required
		 */
		toolRequiresFile(toolSlug) {
			// Tools that typically require files
			const fileTools = [
				'analyze_image',
				'edit_openai_image',
				'edit_gemini_image',
				'crop_image',
				'resize_image',
				'rotate_image',
				'convert_image_format',
				'extract_image_text',
				'generate_image_alt_text',
				'generate_image_caption',
				'transcribe_openai_audio',
				'analyze_video'
			];
			
			return fileTools.some(tool => toolSlug.includes(tool));
		}

		/**
		 * Handle file upload
		 *
		 * @param {Event} e Change event
		 */
		handleFileUpload(e) {
			const file = e.target.files[0];
			if (!file) {
				return;
			}
			
			this.attachedFile = file;
			this.displayFilePreview(file);
			this.$element.find('.wp-mcp-ai-filename').text(file.name);
		}

		/**
		 * Display file preview
		 *
		 * @param {File} file File object
		 */
		displayFilePreview(file) {
			if (file.type.startsWith('image/')) {
				const reader = new FileReader();
				reader.onload = (e) => {
					this.$previewImage.attr('src', e.target.result);
					this.$filePreview.show();
				};
				reader.readAsDataURL(file);
			} else {
				this.$filePreview.hide();
			}
		}

		/**
		 * Open WordPress media library
		 */
		openMediaLibrary() {
			// Create media frame if not exists
			if (!this.mediaFrame) {
				this.mediaFrame = wp.media({
					title: 'Select Media',
					button: {
						text: 'Use this media'
					},
					multiple: false
				});
				
				// Handle selection
				this.mediaFrame.on('select', () => {
					const attachment = this.mediaFrame.state().get('selection').first().toJSON();
					this.attachMediaFile(attachment);
				});
			}
			
			this.mediaFrame.open();
		}

		/**
		 * Attach file from media library
		 *
		 * @param {Object} attachment Attachment object
		 */
		attachMediaFile(attachment) {
			this.attachedFile = {
				id: attachment.id,
				url: attachment.url,
				type: attachment.type,
				filename: attachment.filename
			};
			
			if (attachment.type === 'image') {
				this.$previewImage.attr('src', attachment.url);
				this.$filePreview.show();
			}
			
			this.$element.find('.wp-mcp-ai-filename').text(attachment.filename);
		}

		/**
		 * Remove attached file
		 */
		removeFile() {
			this.attachedFile = null;
			this.$fileInput.val('');
			this.$filePreview.hide();
			this.$element.find('.wp-mcp-ai-filename').text('');
		}

		/**
		 * Execute tool
		 *
		 * @param {string} toolSlug Tool slug
		 * @param {string} toolName Tool display name
		 */
		executeTool(toolSlug, toolName) {
			this.hideMessages();
			this.showProgress('Executing ' + toolName + '…');
			
			// Prepare request data
			const formData = new FormData();
			formData.append('action', 'wp_mcp_ai_execute_quick_action');
			formData.append('nonce', wpMcpAiQuickActions.nonce);
			formData.append('tool', toolSlug);
			
			if (this.attachedFile) {
				if (this.attachedFile instanceof File) {
					formData.append('file', this.attachedFile);
				} else {
					formData.append('media_id', this.attachedFile.id);
				}
			}
			
			// Execute via AJAX
			this.currentRequest = $.ajax({
				url: wpMcpAiQuickActions.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: (response) => this.handleToolResponse(response, toolName),
				error: (xhr) => this.handleToolError(xhr, toolName)
			});
		}

		/**
		 * Handle successful tool response
		 *
		 * @param {Object} response Response data
		 * @param {string} toolName Tool name
		 */
		handleToolResponse(response, toolName) {
			this.hideProgress();
			
			if (!response.success) {
				this.showError(response.data || 'Tool execution failed.');
				return;
			}
			
			// Display result preview
			this.showResultPreview(response.data, toolName);
		}

		/**
		 * Handle tool error
		 *
		 * @param {Object} xhr XHR object
		 * @param {string} toolName Tool name
		 */
		handleToolError(xhr, toolName) {
			this.hideProgress();
			
			let errorMessage = 'An error occurred while executing ' + toolName + '.';
			if (xhr.responseJSON && xhr.responseJSON.data) {
				errorMessage = xhr.responseJSON.data;
			}
			
			this.showError(errorMessage);
		}

		/**
		 * Show result preview
		 *
		 * @param {Object} result Result data
		 * @param {string} toolName Tool name
		 */
		showResultPreview(result, toolName) {
			// Format result for display
			let content = '';
			
			if (result.image_url) {
				content += '<img src="' + result.image_url + '" alt="Generated image" style="max-width: 100%; height: auto;" />';
			}
			
			if (result.text) {
				content += '<div class="result-text">' + this.escapeHtml(result.text) + '</div>';
			}
			
			if (result.content) {
				content += '<div class="result-content">' + result.content + '</div>';
			}
			
			if (!content && result.message) {
				content = '<p>' + this.escapeHtml(result.message) + '</p>';
			}
			
			this.$resultContent.html(content);
			this.$resultPreview.show();
			
			// Store result for later use
			this.currentResult = result;
		}

		/**
		 * Apply result
		 */
		applyResult() {
			if (!this.currentResult) {
				return;
			}
			
			this.hideMessages();
			this.$resultPreview.hide();
			
			// Show success message
			this.showSuccess('Result applied successfully!');
			
			// Clear current state
			this.currentResult = null;
			this.removeFile();
		}

		/**
		 * Regenerate result
		 */
		regenerateResult() {
			this.$resultPreview.hide();
			const toolName = this.$toolsContainer.find('[data-tool="' + this.selectedTool + '"]')
				.find('.wp-mcp-ai-tool-name').text();
			this.executeTool(this.selectedTool, toolName);
		}

		/**
		 * Cancel result
		 */
		cancelResult() {
			this.$resultPreview.hide();
			this.currentResult = null;
		}

		/**
		 * Show progress indicator
		 *
		 * @param {string} message Progress message
		 */
		showProgress(message) {
			this.$progressMessage.text(message);
			this.$progress.show();
		}

		/**
		 * Hide progress indicator
		 */
		hideProgress() {
			this.$progress.hide();
		}

		/**
		 * Show success message
		 *
		 * @param {string} message Success message
		 */
		showSuccess(message) {
			this.$successMessage.find('.wp-mcp-ai-success-text').text(message);
			this.$successMessage.show();
			
			setTimeout(() => {
				this.$successMessage.fadeOut();
			}, 3000);
		}

		/**
		 * Show error message
		 *
		 * @param {string} message Error message
		 */
		showError(message) {
			this.$errorMessage.find('.wp-mcp-ai-error-text').text(message);
			this.$errorMessage.show();
			
			setTimeout(() => {
				this.$errorMessage.fadeOut();
			}, 5000);
		}

		/**
		 * Hide all messages
		 */
		hideMessages() {
			this.$successMessage.hide();
			this.$errorMessage.hide();
		}

		/**
		 * Escape HTML for safe display
		 *
		 * @param {string} text Text to escape
		 * @return {string} Escaped text
		 */
		escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	}

	/**
	 * Initialize widgets on page load
	 */
	$(function() {
		$('.wp-mcp-ai-quick-actions-widget').each(function() {
			new WPMCPAIQuickActionsWidget($(this));
		});
	});

	/**
	 * Initialize widgets in Elementor editor
	 */
	if (typeof elementorFrontend !== 'undefined') {
		$(window).on('elementor/frontend/init', function() {
			elementorFrontend.hooks.addAction('frontend/element_ready/wp_mcp_ai_quick_actions.default', function($scope) {
				new WPMCPAIQuickActionsWidget($scope.find('.wp-mcp-ai-quick-actions-widget'));
			});
		});
	}

})(jQuery);
