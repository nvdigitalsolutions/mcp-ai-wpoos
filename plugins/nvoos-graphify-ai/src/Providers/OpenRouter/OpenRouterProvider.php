<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\OpenRouter;

use NvoosGraphifyAi\Providers\OpenAi\OpenAiCompatibleProvider;

class OpenRouterProvider extends OpenAiCompatibleProvider {
	protected function apiBase(): string { return 'https://openrouter.ai/api/v1'; }
	protected function defaultModel(): string { return 'openai/gpt-4o'; }
	public function getProviderSlug(): string { return 'openrouter'; }
}
