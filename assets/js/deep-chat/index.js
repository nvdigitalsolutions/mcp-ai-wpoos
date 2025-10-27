(function () {
    'use strict';

    var globalConfig = window.wpMcpAiChat || {};
    var ACTIVE_INSTANCES = new WeakMap();

    function parseJsonAttribute(value) {
        if (typeof value !== 'string' || !value) {
            return null;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    }

    function getNumberAttribute(value) {
        if (typeof value === 'number') {
            return value;
        }

        if (typeof value !== 'string' || !value.trim()) {
            return 0;
        }

        var parsed = Number(value);
        return isNaN(parsed) ? 0 : parsed;
    }

    function getDatasetConfig(element) {
        if (!element || !element.dataset) {
            return null;
        }

        return {
            assistantId: Number(element.dataset.assistantId || 0) || 0,
            chatEndpoint: element.dataset.chatEndpoint || '',
            toolsEndpoint: element.dataset.toolsEndpoint || '',
            uploadEndpoint: element.dataset.uploadEndpoint || '',
            restNonce: element.dataset.restNonce || '',
            allowGuests: element.dataset.allowGuests === 'true',
            guestToken: element.dataset.guestToken || '',
            allowedImageMimes: parseJsonAttribute(element.dataset.allowedImageMimes) || [],
            allowedFileMimes: parseJsonAttribute(element.dataset.allowedFileMimes) || [],
            allowedExtensions: parseJsonAttribute(element.dataset.allowedExtensions) || [],
            fileAccept: element.dataset.fileAccept || '',
            maxAttachmentBytes: getNumberAttribute(element.dataset.maxAttachmentBytes || 0),
        };
    }

    function buildHeaders(state) {
        var headers = {
            'Content-Type': 'application/json',
            'X-WP-Nonce': globalConfig.nonce || state.config.restNonce || '',
        };

        if (state.config.guestToken) {
            headers['X-WP-MCP-AI-Guest'] = state.config.guestToken;
        }

        return headers;
    }

    function extractTextFromContent(content) {
        if (typeof content === 'string') {
            return content;
        }

        if (!content) {
            return '';
        }

        if (Array.isArray(content)) {
            var parts = content
                .map(function (part) {
                    if (!part || typeof part !== 'object') {
                        return '';
                    }

                    if (typeof part.text === 'string') {
                        return part.text;
                    }

                    if (typeof part.value === 'string') {
                        return part.value;
                    }

                    return '';
                })
                .filter(function (value) {
                    return value && value.trim();
                });

            return parts.join('\n\n');
        }

        if (typeof content === 'object' && typeof content.text === 'string') {
            return content.text;
        }

        return '';
    }

    function convertDeepChatMessage(message) {
        if (!message || typeof message !== 'object') {
            return null;
        }

        var role = typeof message.role === 'string' ? message.role : '';
        if (!role && typeof message.sender === 'string') {
            role = message.sender;
        }

        if (!role) {
            return null;
        }

        var payload = {
            role: role,
        };

        if (Object.prototype.hasOwnProperty.call(message, 'content')) {
            var content = message.content;
            var text = extractTextFromContent(content);

            if (text && Array.isArray(content) && content.length > 1) {
                payload.content = content
                    .map(function (part) {
                        if (!part || typeof part !== 'object') {
                            return null;
                        }

                        if (typeof part.type === 'string' && part.type !== 'text') {
                            return null;
                        }

                        var partText = extractTextFromContent(part);
                        if (!partText) {
                            return null;
                        }

                        return {
                            type: 'text',
                            text: partText,
                        };
                    })
                    .filter(Boolean);
            } else if (text) {
                payload.content = text;
            }
        } else if (typeof message.text === 'string') {
            payload.content = message.text;
        } else if (typeof message.message === 'string') {
            payload.content = message.message;
        }

        if (message.tool_call_id) {
            payload.tool_call_id = message.tool_call_id;
        }

        return payload;
    }

    function extractAssistantDisplay(message) {
        if (!message || typeof message !== 'object') {
            return { text: '', attachments: [] };
        }

        var text = '';
        if (typeof message.content === 'string') {
            text = message.content;
        } else if (Array.isArray(message.content)) {
            text = message.content
                .map(function (segment) {
                    if (!segment || typeof segment !== 'object') {
                        return '';
                    }
                    if (segment.type === 'text' && typeof segment.text === 'string') {
                        return segment.text;
                    }
                    return '';
                })
                .filter(function (value) {
                    return value && value.trim();
                })
                .join('\n\n');
        } else if (typeof message.text === 'string') {
            text = message.text;
        }

        var attachments = [];
        if (Array.isArray(message.attachments)) {
            attachments = message.attachments;
        }

        return {
            text: text,
            attachments: attachments,
        };
    }

    function addSystemMessage(chat, text) {
        if (!chat || !text) {
            return;
        }

        chat.addMessage({
            role: 'system',
            text: text,
        });
    }

    function addErrorMessage(chat, text) {
        if (!chat || !text) {
            return;
        }

        chat.addMessage({
            error: text,
        });
    }

    function addAssistantMessage(chat, display) {
        if (!chat || !display) {
            return;
        }

        var text = display.text || '';
        if (display.attachments && display.attachments.length) {
            chat.addMessage({
                role: 'assistant',
                files: display.attachments,
                text: text,
            });
            return;
        }

        chat.addMessage({
            role: 'assistant',
            text: text,
        });
    }

    function uploadAttachment(state, file) {
        if (!file || !state || !state.config.uploadEndpoint) {
            return Promise.reject(new Error('Upload unavailable.'));
        }

        var headers = {
            'X-WP-Nonce': globalConfig.nonce || state.config.restNonce || '',
            Accept: 'application/json',
        };

        if (state.config.guestToken) {
            headers['X-WP-MCP-AI-Guest'] = state.config.guestToken;
        }

        var contentDisposition = typeof file.name === 'string' && file.name
            ? 'attachment; filename="' + file.name.replace(/"/g, '\\"') + '"'
            : '';
        if (contentDisposition) {
            headers['Content-Disposition'] = contentDisposition;
        }

        headers['Content-Type'] = file.type || 'application/octet-stream';

        return fetch(state.config.uploadEndpoint, {
            method: 'POST',
            headers: headers,
            body: file,
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw response;
                }
                return response.json();
            })
            .then(function (data) {
                if (!data || typeof data !== 'object') {
                    return null;
                }

                var id = data.id;
                if (!id && data.data && typeof data.data.id !== 'undefined') {
                    id = data.data.id;
                }

                if (typeof id === 'undefined' || id === null) {
                    return null;
                }

                var url = data.source_url || (data.guid && data.guid.rendered) || '';
                var name = '';

                if (data.title) {
                    if (typeof data.title === 'string') {
                        name = data.title;
                    } else if (typeof data.title.rendered === 'string') {
                        name = data.title.rendered;
                    }
                }

                if (!name && typeof data.slug === 'string') {
                    name = data.slug;
                }

                if (!name && file && file.name) {
                    name = file.name;
                }

                return {
                    id: id,
                    url: url,
                    name: name,
                    mime: data.mime_type || file.type || '',
                };
            });
    }

    function handleChatResponse(state, data) {
        var chat = state.chat;
        var chatData = data && data.data ? data.data : null;
        var choices = chatData && Array.isArray(chatData.choices) ? chatData.choices : [];
        var choice = choices.length ? choices[0] : null;
        var message = choice && choice.message ? choice.message : null;

        if (!message) {
            addErrorMessage(chat, 'The assistant response was empty.');
            return Promise.resolve();
        }

        var assistantMessage = { role: 'assistant' };
        var display = extractAssistantDisplay(message);
        var hasText = display.text && display.text.trim();
        var hasAttachments = display.attachments && display.attachments.length;
        var hasContent = hasText || hasAttachments;
        var hasToolCalls = message.tool_calls && Array.isArray(message.tool_calls) && message.tool_calls.length;

        if (hasContent) {
            addAssistantMessage(chat, display);
            assistantMessage.content = display.text || '';
        }

        if (hasToolCalls) {
            assistantMessage.tool_calls = message.tool_calls;
        }

        if (assistantMessage.content || assistantMessage.tool_calls) {
            if (!Object.prototype.hasOwnProperty.call(assistantMessage, 'content')) {
                assistantMessage.content = '';
            }
            state.conversation.push(assistantMessage);
        }

        if (hasToolCalls) {
            addSystemMessage(chat, 'Waiting for tool response…');
            return processToolCalls(state, message.tool_calls).then(function () {
                return sendChat(state);
            });
        }

        state.busy = false;
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

        return sequence;
    }

    function executeTool(state, call) {
        var chat = state.chat;
        var functionCall = call && call['function'] ? call['function'] : null;
        if (!functionCall || !functionCall.name) {
            return Promise.resolve();
        }

        var toolName = functionCall.name;
        addSystemMessage(chat, 'Running tool: ' + toolName);

        var args = {};
        if (functionCall.arguments) {
            try {
                args = JSON.parse(functionCall.arguments);
            } catch (error) {
                args = {};
            }
        }

        var payload = {
            assistant_id: state.config.assistantId,
            tool: toolName,
            arguments: args,
        };

        return fetch(state.config.toolsEndpoint, {
            method: 'POST',
            headers: buildHeaders(state),
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

                chat.addMessage({
                    role: 'tool',
                    text: formatted,
                });

                var toolMessage = {
                    role: 'tool',
                    content: formatted,
                };

                if (call && call.id) {
                    toolMessage.tool_call_id = call.id;
                }

                state.conversation.push(toolMessage);
            })
            .catch(function (error) {
                addErrorMessage(chat, 'Tool execution failed.');
                return Promise.reject(error);
            });
    }

    function sendChat(state) {
        var payload = {
            assistant_id: state.config.assistantId,
            messages: state.conversation.slice(),
        };

        state.busy = true;

        return fetch(state.config.chatEndpoint, {
            method: 'POST',
            headers: buildHeaders(state),
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
            .catch(function (error) {
                state.busy = false;
                if (error && typeof error.json === 'function') {
                    error
                        .json()
                        .then(function (body) {
                            var message = body && (body.message || (body.data && body.data.message));
                            addErrorMessage(state.chat, message || 'Something went wrong.');
                        })
                        .catch(function () {
                            addErrorMessage(state.chat, 'Something went wrong.');
                        });
                } else {
                    addErrorMessage(state.chat, 'Something went wrong.');
                }
                return Promise.reject(error);
            });
    }

    function createFilesConfig(state) {
        var acceptTokens = [];

        function pushTokens(list) {
            if (!Array.isArray(list)) {
                return;
            }

            list.forEach(function (value) {
                if (typeof value !== 'string') {
                    return;
                }
                acceptTokens.push(value);
            });
        }

        pushTokens(state.config.allowedImageMimes);
        pushTokens(state.config.allowedFileMimes);
        pushTokens(state.config.allowedExtensions);

        var config = {
            acceptedFormats: acceptTokens.join(',') || '*',
        };

        if (state.config.maxAttachmentBytes > 0) {
            config.maxFileSize = state.config.maxAttachmentBytes;
        }

        config.handler = function (files) {
            if (!files || !files.length) {
                return Promise.resolve([]);
            }

            var uploads = Array.prototype.slice.call(files).map(function (file) {
                return uploadAttachment(state, file).then(function (record) {
                    if (!record) {
                        return null;
                    }

                    var attachment = {
                        id: record.id,
                        url: record.url,
                        name: record.name,
                    };

                    state.pendingUploads.push({
                        record: record,
                        attachment: attachment,
                    });

                    return attachment;
                });
            });

            return Promise.all(uploads).then(function (results) {
                return results.filter(Boolean);
            });
        };

        return config;
    }

    function createAttachmentSegmentsFromUploads(uploads) {
        if (!uploads || !uploads.length) {
            return [];
        }

        return uploads
            .map(function (item) {
                if (!item || !item.record) {
                    return null;
                }

                var record = item.record;
                var rawId = typeof record.id === 'undefined' ? null : record.id;
                var attachmentId = 0;

                if (typeof rawId === 'number') {
                    attachmentId = rawId;
                } else if (typeof rawId === 'string' && rawId.trim()) {
                    attachmentId = parseInt(rawId, 10);
                }

                if (!attachmentId || !isFinite(attachmentId)) {
                    return null;
                }

                var mime = typeof record.mime === 'string' ? record.mime.toLowerCase() : '';
                var isImage = mime.indexOf('image/') === 0;
                var segment = {
                    type: isImage ? 'input_image' : 'input_file',
                    attachment_id: attachmentId,
                };

                var name = typeof record.name === 'string' ? record.name.trim() : '';
                if (name && !isImage) {
                    segment.display_name = name;
                }

                return segment;
            })
            .filter(Boolean);
    }

    function appendAttachmentSegments(message, attachmentSegments) {
        if (!message || !attachmentSegments || !attachmentSegments.length) {
            return;
        }

        var existing = message.content;
        var segments = Array.isArray(existing) ? existing.slice() : [];

        if (!Array.isArray(existing)) {
            var text = extractTextFromContent(existing);
            if (text && text.trim()) {
                segments.push({
                    type: 'text',
                    text: text.trim(),
                });
            }
        }

        message.content = segments.concat(attachmentSegments);
    }

    function onNewMessage(state, event) {
        if (!event || !event.detail || !event.detail.message) {
            return;
        }

        var detail = event.detail;
        if (detail.isHistory || detail.isInitial) {
            return;
        }

        var message = detail.message;
        if (message.role !== 'user') {
            return;
        }

        var converted = convertDeepChatMessage(message);
        if (!converted) {
            return;
        }

        if (state.pendingUploads.length) {
            var attachmentSegments = createAttachmentSegmentsFromUploads(state.pendingUploads);
            state.pendingUploads = [];

            if (attachmentSegments.length) {
                appendAttachmentSegments(converted, attachmentSegments);
            }
        }

        state.conversation.push(converted);
        sendChat(state);
    }

    function initDeepChatInstance(container) {
        if (typeof window === 'undefined' || !window.DeepChat) {
            return;
        }

        var config = getDatasetConfig(container);
        if (!config || !config.chatEndpoint || !config.assistantId) {
            return;
        }

        var chatElement = document.createElement('deep-chat');
        container.appendChild(chatElement);

        var state = {
            container: container,
            chat: chatElement,
            config: config,
            conversation: [],
            pendingUploads: [],
            busy: false,
        };

        ACTIVE_INSTANCES.set(container, state);

        if (!chatElement.files || typeof chatElement.files !== 'object') {
            chatElement.files = {};
        }

        try {
            chatElement.files = Object.assign({}, chatElement.files, createFilesConfig(state));
        } catch (error) {
            // Ignore file configuration errors.
        }

        chatElement.addEventListener('message', function (event) {
            onNewMessage(state, event);
        });

        addSystemMessage(chatElement, 'Ready.');
    }

    function initAll() {
        var nodes = document.querySelectorAll('[data-wp-mcp-ai-deep-chat="1"]');
        if (!nodes.length) {
            return;
        }

        Array.prototype.forEach.call(nodes, function (node) {
            if (ACTIVE_INSTANCES.has(node)) {
                return;
            }

            initDeepChatInstance(node);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
