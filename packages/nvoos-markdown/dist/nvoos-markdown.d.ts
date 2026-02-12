/**
 * Security-hardened markdown renderer
 * @package @nvdigital/nvoos-markdown
 */

import { marked } from 'marked';
import DOMPurify from 'dompurify';

export interface MarkdownConfig {
  codeBlockClass?: string;
  imageClass?: string;
  allowedTags?: string[];
  allowedAttributes?: string[];
}

export declare class MarkdownRenderer {
  marked: typeof marked;
  DOMPurify: typeof DOMPurify;
  config: Required<MarkdownConfig>;
  
  constructor(
    markedInstance?: typeof marked,
    domPurifyInstance?: typeof DOMPurify,
    customConfig?: MarkdownConfig
  );
  
  render(text: string): string;
  renderInline(text: string): string;
}

export declare function renderMarkdown(text: string): string;
export declare function renderInlineLabel(text: string): string;
export declare function escapeHtml(text: string): string;
export declare function sanitizeUrl(url: string): string;
export declare function formatInline(text: string): string;

export default MarkdownRenderer;
