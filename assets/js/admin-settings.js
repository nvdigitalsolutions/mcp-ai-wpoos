/**
 * Save expanded section state to localStorage.
 * Called by the expand/collapse all buttons.
 * Note: Individual section clicks use their own localStorage logic (line 520).
 */
window.wpMcpAiSaveExpandedState = function() {
    const sections = document.querySelectorAll('.wp-mcp-ai-section--expanded');
    const expandedIds = Array.from(sections)
        .map(function(section) { return section.getAttribute('id'); })
        .filter(function(id) { return id; });
    
    try {
        localStorage.setItem('wp_mcp_ai_expanded_sections', JSON.stringify(expandedIds));
    } catch (e) {
        // Ignore localStorage errors
    }
};

(function ($) {
    'use strict';
    
    // Debug mode - set to false in production
    const DEBUG = false;
    
    function log(message, data) {
        if (DEBUG && console && console.log) {
            if (data !== undefined) {
                console.log('[WP oOS] ' + message, data);
            } else {
                console.log('[WP oOS] ' + message);
            }
        }
    }
    
    function initColorPickers() {
        log('Initializing color pickers...');
        $('.wp-mcp-ai-color-field').each(function () {
            const $field = $(this);
            const format = ($field.data('format') || 'hex').toString().toLowerCase();

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
        log('Color pickers initialized');
    }

    function initOllamaHandlers() {
        log('Initializing Ollama handlers...');
        const $button = $('#wp-mcp-ai-test-ollama-connection');
        
        if ($button.length === 0) {
            log('Ollama test button not found on this page');
            return;
        }
        
        // Test Ollama connection
        $button.on('click', function (e) {
            e.preventDefault();
            log('Ollama test button clicked');
            
            const $btn = $(this);
            const $result = $('#wp-mcp-ai-ollama-test-result');
            const endpointUrl = $('input[name="wp_mcp_ai_settings[ollama_endpoint_url]"]').val();

            if (!endpointUrl) {
                $result.html('<span style="color: #d63638;">Please enter an endpoint URL first.</span>');
                return;
            }

            $btn.prop('disabled', true).text('Testing...');
            $result.html('<span style="color: #3c434a;">Connecting...</span>');
            
            log('Sending AJAX request to test Ollama connection', {
                url: wpMcpAiAdmin.ajaxUrl,
                endpoint: endpointUrl
            });

            // Use the error service for consistent error handling
            $.wpMcpAiAjax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_test_ollama_connection',
                    nonce: wpMcpAiAdmin.nonce,
                    endpoint_url: endpointUrl
                }
            }, {
                success: function (response) {
                    log('Ollama test response:', response);
                    if (response.success) {
                        $result.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                    }
                },
                error: function (error, jqXHR) {
                    log('Ollama test error:', { error: error, status: jqXHR.status });
                    $result.html('<span style="color: #d63638;">✗ ' + (error.userMessage || 'Connection failed') + '</span>');
                },
                complete: function () {
                    log('Ollama test complete');
                    $btn.prop('disabled', false).text('Test Connection');
                }
            });
        });
        log('Ollama handlers initialized');

        // Fetch Ollama models
        $('#wp-mcp-ai-fetch-ollama-models').on('click', function (e) {
            e.preventDefault();
            const $button = $(this);
            const $modelsList = $('#wp-mcp-ai-ollama-models-list');
            const endpointUrl = $('input[name="wp_mcp_ai_settings[ollama_endpoint_url]"]').val();

            if (!endpointUrl) {
                $modelsList.html('<p style="color: #d63638;">Please enter an endpoint URL first.</p>');
                return;
            }

            $button.prop('disabled', true).text('Fetching...');
            $modelsList.html('<p>Loading models...</p>');

            // Use the error service for consistent error handling
            $.wpMcpAiAjax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_fetch_ollama_models',
                    nonce: wpMcpAiAdmin.nonce,
                    endpoint_url: endpointUrl
                }
            }, {
                success: function (response) {
                    if (response.success && response.data.models.length > 0) {
                        let html = '<p><strong>Available models:</strong></p><ul style="list-style: disc; margin-left: 20px;">';
                        response.data.models.forEach(function (model) {
                            const sizeInfo = model.size ? ' (' + formatBytes(model.size) + ')' : '';
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
                error: function (error) {
                    $modelsList.html('<p style="color: #d63638;">' + (error.userMessage || 'Failed to fetch models') + '</p>');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Fetch Models');
                }
            });
        });

        // Handle Ollama model selection
        $(document).on('click', '.wp-mcp-ai-select-ollama-model', function (e) {
            e.preventDefault();
            const modelName = $(this).data('model');
            $('input[name="wp_mcp_ai_settings[ollama_model]"]').val(modelName);
            $('#wp-mcp-ai-ollama-models-list').prepend('<p style="color: #00a32a; font-weight: bold;">Selected: ' + modelName + '</p>');
        });
    }

    function initLMStudioHandlers() {
        // Test LM Studio connection
        $('#wp-mcp-ai-test-lm-studio-connection').on('click', function (e) {
            e.preventDefault();
            const $button = $(this);
            const $result = $('#wp-mcp-ai-lm-studio-test-result');
            const endpointUrl = $('input[name="wp_mcp_ai_settings[lm_studio_endpoint_url]"]').val();

            if (!endpointUrl) {
                $result.html('<span style="color: #d63638;">Please enter an endpoint URL first.</span>');
                return;
            }

            $button.prop('disabled', true).text('Testing...');
            $result.html('<span style="color: #3c434a;">Connecting...</span>');

            // Use the error service for consistent error handling
            $.wpMcpAiAjax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_test_lm_studio_connection',
                    nonce: wpMcpAiAdmin.nonce,
                    endpoint_url: endpointUrl
                }
            }, {
                success: function (response) {
                    if (response.success) {
                        $result.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                    }
                },
                error: function (error) {
                    $result.html('<span style="color: #d63638;">✗ ' + (error.userMessage || 'Connection failed') + '</span>');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Test Connection');
                }
            });
        });

        // Fetch LM Studio models
        $('#wp-mcp-ai-fetch-lm-studio-models').on('click', function (e) {
            e.preventDefault();
            const $button = $(this);
            const $modelsList = $('#wp-mcp-ai-lm-studio-models-list');
            const endpointUrl = $('input[name="wp_mcp_ai_settings[lm_studio_endpoint_url]"]').val();

            if (!endpointUrl) {
                $modelsList.html('<p style="color: #d63638;">Please enter an endpoint URL first.</p>');
                return;
            }

            $button.prop('disabled', true).text('Fetching...');
            $modelsList.html('<p>Loading models...</p>');

            // Use the error service for consistent error handling
            $.wpMcpAiAjax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_fetch_lm_studio_models',
                    nonce: wpMcpAiAdmin.nonce,
                    endpoint_url: endpointUrl
                }
            }, {
                success: function (response) {
                    if (response.success && response.data.models.length > 0) {
                        let html = '<p><strong>Available models:</strong></p><ul style="list-style: disc; margin-left: 20px;">';
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
                error: function (error) {
                    $modelsList.html('<p style="color: #d63638;">' + (error.userMessage || 'Failed to fetch models') + '</p>');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Fetch Models');
                }
            });
        });

        // Handle LM Studio model selection
        $(document).on('click', '.wp-mcp-ai-select-lm-studio-model', function (e) {
            e.preventDefault();
            const modelName = $(this).data('model');
            $('input[name="wp_mcp_ai_settings[lm_studio_model]"]').val(modelName);
            $('#wp-mcp-ai-lm-studio-models-list').prepend('<p style="color: #00a32a; font-weight: bold;">Selected: ' + modelName + '</p>');
        });
    }

    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    function initCloudwaysHandlers() {
        // Fetch Cloudways data
        $('#wp-mcp-ai-fetch-cloudways-data').on('click', function (e) {
            e.preventDefault();
            const $button = $(this);
            const $result = $('#wp-mcp-ai-cloudways-fetch-result');
            const $serversList = $('#wp-mcp-ai-cloudways-servers-list');
            const $appsList = $('#wp-mcp-ai-cloudways-apps-list');
            const email = $('input[name="wp_mcp_ai_settings[cloudways_email]"]').val();
            const apiKey = $('input[name="wp_mcp_ai_settings[cloudways_api_key]"]').val();

            if (!email || !apiKey) {
                $result.html('<span style="color: #d63638;">Please enter both email and API key first.</span>');
                return;
            }

            $button.prop('disabled', true).text('Fetching...');
            $result.html('<span style="color: #3c434a;">Connecting to Cloudways...</span>');
            $serversList.html('');
            $appsList.html('');

            // Use the error service for consistent error handling
            $.wpMcpAiAjax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_fetch_cloudways_data',
                    nonce: wpMcpAiAdmin.nonce,
                    email: email,
                    api_key: apiKey
                }
            }, {
                success: function (response) {
                    if (response.success) {
                        $result.html('<span style="color: #00a32a;">✓ Successfully fetched Cloudways data</span>');

                        // Display servers
                        if (response.data.servers && response.data.servers.length > 0) {
                            const $serversList = $('#wp-mcp-ai-cloudways-servers-list');
                            $serversList.empty();
                            
                            const $serversTitle = $('<p><strong>Select a server:</strong></p>');
                            const $serversUl = $('<ul style="list-style: disc; margin-left: 20px;"></ul>');
                            
                            response.data.servers.forEach(function (server) {
                                const $li = $('<li style="margin-bottom: 5px;"></li>');
                                const $link = $('<a href="#" class="wp-mcp-ai-select-cloudways-server"></a>');
                                $link.attr('data-server-id', server.id);
                                $link.text(server.label + ' (ID: ' + server.id + ', Status: ' + server.status + ')');
                                $li.append($link);
                                $serversUl.append($li);
                            });
                            
                            $serversList.append($serversTitle).append($serversUl);
                        }

                        // Display apps
                        if (response.data.apps && response.data.apps.length > 0) {
                            const $appsList = $('#wp-mcp-ai-cloudways-apps-list');
                            $appsList.empty();
                            
                            const $appsTitle = $('<p><strong>Select an application:</strong></p>');
                            const $appsUl = $('<ul style="list-style: disc; margin-left: 20px;"></ul>');
                            
                            response.data.apps.forEach(function (app) {
                                const $li = $('<li style="margin-bottom: 5px;"></li>');
                                const $link = $('<a href="#" class="wp-mcp-ai-select-cloudways-app"></a>');
                                $link.attr('data-app-id', app.id);
                                $link.attr('data-server-id', app.server_id);
                                $link.text(app.label + ' (ID: ' + app.id + ')');
                                $li.append($link);
                                $appsUl.append($li);
                            });
                            
                            $appsList.append($appsTitle).append($appsUl);
                        }
                    } else {
                        $result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                    }
                },
                error: function (error) {
                    $result.html('<span style="color: #d63638;">✗ ' + (error.userMessage || 'Failed to connect to Cloudways') + '</span>');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Fetch Cloudways Data');
                }
            });
        });

        // Handle server selection
        $(document).on('click', '.wp-mcp-ai-select-cloudways-server', function (e) {
            e.preventDefault();
            const serverId = $(this).data('server-id');
            $('input[name="wp_mcp_ai_settings[cloudways_server_id]"]').val(serverId);
            
            const $message = $('<p style="color: #00a32a; font-weight: bold;"></p>');
            $message.text('Selected Server ID: ' + serverId);
            $('#wp-mcp-ai-cloudways-servers-list').prepend($message);
        });

        // Handle app selection
        $(document).on('click', '.wp-mcp-ai-select-cloudways-app', function (e) {
            e.preventDefault();
            const appId = $(this).data('app-id');
            const serverId = $(this).data('server-id');
            $('input[name="wp_mcp_ai_settings[cloudways_app_id]"]').val(appId);
            $('input[name="wp_mcp_ai_settings[cloudways_server_id]"]').val(serverId);
            
            const $message = $('<p style="color: #00a32a; font-weight: bold;"></p>');
            $message.text('Selected App ID: ' + appId + ' (Server ID: ' + serverId + ')');
            $('#wp-mcp-ai-cloudways-apps-list').prepend($message);
        });
    }

    function initCloudflareHandlers() {
        // Test Cloudflare connection
        $('#wp-mcp-ai-test-cloudflare-connection').on('click', function (e) {
            e.preventDefault();
            const $button = $(this);
            const $result = $('#wp-mcp-ai-cloudflare-test-result');
            const $zoneInfo = $('#wp-mcp-ai-cloudflare-zone-info');
            const zoneId = $('input[name="wp_mcp_ai_settings[cloudflare_zone_id]"]').val();
            const apiToken = $('input[name="wp_mcp_ai_settings[cloudflare_api_token]"]').val();

            if (!zoneId || !apiToken) {
                $result.html('<span style="color: #d63638;">Please enter both Zone ID and API Token first.</span>');
                return;
            }

            $button.prop('disabled', true).text('Testing...');
            $result.html('<span style="color: #3c434a;">Connecting to Cloudflare...</span>');
            $zoneInfo.html('');

            // Use the error service for consistent error handling
            $.wpMcpAiAjax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_test_cloudflare_connection',
                    nonce: wpMcpAiAdmin.nonce,
                    zone_id: zoneId,
                    api_token: apiToken
                }
            }, {
                success: function (response) {
                    if (response.success) {
                        $result.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                        
                        // Display zone information if available
                        if (response.data.zone_info) {
                            const info = response.data.zone_info;
                            let html = '<div style="background: #f0f0f1; padding: 10px; border-radius: 4px; margin-top: 10px;">';
                            html += '<p style="margin: 0 0 5px 0;"><strong>Zone Information:</strong></p>';
                            html += '<ul style="margin: 0; padding-left: 20px;">';
                            if (info.name) {
                                html += '<li><strong>Domain:</strong> ' + info.name + '</li>';
                            }
                            if (info.status) {
                                html += '<li><strong>Status:</strong> ' + info.status + '</li>';
                            }
                            if (info.plan) {
                                html += '<li><strong>Plan:</strong> ' + info.plan + '</li>';
                            }
                            html += '</ul></div>';
                            $zoneInfo.html(html);
                        }
                    } else {
                        $result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                        $zoneInfo.html('');
                    }
                },
                error: function (error) {
                    $result.html('<span style="color: #d63638;">✗ ' + (error.userMessage || 'Connection failed') + '</span>');
                    $zoneInfo.html('');
                },
                complete: function () {
                    $button.prop('disabled', false).text('Test Connection');
                }
            });
        });
    }

    /**
     * Initialize collapsible accordion sections
     */
    function initAccordion() {
        log('Initializing accordion sections...');
        
        // Handle section header clicks
        $(document).on('click', '.wp-mcp-ai-section__header', function(e) {
            e.preventDefault();
            const $header = $(this);
            const $section = $header.closest('.wp-mcp-ai-section');
            const isExpanded = $section.hasClass('wp-mcp-ai-section--expanded');
            
            if (isExpanded) {
                $section.removeClass('wp-mcp-ai-section--expanded');
                $header.attr('aria-expanded', 'false');
            } else {
                $section.addClass('wp-mcp-ai-section--expanded');
                $header.attr('aria-expanded', 'true');
            }
            
            log('Section toggled:', $section.find('.wp-mcp-ai-section__title').text());
        });
        
        // Expand all button
        $(document).on('click', '.wp-mcp-ai-expand-all', function(e) {
            e.preventDefault();
            $('.wp-mcp-ai-section').addClass('wp-mcp-ai-section--expanded');
            $('.wp-mcp-ai-section__header').attr('aria-expanded', 'true');
            log('All sections expanded');
            if (typeof window.wpMcpAiSaveExpandedState === 'function') {
                window.wpMcpAiSaveExpandedState();
            }
        });
        
        // Collapse all button
        $(document).on('click', '.wp-mcp-ai-collapse-all', function(e) {
            e.preventDefault();
            $('.wp-mcp-ai-section').removeClass('wp-mcp-ai-section--expanded');
            $('.wp-mcp-ai-section__header').attr('aria-expanded', 'false');
            log('All sections collapsed');
            if (typeof window.wpMcpAiSaveExpandedState === 'function') {
                window.wpMcpAiSaveExpandedState();
            }
        });
        
        // Keyboard accessibility
        $(document).on('keydown', '.wp-mcp-ai-section__header', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).click();
            }
        });
        
        // Restore expanded state from localStorage
        const expandedSections = localStorage.getItem('wp_mcp_ai_expanded_sections');
        if (expandedSections) {
            try {
                const sections = JSON.parse(expandedSections);
                // Cache jQuery selectors for better performance
                const $allSections = $('.wp-mcp-ai-section');
                const $allHeaders = $('.wp-mcp-ai-section__header');
                // First, collapse all sections
                $allSections.removeClass('wp-mcp-ai-section--expanded');
                $allHeaders.attr('aria-expanded', 'false');
                // Then expand only the saved sections
                sections.forEach(function(id) {
                    const $section = $('#' + id);
                    $section.addClass('wp-mcp-ai-section--expanded');
                    $section.find('.wp-mcp-ai-section__header').attr('aria-expanded', 'true');
                });
                log('Restored expanded sections from localStorage:', sections);
            } catch (e) {
                log('Error parsing expanded sections:', e);
            }
        }
        // If no localStorage, all sections remain expanded (default from PHP)
        
        // Save expanded state to localStorage (after toggle completes)
        $(document).on('click', '.wp-mcp-ai-section__header', function() {
            // Use requestAnimationFrame to ensure this runs after the DOM has updated
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(function() {
                    const expandedIds = [];
                    $('.wp-mcp-ai-section--expanded').each(function() {
                        const id = $(this).attr('id');
                        if (id) {
                            expandedIds.push(id);
                        }
                    });
                    localStorage.setItem('wp_mcp_ai_expanded_sections', JSON.stringify(expandedIds));
                    log('Saved expanded sections to localStorage:', expandedIds);
                });
            } else {
                // Fallback for older browsers
                setTimeout(function() {
                    const expandedIds = [];
                    $('.wp-mcp-ai-section--expanded').each(function() {
                        const id = $(this).attr('id');
                        if (id) {
                            expandedIds.push(id);
                        }
                    });
                    localStorage.setItem('wp_mcp_ai_expanded_sections', JSON.stringify(expandedIds));
                    log('Saved expanded sections to localStorage:', expandedIds);
                }, 50);
            }
        });
        
        log('Accordion initialized');
    }

    $(function () {
        log('DOM ready, initializing WP oOS admin handlers...');
        
        // Initialize accordion first - it doesn't require wpMcpAiAdmin
        initAccordion();
        
        // Check if wpMcpAiAdmin is defined for AJAX features
        if (typeof wpMcpAiAdmin === 'undefined') {
            console.warn('[WP oOS] WARNING: wpMcpAiAdmin is not defined! AJAX features will not be available, but accordion should work.');
            log('All admin handlers initialized (accordion only - no AJAX)');
            return;
        }
        log('wpMcpAiAdmin loaded:', wpMcpAiAdmin);
        
        initColorPickers();
        initOllamaHandlers();
        initLMStudioHandlers();
        initCloudwaysHandlers();
        initCloudflareHandlers();
        initTokenUsageHandlers();
        initProviderPriorityList();
        
        log('All admin handlers initialized successfully');
    });
    
    /**
     * Initialize token usage management handlers.
     */
    function initTokenUsageHandlers() {
        log('Initializing token usage handlers...');
        
        // Reset user token usage
        $('#wp-mcp-ai-reset-user-usage').on('click', function(e) {
            e.preventDefault();
            log('Reset user usage button clicked');
            
            const $btn = $(this);
            const originalText = $btn.text();
            const confirmMessage = $btn.data('confirm');
            
            if (!confirm(confirmMessage)) {
                log('User cancelled reset operation');
                return;
            }
            
            $btn.prop('disabled', true).text('Resetting...');
            
            // Use the error service for consistent error handling
            $.wpMcpAiAjax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_reset_user_token_usage',
                    nonce: wpMcpAiAdmin.nonce
                }
            }, {
                success: function(response) {
                    log('Reset user usage response:', response);
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || 'Failed to reset token usage.');
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function(error) {
                    log('Reset user usage error:', error);
                    alert(error.userMessage || 'Error resetting token usage');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Reset all token usage
        $('#wp-mcp-ai-reset-all-usage').on('click', function(e) {
            e.preventDefault();
            log('Reset all usage button clicked');
            
            const $btn = $(this);
            const originalText = $btn.text();
            const confirmMessage = $btn.data('confirm');
            
            if (!confirm(confirmMessage)) {
                log('User cancelled reset operation');
                return;
            }
            
            $btn.prop('disabled', true).text('Resetting...');
            
            // Use the error service for consistent error handling
            $.wpMcpAiAjax({
                url: wpMcpAiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_mcp_ai_reset_all_token_usage',
                    nonce: wpMcpAiAdmin.nonce
                }
            }, {
                success: function(response) {
                    log('Reset all usage response:', response);
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || 'Failed to reset token usage.');
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function(error) {
                    log('Reset all usage error:', error);
                    alert(error.userMessage || 'Error resetting token usage');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });
        
        log('Token usage handlers initialized');
    }

    /**
     * Initialize provider priority list sortable.
     */
    function initProviderPriorityList() {
        log('Initializing provider priority list sortable...');
        
        const $sortable = $('#wp-mcp-ai-provider-sortable');
        
        if ($sortable.length && typeof $sortable.sortable === 'function') {
            $sortable.sortable({
                axis: 'y',
                handle: '.dashicons-menu',
                cursor: 'move',
                placeholder: 'wp-mcp-ai-provider-item ui-sortable-placeholder',
                opacity: 0.8,
                tolerance: 'pointer',
                update: function(_event, _ui) {
                    log('Provider list reordered');
                    // Update hidden input values to maintain order
                    $sortable.find('li').each(function(_index) {
                        const $item = $(this);
                        const provider = $item.data('provider');
                        $item.find('input[type="hidden"]').val(provider);
                    });
                }
            });
            log('Provider priority list sortable initialized');
        }
    }

})(jQuery);
