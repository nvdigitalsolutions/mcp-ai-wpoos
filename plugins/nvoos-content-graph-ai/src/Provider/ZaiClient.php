<?php
/**
 * Z.AI (Zhipu AI / GLM) provider client.
 *
 * Ported from the base plugin's `includes/class-wp-mcp-ai-zai-client.php`
 * (behaviour-preserving endpoint/settings mapping; the base copy is
 * retained permanently — ecosystem port plan D-NOBASE).
 *
 * Z.AI exposes an OpenAI-compatible API at https://api.z.ai/api/paas/v4,
 * so the client reuses nvoos/core's `OpenAiCompatibleClient` (payload
 * building, SSE assembly, parameter correction, model constraints) and
 * only pins the provider identity — the same pattern as DeepSeek,
 * OpenRouter, Kimi, et al.
 *
 * Settings mapping (standalone installs read the content-graph store):
 *  - API key:  `ai_api_key_zai` (encrypted CredentialStore via
 *    `ContentGraphSettingsStore::getApiKey('zai')`)
 *  - Base URL: `zai_base_url` (defaults to the Z.AI endpoint)
 *  - Model:    global `ai_default_model`, overridable per request
 *
 * Decoupling (documented, additive): the base client's request-time
 * integrations (circuit breaker, audit logger, `zai_request` hooks,
 * token-budget preflight) are provided by nvoos/core and the CG-AI
 * security wave (D4); the endpoint contract is byte-identical.
 *
 * @package NvoosContentGraphAi\Provider
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Infrastructure\Provider\OpenAiCompatibleClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Z.AI provider client (OpenAI-compatible).
 *
 * @since 1.1.0
 */
class ZaiClient extends OpenAiCompatibleClient {

	/**
	 * Default Z.AI API endpoint (same as the base client).
	 *
	 * @var string
	 */
	private const DEFAULT_BASE_URL = 'https://api.z.ai/api/paas/v4';

	/**
	 * Constructor — wires the nvoos/core dependencies and sets the slug.
	 *
	 * @param SettingsStoreInterface $settings Settings store.
	 * @param HttpClientInterface    $http     HTTP client.
	 * @param ErrorFactoryInterface  $errors   Error factory.
	 */
	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'zai';
	}

	/**
	 * Default API endpoint URL when no override is configured.
	 *
	 * @return string
	 */
	protected function getDefaultBaseUrl(): string {
		return self::DEFAULT_BASE_URL;
	}
}
