/**
 * Floating chat bubble widget with accessibility, sessionStorage persistence,
 * badge notifications, and MutationObserver auto-discovery.
 * Zero external dependencies.
 * @package @nvdigitalsolutions/nvoos-chat-bubble
 */

export interface ChatBubbleClasses {
  ROOT?: string;
  TRIGGER?: string;
  PANEL?: string;
  PANEL_CLOSE?: string;
  BADGE?: string;
  OPEN?: string;
}

export interface ChatBubbleEvents {
  OPEN?: string;
  CLOSE?: string;
}

export interface ChatBubbleConfig {
  classes?: ChatBubbleClasses;
  events?: ChatBubbleEvents;
  storagePrefix?: string;
  logPrefix?: string;
  lazyInitCallback?: (panel: HTMLElement) => void;
}

export declare function configure(options: ChatBubbleConfig): void;

export interface BubbleInstance {
  readonly bubbleId: string;
  readonly isOpen: boolean;
  open(): void;
  close(): void;
  toggle(): void;
  setBadge(count: number): void;
  destroy(): void;
}

export declare const ChatBubble: {
  init(scope?: HTMLElement): void;
  open(bubbleId: string): void;
  close(bubbleId: string): void;
  toggle(bubbleId: string): void;
  setBadge(bubbleId: string, count: number): void;
  getInstance(bubbleId: string): BubbleInstance | undefined;
  registerDomObserver(): void;
};

export default ChatBubble;
