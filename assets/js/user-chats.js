(function () {
    'use strict';

    const globalConfig = window.wpMcpAiUserChats || {};
    const REST_PATH = '/chat-transcripts';

    function mergeStrings(instanceStrings) {
        const merged = {};
        const globalStrings = globalConfig.strings || {};
        const localStrings = instanceStrings || {};
        let key;

        for (key in globalStrings) {
            if (!Object.prototype.hasOwnProperty.call(globalStrings, key) || 'roleLabels' === key) {
                continue;
            }

            merged[key] = globalStrings[key];
        }

        for (key in localStrings) {
            if (!Object.prototype.hasOwnProperty.call(localStrings, key) || 'roleLabels' === key) {
                continue;
            }

            merged[key] = localStrings[key];
        }

        const roles = {};
        const globalRoles = globalStrings.roleLabels || {};
        const localRoles = localStrings.roleLabels || {};

        for (key in globalRoles) {
            if (Object.prototype.hasOwnProperty.call(globalRoles, key)) {
                roles[key] = globalRoles[key];
            }
        }

        for (key in localRoles) {
            if (Object.prototype.hasOwnProperty.call(localRoles, key)) {
                roles[key] = localRoles[key];
            }
        }

        merged.roleLabels = roles;

        return merged;
    }

    function parseConfig(container) {
        const raw = container.getAttribute('data-wp-mcp-ai-user-chats');

        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    }

    function normalizeRestUrl() {
        const url = typeof globalConfig.restUrl === 'string' ? globalConfig.restUrl : '';

        if (!url) {
            return '';
        }

        return url.replace(/\/$/, '');
    }

    function buildRestUrl(params) {
        const base = normalizeRestUrl();

        if (!base) {
            return '';
        }

        let url = base + REST_PATH;

        if (!params) {
            return url;
        }

        if (typeof window.URLSearchParams === 'function') {
            const search = new window.URLSearchParams();
            for (const key in params) {
                if (!Object.prototype.hasOwnProperty.call(params, key)) {
                    continue;
                }

                const value = params[key];
                if (value === null || value === undefined || '' === value) {
                    continue;
                }

                search.append(key, value);
            }

            const query = search.toString();
            if (query) {
                url += '?' + query;
            }
        } else {
            const parts = [];

            for (const keyAlt in params) {
                if (!Object.prototype.hasOwnProperty.call(params, keyAlt)) {
                    continue;
                }

                const val = params[keyAlt];
                if (val === null || val === undefined || '' === val) {
                    continue;
                }

                parts.push(encodeURIComponent(keyAlt) + '=' + encodeURIComponent(val));
            }

            if (parts.length) {
                url += '?' + parts.join('&');
            }
        }

        return url;
    }

    function buildHeaders() {
        const headers = {
            'Accept': 'application/json'
        };

        if (globalConfig.nonce) {
            headers['X-WP-Nonce'] = globalConfig.nonce;
        }

        return headers;
    }

    function setStatus(state, message) {
        if (!state.statusEl) {
            return;
        }

        state.statusEl.textContent = message || '';
    }

    function formatDate(value) {
        if (!value) {
            return '';
        }

        const date = new Date(value);

        if (isNaN(date.getTime())) {
            return '';
        }

        if (typeof window.Intl !== 'undefined' && window.Intl.DateTimeFormat) {
            try {
                return new window.Intl.DateTimeFormat(undefined, {
                    dateStyle: 'medium',
                    timeStyle: 'short'
                }).format(date);
            } catch (error) {}
        }

        if (date.toLocaleString) {
            return date.toLocaleString();
        }

        return date.toISOString();
    }

    function getString(state, key, fallback) {
        const value = state.strings && state.strings[key];

        if (typeof value === 'string' && value) {
            return value;
        }

        return fallback || '';
    }

    function formatSessionLabel(state, session, index) {
        if (session && session.assistant_title) {
            return session.assistant_title;
        }

        const template = getString(state, 'sessionLabel', 'Chat session %s');
        const placeholder = template.indexOf('%s') !== -1 ? template : template + ' %s';
        const number = index >= 0 ? index + 1 : (session && session.session_key ? session.session_key : '1');

        return placeholder.replace('%s', number);
    }

    function formatTurnCount(state, count) {
        const template = getString(state, 'turnCountLabel', '%d messages');
        const value = parseInt(count, 10);

        if (isNaN(value)) {
            return '';
        }

        if (template.indexOf('%d') !== -1) {
            return template.replace('%d', value);
        }

        return template + ' ' + value;
    }

    function normalizeRole(role) {
        const value = (role || '').toString().toLowerCase();

        if ('assistant' === value || 'user' === value || 'system' === value) {
            return value;
        }

        if ('tool' === value || 'function' === value || 'tool_result' === value || 'observation' === value) {
            return 'tool';
        }

        return 'assistant';
    }

    function setActiveButton(state, sessionKey) {
        if (!state.sessionButtons) {
            return;
        }

        for (const key in state.sessionButtons) {
            if (!Object.prototype.hasOwnProperty.call(state.sessionButtons, key)) {
                continue;
            }

            const button = state.sessionButtons[key];

            if (!button) {
                continue;
            }

            if (key === sessionKey) {
                button.classList.add('is-active');
            } else {
                button.classList.remove('is-active');
            }
        }
    }

    function clearElement(element) {
        while (element && element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function renderSessionList(state) {
        if (!state.listEl) {
            return;
        }

        clearElement(state.listEl);
        state.sessionButtons = {};

        for (let index = 0; index < state.sessions.length; index++) {
            const session = state.sessions[index];
            const listItem = document.createElement('li');
            const button = document.createElement('button');
            const title = document.createElement('span');
            const preview = document.createElement('span');
            const meta = document.createElement('div');
            const updatedLabel = document.createElement('span');
            const startedLabel = document.createElement('span');
            const turnsLabel = document.createElement('span');

            const buttonKey = session.session_key || ('session-' + index);

            listItem.className = 'wp-mcp-ai-user-chats__session';
            button.type = 'button';
            button.className = 'wp-mcp-ai-user-chats__session-button';
            button.dataset.sessionKey = buttonKey;

            title.className = 'wp-mcp-ai-user-chats__session-title';
            title.textContent = formatSessionLabel(state, session, index);
            button.appendChild(title);

            if (session.preview) {
                preview.className = 'wp-mcp-ai-user-chats__preview';
                preview.textContent = session.preview;
                button.appendChild(preview);
            }

            meta.className = 'wp-mcp-ai-user-chats__meta';

            const formattedUpdated = formatDate(session.updated_at || session.completed_at);
            if (formattedUpdated) {
                updatedLabel.className = 'wp-mcp-ai-user-chats__timestamp';
                updatedLabel.textContent = getString(state, 'updatedLabel', 'Last activity') + ': ' + formattedUpdated;
                meta.appendChild(updatedLabel);
            }

            const formattedStarted = formatDate(session.started_at || session.first_created);
            if (formattedStarted) {
                startedLabel.className = 'wp-mcp-ai-user-chats__timestamp';
                startedLabel.textContent = getString(state, 'startedLabel', 'Started') + ': ' + formattedStarted;
                meta.appendChild(startedLabel);
            }

            if (session.turn_count) {
                turnsLabel.className = 'wp-mcp-ai-user-chats__assistant';
                turnsLabel.textContent = formatTurnCount(state, session.turn_count);
                meta.appendChild(turnsLabel);
            }

            if (session.assistant_model) {
                const modelLabel = document.createElement('span');
                modelLabel.className = 'wp-mcp-ai-user-chats__assistant';
                modelLabel.textContent = session.assistant_model;
                meta.appendChild(modelLabel);
            }

            if (meta.childNodes.length) {
                button.appendChild(meta);
            }

            (function attachListener(instanceState, sessionData, buttonEl, sessionKeyValue) {
                buttonEl.addEventListener('click', function () {
                    if (instanceState.loadingSession) {
                        return;
                    }

                    setActiveButton(instanceState, sessionKeyValue);
                    loadSessionTranscript(instanceState, sessionKeyValue);
                });
            })(state, session, button, buttonKey);

            if (state.activeSessionKey && state.activeSessionKey === session.session_key) {
                button.classList.add('is-active');
            }

            state.sessionButtons[buttonKey] = button;
            listItem.appendChild(button);
            
            // Add "Load into chat" button if target chat widget is available
            if (state.config.targetChatWidget) {
                const loadButton = document.createElement('button');
                loadButton.type = 'button';
                loadButton.className = 'wp-mcp-ai-user-chats__load-button';
                loadButton.textContent = getString(state, 'loadIntoChat', 'Load into chat');
                loadButton.setAttribute('aria-label', getString(state, 'loadIntoChatLabel', 'Load this conversation into the chat window'));
                
                (function attachLoadListener(instanceState, sessionData, loadButtonEl) {
                    loadButtonEl.addEventListener('click', function (event) {
                        event.stopPropagation();
                        loadSessionIntoTargetChat(instanceState, sessionData);
                    });
                })(state, session, loadButton);
                
                listItem.appendChild(loadButton);
            }
            
            state.listEl.appendChild(listItem);
        }
    }

    function renderConversation(state, session) {
        if (!state.conversationWrapper || !state.messagesEl) {
            return;
        }

        clearElement(state.messagesEl);

        let title = formatSessionLabel(state, session, -1);
        if (session.assistant_title) {
            title = session.assistant_title;
        }

        if (state.conversationTitleEl) {
            state.conversationTitleEl.textContent = title;
        }

        if (state.conversationMetaEl) {
            const metaParts = [];
            if (session.assistant_title) {
                metaParts.push(getString(state, 'assistantLabel', 'Assistant') + ': ' + session.assistant_title);
            }

            if (session.assistant_model) {
                metaParts.push(session.assistant_model);
            }

            const startedAt = formatDate(session.started_at);
            if (startedAt) {
                metaParts.push(getString(state, 'startedLabel', 'Started') + ': ' + startedAt);
            }

            const updatedAt = formatDate(session.updated_at);
            if (updatedAt) {
                metaParts.push(getString(state, 'updatedLabel', 'Last activity') + ': ' + updatedAt);
            }

            if (session.turn_count) {
                const formattedCount = formatTurnCount(state, session.turn_count);
                if (formattedCount) {
                    metaParts.push(formattedCount);
                }
            }

            state.conversationMetaEl.textContent = metaParts.join(' • ');
        }

        if (!session.messages || !session.messages.length) {
            const emptyMessage = document.createElement('li');
            emptyMessage.className = 'wp-mcp-ai-user-chats__message';

            const emptyContent = document.createElement('div');
            emptyContent.className = 'wp-mcp-ai-user-chats__message-content';
            emptyContent.textContent = getString(state, 'emptySession', 'No messages are stored for this chat yet.');

            emptyMessage.appendChild(emptyContent);
            state.messagesEl.appendChild(emptyMessage);
        } else {
            for (let index = 0; index < session.messages.length; index++) {
                const message = session.messages[index];
                const normalizedRole = normalizeRole(message.role);
                const messageItem = document.createElement('li');
                const roleLabel = document.createElement('span');
                const content = document.createElement('div');

                messageItem.className = 'wp-mcp-ai-user-chats__message wp-mcp-ai-user-chats__message--' + normalizedRole;

                roleLabel.className = 'wp-mcp-ai-user-chats__message-role';
                let friendlyRole = state.roleLabels && state.roleLabels[normalizedRole];
                if (!friendlyRole && message.role) {
                    friendlyRole = message.role.charAt(0).toUpperCase() + message.role.slice(1);
                }
                roleLabel.textContent = friendlyRole || normalizedRole;
                messageItem.appendChild(roleLabel);

                content.className = 'wp-mcp-ai-user-chats__message-content';
                content.textContent = message.content || '';
                messageItem.appendChild(content);

                if (message.timestamp) {
                    const meta = document.createElement('div');
                    meta.className = 'wp-mcp-ai-user-chats__message-meta';
                    meta.textContent = formatDate(message.timestamp);
                    messageItem.appendChild(meta);
                }

                state.messagesEl.appendChild(messageItem);
            }
        }

        state.listWrapper.hidden = true;
        state.conversationWrapper.hidden = false;
        setStatus(state, '');
    }

    function loadSessionIntoTargetChat(state, session) {
        if (!session || !state.config.targetChatWidget) {
            return;
        }

        // Check if the global API is available
        if (typeof window.wpMcpAiLoadSession !== 'function') {
            setStatus(state, getString(state, 'errorLoadingIntoChat', 'Unable to load into chat. Please refresh the page.'));
            return;
        }

        const sessionKey = session.session_key || '';
        
        // If we already have the full session details with messages, use them directly
        if (session.messages && Array.isArray(session.messages) && session.messages.length > 0) {
            const success = window.wpMcpAiLoadSession({
                sessionKey: sessionKey,
                assistantId: session.assistant_id,
                messages: session.messages,
                target: state.config.targetChatWidget
            });

            if (success) {
                setStatus(state, getString(state, 'loadedIntoChat', 'Conversation loaded into chat.'));
            } else {
                setStatus(state, getString(state, 'errorLoadingIntoChat', 'Unable to load into chat. Target widget not found.'));
            }
            return;
        }

        // Otherwise, fetch the full session details first
        const params = {
            session_key: sessionKey,
            user_id: state.config.userId
        };

        if (state.config.assistantId > 0) {
            params.assistant_id = state.config.assistantId;
        }

        const url = buildRestUrl(params);

        if (!url) {
            setStatus(state, getString(state, 'errorLoadingIntoChat', 'Unable to load into chat.'));
            return;
        }

        setStatus(state, getString(state, 'loadingIntoChat', 'Loading into chat…'));

        fetch(url, {
            credentials: 'same-origin',
            headers: buildHeaders()
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (body) {
                    if (!response.ok) {
                        throw new Error(body && body.message ? body.message : 'Load failed');
                    }
                    return body;
                });
            })
            .then(function (data) {
                if (!data || !data.session || !data.session.messages) {
                    throw new Error('Invalid session data');
                }

                const success = window.wpMcpAiLoadSession({
                    sessionKey: sessionKey,
                    assistantId: data.session.assistant_id,
                    messages: data.session.messages,
                    target: state.config.targetChatWidget
                });

                if (success) {
                    setStatus(state, getString(state, 'loadedIntoChat', 'Conversation loaded into chat.'));
                } else {
                    setStatus(state, getString(state, 'errorLoadingIntoChat', 'Unable to load into chat. Target widget not found.'));
                }
            })
            .catch(function (error) {
                const message = error && error.message ? error.message : getString(state, 'errorLoadingIntoChat', 'Unable to load into chat.');
                setStatus(state, message);
            });
    }

    function showSessionList(state) {
        state.activeSessionKey = null;
        setActiveButton(state, null);

        if (state.conversationWrapper) {
            state.conversationWrapper.hidden = true;
        }

        if (state.listWrapper) {
            state.listWrapper.hidden = state.sessions.length === 0;
        }

        if (state.sessions.length === 0) {
            setStatus(state, getString(state, 'emptyList', 'No chat transcripts are stored for this user yet.'));
        } else {
            setStatus(state, getString(state, 'selectPrompt', 'Select a chat session to review the conversation.'));
        }
    }

    function loadSessionTranscript(state, sessionKey) {
        if (!sessionKey) {
            return;
        }

        if (!window.fetch) {
            setStatus(state, getString(state, 'errorLoadingSession', 'Unable to load the selected chat.'));
            return;
        }

        const params = {
            session_key: sessionKey,
            user_id: state.config.userId
        };

        if (state.config.assistantId > 0) {
            params.assistant_id = state.config.assistantId;
        }

        const url = buildRestUrl(params);

        if (!url) {
            setStatus(state, getString(state, 'errorLoadingSession', 'Unable to load the selected chat.'));
            return;
        }

        state.loadingSession = true;
        setStatus(state, getString(state, 'loadingConversation', 'Loading chat…'));

        fetch(url, {
            credentials: 'same-origin',
            headers: buildHeaders()
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (body) {
                    if (!response.ok) {
                        const errorMessage = body && body.message ? body.message : getString(state, 'errorLoadingSession', 'Unable to load the selected chat.');
                        throw new Error(errorMessage);
                    }

                    return body;
                });
            })
            .then(function (data) {
                state.loadingSession = false;

                if (!data || !data.session) {
                    const sessionError = data && data.message ? data.message : getString(state, 'errorLoadingSession', 'Unable to load the selected chat.');
                    setStatus(state, sessionError);
                    return;
                }

                state.activeSessionKey = data.session.session_key || sessionKey;
                setActiveButton(state, state.activeSessionKey);
                renderConversation(state, data.session);
            })
            .catch(function (error) {
                state.loadingSession = false;
                setStatus(state, (error && error.message) ? error.message : getString(state, 'errorLoadingSession', 'Unable to load the selected chat.'));
            });
    }

    function loadSessions(state) {
        if (!window.fetch) {
            setStatus(state, getString(state, 'errorLoadingList', 'Unable to load chats right now.'));
            return;
        }

        const params = {
            user_id: state.config.userId
        };

        if (state.config.assistantId > 0) {
            params.assistant_id = state.config.assistantId;
        }

        if (state.config.maxSessions > 0) {
            params.per_page = state.config.maxSessions;
        }

        const url = buildRestUrl(params);

        if (!url) {
            setStatus(state, getString(state, 'errorLoadingList', 'Unable to load chats right now.'));
            return;
        }

        state.loadingList = true;
        setStatus(state, getString(state, 'loadingList', 'Loading chats…'));

        fetch(url, {
            credentials: 'same-origin',
            headers: buildHeaders()
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (body) {
                    if (!response.ok) {
                        const errorMessage = body && body.message ? body.message : getString(state, 'errorLoadingList', 'Unable to load chats right now.');
                        throw new Error(errorMessage);
                    }

                    return body;
                });
            })
            .then(function (data) {
                state.loadingList = false;

                state.sessions = Array.isArray(data.sessions) ? data.sessions : [];
                state.totalSessions = typeof data.total === 'number' ? data.total : state.sessions.length;
                const responseMessage = data && data.message ? data.message : '';

                if (!state.sessions.length) {
                    if (state.listWrapper) {
                        state.listWrapper.hidden = true;
                    }

                    if (state.conversationWrapper) {
                        state.conversationWrapper.hidden = true;
                    }

                    setStatus(state, responseMessage || getString(state, 'emptyList', 'No chat transcripts are stored for this user yet.'));
                    return;
                }

                if (state.listWrapper) {
                    state.listWrapper.hidden = false;
                }

                if (state.conversationWrapper) {
                    state.conversationWrapper.hidden = true;
                }

                renderSessionList(state);
                setStatus(state, responseMessage || getString(state, 'selectPrompt', 'Select a chat session to review the conversation.'));
            })
            .catch(function (error) {
                state.loadingList = false;
                setStatus(state, (error && error.message) ? error.message : getString(state, 'errorLoadingList', 'Unable to load chats right now.'));
            });
    }

    function initContainer(container) {
        if (!container || container.__wpMcpAiUserChats) {
            return;
        }

        const config = parseConfig(container) || {};
        let userId = parseInt(config.userId, 10);

        if (isNaN(userId)) {
            userId = 0;
        }

        let assistantId = parseInt(config.assistantId, 10);
        if (isNaN(assistantId)) {
            assistantId = 0;
        }

        let maxSessions = parseInt(config.maxSessions, 10);
        if (isNaN(maxSessions)) {
            maxSessions = 0;
        }
        
        // Get target chat widget configuration
        let targetChatWidget = config.targetChatWidget || null;
        
        // Auto-detect: find first chat widget if no target specified
        if (!targetChatWidget) {
            const chatWidgets = document.querySelectorAll('[data-wp-mcp-ai-chat]');
            if (chatWidgets.length === 1) {
                // If there's only one chat widget, use it as the target
                targetChatWidget = chatWidgets[0];
            } else if (chatWidgets.length > 1) {
                // If there are multiple, try to find the closest one
                let closestWidget = null;
                let closestDistance = Infinity;
                
                for (let i = 0; i < chatWidgets.length; i++) {
                    const widget = chatWidgets[i];
                    // Calculate rough "distance" by comparing positions
                    const containerRect = container.getBoundingClientRect();
                    const widgetRect = widget.getBoundingClientRect();
                    const distance = Math.abs(containerRect.top - widgetRect.top) + 
                                   Math.abs(containerRect.left - widgetRect.left);
                    
                    if (distance < closestDistance) {
                        closestDistance = distance;
                        closestWidget = widget;
                    }
                }
                
                targetChatWidget = closestWidget;
            }
        }

        const strings = mergeStrings(config.strings);
        const state = {
            container: container,
            config: {
                userId: userId,
                assistantId: assistantId,
                maxSessions: maxSessions,
                targetChatWidget: targetChatWidget
            },
            strings: strings,
            roleLabels: strings.roleLabels || {},
            statusEl: container.querySelector('.wp-mcp-ai-user-chats__status'),
            listWrapper: container.querySelector('.wp-mcp-ai-user-chats__list'),
            listEl: container.querySelector('.wp-mcp-ai-user-chats__sessions'),
            conversationWrapper: container.querySelector('.wp-mcp-ai-user-chats__conversation'),
            conversationTitleEl: container.querySelector('.wp-mcp-ai-user-chats__conversation-title'),
            conversationMetaEl: container.querySelector('.wp-mcp-ai-user-chats__conversation-meta'),
            messagesEl: container.querySelector('.wp-mcp-ai-user-chats__messages'),
            backButton: container.querySelector('.wp-mcp-ai-user-chats__back'),
            sessions: []
        };

        container.__wpMcpAiUserChats = state;

        if (state.backButton) {
            state.backButton.textContent = getString(state, 'back', 'Back to chats');
            state.backButton.addEventListener('click', function () {
                showSessionList(state);
            });
        }

        if (!state.config.userId) {
            setStatus(state, getString(state, 'noUserMessage', 'Select a user to view their chat transcripts.'));
            return;
        }

        loadSessions(state);
    }

    function initAll(scope) {
        const context = scope || document;
        const nodes = context.querySelectorAll('[data-wp-mcp-ai-user-chats]');

        for (let index = 0; index < nodes.length; index++) {
            initContainer(nodes[index]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAll();
        });
    } else {
        initAll();
    }

    if (window.elementorFrontend && window.elementorFrontend.hooks && window.elementorFrontend.hooks.addAction) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/wp_mcp_ai_user_chats.default', function ($element) {
            if ($element && $element[0]) {
                initAll($element[0]);
            }
        });
    }
})();
