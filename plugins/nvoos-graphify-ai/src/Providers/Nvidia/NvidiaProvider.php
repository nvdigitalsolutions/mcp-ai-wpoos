<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\Nvidia;

use NvoosGraphifyAi\Providers\OpenAi\OpenAiCompatibleProvider;

class NvidiaProvider extends OpenAiCompatibleProvider {
	protected function apiBase(): string {
		return 'https://integrate.api.nvidia.com/v1'; }
	protected function defaultModel(): string {
		return 'meta/llama-3.3-70b-instruct'; }
	public function getProviderSlug(): string {
		return 'nvidia'; }
}
