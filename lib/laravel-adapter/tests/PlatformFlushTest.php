<?php
/**
 * Platform flush adapter test.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;
use Nvoos\Laravel\Adapter\PlatformFlush;

final class PlatformFlushTest extends TestCase {

	public function test_it_implements_the_contract_and_is_callable(): void {
		$flush = $this->app->make( PlatformFlushInterface::class );

		$this->assertInstanceOf( PlatformFlush::class, $flush );
		$flush->flushPlatformBuffers();

		$this->assertTrue( true );
	}
}
