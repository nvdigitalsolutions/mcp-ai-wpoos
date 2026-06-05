<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\Kimi;

use NvoosGraphifyAi\Providers\OpenAi\OpenAiCompatibleProvider;

class KimiProvider extends OpenAiCompatibleProvider {
	protected function apiBase(): string {
		return 'https://api.moonshot.cn/v1'; }
	protected function defaultModel(): string {
		return 'moonshot-v1-8k'; }
	public function getProviderSlug(): string {
		return 'kimi'; }
}
