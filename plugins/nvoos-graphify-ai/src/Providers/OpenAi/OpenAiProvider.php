<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\OpenAi;

/**
 * OpenAI provider client.
 *
 * @since 1.0.0
 */
class OpenAiProvider extends OpenAiCompatibleProvider {

	protected function apiBase(): string {
		return 'https://api.openai.com/v1';
	}

	protected function defaultModel(): string {
		return 'gpt-4o';
	}

	public function getProviderSlug(): string {
		return 'openai';
	}
}
