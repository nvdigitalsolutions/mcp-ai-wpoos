<?php
/**
 * DeepSeek provider client.
 *
 * OpenAI-compatible API at https://api.deepseek.com/v1.
 * Supports reasoning_content passthrough for deepseek-reasoner.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class DeepSeekClient extends OpenAiCompatibleClient {

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'deepseek';
	}

	protected function getDefaultBaseUrl(): string {
		return 'https://api.deepseek.com/v1';
	}
}
