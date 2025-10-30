(function () {
    'use strict';

    var globalConfig = window.wpMcpAiChat || {};
    var instances = window.wpMcpAiChatInstances || {};
    var objectUrlRegistry = [];
    var SPEECH_TOOL_NAME = 'generate_openai_speech';
    var SPEECH_BUTTON_CLASS = 'wp-mcp-ai-speech-button';
    var SPEECH_ENABLED_CLASS = 'wp-mcp-ai-speech-enabled';
    var SPEECH_ERROR_CLASS = 'wp-mcp-ai-speech-button--error';
    var SPEECH_PLAY_ICON = '<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 4l9 6-9 6V4z"></path></svg>';
    var SPEECH_STOP_ICON = '<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect x="6" y="5" width="8" height="10" rx="1"></rect></svg>';
    var SPEECH_SPINNER_ICON = '<span class="wp-mcp-ai-speech-spinner" aria-hidden="true"></span>';
    var COPY_BUTTON_CLASS = 'wp-mcp-ai-copy-button';
    var COPY_ENABLED_CLASS = 'wp-mcp-ai-copy-enabled';
    var COPY_ERROR_CLASS = 'wp-mcp-ai-copy-button--error';
    var COPY_ICON = '<svg class="wp-mcp-ai-copy-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 5a2 2 0 012-2h7a2 2 0 012 2v9a2 2 0 01-2 2H8a2 2 0 01-2-2zm2-1a1 1 0 00-1 1v9a1 1 0 001 1h7a1 1 0 001-1V5a1 1 0 00-1-1z"></path><path d="M4 7a2 2 0 012-2v1a1 1 0 00-1 1v9a1 1 0 001 1h7a1 1 0 001-1h1a2 2 0 01-2 2H6a2 2 0 01-2-2z"></path></svg>';
    var COPY_SUCCESS_ICON = '<svg class="wp-mcp-ai-copy-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M8.293 12.293l-2.147-2.146 1.414-1.414L9 10.586l3.44-3.44 1.414 1.415L9 13.414z"></path><path d="M6 3a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2zm0 1h8a1 1 0 011 1v10a1 1 0 01-1 1H6a1 1 0 01-1-1V5a1 1 0 011-1z"></path></svg>';
    var TOOL_SHORTCUT_CONTAINER_CLASS = 'wp-mcp-ai-chat__tool-shortcuts';
    var TOOL_SHORTCUT_BUTTON_CLASS = 'wp-mcp-ai-chat__tool-shortcut';

    function registerObjectUrl(url) {
        if (!url) {
            return;
        }

        objectUrlRegistry.push(url);
    }

    function revokeObjectUrls() {
        if (!objectUrlRegistry.length) {
            return;
        }

        objectUrlRegistry.forEach(function (url) {
            try {
                URL.revokeObjectURL(url);
            } catch (error) {
                // Ignore revoke errors.
            }
        });

        objectUrlRegistry = [];
    }

    function normalizeSpeechText(text) {
        if (typeof text !== 'string') {
            return '';
        }

        return text.trim();
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

    function clearSpeechCacheEntry(state, text) {
        if (!state || !state.speechCache || !text) {
            return;
        }

        delete state.speechCache[text];
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
        button.disabled = false;

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

        clearSpeechCacheEntry(state, text);
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

    function startSpeechPlayback(state, button, audio, text) {
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

        var playPromise = audio.play();
        if (playPromise && typeof playPromise.then === 'function') {
            playPromise.catch(function () {
                var currentText = button.dataset ? button.dataset.speechText || text : text;
                setSpeechButtonErrorState(state, button, currentText);
            });
        }
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

    function ensureSpeechAudio(state, button, url, text) {
        if (!url) {
            return;
        }

        var audio = button._wpMcpAiAudio;
        if (!audio || audio.src !== url) {
            audio = createSpeechAudio(state, button, url, text);
            button._wpMcpAiAudio = audio;
        }

        startSpeechPlayback(state, button, audio, text);
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
            headers: buildJsonHeaders(state),
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

    function handleSpeechButtonClick(state, button) {
        if (!state || !button) {
            return;
        }

        var text = normalizeSpeechText(button.dataset.speechText || '');
        if (!text) {
            return;
        }

        var currentState = button.dataset.state;
        if (currentState === 'loading') {
            return;
        }

        if (currentState === 'playing') {
            stopSpeechPlayback(state, button);
            return;
        }

        if (!state.speechCache) {
            state.speechCache = Object.create(null);
        }

        var cache = state.speechCache[text];
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

    function resolveSpeechText(bubble, text) {
        var provided = normalizeSpeechText(text || '');
        if (provided) {
            return provided;
        }

        if (bubble && bubble.dataset && bubble.dataset.speechText) {
            var stored = normalizeSpeechText(bubble.dataset.speechText);
            if (stored) {
                return stored;
            }
        }

        if (!bubble) {
            return '';
        }

        var textContent = '';
        if (typeof bubble.textContent === 'string') {
            textContent = bubble.textContent;
        } else if (bubble.innerText) {
            textContent = bubble.innerText;
        }

        return normalizeSpeechText(textContent);
    }

    function attachSpeechButton(bubble, state, text) {
        if (!bubble || !state || !state.config || !state.config.toolsEndpoint || !state.config.assistantId) {
            return;
        }

        var normalisedText = resolveSpeechText(bubble, text);
        if (!normalisedText) {
            return;
        }

        if (bubble.classList) {
            bubble.classList.add(SPEECH_ENABLED_CLASS);
        }

        if (bubble.dataset) {
            bubble.dataset.speechText = normalisedText;
        }

        if (!state.speechCache) {
            state.speechCache = Object.create(null);
        }

        var existing = bubble.querySelector('.' + SPEECH_BUTTON_CLASS);
        if (existing) {
            var previousText = normalizeSpeechText(existing.dataset.speechText || '');

            if (previousText && previousText !== normalisedText) {
                stopSpeechPlayback(state, existing);
                clearSpeechCacheEntry(state, previousText);
            }

            existing.dataset.speechText = normalisedText;
            existing.disabled = false;
            updateSpeechButtonIcon(existing, 'idle');
            return;
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = SPEECH_BUTTON_CLASS;
        button.dataset.speechText = normalisedText;
        button.setAttribute('aria-label', 'Play response audio');
        button.setAttribute('title', 'Play response audio');

        updateSpeechButtonIcon(button, 'idle');

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            handleSpeechButtonClick(state, button);
        });

        bubble.appendChild(button);
    }

    function updateCopyButtonState(button, stateName) {
        if (!button) {
            return;
        }

        button.classList.remove(COPY_ERROR_CLASS);
        button.dataset.state = stateName;

        if (stateName === 'copied') {
            button.innerHTML = COPY_SUCCESS_ICON;
            button.setAttribute('aria-label', 'Copied response');
            button.setAttribute('title', 'Copied response');
            return;
        }

        if (stateName === 'error') {
            button.innerHTML = COPY_ICON;
            button.setAttribute('aria-label', 'Unable to copy');
            button.setAttribute('title', 'Unable to copy');
            button.classList.add(COPY_ERROR_CLASS);
            return;
        }

        button.innerHTML = COPY_ICON;
        button.setAttribute('aria-label', 'Copy response');
        button.setAttribute('title', 'Copy response');
    }

    function copyTextToClipboard(text) {
        if (!text) {
            return Promise.resolve(false);
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard
                .writeText(text)
                .then(function () {
                    return true;
                })
                .catch(function () {
                    return fallbackCopyText(text);
                });
        }

        return fallbackCopyText(text);
    }

    function fallbackCopyText(text) {
        return new Promise(function (resolve) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';

            document.body.appendChild(textarea);

            var selection = document.getSelection ? document.getSelection().rangeCount : 0;

            textarea.select();
            textarea.setSelectionRange(0, textarea.value.length);

            var succeeded = false;

            try {
                succeeded = document.execCommand('copy');
            } catch (error) {
                succeeded = false;
            }

            document.body.removeChild(textarea);

            if (selection && document.getSelection) {
                try {
                    document.getSelection().removeAllRanges();
                } catch (error) {}
            }

            resolve(Boolean(succeeded));
        });
    }

    function attachCopyButton(bubble, text) {
        if (!bubble) {
            return;
        }

        var normalisedText = resolveSpeechText(bubble, text);
        if (!normalisedText) {
            return;
        }

        if (bubble.classList) {
            bubble.classList.add(COPY_ENABLED_CLASS);
        }

        if (bubble.dataset) {
            bubble.dataset.copyText = normalisedText;
        }

        var existing = bubble.querySelector('.' + COPY_BUTTON_CLASS);
        if (existing) {
            existing.dataset.copyText = normalisedText;
            existing.disabled = false;
            updateCopyButtonState(existing, 'idle');
            return;
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = COPY_BUTTON_CLASS;
        button.dataset.copyText = normalisedText;

        updateCopyButtonState(button, 'idle');

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var textToCopy = resolveSpeechText(bubble, button.dataset.copyText || text);
            if (!textToCopy) {
                updateCopyButtonState(button, 'error');
                setTimeout(function () {
                    updateCopyButtonState(button, 'idle');
                }, 2000);
                return;
            }

            button.disabled = true;

            copyTextToClipboard(textToCopy)
                .then(function (success) {
                    if (success) {
                        updateCopyButtonState(button, 'copied');
                    } else {
                        updateCopyButtonState(button, 'error');
                    }

                    setTimeout(function () {
                        updateCopyButtonState(button, 'idle');
                        button.disabled = false;
                    }, 2000);
                })
                .catch(function () {
                    updateCopyButtonState(button, 'error');
                    setTimeout(function () {
                        updateCopyButtonState(button, 'idle');
                        button.disabled = false;
                    }, 2000);
                });
        });

        bubble.appendChild(button);
    }

    function handleToolShortcutClick(state, button) {
        if (!state || !button || !state.textarea) {
            return;
        }

        var payload = '';

        if (button.dataset) {
            payload = button.dataset.shortcutPayload || button.dataset.shortcutTool || '';
        }

        if (typeof payload !== 'string') {
            payload = '';
        }

        payload = payload.trim();

        if (!payload) {
            return;
        }

        state.textarea.value = payload;
        state.textarea.focus();

        try {
            var caret = state.textarea.value.length;
            state.textarea.setSelectionRange(caret, caret);
        } catch (error) {}

        copyTextToClipboard(payload).catch(function () {});
    }

    function renderToolShortcuts(state) {
        if (!state || !state.toolShortcutsContainer) {
            return;
        }

        var container = state.toolShortcutsContainer;
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }

        var shortcuts = [];
        if (state.config && Array.isArray(state.config.toolShortcuts)) {
            shortcuts = state.config.toolShortcuts;
        }

        shortcuts.forEach(function (shortcut) {
            if (!shortcut) {
                return;
            }

            var label = '';
            var payload = '';
            var tool = '';
            var description = '';

            if (typeof shortcut === 'string') {
                label = shortcut;
                payload = shortcut;
            } else if (typeof shortcut === 'object') {
                if (typeof shortcut.label === 'string') {
                    label = shortcut.label;
                }

                if (typeof shortcut.payload === 'string') {
                    payload = shortcut.payload;
                }

                if (typeof shortcut.tool === 'string') {
                    tool = shortcut.tool;
                }

                if (typeof shortcut.description === 'string') {
                    description = shortcut.description;
                }
            }

            label = label ? label.trim() : '';
            payload = payload ? payload.trim() : '';
            tool = tool ? tool.trim() : '';
            description = description ? description.trim() : '';

            if (!label && tool) {
                label = tool;
            }

            if (!label && !payload) {
                return;
            }

            if (!payload) {
                payload = tool || label;
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = TOOL_SHORTCUT_BUTTON_CLASS;
            button.textContent = label;

            if (tool) {
                button.dataset.shortcutTool = tool;
            }

            if (payload) {
                button.dataset.shortcutPayload = payload;
            }

            if (description) {
                button.dataset.shortcutDescription = description;
            }

            var ariaTemplate = getString('toolShortcutLabel', 'Insert task: %s');
            var ariaLabel = formatString(ariaTemplate, label);

            if (description) {
                ariaLabel += '. ' + description;
            }

            button.setAttribute('aria-label', ariaLabel);
            button.setAttribute('title', description || label);

            button.addEventListener('click', function (event) {
                event.preventDefault();
                handleToolShortcutClick(state, button);
            });

            container.appendChild(button);
        });

        container.hidden = !container.children.length;
    }

    function initialiseExistingSpeechButtons(state) {
        if (!state || !state.messagesEl) {
            return;
        }

        var selector = '.wp-mcp-ai-chat__message--assistant .wp-mcp-ai-chat__bubble';
        var bubbles = state.messagesEl.querySelectorAll(selector);

        Array.prototype.forEach.call(bubbles, function (bubble) {
            var storedText = bubble && bubble.dataset ? bubble.dataset.speechText || '' : '';
            attachSpeechButton(bubble, state, storedText);
            attachCopyButton(bubble, storedText);
        });
    }

    function normaliseList(value) {
        if (!Array.isArray(value)) {
            return [];
        }

        var seen = {};
        var result = [];

        value.forEach(function (item) {
            if (typeof item !== 'string') {
                return;
            }

            var normalised = item.trim().toLowerCase();
            if (!normalised || seen[normalised]) {
                return;
            }

            seen[normalised] = true;
            result.push(normalised);
        });

        return result;
    }

    function getFileExtension(file) {
        if (!file || !file.name) {
            return '';
        }

        var name = String(file.name);
        var dotIndex = name.lastIndexOf('.');

        if (dotIndex === -1) {
            return '';
        }

        return name.slice(dotIndex + 1).toLowerCase();
    }

    function isFileTypeAllowed(file, state) {
        if (!file) {
            return false;
        }

        if (!state || !state.config) {
            return true;
        }

        var allowedImageMimes = Array.isArray(state.config.allowedImageMimes) ? state.config.allowedImageMimes : [];
        var allowedFileMimes = Array.isArray(state.config.allowedFileMimes) ? state.config.allowedFileMimes : [];
        var allowedExtensions = Array.isArray(state.config.allowedExtensions) ? state.config.allowedExtensions : [];

        if (!allowedImageMimes.length && !allowedFileMimes.length && !allowedExtensions.length) {
            return true;
        }

        var mime = (file.type || '').toLowerCase();

        if (mime) {
            var semicolonIndex = mime.indexOf(';');

            if (semicolonIndex !== -1) {
                mime = mime.slice(0, semicolonIndex);
            }

            mime = mime.trim();
        }

        if (mime) {
            if (allowedImageMimes.indexOf(mime) !== -1 || allowedFileMimes.indexOf(mime) !== -1) {
                return true;
            }

            var extensionFromMime = getFileExtension(file);
            if (extensionFromMime && allowedExtensions.indexOf(extensionFromMime) !== -1) {
                return true;
            }

            return false;
        }

        var extension = getFileExtension(file);
        if (extension) {
            return allowedExtensions.indexOf(extension) !== -1;
        }

        return true;
    }

    if (typeof window !== 'undefined' && window.addEventListener) {
        window.addEventListener('beforeunload', revokeObjectUrls);
    }

    function getString(key, fallback) {
        if (globalConfig.strings && Object.prototype.hasOwnProperty.call(globalConfig.strings, key)) {
            return globalConfig.strings[key];
        }
        return fallback;
    }

    function setTranscriptExpanded(state, expanded) {
        if (!state) {
            return;
        }

        state.transcriptExpanded = !!expanded;

        if (state.container && state.container.classList) {
            if (state.transcriptExpanded) {
                state.container.classList.add('wp-mcp-ai-chat--expanded');
            } else {
                state.container.classList.remove('wp-mcp-ai-chat--expanded');
            }
        }

        if (state.transcriptToggle) {
            var label = state.transcriptExpanded
                ? getString('collapseTranscript', 'Collapse conversation')
                : getString('expandTranscript', 'Expand conversation');
            state.transcriptToggle.setAttribute('aria-expanded', state.transcriptExpanded ? 'true' : 'false');
            state.transcriptToggle.setAttribute('aria-label', label);

            var screenReaderText = state.transcriptToggle.querySelector('.screen-reader-text');
            if (screenReaderText) {
                screenReaderText.textContent = label;
            }
        }

        if (state.transcriptExpanded && state.messagesEl) {
            state.messagesEl.scrollTop = state.messagesEl.scrollHeight;
        }
    }

    function buildJsonHeaders(state) {
        var headers = {
            'Content-Type': 'application/json',
            'X-WP-Nonce': globalConfig.nonce || '',
        };

        if (state && state.config && state.config.guestToken) {
            headers['X-WP-MCP-AI-Guest'] = state.config.guestToken;
        }

        return headers;
    }

    function handleFileSelection(event, state) {
        if (!state || !state.canUploadAttachments) {
            return;
        }

        if (!event || !event.target || !event.target.files) {
            return;
        }

        state.validationNotice = '';

        var files = Array.prototype.slice.call(event.target.files);
        event.target.value = '';

        if (!files.length) {
            return;
        }

        var allowedFiles = [];
        var rejectedFiles = [];

        files.forEach(function (file) {
            if (isFileTypeAllowed(file, state)) {
                allowedFiles.push(file);
            } else {
                rejectedFiles.push(file);
            }
        });

        if (rejectedFiles.length) {
            var notice;

            if (rejectedFiles.length === 1) {
                var label = (rejectedFiles[0] && rejectedFiles[0].name) || getString('unsupportedFileLabel', 'This file');
                notice = formatString(
                    getString('unsupportedFileType', '“%s” is not a supported file type. Please choose a different file.'),
                    label
                );
            } else {
                notice = getString(
                    'unsupportedMultipleFiles',
                    'Some selected files are not supported. Please try different files.'
                );
            }

            state.validationNotice = notice;
            setStatus(state.container, notice);
        }

        if (!allowedFiles.length) {
            return;
        }

        var sequence = Promise.resolve();

        allowedFiles.forEach(function (file) {
            sequence = sequence.then(function () {
                return uploadAttachment(state, file);
            });
        });

        sequence.catch(function (error) {
            if (window.console && console.error) {
                console.error('Attachment upload failed', error);
            }
        });
    }

    function uploadAttachment(state, file) {
        if (!state || !state.canUploadAttachments) {
            return Promise.resolve();
        }

        if (!file || !state.config || !state.config.uploadEndpoint) {
            return Promise.resolve();
        }

        state.uploading = (state.uploading || 0) + 1;
        updateAttachButtonState(state);

        var message = formatString(getString('uploadingFile', 'Uploading “%s”…'), file.name || '');
        setStatus(state.container, message);

        var hadError = false;

        var headers = {
            'X-WP-Nonce': globalConfig.nonce || '',
            Accept: 'application/json',
        };

        var contentDisposition = createContentDispositionHeader(file.name || 'attachment');
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
                    var error = new Error('Upload failed');
                    error.response = response;
                    throw error;
                }

                return response.json();
            })
            .then(function (data) {
                var record = normaliseUploadResponse(data, file);
                if (!record) {
                    return;
                }

                state.pendingAttachments.push(record);
                state.attachmentLibrary[record.fileId] = record;
                renderPendingAttachments(state);
            })
            .catch(function (error) {
                hadError = true;
                handleUploadError(state, error);
            })
            .finally(function () {
                state.uploading = Math.max(0, (state.uploading || 1) - 1);
                updateAttachButtonState(state);

                if (!state.busy && state.uploading === 0) {
                    if (!hadError && state.validationNotice) {
                        setStatus(state.container, state.validationNotice);
                        state.validationNotice = '';
                    } else if (!hadError) {
                        setStatus(state.container, '');
                    }
                }
            });
    }

    function normaliseUploadResponse(data, file) {
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

        var fileId = 'wp-attachment-' + id;
        var title = '';

        if (data.title) {
            if (typeof data.title === 'string') {
                title = data.title;
            } else if (typeof data.title.rendered === 'string') {
                title = data.title.rendered;
            }
        }

        if (!title && typeof data.slug === 'string') {
            title = data.slug;
        }

        var name = title || (file && file.name) || '';
        var url = data.source_url || (data.guid && data.guid.rendered) || '';
        var mime = data.mime_type || (file && file.type) || '';

        var size = null;
        if (data.media_details && typeof data.media_details === 'object') {
            if (typeof data.media_details.filesize === 'number') {
                size = data.media_details.filesize;
            }
        }

        if (size === null && typeof data.filesize === 'number') {
            size = data.filesize;
        }

        if (size === null && file && typeof file.size === 'number') {
            size = file.size;
        }

        var isImage = typeof mime === 'string' && mime.indexOf('image/') === 0;

        return {
            id: id,
            fileId: fileId,
            name: name || (file ? file.name : ''),
            originalName: file ? file.name : '',
            url: url,
            mime: mime,
            size: size,
            isImage: isImage,
        };
    }

    function handleUploadError(state, error) {
        if (error && error.response && typeof error.response.json === 'function') {
            error.response
                .json()
                .then(function (body) {
                    var message = body && (body.message || (body.data && body.data.message));
                    setStatus(state.container, message || getString('uploadError', 'The file could not be uploaded. Please try again.'));
                })
                .catch(function () {
                    setStatus(state.container, getString('uploadError', 'The file could not be uploaded. Please try again.'));
                });
        } else {
            setStatus(state.container, getString('uploadError', 'The file could not be uploaded. Please try again.'));
        }

        if (window.console && console.error) {
            console.error('File upload failed', error);
        }
    }

    function renderPendingAttachments(state) {
        var container = state.attachmentsContainer;
        var list = state.attachmentsList;

        if (!container || !list) {
            return;
        }

        list.innerHTML = '';

        if (!state.pendingAttachments.length) {
            container.hidden = true;
            return;
        }

        container.hidden = false;

        state.pendingAttachments.forEach(function (attachment) {
            var item = document.createElement('li');
            item.className = 'wp-mcp-ai-chat__attachments-item';

            var info = document.createElement('div');
            info.className = 'wp-mcp-ai-chat__attachments-info';

            var name = document.createElement('div');
            name.className = 'wp-mcp-ai-chat__attachments-name';
            name.textContent = attachment.name || attachment.originalName || getString('downloadAttachment', 'Download attachment');
            info.appendChild(name);

            var metaText = buildAttachmentMeta(attachment);
            if (metaText) {
                var meta = document.createElement('div');
                meta.className = 'wp-mcp-ai-chat__attachments-meta';
                meta.textContent = metaText;
                info.appendChild(meta);
            }

            item.appendChild(info);

            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'wp-mcp-ai-chat__attachments-remove';
            removeButton.textContent = getString('removeAttachment', 'Remove');
            removeButton.addEventListener('click', function () {
                removePendingAttachment(state, attachment.fileId);
            });

            if (state.busy || state.uploading > 0) {
                removeButton.disabled = true;
            }

            item.appendChild(removeButton);
            list.appendChild(item);
        });
    }

    function removePendingAttachment(state, fileId) {
        if (!fileId) {
            return;
        }

        state.pendingAttachments = state.pendingAttachments.filter(function (attachment) {
            return attachment.fileId !== fileId;
        });

        renderPendingAttachments(state);
        updateAttachButtonState(state);
    }

    function updateAttachButtonState(state) {
        if (!state) {
            return;
        }

        if (!state.canUploadAttachments) {
            if (state.attachButton) {
                state.attachButton.disabled = true;
            }

            if (state.fileInput) {
                state.fileInput.disabled = true;
            }

            return;
        }

        var disabled = !!state.busy || state.uploading > 0;

        if (state.attachButton) {
            state.attachButton.disabled = disabled;
        }

        if (state.fileInput) {
            state.fileInput.disabled = disabled;
        }

        if (state.attachmentsList) {
            var removeButtons = state.attachmentsList.querySelectorAll('.wp-mcp-ai-chat__attachments-remove');
            Array.prototype.forEach.call(removeButtons, function (button) {
                button.disabled = disabled;
            });
        }
    }

    function buildDisplayAttachment(attachment, state) {
        if (!attachment || typeof attachment !== 'object') {
            return null;
        }

        var record = attachment;

        if (attachment.fileId && state && state.attachmentLibrary && state.attachmentLibrary[attachment.fileId]) {
            record = state.attachmentLibrary[attachment.fileId];
        }

        var url = getAttachmentUrlFromRecord(record, state) || attachment.url || '';
        if (!url) {
            return null;
        }

        var label = record.name || record.originalName || attachment.name || attachment.originalName || '';
        if (!label) {
            label = getString('downloadAttachment', 'Download attachment');
        }

        return {
            url: url,
            label: label,
            downloadName: record.originalName || record.name || '',
            meta: buildAttachmentMeta(record),
        };
    }

    function createSegmentFromAttachment(attachment) {
        if (!attachment) {
            return null;
        }

        var id = attachment.id;

        if (!id && attachment.fileId && attachment.fileId.indexOf('wp-attachment-') === 0) {
            var parsed = parseInt(attachment.fileId.replace('wp-attachment-', ''), 10);
            if (!isNaN(parsed)) {
                id = parsed;
            }
        }

        if (!id) {
            return null;
        }

        var mime = attachment.mime || attachment.type || '';
        var isImage = typeof attachment.isImage === 'boolean' ? attachment.isImage : typeof mime === 'string' && mime.indexOf('image/') === 0;

        if (isImage) {
            return {
                type: 'input_image',
                attachment_id: id,
            };
        }

        var segment = {
            type: 'input_file',
            attachment_id: id,
        };

        var displayName = attachment.originalName || attachment.name || '';
        if (displayName) {
            segment.display_name = displayName;
        }

        return segment;
    }

    function prepareAssistantDisplay(message, state) {
        var text = '';
        var attachments = [];

        if (message && typeof message.content !== 'undefined') {
            text = normaliseContent(message.content);
        }

        attachments = extractAttachmentsFromMessage(message, state);

        return {
            text: text,
            attachments: attachments,
        };
    }

    function extractAttachmentsFromMessage(message, state) {
        if (!message) {
            return [];
        }

        var lookup = buildAttachmentLookup(message, state);
        var attachments = [];
        var attachmentsByKey = {};
        var defaultAttachmentLabel = getString('downloadAttachment', 'Download attachment');

        function normaliseMeta(meta) {
            if (!meta) {
                return '';
            }

            return String(meta).replace(/\s+/g, ' ').trim();
        }

        function mergeMeta(extra, existing) {
            if (!extra) {
                return existing || '';
            }

            if (!existing) {
                return extra;
            }

            if (existing.indexOf(extra) !== -1) {
                return existing;
            }

            return extra + ' • ' + existing;
        }

        function registerAttachmentEntry(key, entry, extraMeta, fallbackLabel) {
            if (!key) {
                return;
            }

            var extra = normaliseMeta(extraMeta);
            var label = fallbackLabel ? String(fallbackLabel).trim() : '';
            var existing = attachmentsByKey[key];

            if (existing) {
                if (label && (!existing.label || existing.label === defaultAttachmentLabel)) {
                    existing.label = label;
                }

                if (extra) {
                    existing.meta = mergeMeta(extra, existing.meta);
                }

                return;
            }

            if (label) {
                entry.label = label;
            }

            if (extra) {
                entry.meta = mergeMeta(extra, entry.meta);
            }

            attachmentsByKey[key] = entry;
            attachments.push(entry);
        }

        function addAttachment(record, fallbackLabel, extraMeta) {
            if (!record) {
                return;
            }

            var url = getAttachmentUrlFromRecord(record, state);
            if (!url) {
                return;
            }

            var key = record.fileId || url;
            var label = record.name || record.originalName || record.downloadName || '';
            if (!label) {
                label = defaultAttachmentLabel;
            }

            registerAttachmentEntry(
                key,
                {
                    url: url,
                    label: label,
                    downloadName: record.downloadName || record.originalName || record.name || '',
                    meta: buildAttachmentMeta(record),
                },
                extraMeta,
                fallbackLabel
            );
        }

        function addRemoteAttachment(url, label, extraMeta) {
            if (!url) {
                return;
            }

            var key = 'url:' + url;
            registerAttachmentEntry(
                key,
                {
                    url: url,
                    label: label || defaultAttachmentLabel,
                    downloadName: '',
                    meta: '',
                },
                extraMeta,
                label
            );
        }

        function processAnnotationCandidate(candidate, fallbackLabel) {
            if (!candidate || typeof candidate !== 'object') {
                return;
            }

            if (Array.isArray(candidate)) {
                candidate.forEach(function (entry) {
                    processAnnotationCandidate(entry, fallbackLabel);
                });
                return;
            }

            var label = fallbackLabel || candidate.label || candidate.title || candidate.name || candidate.text || '';
            var extraMeta = candidate.quote || candidate.meta || '';
            var fileId = '';

            if (candidate.file_id) {
                fileId = candidate.file_id;
            } else if (candidate.id && String(candidate.id).indexOf('file-') === 0) {
                fileId = candidate.id;
            }

            if (!fileId && candidate.file && typeof candidate.file === 'object') {
                fileId = candidate.file.file_id || candidate.file.id || '';
                if (!label) {
                    label = candidate.file.display_name || candidate.file.filename || candidate.file.name || '';
                }

                if (!extraMeta && candidate.file.caption) {
                    extraMeta = candidate.file.caption;
                }
            }

            if (!fileId && candidate.file_citation && typeof candidate.file_citation === 'object') {
                fileId = candidate.file_citation.file_id || '';
                if (!extraMeta && candidate.file_citation.quote) {
                    extraMeta = candidate.file_citation.quote;
                }
            }

            var record = fileId ? lookup[fileId] : null;
            if (record) {
                addAttachment(record, label, extraMeta);
                return;
            }

            var url = candidate.url || (candidate.link && candidate.link.url) || (candidate.web && candidate.web.url) || '';
            if (!url && candidate.file && typeof candidate.file === 'object') {
                url = candidate.file.url || candidate.file.download_url || candidate.file.href || candidate.file.source_url || '';
            }

            if (url) {
                addRemoteAttachment(url, label, extraMeta);
            }

            if (candidate.attachments && Array.isArray(candidate.attachments)) {
                candidate.attachments.forEach(function (attachment) {
                    processAnnotationCandidate(attachment, label);
                });
            }
        }

        function processAnnotationCollection(collection) {
            if (!collection) {
                return;
            }

            if (Array.isArray(collection)) {
                collection.forEach(function (item) {
                    processAnnotationCandidate(item);
                });
                return;
            }

            processAnnotationCandidate(collection);
        }

        function processSegment(segment) {
            if (segment === null || typeof segment === 'undefined') {
                return;
            }

            if (Array.isArray(segment)) {
                segment.forEach(processSegment);
                return;
            }

            if (typeof segment === 'string') {
                return;
            }

            if (typeof segment !== 'object') {
                return;
            }

            var type = typeof segment.type === 'string' ? segment.type : '';

            if (type === 'tool_result' && segment.content) {
                processSegment(segment.content);
            }

            if (segment.attachments && Array.isArray(segment.attachments)) {
                segment.attachments.forEach(processSegment);
            }

            if (segment.text && typeof segment.text === 'object' && segment.text.annotations) {
                processAnnotationCollection(segment.text.annotations);
            }

            processAnnotationCollection(segment.annotations);

            if (segment.metadata && typeof segment.metadata === 'object') {
                processAnnotationCollection(segment.metadata.annotations);
                processAnnotationCollection(segment.metadata.references);
                processAnnotationCollection(segment.metadata.citations);
            }

            processAnnotationCollection(segment.references);
            processAnnotationCollection(segment.citations);

            if (type === 'input_file' || type === 'output_file' || type === 'file_path' || type === 'file') {
                var fileId = segment.file_id || (segment.file && segment.file.id) || segment.id || '';
                if (fileId && lookup[fileId]) {
                    addAttachment(lookup[fileId], segment.display_name || (segment.file && segment.file.filename), segment.quote || '');
                }
                return;
            }

            if (type === 'input_image' || type === 'image_file' || type === 'output_image') {
                var inlineFileId = segment.file_id || '';
                if (!inlineFileId) {
                    var imageFile = segment.image_file || segment.image || segment.file || null;
                    if (imageFile) {
                        inlineFileId = imageFile.file_id || imageFile.id || '';
                    }
                }

                if (inlineFileId && lookup[inlineFileId]) {
                    var imageMeta = segment.image_file || segment.image || segment.file || {};
                    addAttachment(lookup[inlineFileId], segment.caption || imageMeta.display_name || imageMeta.filename, segment.quote || '');
                    return;
                }

                var url = '';
                if (typeof segment.image_url === 'string') {
                    url = segment.image_url;
                } else if (segment.image_url && typeof segment.image_url.url === 'string') {
                    url = segment.image_url.url;
                } else if (typeof segment.url === 'string') {
                    url = segment.url;
                }
                if (url) {
                    addRemoteAttachment(url, segment.caption || '', segment.quote || '');
                }
                return;
            }

            if (segment.content) {
                processSegment(segment.content);
            }
        }

        processSegment(message.content);
        processAnnotationCollection(message.annotations);

        if (message.metadata && typeof message.metadata === 'object') {
            processAnnotationCollection(message.metadata.annotations);
            processAnnotationCollection(message.metadata.references);
            processAnnotationCollection(message.metadata.citations);
        }

        processAnnotationCollection(message.references);

        return attachments;
    }

    function buildAttachmentLookup(message, state) {
        var lookup = {};

        function shouldReplace(existing, candidate) {
            if (!existing) {
                return true;
            }

            if (!existing.url && candidate.url) {
                return true;
            }

            if (!existing.data && candidate.data) {
                return true;
            }

            if (!existing.name && candidate.name) {
                return true;
            }

            if (!existing.downloadName && candidate.downloadName) {
                return true;
            }

            return false;
        }

        function registerRecord(record) {
            if (!record || !record.fileId) {
                return;
            }

            if (!lookup[record.fileId] || shouldReplace(lookup[record.fileId], record)) {
                lookup[record.fileId] = record;
            }

            if (state && state.attachmentLibrary) {
                var existing = state.attachmentLibrary[record.fileId];
                if (!existing || shouldReplace(existing, record)) {
                    state.attachmentLibrary[record.fileId] = record;
                }
            }
        }

        if (message && Array.isArray(message.attachments)) {
            message.attachments.forEach(function (item) {
                var record = normaliseAttachmentRecord(item);
                if (record) {
                    registerRecord(record);
                }
            });
        }

        if (message && Array.isArray(message.references)) {
            message.references.forEach(function (reference) {
                if (!reference || typeof reference !== 'object') {
                    return;
                }

                var record = null;

                if (reference.file && typeof reference.file === 'object') {
                    record = normaliseAttachmentRecord(reference.file);
                }

                if (!record && reference.content && typeof reference.content === 'object') {
                    record = normaliseAttachmentRecord(reference.content);
                }

                if (!record) {
                    record = normaliseAttachmentRecord(reference);
                }

                if (!record && reference.file_citation && reference.file_citation.file_id) {
                    record = normaliseAttachmentRecord({ file_id: reference.file_citation.file_id });
                }

                if (record) {
                    registerRecord(record);
                }
            });
        }

        if (state && state.attachmentLibrary) {
            Object.keys(state.attachmentLibrary).forEach(function (key) {
                if (!lookup[key]) {
                    lookup[key] = state.attachmentLibrary[key];
                }
            });
        }

        return lookup;
    }

    function normaliseAttachmentRecord(raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }

        function pickString() {
            for (var index = 0; index < arguments.length; index++) {
                var candidate = arguments[index];
                if (typeof candidate === 'string' && candidate) {
                    return candidate;
                }
            }

            return '';
        }

        var fileId = raw.file_id || raw.id || raw.fileId || raw.reference_id || '';
        if (!fileId && raw.image_file && raw.image_file.file_id) {
            fileId = raw.image_file.file_id;
        }

        if (!fileId && raw.file && typeof raw.file === 'object') {
            fileId = raw.file.file_id || raw.file.id || '';
        }

        if (!fileId) {
            return null;
        }

        var name = pickString(
            raw.display_name,
            raw.filename,
            raw.name,
            raw.label,
            raw.title && typeof raw.title === 'string' ? raw.title : ''
        );

        if (!name && raw.title && typeof raw.title.rendered === 'string') {
            name = raw.title.rendered;
        }

        if (!name) {
            name = pickString(raw.caption, raw.original_name);
        }

        var downloadName = pickString(
            raw.filename,
            raw.name,
            raw.download_name,
            raw.original_name,
            raw.display_name
        );

        var url = pickString(
            raw.url,
            raw.download_url,
            raw.href,
            raw.source_url,
            typeof raw.image_url === 'string' ? raw.image_url : '',
            raw.image_url && raw.image_url.url ? raw.image_url.url : ''
        );

        var mime = pickString(raw.mime_type, raw.type, raw.mime);

        var size = null;
        if (typeof raw.bytes === 'number') {
            size = raw.bytes;
        } else if (typeof raw.size === 'number') {
            size = raw.size;
        }

        var data = '';
        if (typeof raw.data === 'string') {
            data = raw.data;
        } else if (raw.data && typeof raw.data.base64 === 'string') {
            data = raw.data.base64;
        }

        if (raw.file && typeof raw.file === 'object') {
            var fileEntry = raw.file;

            if (!name) {
                if (typeof fileEntry.display_name === 'string' && fileEntry.display_name) {
                    name = fileEntry.display_name;
                } else if (typeof fileEntry.filename === 'string' && fileEntry.filename) {
                    name = fileEntry.filename;
                } else if (typeof fileEntry.name === 'string' && fileEntry.name) {
                    name = fileEntry.name;
                } else if (typeof fileEntry.title === 'string' && fileEntry.title) {
                    name = fileEntry.title;
                }
            }

            if (!downloadName) {
                downloadName = pickString(fileEntry.filename, fileEntry.name, fileEntry.display_name);
            }

            if (!url) {
                url = pickString(fileEntry.url, fileEntry.download_url, fileEntry.href, fileEntry.source_url);
            }

            if (!mime) {
                mime = pickString(fileEntry.mime_type, fileEntry.type, fileEntry.content_type);
            }

            if (size === null) {
                if (typeof fileEntry.bytes === 'number') {
                    size = fileEntry.bytes;
                } else if (typeof fileEntry.size === 'number') {
                    size = fileEntry.size;
                }
            }

            if (!data) {
                if (typeof fileEntry.data === 'string') {
                    data = fileEntry.data;
                } else if (fileEntry.data && typeof fileEntry.data.base64 === 'string') {
                    data = fileEntry.data.base64;
                }
            }
        }

        if (!name && downloadName) {
            name = downloadName;
        }

        return {
            fileId: String(fileId),
            name: name,
            url: url,
            mime: mime,
            size: size,
            data: data,
            downloadName: downloadName,
        };
    }

    function getAttachmentUrlFromRecord(record, state) {
        if (!record) {
            return '';
        }

        if (record.url) {
            return record.url;
        }

        if (record.data) {
            var cacheKey = record.fileId || record.downloadName || '';
            if (cacheKey && state && state.attachmentBlobUrls && state.attachmentBlobUrls[cacheKey]) {
                return state.attachmentBlobUrls[cacheKey];
            }

            var objectUrl = createObjectUrlFromBase64(record.data, record.mime);
            if (objectUrl) {
                if (cacheKey && state && state.attachmentBlobUrls) {
                    state.attachmentBlobUrls[cacheKey] = objectUrl;
                }
                registerObjectUrl(objectUrl);
                return objectUrl;
            }
        }

        return '';
    }

    function createObjectUrlFromBase64(base64, mimeType) {
        try {
            var binary = atob(base64);
            var length = binary.length;
            var bytes = new Uint8Array(length);

            for (var index = 0; index < length; index++) {
                bytes[index] = binary.charCodeAt(index);
            }

            var blob = new Blob([bytes], { type: mimeType || 'application/octet-stream' });
            return URL.createObjectURL(blob);
        } catch (error) {
            if (window.console && console.error) {
                console.error('Failed to create object URL', error);
            }
            return '';
        }
    }

    function buildAttachmentMeta(record) {
        if (!record) {
            return '';
        }

        var parts = [];
        var size = null;

        if (typeof record.size === 'number') {
            size = record.size;
        } else if (typeof record.bytes === 'number') {
            size = record.bytes;
        }

        if (size && size > 0) {
            parts.push(formatBytes(size));
        }

        var mime = record.mime || record.mime_type || record.type;
        if (mime) {
            parts.push(mime);
        }

        return parts.join(' • ');
    }

    function normaliseToolResultForDisplay(toolName, result) {
        if (!result || typeof result !== 'object') {
            return null;
        }

        var url = typeof result.url === 'string' ? result.url : '';
        if (!url) {
            return null;
        }

        var attachments = [];
        var label = '';

        if (typeof result.title === 'string' && result.title.trim()) {
            label = result.title.trim();
        } else if (typeof result.file_name === 'string' && result.file_name.trim()) {
            label = result.file_name.trim();
        } else if (typeof result.fileName === 'string' && result.fileName.trim()) {
            label = result.fileName.trim();
        }

        var metaParts = [];
        var metaRecord = {
            bytes: typeof result.bytes === 'number' ? result.bytes : null,
            mime_type: result.mime_type || result.mimeType || '',
        };

        var baseMeta = buildAttachmentMeta(metaRecord);
        if (baseMeta) {
            metaParts.push(baseMeta);
        }

        if (toolName === 'generate_openai_image') {
            if (typeof result.size === 'string' && result.size.trim()) {
                metaParts.push(result.size.trim());
            }

            if (typeof result.quality === 'string' && result.quality.trim()) {
                metaParts.push(result.quality.trim());
            }
        } else if (toolName === SPEECH_TOOL_NAME) {
            if (typeof result.duration_formatted === 'string' && result.duration_formatted.trim()) {
                metaParts.push(result.duration_formatted.trim());
            }

            if (typeof result.format === 'string' && result.format.trim()) {
                metaParts.push(result.format.trim().toUpperCase());
            }
        }

        var attachmentMeta = metaParts.join(' • ');

        attachments.push({
            url: url,
            label: label || getString('downloadAttachment', 'Download attachment'),
            downloadName: result.file_name || result.fileName || '',
            meta: attachmentMeta,
        });

        if (!attachments.length) {
            return null;
        }

        var text = '';

        if (typeof result.text === 'string' && result.text.trim()) {
            text = result.text.trim();
        } else if (typeof result.message === 'string' && result.message.trim()) {
            text = result.message.trim();
        } else if (toolName === 'generate_openai_image') {
            text = getString('imageToolSuccess', 'Image saved to the Media Library.');
        } else if (toolName === SPEECH_TOOL_NAME) {
            text = getString('speechToolSuccess', 'Speech audio saved to the Media Library.');
        }

        return {
            text: text,
            attachments: attachments,
        };
    }

    function parseNumberValue(value) {
        if (typeof value === 'number' && isFinite(value)) {
            return value;
        }

        if (typeof value === 'string') {
            var parsed = parseFloat(value);
            if (!isNaN(parsed) && isFinite(parsed)) {
                return parsed;
            }
        }

        return NaN;
    }

    function getCrawl4aiPollDelay(metadata, state) {
        var poll = metadata && Object.prototype.hasOwnProperty.call(metadata, 'poll_interval') ? metadata.poll_interval : null;
        var parsed = parseNumberValue(poll);
        if (isNaN(parsed) || parsed <= 0) {
            var fallback = state && state.config ? parseInt(state.config.crawl4aiDefaultPollMs, 10) : 0;
            if (!fallback || fallback < 1000) {
                fallback = 5000;
            }
            return fallback;
        }

        return Math.max(1000, Math.round(parsed * 1000));
    }

    function getCrawl4aiTimeout(metadata) {
        var timeout = metadata && Object.prototype.hasOwnProperty.call(metadata, 'wait_timeout') ? metadata.wait_timeout : null;
        var parsed = parseNumberValue(timeout);
        if (isNaN(parsed) || parsed <= 0) {
            return 600000;
        }

        return Math.max(10000, Math.round(parsed * 1000));
    }

    function isCrawl4aiPendingResult(result) {
        if (!result || typeof result !== 'object') {
            return false;
        }

        var taskId = typeof result.task_id === 'string' ? result.task_id : '';
        if (!taskId) {
            return false;
        }

        if (Array.isArray(result.results) && result.results.length) {
            return false;
        }

        var status = typeof result.status === 'string' ? result.status.toLowerCase() : '';
        return !status || status === 'pending' || status === 'queued' || status === 'running';
    }

    function buildCrawl4aiTaskUrl(state, taskId) {
        if (!state || !state.config || !state.config.crawl4aiTaskEndpoint) {
            return '';
        }

        var base = state.config.crawl4aiTaskEndpoint;
        if (base.charAt(base.length - 1) !== '/') {
            base += '/';
        }

        return base + encodeURIComponent(taskId);
    }

    function fetchCrawl4aiTask(state, taskId) {
        var url = buildCrawl4aiTaskUrl(state, taskId);
        if (!url) {
            return Promise.reject(new Error('Crawl4AI endpoint not configured.'));
        }

        return fetch(url, {
            method: 'GET',
            headers: buildJsonHeaders(state),
            credentials: 'same-origin',
        }).then(function (response) {
            if (response.status === 404) {
                return null;
            }

            if (!response.ok) {
                var error = new Error('HTTP ' + response.status);
                error.status = response.status;
                throw error;
            }

            return response.json();
        });
    }

    function updatePendingTaskEntry(entry, message) {
        if (!entry) {
            return;
        }

        var bubble = entry.querySelector('.wp-mcp-ai-chat__bubble');
        if (!bubble) {
            return;
        }

        bubble.textContent = message;
    }

    function waitForCrawl4aiTask(state, toolName, result) {
        if (!isCrawl4aiPendingResult(result)) {
            return Promise.resolve(result);
        }

        if (!state || !state.config || !state.config.crawl4aiTaskEndpoint) {
            return Promise.resolve(result);
        }

        var taskId = result.task_id;
        var metadata = result && typeof result === 'object' && result.metadata ? result.metadata : {};
        var pollDelay = getCrawl4aiPollDelay(metadata, state);
        var timeout = getCrawl4aiTimeout(metadata);
        var startTime = Date.now();
        var pendingEntry = appendMessage(state.messagesEl, 'system', getString('toolQueued', 'Crawl queued. Results will appear shortly.'));

        state.pendingCrawlTasks[taskId] = {
            entry: pendingEntry,
            pollDelay: pollDelay,
            timeout: timeout,
            start: startTime,
            timer: null,
        };

        return new Promise(function (resolve, reject) {
            function cleanup() {
                var record = state.pendingCrawlTasks[taskId];
                if (record && record.timer) {
                    clearTimeout(record.timer);
                }

                delete state.pendingCrawlTasks[taskId];
            }

            function scheduleNext() {
                var record = state.pendingCrawlTasks[taskId];
                if (!record) {
                    return;
                }

                record.timer = setTimeout(poll, record.pollDelay);
            }

            function poll() {
                var record = state.pendingCrawlTasks[taskId];
                if (!record) {
                    return;
                }

                if (Date.now() - record.start >= record.timeout) {
                    cleanup();
                    updatePendingTaskEntry(pendingEntry, getString('toolTimeout', 'Crawl timed out before completing.'));
                    reject(new Error('timeout'));
                    return;
                }

                fetchCrawl4aiTask(state, taskId)
                    .then(function (payload) {
                        if (!payload) {
                            updatePendingTaskEntry(pendingEntry, getString('toolPolling', 'Crawl in progress…'));
                            scheduleNext();
                            return;
                        }

                        var status = typeof payload.status === 'string' ? payload.status.toLowerCase() : '';
                        if (status === 'failed' || status === 'error') {
                            cleanup();
                            var errorMessage = payload && payload.metadata && payload.metadata.error ? payload.metadata.error : getString('toolError', 'The tool request failed.');
                            updatePendingTaskEntry(pendingEntry, formatString(getString('toolFailed', 'The crawl failed: %s'), errorMessage));
                            reject(new Error(errorMessage));
                            return;
                        }

                        if (status === 'timeout') {
                            cleanup();
                            updatePendingTaskEntry(pendingEntry, getString('toolTimeout', 'Crawl timed out before completing.'));
                            reject(new Error('timeout'));
                            return;
                        }

                        if (Array.isArray(payload.results) && payload.results.length) {
                            cleanup();
                            updatePendingTaskEntry(pendingEntry, getString('toolSuccess', 'Tool response ready.'));
                            resolve(payload);
                            return;
                        }

                        updatePendingTaskEntry(pendingEntry, getString('toolPolling', 'Crawl in progress…'));
                        record.pollDelay = getCrawl4aiPollDelay(payload.metadata || {}, state);
                        scheduleNext();
                    })
                    .catch(function (error) {
                        cleanup();
                        var message = error && error.message ? error.message : getString('toolError', 'The tool request failed.');
                        updatePendingTaskEntry(pendingEntry, formatString(getString('toolFailed', 'The crawl failed: %s'), message));
                        reject(error);
                    });
            }

            poll();
        });
    }

    function formatBytes(bytes) {
        if (typeof bytes !== 'number' || !isFinite(bytes) || bytes <= 0) {
            return '';
        }

        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var exponent = Math.floor(Math.log(bytes) / Math.log(1024));
        exponent = Math.min(units.length - 1, Math.max(exponent, 0));

        var value = bytes / Math.pow(1024, exponent);
        var decimals = exponent === 0 ? 0 : value >= 10 ? 1 : 2;

        return value.toFixed(decimals) + ' ' + units[exponent];
    }

    function createContentDispositionHeader(filename) {
        var fallback = (filename || 'attachment').replace(/"/g, '');
        var encoded = encodeRFC5987ValueChars(filename || fallback);

        return 'attachment; filename="' + fallback + '"; filename*=UTF-8\'' + encoded + '\'';
    }

    function encodeRFC5987ValueChars(str) {
        return encodeURIComponent(str)
            .replace(/['()*]/g, function (character) {
                return '%' + character.charCodeAt(0).toString(16).toUpperCase();
            })
            .replace(/%(7C|60|5E)/g, function (match, hex) {
                return '%' + hex;
            });
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
            var attachmentsContainer = container.querySelector('.wp-mcp-ai-chat__attachments');
            var attachmentsList = container.querySelector('.wp-mcp-ai-chat__attachments-list');
            var attachmentsHeader = container.querySelector('.wp-mcp-ai-chat__attachments-header');
            var attachButton = container.querySelector('.wp-mcp-ai-chat__attach');
            var fileInput = container.querySelector('.wp-mcp-ai-chat__file-input');
            var toolShortcutsContainer = container.querySelector('.' + TOOL_SHORTCUT_CONTAINER_CLASS);
            var transcriptToggle = container.querySelector('.wp-mcp-ai-chat__transcript-toggle');

            if (!form || !textarea || !messagesEl || !statusEl) {
                return;
            }

            var instanceConfig = Object.assign({}, config);
            if (!instanceConfig.uploadEndpoint) {
                instanceConfig.uploadEndpoint = globalConfig.uploadEndpoint || '';
            }

            if (!instanceConfig.crawl4aiTaskEndpoint) {
                instanceConfig.crawl4aiTaskEndpoint = '';
            }

            if (!instanceConfig.crawl4aiDefaultPollMs || instanceConfig.crawl4aiDefaultPollMs < 1000) {
                instanceConfig.crawl4aiDefaultPollMs = globalConfig.crawl4aiDefaultPollMs || 5000;
            }

            if (!Object.prototype.hasOwnProperty.call(instanceConfig, 'canUploadAttachments')) {
                instanceConfig.canUploadAttachments = true;
            } else {
                instanceConfig.canUploadAttachments = !!instanceConfig.canUploadAttachments;
            }

            instanceConfig.allowedImageMimes = normaliseList(instanceConfig.allowedImageMimes);
            instanceConfig.allowedFileMimes = normaliseList(instanceConfig.allowedFileMimes);
            instanceConfig.allowedExtensions = normaliseList(instanceConfig.allowedExtensions);
            if (!Array.isArray(instanceConfig.toolShortcuts)) {
                instanceConfig.toolShortcuts = [];
            }

            if (fileInput && instanceConfig.fileAccept) {
                fileInput.setAttribute('accept', instanceConfig.fileAccept);
            }

            var state = {
                conversation: [],
                busy: false,
                uploading: 0,
                config: instanceConfig,
                canUploadAttachments: instanceConfig.canUploadAttachments,
                container: container,
                textarea: textarea,
                messagesEl: messagesEl,
                statusEl: statusEl,
                attachmentsContainer: attachmentsContainer,
                attachmentsList: attachmentsList,
                attachmentsHeader: attachmentsHeader,
                attachButton: attachButton,
                fileInput: fileInput,
                toolShortcutsContainer: toolShortcutsContainer,
                transcriptToggle: transcriptToggle,
                transcriptExpanded: false,
                pendingAttachments: [],
                attachmentLibrary: {},
                attachmentBlobUrls: {},
                validationNotice: '',
                speechCache: Object.create(null),
                activeSpeech: null,
                pendingCrawlTasks: Object.create(null),
            };

            initialiseExistingSpeechButtons(state);
            renderToolShortcuts(state);

            setTranscriptExpanded(state, false);

            if (transcriptToggle) {
                transcriptToggle.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }

                    setTranscriptExpanded(state, !state.transcriptExpanded);
                });
            }

            textarea.setAttribute('placeholder', getString('placeholder', textarea.getAttribute('placeholder')));
            form.addEventListener('submit', function (event) {
                handleSubmit(event, state);
            });

            if (attachmentsHeader) {
                attachmentsHeader.textContent = getString('attachmentsLabel', attachmentsHeader.textContent);
            }

            if (!state.canUploadAttachments) {
                if (attachmentsContainer) {
                    attachmentsContainer.hidden = true;
                }

                if (attachButton) {
                    attachButton.hidden = true;
                }

                if (fileInput) {
                    fileInput.disabled = true;
                }
            } else if (attachButton) {
                attachButton.textContent = getString('attachFile', attachButton.textContent);
                attachButton.addEventListener('click', function () {
                    if (state.busy || state.uploading > 0) {
                        return;
                    }

                    if (fileInput) {
                        fileInput.click();
                    }
                });
            }

            if (state.canUploadAttachments && fileInput) {
                fileInput.addEventListener('change', function (event) {
                    handleFileSelection(event, state);
                });
            }

            updateAttachButtonState(state);
        });
    }

    function handleSubmit(event, state) {
        event.preventDefault();
        if (state.busy) {
            return;
        }

        if (state.uploading > 0) {
            setStatus(state.container, getString('uploadInProgress', 'Please wait for uploads to finish before sending.'));
            return;
        }

        var inputValue = state.textarea.value;
        var trimmedMessage = inputValue.trim();
        var pending = state.pendingAttachments.slice();
        var hasAttachments = pending.length > 0;

        if (!trimmedMessage && !hasAttachments) {
            setStatus(state.container, getString('emptyMessage', 'Enter a message before sending.'));
            return;
        }

        state.textarea.value = '';

        var segments = [];
        if (trimmedMessage) {
            segments.push({
                type: 'text',
                text: inputValue,
            });
        }

        var displayAttachments = [];

        pending.forEach(function (attachment) {
            var segment = createSegmentFromAttachment(attachment);
            if (segment) {
                segments.push(segment);
            }

            var displayAttachment = buildDisplayAttachment(attachment, state);
            if (displayAttachment) {
                displayAttachments.push(displayAttachment);
            }
        });

        var userMessageElement = null;

        if (trimmedMessage || displayAttachments.length) {
            userMessageElement = appendMessage(state.messagesEl, 'user', {
                text: inputValue,
                attachments: displayAttachments,
            });
        }

        var payloadContent;
        if (segments.length === 1 && segments[0].type === 'text') {
            payloadContent = segments[0].text;
        } else {
            payloadContent = segments;
        }

        var previousConversationLength = state.conversation.length;
        state.conversation.push({ role: 'user', content: payloadContent });

        state.pendingAttachments = [];
        renderPendingAttachments(state);
        updateAttachButtonState(state);

        sendChat(state, {
            previousConversationLength: previousConversationLength,
            pendingAttachments: pending,
            messageElement: userMessageElement,
            inputValue: inputValue,
        });
    }

    function sendChat(state, submissionContext) {
        state.busy = true;
        disableForm(state, true);
        setStatus(state.container, getString('sending', 'Sending…'));

        var payload = {
            assistant_id: state.config.assistantId,
            messages: state.conversation,
        };

        function finalize() {
            state.busy = false;
            disableForm(state, false);
        }

        return fetch(state.config.messagesEndpoint, {
            method: 'POST',
            headers: buildJsonHeaders(state),
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
                restoreSubmissionState(state, submissionContext);
                finalize();
            });
    }

    function restoreSubmissionState(state, submissionContext) {
        if (!submissionContext || typeof submissionContext !== 'object') {
            return;
        }

        if (typeof submissionContext.previousConversationLength === 'number') {
            state.conversation = state.conversation.slice(0, submissionContext.previousConversationLength);
        }

        if (Array.isArray(submissionContext.pendingAttachments)) {
            state.pendingAttachments = submissionContext.pendingAttachments.slice();
            renderPendingAttachments(state);
            updateAttachButtonState(state);
        }

        if (submissionContext.messageElement && submissionContext.messageElement.parentNode) {
            submissionContext.messageElement.parentNode.removeChild(submissionContext.messageElement);
        }

        if (typeof submissionContext.inputValue === 'string' && state.textarea) {
            state.textarea.value = submissionContext.inputValue;

            if (typeof state.textarea.focus === 'function') {
                state.textarea.focus();

                if (typeof state.textarea.setSelectionRange === 'function') {
                    var length = state.textarea.value.length;
                    state.textarea.setSelectionRange(length, length);
                }
            }
        }
    }

    function extractFilteredResponseNotice(choice, message) {
        if (message && typeof message.refusal === 'string' && message.refusal.trim()) {
            return message.refusal.trim();
        }

        var metadata = message && message.metadata ? message.metadata : null;
        if (metadata && typeof metadata === 'object') {
            if (typeof metadata.warning === 'string' && metadata.warning.trim()) {
                return metadata.warning.trim();
            }

            if (typeof metadata.message === 'string' && metadata.message.trim()) {
                return metadata.message.trim();
            }

            if (typeof metadata.reason === 'string' && metadata.reason.trim()) {
                return metadata.reason.trim();
            }

            if (typeof metadata.error === 'string' && metadata.error.trim()) {
                return metadata.error.trim();
            }
        }

        var filterResults = message && message.content_filter_results ? message.content_filter_results : null;
        if (filterResults && typeof filterResults === 'object') {
            if (typeof filterResults.message === 'string' && filterResults.message.trim()) {
                return filterResults.message.trim();
            }

            if (typeof filterResults.reason === 'string' && filterResults.reason.trim()) {
                return filterResults.reason.trim();
            }

            if (filterResults.error && typeof filterResults.error === 'object') {
                if (typeof filterResults.error.message === 'string' && filterResults.error.message.trim()) {
                    return filterResults.error.message.trim();
                }
            }
        }

        var finishReason = choice && typeof choice.finish_reason === 'string' ? choice.finish_reason.trim() : '';

        if (finishReason === 'content_filter') {
            return getString('responseFiltered', 'The assistant response was blocked by safety filters.');
        }

        if (finishReason === 'length') {
            return getString('responseIncomplete', 'The assistant response ended prematurely and could not be displayed.');
        }

        if (finishReason === 'error') {
            return getString('responseErrored', 'The assistant response could not be displayed due to an error.');
        }

        return '';
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
        var assistantDisplay = prepareAssistantDisplay(message, state);
        var hasDisplayText = typeof assistantDisplay.text === 'string' && assistantDisplay.text.trim() !== '';
        var hasDisplayAttachments = assistantDisplay.attachments.length > 0;
        var hasDisplayContent = hasDisplayText || hasDisplayAttachments;
        var hasToolCalls = message.tool_calls && Array.isArray(message.tool_calls) && message.tool_calls.length;

        if (hasDisplayContent) {
            appendMessage(state.messagesEl, 'assistant', assistantDisplay, true, {
                speech: {
                    state: state,
                    text: assistantDisplay.text || '',
                },
            });
            assistantMessage.content = assistantDisplay.text || '';
        }

        if (!hasDisplayContent && !hasToolCalls) {
            var notice = extractFilteredResponseNotice(choice, message);
            if (!notice) {
                notice = getString('responseMissing', 'The assistant response could not be displayed.');
            }

            appendMessage(state.messagesEl, 'system', { text: notice });
            setStatus(state.container, notice);

            return Promise.resolve();
        }

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
            headers: buildJsonHeaders(state),
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
                return waitForCrawl4aiTask(state, toolName, result);
            })
            .then(function (result) {
                var toolContent = '';
                var displayPayload = '';

                if (typeof result === 'string') {
                    toolContent = result;
                    displayPayload = result;
                } else if (result !== null && typeof result !== 'undefined') {
                    if (typeof result === 'object') {
                        var normalised = normaliseToolResultForDisplay(toolName, result);

                        try {
                            toolContent = JSON.stringify(result, null, 2);
                        } catch (error) {
                            toolContent = String(result);
                        }

                        if (normalised) {
                            displayPayload = normalised;
                        } else {
                            displayPayload = toolContent;
                        }
                    } else {
                        toolContent = String(result);
                        displayPayload = toolContent;
                    }
                }

                appendMessage(state.messagesEl, 'tool', displayPayload);

                var toolMessage = {
                    role: 'tool',
                    content: toolContent,
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
        var fallbackMessage = getString('error', 'Something went wrong.');

        function extractMessage(payload) {
            if (!payload) {
                return '';
            }

            if (typeof payload === 'string') {
                return payload;
            }

            if (typeof payload.message === 'string' && payload.message.trim()) {
                return payload.message;
            }

            if (typeof payload.reason === 'string' && payload.reason.trim()) {
                return payload.reason;
            }

            if (payload.data) {
                var dataMessage = extractMessage(payload.data);
                if (dataMessage) {
                    return dataMessage;
                }
            }

            var nestedKeys = ['last_error', 'error', 'incomplete_details', 'response'];

            for (var i = 0; i < nestedKeys.length; i++) {
                var key = nestedKeys[i];
                if (payload[key]) {
                    var nestedMessage = extractMessage(payload[key]);
                    if (nestedMessage) {
                        return nestedMessage;
                    }
                }
            }

            return '';
        }

        function handleResolvedMessage(resolvedMessage) {
            var message = resolvedMessage;

            if (typeof message !== 'string') {
                message = '';
            }

            message = message.trim() || fallbackMessage;

            appendMessage(state.messagesEl, 'system', { text: message });
            setStatus(state.container, message);
        }

        if (error && typeof error.json === 'function') {
            error
                .json()
                .then(function (body) {
                    handleResolvedMessage(extractMessage(body));
                })
                .catch(function () {
                    handleResolvedMessage('');
                });
        } else {
            handleResolvedMessage('');
        }
    }

    function disableForm(state, disabled) {
        var container = state.container;
        var elements = container.querySelectorAll('button, textarea, input');
        Array.prototype.forEach.call(elements, function (element) {
            element.disabled = disabled;
        });

        if (disabled) {
            if (state.attachButton) {
                state.attachButton.disabled = true;
            }
            if (state.fileInput) {
                state.fileInput.disabled = true;
            }
        } else {
            updateAttachButtonState(state);
        }
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

    function appendMessage(listEl, role, payload, allowMarkdown, options) {
        if (typeof payload === 'undefined' || payload === null) {
            return null;
        }

        var text = '';
        var attachments = [];

        if (typeof payload === 'object' && !Array.isArray(payload)) {
            if (Array.isArray(payload.attachments)) {
                attachments = payload.attachments
                    .map(function (attachment) {
                        if (!attachment || typeof attachment !== 'object') {
                            return null;
                        }

                        var url = attachment.url || '';
                        if (!url) {
                            return null;
                        }

                        var label = attachment.label || attachment.name || '';
                        if (!label) {
                            label = getString('downloadAttachment', 'Download attachment');
                        }

                        return {
                            url: url,
                            label: label,
                            downloadName: attachment.downloadName || '',
                            meta: attachment.meta || '',
                        };
                    })
                    .filter(function (attachment) {
                        return attachment !== null;
                    });
            }

            if (Object.prototype.hasOwnProperty.call(payload, 'text')) {
                text = String(payload.text || '');
            } else if (payload.content) {
                text = normaliseContent(payload.content);
            } else if (payload.raw) {
                text = String(payload.raw);
            }
        } else {
            text = String(payload);
        }

        if (typeof text !== 'string') {
            text = '';
        }

        var hasText = text.trim() !== '';
        var hasAttachments = attachments.length > 0;

        if (!hasText && !hasAttachments) {
            return null;
        }

        var entry = document.createElement('div');
        entry.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__message--' + role;

        var bubble = document.createElement('div');
        bubble.className = 'wp-mcp-ai-chat__bubble';

        if (hasText) {
            if (allowMarkdown) {
                bubble.innerHTML = renderMarkdown(text);
            } else {
                var normalisedText = String(text).replace(/\r\n|\r|\u2028|\u2029/g, '\n');
                bubble.innerHTML = escapeHtml(normalisedText).replace(/\n/g, '<br />');
            }
        }

        if (hasAttachments) {
            var list = document.createElement('ul');
            list.className = 'wp-mcp-ai-chat__bubble-attachments';

            attachments.forEach(function (attachment) {
                var item = document.createElement('li');
                item.className = 'wp-mcp-ai-chat__bubble-attachment';

                var link = document.createElement('a');
                link.href = attachment.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = attachment.label;

                if (attachment.downloadName) {
                    link.download = attachment.downloadName;
                }

                item.appendChild(link);

                if (attachment.meta) {
                    var meta = document.createElement('span');
                    meta.className = 'wp-mcp-ai-chat__attachments-meta';
                    meta.textContent = attachment.meta;
                    item.appendChild(document.createTextNode(' – '));
                    item.appendChild(meta);
                }

                list.appendChild(item);
            });

            bubble.appendChild(list);
        }

        if (role === 'assistant') {
            var speechState = options && options.speech ? options.speech.state || null : null;
            var speechText = options && options.speech ? options.speech.text || '' : text;
            attachSpeechButton(bubble, speechState, speechText);
            attachCopyButton(bubble, speechText);
        }

        entry.appendChild(bubble);
        listEl.appendChild(entry);
        listEl.scrollTop = listEl.scrollHeight;

        return entry;
    }

    function renderMarkdown(text) {
        if (!text) {
            return '';
        }

        var placeholderBase = 'WP_MCP_AI_' + Math.random().toString(36).slice(2);
        var codeBlocks = [];
        var inlineCodes = [];
        var links = [];
        var processed = String(text).replace(/\r\n|\r|\u2028|\u2029/g, '\n');

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

            if (listType) {
                flushList();
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
        var processed = String(text).replace(/\r\n|\r|\u2028|\u2029/g, ' ');

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
            } else if (typeof piece.file_id === 'string' && piece.file_id) {
                label = piece.file_id;
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
