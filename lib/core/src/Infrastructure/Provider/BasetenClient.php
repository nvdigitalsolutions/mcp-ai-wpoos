<?php
/**
 * Baseten provider client.
 *
 * OpenAI-compatible API for deploying and serving open-source models.
 * Default endpoint: https://api.baseten.co/v1.
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

class BasetenClient extends OpenAiCompatibleClient {

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'baseten';
	}

	protected function getDefaultBaseUrl(): string {
		return 'https://api.baseten.co/v1';
	}
}
