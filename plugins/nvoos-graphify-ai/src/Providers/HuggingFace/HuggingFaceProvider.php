<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\HuggingFace;

use NvoosGraphifyAi\Providers\OpenAi\OpenAiCompatibleProvider;
use NvoosGraphifyAi\Settings;

class HuggingFaceProvider extends OpenAiCompatibleProvider {
	protected function apiBase(): string {
		$base = Settings::get( 'huggingface_endpoint_url', 'https://api-inference.huggingface.co' );
		return rtrim( $base, '/' );
	}
	protected function defaultModel(): string {
		return Settings::get( 'huggingface_model', 'meta-llama/Llama-3.3-70B-Instruct' );
	}
	public function getProviderSlug(): string { return 'huggingface'; }
}
