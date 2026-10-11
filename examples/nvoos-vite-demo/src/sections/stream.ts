import { SSEService, type SSEConnection } from '@nvdigitalsolutions/nvoos-events';
import { domUpdateBatcher, scrollBatcher } from '@nvdigitalsolutions/nvoos-dom-batcher';
import MarkdownRenderer from '@nvdigitalsolutions/nvoos-markdown';
import { StorageUtil } from '@nvdigitalsolutions/nvoos-storage';
import { attachCopyButton, configure as configureClipboard } from '@nvdigitalsolutions/nvoos-clipboard';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

interface DemoMessage {
  role: 'user' | 'assistant';
  content: string;
}

const HISTORY_KEY = 'nvoos-demo-history';

interface SseMessageData {
  token?: string;
  done?: boolean;
}

/**
 * Streaming chat demo combining five packages:
 *
 * - nvoos-events        → SSEService POST stream
 * - nvoos-dom-batcher   → RAF-batched token rendering + scroll batching
 * - nvoos-markdown      → final answer rendered as sanitised HTML
 * - nvoos-storage       → transcript persisted via Web-Worker JSON
 * - nvoos-clipboard     → copy button on assistant bubbles
 *
 * The stream endpoint is a mock served by the Vite dev server
 * (see vite.config.ts). Swap it for `sseEndpoint()` from
 * @nvdigitalsolutions/nvoos-api to talk to a real NV oOS site.
 */
export function initStream(): void {
  const log = document.querySelector<HTMLDivElement>('#chat-log');
  const form = document.querySelector<HTMLFormElement>('#chat-form');
  const input = document.querySelector<HTMLInputElement>('#chat-input');
  const sendButton = document.querySelector<HTMLButtonElement>('#chat-send');
  if (!log || !form || !input || !sendButton) {
    return;
  }

  const renderer = new MarkdownRenderer(marked, DOMPurify);

  // Adopt the demo's CSS class for copy buttons (package default is
  // 'nvoos-copy-button' — configure() makes it overridable).
  configureClipboard({ copyButtonClass: 'copy-btn' });

  // Web-Worker-backed JSON; falls back to the main thread for small payloads.
  StorageUtil.configure({ workerUrl: '/storage-worker.js', sizeThreshold: 10000 });

  let messages: DemoMessage[] = [];
  let streaming = false;

  const scroll = (): void => scrollBatcher.scrollToBottom(log);

  const appendBubble = (role: DemoMessage['role']): HTMLDivElement => {
    const bubble = document.createElement('div');
    bubble.className = `bubble bubble-${role}`;
    log.appendChild(bubble);
    scroll();
    return bubble;
  };

  const appendAssistant = (content: string): HTMLDivElement => {
    const bubble = appendBubble('assistant');
    bubble.innerHTML = renderer.render(content);
    attachCopyButton(bubble);
    return bubble;
  };

  const persist = async (): Promise<void> => {
    const json = await StorageUtil.stringifyJSON(messages);
    localStorage.setItem(HISTORY_KEY, json);
  };

  const restore = async (): Promise<void> => {
    const raw = localStorage.getItem(HISTORY_KEY);
    if (!raw) {
      return;
    }
    const parsed: unknown = await StorageUtil.parseJSON(raw);
    if (!Array.isArray(parsed)) {
      return;
    }
    messages = parsed as DemoMessage[];
    for (const message of messages) {
      if (message.role === 'assistant') {
        appendAssistant(message.content);
      } else {
        const bubble = appendBubble('user');
        bubble.textContent = message.content; // textContent — no HTML injection.
      }
    }
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (streaming) {
      return;
    }

    const text = input.value.trim();
    if (!text) {
      return;
    }

    streaming = true;
    sendButton.disabled = true;
    input.value = '';

    const userBubble = appendBubble('user');
    userBubble.textContent = text;
    messages.push({ role: 'user', content: text });

    const assistantBubble = appendBubble('assistant');
    let accumulated = '';
    let finished = false;
    let connection: SSEConnection | null = null;

    const finish = async (): Promise<void> => {
      if (finished) {
        return;
      }
      finished = true;
      streaming = false;
      sendButton.disabled = false;
      connection?.close();

      if (accumulated) {
        messages.push({ role: 'assistant', content: accumulated });
        assistantBubble.innerHTML = renderer.render(accumulated);
        attachCopyButton(assistantBubble);
      } else if (!assistantBubble.textContent) {
        assistantBubble.textContent = '(no response)';
      }
      await persist();
      scroll();
    };

    connection = SSEService.connect('/api/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: { messages, message: text },
      onMessage: (data: string | SseMessageData) => {
        if (data && typeof data === 'object' && (data as SseMessageData).done) {
          void finish();
          return;
        }
        const token =
          typeof data === 'string' ? data : (data as SseMessageData).token ?? '';
        if (!token) {
          return;
        }
        accumulated += token;
        // Batch token writes into a single requestAnimationFrame per tick.
        domUpdateBatcher.schedule(() => {
          assistantBubble.textContent = accumulated;
        });
        scroll();
      },
      onError: (error: Error) => {
        console.error('[nvoos-vite-demo] SSE error:', error);
        if (!accumulated) {
          assistantBubble.textContent = `Stream error: ${error.message}`;
        }
        void finish();
      },
    });

    if (!connection) {
      assistantBubble.textContent = 'SSE not supported in this browser.';
      void finish();
    }
  });

  void restore();
}
