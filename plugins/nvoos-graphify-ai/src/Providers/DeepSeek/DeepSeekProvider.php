<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\DeepSeek;

use NvoosGraphifyAi\Providers\OpenAi\OpenAiCompatibleProvider;

class DeepSeekProvider extends OpenAiCompatibleProvider {
	protected function apiBase(): string {
		return 'https://api.deepseek.com'; }
	protected function defaultModel(): string {
		return 'deepseek-chat'; }
	public function getProviderSlug(): string {
		return 'deepseek'; }
}
