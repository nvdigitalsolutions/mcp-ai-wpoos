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

    function initOllamaHandlers() {
        // Test Ollama connection
        $('#wp-mcp-ai-test-ollama-connection').on('click', function (e) {
            e.preventDefault();
            var $button = $(this);
            var $result = $('#wp-mcp-ai-ollama-test-result');
            var endpointUrl = $('input[name="wp_mcp_ai_settings[ollama_endpoint_url]"]').val();

            if (!endpointUrl) {
                $result.html('<span style="color: #d63638;">Please enter an endpoint URL first.</span>');
                return;
            }

            $button.prop('disabled', true).text('Testing...');
            $result.html('<span style="color: #3c434a;">Connecting...</span>');

            $.ajax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_test_ollama_connection',
                    nonce: wpMcpAiAdmin.nonce,
                    endpoint_url: endpointUrl
                },
                success: function (response) {
                    if (response.success) {
                        $result.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                    }
                },
                error: function () {
                    $result.html('<span style="color: #d63638;">✗ Connection failed</span>');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Test Connection');
                }
            });
        });

        // Fetch Ollama models
        $('#wp-mcp-ai-fetch-ollama-models').on('click', function (e) {
            e.preventDefault();
            var $button = $(this);
            var $modelsList = $('#wp-mcp-ai-ollama-models-list');
            var endpointUrl = $('input[name="wp_mcp_ai_settings[ollama_endpoint_url]"]').val();

            if (!endpointUrl) {
                $modelsList.html('<p style="color: #d63638;">Please enter an endpoint URL first.</p>');
                return;
            }

            $button.prop('disabled', true).text('Fetching...');
            $modelsList.html('<p>Loading models...</p>');

            $.ajax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_fetch_ollama_models',
                    nonce: wpMcpAiAdmin.nonce,
                    endpoint_url: endpointUrl
                },
                success: function (response) {
                    if (response.success && response.data.models.length > 0) {
                        var html = '<p><strong>Available models:</strong></p><ul style="list-style: disc; margin-left: 20px;">';
                        response.data.models.forEach(function (model) {
                            var sizeInfo = model.size ? ' (' + formatBytes(model.size) + ')' : '';
                            html += '<li style="margin-bottom: 5px;">';
                            html += '<a href="#" class="wp-mcp-ai-select-ollama-model" data-model="' + model.name + '">';
                            html += model.name + sizeInfo;
                            html += '</a>';
                            if (model.family) {
                                html += ' - ' + model.family;
                            }
                            html += '</li>';
                        });
                        html += '</ul>';
                        $modelsList.html(html);
                    } else if (response.success && response.data.models.length === 0) {
                        $modelsList.html('<p style="color: #d63638;">No models found. Make sure Ollama is running and has models installed.</p>');
                    } else {
                        $modelsList.html('<p style="color: #d63638;">Error: ' + response.data.message + '</p>');
                    }
                },
                error: function () {
                    $modelsList.html('<p style="color: #d63638;">Failed to fetch models</p>');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Fetch Models');
                }
            });
        });

        // Handle Ollama model selection
        $(document).on('click', '.wp-mcp-ai-select-ollama-model', function (e) {
            e.preventDefault();
            var modelName = $(this).data('model');
            $('input[name="wp_mcp_ai_settings[ollama_model]"]').val(modelName);
            $('#wp-mcp-ai-ollama-models-list').prepend('<p style="color: #00a32a; font-weight: bold;">Selected: ' + modelName + '</p>');
        });
    }

    function initLMStudioHandlers() {
        // Test LM Studio connection
        $('#wp-mcp-ai-test-lm-studio-connection').on('click', function (e) {
            e.preventDefault();
            var $button = $(this);
            var $result = $('#wp-mcp-ai-lm-studio-test-result');
            var endpointUrl = $('input[name="wp_mcp_ai_settings[lm_studio_endpoint_url]"]').val();

            if (!endpointUrl) {
                $result.html('<span style="color: #d63638;">Please enter an endpoint URL first.</span>');
                return;
            }

            $button.prop('disabled', true).text('Testing...');
            $result.html('<span style="color: #3c434a;">Connecting...</span>');

            $.ajax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_test_lm_studio_connection',
                    nonce: wpMcpAiAdmin.nonce,
                    endpoint_url: endpointUrl
                },
                success: function (response) {
                    if (response.success) {
                        $result.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                    }
                },
                error: function () {
                    $result.html('<span style="color: #d63638;">✗ Connection failed</span>');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Test Connection');
                }
            });
        });

        // Fetch LM Studio models
        $('#wp-mcp-ai-fetch-lm-studio-models').on('click', function (e) {
            e.preventDefault();
            var $button = $(this);
            var $modelsList = $('#wp-mcp-ai-lm-studio-models-list');
            var endpointUrl = $('input[name="wp_mcp_ai_settings[lm_studio_endpoint_url]"]').val();

            if (!endpointUrl) {
                $modelsList.html('<p style="color: #d63638;">Please enter an endpoint URL first.</p>');
                return;
            }

            $button.prop('disabled', true).text('Fetching...');
            $modelsList.html('<p>Loading models...</p>');

            $.ajax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_fetch_lm_studio_models',
                    nonce: wpMcpAiAdmin.nonce,
                    endpoint_url: endpointUrl
                },
                success: function (response) {
                    if (response.success && response.data.models.length > 0) {
                        var html = '<p><strong>Available models:</strong></p><ul style="list-style: disc; margin-left: 20px;">';
                        response.data.models.forEach(function (model) {
                            html += '<li style="margin-bottom: 5px;">';
                            html += '<a href="#" class="wp-mcp-ai-select-lm-studio-model" data-model="' + model.id + '">';
                            html += model.id;
                            html += '</a>';
                            html += '</li>';
                        });
                        html += '</ul>';
                        $modelsList.html(html);
                    } else if (response.success && response.data.models.length === 0) {
                        $modelsList.html('<p style="color: #d63638;">No models found. Make sure LM Studio server is running and has models loaded.</p>');
                    } else {
                        $modelsList.html('<p style="color: #d63638;">Error: ' + response.data.message + '</p>');
                    }
                },
                error: function () {
                    $modelsList.html('<p style="color: #d63638;">Failed to fetch models</p>');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Fetch Models');
                }
            });
        });

        // Handle LM Studio model selection
        $(document).on('click', '.wp-mcp-ai-select-lm-studio-model', function (e) {
            e.preventDefault();
            var modelName = $(this).data('model');
            $('input[name="wp_mcp_ai_settings[lm_studio_model]"]').val(modelName);
            $('#wp-mcp-ai-lm-studio-models-list').prepend('<p style="color: #00a32a; font-weight: bold;">Selected: ' + modelName + '</p>');
        });
    }

    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        var k = 1024;
        var dm = decimals < 0 ? 0 : decimals;
        var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    function initCloudwaysHandlers() {
        // Fetch Cloudways data
        $('#wp-mcp-ai-fetch-cloudways-data').on('click', function (e) {
            e.preventDefault();
            var $button = $(this);
            var $result = $('#wp-mcp-ai-cloudways-fetch-result');
            var $serversList = $('#wp-mcp-ai-cloudways-servers-list');
            var $appsList = $('#wp-mcp-ai-cloudways-apps-list');
            var email = $('input[name="wp_mcp_ai_settings[cloudways_email]"]').val();
            var apiKey = $('input[name="wp_mcp_ai_settings[cloudways_api_key]"]').val();

            if (!email || !apiKey) {
                $result.html('<span style="color: #d63638;">Please enter both email and API key first.</span>');
                return;
            }

            $button.prop('disabled', true).text('Fetching...');
            $result.html('<span style="color: #3c434a;">Connecting to Cloudways...</span>');
            $serversList.html('');
            $appsList.html('');

            $.ajax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_fetch_cloudways_data',
                    nonce: wpMcpAiAdmin.nonce,
                    email: email,
                    api_key: apiKey
                },
                success: function (response) {
                    if (response.success) {
                        $result.html('<span style="color: #00a32a;">✓ Successfully fetched Cloudways data</span>');

                        // Display servers
                        if (response.data.servers && response.data.servers.length > 0) {
                            var serversHtml = '<p><strong>Select a server:</strong></p><ul style="list-style: disc; margin-left: 20px;">';
                            response.data.servers.forEach(function (server) {
                                serversHtml += '<li style="margin-bottom: 5px;">';
                                serversHtml += '<a href="#" class="wp-mcp-ai-select-cloudways-server" data-server-id="' + server.id + '">';
                                serversHtml += server.label + ' (ID: ' + server.id + ', Status: ' + server.status + ')';
                                serversHtml += '</a>';
                                serversHtml += '</li>';
                            });
                            serversHtml += '</ul>';
                            $serversList.html(serversHtml);
                        }

                        // Display apps
                        if (response.data.apps && response.data.apps.length > 0) {
                            var appsHtml = '<p><strong>Select an application:</strong></p><ul style="list-style: disc; margin-left: 20px;">';
                            response.data.apps.forEach(function (app) {
                                appsHtml += '<li style="margin-bottom: 5px;">';
                                appsHtml += '<a href="#" class="wp-mcp-ai-select-cloudways-app" data-app-id="' + app.id + '" data-server-id="' + app.server_id + '">';
                                appsHtml += app.label + ' (ID: ' + app.id + ')';
                                appsHtml += '</a>';
                                appsHtml += '</li>';
                            });
                            appsHtml += '</ul>';
                            $appsList.html(appsHtml);
                        }
                    } else {
                        $result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                    }
                },
                error: function () {
                    $result.html('<span style="color: #d63638;">✗ Failed to connect to Cloudways</span>');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Fetch Cloudways Data');
                }
            });
        });

        // Handle server selection
        $(document).on('click', '.wp-mcp-ai-select-cloudways-server', function (e) {
            e.preventDefault();
            var serverId = $(this).data('server-id');
            $('input[name="wp_mcp_ai_settings[cloudways_server_id]"]').val(serverId);
            $('#wp-mcp-ai-cloudways-servers-list').prepend('<p style="color: #00a32a; font-weight: bold;">Selected Server ID: ' + serverId + '</p>');
        });

        // Handle app selection
        $(document).on('click', '.wp-mcp-ai-select-cloudways-app', function (e) {
            e.preventDefault();
            var appId = $(this).data('app-id');
            var serverId = $(this).data('server-id');
            $('input[name="wp_mcp_ai_settings[cloudways_app_id]"]').val(appId);
            $('input[name="wp_mcp_ai_settings[cloudways_server_id]"]').val(serverId);
            $('#wp-mcp-ai-cloudways-apps-list').prepend('<p style="color: #00a32a; font-weight: bold;">Selected App ID: ' + appId + ' (Server ID: ' + serverId + ')</p>');
        });
    }

    $(function () {
        initColorPickers();
        initOllamaHandlers();
        initLMStudioHandlers();
        initCloudwaysHandlers();
    });
})(jQuery);
