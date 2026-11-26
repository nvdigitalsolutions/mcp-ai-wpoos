<?php
/**
 * Laravel adapter: provider registration.
 *
 * Registers the core provider clients with the router. Mirrors the
 * WordPress bridge registration so both platforms expose the same
 * provider surface.
 *
 * @package Nvoos\Laravel
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Provider;

use Nvoos\Core\Application\Provider\ProviderHealthTracker;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Infrastructure\Provider\AnthropicClient;
use Nvoos\Core\Infrastructure\Provider\CloudflareClient;
use Nvoos\Core\Infrastructure\Provider\DeepSeekClient;
use Nvoos\Core\Infrastructure\Provider\DigitalOceanClient;
use Nvoos\Core\Infrastructure\Provider\GeminiClient;
use Nvoos\Core\Infrastructure\Provider\HuggingFaceClient;
use Nvoos\Core\Infrastructure\Provider\KimiClient;
use Nvoos\Core\Infrastructure\Provider\LmStudioClient;
use Nvoos\Core\Infrastructure\Provider\NvidiaNimClient;
use Nvoos\Core\Infrastructure\Provider\OllamaClient;
use Nvoos\Core\Infrastructure\Provider\OpenAiClient;
use Nvoos\Core\Infrastructure\Provider\OpenRouterClient;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;

class ProviderRegistrar {

	/**
	 * Register the twelve text providers.
	 *
	 * A token-budget manager is optional so the package stays usable without
	 * one; when supplied it is attached to every client, which turns on the
	 * pre-flight context-window check (Proposal 149 §70).
	 */
	public function register(
		ProviderRouter $router,
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
		?TokenBudgetManager $budget = null,
	): void {
		$router->setHealthTracker( new ProviderHealthTracker() );

		$clients = array(
			new OpenAiClient( $settings, $http, $errors ),
			new GeminiClient( $settings, $http, $errors ),
			new AnthropicClient( $settings, $http, $errors ),
			new DeepSeekClient( $settings, $http, $errors ),
			new OpenRouterClient( $settings, $http, $errors ),
			new KimiClient( $settings, $http, $errors ),
			new OllamaClient( $settings, $http, $errors ),
			new LmStudioClient( $settings, $http, $errors ),
			new DigitalOceanClient( $settings, $http, $errors ),
			new NvidiaNimClient( $settings, $http, $errors ),
			new CloudflareClient( $settings, $http, $errors ),
			new HuggingFaceClient( $settings, $http, $errors ),
		);

		foreach ( $clients as $client ) {
			if ( null !== $budget ) {
				$client->setTokenBudgetManager( $budget );
			}

			$router->register( $client );
		}
	}
}
