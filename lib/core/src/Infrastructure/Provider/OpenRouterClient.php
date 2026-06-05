<?php
/**
 * OpenRouter provider client.
 *
 * Unified gateway for OpenAI, Anthropic, Google, Meta, Mistral, and others.
 * OpenAI-compatible API at https://openrouter.ai/api/v1.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class OpenRouterClient extends OpenAiCompatibleClient {

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'openrouter';
	}

	protected function getDefaultBaseUrl(): string {
		return 'https://openrouter.ai/api/v1';
	}
}
