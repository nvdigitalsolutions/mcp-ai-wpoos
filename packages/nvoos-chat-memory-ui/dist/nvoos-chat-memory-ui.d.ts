/**
 * Chat memory drawer UI — side panel for long-term AI memories.
 * Requires @nvdigitalsolutions/nvoos-chat-memory as peer dependency.
 * @package @nvdigitalsolutions/nvoos-chat-memory-ui
 */

export interface MemoryUIConfig {
  memoryClient: {
    isAvailable(): boolean;
    recall(query: string, filters?: Record<string, unknown>): Promise<unknown>;
    store?(data: Record<string, unknown>): Promise<unknown>;
    update?(id: string, data: Record<string, unknown>): Promise<unknown>;
    remove?(id: string, opts?: Record<string, unknown>): Promise<unknown>;
    audit?(opts?: Record<string, unknown>): Promise<unknown>;
    sessionReplay?(sessionId: string, opts?: Record<string, unknown>): Promise<unknown>;
  };
  i18n?: { __(text: string): string; sprintf?(format: string, ...args: unknown[]): string };
  cssPrefix?: string;
}

export declare function configure(options: MemoryUIConfig): void;

export interface MemoryDrawerController {
  open(returnTarget?: HTMLElement): void;
  close(): void;
  isOpen(): boolean;
  root: HTMLElement;
  refresh(): void;
}

export declare function buildDrawer(container: HTMLElement, state: Record<string, unknown>): MemoryDrawerController;
export declare function attach(container: HTMLElement): void;
export declare function injectToggle(container: HTMLElement, controller: MemoryDrawerController): void;
export declare function decorateMessageWithBadge(bubble: HTMLElement, toolCalls: unknown[]): void;
export declare function handleSseMemoryEvent(payload: { action: string; tool_name?: string }): void;

export declare const MemoryDrawer: {
  attach: typeof attach;
  buildDrawer: typeof buildDrawer;
  injectToggle: typeof injectToggle;
  decorateMessageWithBadge: typeof decorateMessageWithBadge;
  handleSseMemoryEvent: typeof handleSseMemoryEvent;
  configure: typeof configure;
};

export default MemoryDrawer;
