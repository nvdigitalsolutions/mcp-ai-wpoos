(function ($) {
    function initColorPickers() {
        $('.wp-mcp-ai-color-field').each(function () {
            var $field = $(this);
            var format = ($field.data('format') || 'hex').toString().toLowerCase();

            if ('rgba' === format) {
                return;
            }

            if (typeof $field.wpColorPicker === 'function') {
                $field.wpColorPicker({
                    defaultColor: $field.data('default-color') || false,
                    change: function (event, ui) {
                        $field.val(ui.color.toString());
                    },
                    clear: function () {
                        $field.val('');
                    }
                });
            }
        });
    }

    $(initColorPickers);
})(jQuery);
