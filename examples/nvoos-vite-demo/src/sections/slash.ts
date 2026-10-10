import { CommandAutocomplete } from '@nvdigitalsolutions/nvoos-slash-commands';

/**
 * @nvdigitalsolutions/nvoos-slash-commands — fuzzy-search autocomplete for
 * chat inputs. Commands are fetched from `window.mcpAiData.slashCommandListEndpoint`
 * (the same global the mcp-ai-wpoos plugin sets); here it points at the mock
 * endpoint in vite.config.ts. Execution events arrive as
 * `slash-command-event` CustomEvents on `window`.
 */
export function initSlash(): void {
  const input = document.querySelector<HTMLInputElement>('#slash-input');
  const logElement = document.querySelector<HTMLPreElement>('#slash-log');
  if (!input) {
    return;
  }

  window.mcpAiData = {
    slashCommandListEndpoint: '/api/slash-commands',
    slashCommandEndpoint: '/api/slash-commands/execute',
    nonce: 'demo',
  };

  const autocomplete = new CommandAutocomplete(input);
  autocomplete.init();

  // The standalone autocomplete only binds blur / click-outside listeners.
  // In the WordPress plugin, SlashCommandsHandler forwards 'input' events to
  // it — replicate that wiring here so the dropdown opens as the user types.
  input.addEventListener('input', () => {
    const value = input.value.trim();
    if (value.startsWith('/')) {
      autocomplete.show(value);
    } else {
      autocomplete.hide();
    }
  });

  window.addEventListener('slash-command-event', ((event: CustomEvent) => {
    if (logElement) {
      logElement.textContent += `event: ${JSON.stringify(event.detail)}\n`;
    }
  }) as EventListener);
}
