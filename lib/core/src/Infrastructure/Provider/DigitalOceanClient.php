<?php
/**
 * DigitalOcean Serverless Inference provider client.
 *
 * OpenAI-compatible API at https://inference.do-ai.run/v1.
 * Supports Llama 3.3, DeepSeek-R1 distill, gpt-oss, plus native /embeddings.
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

class DigitalOceanClient extends OpenAiCompatibleClient {

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'digitalocean';
	}

	protected function getDefaultBaseUrl(): string {
		return 'https://inference.do-ai.run/v1';
	}
}
