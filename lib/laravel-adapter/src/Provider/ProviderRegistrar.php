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

class ProviderRegistrar {

	/**
	 * Register the twelve text providers.
	 */
	public function register(
		ProviderRouter $router,
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	): void {
		$router->setHealthTracker( new ProviderHealthTracker() );

		$router->register( new OpenAiClient( $settings, $http, $errors ) );
		$router->register( new GeminiClient( $settings, $http, $errors ) );
		$router->register( new AnthropicClient( $settings, $http, $errors ) );
		$router->register( new DeepSeekClient( $settings, $http, $errors ) );
		$router->register( new OpenRouterClient( $settings, $http, $errors ) );
		$router->register( new KimiClient( $settings, $http, $errors ) );
		$router->register( new OllamaClient( $settings, $http, $errors ) );
		$router->register( new LmStudioClient( $settings, $http, $errors ) );
		$router->register( new DigitalOceanClient( $settings, $http, $errors ) );
		$router->register( new NvidiaNimClient( $settings, $http, $errors ) );
		$router->register( new CloudflareClient( $settings, $http, $errors ) );
		$router->register( new HuggingFaceClient( $settings, $http, $errors ) );
	}
}
