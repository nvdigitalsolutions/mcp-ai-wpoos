<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\Cloudflare;

use NvoosGraphifyAi\Providers\OpenAi\OpenAiCompatibleProvider;
use NvoosGraphifyAi\Settings;

class CloudflareProvider extends OpenAiCompatibleProvider {
	protected function apiBase(): string {
		$accountId = Settings::get( 'cloudflare_account_id', '' );
		return "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai";
	}
	protected function defaultModel(): string {
		return Settings::get( 'cloudflare_model', '@cf/meta/llama-3.3-70b-instruct' );
	}
	public function getProviderSlug(): string { return 'cloudflare'; }
}
