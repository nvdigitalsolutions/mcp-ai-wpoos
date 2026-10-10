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
 * Runtime methods on CommandAutocomplete that the published 0.1.0-alpha.3
 * .d.ts does not declare (they are public in the dist). The generator now
 * emits them (see packages/nvoos-slash-commands/adapt-for-npm.cjs), so this
 * augmentation can be dropped once the fixed package is republished.
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
