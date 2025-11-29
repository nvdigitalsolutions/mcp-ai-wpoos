/**
 * Chat Bundle Entry Point
 *
 * This file serves as the entry point for bundling all chat-related JavaScript
 * into a single optimized file. This reduces HTTP requests and improves load
 * times by eliminating the need for multiple separate script files.
 *
 * The bundle includes:
 * - SSE Service (Server-Sent Events)
 * - Job Event Bus (event coordination)
 * - Cron Status Service (async job status)
 * - Chat Storage Service (localStorage management)
 * - Chat Clipboard Service (copy functionality)
 * - Chat Markdown Service (markdown rendering)
 * - Chat UI Utilities Service (DOM helpers)
 * - Chat Audio Service (TTS/transcription)
 * - Main Chat functionality
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

// Import order matters - dependencies must be loaded first

// 1. Core services (no dependencies on other chat modules)
// eslint-disable-next-line no-unused-vars
import './sse-service.js';

// 2. Event coordination (depends on SSE)
// eslint-disable-next-line no-unused-vars
import './job-event-bus.js';

// 3. Status monitoring (depends on SSE and Event Bus)
// eslint-disable-next-line no-unused-vars
import './cron-status-service.js';

// 4. Chat service modules (no cross-dependencies)
// eslint-disable-next-line no-unused-vars
import './chat-storage-service.js';
// eslint-disable-next-line no-unused-vars
import './chat-clipboard-service.js';
// eslint-disable-next-line no-unused-vars
import './chat-markdown-service.js';
// eslint-disable-next-line no-unused-vars
import './chat-ui-utilities-service.js';
// eslint-disable-next-line no-unused-vars
import './chat-audio-service.js';

// 5. Main chat application (depends on all above)
// eslint-disable-next-line no-unused-vars
import './chat.js';
