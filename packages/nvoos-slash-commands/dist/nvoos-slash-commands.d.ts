/**
 * Slash command system with fuzzy-search autocomplete and execution engine.
 * Zero external dependencies — uses only standard browser APIs.
 * @package @nvdigitalsolutions/nvoos-slash-commands
 */

// ─── CommandAutocomplete ──────────────────────────────────────────────────────

export interface SlashCommand {
  name: string;
  description?: string;
  aliases?: string[];
  [key: string]: unknown;
}

export declare class CommandAutocomplete {
  constructor(inputElement: HTMLInputElement | HTMLTextAreaElement);
  init(): void;
  show(input: string): void;
  hide(): void;
  isVisible(): boolean;
  handleKeyDown(e: KeyboardEvent): boolean;
  destroy(): void;
}

// ─── SlashCommandsHandler ────────────────────────────────────────────────────

export interface SlashCommandsConfig {
  restUrl?: string;
  nonce?: string;
  slashCommandEndpoint?: string;
  slashCommandListEndpoint?: string;
  [key: string]: unknown;
}

export declare class SlashCommandsHandler {
  constructor(config?: SlashCommandsConfig);
  static configure(config: SlashCommandsConfig): void;
  init(): void;
  executeCommand(command: string): Promise<void>;
  fetchCommands(): Promise<SlashCommand[]>;
  announceToScreenReader(message: string): void;
  destroy?(): void;
}

// ─── createSlashCommands ─────────────────────────────────────────────────────

/**
 * Convenience factory: creates and auto-initialises a SlashCommandsHandler.
 */
export declare function createSlashCommands(config?: SlashCommandsConfig): SlashCommandsHandler;

declare const _default: {
  CommandAutocomplete: typeof CommandAutocomplete;
  SlashCommandsHandler: typeof SlashCommandsHandler;
  createSlashCommands: typeof createSlashCommands;
};
export default _default;
