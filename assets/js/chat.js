(function () {
    'use strict';

    var globalConfig = window.wpMcpAiChat || {};
    var instances = window.wpMcpAiChatInstances || {};

    function getString(key, fallback) {
        if (globalConfig.strings && Object.prototype.hasOwnProperty.call(globalConfig.strings, key)) {
            return globalConfig.strings[key];
        }
        return fallback;
    }

    function init() {
        var containers = document.querySelectorAll('[data-wp-mcp-ai-chat]');
        Array.prototype.forEach.call(containers, function (container) {
            var instanceId = container.getAttribute('id');
            var config = instances[instanceId];

            if (!config) {
                setStatus(container, getString('missingAssistant', 'Assistant configuration missing.'));
                return;
            }

            var form = container.querySelector('.wp-mcp-ai-chat__form');
            var textarea = container.querySelector('.wp-mcp-ai-chat__input');
            var messagesEl = container.querySelector('.wp-mcp-ai-chat__messages');
            var statusEl = container.querySelector('.wp-mcp-ai-chat__status');

            if (!form || !textarea || !messagesEl || !statusEl) {
                return;
            }

            var state = {
                conversation: [],
                busy: false,
                config: config,
                container: container,
                textarea: textarea,
                messagesEl: messagesEl,
                statusEl: statusEl,
            };

            textarea.setAttribute('placeholder', getString('placeholder', textarea.getAttribute('placeholder')));
            form.addEventListener('submit', function (event) {
                handleSubmit(event, state);
            });
        });
    }

    function handleSubmit(event, state) {
        event.preventDefault();
        if (state.busy) {
            return;
        }

        var message = state.textarea.value.trim();
        if (!message) {
            setStatus(state.container, getString('emptyMessage', 'Enter a message before sending.'));
            return;
        }

        state.textarea.value = '';
        appendMessage(state.messagesEl, 'user', message);
        state.conversation.push({ role: 'user', content: message });

        sendChat(state);
    }

    function sendChat(state) {
        state.busy = true;
        disableForm(state.container, true);
        setStatus(state.container, getString('sending', 'Sending…'));

        var payload = {
            assistant_id: state.config.assistantId,
            messages: state.conversation,
        };

        fetch(state.config.messagesEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': globalConfig.nonce || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })
            .then(function (response) {
                if (!response.ok) {
                    throw response;
                }
                return response.json();
            })
            .then(function (data) {
                handleChatResponse(state, data);
                finalize();
            })
            .catch(function (error) {
                handleError(state, error);
                finalize();
            });

        function finalize() {
            state.busy = false;
            disableForm(state.container, false);
        }
    }

    function handleChatResponse(state, data) {
        var chatData = data && data.data ? data.data : null;
        var choices = chatData && Array.isArray(chatData.choices) ? chatData.choices : [];
        var choice = choices.length ? choices[0] : null;
        var message = choice && choice.message ? choice.message : null;

        if (!message) {
            setStatus(state.container, getString('error', 'Something went wrong.'));
            return;
        }

        if (message.content) {
            var text = normaliseContent(message.content);
            appendMessage(state.messagesEl, 'assistant', text);
            state.conversation.push({ role: 'assistant', content: text });
            setStatus(state.container, getString('waiting', 'Waiting for the assistant…'));
        }

        if (message.tool_calls && Array.isArray(message.tool_calls) && message.tool_calls.length) {
            processToolCalls(state, message.tool_calls).catch(function (err) {
                if (window.console && console.error) {
                    console.error(err);
                }
            });
        } else {
            setStatus(state.container, '');
        }
    }

    function processToolCalls(state, toolCalls) {
        var executions = toolCalls.map(function (call) {
            return executeTool(state, call);
        });
        return Promise.all(executions).then(function () {
            setStatus(state.container, '');
        });
    }

    function executeTool(state, call) {
        var functionCall = call && call['function'] ? call['function'] : null;
        if (!functionCall || !functionCall.name) {
            return Promise.resolve();
        }

        var toolName = functionCall.name;
        var executingText = formatString(getString('toolExecuting', 'Running tool: %s'), toolName);
        appendMessage(state.messagesEl, 'assistant', executingText);

        var args = {};
        if (functionCall.arguments) {
            try {
                args = JSON.parse(functionCall.arguments);
            } catch (error) {
                if (window.console && console.error) {
                    console.error('Failed to parse tool arguments', error);
                }
            }
        }

        var payload = {
            assistant_id: state.config.assistantId,
            tool: toolName,
            arguments: args,
        };

        return fetch(state.config.toolsEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': globalConfig.nonce || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })
            .then(function (response) {
                if (!response.ok) {
                    throw response;
                }
                return response.json();
            })
            .then(function (response) {
                var result = response && Object.prototype.hasOwnProperty.call(response, 'result') ? response.result : null;
                var formatted = typeof result === 'string' ? result : JSON.stringify(result, null, 2);
                appendMessage(state.messagesEl, 'tool', formatted);

                var toolMessage = {
                    role: 'tool',
                    content: formatted,
                };

                if (call && call.id) {
                    toolMessage.tool_call_id = call.id;
                }

                state.conversation.push(toolMessage);
                setStatus(state.container, getString('toolSuccess', 'Tool response ready.'));
            })
            .catch(function (error) {
                appendMessage(state.messagesEl, 'system', getString('toolError', 'The tool request failed.'));
                handleError(state, error);
            });
    }

    function handleError(state, error) {
        if (error && typeof error.json === 'function') {
            error
                .json()
                .then(function (body) {
                    var message = body && (body.message || (body.data && body.data.message));
                    setStatus(state.container, message || getString('error', 'Something went wrong.'));
                })
                .catch(function () {
                    setStatus(state.container, getString('error', 'Something went wrong.'));
                });
        } else {
            setStatus(state.container, getString('error', 'Something went wrong.'));
        }
    }

    function disableForm(container, disabled) {
        var elements = container.querySelectorAll('button, textarea, input');
        Array.prototype.forEach.call(elements, function (element) {
            element.disabled = disabled;
        });
    }

    function setStatus(container, message) {
        var statusEl = container.querySelector('.wp-mcp-ai-chat__status');
        if (!statusEl) {
            return;
        }

        if (!message) {
            statusEl.textContent = '';
            statusEl.hidden = true;
            return;
        }

        statusEl.textContent = message;
        statusEl.hidden = false;
    }

    function appendMessage(listEl, role, text) {
        if (!text) {
            return;
        }

        var entry = document.createElement('div');
        entry.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__message--' + role;

        var bubble = document.createElement('div');
        bubble.className = 'wp-mcp-ai-chat__bubble';
        bubble.textContent = text;

        entry.appendChild(bubble);
        listEl.appendChild(entry);
        listEl.scrollTop = listEl.scrollHeight;
    }

    function normaliseContent(content) {
        if (typeof content === 'string') {
            return content;
        }

        if (Array.isArray(content)) {
            return content
                .map(renderContentPiece)
                .filter(function (value) {
                    return value && value.trim();
                })
                .join('\n\n')
                .trim();
        }

        if (content && typeof content === 'object') {
            return renderContentPiece(content);
        }

        return '';
    }

    function renderContentPiece(piece) {
        if (typeof piece === 'string') {
            return piece;
        }

        if (!piece || typeof piece !== 'object') {
            return '';
        }

        if (typeof piece.text === 'string') {
            return piece.text;
        }

        if (piece.text && typeof piece.text.value === 'string') {
            return piece.text.value;
        }

        if (typeof piece.content === 'string') {
            return piece.content;
        }

        if (Array.isArray(piece.content)) {
            return piece.content
                .map(renderContentPiece)
                .filter(function (value) {
                    return value && value.trim();
                })
                .join('\n\n');
        }

        if (piece.value && typeof piece.value === 'string') {
            return piece.value;
        }

        var type = typeof piece.type === 'string' ? piece.type : '';

        if (type === 'image_file') {
            var label = '';

            if (piece.caption && typeof piece.caption === 'string') {
                label = piece.caption;
            } else if (piece.image_file && typeof piece.image_file === 'object') {
                if (typeof piece.image_file.display_name === 'string') {
                    label = piece.image_file.display_name;
                } else if (typeof piece.image_file.file_id === 'string') {
                    label = piece.image_file.file_id;
                }
            }

            if (!label) {
                label = 'Image';
            }

            return '[' + label + ']';
        }

        if (type === 'image_url' || type === 'input_image') {
            if (piece.image_url && typeof piece.image_url.url === 'string') {
                return '[Image: ' + piece.image_url.url + ']';
            }

            if (typeof piece.url === 'string' && piece.url) {
                return '[Image: ' + piece.url + ']';
            }

            if (typeof piece.caption === 'string' && piece.caption) {
                return '[' + piece.caption + ']';
            }

            return '[Image]';
        }

        if (type === 'tool_result') {
            var parts = [];

            if (piece.output) {
                if (typeof piece.output === 'string') {
                    parts.push(piece.output);
                } else if (Array.isArray(piece.output)) {
                    parts.push(
                        piece.output
                            .map(renderContentPiece)
                            .filter(function (value) {
                                return value && value.trim();
                            })
                            .join('\n\n')
                    );
                } else if (typeof piece.output === 'object') {
                    try {
                        parts.push(JSON.stringify(piece.output));
                    } catch (error) {
                        parts.push('[Tool Result]');
                    }
                }
            }

            if (piece.content) {
                parts.push(normaliseContent(piece.content));
            }

            var toolText = parts
                .filter(function (value) {
                    return value && value.trim();
                })
                .join('\n\n');

            return toolText || '[Tool Result]';
        }

        try {
            return JSON.stringify(piece);
        } catch (error) {
            return '[' + (type || 'content') + ']';
        }
    }

    function formatString(template, value) {
        if (!template) {
            return value;
        }

        return template.replace('%s', value);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
