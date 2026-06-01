<?php
/**
 * NVIDIA NIM provider client.
 *
 * OpenAI-compatible API. Default endpoint depends on the model deployed.
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

class NvidiaNimClient extends OpenAiCompatibleClient {

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'nvidia_nim';
	}

	protected function getDefaultBaseUrl(): string {
		return 'https://integrate.api.nvidia.com/v1';
	}
}
