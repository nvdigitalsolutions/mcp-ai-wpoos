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

        function finalize() {
            state.busy = false;
            disableForm(state.container, false);
        }

        return fetch(state.config.messagesEndpoint, {
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
                return handleChatResponse(state, data);
            })
            .then(function (result) {
                finalize();
                return result;
            }, function (error) {
                handleError(state, error);
                finalize();
            });
    }

    function handleChatResponse(state, data) {
        var chatData = data && data.data ? data.data : null;
        var choices = chatData && Array.isArray(chatData.choices) ? chatData.choices : [];
        var choice = choices.length ? choices[0] : null;
        var message = choice && choice.message ? choice.message : null;

        if (!message) {
            setStatus(state.container, getString('error', 'Something went wrong.'));
            return Promise.resolve();
        }

        var assistantMessage = { role: 'assistant' };
        if (message.content) {
            var text = normaliseContent(message.content);
            appendMessage(state.messagesEl, 'assistant', text, true);
            assistantMessage.content = text;
        }

        var hasToolCalls = message.tool_calls && Array.isArray(message.tool_calls) && message.tool_calls.length;
        if (hasToolCalls) {
            assistantMessage.tool_calls = message.tool_calls;
        }

        if (assistantMessage.content || assistantMessage.tool_calls) {
            if (!assistantMessage.hasOwnProperty('content')) {
                assistantMessage.content = '';
            }
            state.conversation.push(assistantMessage);
        }

        if (hasToolCalls) {
            setStatus(state.container, getString('waiting', 'Waiting for the assistant…'));
            return processToolCalls(state, message.tool_calls).catch(function (err) {
                if (window.console && console.error) {
                    console.error(err);
                }
                return Promise.reject(err);
            });
        }

        setStatus(state.container, '');
        return Promise.resolve();
    }

    function processToolCalls(state, toolCalls) {
        if (!toolCalls || !toolCalls.length) {
            return Promise.resolve();
        }

        var sequence = Promise.resolve();

        toolCalls.forEach(function (call) {
            sequence = sequence.then(function () {
                return executeTool(state, call);
            });
        });

        return sequence.then(function () {
            setStatus(state.container, getString('waiting', 'Waiting for the assistant…'));
            return sendChat(state);
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
                var formatted = '';

                if (typeof result === 'string') {
                    formatted = result;
                } else if (result !== null && typeof result !== 'undefined') {
                    try {
                        formatted = JSON.stringify(result, null, 2);
                    } catch (error) {
                        formatted = String(result);
                    }
                }

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
                return Promise.reject(error);
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

    function appendMessage(listEl, role, text, allowMarkdown) {
        if (!text) {
            return;
        }

        var entry = document.createElement('div');
        entry.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__message--' + role;

        var bubble = document.createElement('div');
        bubble.className = 'wp-mcp-ai-chat__bubble';
        if (allowMarkdown) {
            bubble.innerHTML = renderMarkdown(text);
        } else {
            bubble.innerHTML = escapeHtml(text).replace(/\n/g, '<br />');
        }

        entry.appendChild(bubble);
        listEl.appendChild(entry);
        listEl.scrollTop = listEl.scrollHeight;
    }

    function renderMarkdown(text) {
        if (!text) {
            return '';
        }

        var placeholderBase = 'WP_MCP_AI_' + Math.random().toString(36).slice(2);
        var codeBlocks = [];
        var inlineCodes = [];
        var links = [];
        var processed = String(text).replace(/\r\n/g, '\n');

        processed = processed.replace(/```([\w+-]*)\n?([\s\S]*?)```/g, function (match, language, code) {
            var placeholder = '@@' + placeholderBase + '_CODE_' + codeBlocks.length + '@@';
            codeBlocks.push({
                placeholder: placeholder,
                language: (language || '').trim(),
                code: code.replace(/\s+$/, ''),
            });
            return placeholder;
        });

        processed = processed.replace(/`([^`]+)`/g, function (match, code) {
            var placeholder = '@@' + placeholderBase + '_INLINE_' + inlineCodes.length + '@@';
            inlineCodes.push({
                placeholder: placeholder,
                code: code,
            });
            return placeholder;
        });

        processed = processed.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (match, label, url) {
            var placeholder = '@@' + placeholderBase + '_LINK_' + links.length + '@@';
            links.push({
                placeholder: placeholder,
                label: label,
                url: url,
            });
            return placeholder;
        });

        processed = escapeHtml(processed);

        var codePlaceholderMap = {};
        codeBlocks.forEach(function (item) {
            codePlaceholderMap[item.placeholder] = true;
        });

        var lines = processed.split('\n');
        var htmlParts = [];
        var paragraphLines = [];
        var listType = '';
        var listItems = [];

        function flushParagraph() {
            if (!paragraphLines.length) {
                return;
            }
            htmlParts.push('<p>' + paragraphLines.join('<br />') + '</p>');
            paragraphLines = [];
        }

        function flushList() {
            if (!listType || !listItems.length) {
                listType = '';
                listItems = [];
                return;
            }

            htmlParts.push('<' + listType + '>' + listItems.join('') + '</' + listType + '>');
            listType = '';
            listItems = [];
        }

        lines.forEach(function (line) {
            var trimmed = line.trim();

            if (!trimmed) {
                flushParagraph();
                flushList();
                return;
            }

            if (codePlaceholderMap[trimmed]) {
                flushParagraph();
                flushList();
                htmlParts.push(trimmed);
                return;
            }

            if (trimmed.indexOf('&gt;') === 0) {
                flushParagraph();
                flushList();
                htmlParts.push('<blockquote><p>' + formatInline(trimmed.replace(/^&gt;\s*/, '')) + '</p></blockquote>');
                return;
            }

            var headingMatch = trimmed.match(/^(#{1,6})\s+(.*)$/);
            if (headingMatch) {
                flushParagraph();
                flushList();
                var level = headingMatch[1].length;
                var headingText = formatInline(headingMatch[2]);
                htmlParts.push('<h' + level + '>' + headingText + '</h' + level + '>');
                return;
            }

            var orderedMatch = trimmed.match(/^(\d+)\.\s+(.*)$/);
            if (orderedMatch) {
                var orderedText = formatInline(orderedMatch[2]);
                if (listType !== 'ol') {
                    flushParagraph();
                    flushList();
                    listType = 'ol';
                }
                listItems.push('<li>' + orderedText + '</li>');
                return;
            }

            var bulletMatch = trimmed.match(/^[-*+]\s+(.*)$/);
            if (bulletMatch) {
                var bulletText = formatInline(bulletMatch[1]);
                if (listType !== 'ul') {
                    flushParagraph();
                    flushList();
                    listType = 'ul';
                }
                listItems.push('<li>' + bulletText + '</li>');
                return;
            }

            paragraphLines.push(formatInline(line));
        });

        flushParagraph();
        flushList();

        var html = htmlParts.join('');

        inlineCodes.forEach(function (item) {
            html = replaceAll(html, item.placeholder, '<code>' + escapeHtml(item.code) + '</code>');
        });

        links.forEach(function (item) {
            var labelHtml = renderInlineLabel(item.label);
            var href = sanitizeUrl(item.url);
            var attributes = ' href="' + href + '"';
            if (/^https?:/i.test(href)) {
                attributes += ' target="_blank" rel="noopener noreferrer"';
            }
            html = replaceAll(html, item.placeholder, '<a' + attributes + '>' + labelHtml + '</a>');
        });

        codeBlocks.forEach(function (item) {
            var language = item.language.replace(/[^a-z0-9+#.-]/gi, '').toLowerCase();
            var className = language ? ' class="language-' + language + '"' : '';
            var codeHtml = '<pre class="wp-mcp-ai-chat__code-block"><code' + className + '>' + escapeHtml(item.code) + '</code></pre>';
            html = replaceAll(html, item.placeholder, codeHtml);
        });

        return html;
    }

    function renderInlineLabel(text) {
        if (!text) {
            return '';
        }

        var inlineBase = 'WP_MCP_AI_INLINE_' + Math.random().toString(36).slice(2);
        var inlineCodes = [];
        var processed = String(text).replace(/\r\n/g, ' ');

        processed = processed.replace(/`([^`]+)`/g, function (match, code) {
            var placeholder = '@@' + inlineBase + '_CODE_' + inlineCodes.length + '@@';
            inlineCodes.push({
                placeholder: placeholder,
                code: code,
            });
            return placeholder;
        });

        processed = escapeHtml(processed);
        processed = formatInline(processed);

        inlineCodes.forEach(function (item) {
            processed = replaceAll(processed, item.placeholder, '<code>' + escapeHtml(item.code) + '</code>');
        });

        return processed;
    }

    function sanitizeUrl(url) {
        if (!url) {
            return '#';
        }

        var trimmed = url.trim();
        if (!trimmed) {
            return '#';
        }

        try {
            var parsed = new URL(trimmed, window.location.origin);
            var protocol = parsed.protocol ? parsed.protocol.replace(/:$/, '').toLowerCase() : '';
            if (protocol && ['http', 'https', 'mailto', 'tel'].indexOf(protocol) === -1) {
                return '#';
            }
        } catch (error) {
            if (!/^https?:/i.test(trimmed) && !/^mailto:/i.test(trimmed) && !/^tel:/i.test(trimmed)) {
                return '#';
            }
        }

        return trimmed.replace(/"/g, '%22');
    }

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (character) {
            switch (character) {
                case '&':
                    return '&amp;';
                case '<':
                    return '&lt;';
                case '>':
                    return '&gt;';
                case '"':
                    return '&quot;';
                case '\'':
                    return '&#39;';
                default:
                    return character;
            }
        });
    }

    function formatInline(text) {
        var result = text;
        result = result.replace(/~~(?=\S)(.+?)(?<=\S)~~/g, '<del>$1</del>');
        result = result.replace(/\*\*(?=\S)(.+?)(?<=\S)\*\*/g, '<strong>$1</strong>');
        result = result.replace(/\*(?=\S)(.+?)(?<=\S)\*/g, '<em>$1</em>');
        return result;
    }

    function replaceAll(text, search, replacement) {
        return text.split(search).join(replacement);
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
