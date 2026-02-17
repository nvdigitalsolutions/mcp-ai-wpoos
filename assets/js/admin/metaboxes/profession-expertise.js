/**
 * Profession Expertise Metabox Script
 *
 * Handles expertise area management and tool selection functionality.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Expertise area management.
		$('#add-profession-expertise').on('click', function() {
			if (!window.wpMcpAiProfessionMetabox) {
				return;
			}
			const expertiseHtml = '<div class="profession-expertise-item" style="margin-bottom: 10px;">' +
				'<input type="text" name="profession_expertise[]" value="" class="large-text" />' +
				'<button type="button" class="button button-small remove-expertise">' + wpMcpAiProfessionMetabox.strings.remove + '</button>' +
				'</div>';
			$('#profession-expertise-list').append(expertiseHtml);
		});

		$(document).on('click', '.remove-expertise', function() {
			$(this).closest('.profession-expertise-item').remove();
		});

		// Tools management functionality.
		let searchDebounceTimer = null;
		const $toolsList = $('#profession-default-tools-list');
		const $noResultsMsg = $('#no-tools-found');
		const $searchInput = $('#profession-tools-search');
		const $selectedCount = $('#tools-selected-count');
		const $countNumber = $('#tools-count-number');

		// Update selected count.
		function updateSelectedCount() {
			const count = $('.profession-tool-checkbox:checked').length;

			if (!window.wpMcpAiProfessionMetabox) {
				return;
			}

			const recommendedCount = wpMcpAiProfessionMetabox.recommendedToolCount;
			let countColor = '#666'; // Default gray.

			if (count > recommendedCount + 5) {
				countColor = '#d63638'; // Red - too many.
			} else if (count >= recommendedCount - 2 && count <= recommendedCount + 2) {
				countColor = '#00a32a'; // Green - optimal.
			} else if (count < 3) {
				countColor = '#d63638'; // Red - too few.
			}

			$countNumber.text(count).css('color', countColor);
		}

		// Filter tools based on search term.
		function filterTools() {
			const searchTerm = $searchInput.val().toLowerCase().trim();
			const $toolItems = $('.profession-tool-item');
			let visibleCount = 0;

			$toolItems.each(function() {
				const $item = $(this);
				const toolName = $item.data('tool-name') || '';
				const toolDesc = $item.data('tool-description') || '';

				const matches = searchTerm === '' ||
					toolName.indexOf(searchTerm) !== -1 ||
					toolDesc.indexOf(searchTerm) !== -1;

				if (matches) {
					$item.show();
					visibleCount++;
				} else {
					$item.hide();
				}
			});

			// Toggle no results message.
			if (visibleCount === 0 && searchTerm !== '') {
				$toolsList.hide();
				$noResultsMsg.show();
			} else {
				$toolsList.show();
				$noResultsMsg.hide();
			}
		}

		// Search input handler with debounce.
		$searchInput.on('input', function() {
			clearTimeout(searchDebounceTimer);
			searchDebounceTimer = setTimeout(filterTools, 300);
		});

		// Clear search button.
		$('#clear-tools-search').on('click', function() {
			$searchInput.val('');
			filterTools();
			$searchInput.focus();
		});

		// Toggle all visible tools (used by Select All and Deselect All).
		function toggleAllVisibleTools(checked) {
			$('.profession-tool-item:visible .profession-tool-checkbox').prop('checked', checked);
			updateSelectedCount();
		}

		// Select all visible tools.
		$('#select-all-tools').on('click', function() {
			toggleAllVisibleTools(true);
		});

		// Deselect all visible tools.
		$('#deselect-all-tools').on('click', function() {
			toggleAllVisibleTools(false);
		});

		// Reset to initial state.
		$('#reset-tools').on('click', function() {
			if (!window.wpMcpAiProfessionMetabox) {
				return;
			}

			// Use native confirm as it's consistent with WordPress admin UX patterns.
			if (!confirm(wpMcpAiProfessionMetabox.strings.resetConfirm)) {
				return;
			}

			$('.profession-tool-item').each(function() {
				const $item = $(this);
				const $checkbox = $item.find('.profession-tool-checkbox');
				const initiallyChecked = $item.data('initially-checked') === 1;
				$checkbox.prop('checked', initiallyChecked);
			});
			updateSelectedCount();
		});

		// Update count when checkboxes change.
		$(document).on('change', '.profession-tool-checkbox', function() {
			updateSelectedCount();
		});

		// Initialize count on page load.
		updateSelectedCount();
	});

})(jQuery);
