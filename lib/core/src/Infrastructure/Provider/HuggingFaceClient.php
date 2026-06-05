<?php
/**
 * HuggingFace Inference provider client.
 *
 * OpenAI-compatible API via HuggingFace's text-generation-inference endpoint.
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

class HuggingFaceClient extends OpenAiCompatibleClient {

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'huggingface';
	}

	protected function getDefaultBaseUrl(): string {
		return 'https://api-inference.huggingface.co/v1';
	}
}
