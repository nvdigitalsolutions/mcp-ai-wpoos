/// <reference types="vite/client" />

declare global {
  /**
   * `nvoos-slash-commands` reads its endpoints from `window.mcpAiData` —
   * the same global the mcp-ai-wpoos plugin sets on WordPress pages.
   */
  interface Window {
    mcpAiData?: {
      slashCommandListEndpoint?: string;
      slashCommandEndpoint?: string;
      nonce?: string;
      [key: string]: unknown;
    };
  }
}

/**
 * Runtime methods on CommandAutocomplete that the package's published .d.ts
 * does not declare (typing gap — they are public in the dist). Augment the
 * class type so the demo can drive the dropdown from an input listener.
 */
declare module '@nvdigitalsolutions/nvoos-slash-commands' {
  interface CommandAutocomplete {
    show(value: string): void;
    hide(): void;
    loadCommands(): Promise<void>;
    destroy(): void;
  }
}

export {};
