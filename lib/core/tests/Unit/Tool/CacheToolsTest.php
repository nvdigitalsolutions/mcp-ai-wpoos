<?php
/**
 * Tests for cache tools and DeleteSettingTool.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Tool\GetCacheTool;
use Nvoos\Core\Tool\SetCacheTool;
use Nvoos\Core\Tool\DeleteCacheTool;
use Nvoos\Core\Tool\IncrementCacheTool;
use Nvoos\Core\Tool\DeleteSettingTool;
use PHPUnit\Framework\TestCase;

final class CacheToolsTest extends TestCase {

	// ─── GetCacheTool ───────────────────────────────────────────────

	public function testGetCacheFound(): void {
		$cache = $this->createMock( CacheStoreInterface::class );
		$cache->method( 'getValue' )->with( 'mykey', null )->willReturn( 'cached_value' );

		$tool   = new GetCacheTool( $this->stubErrors(), $cache );
		$result = $tool->execute( array( 'key' => 'mykey' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'cached_value', $result['data']['value'] );
		$this->assertTrue( $result['data']['found'] );
	}

	public function testGetCacheMiss(): void {
		$cache = $this->createMock( CacheStoreInterface::class );
		$cache->method( 'getValue' )->willReturn( null );

		$tool   = new GetCacheTool( $this->stubErrors(), $cache );
		$result = $tool->execute( array( 'key' => 'missing' ) );

		$this->assertTrue( $result['success'] );
		$this->assertNull( $result['data']['value'] );
		$this->assertFalse( $result['data']['found'] );
	}

	// ─── SetCacheTool ───────────────────────────────────────────────

	public function testSetCache(): void {
		$cache = $this->createMock( CacheStoreInterface::class );
		$cache->expects( $this->once() )->method( 'setValue' )
			->with( 'mykey', 'myvalue', 3600 )
			->willReturn( true );

		$tool   = new SetCacheTool( $this->stubErrors(), $cache );
		$result = $tool->execute( array( 'key' => 'mykey', 'value' => 'myvalue' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['stored'] );
	}

	// ─── DeleteCacheTool ────────────────────────────────────────────

	public function testDeleteCache(): void {
		$cache = $this->createMock( CacheStoreInterface::class );
		$cache->method( 'deleteValue' )->with( 'mykey' )->willReturn( true );

		$tool   = new DeleteCacheTool( $this->stubErrors(), $cache );
		$result = $tool->execute( array( 'key' => 'mykey' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['deleted'] );
	}

	// ─── IncrementCacheTool ─────────────────────────────────────────

	public function testIncrementCache(): void {
		$cache = $this->createMock( CacheStoreInterface::class );
		$cache->method( 'increment' )->with( 'counter', 1, 3600 )->willReturn( 42 );

		$tool   = new IncrementCacheTool( $this->stubErrors(), $cache );
		$result = $tool->execute( array( 'key' => 'counter' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 42, $result['data']['new_value'] );
	}

	// ─── DeleteSettingTool ──────────────────────────────────────────

	public function testDeleteSetting(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->expects( $this->once() )->method( 'delete' )->with( 'old_key' );

		$tool   = new DeleteSettingTool( $this->stubErrors(), $settings );
		$result = $tool->execute( array( 'key' => 'old_key' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'old_key', $result['data']['key'] );
	}

	private function stubErrors(): ErrorFactoryInterface {
		return $this->createMock( ErrorFactoryInterface::class );
	}
}
