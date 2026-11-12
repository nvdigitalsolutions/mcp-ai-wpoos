<?php
/**
 * Service provider boot and binding tests.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;

final class ServiceProviderTest extends TestCase {

	public function test_all_contracts_resolve(): void {
		$bindings = array(
			ErrorFactoryInterface::class,
			CacheStoreInterface::class,
			SettingsStoreInterface::class,
			EventDispatcherInterface::class,
			FileStoreInterface::class,
			QueueClientInterface::class,
			AuthProviderInterface::class,
			ContentStoreInterface::class,
			SchemaStoreInterface::class,
			HttpClientInterface::class,
		);

		foreach ( $bindings as $binding ) {
			$this->assertNotNull( $this->app->make( $binding ), $binding );
		}
	}

	public function test_orchestrator_resolves_with_all_constructor_arguments(): void {
		$this->assertInstanceOf( ChatOrchestrator::class, $this->app->make( ChatOrchestrator::class ) );
	}

	public function test_sse_handler_resolves(): void {
		$this->assertInstanceOf( SseHandler::class, $this->app->make( SseHandler::class ) );
	}

	public function test_tool_registry_is_seeded(): void {
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( 'color_convert' ) );
	}

	public function test_provider_router_registers_core_providers(): void {
		$slugs = $this->app->make( ProviderRouter::class )->getRegisteredSlugs();

		foreach ( array( 'openai', 'gemini', 'anthropic', 'ollama', 'lm_studio' ) as $slug ) {
			$this->assertContains( $slug, $slugs );
		}
	}
}
