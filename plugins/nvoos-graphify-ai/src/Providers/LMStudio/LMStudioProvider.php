<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\LMStudio;

use NvoosGraphifyAi\Providers\OpenAi\OpenAiCompatibleProvider;
use NvoosGraphifyAi\Settings;

class LMStudioProvider extends OpenAiCompatibleProvider {
	protected function apiBase(): string {
		return Settings::get( 'lmstudio_base_url', 'http://localhost:1234/v1' );
	}
	protected function defaultModel(): string {
		return Settings::get( 'lmstudio_model', 'local-model' );
	}
	public function getProviderSlug(): string {
		return 'lmstudio'; }
}
