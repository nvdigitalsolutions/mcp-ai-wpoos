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
    var TRANSCRIBE_TOOL_NAME = 'transcribe_openai_audio';
    var TRANSCRIBE_RECORDING_CLASS = 'wp-mcp-ai-chat__transcribe--recording';
    var MAX_TRANSCRIBE_BYTES = 26214400;
    var TOOL_SHORTCUT_CONTAINER_CLASS = 'wp-mcp-ai-chat__tool-shortcuts';
    var TOOL_SHORTCUT_BUTTON_CLASS = 'wp-mcp-ai-chat__tool-shortcut';
    var STORAGE_KEY_PREFIX = 'wp_mcp_ai_chat_';
    var STORAGE_EXPIRY_MS = 24 * 60 * 60 * 1000; // 24 hours

    function getStorageKey(assistantId) {
        return STORAGE_KEY_PREFIX + assistantId;
    }

    function saveConversationToStorage(state) {
        if (!state || !state.config || !state.config.assistantId) {
            return;
        }

        if (!window.localStorage) {
            return;
        }

        try {
            var storageKey = getStorageKey(state.config.assistantId);
            var data = {
                conversation: state.conversation || [],
                sessionKey: state.config.sessionKey || '',
                timestamp: Date.now(),
                assistantId: state.config.assistantId
            };

            window.localStorage.setItem(storageKey, JSON.stringify(data));
        } catch (error) {
            // Silently fail if localStorage is not available or quota exceeded
        }
    }

    function loadConversationFromStorage(state) {
        if (!state || !state.config || !state.config.assistantId) {
            return null;
        }

        if (!window.localStorage) {
            return null;
        }

        try {
            var storageKey = getStorageKey(state.config.assistantId);
            var stored = window.localStorage.getItem(storageKey);

            if (!stored) {
                return null;
            }

            var data = JSON.parse(stored);

            if (!data || typeof data !== 'object') {
                return null;
            }

            // Check if data is expired
            var age = Date.now() - (data.timestamp || 0);
            if (age > STORAGE_EXPIRY_MS) {
                window.localStorage.removeItem(storageKey);
                return null;
            }

            // Verify it's for the same assistant
            if (data.assistantId !== state.config.assistantId) {
                return null;
            }

            return {
                conversation: Array.isArray(data.conversation) ? data.conversation : [],
                sessionKey: data.sessionKey || ''
            };
        } catch (error) {
            // Return null if parsing fails
            return null;
        }
    }

    function clearConversationFromStorage(state) {
        if (!state || !state.config || !state.config.assistantId) {
            return;
        }

        if (!window.localStorage) {
            return;
        }

        try {
            var storageKey = getStorageKey(state.config.assistantId);
            window.localStorage.removeItem(storageKey);
        } catch (error) {
            // Silently fail
        }
    }

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

    function supportsAudioRecording() {
        return (
            typeof window !== 'undefined' &&
            window.navigator &&
            navigator.mediaDevices &&
            typeof navigator.mediaDevices.getUserMedia === 'function' &&
            typeof window.MediaRecorder !== 'undefined'
        );
    }

    function stopRecordingStream(state) {
        if (!state || !state.recordingStream) {
            return;
        }

        var tracks = state.recordingStream.getTracks ? state.recordingStream.getTracks() : [];
        tracks.forEach(function (track) {
            try {
                track.stop();
            } catch (error) {}
        });

        state.recordingStream = null;
    }

    function setTranscribeRecordingState(state, recording) {
        if (!state) {
            return;
        }

        state.isRecording = !!recording;

        var button = state.transcribeButton;
        if (button && button.classList) {
            if (state.isRecording) {
                button.classList.add(TRANSCRIBE_RECORDING_CLASS);
            } else {
                button.classList.remove(TRANSCRIBE_RECORDING_CLASS);
            }
        }

        if (button) {
            var label = state.isRecording
                ? getString('stopRecording', 'Stop recording')
                : getString('transcribeAudio', 'Transcribe audio');
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        }

        if (state.container) {
            if (state.isRecording) {
                setStatus(state.container, getString('recording', 'Recording… tap to stop.'));
            } else if (!state.transcribing && !state.busy) {
                setStatus(state.container, '');
            }
        }
    }

    function updateTranscribeButtonState(state) {
        if (!state) {
            return;
        }

        var button = state.transcribeButton;
        var input = state.transcribeInput;

        var canUse = !!state.canUploadAttachments;
        var disabled = !canUse || state.busy || state.uploading > 0 || state.transcribing;

        if (state.isRecording) {
            disabled = false;
        }

        if (button) {
            button.disabled = disabled;

            if (!canUse) {
                button.hidden = true;
            } else {
                button.hidden = false;
            }
        }

        if (input) {
            input.disabled = !canUse || state.busy || state.uploading > 0 || state.transcribing || state.isRecording;
        }
    }

    function handleTranscribeButtonClick(state) {
        if (!state || state.transcribing) {
            return;
        }

        if (state.isRecording) {
            stopTranscribeRecording(state);
            return;
        }

        if (!state.canUploadAttachments) {
            return;
        }

        if (supportsAudioRecording()) {
            var shouldRecord = true;

            if (state.transcribeInput) {
                var message = getString(
                    'transcribeChooseSource',
                    'Press OK to record with your microphone, or Cancel to choose an audio file.'
                );

                if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
                    shouldRecord = window.confirm(message);
                }
            }

            if (shouldRecord) {
                startTranscribeRecording(state);
                return;
            }
        }

        if (state.transcribeInput && !state.transcribeInput.disabled) {
            state.transcribeInput.click();
        }
    }

    function startTranscribeRecording(state) {
        if (!state || !supportsAudioRecording()) {
            return;
        }

        state.recordingShouldProcess = false;

        if (state.transcribeButton) {
            state.transcribeButton.disabled = true;
        }

        navigator.mediaDevices
            .getUserMedia({ audio: true })
            .then(function (stream) {
                state.recordingStream = stream;
                state.recordedChunks = [];

                try {
                    state.mediaRecorder = new MediaRecorder(stream);
                } catch (error) {
                    stopRecordingStream(state);
                    setStatus(
                        state.container,
                        getString(
                            'recordingError',
                            'Could not access your microphone. Please allow access or upload an audio file instead.'
                        )
                    );
                    updateTranscribeButtonState(state);
                    return;
                }

                if (!state.mediaRecorder) {
                    stopRecordingStream(state);
                    updateTranscribeButtonState(state);
                    return;
                }

                state.recordingShouldProcess = true;

                state.mediaRecorder.addEventListener('dataavailable', function (event) {
                    if (event && event.data && event.data.size) {
                        state.recordedChunks.push(event.data);
                    }
                });

                state.mediaRecorder.addEventListener('stop', function () {
                    var chunks = state.recordedChunks || [];
                    var mimeType = state.mediaRecorder && state.mediaRecorder.mimeType ? state.mediaRecorder.mimeType : 'audio/webm';
                    var baseMimeType = typeof mimeType === 'string' ? mimeType.split(';')[0] : '';
                    if (!baseMimeType && typeof mimeType === 'string') {
                        baseMimeType = mimeType;
                    }

                    stopRecordingStream(state);
                    setTranscribeRecordingState(state, false);

                    if (!state.recordingShouldProcess) {
                        state.mediaRecorder = null;
                        state.recordedChunks = [];
                        return;
                    }

                    var blob = null;
                    try {
                        var blobType = baseMimeType || mimeType;
                        if (blobType && typeof blobType === 'string') {
                            blobType = blobType.split(';')[0];
                        }
                        blob = new Blob(chunks, { type: blobType || 'audio/webm' });
                    } catch (error) {}

                    state.mediaRecorder = null;
                    state.recordedChunks = [];

                    if (!blob || !blob.size) {
                        updateTranscribeButtonState(state);
                        return;
                    }

                    var extension = '';
                    if (baseMimeType && baseMimeType.indexOf('/') !== -1) {
                        extension = baseMimeType.split('/')[1] || '';
                    }

                    var safeExtension = extension ? extension.replace(/[^a-z0-9]/gi, '') : 'webm';
                    if (!safeExtension) {
                        safeExtension = 'webm';
                    }
                    var fileName = 'transcription-' + Date.now() + '.' + safeExtension;

                    var file = null;
                    try {
                        var fileType = blob && blob.type ? blob.type : baseMimeType || 'audio/webm';
                        if (fileType && typeof fileType === 'string') {
                            fileType = fileType.split(';')[0];
                        }
                        file = new File([blob], fileName, { type: fileType || 'audio/webm' });
                    } catch (error) {
                        file = blob;
                        file.name = fileName;
                        if (file && file.type && typeof file.type === 'string') {
                            file.type = file.type.split(';')[0];
                        }
                        if (file && !file.type && baseMimeType) {
                            file.type = baseMimeType;
                        }
                    }

                    transcribeAudioFile(state, file);
                });

                state.mediaRecorder.start();
                setTranscribeRecordingState(state, true);
                updateTranscribeButtonState(state);
            })
            .catch(function () {
                stopRecordingStream(state);
                setStatus(
                    state.container,
                    getString(
                        'recordingError',
                        'Could not access your microphone. Please allow access or upload an audio file instead.'
                    )
                );

                if (state.transcribeInput && !state.transcribeInput.disabled) {
                    state.transcribeInput.click();
                }

                updateTranscribeButtonState(state);
            });
    }

    function stopTranscribeRecording(state) {
        if (!state || !state.mediaRecorder) {
            return;
        }

        state.recordingShouldProcess = true;

        try {
            if (state.mediaRecorder.state !== 'inactive') {
                state.mediaRecorder.stop();
            }
        } catch (error) {
            stopRecordingStream(state);
            setTranscribeRecordingState(state, false);
            updateTranscribeButtonState(state);
        }
    }

    function handleTranscribeFileSelection(event, state) {
        if (!state || !state.canUploadAttachments) {
            return;
        }

        if (!event || !event.target || !event.target.files) {
            return;
        }

        var files = Array.prototype.slice.call(event.target.files);
        event.target.value = '';

        if (!files.length) {
            return;
        }

        var file = files[0];
        transcribeAudioFile(state, file);
    }

    function transcribeAudioFile(state, file) {
        if (!state || !file || !state.canUploadAttachments || state.transcribing) {
            return;
        }

        if (file.size && file.size > MAX_TRANSCRIBE_BYTES) {
            setStatus(
                state.container,
                getString(
                    'transcriptionFileTooLarge',
                    'The selected audio file is too large. Please choose a file under 25MB.'
                )
            );
            updateTranscribeButtonState(state);
            return;
        }

        state.transcribing = true;
        updateTranscribeButtonState(state);

        setStatus(state.container, getString('transcribing', 'Transcribing audio…'));

        var uploadedRecord = null;

        uploadAudioForTranscription(state, file)
            .then(function (record) {
                uploadedRecord = record;
                if (!record || typeof record.id === 'undefined') {
                    throw new Error('Upload failed');
                }

                if (state.attachmentLibrary && record.fileId) {
                    state.attachmentLibrary[record.fileId] = record;
                }

                return requestTranscription(state, record);
            })
            .then(function (response) {
                var result = extractTranscriptionResult(response);
                insertTranscriptionResult(state, result, uploadedRecord || file);

                var label = '';
                if (uploadedRecord && uploadedRecord.name) {
                    label = uploadedRecord.name;
                } else if (file && file.name) {
                    label = file.name;
                }

                var messageLabel = label || getString('transcribeAudio', 'Transcribe audio');
                var message = formatString(
                    getString('transcriptionSuccess', 'Inserted transcription from “%s”.'),
                    messageLabel
                );
                setStatus(state.container, message);
            })
            .catch(function (error) {
                setStatus(
                    state.container,
                    getString('transcriptionError', 'The transcription request failed. Please try again.')
                );

                if (window.console && console.error) {
                    console.error('Transcription failed', error);
                }
            })
            .finally(function () {
                state.transcribing = false;
                updateTranscribeButtonState(state);
            });
    }

    function uploadAudioForTranscription(state, file) {
        if (!state || !file || !state.config || !state.config.uploadEndpoint) {
            return Promise.reject(new Error('Upload unavailable'));
        }

        var headers = {
            'X-WP-Nonce': globalConfig.nonce || '',
            Accept: 'application/json',
        };

        var contentDisposition = createContentDispositionHeader(file.name || 'audio');
        if (contentDisposition) {
            headers['Content-Disposition'] = contentDisposition;
        }

        var contentType = '';
        if (file && file.type && typeof file.type === 'string') {
            contentType = file.type.split(';')[0];
        }

        headers['Content-Type'] = contentType || 'audio/webm';

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
                return normaliseUploadResponse(data, file);
            });
    }

    function requestTranscription(state, record) {
        if (!state || !record || typeof record.id === 'undefined') {
            return Promise.reject(new Error('Missing attachment id'));
        }

        if (!state.config || !state.config.toolsEndpoint) {
            return Promise.reject(new Error('Tools endpoint unavailable'));
        }

        var payload = {
            assistant_id: state.config.assistantId,
            tool: TRANSCRIBE_TOOL_NAME,
            arguments: {
                attachment_id: record.id,
            },
        };

        return fetch(state.config.toolsEndpoint, {
            method: 'POST',
            headers: buildJsonHeaders(state),
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        }).then(function (response) {
            if (!response.ok) {
                throw response;
            }

            return response.json();
        });
    }

    function extractTranscriptionResult(body) {
        if (!body || typeof body !== 'object') {
            return null;
        }

        if (Object.prototype.hasOwnProperty.call(body, 'result')) {
            return body.result;
        }

        return body;
    }

    function insertTranscriptionResult(state, result, record) {
        if (!state || !state.textarea) {
            return;
        }

        var payload = result || {};
        var text = '';

        if (payload && typeof payload.text === 'string') {
            text = payload.text.trim();
        }

        var metaParts = [];
        if (record && record.name) {
            metaParts.push(record.name);
        }

        if (payload.language) {
            metaParts.push('Language: ' + payload.language);
        }

        if (typeof payload.duration === 'number') {
            var duration = formatDuration(payload.duration);
            if (duration) {
                metaParts.push('Duration: ' + duration);
            }
        }

        if (payload.translated) {
            metaParts.push('Translated to English');
        }

        var segmentsText = '';
        if (Array.isArray(payload.segments) && payload.segments.length) {
            segmentsText = payload.segments
                .map(function (segment) {
                    if (!segment) {
                        return '';
                    }

                    var start = formatDuration(segment.start);
                    var end = formatDuration(segment.end);
                    var segmentText = segment.text || '';
                    var prefix = '';

                    if (start && end) {
                        prefix = start + '–' + end;
                    } else if (start) {
                        prefix = start;
                    }

                    if (prefix) {
                        return prefix + ': ' + segmentText;
                    }

                    return segmentText;
                })
                .filter(function (segmentText) {
                    return segmentText && segmentText.trim();
                })
                .join('\n');
        }

        var hasTextContent = Boolean(text) || Boolean(segmentsText);
        if (!hasTextContent) {
            return;
        }

        var sections = [];
        if (metaParts.length) {
            sections.push(metaParts.join(' • '));
        }

        if (text) {
            sections.push(text);
        }

        if (segmentsText) {
            sections.push(segmentsText);
        }

        var combined = sections.join('\n\n').trim();
        if (!combined) {
            return;
        }

        var existing = state.textarea.value || '';
        var trimmedExisting = existing.replace(/\s+$/, '');
        var newValue = trimmedExisting ? trimmedExisting + '\n\n' + combined : combined;

        state.textarea.value = newValue;
        state.textarea.focus();

        try {
            var caret = newValue.length;
            state.textarea.setSelectionRange(caret, caret);
        } catch (error) {}
    }

    function formatDuration(value) {
        var seconds = Number(value);
        if (!isFinite(seconds) || seconds < 0) {
            return '';
        }

        var totalSeconds = Math.round(seconds);
        var hours = Math.floor(totalSeconds / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var secs = totalSeconds % 60;

        var parts = [];
        if (hours) {
            parts.push(hours);
        }

        parts.push(hours ? String(minutes).padStart(2, '0') : String(minutes));
        parts.push(String(secs).padStart(2, '0'));

        return parts.join(':');
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

    function getRoleLabel(role) {
        var labels = globalConfig.strings && globalConfig.strings.roleLabels ? globalConfig.strings.roleLabels : null;
        var normalised = typeof role === 'string' ? role.toLowerCase() : '';

        if (labels && Object.prototype.hasOwnProperty.call(labels, normalised)) {
            var label = labels[normalised];
            if (typeof label === 'string' && label) {
                return label;
            }
        }

        if (!normalised) {
            return '';
        }

        return normalised.charAt(0).toUpperCase() + normalised.slice(1);
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

    function updateHistoryToggle(state) {
        if (!state || !state.historyToggle) {
            return;
        }

        var expanded = !!state.historyVisible;
        var label = expanded
            ? getString('historyToggleHide', 'Hide previous conversations')
            : getString('historyToggleShow', 'Show previous conversations');

        state.historyToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        state.historyToggle.setAttribute('aria-label', label);

        var screenReaderText = state.historyToggle.querySelector('.screen-reader-text');
        if (screenReaderText) {
            screenReaderText.textContent = label;
        }

        if (expanded) {
            state.historyToggle.classList.add('wp-mcp-ai-chat__history-toggle--active');
        } else {
            state.historyToggle.classList.remove('wp-mcp-ai-chat__history-toggle--active');
        }
    }

    function setHistoryStatus(state, message, isError) {
        if (!state || !state.historyStatus) {
            return;
        }

        if (!message) {
            state.historyStatus.textContent = '';
            state.historyStatus.hidden = true;
            state.historyStatus.classList.remove('wp-mcp-ai-chat__history-status--error');
            return;
        }

        state.historyStatus.hidden = false;
        state.historyStatus.textContent = message;

        if (isError) {
            state.historyStatus.classList.add('wp-mcp-ai-chat__history-status--error');
        } else {
            state.historyStatus.classList.remove('wp-mcp-ai-chat__history-status--error');
        }
    }

    function getHistoryEndpoint(state) {
        if (state && state.config && state.config.transcriptsEndpoint) {
            return state.config.transcriptsEndpoint;
        }

        if (globalConfig.transcriptsEndpoint) {
            return globalConfig.transcriptsEndpoint;
        }

        return '';
    }

    function buildHistoryHeaders(state) {
        var headers = { 'Accept': 'application/json' };
        var nonce = '';

        if (state && state.config) {
            if (state.config.restNonce) {
                nonce = state.config.restNonce;
            } else if (globalConfig.nonce) {
                nonce = globalConfig.nonce;
            }

            if (state.config.guestToken) {
                headers['X-WP-MCP-AI-Guest'] = state.config.guestToken;
            }
        } else if (globalConfig.nonce) {
            nonce = globalConfig.nonce;
        }

        if (nonce) {
            headers['X-WP-Nonce'] = nonce;
        }

        return headers;
    }

    function formatHistoryDate(value) {
        if (!value) {
            return '';
        }

        var date = new Date(value);

        if (isNaN(date.getTime())) {
            return '';
        }

        if (typeof window !== 'undefined' && window.Intl && window.Intl.DateTimeFormat) {
            try {
                return new window.Intl.DateTimeFormat(undefined, {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                }).format(date);
            } catch (error) {}
        }

        if (date.toLocaleString) {
            return date.toLocaleString();
        }

        return date.toISOString();
    }

    function formatHistoryMessageCount(count) {
        var total = parseInt(count, 10) || 0;

        if (total === 1) {
            return getString('historySingleMessage', '1 message');
        }

        var template = getString('historyMessageCount', '%d messages');

        if (template.indexOf('%d') !== -1) {
            return template.replace('%d', total);
        }

        return template + ' ' + total;
    }

    function formatHistorySessionTitle(state, session, index) {
        if (session && session.preview) {
            return session.preview;
        }

        if (session && session.assistant_title) {
            return session.assistant_title;
        }

        var template = getString('historyPreviewFallback', 'Conversation %s');
        var placeholder = template.indexOf('%s') !== -1 ? template : template + ' %s';
        var number = typeof index === 'number' ? index + 1 : 1;

        return placeholder.replace('%s', number);
    }

    function buildHistoryMeta(state, session) {
        var parts = [];

        if (session) {
            var timestamp = session.updated_at || session.completed_at || session.started_at;
            var formattedDate = formatHistoryDate(timestamp);

            if (formattedDate) {
                parts.push(formattedDate);
            }

            if (session.turn_count) {
                parts.push(formatHistoryMessageCount(session.turn_count));
            }
        }

        return parts.join(' · ');
    }

    function renderHistorySessions(state) {
        if (!state || !state.historyList) {
            return;
        }

        state.historyList.innerHTML = '';

        if (!Array.isArray(state.historySessions) || !state.historySessions.length) {
            return;
        }

        var fragment = document.createDocumentFragment();

        state.historySessions.forEach(function (session, index) {
            var item = document.createElement('li');
            item.className = 'wp-mcp-ai-chat__history-item';

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'wp-mcp-ai-chat__history-session';
            button.setAttribute('aria-expanded', 'false');
            button.dataset.sessionKey = session && session.session_key ? session.session_key : '';

            var content = document.createElement('div');
            content.className = 'wp-mcp-ai-chat__history-session-content';

            var title = document.createElement('span');
            title.className = 'wp-mcp-ai-chat__history-session-title';
            title.textContent = formatHistorySessionTitle(state, session, index);
            content.appendChild(title);

            var metaText = buildHistoryMeta(state, session);
            if (metaText) {
                var meta = document.createElement('span');
                meta.className = 'wp-mcp-ai-chat__history-session-meta';
                meta.textContent = metaText;
                content.appendChild(meta);
            }

            button.appendChild(content);

            var icon = document.createElement('span');
            icon.className = 'wp-mcp-ai-chat__history-session-icon';
            icon.innerHTML =
                '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
                '<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />' +
                '</svg>';
            button.appendChild(icon);

            var deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'wp-mcp-ai-chat__history-delete';
            deleteButton.setAttribute('aria-label', getString('deleteConversation', 'Delete this conversation'));
            deleteButton.setAttribute('title', getString('deleteConversation', 'Delete this conversation'));
            deleteButton.innerHTML =
                '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
                '<path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" />' +
                '</svg>';

            deleteButton.addEventListener('click', function (event) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }
                if (event && typeof event.stopPropagation === 'function') {
                    event.stopPropagation();
                }

                handleHistoryDelete(state, session, item);
            });

            var details = document.createElement('div');
            details.className = 'wp-mcp-ai-chat__history-messages';
            details.hidden = true;

            item.appendChild(button);
            item.appendChild(deleteButton);
            item.appendChild(details);

            button.addEventListener('click', function (event) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }

                toggleHistorySession(state, item, session);
            });

            if (
                state.activeHistorySessionKey &&
                session &&
                session.session_key &&
                state.activeHistorySessionKey === session.session_key
            ) {
                item.classList.add('wp-mcp-ai-chat__history-item--active');
            }

            fragment.appendChild(item);
        });

        state.historyList.appendChild(fragment);
    }

    function handleHistoryDelete(state, session, item) {
        if (!state || !session || !session.session_key) {
            return;
        }

        var confirmMessage = getString(
            'confirmDeleteConversation',
            'Are you sure you want to delete this conversation? This action cannot be undone.'
        );

        if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
            if (!window.confirm(confirmMessage)) {
                return;
            }
        }

        var sessionKey = session.session_key;
        var endpoint = getHistoryEndpoint(state);

        if (!endpoint) {
            setHistoryStatus(state, getString('historyDeleteError', 'Unable to delete conversation.'), true);
            return;
        }

        var deleteUrl = endpoint + '/' + encodeURIComponent(sessionKey);

        fetch(deleteUrl, {
            method: 'DELETE',
            headers: buildHistoryHeaders(state),
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    if (response.status === 401) {
                        throw new Error('unauthorized');
                    } else if (response.status === 404) {
                        throw new Error('not_found');
                    } else if (response.status >= 500) {
                        throw new Error('server_error');
                    }
                    throw new Error('delete_failed');
                }
                return response.json();
            })
            .then(function () {
                if (item && item.parentNode) {
                    item.parentNode.removeChild(item);
                }

                state.historySessions = state.historySessions.filter(function (s) {
                    return s.session_key !== sessionKey;
                });

                if (state.activeHistorySessionKey === sessionKey) {
                    state.activeHistorySessionKey = '';
                }

                if (state.historySessionDetails && state.historySessionDetails[sessionKey]) {
                    delete state.historySessionDetails[sessionKey];
                }

                setHistoryStatus(state, getString('historyDeleteSuccess', 'Conversation deleted successfully.'), false);

                setTimeout(function () {
                    setHistoryStatus(state, '', false);
                }, 3000);
            })
            .catch(function (error) {
                var errorMessage;
                
                if (error && error.message === 'unauthorized') {
                    errorMessage = getString('historyDeleteUnauthorized', 'You are not authorized to delete this conversation.');
                } else if (error && error.message === 'not_found') {
                    errorMessage = getString('historyDeleteNotFound', 'This conversation could not be found.');
                } else if (error && error.message === 'server_error') {
                    errorMessage = getString('historyDeleteServerError', 'A server error occurred. Please try again later.');
                } else {
                    errorMessage = getString('historyDeleteError', 'Unable to delete conversation.');
                }
                
                setHistoryStatus(state, errorMessage, true);
            });
    }

    function toggleHistoryVisibility(state) {
        if (!state) {
            return;
        }

        setHistoryVisibility(state, !state.historyVisible);
    }

    function setHistoryVisibility(state, visible) {
        if (!state) {
            return;
        }

        state.historyVisible = !!visible;

        if (state.historyContainer) {
            state.historyContainer.hidden = !state.historyVisible;
        }

        updateHistoryToggle(state);

        if (state.historyVisible) {
            ensureHistorySessions(state);
        }
    }

    function ensureHistorySessions(state) {
        if (!state) {
            return Promise.resolve();
        }

        if (state.historyLoaded || state.historyLoading) {
            return state.historyLoadPromise || Promise.resolve();
        }

        state.historyLoadPromise = loadHistorySessions(state);
        return state.historyLoadPromise;
    }

    function loadHistorySessions(state) {
        var endpoint = getHistoryEndpoint(state);

        if (!endpoint) {
            state.historyLoaded = true;
            setHistoryStatus(state, getString('historyError', 'Unable to load conversation history.'), true);
            state.historyLoadPromise = null;
            return Promise.resolve();
        }

        state.historyLoading = true;
        setHistoryStatus(state, getString('historyLoading', 'Loading conversations…'), false);

        var perPage = state.config && state.config.historyPerPage ? state.config.historyPerPage : globalConfig.historyPerPage;
        if (!perPage || perPage < 1) {
            perPage = 20;
        }

        return fetchHistorySessions(state, endpoint, perPage)
            .then(function (data) {
                var sessions = Array.isArray(data && data.sessions) ? data.sessions : [];
                var assistantId = state.config && state.config.assistantId ? parseInt(state.config.assistantId, 10) : 0;

                if (assistantId) {
                    sessions = sessions.filter(function (session) {
                        return parseInt(session.assistant_id, 10) === assistantId;
                    });
                }

                state.historySessions = sessions;
                state.historyLoaded = true;

                renderHistorySessions(state);

                if (!sessions.length) {
                    var message = data && data.message ? data.message : getString('historyEmpty', 'No previous conversations yet.');
                    setHistoryStatus(state, message, false);
                } else {
                    setHistoryStatus(state, '', false);
                }
            })
            .catch(function (error) {
                state.historySessions = [];
                renderHistorySessions(state);

                var message = error && error.message ? error.message : getString('historyError', 'Unable to load conversation history.');
                setHistoryStatus(state, message, true);
                state.historyLoaded = false;
            })
            .finally(function () {
                state.historyLoading = false;
                state.historyLoadPromise = null;
            });
    }

    function fetchHistorySessions(state, endpoint, perPage) {
        var url = endpoint;

        if (perPage && perPage > 0) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'per_page=' + encodeURIComponent(perPage);
        }

        return fetch(url, {
            method: 'GET',
            headers: buildHistoryHeaders(state),
        }).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return null;
                })
                .then(function (data) {
                    if (!response.ok) {
                        var message = data && data.message ? data.message : getString('historyError', 'Unable to load conversation history.');
                        var error = new Error(message);
                        error.status = response.status;
                        throw error;
                    }

                    return data;
                });
        });
    }

    function fetchHistorySessionDetails(state, sessionKey) {
        if (!sessionKey) {
            return Promise.reject(new Error(getString('historySessionError', 'Unable to load this conversation. Please try again.')));
        }

        var endpoint = getHistoryEndpoint(state);

        if (!endpoint) {
            return Promise.reject(new Error(getString('historySessionError', 'Unable to load this conversation. Please try again.')));
        }

        var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'session_key=' + encodeURIComponent(sessionKey);

        return fetch(url, {
            method: 'GET',
            headers: buildHistoryHeaders(state),
        }).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return null;
                })
                .then(function (data) {
                    if (!response.ok) {
                        var message = data && data.message ? data.message : getString('historySessionError', 'Unable to load this conversation. Please try again.');
                        var error = new Error(message);
                        error.status = response.status;
                        throw error;
                    }

                    if (data && data.session) {
                        return data.session;
                    }

                    if (data && data.message) {
                        throw new Error(data.message);
                    }

                    throw new Error(getString('historySessionError', 'Unable to load this conversation. Please try again.'));
                });
        });
    }

    function renderHistorySessionLoading(state, container) {
        if (!container) {
            return;
        }

        container.dataset.loaded = 'loading';
        container.hidden = false;
        container.textContent = '';

        var notice = document.createElement('p');
        notice.className = 'wp-mcp-ai-chat__history-notice';
        notice.textContent = getString('historySessionLoading', 'Loading conversation…');
        container.appendChild(notice);
    }

    function renderHistorySessionError(state, container, message) {
        if (!container) {
            return;
        }

        container.dataset.loaded = 'error';
        container.textContent = '';

        var notice = document.createElement('p');
        notice.className = 'wp-mcp-ai-chat__history-notice wp-mcp-ai-chat__history-notice--error';
        notice.textContent = message || getString('historySessionError', 'Unable to load this conversation. Please try again.');
        container.appendChild(notice);
    }

    function renderHistorySessionDetails(state, container, session) {
        if (!container) {
            return;
        }

        container.dataset.loaded = 'true';
        container.textContent = '';

        if (!session || !Array.isArray(session.messages) || !session.messages.length) {
            var empty = document.createElement('p');
            empty.className = 'wp-mcp-ai-chat__history-notice';
            empty.textContent = getString('historyNoMessages', 'No messages were saved for this conversation.');
            container.appendChild(empty);
            return;
        }

        var list = document.createElement('ul');
        list.className = 'wp-mcp-ai-chat__history-message-list';

        session.messages.forEach(function (message) {
            if (!message || typeof message !== 'object') {
                return;
            }

            var role = typeof message.role === 'string' ? message.role.toLowerCase() : 'assistant';
            var text = typeof message.content === 'string' ? message.content : '';

            var item = document.createElement('li');
            item.className = 'wp-mcp-ai-chat__history-message wp-mcp-ai-chat__history-message--' + role;

            var roleLabel = document.createElement('span');
            roleLabel.className = 'wp-mcp-ai-chat__history-message-role';
            roleLabel.textContent = getRoleLabel(role);
            item.appendChild(roleLabel);

            var body = document.createElement('div');
            body.className = 'wp-mcp-ai-chat__history-message-text';

            if (text) {
                var normalised = String(text).replace(/\r\n|\r|\u2028|\u2029/g, '\n');
                body.innerHTML = escapeHtml(normalised).replace(/\n/g, '<br />');
            } else {
                body.textContent = '';
            }

            item.appendChild(body);
            list.appendChild(item);
        });

        if (!list.children.length) {
            var fallback = document.createElement('p');
            fallback.className = 'wp-mcp-ai-chat__history-notice';
            fallback.textContent = getString('historyNoMessages', 'No messages were saved for this conversation.');
            container.appendChild(fallback);
            return;
        }

        container.appendChild(list);
    }

    function normaliseHistoryRole(role) {
        if (!role) {
            return '';
        }

        var normalised = String(role).toLowerCase();

        if (normalised === 'function' || normalised === 'tool_result' || normalised === 'observation') {
            return 'tool';
        }

        if (normalised === 'assistant' || normalised === 'user' || normalised === 'system' || normalised === 'tool') {
            return normalised;
        }

        return '';
    }

    function setActiveHistorySession(state, sessionKey, activeItem) {
        if (!state || !state.historyList) {
            return;
        }

        state.activeHistorySessionKey = sessionKey || '';

        var items = state.historyList.querySelectorAll('.wp-mcp-ai-chat__history-item');
        Array.prototype.forEach.call(items, function (node) {
            if (node === activeItem) {
                node.classList.add('wp-mcp-ai-chat__history-item--active');
            } else {
                node.classList.remove('wp-mcp-ai-chat__history-item--active');
            }
        });
    }

    function loadHistorySessionIntoChat(state, session, activeItem, chatWindow) {
        // Use the provided chatWindow or fall back to state.messagesEl
        var messagesEl = chatWindow || state.messagesEl;
        
        if (!state || !messagesEl) {
            return;
        }

        if (!session || typeof session !== 'object') {
            setActiveHistorySession(state, '', activeItem);
            setStatus(state.container, getString('historySessionError', 'Unable to load this conversation. Please try again.'));
            return;
        }

        var sessionKey = session.session_key ? String(session.session_key) : '';
        setActiveHistorySession(state, sessionKey, activeItem);

        if (sessionKey) {
            state.config.sessionKey = sessionKey;
        }

        var assistantId = parseInt(session.assistant_id, 10);
        if (!isNaN(assistantId) && assistantId > 0) {
            state.config.assistantId = assistantId;
        }

        messagesEl.textContent = '';
        state.conversation = [];
        state.pendingAttachments = [];
        state.validationNotice = '';

        renderPendingAttachments(state);
        updateAttachButtonState(state);

        if (state.textarea) {
            state.textarea.value = '';
        }

        var messages = Array.isArray(session.messages) ? session.messages : [];

        if (!messages.length) {
            appendMessage(messagesEl, 'system', {
                text: getString('historyNoMessages', 'No messages were saved for this conversation.'),
            });
            setTranscriptExpanded(state, true);
            setStatus(state.container, '');
            return;
        }

        messages.forEach(function (message) {
            if (!message || typeof message !== 'object') {
                return;
            }

            var role = normaliseHistoryRole(message.role);
            if (!role) {
                return;
            }

            var content = '';
            if (typeof message.content === 'string') {
                content = message.content;
            } else if (message.content && typeof message.content.text === 'string') {
                content = message.content.text;
            }

            var trimmedContent = typeof content === 'string' ? content : '';
            var hasContent = trimmedContent.trim() !== '';

            if (!hasContent && role !== 'tool') {
                return;
            }

            var payload = { text: trimmedContent };
            var allowMarkdown = role === 'assistant';

            appendMessage(messagesEl, role, payload, allowMarkdown);
            if (hasContent || role === 'tool') {
                state.conversation.push({ role: role, content: trimmedContent });
            }
        });

        // Save the loaded conversation to localStorage
        saveConversationToStorage(state);

        setTranscriptExpanded(state, true);
        setStatus(state.container, '');
    }

    function toggleHistorySession(state, item, session) {
        if (!state || !item) {
            return;
        }

        var button = item.querySelector('.wp-mcp-ai-chat__history-session');
        var details = item.querySelector('.wp-mcp-ai-chat__history-messages');

        if (!button || !details) {
            return;
        }

        var expanded = button.getAttribute('aria-expanded') === 'true';

        if (expanded) {
            button.setAttribute('aria-expanded', 'false');
            details.hidden = true;
            item.classList.remove('wp-mcp-ai-chat__history-item--expanded');
            return;
        }

        button.setAttribute('aria-expanded', 'true');
        details.hidden = false;
        item.classList.add('wp-mcp-ai-chat__history-item--expanded');

        var sessionKey = session && session.session_key ? session.session_key : '';

        // Find the main chat window relative to the history item to ensure correct targeting
        var chatContainer = item.closest('.wp-mcp-ai-chat');
        var chatWindow = chatContainer ? chatContainer.querySelector('.wp-mcp-ai-chat__messages') : null;
        
        if (!chatWindow) {
            // Fallback to state.messagesEl if DOM traversal fails
            chatWindow = state.messagesEl;
        }

        if (sessionKey && state.historySessionDetails && state.historySessionDetails[sessionKey]) {
            var cachedSession = state.historySessionDetails[sessionKey];
            renderHistorySessionDetails(state, details, cachedSession);
            loadHistorySessionIntoChat(state, cachedSession, item, chatWindow);
            return;
        }

        if (details.dataset.loaded === 'true') {
            return;
        }

        renderHistorySessionLoading(state, details);

        fetchHistorySessionDetails(state, sessionKey)
            .then(function (data) {
                if (sessionKey) {
                    state.historySessionDetails[sessionKey] = data;
                }

                renderHistorySessionDetails(state, details, data);
                loadHistorySessionIntoChat(state, data, item, chatWindow);
            })
            .catch(function (error) {
                var message = error && error.message ? error.message : getString('historySessionError', 'Unable to load this conversation. Please try again.');
                renderHistorySessionError(state, details, message);
            });
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

            updateTranscribeButtonState(state);
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

        updateTranscribeButtonState(state);
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

        function attachToolResultAttachments(segment) {
            if (!segment || typeof segment !== 'object') {
                return;
            }

            var toolCallId = '';

            if (typeof segment.tool_call_id === 'string' && segment.tool_call_id) {
                toolCallId = segment.tool_call_id;
            } else if (typeof segment.call_id === 'string' && segment.call_id) {
                toolCallId = segment.call_id;
            } else if (typeof segment.id === 'string' && segment.id.indexOf('call_') === 0) {
                toolCallId = segment.id;
            }

            if (!toolCallId) {
                return;
            }

            var fallbackLabel = '';
            if (typeof segment.label === 'string' && segment.label.trim()) {
                fallbackLabel = segment.label.trim();
            } else if (typeof segment.title === 'string' && segment.title.trim()) {
                fallbackLabel = segment.title.trim();
            } else if (typeof segment.name === 'string' && segment.name.trim()) {
                fallbackLabel = segment.name.trim();
            } else if (typeof segment.tool_name === 'string' && segment.tool_name.trim()) {
                fallbackLabel = segment.tool_name.trim();
            }

            var lookupResult = findToolResultInConversation(state, toolCallId);
            if (!lookupResult || !lookupResult.result) {
                return;
            }

            var toolName = fallbackLabel || lookupResult.toolName || '';
            var normalised = normaliseToolResultForDisplay(toolName, lookupResult.result);

            if (!normalised || !normalised.attachments || !normalised.attachments.length) {
                return;
            }

            normalised.attachments.forEach(function (attachment, index) {
                if (!attachment || typeof attachment !== 'object' || !attachment.url) {
                    return;
                }

                var entry = {
                    url: attachment.url,
                    label: attachment.label || defaultAttachmentLabel,
                    downloadName: attachment.downloadName || '',
                    meta: attachment.meta || '',
                };

                registerAttachmentEntry(
                    'tool-call-' + toolCallId + '-' + index,
                    entry,
                    '',
                    fallbackLabel || toolName
                );
            });
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

            if (type === 'tool_result') {
                attachToolResultAttachments(segment);

                if (segment.content) {
                    processSegment(segment.content);
                }

                return;
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

            var downloadUrl = record.url || '';
            if (!downloadUrl) {
                downloadUrl = buildFileDownloadUrl(state, record.fileId);
                if (downloadUrl) {
                    record.url = downloadUrl;
                }
            }

            if (!record.downloadName) {
                if (record.name) {
                    record.downloadName = record.name;
                } else {
                    record.downloadName = record.fileId;
                }
            }

            if (!lookup[record.fileId] || shouldReplace(lookup[record.fileId], record)) {
                lookup[record.fileId] = record;
            } else if (!lookup[record.fileId].url && downloadUrl) {
                lookup[record.fileId].url = downloadUrl;
            }

            if (state && state.attachmentLibrary) {
                var existing = state.attachmentLibrary[record.fileId];
                if (existing && !existing.url && downloadUrl) {
                    existing.url = downloadUrl;
                }
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

    function buildFileDownloadUrl(state, fileId) {
        if (!fileId) {
            return '';
        }

        var base = '';

        if (state && state.config && state.config.filesEndpoint) {
            base = state.config.filesEndpoint;
        } else if (globalConfig.filesEndpoint) {
            base = globalConfig.filesEndpoint;
        }

        if (!base) {
            return '';
        }

        if (base.charAt(base.length - 1) === '/') {
            base = base.slice(0, -1);
        }

        var url = base + '/' + encodeURIComponent(String(fileId)) + '/download';
        var params = [];

        if (state && state.config && state.config.assistantId) {
            params.push('assistant_id=' + encodeURIComponent(state.config.assistantId));
        }

        var guestToken = state && state.config ? state.config.guestToken : '';
        if (guestToken) {
            params.push('guest_token=' + encodeURIComponent(guestToken));
        } else {
            var nonce = '';
            if (state && state.config && state.config.restNonce) {
                nonce = state.config.restNonce;
            } else if (globalConfig.nonce) {
                nonce = globalConfig.nonce;
            }

            if (nonce) {
                params.push('_wpnonce=' + encodeURIComponent(nonce));
            }
        }

        if (params.length) {
            url += '?' + params.join('&');
        }

        return url;
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

        if (record.fileId) {
            var fallbackUrl = buildFileDownloadUrl(state, record.fileId);
            if (fallbackUrl) {
                record.url = fallbackUrl;
                return record.url;
            }
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

        var nestedImage = result && result.image && typeof result.image === 'object' ? result.image : null;

        var url = '';
        if (typeof result.url === 'string' && result.url.trim()) {
            url = result.url.trim();
        } else if (typeof result.download_url === 'string' && result.download_url.trim()) {
            url = result.download_url.trim();
        } else if (typeof result.downloadUrl === 'string' && result.downloadUrl.trim()) {
            url = result.downloadUrl.trim();
        } else if (nestedImage) {
            if (typeof nestedImage.url === 'string' && nestedImage.url.trim()) {
                url = nestedImage.url.trim();
            } else if (typeof nestedImage.download_url === 'string' && nestedImage.download_url.trim()) {
                url = nestedImage.download_url.trim();
            } else if (typeof nestedImage.downloadUrl === 'string' && nestedImage.downloadUrl.trim()) {
                url = nestedImage.downloadUrl.trim();
            }
        }

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
        } else if (nestedImage) {
            if (typeof nestedImage.title === 'string' && nestedImage.title.trim()) {
                label = nestedImage.title.trim();
            } else if (typeof nestedImage.file_name === 'string' && nestedImage.file_name.trim()) {
                label = nestedImage.file_name.trim();
            } else if (typeof nestedImage.fileName === 'string' && nestedImage.fileName.trim()) {
                label = nestedImage.fileName.trim();
            }
        }

        var metaParts = [];
        var metaRecord = {
            bytes: typeof result.bytes === 'number' ? result.bytes : null,
            mime_type: result.mime_type || result.mimeType || '',
        };

        if (metaRecord.bytes === null && nestedImage && typeof nestedImage.bytes === 'number') {
            metaRecord.bytes = nestedImage.bytes;
        }

        if (!metaRecord.mime_type && nestedImage) {
            metaRecord.mime_type = nestedImage.mime_type || nestedImage.mimeType || '';
        }

        var baseMeta = buildAttachmentMeta(metaRecord);
        if (baseMeta) {
            metaParts.push(baseMeta);
        }

        var attachmentId = typeof result.attachment_id === 'number' ? result.attachment_id : null;
        if (!attachmentId && nestedImage && typeof nestedImage.attachment_id === 'number') {
            attachmentId = nestedImage.attachment_id;
        }

        if (attachmentId) {
            metaParts.push('ID: ' + attachmentId);
        }

        var sizeValue = '';
        if (typeof result.size === 'string' && result.size.trim()) {
            sizeValue = result.size.trim();
        } else if (nestedImage && typeof nestedImage.size === 'string' && nestedImage.size.trim()) {
            sizeValue = nestedImage.size.trim();
        }

        var qualityValue = '';
        if (typeof result.quality === 'string' && result.quality.trim()) {
            qualityValue = result.quality.trim();
        } else if (nestedImage && typeof nestedImage.quality === 'string' && nestedImage.quality.trim()) {
            qualityValue = nestedImage.quality.trim();
        }

        var aspectRatioValue = '';
        if (typeof result.aspect_ratio === 'string' && result.aspect_ratio.trim()) {
            aspectRatioValue = result.aspect_ratio.trim();
        } else if (nestedImage && typeof nestedImage.aspect_ratio === 'string' && nestedImage.aspect_ratio.trim()) {
            aspectRatioValue = nestedImage.aspect_ratio.trim();
        }

        var formatValue = '';
        if (typeof result.format === 'string' && result.format.trim()) {
            formatValue = result.format.trim();
        } else if (nestedImage && typeof nestedImage.format === 'string' && nestedImage.format.trim()) {
            formatValue = nestedImage.format.trim();
        }

        if (toolName === 'generate_openai_image' || toolName === 'generate_perfume_lifestyle_image') {
            if (sizeValue) {
                metaParts.push(sizeValue);
            }

            if (qualityValue) {
                metaParts.push(qualityValue);
            }
        } else if (toolName === 'generate_gemini_image') {
            if (aspectRatioValue) {
                metaParts.push(aspectRatioValue);
            }

            if (formatValue) {
                metaParts.push(formatValue.toUpperCase());
            }
        } else if (toolName === SPEECH_TOOL_NAME) {
            if (typeof result.duration_formatted === 'string' && result.duration_formatted.trim()) {
                metaParts.push(result.duration_formatted.trim());
            }

            if (formatValue) {
                metaParts.push(formatValue.toUpperCase());
            }
        }

        var attachmentMeta = metaParts.join(' • ');

        var downloadName = '';
        if (typeof result.file_name === 'string' && result.file_name.trim()) {
            downloadName = result.file_name.trim();
        } else if (typeof result.fileName === 'string' && result.fileName.trim()) {
            downloadName = result.fileName.trim();
        } else if (nestedImage) {
            if (typeof nestedImage.file_name === 'string' && nestedImage.file_name.trim()) {
                downloadName = nestedImage.file_name.trim();
            } else if (typeof nestedImage.fileName === 'string' && nestedImage.fileName.trim()) {
                downloadName = nestedImage.fileName.trim();
            }
        }

        if (!label && downloadName) {
            label = downloadName;
        }

        attachments.push({
            url: url,
            label: label || getString('downloadAttachment', 'Download attachment'),
            downloadName: downloadName,
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
        } else if (toolName === 'generate_gemini_image') {
            text = getString('geminiImageToolSuccess', 'Gemini image saved to the Media Library.');
        } else if (toolName === 'generate_perfume_lifestyle_image') {
            text = getString('perfumeLifestyleImageToolSuccess', 'Lifestyle image saved to the Media Library.');
        } else if (toolName === SPEECH_TOOL_NAME) {
            text = getString('speechToolSuccess', 'Speech audio saved to the Media Library.');
        }

        return {
            text: text,
            attachments: attachments,
        };
    }

    function parseToolMessagePayload(content, seen) {
        if (content === null || typeof content === 'undefined') {
            return null;
        }

        if (typeof content === 'string') {
            var trimmed = content.trim();

            if (!trimmed) {
                return null;
            }

            try {
                return JSON.parse(trimmed);
            } catch (error) {
                return null;
            }
        }

        var visited = seen || null;
        var shouldTrack = typeof content === 'object';

        if (shouldTrack && content !== null) {
            if (!visited && typeof WeakSet === 'function') {
                visited = new WeakSet();
            }

            if (visited) {
                if (visited.has(content)) {
                    return null;
                }

                visited.add(content);
            }
        }

        if (Array.isArray(content)) {
            for (var index = 0; index < content.length; index++) {
                var parsedItem = parseToolMessagePayload(content[index], visited);
                if (parsedItem) {
                    return parsedItem;
                }
            }

            return null;
        }

        if (typeof content !== 'object') {
            return null;
        }

        if (
            Object.prototype.hasOwnProperty.call(content, 'url') ||
            Object.prototype.hasOwnProperty.call(content, 'download_url') ||
            Object.prototype.hasOwnProperty.call(content, 'downloadUrl')
        ) {
            return content;
        }

        if (typeof content.text === 'string') {
            return parseToolMessagePayload(content.text, visited);
        }

        if (content.text && typeof content.text.value === 'string') {
            return parseToolMessagePayload(content.text.value, visited);
        }

        if (typeof content.content !== 'undefined') {
            return parseToolMessagePayload(content.content, visited);
        }

        if (typeof content.value === 'string') {
            return parseToolMessagePayload(content.value, visited);
        }

        if (typeof content.result !== 'undefined') {
            return parseToolMessagePayload(content.result, visited);
        }

        var keys = Object.keys(content);
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            if (!Object.prototype.hasOwnProperty.call(content, key)) {
                continue;
            }

            var candidate = content[key];
            if (candidate && typeof candidate === 'object') {
                var parsed = parseToolMessagePayload(candidate, visited);
                if (parsed) {
                    return parsed;
                }
            }
        }

        return null;
    }

    function findToolResultInConversation(state, toolCallId) {
        if (!state || !Array.isArray(state.conversation) || !toolCallId) {
            return null;
        }

        for (var index = state.conversation.length - 1; index >= 0; index--) {
            var entry = state.conversation[index];
            if (!entry || entry.role !== 'tool') {
                continue;
            }

            var entryCallId = '';

            if (typeof entry.tool_call_id === 'string' && entry.tool_call_id) {
                entryCallId = entry.tool_call_id;
            } else if (entry.metadata && typeof entry.metadata.tool_call_id === 'string') {
                entryCallId = entry.metadata.tool_call_id;
            }

            if (entryCallId !== toolCallId) {
                continue;
            }

            var payload = parseToolMessagePayload(entry.content);

            if (!payload && typeof entry.content === 'object' && entry.content !== null) {
                payload = parseToolMessagePayload({ content: entry.content });
            }

            if (!payload && typeof entry.text === 'string') {
                payload = parseToolMessagePayload(entry.text);
            }

            if (!payload) {
                continue;
            }

            var toolName = '';
            if (typeof entry.name === 'string' && entry.name.trim()) {
                toolName = entry.name.trim();
            }

            return {
                result: payload,
                toolName: toolName,
            };
        }

        return null;
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
            var transcribeButton = container.querySelector('.wp-mcp-ai-chat__transcribe');
            var transcribeInput = container.querySelector('.wp-mcp-ai-chat__transcribe-input');
            var toolShortcutsContainer = container.querySelector('.' + TOOL_SHORTCUT_CONTAINER_CLASS);
            var transcriptToggle = container.querySelector('.wp-mcp-ai-chat__transcript-toggle');
            var historyToggle = container.querySelector('.wp-mcp-ai-chat__history-toggle');
            var historyContainer = container.querySelector('.wp-mcp-ai-chat__history');
            var historyStatusEl = container.querySelector('.wp-mcp-ai-chat__history-status');
            var historyList = container.querySelector('.wp-mcp-ai-chat__history-list');

            if (!form || !textarea || !messagesEl || !statusEl) {
                return;
            }

            var instanceConfig = Object.assign({}, config);
            if (!instanceConfig.uploadEndpoint) {
                instanceConfig.uploadEndpoint = globalConfig.uploadEndpoint || '';
            }

            if (!instanceConfig.filesEndpoint) {
                instanceConfig.filesEndpoint = globalConfig.filesEndpoint || '';
            }

            if (!instanceConfig.restNonce) {
                instanceConfig.restNonce = globalConfig.nonce || '';
            }

            if (!instanceConfig.transcriptsEndpoint) {
                instanceConfig.transcriptsEndpoint = globalConfig.transcriptsEndpoint || '';
            }

            if (!instanceConfig.historyPerPage) {
                instanceConfig.historyPerPage = globalConfig.historyPerPage || 20;
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
                transcribeButton: transcribeButton,
                transcribeInput: transcribeInput,
                toolShortcutsContainer: toolShortcutsContainer,
                transcriptToggle: transcriptToggle,
                historyToggle: historyToggle,
                historyContainer: historyContainer,
                historyStatus: historyStatusEl,
                historyList: historyList,
                transcriptExpanded: false,
                historyVisible: false,
                historyLoaded: false,
                historyLoading: false,
                historyLoadPromise: null,
                historySessions: [],
                historySessionDetails: Object.create(null),
                activeHistorySessionKey: '',
                pendingAttachments: [],
                attachmentLibrary: {},
                attachmentBlobUrls: {},
                validationNotice: '',
                speechCache: Object.create(null),
                activeSpeech: null,
                pendingCrawlTasks: Object.create(null),
                transcribing: false,
                isRecording: false,
                recordingStream: null,
                recordedChunks: [],
                mediaRecorder: null,
                recordingShouldProcess: false,
            };

            initialiseExistingSpeechButtons(state);
            renderToolShortcuts(state);

            setTranscriptExpanded(state, false);
            setHistoryVisibility(state, false);

            if (historyToggle) {
                historyToggle.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }

                    toggleHistoryVisibility(state);
                });
            }

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

                if (transcribeButton) {
                    transcribeButton.hidden = true;
                    transcribeButton.disabled = true;
                }

                if (transcribeInput) {
                    transcribeInput.disabled = true;
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

            if (state.canUploadAttachments && transcribeButton) {
                transcribeButton.hidden = false;
                transcribeButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    handleTranscribeButtonClick(state);
                });
            }

            if (state.canUploadAttachments && transcribeInput) {
                transcribeInput.addEventListener('change', function (event) {
                    handleTranscribeFileSelection(event, state);
                });
            }

            updateAttachButtonState(state);
            updateTranscribeButtonState(state);

            // Load and restore conversation from localStorage
            restoreConversationFromStorage(state);
        });
    }

    function restoreConversationFromStorage(state) {
        if (!state) {
            return;
        }

        var saved = loadConversationFromStorage(state);

        if (!saved || !Array.isArray(saved.conversation) || !saved.conversation.length) {
            return;
        }

        // Restore session key if available
        // Only restore if no session key exists to avoid overwriting an active session.
        // This ensures we don't mix conversations from different sessions.
        if (saved.sessionKey && !state.config.sessionKey) {
            state.config.sessionKey = saved.sessionKey;
        }

        // Restore conversation state
        state.conversation = saved.conversation;

        // Render each message in the UI
        saved.conversation.forEach(function (message) {
            if (!message || !message.role) {
                return;
            }

            var role = message.role;
            var content = message.content;

            if (role === 'system') {
                // Skip system messages in UI
                return;
            }

            if (role === 'tool') {
                // Render tool responses
                appendMessage(state.messagesEl, 'tool', content);
                return;
            }

            if (role === 'user') {
                // Render user messages
                var displayPayload = { text: '' };

                if (typeof content === 'string') {
                    displayPayload.text = content;
                } else if (Array.isArray(content)) {
                    // Extract text from structured content and show placeholders for attachments
                    var textParts = [];
                    content.forEach(function (segment) {
                        if (segment && segment.type === 'text' && segment.text) {
                            textParts.push(segment.text);
                        } else if (segment && segment.type === 'input_image') {
                            // Show placeholder for image attachments
                            textParts.push('[Image attachment]');
                        } else if (segment && segment.type === 'input_file') {
                            // Show placeholder for file attachments
                            textParts.push('[File attachment]');
                        }
                    });
                    displayPayload.text = textParts.join('\n');
                }

                appendMessage(state.messagesEl, 'user', displayPayload);
                return;
            }

            if (role === 'assistant') {
                // Render assistant messages
                var assistantPayload = { text: content || '' };
                
                appendMessage(state.messagesEl, 'assistant', assistantPayload, true, {
                    speech: {
                        state: state,
                        text: content || '',
                    },
                });
                return;
            }
        });

        // Scroll to bottom after restoration
        if (state.messagesEl) {
            state.messagesEl.scrollTop = state.messagesEl.scrollHeight;
        }
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

        // Save conversation immediately after user message
        saveConversationToStorage(state);

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
            save_transcript: state.config.saveTranscript !== false,
        };

        if (state.config.sessionKey) {
            payload.session_key = state.config.sessionKey;
        }

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
                saveConversationToStorage(state);
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

        if (!hasDisplayContent) {
            var fallbackText = '';

            function normaliseCandidate(candidate) {
                if (candidate === null || typeof candidate === 'undefined') {
                    return '';
                }

                if (typeof candidate === 'string') {
                    return candidate;
                }

                if (Array.isArray(candidate)) {
                    return normaliseContent(candidate);
                }

                if (typeof candidate === 'object') {
                    if (typeof candidate.text !== 'undefined') {
                        return normaliseCandidate(candidate.text);
                    }

                    if (typeof candidate.content !== 'undefined') {
                        return normaliseCandidate(candidate.content);
                    }

                    if (typeof candidate.value !== 'undefined') {
                        return normaliseCandidate(candidate.value);
                    }

                    return normaliseContent(candidate);
                }

                return '';
            }

            if (chatData && typeof chatData.output_text !== 'undefined' && chatData.output_text !== null) {
                fallbackText = normaliseCandidate(chatData.output_text).trim();
            }

            if (!fallbackText && chatData && Array.isArray(chatData.output)) {
                fallbackText = chatData.output
                    .map(function (item) {
                        return normaliseCandidate(item).trim();
                    })
                    .filter(function (value) {
                        return value;
                    })
                    .join('\n\n')
                    .trim();
            }

            if (!fallbackText && chatData && chatData.response && typeof chatData.response === 'object') {
                var nestedResponse = chatData.response;

                if (typeof nestedResponse.output_text !== 'undefined' && nestedResponse.output_text !== null) {
                    fallbackText = normaliseCandidate(nestedResponse.output_text).trim();
                }

                if (!fallbackText && Array.isArray(nestedResponse.output)) {
                    fallbackText = nestedResponse.output
                        .map(function (item) {
                            return normaliseCandidate(item).trim();
                        })
                        .filter(function (value) {
                            return value;
                        })
                        .join('\n\n')
                        .trim();
                }
            }

            if (fallbackText) {
                assistantDisplay.text = fallbackText;
                hasDisplayText = true;
                hasDisplayContent = true;
            }
        }

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
                if (window.console && console.log) {
                    var logSafeResult = result;
                    
                    if (result && typeof result === 'object') {
                        logSafeResult = {
                            attachment_id: result.attachment_id,
                            url: result.url,
                            file_name: result.file_name,
                            mime_type: result.mime_type,
                            bytes: result.bytes,
                            hasContent: !!result.content,
                        };
                    }
                    
                    console.log('[WP MCP AI] Tool response received:', {
                        tool: toolName,
                        result: logSafeResult,
                        timestamp: new Date().toISOString(),
                    });
                }

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

                            if (window.console && console.log) {
                                var logSafeNormalised = {
                                    text: normalised.text,
                                    attachmentCount: normalised.attachments ? normalised.attachments.length : 0,
                                };
                                
                                if (normalised.attachments && normalised.attachments.length > 0) {
                                    logSafeNormalised.firstAttachment = {
                                        label: normalised.attachments[0].label,
                                        meta: normalised.attachments[0].meta,
                                        hasUrl: !!normalised.attachments[0].url,
                                    };
                                }
                                
                                console.log('[WP MCP AI] Tool payload normalized for display:', {
                                    tool: toolName,
                                    normalizedPayload: logSafeNormalised,
                                });
                            }
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

                if (toolName) {
                    toolMessage.name = toolName;
                }

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
        var showJsonResponse =
            hasText &&
            !hasAttachments &&
            shouldDisplayJsonResponse(role, text, allowMarkdown);

        if (!hasText && !hasAttachments) {
            return null;
        }

        var entry = document.createElement('div');
        entry.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__message--' + role;

        var bubble = document.createElement('div');
        bubble.className = 'wp-mcp-ai-chat__bubble';

        if (showJsonResponse) {
            bubble.classList.add('wp-mcp-ai-chat__bubble--json');
            bubble.appendChild(createJsonResponseElement(text));
        } else if (hasText) {
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

    function shouldDisplayJsonResponse(role, text, allowMarkdown) {
        if (allowMarkdown) {
            return false;
        }

        if (role !== 'tool') {
            return false;
        }

        return isLikelyJson(text);
    }

    function isLikelyJson(text) {
        if (!text || typeof text !== 'string') {
            return false;
        }

        var trimmed = text.trim();
        if (!trimmed) {
            return false;
        }

        var firstChar = trimmed.charAt(0);
        if (firstChar !== '{' && firstChar !== '[') {
            return false;
        }

        try {
            JSON.parse(trimmed);
            return true;
        } catch (error) {
            return false;
        }
    }

    function createJsonResponseElement(text) {
        var details = document.createElement('details');
        details.className = 'wp-mcp-ai-chat__json-response';

        var summary = document.createElement('summary');
        summary.className = 'wp-mcp-ai-chat__json-summary';

        var icon = document.createElement('span');
        icon.className = 'wp-mcp-ai-chat__json-icon';
        icon.innerHTML =
            '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
            '<path d="M12 8.5a1 1 0 0 1 .7.29l5 5a1 1 0 0 1-1.4 1.42L12 10.91l-4.3 4.3a1 1 0 1 1-1.4-1.42l5-5a1 1 0 0 1 .7-.29z" />' +
            '</svg>';
        summary.appendChild(icon);

        var label = document.createElement('span');
        label.className = 'wp-mcp-ai-chat__json-label';
        label.textContent = getString('jsonResponse', 'JSON response');
        summary.appendChild(label);

        details.appendChild(summary);

        var pre = document.createElement('pre');
        pre.className = 'wp-mcp-ai-chat__json-content';
        pre.textContent = text;
        details.appendChild(pre);

        return details;
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

    function extractNestedText(value, depth) {
        if (depth > 5) {
            return [];
        }

        if (typeof value === 'string' || typeof value === 'number') {
            var normalised = String(value).trim();
            return normalised ? [normalised] : [];
        }

        if (!value) {
            return [];
        }

        if (Array.isArray(value)) {
            return value.reduce(function (parts, item) {
                return parts.concat(extractNestedText(item, depth + 1));
            }, []);
        }

        if (typeof value === 'object') {
            var segments = [];

            if (typeof value.text === 'string') {
                segments.push(value.text);
            } else if (value.text && typeof value.text.value === 'string') {
                segments.push(value.text.value);
            }

            if (typeof value.value === 'string') {
                segments.push(value.value);
            }

            if (typeof value.message === 'string') {
                segments.push(value.message);
            }

            if (typeof value.reason === 'string') {
                segments.push(value.reason);
            }

            if (typeof value.explanation === 'string') {
                segments.push(value.explanation);
            }

            if (typeof value.summary === 'string') {
                segments.push(value.summary);
            }

            if (typeof value.content === 'string') {
                segments.push(value.content);
            }

            var nestedKeys = ['summary', 'reasoning', 'content', 'steps', 'output', 'parts', 'messages'];
            nestedKeys.forEach(function (key) {
                if (value[key] && value[key] !== value) {
                    segments = segments.concat(extractNestedText(value[key], depth + 1));
                }
            });

            return segments;
        }

        return [];
    }

    function dedupeTextParts(parts) {
        var seen = Object.create(null);

        return parts
            .map(function (part) {
                return typeof part === 'string' ? part.trim() : '';
            })
            .filter(function (part) {
                if (!part) {
                    return false;
                }

                if (seen[part]) {
                    return false;
                }

                seen[part] = true;
                return true;
            });
    }

    function renderReasoningSegment(piece) {
        if (!piece || typeof piece !== 'object') {
            return '';
        }

        var fragments = [];

        fragments = fragments.concat(extractNestedText(piece.summary, 0));
        fragments = fragments.concat(extractNestedText(piece.reasoning, 0));
        fragments = fragments.concat(extractNestedText(piece.text, 0));
        fragments = fragments.concat(extractNestedText(piece.output, 0));
        fragments = fragments.concat(extractNestedText(piece.content, 0));

        var unique = dedupeTextParts(fragments);

        if (!unique.length) {
            return '';
        }

        var heading = getString('reasoningLabel', 'Reasoning');
        return heading + ':\n\n' + unique.join('\n\n');
    }

    function renderFunctionCallSegment(piece) {
        if (!piece || typeof piece !== 'object') {
            return '';
        }

        var parts = [];
        var name = typeof piece.name === 'string' ? piece.name.trim() : '';
        var status = typeof piece.status === 'string' ? piece.status.trim() : '';
        var callId = typeof piece.call_id === 'string' ? piece.call_id.trim() : '';
        var identifier = typeof piece.id === 'string' ? piece.id.trim() : '';

        if (name) {
            parts.push(formatString(getString('functionCallTitle', 'Function call: %s'), name));
        } else {
            parts.push(getString('functionCallFallback', 'Function call'));
        }

        if (status) {
            parts.push(formatString(getString('functionCallStatus', 'Status: %s'), status));
        }

        if (callId) {
            parts.push(formatString(getString('functionCallId', 'Call ID: %s'), callId));
        } else if (identifier) {
            parts.push(formatString(getString('functionCallId', 'Call ID: %s'), identifier));
        }

        var rawArguments = typeof piece.arguments !== 'undefined' ? piece.arguments : null;
        var argumentText = '';
        var parsedArguments = null;

        if (typeof rawArguments === 'string') {
            var trimmed = rawArguments.trim();

            if (trimmed) {
                try {
                    parsedArguments = JSON.parse(trimmed);
                } catch (error) {
                    parsedArguments = null;
                }

                argumentText = parsedArguments ? JSON.stringify(parsedArguments, null, 2) : trimmed;
            }
        } else if (rawArguments && typeof rawArguments === 'object') {
            try {
                argumentText = JSON.stringify(rawArguments, null, 2);
                parsedArguments = rawArguments;
            } catch (error) {
                argumentText = String(rawArguments);
            }
        }

        if (argumentText) {
            var argumentsLabel = getString('functionCallArgumentsLabel', 'Arguments:');

            if (parsedArguments) {
                parts.push(argumentsLabel + '\n```json\n' + argumentText + '\n```');
            } else {
                parts.push(argumentsLabel + ' ' + argumentText);
            }
        }

        return parts.join('\n\n');
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

        if (type === 'reasoning') {
            return renderReasoningSegment(piece);
        }

        if (type === 'function_call') {
            return renderFunctionCallSegment(piece);
        }

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
