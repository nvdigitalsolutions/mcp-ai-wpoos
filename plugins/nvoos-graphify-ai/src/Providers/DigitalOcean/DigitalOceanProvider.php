<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\DigitalOcean;

use NvoosGraphifyAi\Providers\OpenAi\OpenAiCompatibleProvider;

class DigitalOceanProvider extends OpenAiCompatibleProvider {
	protected function apiBase(): string {
		return 'https://api.digitalocean.com/v1'; }
	protected function defaultModel(): string {
		return 'meta-llama/llama-3.3-70b-instruct'; }
	public function getProviderSlug(): string {
		return 'digitalocean'; }
}
