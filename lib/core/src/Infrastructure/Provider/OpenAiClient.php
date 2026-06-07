<?php
/**
 * OpenAI provider client — framework-agnostic implementation.
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

class OpenAiClient extends OpenAiCompatibleClient {

	private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'openai';
	}

	protected function getDefaultBaseUrl(): string {
		return self::DEFAULT_BASE_URL;
	}
}
