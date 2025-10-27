(function () {
    'use strict';

    var globalConfig = window.wpMcpAiChat || {};
    var ACTIVE_INSTANCES = new WeakMap();
    var SPEECH_TOOL_NAME = 'generate_openai_speech';
    var SPEECH_BUTTON_CLASS = 'wp-mcp-ai-speech-button';
    var SPEECH_ENABLED_CLASS = 'wp-mcp-ai-speech-enabled';
    var SPEECH_ERROR_CLASS = 'wp-mcp-ai-speech-button--error';
    var SPEECH_STYLE_FLAG = '__wpMcpAiSpeechStylesApplied';
    var SPEECH_PLAY_ICON =
        '<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 4l9 6-9 6V4z"></path></svg>';
    var SPEECH_STOP_ICON =
        '<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect x="6" y="5" width="8" height="10" rx="1"></rect></svg>';
    var SPEECH_SPINNER_ICON =
        '<span class="wp-mcp-ai-speech-spinner" aria-hidden="true"></span>';

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
            canUploadAttachments: element.dataset.canUploadAttachments
                ? element.dataset.canUploadAttachments === 'true'
                : true,
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

    function addAssistantMessage(state, display) {
        if (!state || !state.chat || !display) {
            return;
        }

        var chat = state.chat;
        var text = display.text || '';
        var message = {
            role: 'assistant',
            text: text,
        };

        if (display.attachments && display.attachments.length) {
            message.files = display.attachments;
        }

        chat.addMessage(message);

        if (typeof text === 'string' && text.trim()) {
            queueSpeechButtonAttachment(state, text);
        }
    }

    function ensureSpeechStyles(chatElement) {
        if (!chatElement || !chatElement.shadowRoot) {
            return;
        }

        if (chatElement[SPEECH_STYLE_FLAG]) {
            return;
        }

        var style = document.createElement('style');
        style.textContent =
            '.' +
            SPEECH_ENABLED_CLASS +
            ' { position: relative; }\n' +
            '.' +
            SPEECH_BUTTON_CLASS +
            ' { position: absolute; top: 0.35rem; right: 0.35rem; display: inline-flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; border-radius: 999px; border: none; background: rgba(15, 23, 42, 0.75); color: #fff; cursor: pointer; transition: background-color 0.2s ease, opacity 0.2s ease; padding: 0; }\n' +
            '.' + SPEECH_BUTTON_CLASS + ':hover { background: rgba(15, 23, 42, 0.9); }\n' +
            '.' + SPEECH_BUTTON_CLASS + ':focus { outline: none; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.45); }\n' +
            '.' + SPEECH_BUTTON_CLASS + ':disabled { opacity: 0.6; cursor: default; }\n' +
            '.' +
            SPEECH_BUTTON_CLASS +
            '.' +
            SPEECH_ERROR_CLASS +
            ' { background: rgba(220, 38, 38, 0.85); }\n' +
            '.' + SPEECH_BUTTON_CLASS + ' .wp-mcp-ai-speech-icon { width: 1rem; height: 1rem; fill: currentColor; }\n' +
            '.' + SPEECH_BUTTON_CLASS + ' .wp-mcp-ai-speech-spinner { display: inline-block; width: 1rem; height: 1rem; border-radius: 999px; border: 2px solid currentColor; border-top-color: transparent; animation: wp-mcp-ai-speech-spin 0.75s linear infinite; }\n' +
            '@keyframes wp-mcp-ai-speech-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';

        chatElement.shadowRoot.appendChild(style);
        chatElement[SPEECH_STYLE_FLAG] = true;
    }

    function queueSpeechButtonAttachment(state, text) {
        if (!state || !state.chat || !state.config || !state.config.toolsEndpoint || !state.config.assistantId) {
            return;
        }

        if (typeof text !== 'string') {
            return;
        }

        var trimmed = text.trim();
        if (!trimmed) {
            return;
        }

        ensureSpeechStyles(state.chat);

        var schedule = typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function'
            ? window.requestAnimationFrame
            : function (callback) {
                  return setTimeout(callback, 0);
              };

        schedule(function () {
            attachSpeechButtonToLatestAssistant(state, trimmed);
        });
    }

    function attachSpeechButtonToLatestAssistant(state, text) {
        if (!state || !state.chat) {
            return;
        }

        var chatElement = state.chat;
        var ref = findLatestAssistantMessageRef(chatElement);

        if (!ref || !ref.bubble) {
            return;
        }

        var bubble = ref.bubble;
        if (bubble.classList) {
            bubble.classList.add(SPEECH_ENABLED_CLASS);
        }

        var existing = bubble.querySelector('.' + SPEECH_BUTTON_CLASS);
        if (existing) {
            existing.dataset.speechText = text;
            return;
        }

        var button = createSpeechButton(state, text);
        bubble.appendChild(button);
    }

    function findLatestAssistantMessageRef(chatElement) {
        if (!chatElement) {
            return null;
        }

        var manager = chatElement._messages;
        if (manager && Array.isArray(manager.messageElementRefs)) {
            for (var i = manager.messageElementRefs.length - 1; i >= 0; i--) {
                var ref = manager.messageElementRefs[i];
                if (!ref || !ref.outerContainer) {
                    continue;
                }

                if (ref.outerContainer.classList && ref.outerContainer.classList.contains('deep-chat-outer-container-role-assistant')) {
                    return {
                        outer: ref.outerContainer,
                        bubble: ref.bubbleElement || ref.outerContainer,
                    };
                }
            }
        }

        if (chatElement.shadowRoot) {
            var nodes = chatElement.shadowRoot.querySelectorAll('.deep-chat-outer-container-role-assistant');
            if (nodes && nodes.length) {
                var outer = nodes[nodes.length - 1];
                var bubble = outer.querySelector('.deep-chat-message');
                return {
                    outer: outer,
                    bubble: bubble || outer,
                };
            }
        }

        return null;
    }

    function createSpeechButton(state, text) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = SPEECH_BUTTON_CLASS;
        button.dataset.speechText = text;
        button.setAttribute('aria-label', 'Play response audio');
        button.setAttribute('title', 'Play response audio');

        updateSpeechButtonIcon(button, 'idle');

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            handleSpeechButtonClick(state, button);
        });

        return button;
    }

    function updateSpeechButtonIcon(button, stateName) {
        if (!button) {
            return;
        }

        if (button.classList) {
            button.classList.remove(SPEECH_ERROR_CLASS);
        }

        button.dataset.state = stateName;

        if (stateName === 'loading') {
            button.innerHTML = SPEECH_SPINNER_ICON;
            button.setAttribute('aria-label', 'Generating audio...');
            button.setAttribute('title', 'Generating audio...');
            button.setAttribute('aria-busy', 'true');
            return;
        }

        button.removeAttribute('aria-busy');

        if (stateName === 'playing') {
            button.innerHTML = SPEECH_STOP_ICON;
            button.setAttribute('aria-label', 'Stop audio playback');
            button.setAttribute('title', 'Stop audio playback');
            return;
        }

        button.innerHTML = SPEECH_PLAY_ICON;
        button.setAttribute('aria-label', 'Play response audio');
        button.setAttribute('title', 'Play response audio');
    }

    function setSpeechButtonErrorState(state, button, text) {
        if (!button) {
            return;
        }

        button.dataset.state = 'error';
        button.innerHTML = SPEECH_PLAY_ICON;
        button.setAttribute('aria-label', 'Unable to generate audio');
        button.setAttribute('title', 'Unable to generate audio');
        button.removeAttribute('aria-busy');

        if (button.classList) {
            button.classList.add(SPEECH_ERROR_CLASS);
        }

        if (button._wpMcpAiAudio) {
            try {
                button._wpMcpAiAudio.pause();
            } catch (error) {}
        }

        button._wpMcpAiAudio = null;

        if (state && state.activeSpeech && state.activeSpeech.button === button) {
            state.activeSpeech = null;
        }

        if (state && state.speechCache && text) {
            delete state.speechCache[text];
        }
    }

    function handleSpeechButtonClick(state, button) {
        if (!state || !button) {
            return;
        }

        var text = button.dataset.speechText || '';
        if (!text) {
            return;
        }

        var current = button.dataset.state;
        if (current === 'loading') {
            return;
        }

        if (current === 'playing') {
            stopSpeechPlayback(state, button);
            return;
        }

        var cache = state.speechCache && state.speechCache[text];
        if (cache && cache.url) {
            ensureSpeechAudio(state, button, cache.url, text);
            return;
        }

        updateSpeechButtonIcon(button, 'loading');
        button.disabled = true;

        requestSpeechAudio(state, text)
            .then(function (info) {
                if (!info || !info.url) {
                    throw new Error('Invalid speech response');
                }

                if (!state.speechCache) {
                    state.speechCache = Object.create(null);
                }

                state.speechCache[text] = { url: info.url };
                ensureSpeechAudio(state, button, info.url, text);
            })
            .catch(function () {
                setSpeechButtonErrorState(state, button, text);
            })
            .finally(function () {
                button.disabled = false;
                if (button.dataset.state === 'loading') {
                    updateSpeechButtonIcon(button, 'idle');
                }
            });
    }

    function ensureSpeechAudio(state, button, url, text) {
        if (!url) {
            return;
        }

        var audio = button._wpMcpAiAudio;
        if (!audio || audio.src !== url) {
            audio = createSpeechAudio(state, button, url, text);
            button._wpMcpAiAudio = audio;
        }

        startSpeechPlayback(state, button, audio);
    }

    function createSpeechAudio(state, button, url, text) {
        var audio = new Audio(url);
        audio.preload = 'auto';

        audio.addEventListener('ended', function () {
            if (state.activeSpeech && state.activeSpeech.audio === audio) {
                state.activeSpeech = null;
            }
            updateSpeechButtonIcon(button, 'idle');
        });

        audio.addEventListener('pause', function () {
            if (button.dataset && button.dataset.state === 'error') {
                return;
            }
            if (!audio.duration || audio.currentTime < audio.duration) {
                if (state.activeSpeech && state.activeSpeech.audio === audio) {
                    state.activeSpeech = null;
                }
                updateSpeechButtonIcon(button, 'idle');
            }
        });

        audio.addEventListener('play', function () {
            state.activeSpeech = { button: button, audio: audio, text: text };
            updateSpeechButtonIcon(button, 'playing');
        });

        audio.addEventListener('error', function () {
            setSpeechButtonErrorState(state, button, text);
        });

        return audio;
    }

    function startSpeechPlayback(state, button, audio) {
        if (!audio) {
            return;
        }

        if (state.activeSpeech && state.activeSpeech.audio && state.activeSpeech.audio !== audio) {
            try {
                state.activeSpeech.audio.pause();
            } catch (error) {}

            if (state.activeSpeech.button) {
                updateSpeechButtonIcon(state.activeSpeech.button, 'idle');
            }
        }

        audio.currentTime = 0;

        var promise = audio.play();
        if (promise && typeof promise.then === 'function') {
            promise.catch(function () {
                var currentText = button.dataset ? button.dataset.speechText || text : text;
                setSpeechButtonErrorState(state, button, currentText);
            });
        }
    }

    function stopSpeechPlayback(state, button) {
        if (!state || !button) {
            return;
        }

        var audio = button._wpMcpAiAudio;
        if (!audio && state.activeSpeech && state.activeSpeech.button === button) {
            audio = state.activeSpeech.audio;
        }

        if (audio) {
            try {
                audio.pause();
            } catch (error) {}

            try {
                audio.currentTime = 0;
            } catch (error) {}
        }

        if (state.activeSpeech && state.activeSpeech.button === button) {
            state.activeSpeech = null;
        }

        updateSpeechButtonIcon(button, 'idle');
    }

    function requestSpeechAudio(state, text) {
        if (!state || !state.config || !state.config.toolsEndpoint) {
            return Promise.reject(new Error('Speech tool unavailable.'));
        }

        var payload = {
            assistant_id: state.config.assistantId,
            tool: SPEECH_TOOL_NAME,
            arguments: {
                text: text,
            },
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
            .then(function (body) {
                if (!body || typeof body !== 'object') {
                    return Promise.reject(new Error('Invalid response.'));
                }

                var result = Object.prototype.hasOwnProperty.call(body, 'result') ? body.result : body;
                if (!result || typeof result !== 'object' || !result.url) {
                    return Promise.reject(new Error('Missing audio result.'));
                }

                return {
                    url: result.url,
                    attachmentId: result.attachment_id,
                    format: result.format,
                    mimeType: result.mime_type,
                };
            });
    }

    function uploadAttachment(state, file) {
        if (!state || state.config.canUploadAttachments === false) {
            return Promise.reject(new Error('Upload unavailable.'));
        }

        if (!file || !state.config.uploadEndpoint) {
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
            addAssistantMessage(state, display);
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
            speechCache: Object.create(null),
            activeSpeech: null,
        };

        ACTIVE_INSTANCES.set(container, state);

        if (!config.canUploadAttachments) {
            chatElement.files = Object.assign({}, chatElement.files, { disabled: true });
        } else {
            if (!chatElement.files || typeof chatElement.files !== 'object') {
                chatElement.files = {};
            }

            try {
                chatElement.files = Object.assign({}, chatElement.files, createFilesConfig(state));
            } catch (error) {
                // Ignore file configuration errors.
            }
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
