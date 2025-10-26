(function () {
    'use strict';

    var globalConfig = window.wpMcpAiChat || {};
    var instances = window.wpMcpAiChatInstances || {};
    var objectUrlRegistry = [];

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

    if (typeof window !== 'undefined' && window.addEventListener) {
        window.addEventListener('beforeunload', revokeObjectUrls);
    }

    function getString(key, fallback) {
        if (globalConfig.strings && Object.prototype.hasOwnProperty.call(globalConfig.strings, key)) {
            return globalConfig.strings[key];
        }
        return fallback;
    }

    function handleFileSelection(event, state) {
        if (!event || !event.target || !event.target.files) {
            return;
        }

        var files = Array.prototype.slice.call(event.target.files);
        event.target.value = '';

        if (!files.length) {
            return;
        }

        var sequence = Promise.resolve();

        files.forEach(function (file) {
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
        if (!file || !state || !state.config || !state.config.uploadEndpoint) {
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

                if (!state.busy && state.uploading === 0 && !hadError) {
                    setStatus(state.container, '');
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
        var seen = {};

        function addAttachment(record, fallbackLabel) {
            if (!record) {
                return;
            }

            var url = getAttachmentUrlFromRecord(record, state);
            if (!url) {
                return;
            }

            var key = record.fileId || url;
            if (seen[key]) {
                return;
            }
            seen[key] = true;

            var label = fallbackLabel || record.name || record.downloadName || '';
            if (!label) {
                label = getString('downloadAttachment', 'Download attachment');
            }

            attachments.push({
                url: url,
                label: label,
                downloadName: record.downloadName || record.originalName || record.name || '',
                meta: buildAttachmentMeta(record),
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

            if (type === 'tool_result' && segment.content) {
                processSegment(segment.content);
            }

            if (segment.attachments && Array.isArray(segment.attachments)) {
                segment.attachments.forEach(processSegment);
            }

            if (type === 'input_file' || type === 'output_file' || type === 'file_path' || type === 'file') {
                var fileId = segment.file_id || (segment.file && segment.file.id) || segment.id || '';
                if (fileId) {
                    var record = lookup[fileId];
                    if (record) {
                        addAttachment(record, segment.display_name || (segment.file && segment.file.filename));
                    }
                }
                return;
            }

            if (type === 'input_image' || type === 'image_file' || type === 'output_image') {
                var imageFile = segment.image_file || segment.image || segment.file || null;
                if (imageFile) {
                    var imageId = imageFile.file_id || imageFile.id || '';
                    if (imageId && lookup[imageId]) {
                        addAttachment(lookup[imageId], segment.caption || imageFile.display_name || imageFile.filename);
                        return;
                    }
                }

                var url = (segment.image_url && segment.image_url.url) || segment.url || '';
                if (url) {
                    attachments.push({
                        url: url,
                        label: segment.caption || getString('downloadAttachment', 'Download attachment'),
                        downloadName: '',
                        meta: '',
                    });
                }
                return;
            }

            if (segment.content) {
                processSegment(segment.content);
            }
        }

        processSegment(message.content);

        return attachments;
    }

    function buildAttachmentLookup(message, state) {
        var lookup = {};

        if (message && Array.isArray(message.attachments)) {
            message.attachments.forEach(function (item) {
                var record = normaliseAttachmentRecord(item);
                if (record) {
                    lookup[record.fileId] = record;

                    if (state && state.attachmentLibrary) {
                        var existing = state.attachmentLibrary[record.fileId];
                        if (!existing || (!existing.url && record.url) || (!existing.data && record.data)) {
                            state.attachmentLibrary[record.fileId] = record;
                        }
                    }
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

        var fileId = raw.file_id || raw.id || raw.fileId || '';
        if (!fileId && raw.image_file && raw.image_file.file_id) {
            fileId = raw.image_file.file_id;
        }

        if (!fileId) {
            return null;
        }

        var name = raw.display_name || raw.filename || raw.name || '';
        if (!name && raw.title && typeof raw.title.rendered === 'string') {
            name = raw.title.rendered;
        }

        var url = raw.url || raw.download_url || raw.href || raw.source_url || '';
        if (!url && raw.image_url && raw.image_url.url) {
            url = raw.image_url.url;
        }

        var mime = raw.mime_type || raw.type || '';

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

        return {
            fileId: String(fileId),
            name: name,
            url: url,
            mime: mime,
            size: size,
            data: data,
            downloadName: raw.filename || raw.name || '',
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

            if (!form || !textarea || !messagesEl || !statusEl) {
                return;
            }

            var instanceConfig = Object.assign({}, config);
            if (!instanceConfig.uploadEndpoint) {
                instanceConfig.uploadEndpoint = globalConfig.uploadEndpoint || '';
            }

            var state = {
                conversation: [],
                busy: false,
                uploading: 0,
                config: instanceConfig,
                container: container,
                textarea: textarea,
                messagesEl: messagesEl,
                statusEl: statusEl,
                attachmentsContainer: attachmentsContainer,
                attachmentsList: attachmentsList,
                attachmentsHeader: attachmentsHeader,
                attachButton: attachButton,
                fileInput: fileInput,
                pendingAttachments: [],
                attachmentLibrary: {},
                attachmentBlobUrls: {},
            };

            textarea.setAttribute('placeholder', getString('placeholder', textarea.getAttribute('placeholder')));
            form.addEventListener('submit', function (event) {
                handleSubmit(event, state);
            });

            if (attachmentsHeader) {
                attachmentsHeader.textContent = getString('attachmentsLabel', attachmentsHeader.textContent);
            }

            if (attachButton) {
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

            if (fileInput) {
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

        var message = state.textarea.value.trim();
        var pending = state.pendingAttachments.slice();
        var hasAttachments = pending.length > 0;

        if (!message && !hasAttachments) {
            setStatus(state.container, getString('emptyMessage', 'Enter a message before sending.'));
            return;
        }

        state.textarea.value = '';

        var segments = [];
        if (message) {
            segments.push({
                type: 'input_text',
                text: message,
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

        if (message || displayAttachments.length) {
            appendMessage(state.messagesEl, 'user', { text: message, attachments: displayAttachments });
        }

        var payloadContent;
        if (segments.length === 1 && segments[0].type === 'input_text') {
            payloadContent = segments[0].text;
        } else {
            payloadContent = segments;
        }

        state.conversation.push({ role: 'user', content: payloadContent });

        state.pendingAttachments = [];
        renderPendingAttachments(state);
        updateAttachButtonState(state);

        sendChat(state);
    }

    function sendChat(state) {
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
        var assistantDisplay = prepareAssistantDisplay(message, state);

        if (assistantDisplay.text || assistantDisplay.attachments.length) {
            appendMessage(state.messagesEl, 'assistant', assistantDisplay, true);
            assistantMessage.content = assistantDisplay.text || '';
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

    function appendMessage(listEl, role, payload, allowMarkdown) {
        if (typeof payload === 'undefined' || payload === null) {
            return;
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
            return;
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
