/**
 * Displays Dashboard JavaScript
 *
 * Handles search and filtering functionality for the displays dashboard.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		/**
		 * Search functionality for widgets and blocks.
		 */
		const $searchInput = $('#wp-mcp-ai-search-displays');
		const $clearButton = $('#wp-mcp-ai-clear-search');
		const $cards = $('.wp-mcp-ai-widget-card, .wp-mcp-ai-block-card');
		const $categories = $('.wp-mcp-ai-widget-category, .wp-mcp-ai-block-category');

		/**
		 * Filter cards based on search query.
		 *
		 * @param {string} query Search query.
		 */
		function filterCards(query) {
			const searchQuery = query.toLowerCase().trim();

			if (searchQuery === '') {
				$cards.removeClass('hidden');
				$categories.show();
				return;
			}

			let visibleCount = 0;

			$cards.each(function() {
				const $card = $(this);
				const searchable = $card.data('searchable') || '';

				if (searchable.indexOf(searchQuery) !== -1) {
					$card.removeClass('hidden');
					visibleCount++;
				} else {
					$card.addClass('hidden');
				}
			});

			// Hide categories with no visible cards.
			$categories.each(function() {
				const $category = $(this);
				const hasVisibleCards = $category.find('.wp-mcp-ai-widget-card:not(.hidden), .wp-mcp-ai-block-card:not(.hidden)').length > 0;

				if (hasVisibleCards) {
					$category.show();
				} else {
					$category.hide();
				}
			});
		}

		// Search input event handler.
		$searchInput.on('input', function() {
			const query = $(this).val();
			filterCards(query);
		});

		// Clear search button event handler.
		$clearButton.on('click', function() {
			$searchInput.val('');
			filterCards('');
			$searchInput.focus();
		});

		/**
		 * Add keyboard shortcuts.
		 */
		$(document).on('keydown', function(e) {
			// Ctrl/Cmd + K to focus search.
			if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
				e.preventDefault();
				$searchInput.focus();
			}

			// Escape to clear search.
			if (e.key === 'Escape' && $searchInput.is(':focus')) {
				$searchInput.val('');
				filterCards('');
			}
		});

		/**
		 * Add tooltips to widget/block cards (optional enhancement).
		 */
		$cards.each(function() {
			const $card = $(this);
			const $title = $card.find('h4');

			// Add accessibility attributes.
			$card.attr('role', 'article');
			$card.attr('aria-label', $title.text());
		});

		/**
		 * Add visual feedback when cards are clicked (optional).
		 */
		$cards.on('click', function() {
			const $card = $(this);
			const slug = $card.find('code').text();

			// Flash the card to indicate it was clicked.
			$card.css({
				'background-color': '#f0f6fc',
				'border-color': '#2271b1'
			});

			setTimeout(function() {
				$card.css({
					'background-color': '',
					'border-color': ''
				});
			}, 200);

			// Copy slug to clipboard (optional feature).
			if (navigator.clipboard) {
				navigator.clipboard.writeText(slug).then(function() {
					// Show a temporary notification.
					const $notification = $('<div class="notice notice-success is-dismissible" style="position: fixed; top: 32px; right: 20px; z-index: 9999;"><p>Slug copied to clipboard: <code>' + slug + '</code></p></div>');
					$('body').append($notification);

					setTimeout(function() {
						$notification.fadeOut(function() {
							$(this).remove();
						});
					}, 2000);
				});
			}
		});

		/**
		 * Add category toggle functionality (collapse/expand).
		 */
		$('.wp-mcp-ai-widget-category h3, .wp-mcp-ai-block-category h3').each(function() {
			const $heading = $(this);
			const $category = $heading.parent();
			const $grid = $category.find('.wp-mcp-ai-widgets-grid, .wp-mcp-ai-blocks-grid');

			// Add toggle icon.
			$heading.css({
				'cursor': 'pointer',
				'user-select': 'none'
			}).prepend('<span class="dashicons dashicons-arrow-down-alt2" style="margin-right: 8px; transition: transform 0.2s;"></span>');

			// Toggle on click.
			$heading.on('click', function() {
				const $icon = $heading.find('.dashicons');

				if ($grid.is(':visible')) {
					$grid.slideUp(200);
					$icon.css('transform', 'rotate(-90deg)');
				} else {
					$grid.slideDown(200);
					$icon.css('transform', 'rotate(0deg)');
				}
			});
		});

		/**
		 * Add stats counter.
		 */
		function updateStats() {
			const totalWidgets = $('.wp-mcp-ai-widget-card').length;
			const totalBlocks = $('.wp-mcp-ai-block-card').length;
			const visibleWidgets = $('.wp-mcp-ai-widget-card:not(.hidden)').length;
			const visibleBlocks = $('.wp-mcp-ai-block-card:not(.hidden)').length;

			let statsHtml = '<div class="wp-mcp-ai-stats" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">';
			statsHtml += '<strong>Showing:</strong> ';
			statsHtml += visibleWidgets + ' of ' + totalWidgets + ' widgets, ';
			statsHtml += visibleBlocks + ' of ' + totalBlocks + ' blocks';
			statsHtml += '</div>';

			// Remove existing stats and add new ones.
			$('.wp-mcp-ai-stats').remove();
			$('.wp-mcp-ai-displays-filter').after(statsHtml);
		}

		// Update stats on page load.
		updateStats();

		// Update stats when search changes.
		$searchInput.on('input', function() {
			setTimeout(updateStats, 100);
		});

		$clearButton.on('click', function() {
			setTimeout(updateStats, 100);
		});
	});
})(jQuery);
