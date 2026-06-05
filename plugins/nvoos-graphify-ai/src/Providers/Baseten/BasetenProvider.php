<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\Baseten;

use NvoosGraphifyAi\Providers\OpenAi\OpenAiCompatibleProvider;

class BasetenProvider extends OpenAiCompatibleProvider {
	protected function apiBase(): string { return 'https://api.baseten.co/v1'; }
	protected function defaultModel(): string { return 'meta-llama/Llama-3.3-70B-Instruct'; }
	public function getProviderSlug(): string { return 'baseten'; }
}
