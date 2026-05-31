<?php
/**
 * OpenAI provider client — framework-agnostic implementation.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

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
