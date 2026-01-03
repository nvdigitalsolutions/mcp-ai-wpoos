/**
 * NV oOS Accessibility & UX Enhancements (Phase 3)
 * 
 * JavaScript for:
 * - Keyboard navigation and focus management
 * - ARIA live region announcements
 * - Loading state management
 * - Modal focus trapping
 * - Skip link functionality
 * 
 * @package WP_MCP_AI
 * @since 1.0.0
 */

(function($) {
	'use strict';

	/**
	 * Accessibility Manager
	 */
	const WPMCPAccessibility = {
		
		/**
		 * Initialize all accessibility features
		 */
		init: function() {
			this.initSkipLinks();
			this.initFocusManagement();
			this.initKeyboardNavigation();
			this.initARIALiveRegions();
			this.initLoadingStates();
			this.initModalFocusTraps();
			this.initSortableHeaders();
			this.initReducedMotion();
			
			console.log('[WP MCP AI] Accessibility enhancements initialized');
		},
		
		/**
		 * Skip to Content Links
		 */
		initSkipLinks: function() {
			// Add skip link if not exists
			if ($('.wp-mcp-ai-skip-link').length === 0) {
				const skipLink = $('<a>')
					.attr({
						'href': '#wp-mcp-ai-main-content',
						'class': 'wp-mcp-ai-skip-link'
					})
					.text('Skip to main content');
				
				$('body').prepend(skipLink);
			}
			
			// Add ID to main content if not exists
			const mainContent = $('.wp-mcp-ai-dashboard, .wp-mcp-ai-tools-manager, .wp-mcp-ai-token-manager').first();
			if (mainContent.length && !mainContent.attr('id')) {
				mainContent.attr('id', 'wp-mcp-ai-main-content');
			}
		},
		
		/**
		 * Focus Management for Dynamic Content
		 */
		initFocusManagement: function() {
			const self = this;
			
			// When content is loaded via AJAX, manage focus
			$(document).on('wp-mcp-ai-content-loaded', function(e, $container) {
				self.announceLiveRegion('Content loaded');
				
				// Focus on the first heading or focusable element
				const firstHeading = $container.find('h2, h3').first();
				if (firstHeading.length) {
					firstHeading.attr('tabindex', '-1').focus();
				}
			});
			
			// Restore focus after modal closes
			$(document).on('wp-mcp-ai-modal-closed', function(e, previousFocus) {
				if (previousFocus && previousFocus.length) {
					previousFocus.focus();
				}
			});
		},
		
		/**
		 * Keyboard Navigation Enhancements
		 */
		initKeyboardNavigation: function() {
			const self = this;
			
			// Arrow key navigation for tabs
			$('.nav-tab-wrapper').on('keydown', '.nav-tab', function(e) {
				const $tabs = $(this).parent().find('.nav-tab');
				const currentIndex = $tabs.index(this);
				let nextIndex;
				
				switch(e.key) {
					case 'ArrowRight':
					case 'ArrowDown':
						e.preventDefault();
						nextIndex = (currentIndex + 1) % $tabs.length;
						$tabs.eq(nextIndex).focus();
						break;
					case 'ArrowLeft':
					case 'ArrowUp':
						e.preventDefault();
						nextIndex = (currentIndex - 1 + $tabs.length) % $tabs.length;
						$tabs.eq(nextIndex).focus();
						break;
					case 'Home':
						e.preventDefault();
						$tabs.first().focus();
						break;
					case 'End':
						e.preventDefault();
						$tabs.last().focus();
						break;
				}
			});
			
			// Escape key to close modals
			$(document).on('keydown', function(e) {
				if (e.key === 'Escape') {
					const $openModal = $('.wp-mcp-ai-modal--active, .wp-mcp-ai-test-modal--active');
					if ($openModal.length) {
						e.preventDefault();
						$openModal.find('.wp-mcp-ai-modal__close, .wp-mcp-ai-test-modal__close').first().click();
					}
				}
			});
			
			// Add keyboard shortcuts
			this.initKeyboardShortcuts();
		},
		
		/**
		 * Keyboard Shortcuts
		 */
		initKeyboardShortcuts: function() {
			const shortcuts = {
				'ctrl+k': function() {
					// Focus on search
					$('#tool_search, input[type="search"]').first().focus();
				},
				'ctrl+/': function() {
					// Show keyboard shortcuts help
					alert('Keyboard Shortcuts:\nCtrl+K: Focus search\nCtrl+/: Show this help\nEsc: Close modal\nTab: Navigate forward\nShift+Tab: Navigate backward');
				}
			};
			
			$(document).on('keydown', function(e) {
				const key = [];
				if (e.ctrlKey) key.push('ctrl');
				if (e.shiftKey) key.push('shift');
				if (e.altKey) key.push('alt');
				key.push(e.key.toLowerCase());
				
				const shortcut = key.join('+');
				if (shortcuts[shortcut]) {
					e.preventDefault();
					shortcuts[shortcut]();
				}
			});
		},
		
		/**
		 * ARIA Live Regions for Dynamic Announcements
		 */
		initARIALiveRegions: function() {
			// Create live region if not exists
			if ($('#wp-mcp-ai-aria-live').length === 0) {
				$('<div>')
					.attr({
						'id': 'wp-mcp-ai-aria-live',
						'class': 'wp-mcp-ai-aria-live',
						'aria-live': 'polite',
						'aria-atomic': 'true'
					})
					.appendTo('body');
			}
			
			// Create assertive live region for urgent announcements
			if ($('#wp-mcp-ai-aria-live-assertive').length === 0) {
				$('<div>')
					.attr({
						'id': 'wp-mcp-ai-aria-live-assertive',
						'class': 'wp-mcp-ai-aria-live',
						'aria-live': 'assertive',
						'aria-atomic': 'true'
					})
					.appendTo('body');
			}
		},
		
		/**
		 * Announce message to screen readers
		 */
		announceLiveRegion: function(message, assertive) {
			const regionId = assertive ? '#wp-mcp-ai-aria-live-assertive' : '#wp-mcp-ai-aria-live';
			const $region = $(regionId);
			
			if ($region.length) {
				// Clear and set new message
				$region.text('');
				setTimeout(function() {
					$region.text(message);
				}, 100);
				
				// Clear after 5 seconds
				setTimeout(function() {
					$region.text('');
				}, 5000);
			}
		},
		
		/**
		 * Loading States Management
		 */
		initLoadingStates: function() {
			const self = this;
			
			/**
			 * Show skeleton loader for charts
			 */
			window.wpMcpAiShowChartSkeleton = function($chartContainer) {
				if (!$chartContainer.hasClass('wp-mcp-ai-chart-loading')) {
					$chartContainer.addClass('wp-mcp-ai-chart-loading');
					
					const $skeleton = $('<div>')
						.addClass('wp-mcp-ai-skeleton wp-mcp-ai-skeleton--chart')
						.attr('aria-label', 'Loading chart data');
					
					$chartContainer.prepend($skeleton);
				}
			};
			
			/**
			 * Hide skeleton loader
			 */
			window.wpMcpAiHideChartSkeleton = function($chartContainer) {
				$chartContainer.addClass('loaded');
				setTimeout(function() {
					$chartContainer.removeClass('wp-mcp-ai-chart-loading loaded');
					$chartContainer.find('.wp-mcp-ai-skeleton--chart').remove();
				}, 300);
			};
			
			/**
			 * Show loading overlay
			 */
			window.wpMcpAiShowLoadingOverlay = function($container, message) {
				message = message || 'Loading...';
				
				const $overlay = $('<div>')
					.addClass('wp-mcp-ai-loading-overlay')
					.attr('role', 'status')
					.html(
						'<div class="wp-mcp-ai-spinner wp-mcp-ai-spinner--large" aria-hidden="true"></div>' +
						'<div class="wp-mcp-ai-loading-overlay__message">' + message + '</div>' +
						'<span class="wp-mcp-ai-sr-only">' + message + '</span>'
					);
				
				$container.css('position', 'relative').append($overlay);
				self.announceLiveRegion(message);
			};
			
			/**
			 * Hide loading overlay
			 */
			window.wpMcpAiHideLoadingOverlay = function($container) {
				$container.find('.wp-mcp-ai-loading-overlay').fadeOut(200, function() {
					$(this).remove();
				});
				self.announceLiveRegion('Loading complete');
			};
			
			/**
			 * Show progress bar
			 */
			window.wpMcpAiShowProgress = function($container, options) {
				options = $.extend({
					label: 'Processing...',
					percentage: 0,
					showPercentage: true
				}, options);
				
				const $wrapper = $('<div>').addClass('wp-mcp-ai-progress-wrapper');
				
				const $info = $('<div>')
					.addClass('wp-mcp-ai-progress-info')
					.html(
						'<span class="wp-mcp-ai-progress-info__label">' + options.label + '</span>' +
						(options.showPercentage ? '<span class="wp-mcp-ai-progress-info__percentage">0%</span>' : '')
					);
				
				const $progress = $('<div>')
					.addClass('wp-mcp-ai-progress')
					.attr('role', 'progressbar')
					.attr('aria-valuemin', '0')
					.attr('aria-valuemax', '100')
					.attr('aria-valuenow', options.percentage)
					.html('<div class="wp-mcp-ai-progress__bar" style="width: ' + options.percentage + '%"></div>');
				
				$wrapper.append($info, $progress);
				$container.append($wrapper);
				
				return $wrapper;
			};
			
			/**
			 * Update progress bar
			 */
			window.wpMcpAiUpdateProgress = function($wrapper, percentage) {
				percentage = Math.min(100, Math.max(0, percentage));
				
				$wrapper.find('.wp-mcp-ai-progress').attr('aria-valuenow', percentage);
				$wrapper.find('.wp-mcp-ai-progress__bar').css('width', percentage + '%');
				$wrapper.find('.wp-mcp-ai-progress-info__percentage').text(percentage + '%');
				
				if (percentage === 100) {
					self.announceLiveRegion('Process complete');
				}
			};
			
			// Apply to existing filter buttons
			$('#wp-mcp-ai-filter-tools').on('click', function() {
				const $button = $(this);
				const $icon = $button.find('.dashicons');
				
				$button.prop('disabled', true).addClass('is-loading');
				
				// Add screen reader announcement
				self.announceLiveRegion('Filtering tools...');
			});
		},
		
		/**
		 * Modal Focus Trap
		 */
		initModalFocusTraps: function() {
			const self = this;
			
			// Track the element that opened the modal
			let previousFocus = null;
			
			// When modal opens
			$(document).on('wp-mcp-ai-modal-open', function(e, $modal) {
				previousFocus = $(document.activeElement);
				
				const $focusableElements = $modal.find(
					'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
				);
				
				if ($focusableElements.length) {
					// Focus on first element (usually close button or first input)
					$focusableElements.first().focus();
					
					// Trap focus within modal
					$modal.on('keydown.focus-trap', function(e) {
						if (e.key === 'Tab') {
							const firstElement = $focusableElements.first()[0];
							const lastElement = $focusableElements.last()[0];
							
							if (e.shiftKey) {
								// Shift + Tab
								if (document.activeElement === firstElement) {
									e.preventDefault();
									lastElement.focus();
								}
							} else {
								// Tab
								if (document.activeElement === lastElement) {
									e.preventDefault();
									firstElement.focus();
								}
							}
						}
					});
				}
				
				// Add ARIA attributes
				$modal.attr('role', 'dialog').attr('aria-modal', 'true');
				
				// Announce modal opened
				const modalTitle = $modal.find('h2, h3').first().text() || 'Dialog opened';
				self.announceLiveRegion(modalTitle, true);
			});
			
			// When modal closes
			$(document).on('wp-mcp-ai-modal-close', function(e, $modal) {
				$modal.off('keydown.focus-trap');
				
				// Restore focus
				if (previousFocus && previousFocus.length) {
					previousFocus.focus();
				}
				
				$(document).trigger('wp-mcp-ai-modal-closed', [previousFocus]);
			});
			
			// Hook into existing modal open/close
			$('.wp-mcp-ai-modal, .wp-mcp-ai-test-modal').each(function() {
				const $modal = $(this);
				
				// When modal becomes active
				const observer = new MutationObserver(function(mutations) {
					mutations.forEach(function(mutation) {
						if (mutation.attributeName === 'class') {
							if ($modal.hasClass('wp-mcp-ai-modal--active') || $modal.hasClass('wp-mcp-ai-test-modal--active')) {
								$(document).trigger('wp-mcp-ai-modal-open', [$modal]);
							} else {
								$(document).trigger('wp-mcp-ai-modal-close', [$modal]);
							}
						}
					});
				});
				
				observer.observe(this, { attributes: true, attributeFilter: ['class'] });
			});
		},
		
		/**
		 * Sortable Table Headers
		 */
		initSortableHeaders: function() {
			const self = this;
			
			$('.wp-mcp-ai-sortable-header').each(function() {
				const $header = $(this);
				
				// Add ARIA attributes
				if (!$header.attr('aria-sort')) {
					$header.attr('aria-sort', 'none');
				}
				
				// Add keyboard support
				if (!$header.attr('tabindex')) {
					$header.attr('tabindex', '0').attr('role', 'button');
				}
				
				// Handle click and Enter/Space
				$header.on('click keydown', function(e) {
					if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
						return;
					}
					
					e.preventDefault();
					
					const currentSort = $header.attr('aria-sort');
					const newSort = currentSort === 'ascending' ? 'descending' : 'ascending';
					
					// Reset other headers
					$('.wp-mcp-ai-sortable-header').attr('aria-sort', 'none');
					
					// Set new sort
					$header.attr('aria-sort', newSort);
					
					// Announce to screen readers
					const columnName = $header.text().trim();
					self.announceLiveRegion(
						'Sorted ' + columnName + ' ' + newSort
					);
				});
			});
		},
		
		/**
		 * Reduced Motion Detection
		 */
		initReducedMotion: function() {
			const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
			
			if (prefersReducedMotion.matches) {
				$('body').addClass('wp-mcp-ai-reduced-motion');
				console.log('[WP MCP AI] Reduced motion mode enabled');
			}
			
			// Listen for changes
			prefersReducedMotion.addEventListener('change', function() {
				if (prefersReducedMotion.matches) {
					$('body').addClass('wp-mcp-ai-reduced-motion');
				} else {
					$('body').removeClass('wp-mcp-ai-reduced-motion');
				}
			});
		}
	};
	
	/**
	 * Initialize on document ready
	 */
	$(document).ready(function() {
		WPMCPAccessibility.init();
	});
	
	/**
	 * Expose to global scope
	 */
	window.WPMCPAccessibility = WPMCPAccessibility;
	
})(jQuery);
