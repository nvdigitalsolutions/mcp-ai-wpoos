import MarkdownRenderer from '@nvdigitalsolutions/nvoos-markdown';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

/**
 * @nvdigitalsolutions/nvoos-markdown — XSS-safe markdown rendering
 * (marked for parsing, DOMPurify for sanitisation, pre-configured for
 * AI-generated content).
 */
export function initMarkdown(): void {
  const input = document.querySelector<HTMLTextAreaElement>('#md-input');
  const preview = document.querySelector<HTMLDivElement>('#md-preview');
  if (!input || !preview) {
    return;
  }

  const renderer = new MarkdownRenderer(marked, DOMPurify, {
    codeBlockClass: 'demo-code',
  });

  const update = (): void => {
    preview.innerHTML = renderer.render(input.value);
  };

  input.addEventListener('input', update);
  update();
}
