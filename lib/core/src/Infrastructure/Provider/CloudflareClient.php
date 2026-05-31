<?php
/**
 * Cloudflare Workers AI provider client.
 *
 * OpenAI-compatible API. Default endpoint uses the Cloudflare AI Gateway.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

class CloudflareClient extends OpenAiCompatibleClient {

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'cloudflare';
	}

	protected function getDefaultBaseUrl(): string {
		return 'https://api.cloudflare.com/client/v4/accounts/:account_id/ai/v1';
	}
}
