/**
 * Product Research Page JavaScript
 *
 * Handles product research page functionality including chat interaction
 * and creating products from research results.
 *
 * @package WP_MCP_AI_Pro
 */

(function($) {
	'use strict';

	/**
	 * Product Research Page Manager
	 */
	const ProductResearchPage = {
		/**
		 * Initialize the product research page.
		 */
		init: function() {
			console.log('[Product Research Page] Initializing product research page');
			this.bindEvents();
			this.initPreview();
			console.log('[Product Research Page] Product research page initialized successfully');
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			console.log('[Product Research Page] Binding event handlers');
			
			// Example query buttons
			$(document).on('click', '.wp-mcp-ai-example-query', this.handleExampleQuery.bind(this));

			// Listen for CPT action events from chat UI
			console.log('[Product Research Page] Registering wp-mcp-ai-cpt-action event listener');
			document.addEventListener('wp-mcp-ai-cpt-action', this.handleCptActionNative.bind(this));

			// Listen for tool result storage events to update preview
			document.addEventListener('wp-mcp-ai-tool-result-stored', this.handleToolResultStoredNative.bind(this));
			
			console.log('[Product Research Page] Event handlers bound successfully');
		},

		/**
		 * Initialize preview panel.
		 */
		initPreview: function() {
			// Hide preview initially
			$('#wp-mcp-ai-product-preview').hide();
			
			// Initialize preview state
			this.previewState = {
				productData: null
			};
		},

		/**
		 * Handle CPT action event (native CustomEvent version).
		 *
		 * @param {CustomEvent} e Native custom event
		 */
		handleCptActionNative: function(e) {
			console.log('[Product Research Page] Received wp-mcp-ai-cpt-action event', e.detail);
			
			if (!e.detail) {
				console.warn('[Product Research Page] Event detail is missing');
				return;
			}

			const detail = e.detail;
			const action = detail.action;
			const conversation = detail.conversation;
			const button = detail.button;

			console.log('[Product Research Page] Processing action:', action);

			// Route to appropriate handler based on action type
			if (action === 'create_product') {
				this.handleCreateProductFromConversation(conversation, button);
			}
		},

		/**
		 * Handle tool result stored event (native CustomEvent version).
		 * Updates the sidebar preview when research tools complete.
		 *
		 * @param {CustomEvent} e Native custom event
		 */
		handleToolResultStoredNative: function(e) {
			if (!e.detail) {
				return;
			}

			const detail = e.detail;
			const toolName = detail.toolName;
			const result = detail.result;

			// Only handle research_product results
			if (toolName !== 'research_product') {
				return;
			}

			// Update preview with structured data
			this.updatePreview(result);
		},

		/**
		 * Update preview panel with product data.
		 *
		 * @param {Object} data Product data from research tool
		 */
		updatePreview: function(data) {
			const $preview = $('#wp-mcp-ai-product-preview');
			const $loading = $preview.find('.wp-mcp-ai-preview-loading');
			const $data = $preview.find('.wp-mcp-ai-preview-data');

			// Check if this is an update or initial load
			const isUpdate = $preview.is(':visible') && $data.is(':visible');

			// Show preview panel if hidden
			if (!$preview.is(':visible')) {
				$preview.slideDown(300);
			}

			// Validate data
			if (!data || typeof data !== 'object') {
				$loading.show();
				$loading.html('<p class="wp-mcp-ai-preview-error">Failed to load product data</p>');
				$data.hide();
				return;
			}

			// Store product data in state
			this.previewState.productData = data;

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
				
				// Render product details
				this.renderProductDetails(data);
				
				// Render images if available
				this.renderProductImages(data);

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
		 * Update preview header with product metadata.
		 *
		 * @param {Object} data Product data
		 */
		updatePreviewHeader: function(data) {
			const $data = $('.wp-mcp-ai-preview-data');
			
			// Update title
			const title = data.tool_arguments && data.tool_arguments.title 
				? data.tool_arguments.title 
				: data.query || 'Product Preview';
			$data.find('.wp-mcp-ai-preview-title').text(title);

			// Update meta information
			const metaParts = [];
			if (data.reference) {
				metaParts.push('SKU: ' + data.reference);
			}
			if (data.tool_arguments && data.tool_arguments.brand) {
				metaParts.push('Brand: ' + data.tool_arguments.brand);
			}
			
			$data.find('.wp-mcp-ai-preview-meta').text(metaParts.join(' • '));
		},

		/**
		 * Render product details.
		 *
		 * @param {Object} data Product data
		 */
		renderProductDetails: function(data) {
			const $details = $('.wp-mcp-ai-preview-details');
			$details.empty();

			if (!data.structure || typeof data.structure !== 'object') {
				$details.html('<p class="wp-mcp-ai-preview-empty">Product data structure not available.</p>');
				return;
			}

			// Show guidance and structure
			if (data.guidance) {
				const $guidance = $('<div class="wp-mcp-ai-product-guidance"></div>');
				$guidance.html('<strong>Research Guidance:</strong><pre>' + this.escapeHtml(data.guidance) + '</pre>');
				$details.append($guidance);
			}

			// Show next steps
			if (data.next_steps && Array.isArray(data.next_steps)) {
				const $steps = $('<div class="wp-mcp-ai-product-steps"></div>');
				$steps.append('<strong>Next Steps:</strong>');
				const $list = $('<ol></ol>');
				data.next_steps.forEach(function(step) {
					$list.append('<li>' + this.escapeHtml(step) + '</li>');
				}.bind(this));
				$steps.append($list);
				$details.append($steps);
			}
		},

		/**
		 * Render product images section.
		 *
		 * @param {Object} data Product data
		 */
		renderProductImages: function(data) {
			const $imagesSection = $('.wp-mcp-ai-preview-images');
			const $imageList = $('.wp-mcp-ai-preview-image-list');
			
			$imageList.empty();

			// Check if images are available in tool_arguments
			const images = data.tool_arguments && data.tool_arguments.image_urls;
			
			if (!images || !Array.isArray(images) || images.length === 0) {
				$imagesSection.hide();
				return;
			}

			$imagesSection.show();
			
			images.forEach(function(url) {
				const $img = $('<img>')
					.attr('src', url)
					.attr('alt', 'Product image')
					.addClass('wp-mcp-ai-preview-image')
					.css({
						maxWidth: '100%',
						height: 'auto',
						marginBottom: '10px',
						borderRadius: '4px'
					});
				$imageList.append($img);
			});
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
		 * Handle product creation from conversation data.
		 *
		 * @param {Object} conversation Extracted conversation data
		 * @param {HTMLElement} button The button element
		 */
		handleCreateProductFromConversation: function(conversation, button) {
			console.log('[Product Research Page] handleCreateProductFromConversation called');
			
			if (!conversation) {
				console.error('[Product Research Page] No conversation data provided');
				this.showError(wpMcpAiProductResearchPage.strings.error);
				return;
			}

			console.log('[Product Research Page] Conversation received:', {
				hasToolResults: !!(conversation.toolResults),
				toolResultKeys: conversation.toolResults ? Object.keys(conversation.toolResults) : [],
				hasLastAssistantMessage: !!(conversation.lastAssistantMessage),
				hasFullText: !!(conversation.fullText),
				messageCount: (conversation.messages || []).length
			});

			// Check if we have structured data from research_product tool
			let researchData = null;
			if (conversation.toolResults && conversation.toolResults.research_product) {
				console.log('[Product Research Page] Found research_product tool result');
				researchData = conversation.toolResults.research_product;
				
				// Use tool_arguments as the base for product creation
				if (researchData.tool_arguments) {
					this.createProductFromData(researchData.tool_arguments, button);
					return;
				}
			}

			// Fallback: extract from conversation text
			console.log('[Product Research Page] No research data found, showing error');
			this.showError(wpMcpAiProductResearchPage.strings.error);
		},

		/**
		 * Create product from research data.
		 *
		 * @param {Object} productData Product data
		 * @param {HTMLElement} button Optional button element
		 */
		createProductFromData: function(productData, button) {
			if (button) {
				button.disabled = true;
				button.textContent = wpMcpAiProductResearchPage.strings.creating;
			}

			$.ajax({
				url: wpMcpAiProductResearchPage.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_create_product_from_research',
					nonce: wpMcpAiProductResearchPage.nonce,
					research_data: JSON.stringify(productData)
				},
				success: (response) => {
					if (response.success) {
						this.showSuccess(response.data.message);
						
						// Redirect to edit page if available
						if (response.data.edit_url) {
							setTimeout(() => {
								window.location.href = response.data.edit_url;
							}, 1500);
						}
					} else {
						this.showError(response.data.message || wpMcpAiProductResearchPage.strings.error);
					}
				},
				error: () => {
					this.showError(wpMcpAiProductResearchPage.strings.error);
				},
				complete: () => {
					if (button) {
						button.disabled = false;
						button.textContent = 'Create Product';
					}
				}
			});
		},

		/**
		 * Show success message.
		 *
		 * @param {string} message Success message
		 */
		showSuccess: function(message) {
			const $status = $('.wp-mcp-ai-chat__status');
			if ($status.length) {
				$status.html('<div class="wp-mcp-ai-success-message"><p>' + message + '</p></div>');
				setTimeout(() => {
					$status.empty();
				}, 5000);
			}
		},

		/**
		 * Show error message.
		 *
		 * @param {string} message Error message
		 */
		showError: function(message) {
			const $status = $('.wp-mcp-ai-chat__status');
			if ($status.length) {
				$status.html('<div class="wp-mcp-ai-error-message"><p>' + message + '</p></div>');
			}
		}
	};

	// Initialize when DOM is ready
	$(document).ready(function() {
		// Only initialize if we're on the product research page
		if ($('.wp-mcp-ai-research-page').length && $('#wp-mcp-ai-product-preview').length) {
			ProductResearchPage.init();
		}
	});

})(jQuery);
