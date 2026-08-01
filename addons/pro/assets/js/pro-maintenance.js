/**
 * Pro Maintenance Admin JavaScript
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

(function ($) {
	'use strict';

	// Form validation: ensure end time is after start time.
	$('form').on('submit', function () {
		var $start = $('#maint-start');
		var $end = $('#maint-end');

		if ($start.length && $end.length) {
			var startVal = $start.val();
			var endVal = $end.val();

			if (startVal && endVal && endVal <= startVal) {
				alert('End time must be after start time.');
				return false;
			}
		}
	});

}(jQuery));
